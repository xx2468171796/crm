<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensureDictTableExists();
    
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('参数错误');
    }
    
    Db::execute("DELETE FROM system_dict WHERE id = ?", [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => '删除成功'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
