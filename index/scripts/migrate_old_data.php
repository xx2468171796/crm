<?php
/**
 * 数据迁移脚本
 * 从旧数据库(crm20260111)迁移客户、合同、分期、项目数据到新数据库(crmtest0113)
 * 
 * 映射规则：
 * - sales_user_id -> 保持不变（用户ID一致）
 * - 货币单位 -> TWD（新台币）
 * 
 * 使用方法：php migrate_old_data.php [--dry-run]
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$dryRun = in_array('--dry-run', $argv ?? []);

echo "=== 数据迁移脚本 ===\n";
echo $dryRun ? "[DRY RUN 模式 - 不会实际写入数据]\n" : "[实际执行模式]\n";
echo "\n";

// 旧数据库连接
$oldDb = new PDO(
    'mysql:host=192.168.110.246;port=3306;dbname=crm20260111;charset=utf8mb4',
    'crm20260111',
    'dsAe3E2J3smxsGZB',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 新数据库连接
$newDb = new PDO(
    'mysql:host=192.168.110.246;port=3306;dbname=crmtest0113;charset=utf8mb4',
    'crmtest0113',
    's4ndkh5KDKQ4E5He',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 统计
$stats = [
    'customers_deleted' => 0,
    'customers_migrated' => 0,
    'contracts_deleted' => 0,
    'contracts_migrated' => 0,
    'installments_deleted' => 0,
    'installments_migrated' => 0,
    'projects_deleted' => 0,
    'projects_migrated' => 0,
];

try {
    $newDb->beginTransaction();
    
    // ========== 1. 清理新数据库测试数据 ==========
    echo ">>> 步骤1: 清理新数据库测试数据\n";
    
    // 先删除依赖表
    $deleteTables = [
        'finance_receipt_files',
        'finance_receipts', 
        'finance_installment_change_logs',
        'finance_status_change_logs',
        'finance_installments',
        'finance_contract_files',
        'finance_contracts',
        'project_files',
        'project_stage_times',
        'project_tech_assignments',
        'project_evaluations',
        'project_status_log',
        'projects',
        'customer_files',
        'customer_logs',
        'customer_tech_assignments',
        'customer_links',
        'customers',
    ];
    
    foreach ($deleteTables as $table) {
        if (!$dryRun) {
            $newDb->exec("SET FOREIGN_KEY_CHECKS = 0");
            $count = $newDb->exec("DELETE FROM `$table`");
            $newDb->exec("SET FOREIGN_KEY_CHECKS = 1");
            echo "   删除 $table: $count 条\n";
        } else {
            $count = $newDb->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "   [DRY] 将删除 $table: $count 条\n";
        }
    }
    
    // ========== 2. 迁移客户数据 ==========
    echo "\n>>> 步骤2: 迁移客户数据\n";
    
    $customers = $oldDb->query("SELECT * FROM customers WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
    echo "   找到 " . count($customers) . " 个客户\n";
    
    foreach ($customers as $c) {
        if (!$dryRun) {
            $sql = "INSERT INTO customers (
                id, customer_code, group_code, group_name, custom_id, name, alias, mobile,
                customer_group, gender, age, identity, demand_time_type, activity_tag,
                intent_level, intent_score, intent_summary, owner_user_id, department_id,
                status, create_time, update_time, create_user_id, update_user_id, deleted_at, deleted_by
            ) VALUES (
                :id, :customer_code, :group_code, :group_name, :custom_id, :name, :alias, :mobile,
                :customer_group, :gender, :age, :identity, :demand_time_type, :activity_tag,
                :intent_level, :intent_score, :intent_summary, :owner_user_id, :department_id,
                :status, :create_time, :update_time, :create_user_id, :update_user_id, :deleted_at, :deleted_by
            )";
            $stmt = $newDb->prepare($sql);
            $stmt->execute($c);
        }
        $stats['customers_migrated']++;
    }
    echo "   迁移客户: {$stats['customers_migrated']} 条\n";
    
    // ========== 3. 迁移合同数据 ==========
    echo "\n>>> 步骤3: 迁移合同数据\n";
    
    $contracts = $oldDb->query("SELECT * FROM finance_contracts")->fetchAll(PDO::FETCH_ASSOC);
    echo "   找到 " . count($contracts) . " 个合同\n";
    
    foreach ($contracts as $c) {
        if (!$dryRun) {
            $sql = "INSERT INTO finance_contracts (
                id, customer_id, contract_no, title, sales_user_id, sign_date,
                gross_amount, discount_in_calc, discount_type, discount_value, discount_note,
                net_amount, status, is_first_contract, manual_status,
                create_time, update_time, create_user_id, update_user_id,
                locked_commission_rate, contract_owner_user_id
            ) VALUES (
                :id, :customer_id, :contract_no, :title, :sales_user_id, :sign_date,
                :gross_amount, :discount_in_calc, :discount_type, :discount_value, :discount_note,
                :net_amount, :status, :is_first_contract, :manual_status,
                :create_time, :update_time, :create_user_id, :update_user_id,
                :locked_commission_rate, :contract_owner_user_id
            )";
            $stmt = $newDb->prepare($sql);
            $stmt->execute($c);
        }
        $stats['contracts_migrated']++;
    }
    echo "   迁移合同: {$stats['contracts_migrated']} 条\n";
    
    // ========== 4. 迁移分期数据 ==========
    echo "\n>>> 步骤4: 迁移分期数据\n";
    
    $installments = $oldDb->query("SELECT * FROM finance_installments")->fetchAll(PDO::FETCH_ASSOC);
    echo "   找到 " . count($installments) . " 个分期\n";
    
    foreach ($installments as $i) {
        if (!$dryRun) {
            $columns = array_keys($i);
            $placeholders = array_map(fn($c) => ":$c", $columns);
            $sql = "INSERT INTO finance_installments (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $newDb->prepare($sql);
            $stmt->execute($i);
        }
        $stats['installments_migrated']++;
    }
    echo "   迁移分期: {$stats['installments_migrated']} 条\n";
    
    // ========== 5. 迁移项目数据 ==========
    echo "\n>>> 步骤5: 迁移项目数据\n";
    
    $projects = $oldDb->query("SELECT * FROM projects")->fetchAll(PDO::FETCH_ASSOC);
    echo "   找到 " . count($projects) . " 个项目\n";
    
    foreach ($projects as $p) {
        if (!$dryRun) {
            $columns = array_keys($p);
            $placeholders = array_map(fn($c) => ":$c", $columns);
            $sql = "INSERT INTO projects (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $newDb->prepare($sql);
            $stmt->execute($p);
        }
        $stats['projects_migrated']++;
    }
    echo "   迁移项目: {$stats['projects_migrated']} 条\n";
    
    // ========== 提交事务 ==========
    if (!$dryRun) {
        $newDb->commit();
        echo "\n=== 迁移完成! ===\n";
    } else {
        $newDb->rollBack();
        echo "\n=== DRY RUN 完成 (未实际写入) ===\n";
    }
    
    echo "\n统计:\n";
    foreach ($stats as $key => $val) {
        echo "  $key: $val\n";
    }
    
} catch (Exception $e) {
    $newDb->rollBack();
    echo "\n!!! 错误: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
