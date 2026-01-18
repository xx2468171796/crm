<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 字段管理API（第3层）
 * 提供维度下字段的增删改查功能
 * 在新三层结构中：menus → dimensions → fields
 */

// 清除任何之前的输出缓冲
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 检查登录
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 检查管理员权限
$user = current_user();
if (!canOrAdmin(PermissionCode::FIELD_MANAGE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限操作']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            // 获取选项列表
            getOptionList();
            break;
            
        case 'get':
            // 获取单个选项
            getOption();
            break;
            
        case 'add':
            // 添加选项
            addOption();
            break;
            
        case 'batch_add':
            // 批量添加选项
            batchAddOptions();
            break;
            
        case 'edit':
            // 编辑选项
            editOption();
            break;
            
        case 'delete':
            // 删除选项
            deleteOption();
            break;
            
        case 'batch':
            // 批量操作
            batchOperation();
            break;
            
        case 'sort':
            // 更新排序
            updateSort();
            break;
            
        default:
            throw new Exception('无效的操作');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 获取选项列表
 */
function getOptionList() {
    $fieldId = intval($_GET['field_id'] ?? 0);
    
    if ($fieldId <= 0) {
        throw new Exception('字段ID无效');
    }
    
    $sql = 'SELECT 
                f.*,
                f.field_name as option_label,
                f.field_value as option_value,
                f.dimension_id as field_id
            FROM fields f
            WHERE f.dimension_id = ? 
            ORDER BY f.row_order, f.col_order, f.sort_order, f.id';
    
    $options = Db::query($sql, [$fieldId]);
    
    echo json_encode([
        'success' => true,
        'data' => $options
    ]);
}

/**
 * 获取单个选项
 */
function getOption() {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        throw new Exception('选项ID无效');
    }
    
    $option = Db::queryOne('SELECT f.*, f.field_name as option_label, f.field_value as option_value, f.dimension_id as field_id FROM fields f WHERE f.id = ?', [$id]);
    
    if (!$option) {
        throw new Exception('选项不存在');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $option
    ]);
}

/**
 * 添加选项
 */
function addOption() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 验证必填字段
    if (empty($data['field_id'])) {
        throw new Exception('字段ID不能为空');
    }
    
    if (empty($data['option_label'])) {
        throw new Exception('选项名称不能为空');
    }
    
    $fieldId = intval($data['field_id']);
    
    // 检查维度是否存在
    $dimension = Db::queryOne('SELECT * FROM dimensions WHERE id = ?', [$fieldId]);
    if (!$dimension) {
        throw new Exception('维度不存在');
    }
    
    // 处理父选项：如果提供了父选项名称，先创建父选项
    $parentFieldId = $data['parent_option_id'] ?? null;
    
    if (!empty($data['parent_option_name']) && empty($parentFieldId)) {
        // 需要创建新的父选项
        $parentName = trim($data['parent_option_name']);
        
        // 检查是否已存在同名父选项
        $existingParent = Db::queryOne(
            'SELECT id FROM fields WHERE dimension_id = ? AND field_name = ? AND status = 1',
            [$fieldId, $parentName]
        );
        
        if ($existingParent) {
            $parentFieldId = $existingParent['id'];
        } else {
            // 创建新的父选项
            $parentMaxSort = Db::queryOne('SELECT MAX(sort_order) as max_sort FROM fields WHERE dimension_id = ?', [$fieldId]);
            $parentSortOrder = ($parentMaxSort['max_sort'] ?? 0) + 10;
            
            $parentFieldCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $parentName));
            $parentSql = 'INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, parent_field_id, sort_order, status, create_time, update_time) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
            
            $parentParams = [
                $fieldId,
                $parentName,
                $parentFieldCode,
                $parentName,
                $data['field_type'] ?? 'radio',
                $data['row_order'] ?? 0,
                $data['col_order'] ?? 0,
                isset($data['width']) && $data['width'] !== '' ? $data['width'] : 'auto',
                null, // 父选项本身没有父选项
                $parentSortOrder,
                time(),
                time()
            ];
            
            Db::execute($parentSql, $parentParams);
            $parentFieldId = Db::lastInsertId();
        }
    }
    
    // 获取最大排序号
    $maxSort = Db::queryOne('SELECT MAX(sort_order) as max_sort FROM fields WHERE dimension_id = ?', [$fieldId]);
    $sortOrder = ($maxSort['max_sort'] ?? 0) + 10;
    
    // 插入数据
    $sql = 'INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, parent_field_id, sort_order, status, create_time, update_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
    
    $optionValue = $data['option_value'] ?? $data['option_label'];
    $fieldCode = $data['field_code'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $data['option_label']));
    
    $params = [
        $fieldId,
        $data['option_label'],
        $fieldCode,
        $optionValue,
        $data['field_type'] ?? 'radio',
        $data['row_order'] ?? 0,
        $data['col_order'] ?? 0,
        isset($data['width']) && $data['width'] !== '' ? $data['width'] : 'auto',
        $parentFieldId,
        $sortOrder,
        time(),
        time()
    ];
    
    Db::execute($sql, $params);
    $id = Db::lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => '添加成功',
        'data' => ['id' => $id]
    ]);
}

