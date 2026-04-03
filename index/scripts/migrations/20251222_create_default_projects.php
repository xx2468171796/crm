<?php
/**
 * 数据迁移脚本：为现有客户创建默认项目
 * 将现有客户数据平滑迁移到项目体系
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始数据迁移：为现有客户创建默认项目\n";

try {
    $pdo = Db::pdo();
    
    // 查询所有未删除的客户
    $stmt = $pdo->query("
        SELECT id, name, group_code, owner_user_id, create_time 
        FROM customers 
        WHERE deleted_at IS NULL
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "找到 " . count($customers) . " 个客户需要创建默认项目\n";
    
    $created = 0;
    $skipped = 0;
    
    foreach ($customers as $customer) {
        // 检查是否已有项目
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM projects WHERE customer_id = ? AND deleted_at IS NULL
        ");
        $checkStmt->execute([$customer['id']]);
        $existingCount = $checkStmt->fetchColumn();
        
        if ($existingCount > 0) {
            echo "  跳过客户 #{$customer['id']} {$customer['name']}（已有项目）\n";
            $skipped++;
            continue;
        }
        
        // 生成项目编号
        $year = date('Y');
        $projectCode = "PRJ-{$year}-" . str_pad($customer['id'], 4, '0', STR_PAD_LEFT);
        
        // 创建默认项目
        $insertStmt = $pdo->prepare("
            INSERT INTO projects (
                customer_id, 
                project_name, 
                project_code, 
                group_code,
                current_status,
                created_by,
                create_time,
                update_time
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $now = time();
        $insertStmt->execute([
            $customer['id'],
            '默认项目',
            $projectCode,
            $customer['group_code'],
            '待沟通',
            $customer['owner_user_id'] ?? 1, // 使用客户归属人或默认管理员
            $customer['create_time'] ?? $now,
            $now
        ]);
        
        $projectId = $pdo->lastInsertId();
        
        // 如果客户已有技术分配，同步到项目
        $techStmt = $pdo->prepare("
            SELECT tech_user_id, assigned_by, assigned_at, notes
            FROM customer_tech_assignments
            WHERE customer_id = ?
        ");
        $techStmt->execute([$customer['id']]);
        $techAssignments = $techStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($techAssignments as $tech) {
            $assignStmt = $pdo->prepare("
                INSERT INTO project_tech_assignments (
                    project_id, tech_user_id, assigned_by, assigned_at, notes
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $assignStmt->execute([
                $projectId,
                $tech['tech_user_id'],
                $tech['assigned_by'],
                $tech['assigned_at'],
                '从客户分配迁移'
            ]);
        }
        
        echo "  ✓ 为客户 #{$customer['id']} {$customer['name']} 创建项目 #{$projectId} ({$projectCode})\n";
        $created++;
    }
    
    echo "\n数据迁移完成！\n";
    echo "创建项目数：{$created}\n";
    echo "跳过客户数：{$skipped}\n";
    
} catch (Exception $e) {
    echo "✗ 数据迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
