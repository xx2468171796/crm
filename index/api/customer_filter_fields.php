<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 客户筛选字段管理 API
 * 
 * GET    - 获取字段列表
 * POST   - 创建字段 / 更新字段 / 删除字段 / 管理选项
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

// 获取当前用户（允许未登录用户查询，但POST操作需要登录）
$user = current_user();

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        case 'POST':
            // POST 操作需要登录
            if (!$user) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            handlePost();
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * 处理 GET 请求
 */
function handleGet() {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            // 获取所有字段及其选项
            $includeInactive = ($_GET['include_inactive'] ?? '0') === '1';
            $fields = getFieldsWithOptions($includeInactive);
            echo json_encode(['success' => true, 'data' => $fields], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'field':
            // 获取单个字段详情
            $fieldId = (int)($_GET['id'] ?? 0);
            if (!$fieldId) {
                echo json_encode(['success' => false, 'message' => '缺少字段ID']);
                return;
            }
            $field = getFieldById($fieldId);
            echo json_encode(['success' => true, 'data' => $field], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'customer_values':
            // 获取客户的筛选字段值
            $customerId = (int)($_GET['customer_id'] ?? 0);
            if (!$customerId) {
                echo json_encode(['success' => false, 'message' => '缺少客户ID']);
                return;
            }
            $values = getCustomerFilterValues($customerId);
            echo json_encode(['success' => true, 'data' => $values], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
}

/**
 * 处理 POST 请求
 */
function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create_field':
            $result = createField($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'update_field':
            $result = updateField($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete_field':
            $result = deleteField($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'toggle_field':
            $result = toggleField($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'reorder_fields':
            $result = reorderFields($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create_option':
            $result = createOption($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'update_option':
            $result = updateOption($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete_option':
            $result = deleteOption($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'reorder_options':
            $result = reorderOptions($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'set_customer_value':
            $result = setCustomerValue($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'clear_customer_value':
            $result = clearCustomerValue($input);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '未知操作: ' . $action]);
    }
}

// ==================== 字段管理 ====================

function getFieldsWithOptions(bool $includeInactive = false): array {
    $pdo = Db::pdo();
    
    $sql = "SELECT * FROM customer_filter_fields";
    if (!$includeInactive) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    
    $fields = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取每个字段的选项
    foreach ($fields as &$field) {
        $optionSql = "SELECT * FROM customer_filter_options WHERE field_id = ?";
        if (!$includeInactive) {
            $optionSql .= " AND is_active = 1";
        }
        $optionSql .= " ORDER BY sort_order ASC, id ASC";
        
        $stmt = $pdo->prepare($optionSql);
        $stmt->execute([$field['id']]);
        $field['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $fields;
}

function getFieldById(int $fieldId): ?array {
    $pdo = Db::pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM customer_filter_fields WHERE id = ?");
    $stmt->execute([$fieldId]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$field) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM customer_filter_options WHERE field_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$fieldId]);
    $field['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $field;
}

function createField(array $input): array {
    $fieldName = trim($input['field_name'] ?? '');
    $fieldLabel = trim($input['field_label'] ?? '');
    
    if (!$fieldName || !$fieldLabel) {
        return ['success' => false, 'message' => '字段名和标签不能为空'];
    }
    
    // 检查字段名是否已存在
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("SELECT id FROM customer_filter_fields WHERE field_name = ?");
    $stmt->execute([$fieldName]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => '字段名已存在'];
    }
    
    // 获取最大排序值
    $maxOrder = $pdo->query("SELECT MAX(sort_order) FROM customer_filter_fields")->fetchColumn() ?? 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO customer_filter_fields (field_name, field_label, sort_order) 
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$fieldName, $fieldLabel, $maxOrder + 1]);
    
    $newId = $pdo->lastInsertId();
    
    return ['success' => true, 'message' => '字段创建成功', 'data' => ['id' => $newId]];
}

function updateField(array $input): array {
    $fieldId = (int)($input['id'] ?? 0);
    $fieldLabel = trim($input['field_label'] ?? '');
    
    if (!$fieldId || !$fieldLabel) {
        return ['success' => false, 'message' => '参数不完整'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("UPDATE customer_filter_fields SET field_label = ? WHERE id = ?");
    $stmt->execute([$fieldLabel, $fieldId]);
    
    return ['success' => true, 'message' => '字段更新成功'];
}

function deleteField(array $input): array {
    $fieldId = (int)($input['id'] ?? 0);
    
    if (!$fieldId) {
        return ['success' => false, 'message' => '缺少字段ID'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("DELETE FROM customer_filter_fields WHERE id = ?");
    $stmt->execute([$fieldId]);
    
    return ['success' => true, 'message' => '字段删除成功'];
}

function toggleField(array $input): array {
    $fieldId = (int)($input['id'] ?? 0);
    $isActive = (int)($input['is_active'] ?? 1);
    
    if (!$fieldId) {
        return ['success' => false, 'message' => '缺少字段ID'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("UPDATE customer_filter_fields SET is_active = ? WHERE id = ?");
    $stmt->execute([$isActive, $fieldId]);
    
    return ['success' => true, 'message' => $isActive ? '字段已启用' : '字段已禁用'];
}

function reorderFields(array $input): array {
    $orders = $input['orders'] ?? [];
    
    if (empty($orders)) {
        return ['success' => false, 'message' => '缺少排序数据'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("UPDATE customer_filter_fields SET sort_order = ? WHERE id = ?");
    
    foreach ($orders as $order) {
        $stmt->execute([$order['sort_order'], $order['id']]);
    }
    
    return ['success' => true, 'message' => '排序更新成功'];
}

// ==================== 选项管理 ====================

function createOption(array $input): array {
    $fieldId = (int)($input['field_id'] ?? 0);
    $optionValue = trim($input['option_value'] ?? '');
    $optionLabel = trim($input['option_label'] ?? '');
    $color = trim($input['color'] ?? '#6366f1');
    
    if (!$fieldId || !$optionValue || !$optionLabel) {
        return ['success' => false, 'message' => '参数不完整'];
    }
    
    $pdo = Db::pdo();
    
    // 检查选项值是否已存在
    $stmt = $pdo->prepare("SELECT id FROM customer_filter_options WHERE field_id = ? AND option_value = ?");
    $stmt->execute([$fieldId, $optionValue]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => '选项值已存在'];
    }
    
    // 获取最大排序值
    $stmt = $pdo->prepare("SELECT MAX(sort_order) FROM customer_filter_options WHERE field_id = ?");
    $stmt->execute([$fieldId]);
    $maxOrder = $stmt->fetchColumn() ?? 0;
    
    $stmt = $pdo->prepare("
        INSERT INTO customer_filter_options (field_id, option_value, option_label, color, sort_order) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$fieldId, $optionValue, $optionLabel, $color, $maxOrder + 1]);
    
    $newId = $pdo->lastInsertId();
    
    return ['success' => true, 'message' => '选项创建成功', 'data' => ['id' => $newId]];
}

function updateOption(array $input): array {
    $optionId = (int)($input['id'] ?? 0);
    $optionLabel = trim($input['option_label'] ?? '');
    $color = trim($input['color'] ?? '');
    
    if (!$optionId) {
        return ['success' => false, 'message' => '缺少选项ID'];
    }
    
    $pdo = Db::pdo();
    $updates = [];
    $params = [];
    
    if ($optionLabel) {
        $updates[] = "option_label = ?";
        $params[] = $optionLabel;
    }
    if ($color) {
        $updates[] = "color = ?";
        $params[] = $color;
    }
    
    if (empty($updates)) {
        return ['success' => false, 'message' => '没有要更新的内容'];
    }
    
    $params[] = $optionId;
    $stmt = $pdo->prepare("UPDATE customer_filter_options SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmt->execute($params);
    
    return ['success' => true, 'message' => '选项更新成功'];
}

function deleteOption(array $input): array {
    $optionId = (int)($input['id'] ?? 0);
    
    if (!$optionId) {
        return ['success' => false, 'message' => '缺少选项ID'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("DELETE FROM customer_filter_options WHERE id = ?");
    $stmt->execute([$optionId]);
    
    return ['success' => true, 'message' => '选项删除成功'];
}

function reorderOptions(array $input): array {
    $orders = $input['orders'] ?? [];
    
    if (empty($orders)) {
        return ['success' => false, 'message' => '缺少排序数据'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("UPDATE customer_filter_options SET sort_order = ? WHERE id = ?");
    
    foreach ($orders as $order) {
        $stmt->execute([$order['sort_order'], $order['id']]);
    }
    
    return ['success' => true, 'message' => '排序更新成功'];
}

// ==================== 客户字段值管理 ====================

function getCustomerFilterValues(int $customerId): array {
    $pdo = Db::pdo();
    
    $sql = "
        SELECT 
            v.id,
            v.field_id,
            v.option_id,
            f.field_name,
            f.field_label,
            o.option_value,
            o.option_label,
            o.color
        FROM customer_filter_values v
        JOIN customer_filter_fields f ON v.field_id = f.id
        JOIN customer_filter_options o ON v.option_id = o.id
        WHERE v.customer_id = ? AND f.is_active = 1
        ORDER BY f.sort_order ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$customerId]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function setCustomerValue(array $input): array {
    $customerId = (int)($input['customer_id'] ?? 0);
    $fieldId = (int)($input['field_id'] ?? 0);
    $optionId = (int)($input['option_id'] ?? 0);
    
    if (!$customerId || !$fieldId || !$optionId) {
        return ['success' => false, 'message' => '参数不完整'];
    }
    
    $pdo = Db::pdo();
    
    // 使用 REPLACE INTO 实现插入或更新
    $stmt = $pdo->prepare("
        REPLACE INTO customer_filter_values (customer_id, field_id, option_id) 
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$customerId, $fieldId, $optionId]);
    
    return ['success' => true, 'message' => '保存成功'];
}

function clearCustomerValue(array $input): array {
    $customerId = (int)($input['customer_id'] ?? 0);
    $fieldId = (int)($input['field_id'] ?? 0);
    
    if (!$customerId || !$fieldId) {
        return ['success' => false, 'message' => '参数不完整'];
    }
    
    $pdo = Db::pdo();
    $stmt = $pdo->prepare("DELETE FROM customer_filter_values WHERE customer_id = ? AND field_id = ?");
    $stmt->execute([$customerId, $fieldId]);
    
    return ['success' => true, 'message' => '已清除'];
}
