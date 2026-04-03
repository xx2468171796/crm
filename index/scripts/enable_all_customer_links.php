<?php
/**
 * 批量为所有客户开通分享链接
 * 运行方式：php scripts/enable_all_customer_links.php
 */

require_once __DIR__ . '/../core/db.php';

echo "=== 批量开通客户分享链接 ===\n\n";

try {
    $pdo = Db::pdo();
    
    // 1. 统计当前状态
    $totalCustomers = Db::queryOne('SELECT COUNT(*) as cnt FROM customers')['cnt'];
    $existingLinks = Db::queryOne('SELECT COUNT(*) as cnt FROM customer_links')['cnt'];
    
    echo "当前状态:\n";
    echo "  - 总客户数: {$totalCustomers}\n";
    echo "  - 已有链接数: {$existingLinks}\n";
    
    // 2. 查找没有分享链接的客户
    $missingCustomers = Db::query('
        SELECT c.id, c.customer_code, c.name 
        FROM customers c 
        LEFT JOIN customer_links cl ON c.id = cl.customer_id 
        WHERE cl.id IS NULL
    ');
    
    $missingCount = count($missingCustomers);
    echo "  - 缺少链接的客户数: {$missingCount}\n\n";
    
    if ($missingCount === 0) {
        echo "✅ 所有客户都已有分享链接，无需操作。\n";
        exit(0);
    }
    
    // 3. 批量创建分享链接
    echo "开始创建分享链接...\n";
    
    $now = time();
    $created = 0;
    $errors = 0;
    
    foreach ($missingCustomers as $customer) {
        try {
            $token = bin2hex(random_bytes(32)); // 64位随机token
            
            Db::execute('INSERT INTO customer_links 
                (customer_id, token, enabled, created_at, updated_at) 
                VALUES 
                (:cid, :token, 1, :created_at, :updated_at)', [
                'cid' => $customer['id'],
                'token' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            
            $created++;
            
            if ($created % 100 === 0) {
                echo "  已处理 {$created} / {$missingCount} ...\n";
            }
        } catch (Exception $e) {
            $errors++;
            echo "  ❌ 客户 {$customer['id']} ({$customer['name']}) 失败: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== 完成 ===\n";
    echo "  - 成功创建: {$created}\n";
    echo "  - 失败: {$errors}\n";
    
    // 4. 验证结果
    $newTotal = Db::queryOne('SELECT COUNT(*) as cnt FROM customer_links')['cnt'];
    echo "  - 当前链接总数: {$newTotal}\n";
    
    echo "\n✅ 所有客户分享链接已开通！\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    exit(1);
}
