<?php
/**
 * 执行技术提成系统数据库迁移
 */

require_once __DIR__ . '/../core/db.php';

echo "开始执行技术提成系统迁移...\n";

try {
    // 1. 检查字段是否已存在
    $stmt = $pdo->query("SHOW COLUMNS FROM project_tech_assignments LIKE 'commission_amount'");
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "✓ commission_amount 字段已存在，跳过\n";
    } else {
        // 添加提成字段
        $pdo->exec("ALTER TABLE project_tech_assignments 
            ADD COLUMN commission_amount DECIMAL(10,2) DEFAULT NULL COMMENT '提成金额',
            ADD COLUMN commission_set_by INT DEFAULT NULL COMMENT '设置人ID',
            ADD COLUMN commission_set_at INT DEFAULT NULL COMMENT '设置时间戳',
            ADD COLUMN commission_note VARCHAR(255) DEFAULT NULL COMMENT '提成备注'
        ");
        echo "✓ 已添加提成字段\n";
    }
    
    // 2. 添加索引
    $stmt = $pdo->query("SHOW INDEX FROM project_tech_assignments WHERE Key_name = 'idx_commission_set_by'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE project_tech_assignments ADD INDEX idx_commission_set_by (commission_set_by)");
        echo "✓ 已添加索引 idx_commission_set_by\n";
    }
    
    // 3. 添加权限
    $permissions = [
        ['tech_commission_view', '查看技术提成', 'tech', '技术人员查看自己的项目提成', 34],
        ['tech_commission_set', '设置技术提成', 'tech', '技术主管设置项目提成金额', 35],
        ['tech_commission_report', '技术财务报表', 'tech', '管理层查看技术财务汇总报表', 36],
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO permissions (code, name, module, description, sort, create_time) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($permissions as $p) {
        $stmt->execute([$p[0], $p[1], $p[2], $p[3], $p[4], time()]);
    }
    echo "✓ 已添加权限数据\n";
    
    echo "\n✅ 技术提成系统迁移完成！\n";
    
} catch (Exception $e) {
    echo "❌ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
