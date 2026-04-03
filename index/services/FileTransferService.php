<?php
/**
 * 统一文件传输服务
 * 
 * 提供统一的上传/下载接口，自动检测环境选择直连或代理模式
 * 支持小文件直传、大文件分片上传、进度跟踪
 */

require_once __DIR__ . '/../core/storage/storage_provider.php';
require_once __DIR__ . '/MultipartUploadService.php';

class FileTransferService
{
    private array $config;
    private array $s3Config;
    private array $uploadConfig;
    private string $progressDir;
    
    public function __construct()
    {
        $this->config = storage_config();
        $this->s3Config = $this->config['s3'] ?? [];
        $this->uploadConfig = $this->config['upload'] ?? [];
        
        // 初始化进度目录
        $this->progressDir = $this->uploadConfig['progress_dir'] ?? sys_get_temp_dir() . '/file_transfer_progress';
        if (!is_dir($this->progressDir)) {
            @mkdir($this->progressDir, 0755, true);
        }
    }
    
    /**
     * 检测传输模式
     * 
     * @return string 'direct' 或 'proxy'
     */
    public function detectMode(): string
    {
        $mode = $this->uploadConfig['mode'] ?? 'auto';
        
        // 强制模式
        if ($mode === 'proxy') {
            return 'proxy';
        }
        if ($mode === 'direct') {
            return 'direct';
        }
        
        // 自动检测
        $s3UseHttps = $this->s3Config['use_https'] ?? false;
        $siteUseHttps = $this->isSiteHttps();
        
        // 协议一致时直连，否则代理
        if ($s3UseHttps === $siteUseHttps) {
            return 'direct';
        }
        
        return 'proxy';
    }
    
