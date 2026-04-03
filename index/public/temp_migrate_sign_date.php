<?php
require_once __DIR__ . '/../core/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    Db::exec('ALTER TABLE finance_contracts MODIFY COLUMN sign_date DATETIME DEFAULT NULL');
    echo "SUCCESS: sign_date字段已改为DATETIME类型\n";
    
    // 执行完后删除自己
    unlink(__FILE__);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
