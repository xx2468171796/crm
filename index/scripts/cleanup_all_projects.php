<?php
/**
 * 清理所有项目数据脚本
 * 保留客户数据，删除所有项目及关联数据
 */

require_once __DIR__ . '/../core/bootstrap.php';

use Core\Db;

// 安全检查
if (php_sapi_name() !== 'cli') {
    die('此脚本只能通过命令行运行');
}

echo "=== 清理所有项目数据 ===\n\n";

try {
    // 开始事务
    Db::beginTransaction();
    
    // 1. 删除项目文件记录
    $count = Db::execute("DELETE FROM project_files WHERE 1=1");
    echo "已删除 project_files 记录: {$count} 条\n";
    
    // 2. 删除项目技术分配
    $count = Db::execute("DELETE FROM project_tech_assignments WHERE 1=1");
    echo "已删除 project_tech_assignments 记录: {$count} 条\n";
    
    // 3. 删除项目任务
    $count = Db::execute("DELETE FROM project_tasks WHERE 1=1");
    echo "已删除 project_tasks 记录: {$count} 条\n";
    
    // 4. 删除项目阶段时间线
    $count = Db::execute("DELETE FROM project_stage_timeline WHERE 1=1");
    echo "已删除 project_stage_timeline 记录: {$count} 条\n";
    
    // 5. 删除项目评价
    $count = Db::execute("DELETE FROM project_evaluations WHERE 1=1");
    echo "已删除 project_evaluations 记录: {$count} 条\n";
    
    // 6. 删除项目评价表单
    $count = Db::execute("DELETE FROM project_evaluation_forms WHERE 1=1");
    echo "已删除 project_evaluation_forms 记录: {$count} 条\n";
    
    // 7. 删除交付物
    $count = Db::execute("DELETE FROM deliverables WHERE 1=1");
    echo "已删除 deliverables 记录: {$count} 条\n";
    
    // 8. 删除沟通记录
    $count = Db::execute("DELETE FROM communications WHERE 1=1");
    echo "已删除 communications 记录: {$count} 条\n";
    
    // 9. 删除项目记录
    $count = Db::execute("DELETE FROM projects WHERE 1=1");
    echo "已删除 projects 记录: {$count} 条\n";
    
    // 10. 重置项目编号序列（如果有的话）
    // 可选：重置AUTO_INCREMENT
    Db::execute("ALTER TABLE projects AUTO_INCREMENT = 1");
    echo "已重置 projects 表 AUTO_INCREMENT\n";
    
    // 提交事务
    Db::commit();
    
    echo "\n=== 清理完成 ===\n";
    echo "注意：S3存储的文件需要单独清理\n";
    
} catch (Exception $e) {
    Db::rollback();
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
