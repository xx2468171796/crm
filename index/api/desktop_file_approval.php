<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端文件审批 API
 * 
 * GET: 获取待审批文件列表
 * POST action=submit: 提交文件审批
 * POST action=approve: 审批通过
 * POST action=reject: 审批拒绝
 */

// CORS 配置
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';

// 验证 Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';
if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '未登录']);
    exit;
}

$tokenRecord = Db::queryOne('SELECT user_id, expire_at FROM desktop_tokens WHERE token = ? LIMIT 1', [$token]);
if (!$tokenRecord || $tokenRecord['expire_at'] < time()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token 无效或已过期']);
    exit;
}

$user = Db::queryOne('SELECT id, username, realname as name, role, department_id FROM users WHERE id = ? AND status = 1 LIMIT 1', [$tokenRecord['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户不存在或已禁用']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = Db::pdo();

function tableColumns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['Field']] = strtolower((string)($row['Type'] ?? ''));
    }
    $cache[$table] = $cols;
    return $cols;
}

function isIntColumnType(?string $type): bool {
    if (!$type) return false;
    return str_contains($type, 'int');
}

$faCols = tableColumns($pdo, 'file_approvals');
$cfCols = tableColumns($pdo, 'customer_files');

$cfNameExpr = isset($cfCols['original_name']) ? 'cf.original_name' : (isset($cfCols['filename']) ? 'cf.filename' : "''");
$cfSizeExpr = isset($cfCols['file_size']) ? 'cf.file_size' : (isset($cfCols['filesize']) ? 'cf.filesize' : '0');
$cfFolderExpr = isset($cfCols['folder_type']) ? 'cf.folder_type' : (isset($cfCols['folder_path']) ? 'cf.folder_path' : "''");
$cfUploaderExpr = isset($cfCols['uploader_id']) ? 'cf.uploader_id' : (isset($cfCols['uploaded_by']) ? 'cf.uploaded_by' : 'NULL');

$hasProjectId = isset($cfCols['project_id']);
$hasStatus = isset($cfCols['status']);

$submitIsInt = isIntColumnType($faCols['submit_time'] ?? null);
$reviewIsInt = isIntColumnType($faCols['review_time'] ?? null);

