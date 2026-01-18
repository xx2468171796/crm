<?php
require_once __DIR__ . '/../core/api_init.php';
// 模拟 desktop_tasks.php 逻辑调试
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);
header('Content-Type: application/json');
require_once __DIR__ . '/../core/db.php';

try {
    $sql = "
        SELECT 
            p.id as project_id,
            p.project_name,
            p.current_status,
            p.deadline,
            c.id as customer_id,
            c.name as customer_name
        FROM projects p
        LEFT JOIN customers c ON p.customer_id = c.id
        WHERE p.status NOT IN ('completed', 'cancelled')
        ORDER BY p.id DESC
        LIMIT 10
    ";
    $projects = Db::query($sql);
    echo json_encode(['success' => true, 'count' => count($projects), 'data' => $projects], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], JSON_UNESCAPED_UNICODE);
}
