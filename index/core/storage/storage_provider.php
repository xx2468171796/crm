<?php

/**
 * StorageProvider 抽象 & 工厂
 */

if (!function_exists('storage_config')) {
    function storage_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $path = dirname(__DIR__, 2) . '/config/storage.php';
        if (!file_exists($path)) {
            throw new RuntimeException('缺少 storage 配置文件: config/storage.php');
        }

        $config = require $path;
        return $config;
    }
}

interface StorageProviderInterface
{
    public function disk(): string;

    /**
     * @param string $storageKey customer/{customerId}/{uuid.ext}
     * @param string $sourcePath 临时文件路径
     * @param array $options
     * @return array
     */
    public function putObject(string $storageKey, string $sourcePath, array $options = []): array;

    /**
     * @return resource file handle
     */
    public function readStream(string $storageKey);

    public function deleteObject(string $storageKey): bool;

    public function copyObject(string $sourceKey, string $destKey): bool;

    public function getTemporaryUrl(string $storageKey, int $expiresIn = 300, array $options = []): ?string;

    public function supportsPreview(string $mimeType): bool;
}

abstract class AbstractStorageProvider implements StorageProviderInterface
{
    protected array $limits;

    public function __construct(array $limits)
    {
        $this->limits = $limits;
    }

    public function supportsPreview(string $mimeType): bool
    {
        $previewMimes = $this->limits['preview_mimes'] ?? [];
        return in_array(strtolower($mimeType), array_map('strtolower', $previewMimes), true);
    }
}

class LocalStorageProvider extends AbstractStorageProvider
{
    protected string $root;
    protected ?string $baseUrl;

    public function __construct(array $config, array $limits)
    {
        parent::__construct($limits);
        $this->root = rtrim($config['root'] ?? dirname(__DIR__, 2) . '/uploads/storage', '/');
        $this->baseUrl = $config['base_url'] ?? null;
    }

    public function disk(): string
    {
        return 'local';
    }

    public function putObject(string $storageKey, string $sourcePath, array $options = []): array
    {
        $destination = $this->absolutePath($storageKey);
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('无法创建目录: ' . $dir);
            }
        }

        if (!rename($sourcePath, $destination)) {
            if (!copy($sourcePath, $destination)) {
                throw new RuntimeException('写入文件失败: ' . $destination);
            }
            unlink($sourcePath);
        }

        return [
            'disk' => $this->disk(),
            'storage_key' => $storageKey,
            'bytes' => filesize($destination),
            'extra' => null,
        ];
    }

    public function readStream(string $storageKey)
    {
        $path = $this->absolutePath($storageKey);
        if (!is_file($path)) {
            throw new RuntimeException('文件不存在: ' . $storageKey);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法打开文件: ' . $storageKey);
        }
        return $handle;
    }

    public function deleteObject(string $storageKey): bool
    {
        $path = $this->absolutePath($storageKey);
        if (!file_exists($path)) {
            return true;
        }
        return unlink($path);
    }

    public function copyObject(string $sourceKey, string $destKey): bool
    {
        $sourcePath = $this->absolutePath($sourceKey);
        $destPath = $this->absolutePath($destKey);
        
        if (!file_exists($sourcePath)) {
            return false;
        }
        
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return false;
            }
        }
        
        return copy($sourcePath, $destPath);
    }

    public function getTemporaryUrl(string $storageKey, int $expiresIn = 300, array $options = []): ?string
    {
        if (!$this->baseUrl) {
            return null;
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($storageKey, '/');
    }

    protected function absolutePath(string $storageKey): string
    {
        return $this->root . '/' . ltrim($storageKey, '/');
    }
}

class S3StorageProvider extends AbstractStorageProvider
{
    private array $config;
    private string $bucket;
    private string $region;
    private string $accessKey;
    private string $secretKey;

    public function __construct(array $config, array $limits)
    {
        parent::__construct($limits);
        $this->config = $config;
        $this->bucket = $this->requireConfig('bucket');
        $this->region = $this->requireConfig('region');
        $this->accessKey = $this->requireConfig('access_key');
        $this->secretKey = $this->requireConfig('secret_key');
        $this->ensureCurlAvailable();
    }

    public function disk(): string
    {
        return 's3';
    }

