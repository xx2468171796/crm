<?php
/**
 * 创建提成调整记录表
 */
require_once __DIR__ . '/../../core/db.php';

echo "Creating commission_adjustments table...\n";

$sql = "
CREATE TABLE IF NOT EXISTS commission_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT '销售人员ID',
    month VARCHAR(7) NOT NULL COMMENT '结算月份 格式:2024-01',
    amount DECIMAL(10,2) NOT NULL COMMENT '调整金额(正/负)',
    reason VARCHAR(255) NOT NULL COMMENT '调整原因',
    created_by INT NOT NULL COMMENT '操作人ID',
    created_at INT NOT NULL COMMENT '创建时间戳',
    INDEX idx_user_month (user_id, month),
    INDEX idx_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提成手动调整记录';
";

try {
    Db::exec($sql);
    echo "Table commission_adjustments created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
