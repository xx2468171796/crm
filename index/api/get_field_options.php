<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 获取字段选项API
 * 用于动态加载字段选项，特别是级联字段
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// 需要登录
auth_require();

try {
    $fieldId = intval($_GET['field_id'] ?? 0);
    $parentValue = $_GET['parent_value'] ?? null;
    
    if ($fieldId <= 0) {
        throw new Exception('字段ID无效');
    }
    
    // 获取字段配置
    $field = Db::queryOne('SELECT * FROM custom_fields WHERE id = ?', [$fieldId]);
    
    if (!$field) {
        throw new Exception('字段不存在');
    }
    
    $options = [];
    
    if ($field['option_source'] === 'inline') {
        // 从JSON解析选项
        $optionsData = json_decode($field['field_options'], true);
        if ($optionsData) {
            foreach ($optionsData as $option) {
                $options[] = [
                    'option_value' => $option,
                    'option_label' => $option
                ];
            }
        }
    } else {
        // 从数据库表读取选项
        if ($parentValue && $field['parent_field_id']) {
            // 级联查询：根据父级值查询子选项
            // 先找到父级选项的ID
            $parentField = Db::queryOne('SELECT * FROM custom_fields WHERE id = ?', [$field['parent_field_id']]);
            
            if ($parentField) {
                $parentOption = Db::queryOne(
                    'SELECT id FROM field_options WHERE field_id = ? AND option_value = ?',
                    [$parentField['id'], $parentValue]
                );
                
                if ($parentOption) {
                    $sql = "SELECT * FROM field_options 
                            WHERE field_id = :field_id 
                            AND parent_option_id = :parent_option_id
                            AND status = 1
                            ORDER BY sort_order, id";
                    $options = Db::query($sql, [
                        'field_id' => $fieldId,
                        'parent_option_id' => $parentOption['id']
                    ]);
                }
            }
        } else {
            // 普通查询：查询所有选项
            $sql = "SELECT * FROM field_options 
                    WHERE field_id = :field_id 
                    AND status = 1";
            
            // 如果是级联字段但没有父级值，只查询顶级选项
            if ($field['parent_field_id']) {
                $sql .= " AND parent_option_id IS NULL";
            }
            
            $sql .= " ORDER BY sort_order, id";
            
            $options = Db::query($sql, ['field_id' => $fieldId]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'options' => $options
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
