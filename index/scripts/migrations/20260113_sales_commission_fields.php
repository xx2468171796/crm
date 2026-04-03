<?php
/**
 * 销售提成字段迁移脚本
 * 为已有合同补录 contract_owner_user_id 和 locked_commission_rate
 */

require_once __DIR__ . '/../../core/db.php';

echo "开始迁移销售提成相关字段...\n";

// 1. 补录 contract_owner_user_id（从客户归属人继承）
echo "1. 补录合同归属人...\n";
$sql1 = "
    UPDATE finance_contracts fc
    INNER JOIN customers c ON c.id = fc.customer_id
    SET fc.contract_owner_user_id = c.owner_user_id
    WHERE fc.contract_owner_user_id IS NULL
";
$affected1 = Db::execute($sql1);
echo "   更新了 {$affected1} 条合同的归属人\n";

// 2. 补录 locked_commission_rate（使用默认档位或按签约月份推算）
echo "2. 补录历史档位...\n";

// 获取当前启用的规则
$rule = Db::queryOne("SELECT * FROM commission_rule_sets WHERE is_active = 1 ORDER BY id DESC LIMIT 1");

if ($rule) {
    $ruleType = $rule['rule_type'] ?? 'fixed';
    $fixedRate = (float)($rule['fixed_rate'] ?? 0.05);
    
    if ($ruleType === 'fixed') {
        // 固定比例规则，直接使用固定比例
        $sql2 = "
            UPDATE finance_contracts
            SET locked_commission_rate = :rate
            WHERE locked_commission_rate IS NULL
              AND is_first_contract = 1
        ";
        $affected2 = Db::execute($sql2, ['rate' => $fixedRate]);
        echo "   使用固定比例 {$fixedRate} 更新了 {$affected2} 条首单合同\n";
    } else {
        // 阶梯规则，需要按合同金额计算
        $tiers = Db::query("SELECT * FROM commission_rule_tiers WHERE rule_set_id = :id ORDER BY tier_from ASC", ['id' => $rule['id']]);
        
        // 获取所有未设置档位的首单合同
        $contracts = Db::query("
            SELECT id, net_amount 
            FROM finance_contracts 
            WHERE locked_commission_rate IS NULL 
              AND is_first_contract = 1
        ");
        
        $updated = 0;
        foreach ($contracts as $contract) {
            $amount = (float)$contract['net_amount'];
            $rate = 0;
            
            // 根据合同金额找到对应的档位比例
            foreach ($tiers as $tier) {
                $from = (float)($tier['tier_from'] ?? 0);
                $to = $tier['tier_to'];
                
                if ($amount >= $from && ($to === null || $amount < (float)$to)) {
                    $rate = (float)($tier['rate'] ?? 0);
                    break;
                }
            }
            
            if ($rate > 0) {
                Db::execute("UPDATE finance_contracts SET locked_commission_rate = :rate WHERE id = :id", [
                    'rate' => $rate,
                    'id' => $contract['id']
                ]);
                $updated++;
            }
        }
        echo "   按阶梯规则更新了 {$updated} 条首单合同\n";
    }
} else {
    // 无规则，使用默认5%
    $defaultRate = 0.05;
    $sql2 = "
        UPDATE finance_contracts
        SET locked_commission_rate = :rate
        WHERE locked_commission_rate IS NULL
          AND is_first_contract = 1
    ";
    $affected2 = Db::execute($sql2, ['rate' => $defaultRate]);
    echo "   使用默认比例 {$defaultRate} 更新了 {$affected2} 条首单合同\n";
}

echo "迁移完成！\n";
