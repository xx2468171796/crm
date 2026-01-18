<?php
/**
 * 系统字典公共函数
 */

require_once __DIR__ . '/db.php';

/**
 * 获取字典选项列表
 * @param string $dictType 字典类型
 * @param bool $enabledOnly 是否只返回启用的
 * @return array [['value' => 'xxx', 'label' => 'xxx'], ...]
 */
function getDictOptions(string $dictType, bool $enabledOnly = true): array {
    static $cache = [];
    $cacheKey = $dictType . ($enabledOnly ? '_enabled' : '_all');
    
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    // 确保表存在
    ensureDictTableExists();
    
    $sql = 'SELECT dict_code AS value, dict_label AS label FROM system_dict WHERE dict_type = ?';
    $params = [$dictType];
    
    if ($enabledOnly) {
        $sql .= ' AND is_enabled = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    
    $rows = Db::query($sql, $params);
    $cache[$cacheKey] = $rows;
    
    return $rows;
}

/**
 * 获取支付方式选项
 * @return array
 */
function getPaymentMethodOptions(): array {
    return getDictOptions('payment_method', true);
}

/**
 * 获取支付方式的中文标签
 * @param string $value 英文代码或中文标签
 * @return string 中文标签
 */
function getPaymentMethodLabel(string $value): string {
    if ($value === '') return '';
    $options = getPaymentMethodOptions();
    // 首先尝试按 value (dict_code) 匹配
    foreach ($options as $opt) {
        if ($opt['value'] === $value) {
            return $opt['label'];
        }
    }
    // 如果按 value 找不到，尝试按 label 匹配（兼容历史数据存储中文的情况）
    foreach ($options as $opt) {
        if ($opt['label'] === $value) {
            return $opt['label'];
        }
    }
    return $value; // 如果都找不到则返回原值
}

/**
 * 渲染支付方式下拉选项HTML
 * @param string $selected 当前选中的值
 * @param bool $includeEmpty 是否包含空选项
 * @param string $emptyLabel 空选项的显示文本
 * @return string
 */
function renderPaymentMethodOptions(string $selected = '', bool $includeEmpty = true, string $emptyLabel = '未填写'): string {
    $options = getPaymentMethodOptions();
    $html = '';
    
    if ($includeEmpty) {
        $sel = ($selected === '') ? ' selected' : '';
        $html .= '<option value=""' . $sel . '>' . htmlspecialchars($emptyLabel) . '</option>';
    }
    
    foreach ($options as $opt) {
        $sel = ($selected === $opt['value']) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($opt['value']) . '"' . $sel . '>' . htmlspecialchars($opt['label']) . '</option>';
    }
    
    return $html;
}

/**
 * 获取催款方式的中文标签
 */
function getCollectionMethodLabel(string $value): string {
    $labels = [
        'phone' => '电话',
        'wechat' => '微信',
        'visit' => '上门',
        'sms' => '短信',
        'letter' => '信函',
        'cash' => '现金',
        'bank_transfer' => '银行转账',
        'alipay' => '支付宝',
        'pos' => '刷卡',
        'taiwan' => '台湾转账',
        'paypal' => 'PayPal',
        'prepay' => '预收抵扣',
        'hkankotti' => '香港安科蒂',
        'apply' => '申请',
        'other' => '其他',
    ];
    return $labels[$value] ?? $value;
}

/**
 * 获取催款结果的中文标签
 */
function getCollectionResultLabel(string $value): string {
    $labels = [
        'promised' => '承诺还款',
        'received' => '已收款',
        'partial' => '部分还款',
        'refused' => '拒绝还款',
        'unreachable' => '无法联系',
        'dispute' => '有争议',
        'other' => '其他',
    ];
    return $labels[$value] ?? $value;
}

/**
 * 确保字典表存在并有默认数据
 */
function ensureDictTableExists(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    $pdo = Db::pdo();
    
    // 确保表存在
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_dict (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dict_type VARCHAR(50) NOT NULL COMMENT '字典类型',
            dict_code VARCHAR(50) NOT NULL COMMENT '字典代码',
            dict_label VARCHAR(100) NOT NULL COMMENT '显示名称',
            sort_order INT NOT NULL DEFAULT 0 COMMENT '排序',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
            create_time INT UNSIGNED DEFAULT NULL COMMENT '创建时间',
            update_time INT UNSIGNED DEFAULT NULL COMMENT '更新时间',
            UNIQUE KEY uk_type_code (dict_type, dict_code),
            KEY idx_type_enabled (dict_type, is_enabled, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统字典表'
    ");
    
    // 检查是否有支付方式数据，没有则插入默认数据
    $count = Db::queryOne("SELECT COUNT(*) as cnt FROM system_dict WHERE dict_type = 'payment_method'");
    if (($count['cnt'] ?? 0) == 0) {
        $now = time();
        $methods = [
            ['payment_method', 'cash', '现金', 1],
            ['payment_method', 'transfer', '转账', 2],
            ['payment_method', 'wechat', '微信', 3],
            ['payment_method', 'alipay', '支付宝', 4],
            ['payment_method', 'pos', 'POS', 5],
            ['payment_method', 'other', '其他', 99],
        ];
        
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        ");
        
        foreach ($methods as $m) {
            $stmt->execute([$m[0], $m[1], $m[2], $m[3], $now, $now]);
        }
    }
}

