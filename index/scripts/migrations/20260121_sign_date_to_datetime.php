<?php
/**
 * 将 sign_date 字段从 DATE 改为 DATETIME，支持精确到分钟
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始迁移：将 sign_date 从 DATE 改为 DATETIME...\n";

try {
    // 修改字段类型
    Db::exec('ALTER TABLE finance_contracts MODIFY COLUMN sign_date DATETIME DEFAULT NULL');
    echo "✓ sign_date 字段已改为 DATETIME 类型\n";
    
    echo "迁移完成！\n";
} catch (Exception $e) {
    echo "迁移失败：" . $e->getMessage() . "\n";
    exit(1);
}
