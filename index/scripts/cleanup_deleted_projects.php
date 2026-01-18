<?php
/**
 * 清理已删除项目脚本
 * 15天后自动永久删除已软删除的项目及其相关数据
 * 
 * 使用方式：php cleanup_deleted_projects.php
 * 建议配置cron定时任务：0 2 * * * php /path/to/cleanup_deleted_projects.php >> /var/log/cleanup_projects.log 2>&1
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

// 配置：软删除后多少天永久删除
$RETENTION_DAYS = 15;

$cutoffTime = time() - ($RETENTION_DAYS * 24 * 60 * 60);

echo "[" . date('Y-m-d H:i:s') . "] 开始清理已删除项目...\n";
echo "截止时间: " . date('Y-m-d H:i:s', $cutoffTime) . " (删除{$RETENTION_DAYS}天前的数据)\n";

try {
    $pdo = Db::pdo();
    
    // 1. 查询待永久删除的项目
    $stmt = $pdo->prepare("
        SELECT id, project_name, project_code, deleted_at 
        FROM projects 
        WHERE deleted_at IS NOT NULL AND deleted_at < ?
    ");
    $stmt->execute([$cutoffTime]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($projects)) {
        echo "没有需要清理的项目\n";
        exit(0);
    }
    
    echo "找到 " . count($projects) . " 个待清理项目\n";
    
    // 初始化S3服务
    $storageConfig = storage_config();
    $s3Config = $storageConfig['s3'] ?? [];
    $s3Client = null;
    
    if (!empty($s3Config['endpoint']) && !empty($s3Config['bucket'])) {
        require_once __DIR__ . '/../core/storage/s3_client.php';
        $s3Client = new S3Client($s3Config);
    }
    
    $totalDeleted = 0;
    $totalFiles = 0;
    $errors = [];
    
    foreach ($projects as $project) {
        $projectId = $project['id'];
        echo "\n处理项目: {$project['project_code']} - {$project['project_name']}\n";
        
        $pdo->beginTransaction();
        try {
            // 2. 获取项目相关的文件路径（用于S3清理）
            $fileStmt = $pdo->prepare("SELECT id, file_path FROM deliverables WHERE project_id = ?");
            $fileStmt->execute([$projectId]);
            $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 3. 删除S3中的文件
            if ($s3Client && !empty($files)) {
                foreach ($files as $file) {
                    if (!empty($file['file_path'])) {
                        try {
                            $s3Client->deleteObject($file['file_path']);
                            $totalFiles++;
                            echo "  - 删除S3文件: {$file['file_path']}\n";
                        } catch (Exception $e) {
                            echo "  - 警告: 删除S3文件失败: {$file['file_path']} - {$e->getMessage()}\n";
                        }
                    }
                }
            }
            
            // 4. 永久删除相关数据（按依赖顺序）
            
            // 删除项目技术分配
            $pdo->prepare("DELETE FROM project_tech_assignments WHERE project_id = ?")->execute([$projectId]);
            
            // 删除项目阶段时间
            $pdo->prepare("DELETE FROM project_stage_times WHERE project_id = ?")->execute([$projectId]);
            
            // 删除表单实例
            $pdo->prepare("DELETE FROM form_instances WHERE project_id = ?")->execute([$projectId]);
            
            // 删除交付物
            $pdo->prepare("DELETE FROM deliverables WHERE project_id = ?")->execute([$projectId]);
            
            // 删除时间线事件
            $pdo->prepare("DELETE FROM timeline_events WHERE entity_type = 'project' AND entity_id = ?")->execute([$projectId]);
            
            // 最后删除项目本身
            $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$projectId]);
            
            $pdo->commit();
            $totalDeleted++;
            echo "  ✓ 项目已永久删除\n";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "项目 {$project['project_code']}: {$e->getMessage()}";
            echo "  ✗ 删除失败: {$e->getMessage()}\n";
        }
    }
    
    echo "\n========== 清理完成 ==========\n";
    echo "成功删除项目: {$totalDeleted}\n";
    echo "删除S3文件: {$totalFiles}\n";
    if (!empty($errors)) {
        echo "错误数量: " . count($errors) . "\n";
        foreach ($errors as $err) {
            echo "  - {$err}\n";
        }
    }
    
} catch (Exception $e) {
    echo "致命错误: " . $e->getMessage() . "\n";
    exit(1);
}
