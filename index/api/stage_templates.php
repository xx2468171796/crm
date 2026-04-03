<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 项目阶段时间模板管理 API
 * 支持：GET(列表) / POST(创建/更新) / DELETE(删除)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

$user = current_user();
if (!$user) {
    echo json_encode(['success' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 只有管理员可以管理模板
if (!isAdmin($user)) {
    echo json_encode(['success' => false, 'message' => '无权限操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // 获取所有模板
            $templates = Db::query("
                SELECT * FROM project_stage_templates 
                WHERE is_active = 1 
                ORDER BY stage_order ASC
            ");
            echo json_encode([
                'success' => true,
                'data' => $templates
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = intval($input['id'] ?? 0);
            $stageFrom = trim($input['stage_from'] ?? '');
            $stageTo = trim($input['stage_to'] ?? '');
            $stageOrder = intval($input['stage_order'] ?? 0);
            $defaultDays = intval($input['default_days'] ?? 1);
            $description = trim($input['description'] ?? '');

            if ($stageFrom === '' || $stageTo === '') {
                echo json_encode(['success' => false, 'message' => '阶段名称不能为空'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($defaultDays < 1) {
                $defaultDays = 1;
            }

            $now = date('Y-m-d H:i:s');
            $userId = $user['id'];

            if ($id > 0) {
                // 更新
                Db::execute("
                    UPDATE project_stage_templates SET
                        stage_from = :stage_from,
                        stage_to = :stage_to,
                        stage_order = :stage_order,
                        default_days = :default_days,
                        description = :description,
                        updated_at = :updated_at,
                        updated_by = :updated_by
                    WHERE id = :id
                ", [
                    'stage_from' => $stageFrom,
                    'stage_to' => $stageTo,
                    'stage_order' => $stageOrder,
                    'default_days' => $defaultDays,
                    'description' => $description,
                    'updated_at' => $now,
                    'updated_by' => $userId,
                    'id' => $id
                ]);
                echo json_encode(['success' => true, 'message' => '模板更新成功'], JSON_UNESCAPED_UNICODE);
            } else {
                // 新增
                Db::execute("
                    INSERT INTO project_stage_templates 
                    (stage_from, stage_to, stage_order, default_days, description, is_active, created_at, created_by)
                    VALUES 
                    (:stage_from, :stage_to, :stage_order, :default_days, :description, 1, :created_at, :created_by)
                ", [
                    'stage_from' => $stageFrom,
                    'stage_to' => $stageTo,
                    'stage_order' => $stageOrder,
                    'default_days' => $defaultDays,
                    'description' => $description,
                    'created_at' => $now,
                    'created_by' => $userId
                ]);
                echo json_encode(['success' => true, 'message' => '模板创建成功'], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            $id = intval($input['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => '无效的模板ID'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            Db::execute("UPDATE project_stage_templates SET is_active = 0 WHERE id = :id", ['id' => $id]);
            echo json_encode(['success' => true, 'message' => '模板删除成功'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '不支持的请求方法'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log('[STAGE_TEMPLATE_API] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
