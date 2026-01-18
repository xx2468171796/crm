<?php
$host = '192.168.110.246';
$port = 3306;
$dbname = 'file1217';
$user = 'file1217';
$pass = 'WNkbKR3FKPXh7MKT';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = file_get_contents(__DIR__ . '/desktop_tables.sql');
    
    // 分割多条语句
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $stmt) {
        if (!empty($stmt) && !preg_match('/^--/', $stmt)) {
            $pdo->exec($stmt);
        }
    }
    
    echo "Tables created successfully!\n";
    
    // 验证表
    $tables = $pdo->query("SHOW TABLES LIKE 'daily_tasks'")->fetchAll();
    echo "daily_tasks: " . (count($tables) ? "OK" : "NOT FOUND") . "\n";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'task_comments'")->fetchAll();
    echo "task_comments: " . (count($tables) ? "OK" : "NOT FOUND") . "\n";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'work_approvals'")->fetchAll();
    echo "work_approvals: " . (count($tables) ? "OK" : "NOT FOUND") . "\n";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'work_approval_versions'")->fetchAll();
    echo "work_approval_versions: " . (count($tables) ? "OK" : "NOT FOUND") . "\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
