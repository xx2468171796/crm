<?php
/**
 * 添加设计师主管角色到 roles 表
 * 运行方式：通过浏览器访问或命令行执行
 */

require_once __DIR__ . '/../core/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Db::pdo();
    
    // 检查是否已存在
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE code = 'design_manager'");
    $stmt->execute();
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo "✓ design_manager 角色已存在 (ID: {$existing['id']})\n";
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
            'all_data_view', // 可查看所有数据
        ]);
        
        $stmt = $pdo->prepare("
            INSERT INTO roles (code, name, description, permissions, is_system, status, create_time)
            VALUES ('design_manager', '设计师主管', '可管理项目、分配人员、设置提成、审批文件，但无财务权限', ?, 1, 1, ?)
        ");
        $stmt->execute([$permissions, time()]);
        
        $newId = $pdo->lastInsertId();
        echo "✓ design_manager 角色已创建 (ID: {$newId})\n";
    }
    
    // 列出所有角色
    echo "\n当前所有角色:\n";
    echo str_repeat('-', 60) . "\n";
    $roles = Db::query('SELECT id, code, name FROM roles WHERE status = 1 ORDER BY id');
    foreach ($roles as $r) {
        echo sprintf("%-4s | %-20s | %s\n", $r['id'], $r['code'], $r['name']);
    }
    
    echo "\n使用方法:\n";
    echo "1. 在后台【员工管理】页面编辑用户，选择【设计师主管】角色\n";
    echo "2. 或直接执行 SQL: UPDATE users SET role = 'design_manager' WHERE username = '用户名';\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
