<?php
/**
 * 初始化设计师主管角色 API
 * 访问此接口即可添加 design_manager 角色到数据库
 */

require_once __DIR__ . '/../core/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Db::pdo();
    
    // 检查是否已存在
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE code = 'design_manager'");
    $stmt->execute();
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo json_encode([
            'success' => true,
            'message' => 'design_manager 角色已存在',
            'role_id' => $existing['id']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 插入新角色
        $permissions = json_encode([
            'project_view',
            'project_create',
            'project_edit',
            'project_status_edit',
            'project_assign',
            'customer_view',
            'customer_edit',
            'customer_transfer',
            'file_upload',
            'file_delete',
            'portal_view',
            'portal_copy_link',
            'portal_view_password',
            'portal_edit_password',
            'all_data_view',
        ]);
        
        $stmt = $pdo->prepare("
            INSERT INTO roles (code, name, description, permissions, is_system, status, create_time)
            VALUES ('design_manager', '设计师主管', '可管理项目、分配人员、设置提成、审批文件，但无财务权限', ?, 1, 1, ?)
        ");
        $stmt->execute([$permissions, time()]);
        
        $newId = $pdo->lastInsertId();
        echo json_encode([
            'success' => true,
            'message' => 'design_manager 角色已创建',
            'role_id' => $newId
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
