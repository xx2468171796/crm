<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 文件删除 API
 * 
 * 权限规则：
 * - 待审批/被驳回：上传者可删除
 * - 已通过：仅主管/管理员可删除
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '仅支持 POST 请求']);
    exit;
}

// 验证 Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未提供认证信息']);
    exit;
}

$token = $matches[1];
$user = verifyDesktopToken($token);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '认证失败']);
    exit;
}

$currentUserId = $user['id'];
$currentUserRole = $user['role'] ?? '';

// 管理员角色列表
$managerRoles = ['admin', 'super_admin', 'manager', 'tech_manager'];
$isManager = in_array($currentUserRole, $managerRoles);

// 解析请求
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$fileId = $input['file_id'] ?? 0;

if ($action !== 'delete' || !$fileId) {
    echo json_encode(['success' => false, 'error' => '参数错误']);
    exit;
}

try {
    $pdo = Db::getInstance();
    
    // 获取文件信息
    $stmt = $pdo->prepare("
        SELECT r.id, r.filename, r.storage_key, r.uploader_id, r.folder_type,
               fa.id AS approval_id, fa.status AS approval_status
        FROM resources r
        LEFT JOIN file_approvals fa ON r.id = fa.resource_id
        WHERE r.id = ?
    ");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => '文件不存在']);
        exit;
    }
    
    $approvalStatus = $file['approval_status'] ?? 'pending';
    $uploaderId = $file['uploader_id'];
    
    // 权限检查
    $canDelete = false;
    $reason = '';
    
    if ($approvalStatus === 'pending' || $approvalStatus === 'rejected') {
        // 待审批或被驳回：上传者可删除
        if ($uploaderId == $currentUserId || $isManager) {
            $canDelete = true;
        } else {
            $reason = '只有上传者或管理员可以删除此文件';
        }
    } elseif ($approvalStatus === 'approved') {
        // 已通过：仅主管/管理员可删除
        if ($isManager) {
            $canDelete = true;
        } else {
            $reason = '已审批通过的文件只能由管理员删除';
        }
    } else {
        // 其他状态：仅管理员可删除
        if ($isManager) {
            $canDelete = true;
        } else {
            $reason = '只有管理员可以删除此文件';
        }
    }
    
    if (!$canDelete) {
        echo json_encode(['success' => false, 'error' => $reason]);
        exit;
    }
    
    // 开始删除
    $pdo->beginTransaction();
    
    try {
        // 删除审批记录
        if ($file['approval_id']) {
            $stmt = $pdo->prepare("DELETE FROM file_approvals WHERE id = ?");
            $stmt->execute([$file['approval_id']]);
        }
        
        // 删除文件记录
        $stmt = $pdo->prepare("DELETE FROM resources WHERE id = ?");
        $stmt->execute([$fileId]);
        
        // TODO: 删除 S3/MinIO 上的实际文件
        // 可选：保留文件，只删除记录
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '文件删除成功',
            'data' => [
                'file_id' => $fileId,
                'filename' => $file['filename'],
            ],
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    error_log('[desktop_file_delete] 删除失败: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => '删除失败: ' . $e->getMessage()]);
}
