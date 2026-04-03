<?php
/**
 * 测试永久删除功能
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/storage/storage_provider.php';

$pdo = Db::pdo();
$storage = storage_provider();

echo "=== 测试永久删除功能 ===\n\n";

// 1. 查看回收站中的文件
echo "1. 回收站中的文件:\n";
$stmt = $pdo->query("SELECT id, deliverable_name, file_path, deleted_at FROM deliverables WHERE deleted_at IS NOT NULL");
$deletedFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($deletedFiles)) {
    echo "   回收站为空\n";
} else {
    foreach ($deletedFiles as $f) {
        echo "   - ID: {$f['id']}, 名称: {$f['deliverable_name']}, 路径: {$f['file_path']}\n";
    }
}

// 2. 如果有文件，尝试测试删除第一个
if (!empty($deletedFiles)) {
    $testFile = $deletedFiles[0];
    echo "\n2. 尝试永久删除 ID {$testFile['id']}:\n";
    
    // 检查 S3 文件是否存在
    if (!empty($testFile['file_path'])) {
        echo "   文件路径: {$testFile['file_path']}\n";
        
        // 尝试删除 S3 文件
        try {
            $result = $storage->deleteObject($testFile['file_path']);
            echo "   S3 删除结果: " . ($result ? '成功' : '失败') . "\n";
        } catch (Exception $e) {
            echo "   S3 删除异常: " . $e->getMessage() . "\n";
        }
        
        // 删除数据库记录
        $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
        $stmt->execute([$testFile['id']]);
        echo "   数据库删除: 成功\n";
    } else {
        echo "   文件路径为空，直接删除数据库记录\n";
        $stmt = $pdo->prepare("DELETE FROM deliverables WHERE id = ?");
        $stmt->execute([$testFile['id']]);
        echo "   数据库删除: 成功\n";
    }
}

// 3. 再次查看回收站
echo "\n3. 删除后回收站中的文件:\n";
$stmt = $pdo->query("SELECT id, deliverable_name, file_path, deleted_at FROM deliverables WHERE deleted_at IS NOT NULL");
$remainingFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($remainingFiles)) {
    echo "   回收站为空\n";
} else {
    foreach ($remainingFiles as $f) {
        echo "   - ID: {$f['id']}, 名称: {$f['deliverable_name']}, 路径: {$f['file_path']}\n";
    }
}

echo "\n=== 测试完成 ===\n";