if ($method === 'GET') {
    // 获取待审批文件列表
    $status = $_GET['status'] ?? 'pending';
    $projectId = (int)($_GET['project_id'] ?? 0);
    $submitterId = (int)($_GET['submitter_id'] ?? 0);
    $departmentId = (int)($_GET['department_id'] ?? 0);
    $folderType = $_GET['folder_type'] ?? '';
    
    try {
        $conditions = ["fa.status = ?"];
        $params = [$status];
        
        if ($projectId > 0 && $hasProjectId) {
            $conditions[] = "cf.project_id = ?";
            $params[] = $projectId;
        }
        
        // 按提交人筛选
        if ($submitterId > 0) {
            $conditions[] = "fa.submitter_id = ?";
            $params[] = $submitterId;
        }
        
        // 按部门筛选
        if ($departmentId > 0) {
            $conditions[] = "u_sub.department_id = ?";
            $params[] = $departmentId;
        }
        
        // 按文件类型筛选
        if ($folderType && $cfFolderExpr !== "''") {
            $conditions[] = "{$cfFolderExpr} = ?";
            $params[] = $folderType;
        }
        
        // 管理员/主管可以看到所有待审批文件
        // 技术只能看到自己提交的
        if ($user['role'] !== 'admin' && $user['role'] !== 'tech_lead') {
            $conditions[] = "fa.submitter_id = ?";
            $params[] = $user['id'];
        }
        
        $whereClause = implode(' AND ', $conditions);
        
        $projectSelect = $hasProjectId
            ? "p.id as project_id, p.project_name, p.project_code,"
            : "NULL as project_id, NULL as project_name, NULL as project_code,";
        $projectJoin = $hasProjectId ? "LEFT JOIN projects p ON cf.project_id = p.id" : "";

        $stmt = $pdo->prepare("
            SELECT 
                fa.id as approval_id,
                fa.file_id,
                fa.status as approval_status,
                fa.submitter_id,
                fa.reviewer_id,
                fa.submit_time,
                fa.review_time,
                fa.review_note,
                {$cfNameExpr} as original_name,
                {$cfSizeExpr} as file_size,
                cf.mime_type,
                {$cfFolderExpr} as folder_type,
                {$projectSelect}
                c.name as customer_name,
                c.group_name,
                c.group_code,
                u_sub.name as submitter_name,
                u_rev.name as reviewer_name
            FROM file_approvals fa
            JOIN customer_files cf ON fa.file_id = cf.id
            {$projectJoin}
            LEFT JOIN customers c ON cf.customer_id = c.id
            LEFT JOIN users u_sub ON fa.submitter_id = u_sub.id
            LEFT JOIN users u_rev ON fa.reviewer_id = u_rev.id
            WHERE {$whereClause}
            ORDER BY fa.submit_time DESC
        ");
        $stmt->execute($params);
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 按文件夹分组
        $groupedApprovals = [];
        foreach ($approvals as $approval) {
            $folderKey = ($approval['project_id'] ?? 0) . '_' . ($approval['folder_type'] ?? '未分类');
            if (!isset($groupedApprovals[$folderKey])) {
                $groupedApprovals[$folderKey] = [
                    'folder_key' => $folderKey,
                    'project_id' => $approval['project_id'],
                    'project_name' => $approval['project_name'],
                    'project_code' => $approval['project_code'],
                    'customer_name' => $approval['customer_name'],
                    'group_name' => $approval['group_name'],
                    'group_code' => $approval['group_code'] ?? null,
                    'folder_type' => $approval['folder_type'] ?? '未分类',
                    'file_count' => 0,
                    'files' => [],
                ];
            }
            $groupedApprovals[$folderKey]['file_count']++;
            $groupedApprovals[$folderKey]['files'][] = $approval;
        }
        $folders = array_values($groupedApprovals);
        
        // 获取提交人列表（用于筛选）
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.realname as name
            FROM file_approvals fa
            JOIN users u ON fa.submitter_id = u.id
            ORDER BY u.realname
        ");
        $stmt->execute();
        $submitters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取部门列表（用于筛选）
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id, d.name
            FROM file_approvals fa
            JOIN users u ON fa.submitter_id = u.id
            JOIN departments d ON u.department_id = d.id
            ORDER BY d.name
        ");
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取文件类型列表（用于筛选）
        $folderTypes = [];
        if ($cfFolderExpr !== "''") {
            $stmt = $pdo->prepare("
                SELECT DISTINCT {$cfFolderExpr} as folder_type
                FROM file_approvals fa
                JOIN customer_files cf ON fa.file_id = cf.id
                WHERE {$cfFolderExpr} IS NOT NULL AND {$cfFolderExpr} != ''
                ORDER BY {$cfFolderExpr}
            ");
            $stmt->execute();
            $folderTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        echo json_encode([
            'success' => true,
            'approvals' => $approvals,
            'folders' => $folders,
            'total' => count($approvals),
            'submitters' => $submitters,
            'departments' => $departments,
            'folder_types' => $folderTypes,
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'submit':
            // 提交文件审批
            $fileId = (int)($input['file_id'] ?? 0);
            
            if ($fileId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '文件ID无效']);
                exit;
            }
            
            try {
                // 检查文件是否存在
                $stmt = $pdo->prepare("SELECT * FROM customer_files WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$fileId]);
                $file = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$file) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => '文件不存在']);
                    exit;
                }
                
                // 检查是否已有待审批记录
                $stmt = $pdo->prepare("SELECT id FROM file_approvals WHERE file_id = ? AND status = 'pending'");
                $stmt->execute([$fileId]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'error' => '该文件已有待审批记录']);
                    exit;
                }
                
                // 创建审批记录
                $now = time();
                if ($submitIsInt) {
                    $stmt = $pdo->prepare("
                        INSERT INTO file_approvals (file_id, submitter_id, status, submit_time)
                        VALUES (?, ?, 'pending', ?)
                    ");
                    $stmt->execute([$fileId, $user['id'], $now]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO file_approvals (file_id, submitter_id, status, submit_time)
                        VALUES (?, ?, 'pending', NOW())
                    ");
                    $stmt->execute([$fileId, $user['id']]);
                }
                $approvalId = $pdo->lastInsertId();
                
                // 更新文件状态
                if ($hasStatus) {
                    $stmt = $pdo->prepare("UPDATE customer_files SET status = 'pending_approval' WHERE id = ?");
                    $stmt->execute([$fileId]);
                }
                
                // 发送通知给管理员/主管
                $stmt = $pdo->prepare("
                    SELECT id FROM users WHERE role IN ('admin', 'tech_lead') AND status = 'active'
                ");
                $stmt->execute();
                $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $notifyTime = time();
                $fileDisplayName = $file['original_name'] ?? ($file['filename'] ?? '');
                foreach ($managers as $managerId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, create_time)
                        VALUES (?, 'file_approval', '新文件待审批', ?, 'file', ?, ?)
                    ");
                    $stmt->execute([
                        $managerId,
                        "技术 {$user['name']} 提交了文件 {$fileDisplayName} 待审批",
                        $fileId,
                        $notifyTime
                    ]);
                }
                
                echo json_encode([
                    'success' => true,
                    'approval_id' => $approvalId,
                    'message' => '审批已提交',
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
            }
            break;
            
        case 'approve':
        case 'reject':
            // 审批通过/拒绝
            if ($user['role'] !== 'admin' && $user['role'] !== 'tech_lead') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => '无权限审批']);
                exit;
            }
            
            $approvalId = (int)($input['approval_id'] ?? 0);
            $note = $input['note'] ?? '';
            
            if ($approvalId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '审批ID无效']);
                exit;
            }
            
            try {
                // 获取审批记录
                $stmt = $pdo->prepare("
                    SELECT fa.*, {$cfNameExpr} as original_name, {$cfUploaderExpr} as uploader_id
                    FROM file_approvals fa
                    JOIN customer_files cf ON fa.file_id = cf.id
                    WHERE fa.id = ? AND fa.status = 'pending'
                ");
                $stmt->execute([$approvalId]);
                $approval = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$approval) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => '审批记录不存在或已处理']);
                    exit;
                }
                
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';
                $fileStatus = $action === 'approve' ? 'approved' : 'rejected';
                
                // 更新审批记录
                $now = time();
                if ($reviewIsInt) {
                    $stmt = $pdo->prepare("
                        UPDATE file_approvals 
                        SET status = ?, reviewer_id = ?, review_time = ?, review_note = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newStatus, $user['id'], $now, $note, $approvalId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE file_approvals 
                        SET status = ?, reviewer_id = ?, review_time = NOW(), review_note = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newStatus, $user['id'], $note, $approvalId]);
                }
                
                // 更新文件状态
                if ($hasStatus) {
                    $stmt = $pdo->prepare("UPDATE customer_files SET status = ? WHERE id = ?");
                    $stmt->execute([$fileStatus, $approval['file_id']]);
                }
                
                // 通知提交者
                $resultText = $action === 'approve' ? '已通过' : '已拒绝';
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, content, related_type, related_id, create_time)
                    VALUES (?, 'file_approval_result', ?, ?, 'file', ?, ?)
                ");
                $stmt->execute([
                    $approval['submitter_id'],
                    "文件审批{$resultText}",
                    "您提交的文件 {$approval['original_name']} {$resultText}" . ($note ? "，备注：{$note}" : ''),
                    $approval['file_id'],
                    time()
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => "审批{$resultText}",
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
            }
            break;
        
        case 'batch_approve':
        case 'batch_reject':
            // 批量审批（文件夹级别）
            if ($user['role'] !== 'admin' && $user['role'] !== 'tech_lead') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => '无权限审批']);
                exit;
            }
            
            $approvalIds = $input['approval_ids'] ?? [];
            $note = $input['note'] ?? '';
            
            if (empty($approvalIds) || !is_array($approvalIds)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '审批ID列表无效']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                $newStatus = $action === 'batch_approve' ? 'approved' : 'rejected';
                $fileStatus = $action === 'batch_approve' ? 'approved' : 'rejected';
                $successCount = 0;
                $notifyUsers = [];
                
                foreach ($approvalIds as $approvalId) {
                    $approvalId = (int)$approvalId;
                    if ($approvalId <= 0) continue;
                    
                    // 获取审批记录
                    $stmt = $pdo->prepare("
                        SELECT fa.*, {$cfNameExpr} as original_name
                        FROM file_approvals fa
                        JOIN customer_files cf ON fa.file_id = cf.id
                        WHERE fa.id = ? AND fa.status = 'pending'
                    ");
                    $stmt->execute([$approvalId]);
                    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$approval) continue;
                    
                    // 更新审批记录
                    $now = time();
                    if ($reviewIsInt) {
                        $stmt = $pdo->prepare("
                            UPDATE file_approvals 
                            SET status = ?, reviewer_id = ?, review_time = ?, review_note = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$newStatus, $user['id'], $now, $note, $approvalId]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE file_approvals 
                            SET status = ?, reviewer_id = ?, review_time = NOW(), review_note = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$newStatus, $user['id'], $note, $approvalId]);
                    }
                    
                    // 更新文件状态
                    if ($hasStatus) {
                        $stmt = $pdo->prepare("UPDATE customer_files SET status = ? WHERE id = ?");
                        $stmt->execute([$fileStatus, $approval['file_id']]);
                    }
                    
                    // 记录需要通知的用户
                    if (!isset($notifyUsers[$approval['submitter_id']])) {
                        $notifyUsers[$approval['submitter_id']] = [];
                    }
                    $notifyUsers[$approval['submitter_id']][] = $approval['original_name'];
                    
                    $successCount++;
                }
                
                // 批量发送通知
                $resultText = $action === 'batch_approve' ? '已通过' : '已拒绝';
                $batchNotifyTime = time();
                foreach ($notifyUsers as $submitterId => $fileNames) {
                    $fileCount = count($fileNames);
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, content, related_type, related_id, create_time)
                        VALUES (?, 'file_approval_result', ?, ?, 'batch', 0, ?)
                    ");
                    $stmt->execute([
                        $submitterId,
                        "批量文件审批{$resultText}",
                        "您提交的 {$fileCount} 个文件{$resultText}" . ($note ? "，备注：{$note}" : ''),
                        $batchNotifyTime
                    ]);
                }
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => "已批量{$resultText} {$successCount} 个文件",
                    'count' => $successCount,
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '无效的操作']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
}
