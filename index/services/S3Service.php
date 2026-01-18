<?php
/**
 * S3/MinIO 服务封装
 * 
 * 支持预签名 URL 生成，用于大文件上传和下载
 * 配置从 config/storage.php 读取，禁止硬编码
 */

require_once __DIR__ . '/../core/storage/storage_provider.php';

class S3Service
{
    private array $config;
    private string $endpoint;
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $prefix;
    private bool $usePathStyle;
    private bool $useHttps;
    
    public function __construct()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl 扩展未安装或未启用');
        }

        $storageConfig = storage_config();
        
        if (($storageConfig['type'] ?? 'local') !== 's3') {
            throw new RuntimeException('当前存储类型不是 S3');
        }
        
        $this->config = $storageConfig['s3'] ?? [];
        $this->endpoint = rtrim($this->config['endpoint'] ?? '', '/');
        $this->bucket = $this->config['bucket'] ?? '';
        $this->region = $this->config['region'] ?? 'us-east-1';
        $this->accessKey = $this->config['access_key'] ?? '';
        $this->secretKey = $this->config['secret_key'] ?? '';
        $this->prefix = trim($this->config['prefix'] ?? '', '/');
        $this->usePathStyle = $this->config['use_path_style'] ?? true;
        $this->useHttps = $this->config['use_https'] ?? false;
        
        if (empty($this->bucket) || empty($this->accessKey) || empty($this->secretKey)) {
            throw new RuntimeException('S3 配置不完整，请检查 config/storage.php');
        }
    }
    
    /**
     * 获取预签名下载 URL
     * 
     * @param string $storageKey 存储键（不含 prefix）
     * @param int $expiresIn 有效期（秒）
     * @return string 预签名 URL
     */
    public function getPresignedUrl(string $storageKey, int $expiresIn = 3600): string
    {
        return $this->buildPresignedUrl('GET', $storageKey, [], $expiresIn);
    }
    
    /**
     * 获取预签名上传 URL
     * 
     * @param string $storageKey 存储键
     * @param int $expiresIn 有效期（秒）
     * @param string $contentType MIME 类型
     * @return string 预签名 URL
     */
    public function getPresignedUploadUrl(string $storageKey, int $expiresIn = 3600, string $contentType = ''): string
    {
        $headers = [];
        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }
        return $this->buildPresignedUrl('PUT', $storageKey, [], $expiresIn, $headers);
    }
    
    /**
     * 构建预签名 URL（AWS Signature V4）
     */
    private function buildPresignedUrl(string $method, string $storageKey, array $query = [], int $expiresIn = 3600, array $signedHeaders = []): string
    {
        $objectKey = $this->applyPrefix($storageKey);
        $timestamp = time();
        $date = gmdate('Ymd', $timestamp);
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        
        // 解析 endpoint
        $parsedEndpoint = parse_url($this->endpoint);
        $host = $parsedEndpoint['host'] ?? '';
        $port = $parsedEndpoint['port'] ?? null;
        $scheme = $this->useHttps ? 'https' : ($parsedEndpoint['scheme'] ?? 'http');
        
        // 构建 Host header
        $hostHeader = $host;
        if ($port && $port != 80 && $port != 443) {
            $hostHeader .= ':' . $port;
        }
        
        // 构建 URI
        if ($this->usePathStyle) {
            $uri = '/' . $this->bucket . '/' . ltrim($objectKey, '/');
            $baseUrl = $scheme . '://' . $hostHeader;
        } else {
            $uri = '/' . ltrim($objectKey, '/');
            $baseUrl = $scheme . '://' . $this->bucket . '.' . $hostHeader;
            $hostHeader = $this->bucket . '.' . $hostHeader;
        }
        
        // 预签名查询参数
        $credential = $this->accessKey . '/' . $date . '/' . $this->region . '/s3/aws4_request';
        
        $query['X-Amz-Algorithm'] = 'AWS4-HMAC-SHA256';
        $query['X-Amz-Credential'] = $credential;
        $query['X-Amz-Date'] = $datetime;
        $query['X-Amz-Expires'] = (string)$expiresIn;
        $query['X-Amz-SignedHeaders'] = 'host';
        
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        
        // 规范请求
        $canonicalHeaders = "host:{$hostHeader}\n";
        $signedHeadersList = 'host';
        
        $canonicalRequest = implode("\n", [
            $method,
            $uri,
            $queryString,
            $canonicalHeaders,
            $signedHeadersList,
            'UNSIGNED-PAYLOAD'
        ]);
        
        // 待签名字符串
        $scope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $datetime,
            $scope,
            hash('sha256', $canonicalRequest)
        ]);
        
        // 生成签名密钥
        $signingKey = $this->getSigningKey($date);
        
        // 计算签名
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        return $baseUrl . $uri . '?' . $queryString . '&X-Amz-Signature=' . $signature;
    }
    
    /**
     * 生成签名密钥
     */
    private function getSigningKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
    
    /**
     * 应用前缀
     */
    private function applyPrefix(string $storageKey): string
    {
        if (empty($this->prefix)) {
            return $storageKey;
        }
        return $this->prefix . '/' . ltrim($storageKey, '/');
    }
    
    /**
     * 检查文件是否存在
     */
    public function exists(string $storageKey): bool
    {
        try {
            $objectKey = $this->applyPrefix($storageKey);
            $url = $this->buildHeadUrl($objectKey);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 构建 HEAD 请求 URL
     */
    private function buildHeadUrl(string $objectKey): string
    {
        return $this->buildPresignedUrl('HEAD', $objectKey, [], 60);
    }
    
    /**
     * 获取存储配置（只读）
     */
    public function getConfig(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'bucket' => $this->bucket,
            'region' => $this->region,
            'use_path_style' => $this->usePathStyle,
            'use_https' => $this->useHttps,
        ];
    }
    
    /**
     * 列出指定前缀下的对象
     * 
     * @param string $prefix 前缀
     * @param string $delimiter 分隔符（用于模拟目录）
     * @return array 文件列表
     */
    public function listObjects(string $prefix, string $delimiter = ''): array
    {
        $timestamp = time();
        $date = gmdate('Ymd', $timestamp);
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        
        $parsedEndpoint = parse_url($this->endpoint);
        $host = $parsedEndpoint['host'] ?? '';
        $port = $parsedEndpoint['port'] ?? null;
        $scheme = $this->useHttps ? 'https' : ($parsedEndpoint['scheme'] ?? 'http');
        
        $hostHeader = $host;
        if ($port && $port != 80 && $port != 443) {
            $hostHeader .= ':' . $port;
        }
        
        if ($this->usePathStyle) {
            $uri = '/' . $this->bucket;
            $baseUrl = $scheme . '://' . $hostHeader;
        } else {
            $uri = '/';
            $baseUrl = $scheme . '://' . $this->bucket . '.' . $hostHeader;
            $hostHeader = $this->bucket . '.' . $hostHeader;
        }
        
        $queryParams = [
            'list-type' => '2',
            'prefix' => $prefix,
            'max-keys' => '1000',
        ];
        if ($delimiter) {
            $queryParams['delimiter'] = $delimiter;
        }
        ksort($queryParams);
        
        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $payloadHash = hash('sha256', '');
        $canonicalHeaders = "host:{$hostHeader}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$datetime}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        
        $canonicalRequest = "GET\n{$uri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        
        $url = "{$baseUrl}{$uri}?{$canonicalQueryString}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Host: {$hostHeader}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$datetime}",
                "Authorization: {$authorization}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('S3 请求失败: HTTP ' . $status);
        }
        
        $files = [];
        $prefixLen = strlen($this->prefix ? $this->prefix . '/' : '');
        
        if (preg_match_all('/<Contents>(.+?)<\/Contents>/s', $response, $matches)) {
            foreach ($matches[1] as $content) {
                $key = '';
                $size = 0;
                $modified = '';
                
                if (preg_match('/<Key>(.+?)<\/Key>/', $content, $m)) {
                    $key = html_entity_decode($m[1]);
                }
                if (preg_match('/<Size>(\d+)<\/Size>/', $content, $m)) {
                    $size = (int)$m[1];
                }
                if (preg_match('/<LastModified>(.+?)<\/LastModified>/', $content, $m)) {
                    $modified = $m[1];
                }
                
                $keyLen = strlen($key);
                if ($key === '' || $key === $prefix || ($keyLen > 0 && substr($key, $keyLen - 1) === '/')) {
                    continue;
                }
                
                $relPath = $prefixLen > 0 ? substr($key, $prefixLen) : $key;
                $relPath = preg_replace('#^groups/[^/]+/[^/]+/#', '', $relPath);
                
                $files[] = [
                    'rel_path' => $relPath,
                    'filename' => basename($relPath),
                    'size' => $size,
                    'modified_at' => $modified,
                    'storage_key' => $key,
                    'is_dir' => false,
                ];
            }
        }
        
        return $files;
    }
    
    /**
     * 删除对象
     * 
     * @param string $storageKey 存储键
     * @return bool 删除是否成功
     */
    public function deleteObject(string $storageKey): bool
    {
        $timestamp = time();
        $date = gmdate('Ymd', $timestamp);
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        
        $storageKey = ltrim($storageKey, '/');
        if ($this->prefix) {
            $fullKey = $this->prefix . '/' . $storageKey;
        } else {
            $fullKey = $storageKey;
        }
        
        $parsedEndpoint = parse_url($this->endpoint);
        $host = $parsedEndpoint['host'] ?? '';
        $port = $parsedEndpoint['port'] ?? null;
        $scheme = $this->useHttps ? 'https' : ($parsedEndpoint['scheme'] ?? 'http');
        
        $hostHeader = $host;
        if ($port && $port != 80 && $port != 443) {
            $hostHeader .= ':' . $port;
        }
        
        if ($this->usePathStyle) {
            $uri = '/' . $this->bucket . '/' . $this->rawEncode($fullKey);
            $baseUrl = $scheme . '://' . $hostHeader;
        } else {
            $uri = '/' . $this->rawEncode($fullKey);
            $baseUrl = $scheme . '://' . $this->bucket . '.' . $hostHeader;
            $hostHeader = $this->bucket . '.' . $hostHeader;
        }
        
        $payloadHash = hash('sha256', '');
        $canonicalHeaders = "host:{$hostHeader}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$datetime}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        
        $canonicalRequest = "DELETE\n{$uri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$datetime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        
        $url = $baseUrl . $uri;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Host: {$hostHeader}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$datetime}",
                "Authorization: {$authorization}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("[S3Service] deleteObject CURL 错误: {$error}");
            throw new RuntimeException("删除失败: {$error}");
        }
        
        // 204 No Content 或 200 OK 表示成功
        if ($httpCode === 204 || $httpCode === 200) {
            return true;
        }
        
        // 404 表示文件不存在，也算成功
        if ($httpCode === 404) {
            return true;
        }
        
        error_log("[S3Service] deleteObject 失败: HTTP {$httpCode}, Response: {$response}");
        throw new RuntimeException("删除失败: HTTP {$httpCode}");
    }
    
    /**
     * URL 编码（保留斜杠）
     */
    private function rawEncode(string $value): string
    {
        $segments = explode('/', $value);
        return implode('/', array_map('rawurlencode', $segments));
    }
}
