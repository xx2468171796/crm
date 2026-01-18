<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensureDictTableExists();
    
    $type = trim($_GET['type'] ?? '');
    
    $sql = "SELECT id, dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time 
            FROM system_dict";
    $params = [];
    
    if ($type !== '') {
        $sql .= " WHERE dict_type = ?";
        $params[] = $type;
    }
    
    $sql .= " ORDER BY dict_type, sort_order, id";
    
    $rows = Db::query($sql, $params);
    
    // 获取所有字典类型
    $types = Db::query("SELECT DISTINCT dict_type FROM system_dict ORDER BY dict_type");
    $typeList = array_column($types, 'dict_type');
    
    echo json_encode([
        'success' => true,
        'data' => $rows,
        'types' => $typeList
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