/**
 * 批量添加选项
 */
function batchAddOptions() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 验证必填字段
    if (empty($data['field_id']) && empty($data['common']['field_id'])) {
        throw new Exception('字段ID不能为空');
    }
    
    if (empty($data['options']) || !is_array($data['options'])) {
        throw new Exception('选项列表不能为空');
    }
    
    $fieldId = intval($data['field_id'] ?? $data['common']['field_id']);
    $common = $data['common'] ?? [];
    $options = $data['options'];
    
    // 检查维度是否存在
    $dimension = Db::queryOne('SELECT * FROM dimensions WHERE id = ?', [$fieldId]);
    if (!$dimension) {
        throw new Exception('维度不存在');
    }
    
    // 获取最大排序号
    $maxSort = Db::queryOne('SELECT MAX(sort_order) as max_sort FROM fields WHERE dimension_id = ?', [$fieldId]);
    $baseSortOrder = ($maxSort['max_sort'] ?? 0) + 10;
    
    // 开始事务
    Db::beginTransaction();
    
    try {
        $insertedCount = 0;
        $sql = 'INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, parent_field_id, sort_order, status, create_time, update_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
        
        $now = time();
        $sortOrder = $baseSortOrder;
        
        foreach ($options as $option) {
            if (empty($option['option_label'])) {
                continue; // 跳过空的选项
            }
            
            $optionValue = $option['option_value'] ?? $option['option_label'];
            // 生成字段代码
            $fieldCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $option['option_label']));
            // 确保字段代码唯一（添加时间戳和序号）
            $fieldCode = $fieldCode . '_' . $now . '_' . $insertedCount;
            // 限制长度
            if (strlen($fieldCode) > 50) {
                $fieldCode = substr($fieldCode, 0, 47) . '_' . $insertedCount;
            }
            
            $params = [
                $fieldId,
                $option['option_label'],
                $fieldCode,
                $optionValue,
                $common['field_type'] ?? 'select',
                $common['row_order'] ?? 0,
                $common['col_order'] ?? 0,
                isset($common['width']) && $common['width'] !== '' ? $common['width'] : 'auto',
                null,
                $sortOrder,
                $now,
                $now
            ];
            
            Db::execute($sql, $params);
            $insertedCount++;
            $sortOrder += 10;
        }
        
        if ($insertedCount === 0) {
            throw new Exception('没有有效的选项可以添加');
        }
        
        Db::commit();
        
        echo json_encode([
            'success' => true,
            'message' => "成功添加 {$insertedCount} 个选项",
            'data' => ['count' => $insertedCount]
        ]);
    } catch (Exception $e) {
        Db::rollback();
        throw $e;
    }
}

/**
 * 编辑选项
 */
