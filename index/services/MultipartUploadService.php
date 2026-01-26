<?php

/**
 * MinIO/S3 分片上传服务
 * 
 * 支持：
 * - 初始化分片上传（CreateMultipartUpload）
 * - 获取分片预签名 URL（PresignedUploadPart）
 * - 完成分片上传（CompleteMultipartUpload）
 * - 取消分片上传（AbortMultipartUpload）
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

class MultipartUploadService
{
    private array $s3Config;
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    
    public function __construct()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl 扩展未安装或未启用');
        }

        $config = storage_config();
        if (($config['type'] ?? 'local') !== 's3') {
            throw new RuntimeException('分片上传仅支持 S3/MinIO 存储');
        }
        
        $this->s3Config = $config['s3'] ?? [];
        $this->bucket = $this->s3Config['bucket'] ?? '';
        $this->region = $this->s3Config['region'] ?? 'us-east-1';
        $this->accessKey = $this->s3Config['access_key'] ?? '';
        $this->secretKey = $this->s3Config['secret_key'] ?? '';
        
        if (empty($this->bucket) || empty($this->accessKey) || empty($this->secretKey)) {
            throw new RuntimeException('S3 配置不完整');
        }
    }
    
    /**
     * 初始化分片上传
     * 
     * @param string $storageKey 存储键（如 groups/Q2025122001/作品文件/xxx.psd）
     * @param string $contentType MIME 类型
     * @return array ['upload_id' => string, 'storage_key' => string]
     */
    public function initiate(string $storageKey, string $contentType = 'application/octet-stream'): array
    {
        $objectKey = $this->applyPrefix($storageKey);
        $url = $this->buildUrl($objectKey, ['uploads' => '']);
        
        $headers = [
            'Content-Type' => $contentType,
        ];
        
        $response = $this->sendRequest('POST', $url, $objectKey, $headers, ['uploads' => '']);
        
        // 解析响应获取 upload_id
        if (preg_match('/<UploadId>(.+?)<\/UploadId>/', $response['body'], $matches)) {
            return [
                'upload_id' => $matches[1],
                'storage_key' => $storageKey,
            ];
        }
        
        throw new RuntimeException('无法获取 upload_id: ' . ($response['body'] ?? ''));
    }
    
    /**
     * 获取分片上传预签名 URL
     * 
     * @param string $storageKey 存储键
     * @param string $uploadId 上传会话 ID
     * @param int $partNumber 分片编号（从 1 开始）
     * @param int $expiresIn 有效期（秒，默认 1 小时）
     * @return string 预签名 URL
     */
    public function getPartPresignedUrl(string $storageKey, string $uploadId, int $partNumber, int $expiresIn = 3600): string
    {
        $objectKey = $this->applyPrefix($storageKey);
        
        $query = [
            'partNumber' => (string)$partNumber,
            'uploadId' => $uploadId,
        ];
        
        return $this->buildPresignedUrl('PUT', $objectKey, $query, $expiresIn);
    }
    
    /**
     * 完成分片上传
     * 
     * @param string $storageKey 存储键
     * @param string $uploadId 上传会话 ID
     * @param array $parts 分片信息 [['PartNumber' => int, 'ETag' => string], ...]
     * @return array ['etag' => string, 'location' => string]
     */
    public function complete(string $storageKey, string $uploadId, array $parts): array
    {
        $objectKey = $this->applyPrefix($storageKey);
        $url = $this->buildUrl($objectKey, ['uploadId' => $uploadId]);
        
        // 构建 CompleteMultipartUpload XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<CompleteMultipartUpload>';
        
        // 按 PartNumber 排序
        usort($parts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        
        foreach ($parts as $part) {
            $xml .= '<Part>';
            $xml .= '<PartNumber>' . (int)$part['PartNumber'] . '</PartNumber>';
            $xml .= '<ETag>' . htmlspecialchars($part['ETag']) . '</ETag>';
            $xml .= '</Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        
        $headers = [
            'Content-Type' => 'application/xml',
        ];
        
        $response = $this->sendRequest('POST', $url, $objectKey, $headers, ['uploadId' => $uploadId], $xml);
        
        // 解析响应
        $etag = '';
        $location = '';
        if (preg_match('/<ETag>(.+?)<\/ETag>/', $response['body'], $matches)) {
            $etag = trim($matches[1], '"');
        }
        if (preg_match('/<Location>(.+?)<\/Location>/', $response['body'], $matches)) {
            $location = $matches[1];
        }
        
        return [
            'etag' => $etag,
            'location' => $location,
        ];
    }
    
    /**
     * 取消分片上传
     * 
     * @param string $storageKey 存储键
     * @param string $uploadId 上传会话 ID
     * @return bool
     */
    public function abort(string $storageKey, string $uploadId): bool
    {
        try {
            $objectKey = $this->applyPrefix($storageKey);
            $url = $this->buildUrl($objectKey, ['uploadId' => $uploadId]);
            $this->sendRequest('DELETE', $url, $objectKey, [], ['uploadId' => $uploadId]);
            return true;
        } catch (Exception $e) {
            error_log('[SYNC_DEBUG] 取消分片上传失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取下载预签名 URL
     */
    public function getDownloadPresignedUrl(string $storageKey, int $expiresIn = 3600): string
    {
        $objectKey = $this->applyPrefix($storageKey);
        return $this->buildPresignedUrl('GET', $objectKey, [], $expiresIn);
    }
    
    private function applyPrefix(string $storageKey): string
    {
        $key = ltrim($storageKey, '/');
        $prefix = trim((string)($this->s3Config['prefix'] ?? ''), '/');
        if ($prefix === '') {
            return $key;
        }
        return $prefix . '/' . $key;
    }
    
    private function buildUrl(string $objectKey, array $query = []): string
    {
        [$baseUrl, , ] = $this->buildRequestParts($objectKey);
        if (!empty($query)) {
            $baseUrl .= '?' . $this->canonicalQueryString($query);
        }
        return $baseUrl;
    }
    
    private function buildRequestParts(string $objectKey): array
    {
        $objectKey = ltrim($objectKey, '/');
        $encodedKey = $this->encodeKey($objectKey);
        
        $useHttps = ($this->s3Config['use_https'] ?? true) !== false;
        $defaultScheme = $useHttps ? 'https' : 'http';
        $endpoint = $this->s3Config['endpoint'] ?? '';
        $usePathStyle = (bool)($this->s3Config['use_path_style'] ?? false);
        
        if ($endpoint) {
            $parsed = parse_url($endpoint);
            $scheme = $parsed['scheme'] ?? $defaultScheme;
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? (int)$parsed['port'] : null;
            $basePath = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
            $portSuffix = $this->formatPortSuffix($scheme, $port);
            
            if ($usePathStyle) {
                $path = $basePath . '/' . $this->rawEncode($this->bucket) . '/' . $encodedKey;
                $hostHeader = $host . $portSuffix;
            } else {
                $path = $basePath . '/' . $encodedKey;
                $hostHeader = $this->bucket . '.' . $host . $portSuffix;
            }
        } else {
            $scheme = $defaultScheme;
            $hostHeader = $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
            $path = '/' . $encodedKey;
        }
        
        $canonicalUri = $this->normalizePath($path);
        $baseUrl = $scheme . '://' . $hostHeader . $canonicalUri;
        
        return [$baseUrl, $hostHeader, $canonicalUri];
    }
    
    private function sendRequest(string $method, string $url, string $objectKey, array $headers = [], array $query = [], ?string $body = null): array
    {
        [, $hostHeader, $canonicalUri] = $this->buildRequestParts($objectKey);
        
        $payloadHash = $body !== null ? hash('sha256', $body) : 'UNSIGNED-PAYLOAD';
        $canonicalQuery = $this->canonicalQueryString($query);
        
        $signed = $this->signRequest($method, $canonicalUri, $canonicalQuery, $headers, $payloadHash, $hostHeader);
        
        $httpHeaders = [];
        foreach ($signed['headers'] as $key => $value) {
            $httpHeaders[] = $key . ': ' . $value;
        }
        $httpHeaders[] = 'Authorization: ' . $signed['authorization'];
        
        if ($body !== null) {
            $httpHeaders[] = 'Content-Length: ' . strlen($body);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false && $error !== '') {
            throw new RuntimeException('S3 请求失败: ' . $error);
        }
        
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('S3 请求失败: HTTP ' . $status . ' - ' . ($response ?: ''));
        }
        
        return ['status' => $status, 'body' => $response];
    }
    
    private function buildPresignedUrl(string $method, string $objectKey, array $extraQuery, int $expiresIn): string
    {
        [$baseUrl, $hostHeader, $canonicalUri] = $this->buildRequestParts($objectKey);
        
        $expires = max(1, min(604800, $expiresIn));
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        
        $query = array_merge([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ], $extraQuery);
        
        $canonicalQuery = $this->canonicalQueryString($query);
        $canonicalHeaders = 'host:' . strtolower($hostHeader) . "\n";
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            'host',
            'UNSIGNED-PAYLOAD',
        ]);
        
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));
        $query['X-Amz-Signature'] = $signature;
        $finalQuery = $this->canonicalQueryString($query);
        
        return $baseUrl . '?' . $finalQuery;
    }
    
    private function signRequest(string $method, string $canonicalUri, string $canonicalQuery, array $headers, string $payloadHash, string $hostHeader): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = trim((string)$value);
        }
        $normalizedHeaders['host'] = strtolower($hostHeader);
        $normalizedHeaders['x-amz-date'] = $amzDate;
        $normalizedHeaders['x-amz-content-sha256'] = $payloadHash;
        ksort($normalizedHeaders);
        
        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        foreach ($normalizedHeaders as $key => $value) {
            $canonicalHeaders .= $key . ':' . preg_replace('/\s+/', ' ', $value) . "\n";
            $signedHeaderKeys[] = $key;
        }
        $signedHeaders = implode(';', $signedHeaderKeys);
        
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        
        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);
        
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));
        
        return [
            'headers' => $normalizedHeaders,
            'authorization' => 'AWS4-HMAC-SHA256'
                . ' Credential=' . $this->accessKey . '/' . $credentialScope
                . ', SignedHeaders=' . $signedHeaders
                . ', Signature=' . $signature,
        ];
    }
    
    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
    
    private function canonicalQueryString(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        ksort($query);
        $segments = [];
        foreach ($query as $key => $value) {
            $segments[] = $this->rawEncode($key) . '=' . $this->rawEncode((string)$value);
        }
        return implode('&', $segments);
    }
    
    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');
        return preg_replace('#/+#', '/', $normalized) ?: '/';
    }
    
    private function formatPortSuffix(string $scheme, ?int $port): string
    {
        if ($port === null) {
            return '';
        }
        $isDefault = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        return $isDefault ? '' : ':' . $port;
    }
    
    private function encodeKey(string $key): string
    {
        $segments = explode('/', $key);
        return implode('/', array_map([$this, 'rawEncode'], $segments));
    }
    
    private function rawEncode(string $value): string
    {
        // 确保UTF-8编码
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'auto');
        }
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
