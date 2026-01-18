<?php
/**
 * 更新项目状态名称
 * 确认需求 → 需求确认
 * 设计校对 → 设计核对
 */

require_once __DIR__ . '/../../core/Db.php';

try {
    $pdo = Db::pdo();
    
    // 开始事务
    $pdo->beginTransaction();
    
    // 更新 projects 表
    $stmt1 = $pdo->prepare("UPDATE projects SET current_status = '需求确认' WHERE current_status = '确认需求'");
    $stmt1->execute();
    $count1 = $stmt1->rowCount();
    
    $stmt2 = $pdo->prepare("UPDATE projects SET current_status = '设计核对' WHERE current_status = '设计校对'");
    $stmt2->execute();
    $count2 = $stmt2->rowCount();
    
    // 检查 project_status_logs 表是否存在
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'project_status_logs'");
    if ($tableCheck->rowCount() > 0) {
        $stmt3 = $pdo->prepare("UPDATE project_status_logs SET old_status = '需求确认' WHERE old_status = '确认需求'");
        $stmt3->execute();
        
        $stmt4 = $pdo->prepare("UPDATE project_status_logs SET new_status = '需求确认' WHERE new_status = '确认需求'");
        $stmt4->execute();
        
        $stmt5 = $pdo->prepare("UPDATE project_status_logs SET old_status = '设计核对' WHERE old_status = '设计校对'");
        $stmt5->execute();
        
        $stmt6 = $pdo->prepare("UPDATE project_status_logs SET new_status = '设计核对' WHERE new_status = '设计校对'");
        $stmt6->execute();
    }
    
    // 提交事务
    $pdo->commit();
    
    echo "✅ 状态更新成功！\n";
    echo "- projects 表：确认需求→需求确认 更新了 {$count1} 条\n";
    echo "- projects 表：设计校对→设计核对 更新了 {$count2} 条\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ 更新失败：" . $e->getMessage() . "\n";
    exit(1);
}
