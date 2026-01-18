<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * OKR 评论管理 API
 * 提供评论的增删改查、点赞等功能
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// 检查登录
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$user = current_user();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            getCommentList();
            break;
            
        case 'save':
            saveComment();
            break;
            
        case 'delete':
            deleteComment();
            break;
            
        case 'like':
            toggleLike();
            break;
            
        default:
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 获取评论列表
 */
function getCommentList() {
    $targetType = trim($_GET['target_type'] ?? '');
    $targetId = intval($_GET['target_id'] ?? 0);
    
    if (empty($targetType) || $targetId <= 0) {
        throw new Exception('参数无效');
    }
    
    if (!in_array($targetType, ['okr', 'objective', 'kr', 'task'])) {
        throw new Exception('目标类型无效');
    }
    
    $comments = Db::query(
        'SELECT c.*, u.realname as user_name FROM okr_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.target_type = :target_type AND c.target_id = :target_id ORDER BY c.create_time ASC',
        ['target_type' => $targetType, 'target_id' => $targetId]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $comments
    ]);
}

/**
 * 保存评论（新增或更新）
 */
function saveComment() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    $targetType = trim($_POST['target_type'] ?? '');
    $targetId = intval($_POST['target_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $parentId = intval($_POST['parent_id'] ?? 0);
    
    if (empty($targetType) || $targetId <= 0) {
        throw new Exception('参数无效');
    }
    
    if (!in_array($targetType, ['okr', 'objective', 'kr', 'task'])) {
        throw new Exception('目标类型无效');
    }
    
    if (empty($content)) {
        throw new Exception('评论内容不能为空');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        $comment = Db::queryOne('SELECT * FROM okr_comments WHERE id = :id', ['id' => $id]);
        if (!$comment) {
            throw new Exception('评论不存在');
        }
        
        if ($comment['user_id'] != $user['id']) {
            throw new Exception('无权限编辑此评论');
        }
        
        Db::execute(
            'UPDATE okr_comments SET content = :content, update_time = :update_time WHERE id = :id',
            ['id' => $id, 'content' => $content, 'update_time' => $now]
        );
    } else {
        // 新增
        Db::execute(
            'INSERT INTO okr_comments (target_type, target_id, user_id, content, parent_id, likes, create_time, update_time) VALUES (:target_type, :target_id, :user_id, :content, :parent_id, 0, :create_time, :update_time)',
            [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'user_id' => $user['id'],
                'content' => $content,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'create_time' => $now,
                'update_time' => $now
            ]
        );
        $id = Db::lastInsertId();
        
        // 记录操作日志
        logOkrAction($targetType, $targetId, 'comment', null, ['comment_id' => $id]);
    }
    
    $comment = Db::queryOne(
        'SELECT c.*, u.realname as user_name FROM okr_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = :id',
        ['id' => $id]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $comment,
        'message' => $id > 0 ? '评论保存成功' : '评论创建成功'
    ]);
}

/**
 * 删除评论
 */
function deleteComment() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('评论ID无效');
    }
    
    $comment = Db::queryOne('SELECT * FROM okr_comments WHERE id = :id', ['id' => $id]);
    if (!$comment) {
        throw new Exception('评论不存在');
    }
    
    if ($comment['user_id'] != $user['id']) {
        throw new Exception('无权限删除此评论');
    }
    
    Db::execute('DELETE FROM okr_comments WHERE id = :id', ['id' => $id]);
    
    echo json_encode([
        'success' => true,
        'message' => '评论删除成功'
    ]);
}

/**
 * 点赞/取消点赞
 */
function toggleLike() {
    global $user;
    
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('评论ID无效');
    }
    
    $comment = Db::queryOne('SELECT * FROM okr_comments WHERE id = :id', ['id' => $id]);
    if (!$comment) {
        throw new Exception('评论不存在');
    }
    
    // 这里简化处理，直接增加点赞数（实际应该记录点赞用户，避免重复点赞）
    $likes = intval($comment['likes']) + 1;
    
    Db::execute(
        'UPDATE okr_comments SET likes = :likes WHERE id = :id',
        ['likes' => $likes, 'id' => $id]
    );
    
    echo json_encode([
        'success' => true,
        'data' => ['likes' => $likes],
        'message' => '点赞成功'
    ]);
}

/**
 * 记录 OKR 操作日志
 */
function logOkrAction($targetType, $targetId, $action, $oldValue = null, $newValue = null, $description = null) {
    global $user;
    
    Db::execute(
        'INSERT INTO okr_logs (target_type, target_id, user_id, action, old_value, new_value, description, create_time) VALUES (:target_type, :target_id, :user_id, :action, :old_value, :new_value, :description, :create_time)',
        [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'user_id' => $user['id'],
            'action' => $action,
            'old_value' => $oldValue ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
            'new_value' => $newValue ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
            'description' => $description,
            'create_time' => time()
        ]
    );
}