    /**
     * 检测当前网站是否使用 HTTPS
     */
    private function isSiteHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        }
        return false;
    }
    
    /**
     * 初始化上传
     * 
     * @param string $filename 文件名
     * @param int $filesize 文件大小
     * @param string $storageKey 存储路径
     * @param string $mimeType MIME类型
     * @return array
     */
    public function initUpload(string $filename, int $filesize, string $storageKey, string $mimeType = 'application/octet-stream'): array
    {
        $transferId = $this->generateTransferId();
        $mode = $this->detectMode();
        $chunkThreshold = $this->uploadConfig['chunk_threshold'] ?? 10 * 1024 * 1024;
        $chunkSize = $this->uploadConfig['chunk_size'] ?? 10 * 1024 * 1024;
        
        $useChunked = $filesize > $chunkThreshold;
        $totalParts = $useChunked ? (int)ceil($filesize / $chunkSize) : 1;
        
        $uploadId = null;
        if ($useChunked) {
            // 初始化分片上传
            $multipart = new MultipartUploadService();
            $initResult = $multipart->initiate($storageKey, $mimeType);
            $uploadId = $initResult['upload_id'];
        }
        
        // 初始化进度
        $this->updateProgress($transferId, [
            'transfer_id' => $transferId,
            'type' => 'upload',
            'status' => 'pending',
            'filename' => $filename,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'total_size' => $filesize,
            'transferred' => 0,
            'progress' => 0,
            'mode' => $mode,
            'chunked' => $useChunked,
            'upload_id' => $uploadId,
            'total_parts' => $totalParts,
            'chunk_size' => $chunkSize,
            'completed_parts' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        
        return [
            'transfer_id' => $transferId,
            'mode' => $mode,
            'chunked' => $useChunked,
            'upload_id' => $uploadId,
            'total_parts' => $totalParts,
            'chunk_size' => $chunkSize,
            'storage_key' => $storageKey,
        ];
    }
    
    /**
     * 上传分片（代理模式）
     * 
     * @param string $transferId 传输ID
     * @param int $partNumber 分片编号
     * @param string $chunkData 分片数据
     * @return array
     */
    public function uploadChunk(string $transferId, int $partNumber, string $chunkData): array
    {
        $progress = $this->getProgress($transferId);
        if (!$progress) {
            throw new Exception('传输不存在或已过期');
        }
        
        $uploadId = $progress['upload_id'];
        $storageKey = $progress['storage_key'];
        
        // 获取预签名URL并上传
        $multipart = new MultipartUploadService();
        $presignedUrl = $multipart->getPartPresignedUrl($storageKey, $uploadId, $partNumber, 3600);
        
        // 使用 cURL 上传到 S3
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $presignedUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $chunkData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($chunkData),
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('上传分片失败: ' . $error);
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $body = substr($response, $headerSize);
            throw new Exception('S3 返回错误: HTTP ' . $httpCode);
        }
        
        // 从响应头中提取 ETag
        $headers = substr($response, 0, $headerSize);
        $etag = '';
        if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $headers, $matches)) {
            $etag = trim($matches[1], '"');
        }
        
        // 更新进度
        $completedParts = $progress['completed_parts'] ?? [];
        $completedParts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
        
        $transferred = count($completedParts) * ($progress['chunk_size'] ?? 0);
        $transferred = min($transferred, $progress['total_size']);
        $progressPct = $progress['total_size'] > 0 ? (int)round(($transferred / $progress['total_size']) * 100) : 0;
        
        $this->updateProgress($transferId, [
            'status' => 'uploading',
            'transferred' => $transferred,
            'progress' => $progressPct,
            'completed_parts' => $completedParts,
            'updated_at' => time(),
        ]);
        
        return [
            'part_number' => $partNumber,
            'etag' => $etag,
            'progress' => $progressPct,
            'transferred' => $transferred,
        ];
    }
    
    /**
     * 完成上传
     * 
     * @param string $transferId 传输ID
     * @return array
     */
    public function completeUpload(string $transferId): array
    {
        $progress = $this->getProgress($transferId);
        if (!$progress) {
            throw new Exception('传输不存在或已过期');
        }
        
        if ($progress['chunked']) {
            // 完成分片上传
            $multipart = new MultipartUploadService();
            $result = $multipart->complete(
                $progress['storage_key'],
                $progress['upload_id'],
                $progress['completed_parts']
            );
            
            $this->updateProgress($transferId, [
                'status' => 'completed',
                'progress' => 100,
                'transferred' => $progress['total_size'],
                'etag' => $result['etag'] ?? '',
                'updated_at' => time(),
            ]);
            
            return [
                'success' => true,
                'storage_key' => $progress['storage_key'],
                'etag' => $result['etag'] ?? '',
                'location' => $result['location'] ?? '',
            ];
        }
        
        // 非分片上传已在 uploadDirect 中完成
        return [
            'success' => true,
            'storage_key' => $progress['storage_key'],
        ];
    }
    
    /**
     * 直接上传小文件
     * 
     * @param string $transferId 传输ID
     * @param string $tmpPath 临时文件路径
     * @return array
     */
    public function uploadDirect(string $transferId, string $tmpPath): array
    {
        $progress = $this->getProgress($transferId);
        if (!$progress) {
            throw new Exception('传输不存在或已过期');
        }
        
        $this->updateProgress($transferId, [
            'status' => 'uploading',
            'updated_at' => time(),
        ]);
        
        $storage = storage_provider();
        $result = $storage->putObject($progress['storage_key'], $tmpPath, [
            'mime_type' => $progress['mime_type'],
        ]);
        
        $this->updateProgress($transferId, [
            'status' => 'completed',
            'progress' => 100,
            'transferred' => $progress['total_size'],
            'updated_at' => time(),
        ]);
        
        return [
            'success' => true,
            'storage_key' => $progress['storage_key'],
            'bytes' => $result['bytes'] ?? $progress['total_size'],
        ];
    }
    
    /**
     * 代理下载文件
     * 
     * @param string $url S3 文件 URL
     * @param string|null $filename 下载文件名
     */
    public function proxyDownload(string $url, ?string $filename = null): void
    {
        // 验证URL是否合法
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            http_response_code(400);
            die('无效的URL');
        }
        
        // 只允许访问配置的S3端点
        $s3Endpoint = $this->s3Config['endpoint'] ?? '';
        $s3PublicUrl = $this->s3Config['public_url'] ?? '';
        $s3Host = parse_url($s3Endpoint, PHP_URL_HOST);
        $s3PublicHost = $s3PublicUrl ? parse_url($s3PublicUrl, PHP_URL_HOST) : null;
        
        $allowedHosts = array_filter([$s3Host, $s3PublicHost, 'localhost', '127.0.0.1']);
        if (!in_array($parsedUrl['host'], $allowedHosts)) {
            http_response_code(403);
            die('不允许访问该URL');
        }
        
        // 使用cURL获取文件
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) return $len;
            $headers[strtolower(trim($header[0]))] = trim($header[1]);
            return $len;
        });
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            http_response_code(500);
            die('获取文件失败: ' . $error);
        }
        
        if ($httpCode !== 200) {
            http_response_code($httpCode);
            die('获取文件失败: HTTP ' . $httpCode);
        }
        
        // 设置响应头
        if (isset($headers['content-type'])) {
            header('Content-Type: ' . $headers['content-type']);
        }
        
        if (isset($headers['content-length'])) {
            header('Content-Length: ' . $headers['content-length']);
        } else {
            header('Content-Length: ' . strlen($content));
        }
        
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');
        
        if ($filename) {
            $encodedFilename = rawurlencode($filename);
            header("Content-Disposition: attachment; filename=\"{$filename}\"; filename*=UTF-8''{$encodedFilename}");
        }
        
        echo $content;
    }
    
    /**
     * 获取进度
     */
    public function getProgress(string $transferId): ?array
    {
        $file = $this->progressDir . '/' . $transferId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        
        $data = @json_decode(file_get_contents($file), true);
        if (!$data) {
            return null;
        }
        
        // 检查是否过期
        $ttl = $this->uploadConfig['progress_ttl'] ?? 86400;
        if (time() - ($data['created_at'] ?? 0) > $ttl) {
            @unlink($file);
            return null;
        }
        
        // 计算速度和剩余时间
        if ($data['status'] === 'uploading' && $data['transferred'] > 0) {
            $elapsed = time() - ($data['created_at'] ?? time());
            if ($elapsed > 0) {
                $data['speed'] = (int)($data['transferred'] / $elapsed);
                $remaining = $data['total_size'] - $data['transferred'];
                $data['eta'] = $data['speed'] > 0 ? (int)ceil($remaining / $data['speed']) : 0;
            }
        }
        
        return $data;
    }
    
    /**
     * 更新进度
     */
    public function updateProgress(string $transferId, array $data): void
    {
        $file = $this->progressDir . '/' . $transferId . '.json';
        
        $existing = [];
        if (file_exists($file)) {
            $existing = @json_decode(file_get_contents($file), true) ?: [];
        }
        
        $merged = array_merge($existing, $data);
        file_put_contents($file, json_encode($merged, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * 删除进度
     */
    public function deleteProgress(string $transferId): void
    {
        $file = $this->progressDir . '/' . $transferId . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    /**
     * 生成传输ID
     */
    private function generateTransferId(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * 获取预签名URL（直连模式）
     */
    public function getPresignedUploadUrl(string $storageKey, int $expiresIn = 3600): string
    {
        $multipart = new MultipartUploadService();
        return $multipart->getDownloadPresignedUrl($storageKey, $expiresIn);
    }
    
    /**
     * 清理过期进度文件
     */
    public function cleanupExpiredProgress(): int
    {
        $ttl = $this->uploadConfig['progress_ttl'] ?? 86400;
        $count = 0;
        
        $files = glob($this->progressDir . '/*.json');
        foreach ($files as $file) {
            $data = @json_decode(file_get_contents($file), true);
            if (!$data || time() - ($data['created_at'] ?? 0) > $ttl) {
                @unlink($file);
                $count++;
            }
        }
        
        return $count;
    }
}
