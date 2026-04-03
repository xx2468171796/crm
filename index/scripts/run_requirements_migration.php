<?php
/**
 * 执行客户需求文档表迁移
 *
 * 使用方法：
 * php scripts/run_requirements_migration.php
 */

require_once __DIR__ . '/../core/db.php';

echo "开始执行客户需求文档表迁移...\n";

try {
    // 读取SQL文件
    $sqlFile = __DIR__ . '/../migrations/create_customer_requirements_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL文件不存在: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);

    // 分割SQL语句（按分号分割）
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );

    $db = Db::getInstance();

    foreach ($statements as $statement) {
        if (empty($statement)) continue;

        echo "执行SQL: " . substr($statement, 0, 100) . "...\n";
        $db->exec($statement);
    }

    echo "✓ 迁移成功完成！\n";
    echo "\n创建的表：\n";
    echo "  - customer_requirements (客户需求文档表)\n";
    echo "  - customer_requirements_history (需求文档历史版本表)\n";

} catch (Exception $e) {
    echo "✗ 迁移失败: " . $e->getMessage() . "\n";
    exit(1);
}
