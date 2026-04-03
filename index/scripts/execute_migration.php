<?php
/**
 * 执行需求文档数据库迁移
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo = Db::pdo();

    // 读取SQL文件
    $sqlFile = __DIR__ . '/../migrations/create_customer_requirements_table.sql';
    $sql = file_get_contents($sqlFile);

    if ($sql === false) {
        die("错误: 无法读取SQL文件\n");
    }

    // 分割SQL语句（按分号分割）
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );

    echo "开始执行数据库迁移...\n\n";

    $successCount = 0;
    foreach ($statements as $statement) {
        if (empty($statement)) continue;

        try {
            $pdo->exec($statement);
            $successCount++;

            // 提取表名用于显示
            if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $statement, $matches)) {
                echo "✓ 创建表: {$matches[1]}\n";
            } elseif (preg_match('/CREATE INDEX.*?ON `(\w+)`/i', $statement, $matches)) {
                echo "✓ 创建索引: {$matches[1]}\n";
            } else {
                echo "✓ 执行SQL语句\n";
            }
        } catch (PDOException $e) {
            // 如果是表已存在的错误，跳过
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⊙ 表已存在，跳过\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n✅ 迁移完成！成功执行 {$successCount} 条SQL语句\n";

    // 验证表是否创建成功
    echo "\n验证表结构...\n";
    $tables = ['customer_requirements', 'customer_requirements_history'];
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
        if ($result) {
            echo "✓ 表 {$table} 存在\n";

            // 显示表结构
            $columns = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll();
            echo "  字段数: " . count($columns) . "\n";
        } else {
            echo "✗ 表 {$table} 不存在\n";
        }
    }

    echo "\n🎉 数据库迁移成功完成！\n";

} catch (Exception $e) {
    echo "\n❌ 错误: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}
