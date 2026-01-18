<?php
/**
 * 数据库迁移: deliverables 表添加 form_instance_id 字段
 */

require_once __DIR__ . '/../core/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h2>表单附件功能 - 数据库迁移</h2>";

$pdo = Db::pdo();

try {
    // 检查字段是否已存在
    $checkStmt = $pdo->query("SHOW COLUMNS FROM deliverables LIKE 'form_instance_id'");
    if ($checkStmt->fetch()) {
        echo "<p style='color:green'>✅ form_instance_id 字段已存在</p>";
    } else {
        // 添加字段
        $pdo->exec("ALTER TABLE deliverables ADD COLUMN form_instance_id INT NULL AFTER project_id");
        echo "<p style='color:green'>✅ 已添加 form_instance_id 字段</p>";
    }
    
    // 检查索引是否已存在
    $indexStmt = $pdo->query("SHOW INDEX FROM deliverables WHERE Key_name = 'idx_form_instance'");
    if ($indexStmt->fetch()) {
        echo "<p style='color:green'>✅ idx_form_instance 索引已存在</p>";
    } else {
        // 添加索引
        $pdo->exec("ALTER TABLE deliverables ADD INDEX idx_form_instance (form_instance_id)");
        echo "<p style='color:green'>✅ 已添加 idx_form_instance 索引</p>";
    }
    
    echo "<h3>迁移完成</h3>";
    echo "<p>deliverables 表现在支持关联表单实例</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ 错误: " . htmlspecialchars($e->getMessage()) . "</p>";
}