    public function putObject(string $storageKey, string $sourcePath, array $options = []): array
    {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('上传源文件不存在: ' . $sourcePath);
        }
        $bytes = filesize($sourcePath);
        if ($bytes === false) {
            throw new RuntimeException('无法读取文件大小: ' . $sourcePath);
        }
        $payloadHash = hash_file('sha256', $sourcePath);
        $mime = $options['mime_type'] ?? 'application/octet-stream';
        $this->sendRequest('PUT', $storageKey, [
            'headers' => [
                'Content-Type' => $mime,
            ],
            'payload_hash' => $payloadHash,
            'source_path' => $sourcePath,
            'source_bytes' => $bytes,
        ]);
        if (is_file($sourcePath)) {
            @unlink($sourcePath);
        }
        return [
            'disk' => $this->disk(),
            'storage_key' => $storageKey,
            'bytes' => $bytes,
            'extra' => null,
        ];
    }

    public function readStream(string $storageKey)
    {
        $tmpFile = $this->downloadToTemp($storageKey);
        $handle = fopen($tmpFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法打开临时文件: ' . $tmpFile);
        }
        register_shutdown_function(static function () use ($tmpFile) {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        });
        return $handle;
    }

    public function deleteObject(string $storageKey): bool
    {
        try {
            $this->sendRequest('DELETE', $storageKey);
            return true;
        } catch (Throwable $e) {
            error_log('[S3] 删除失败: ' . $e->getMessage());
            return false;
        }
    }

    public function copyObject(string $sourceKey, string $destKey): bool
    {
        try {
            // S3 CopyObject API 使用 x-amz-copy-source 头
            $sourceKeyWithPrefix = $this->applyPrefix($sourceKey);
            $copySource = '/' . $this->bucket . '/' . ltrim($sourceKeyWithPrefix, '/');
            
            $this->sendRequest('PUT', $destKey, [
                'headers' => [
                    'x-amz-copy-source' => $copySource,
                ],
                'payload_hash' => hash('sha256', ''), // 空 payload
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[S3] 复制失败: ' . $e->getMessage());
            return false;
        }
    }

    public function getTemporaryUrl(string $storageKey, int $expiresIn = 300, array $options = []): ?string
    {
        try {
            return $this->buildPresignedUrl($storageKey, $expiresIn);
        } catch (Throwable $e) {
            error_log('[S3] 生成临时链接失败: ' . $e->getMessage());
            return null;
        }
    }

    private function ensureCurlAvailable(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('S3 驱动需要启用 php-curl 扩展');
        }
    }

    private function requireConfig(string $key): string
    {
        $value = (string)($this->config[$key] ?? '');
        if ($value === '') {
            throw new RuntimeException('S3 配置缺失: ' . $key);
        }
        return $value;
    }

    private function applyPrefix(string $storageKey): string
    {
        $key = ltrim($storageKey, '/');
        $prefix = trim((string)($this->config['prefix'] ?? ''), '/');
        if ($prefix === '') {
            return $key;
        }
        return $prefix . '/' . $key;
    }

    private function sendRequest(string $method, string $storageKey, array $options = []): array
    {
        $objectKey = $this->applyPrefix($storageKey);
        [$baseUrl, $hostHeader, $canonicalUri] = $this->buildRequestParts($objectKey);
        $query = $options['query'] ?? [];
        $canonicalQuery = $this->canonicalQueryString($query);
        $url = $baseUrl . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

        $payloadHash = $options['payload_hash'] ?? 'UNSIGNED-PAYLOAD';
        $headers = $options['headers'] ?? [];
        $signed = $this->signRequest(
            $method,
            $canonicalUri,
            $canonicalQuery,
            $headers,
            $payloadHash,
            $hostHeader
        );

        $httpHeaders = [];
        foreach ($signed['headers'] as $key => $value) {
            $httpHeaders[] = $key . ': ' . $value;
        }
        $httpHeaders[] = 'Authorization: ' . $signed['authorization'];

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('无法初始化 cURL');
        }

        $timeout = (int)($this->config['timeout'] ?? 60);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => !isset($options['sink']),
            CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : 60,
        ];

        if (isset($options['source_path'])) {
            $handle = fopen($options['source_path'], 'rb');
            if ($handle === false) {
                throw new RuntimeException('无法读取文件: ' . $options['source_path']);
            }
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $handle;
            $opts[CURLOPT_INFILESIZE] = (int)$options['source_bytes'];
        } elseif (isset($options['body'])) {
            $opts[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['sink'])) {
            $fp = fopen($options['sink'], 'wb');
            if ($fp === false) {
                throw new RuntimeException('无法写入临时文件: ' . $options['sink']);
            }
            $opts[CURLOPT_FILE] = $fp;
            $opts[CURLOPT_RETURNTRANSFER] = false;
            $opts[CURLOPT_FOLLOWLOCATION] = true;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close($ch); // PHP 8.0+ 不再需要手动关闭

        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
            if ($status < 200 || $status >= 300) {
                @unlink($options['sink']);
            }
        }

        if ($response === false && $error !== '') {
            throw new RuntimeException('S3 请求失败: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $body = is_string($response) ? $response : '';
            $errorMessage = $this->parseS3Error($status, $body);
            
            // 针对 HTTP 403 提供更详细的诊断信息
            if ($status === 403) {
                $diagnostics = [];
                $diagnostics[] = 'Access Key/Secret Key 可能权限不足';
                $diagnostics[] = 'Bucket 策略可能限制写入操作';
                if (!empty($this->config['endpoint'])) {
                    $diagnostics[] = 'MinIO 用户权限配置可能不正确';
                    if (empty($this->config['use_path_style'])) {
                        $diagnostics[] = '建议尝试设置 use_path_style=1（MinIO 通常需要路径样式访问）';
                    }
                }
                $errorMessage .= ' | 可能原因：' . implode('；', $diagnostics);
            }
            
            throw new RuntimeException('S3 请求失败: HTTP ' . $status . ' - ' . $errorMessage);
        }

        return [
            'status' => $status,
            'body' => isset($options['sink']) ? null : $response,
        ];
    }

    private function downloadToTemp(string $storageKey): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 's3_download_');
        if ($tmpFile === false) {
            throw new RuntimeException('无法创建临时文件用于下载');
        }
        try {
            $this->sendRequest('GET', $storageKey, [
                'sink' => $tmpFile,
            ]);
        } catch (Throwable $e) {
            @unlink($tmpFile);
            throw $e;
        }
        return $tmpFile;
    }

    private function buildRequestParts(string $objectKey): array
    {
        $objectKey = ltrim($objectKey, '/');
        $encodedKey = $this->encodeKey($objectKey);

        $useHttps = ($this->config['use_https'] ?? true) !== false;
        $defaultScheme = $useHttps ? 'https' : 'http';
        $endpoint = $this->config['endpoint'] ?? '';
        $usePathStyle = (bool)($this->config['use_path_style'] ?? false);

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

    private function parseS3Error(int $status, string $body): string
    {
        if (empty($body)) {
            return '无错误详情';
        }
        
        // 尝试解析 XML 错误响应（S3/MinIO 标准格式）
        if (preg_match('/<Code>(.*?)<\/Code>/s', $body, $codeMatch)) {
            $code = trim($codeMatch[1]);
            $message = '';
            if (preg_match('/<Message>(.*?)<\/Message>/s', $body, $msgMatch)) {
                $message = trim($msgMatch[1]);
            }
            if ($message) {
                return $code . ': ' . $message;
            }
            return $code;
        }
        
        // 如果不是 XML，尝试解析 JSON
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($json['message'])) {
            return $json['message'];
        }
        
        // 返回原始响应（截断过长的内容）
        return strlen($body) > 200 ? substr($body, 0, 200) . '...' : $body;
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim($path, '/');
        $normalized = preg_replace('#/+#', '/', $normalized);
        if ($normalized === '') {
            return '/';
        }
        return $normalized;
    }

    private function canonicalQueryString(array $query): string
    {
        if (empty($query)) {
            return '';
        }
        $segments = [];
        ksort($query);
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                sort($value);
                foreach ($value as $item) {
                    $segments[] = $this->rawEncode($key) . '=' . $this->rawEncode((string)$item);
                }
            } else {
                $segments[] = $this->rawEncode($key) . '=' . $this->rawEncode((string)$value);
            }
        }
        return implode('&', $segments);
    }

    private function signRequest(
        string $method,
        string $canonicalUri,
        string $canonicalQuery,
        array $headers,
        string $payloadHash,
        string $hostHeader
    ): array {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = trim((string)$value);
        }
        $normalizedHeaders['host'] = strtolower($hostHeader);
        $normalizedHeaders['x-amz-date'] = $amzDate;
        $normalizedHeaders['x-amz-content-sha256'] = $payloadHash;
        if (!empty($this->config['session_token'])) {
            $normalizedHeaders['x-amz-security-token'] = $this->config['session_token'];
        }
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

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'headers' => $normalizedHeaders,
            'authorization' => $algorithm
                . ' Credential=' . $this->accessKey . '/' . $credentialScope
                . ', SignedHeaders=' . $signedHeaders
                . ', Signature=' . $signature,
        ];
    }

    private function signingKey(string $dateStamp)
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function buildPresignedUrl(string $storageKey, int $expiresIn): string
    {
        $objectKey = $this->applyPrefix($storageKey);
        [$baseUrl, $hostHeader, $canonicalUri] = $this->buildRequestParts($objectKey);

        $expires = max(1, min(604800, $expiresIn));
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if (!empty($this->config['session_token'])) {
            $query['X-Amz-Security-Token'] = $this->config['session_token'];
        }

        $canonicalQuery = $this->canonicalQueryString($query);
        $canonicalHeaders = 'host:' . strtolower($hostHeader) . "\n";
        $canonicalRequest = implode("\n", [
            'GET',
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
        $encoded = array_map([$this, 'rawEncode'], $segments);
        return implode('/', $encoded);
    }

    private function rawEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}

function storage_provider(): StorageProviderInterface
{
    static $provider = null;
    if ($provider !== null) {
        return $provider;
    }

    $config = storage_config();
    $limits = $config['limits'] ?? [];
    $driver = $config['type'] ?? 'local';

    if ($driver === 's3') {
        $provider = new S3StorageProvider($config['s3'] ?? [], $limits);
    } else {
        $provider = new LocalStorageProvider($config['local'] ?? [], $limits);
    }

    return $provider;
}

