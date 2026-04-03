<?php
/**
 * 添加回收站相关字段和索引
 */
require_once __DIR__ . '/../core/db.php';

$pdo = Db::pdo();

try {
    // T1: 添加 deleted_by 字段
    $pdo->exec('ALTER TABLE deliverables ADD COLUMN deleted_by INT DEFAULT NULL');
    echo "deleted_by 字段已添加\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "deleted_by 字段已存在\n";
    } else {
        echo "添加 deleted_by 失败: " . $e->getMessage() . "\n";
    }
}

try {
    // T2: 添加索引
    $pdo->exec('CREATE INDEX idx_deliverables_deleted ON deliverables (deleted_at, project_id)');
    echo "索引 idx_deliverables_deleted 已添加\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "索引 idx_deliverables_deleted 已存在\n";
    } else {
        echo "添加索引失败: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec('CREATE INDEX idx_deliverables_deleted_by ON deliverables (deleted_by)');
    echo "索引 idx_deliverables_deleted_by 已添加\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "索引 idx_deliverables_deleted_by 已存在\n";
    } else {
        echo "添加索引失败: " . $e->getMessage() . "\n";
    }
}

echo "完成!\n";
