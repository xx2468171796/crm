<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensureDictTableExists();
    
    $id = (int)($_POST['id'] ?? 0);
    $dictType = trim($_POST['dict_type'] ?? '');
    $dictCode = trim($_POST['dict_code'] ?? '');
    $dictLabel = trim($_POST['dict_label'] ?? '');
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isEnabled = (int)($_POST['is_enabled'] ?? 1);
    
    if ($dictType === '' || $dictCode === '' || $dictLabel === '') {
        throw new Exception('类型、代码和名称不能为空');
    }
    
    $now = time();
    
    if ($id > 0) {
        // 更新
        Db::execute(
            "UPDATE system_dict SET dict_type = ?, dict_code = ?, dict_label = ?, sort_order = ?, is_enabled = ?, update_time = ? WHERE id = ?",
            [$dictType, $dictCode, $dictLabel, $sortOrder, $isEnabled, $now, $id]
        );
    } else {
        // 新增
        Db::execute(
            "INSERT INTO system_dict (dict_type, dict_code, dict_label, sort_order, is_enabled, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$dictType, $dictCode, $dictLabel, $sortOrder, $isEnabled, $now, $now]
        );
        $id = Db::lastInsertId();
    }
    
    echo json_encode([
        'success' => true,
        'message' => '保存成功',
        'id' => $id
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