function editOption() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('选项ID无效');
    }
    
    // 检查字段是否存在
    $option = Db::queryOne('SELECT * FROM fields WHERE id = ?', [$id]);
    if (!$option) {
        throw new Exception('字段不存在');
    }
    
    // 验证必填字段
    if (empty($data['option_label'])) {
        throw new Exception('选项名称不能为空');
    }
    
    // 处理父选项：如果提供了父选项名称，先创建父选项
    $parentFieldId = $data['parent_option_id'] ?? null;
    $fieldId = $option['dimension_id'];
    
    if (!empty($data['parent_option_name']) && empty($parentFieldId)) {
        // 需要创建新的父选项
        $parentName = trim($data['parent_option_name']);
        
        // 检查是否已存在同名父选项
        $existingParent = Db::queryOne(
            'SELECT id FROM fields WHERE dimension_id = ? AND field_name = ? AND status = 1',
            [$fieldId, $parentName]
        );
        
        if ($existingParent) {
            $parentFieldId = $existingParent['id'];
        } else {
            // 创建新的父选项
            $parentMaxSort = Db::queryOne('SELECT MAX(sort_order) as max_sort FROM fields WHERE dimension_id = ?', [$fieldId]);
            $parentSortOrder = ($parentMaxSort['max_sort'] ?? 0) + 10;
            
            $parentFieldCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $parentName));
            $parentSql = 'INSERT INTO fields (dimension_id, field_name, field_code, field_value, field_type, row_order, col_order, width, parent_field_id, sort_order, status, create_time, update_time) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)';
            
            $parentParams = [
                $fieldId,
                $parentName,
                $parentFieldCode,
                $parentName,
                $data['field_type'] ?? $option['field_type'],
                $data['row_order'] ?? $option['row_order'],
                $data['col_order'] ?? $option['col_order'],
                isset($data['width']) && $data['width'] !== '' 
                    ? $data['width'] 
                    : ($option['width'] ?: 'auto'),
                null, // 父选项本身没有父选项
                $parentSortOrder,
                time(),
                time()
            ];
            
            Db::execute($parentSql, $parentParams);
            $parentFieldId = Db::lastInsertId();
        }
    }
    
    // 更新数据
    $sql = 'UPDATE fields 
            SET field_name = ?, field_value = ?, field_code = ?, field_type = ?, row_order = ?, col_order = ?, width = ?, parent_field_id = ?, update_time = ?
            WHERE id = ?';
    
    $optionValue = $data['option_value'] ?? $data['option_label'];
    $fieldCode = $data['field_code'] ?? $option['field_code'];
    
    $params = [
        $data['option_label'],
        $optionValue,
        $fieldCode,
        $data['field_type'] ?? $option['field_type'],
        $data['row_order'] ?? $option['row_order'],
        $data['col_order'] ?? $option['col_order'],
        isset($data['width']) && $data['width'] !== '' 
            ? $data['width'] 
            : ($option['width'] ?: 'auto'),
        $parentFieldId,
        time(),
        $id
    ];
    
    Db::execute($sql, $params);
    
    echo json_encode([
        'success' => true,
        'message' => '更新成功'
    ]);
}

/**
 * 删除选项
 */
function deleteOption() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('选项ID无效');
    }
    
    // 检查字段是否存在
    $option = Db::queryOne('SELECT * FROM fields WHERE id = ?', [$id]);
    if (!$option) {
        throw new Exception('字段不存在');
    }
    
    // 删除字段
    Db::execute('DELETE FROM fields WHERE id = ?', [$id]);
    
    echo json_encode([
        'success' => true,
        'message' => '删除成功'
    ]);
}

/**
 * 批量操作
 */
function batchOperation() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['ids']) || !is_array($data['ids'])) {
        throw new Exception('请选择要操作的选项');
    }
    
    if (empty($data['operation'])) {
        throw new Exception('请指定操作类型');
    }
    
    $ids = array_map('intval', $data['ids']);
    $operation = $data['operation'];
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    switch ($operation) {
        case 'enable':
            $sql = "UPDATE fields SET status = 1, update_time = ? WHERE id IN ($placeholders)";
            $params = array_merge([time()], $ids);
            Db::execute($sql, $params);
            $message = '批量启用成功';
            break;
            
        case 'disable':
            $sql = "UPDATE fields SET status = 0, update_time = ? WHERE id IN ($placeholders)";
            $params = array_merge([time()], $ids);
            Db::execute($sql, $params);
            $message = '批量禁用成功';
            break;
            
        case 'delete':
            $sql = "DELETE FROM fields WHERE id IN ($placeholders)";
            Db::execute($sql, $ids);
            $message = '批量删除成功';
            break;
            
        default:
            throw new Exception('无效的操作类型');
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => ['affected' => count($ids)]
    ]);
}

/**
 * 更新排序
 */
function updateSort() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['orders']) || !is_array($data['orders'])) {
        throw new Exception('排序数据无效');
    }
    
    // 开始事务
    Db::beginTransaction();
    
    try {
        foreach ($data['orders'] as $index => $id) {
            $sortOrder = ($index + 1) * 10;
            Db::execute('UPDATE fields SET sort_order = ?, update_time = ? WHERE id = ?', [$sortOrder, time(), $id]);
        }
        
        Db::commit();
        
        echo json_encode([
            'success' => true,
            'message' => '排序更新成功'
        ]);
    } catch (Exception $e) {
        Db::rollback();
        throw $e;
    }
}
