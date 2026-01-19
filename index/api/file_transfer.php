<?php
/**
 * 统一文件传输 API
 * 
 * POST /api/file_transfer.php?action=init      - 初始化上传
 * POST /api/file_transfer.php?action=chunk     - 上传分片（代理模式）
 * POST /api/file_transfer.php?action=complete  - 完成上传
 * POST /api/file_transfer.php?action=direct    - 小文件直传
 * GET  /api/file_transfer.php?action=progress  - 获取进度
 * GET  /api/file_transfer.php?action=download  - 代理下载
 * GET  /api/file_transfer.php?action=mode      - 获取当前模式
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

require_once __DIR__ . '/../core/api_init.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../services/FileTransferService.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'mode';

// 下载和进度查询可能不需要登录（通过 token 验证）
$requireAuth = !in_array($action, ['download', 'progress', 'mode']);

if ($requireAuth) {
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $service = new FileTransferService();
    
    switch ($action) {
        case 'mode':
            // 获取当前传输模式
            echo json_encode([
                'success' => true,
                'data' => [
                    'mode' => $service->detectMode(),
                    'site_https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'init':
            // 初始化上传
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('仅支持 POST 请求');
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $filename = trim($input['filename'] ?? '');
            $filesize = intval($input['filesize'] ?? 0);
            $storageKey = trim($input['storage_key'] ?? '');
            $mimeType = trim($input['mime_type'] ?? 'application/octet-stream');
            
            if (empty($filename) || $filesize <= 0 || empty($storageKey)) {
                throw new Exception('缺少必要参数');
            }
            
            $result = $service->initUpload($filename, $filesize, $storageKey, $mimeType);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'chunk':
            // 上传分片（代理模式）
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('仅支持 POST 请求');
            }
            
            $transferId = trim($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? '');
            $partNumber = intval($_GET['part_number'] ?? $_POST['part_number'] ?? 0);
            
            if (empty($transferId) || $partNumber <= 0) {
                throw new Exception('缺少必要参数');
            }
            
            // 读取分片数据
            $chunkData = file_get_contents('php://input');
            if (empty($chunkData)) {
                throw new Exception('分片数据为空');
            }
            
            $result = $service->uploadChunk($transferId, $partNumber, $chunkData);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'complete':
            // 完成上传
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('仅支持 POST 请求');
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $transferId = trim($input['transfer_id'] ?? '');
            
            if (empty($transferId)) {
                throw new Exception('缺少传输ID');
            }
            
            $result = $service->completeUpload($transferId);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'direct':
            // 小文件直传
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('仅支持 POST 请求');
            }
            
            $transferId = trim($_POST['transfer_id'] ?? '');
            
            if (empty($transferId)) {
                throw new Exception('缺少传输ID');
            }
            
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败');
            }
            
            $result = $service->uploadDirect($transferId, $_FILES['file']['tmp_name']);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'progress':
            // 获取进度
            $transferId = trim($_GET['transfer_id'] ?? '');
            
            if (empty($transferId)) {
                throw new Exception('缺少传输ID');
            }
            
            $progress = $service->getProgress($transferId);
            
            if (!$progress) {
                throw new Exception('传输不存在或已过期');
            }
            
            echo json_encode([
                'success' => true,
                'data' => $progress
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'download':
            // 代理下载
            $url = $_GET['url'] ?? '';
            $filename = $_GET['filename'] ?? null;
            
            if (empty($url)) {
                throw new Exception('缺少URL参数');
            }
            
            // 清除之前的输出
            header_remove('Content-Type');
            
            $service->proxyDownload($url, $filename);
            exit;
            
        default:
            throw new Exception('未知操作: ' . $action);
    }
    
} catch (Exception $e) {
    error_log('[FileTransfer] 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
