<?php
/**
 * 字段渲染工具类
 * 用于动态渲染自定义字段
 */

require_once __DIR__ . '/db.php';

/**
 * 根据菜单代码加载字段配置（新三层结构）
 * @param string $menuCode 菜单代码（如 first_contact）
 * @param bool $includeDisabled 是否包含禁用的字段
 * @return array 按维度分组的字段配置数组
 */
function getFieldsByModule($menuCode, $includeDisabled = false) {
    // 查询三层结构：menus → dimensions → fields
    // 先检查 dimensions 表是否有 field_type 字段
    $checkSql = "SHOW COLUMNS FROM dimensions LIKE 'field_type'";
    $hasFieldType = !empty(Db::query($checkSql));
    
    $sql = "SELECT 
                d.id as dimension_id,
                d.dimension_name,
                d.dimension_code,";
    
    // 如果有 field_type 字段，则查询它
    if ($hasFieldType) {
        $sql .= "d.field_type as dimension_field_type,";
    }
    
    $sql .= "
                d.sort_order as dimension_sort_order,
                f.id,
                f.field_name,
                f.field_code,
                f.field_value,
                f.field_type,
                f.is_required,
                f.allow_custom,
                f.row_order,
                f.col_order,
                f.width,
                f.display_type,
                f.placeholder,
                f.help_text,
                f.parent_field_id,
                f.sort_order,
                f.status
            FROM fields f
            INNER JOIN dimensions d ON f.dimension_id = d.id
            INNER JOIN menus m ON d.menu_id = m.id
            WHERE m.menu_code = :menu_code";
    
    if (!$includeDisabled) {
        $sql .= " AND f.status = 1 AND d.status = 1 AND m.status = 1";
    }
    
    $sql .= " ORDER BY d.sort_order, f.row_order, f.col_order, f.sort_order";
    
    try {
        return Db::query($sql, ['menu_code' => $menuCode]);
    } catch (Exception $e) {
        error_log("getFieldsByModule error: " . $e->getMessage());
        return [];
    }
}

/**
 * 获取字段的选项列表
 * @param array $field 字段配置
 * @param int|null $parentFieldId 父级字段ID（用于级联）
 * @return array 选项数组
 */
function getFieldOptions($field, $parentFieldId = null) {
    // 优先从 field_options 表获取选项
    if (isset($field['id'])) {
        try {
            $options = Db::query(
                "SELECT option_value, option_label FROM field_options 
                 WHERE field_id = :field_id AND status = 1 
                 AND (parent_option_id IS NULL OR parent_option_id = 0)
                 ORDER BY sort_order, id",
                ['field_id' => $field['id']]
            );
            
            if (!empty($options)) {
                $result = [];
                foreach ($options as $option) {
                    $result[] = [
                        'option_value' => $option['option_value'],
                        'option_label' => $option['option_label']
                    ];
                }
                return $result;
            }
        } catch (Exception $e) {
            // 如果表不存在，继续使用其他方法
        }
    }
    
    // 如果字段有 field_value，说明它本身就是一个选项（新三层结构）
    if (isset($field['field_value'])) {
        return [[
            'option_value' => $field['field_value'],
            'option_label' => $field['field_name']
        ]];
    }
    
    return [];
}

/**
 * 渲染单个字段
 * @param array $field 字段配置
 * @param mixed $value 字段值
 * @return string HTML代码
 */
function renderField($field, $value = null) {
    $html = '<div class="field-row">';
    $html .= '<div class="field-label">';
    $html .= htmlspecialchars($field['field_name']);
    if ($field['is_required']) {
        $html .= ' <span class="text-danger">*</span>';
    }
    $html .= '</div>';
    $html .= '<div class="field-options">';
    
    switch ($field['field_type']) {
        case 'text':
            $html .= renderTextField($field, $value);
            break;
        case 'textarea':
            $html .= renderTextareaField($field, $value);
            break;
        case 'select':
            $html .= renderSelectField($field, $value);
            break;
        case 'cascading_select':
            $html .= renderCascadingSelectField($field, $value);
            break;
        case 'radio':
            $html .= renderRadioField($field, $value);
            break;
        case 'checkbox':
            $html .= renderCheckboxField($field, $value);
            break;
        case 'date':
            $html .= renderDateField($field, $value);
            break;
        default:
            $html .= '<p class="text-muted">不支持的字段类型</p>';
    }
    
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * 渲染文本框
 */
function renderTextField($field, $value = null) {
    $required = $field['is_required'] ? 'required' : '';
    $valueAttr = $value ? 'value="' . htmlspecialchars($value) . '"' : '';
    
    // 使用iOS样式类 form-input（手机版会自动应用iOS样式）
    return sprintf(
        '<input type="text" name="%s" class="form-input" %s %s>',
        htmlspecialchars($field['field_code']),
        $valueAttr,
        $required
    );
}

/**
 * 渲染多行文本框
 */
function renderTextareaField($field, $value = null) {
    $required = $field['is_required'] ? 'required' : '';
    $valueText = $value ? htmlspecialchars($value) : '';
    
    // 使用iOS样式类 form-input（手机版会自动应用iOS样式）
    return sprintf(
        '<textarea name="%s" class="form-input" rows="3" %s>%s</textarea>',
        htmlspecialchars($field['field_code']),
        $required,
        $valueText
    );
}

/**
 * 渲染下拉框
 */
function renderSelectField($field, $value = null) {
    $required = $field['is_required'] ? 'required' : '';
    $options = getFieldOptions($field);
    
    $html = sprintf(
        '<select name="%s" class="form-control" %s data-field-id="%s">',
        htmlspecialchars($field['field_code']),
        $required,
        $field['id']
    );
    
    $html .= '<option value="">请选择</option>';
    
    foreach ($options as $option) {
        $selected = ($value == $option['option_value']) ? 'selected' : '';
        $html .= sprintf(
            '<option value="%s" %s>%s</option>',
            htmlspecialchars($option['option_value']),
            $selected,
            htmlspecialchars($option['option_label'])
        );
    }
    
    $html .= '</select>';
    
    // 如果是级联字段的父级，添加onchange事件
    if ($field['parent_field_id'] === null) {
        // 检查是否有子字段
        $hasChildren = Db::queryOne(
            "SELECT COUNT(*) as count FROM fields WHERE parent_field_id = :parent_id",
            ['parent_id' => $field['id']]
        );
        
        if ($hasChildren && $hasChildren['count'] > 0) {
            $html .= sprintf(
                '<script>
                document.querySelector(\'select[name="%s"]\').addEventListener(\'change\', function() {
                    loadCascadeOptions(%d, this.value);
                });
                </script>',
                htmlspecialchars($field['field_code']),
                $field['id']
            );
        }
    }
    
    return $html;
}

/**
 * 渲染级联下拉框
 */
function renderCascadingSelectField($field, $value = null) {
    $required = $field['is_required'] ? 'required' : '';
    
    // 获取父级字段（兼容 custom_fields 和 fields 表）
    $parentField = null;
    if ($field['parent_field_id']) {
        // 先尝试从 custom_fields 表查询
        try {
            $parentField = Db::queryOne(
                "SELECT * FROM custom_fields WHERE id = :parent_id",
                ['parent_id' => $field['parent_field_id']]
            );
        } catch (Exception $e) {
            // 如果 custom_fields 表不存在，尝试从 fields 表查询
            try {
                $parentField = Db::queryOne(
                    "SELECT * FROM fields WHERE id = :parent_id",
                    ['parent_id' => $field['parent_field_id']]
                );
            } catch (Exception $e2) {
                // 忽略错误
            }
        }
    }
    
    $html = '';
    
    // 如果有父级字段，先渲染父级下拉框
    if ($parentField) {
        $parentOptions = getFieldOptions($parentField);
        $parentValue = $_POST[$parentField['field_code']] ?? null;
        
        $html .= sprintf(
            '<select name="%s" class="form-control mb-2" id="parent_%s" data-field-id="%s" onchange="loadCascadeOptions(%d, this.value, \'%s\')">',
            htmlspecialchars($parentField['field_code']),
            htmlspecialchars($parentField['field_code']),
            $parentField['id'],
            $field['id'],
            htmlspecialchars($field['field_code'])
        );
        $html .= '<option value="">请选择' . htmlspecialchars($parentField['field_name']) . '</option>';
        
        foreach ($parentOptions as $option) {
            $selected = ($parentValue == $option['option_value']) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s</option>',
                htmlspecialchars($option['option_value']),
                $selected,
                htmlspecialchars($option['option_label'])
            );
        }
        $html .= '</select>';
    }
    
    // 渲染当前级联字段的下拉框
    $html .= sprintf(
        '<select name="%s" class="form-control" id="cascade_%s" %s data-field-id="%s" %s>',
        htmlspecialchars($field['field_code']),
        htmlspecialchars($field['field_code']),
        $parentField ? 'disabled' : '',
        $field['id'],
        $required
    );
    $html .= '<option value="">' . ($parentField ? '请先选择' . htmlspecialchars($parentField['field_name']) : '请选择') . '</option>';
    
    // 如果有父级值，加载子选项
    if ($parentField && $parentValue) {
        $childOptions = getFieldOptionsByParent($field, $parentValue);
        foreach ($childOptions as $option) {
            $selected = ($value == $option['option_value']) ? 'selected' : '';
            $html .= sprintf(
                '<option value="%s" %s>%s</option>',
                htmlspecialchars($option['option_value']),
                $selected,
                htmlspecialchars($option['option_label'])
            );
        }
    }
    
    $html .= '</select>';
    
    // 添加JavaScript函数来动态加载级联选项
    if ($parentField) {
        $html .= sprintf(
            '<script>
            if (typeof loadCascadeOptions !== "function") {
                function loadCascadeOptions(fieldId, parentValue, fieldCode) {
                    if (!parentValue) {
                        document.getElementById("cascade_" + fieldCode).innerHTML = "<option value="">请先选择父级选项</option>";
                        document.getElementById("cascade_" + fieldCode).disabled = true;
                        return;
                    }
                    
                    fetch("/api/get_field_options.php?field_id=" + fieldId + "&parent_value=" + encodeURIComponent(parentValue))
                        .then(res => res.json())
                        .then(data => {
                            const select = document.getElementById("cascade_" + fieldCode);
                            if (data.success && data.options) {
                                select.innerHTML = "<option value="">请选择</option>";
                                data.options.forEach(opt => {
                                    const option = document.createElement("option");
                                    option.value = opt.option_value || opt.option_label;
                                    option.textContent = opt.option_label || opt.option_value;
                                    select.appendChild(option);
                                });
                                select.disabled = false;
                            } else {
                                select.innerHTML = "<option value="">暂无选项</option>";
                                select.disabled = false;
                            }
                        })
                        .catch(err => {
                            console.error("加载级联选项失败:", err);
                            document.getElementById("cascade_" + fieldCode).innerHTML = "<option value="">加载失败</option>";
                        });
                }
            }
            </script>',
            $field['id'],
            htmlspecialchars($field['field_code'])
        );
    }
    
    return $html;
}

/**
 * 根据父级值获取字段选项（用于级联）
 */
function getFieldOptionsByParent($field, $parentValue) {
    if (!$field['parent_field_id']) {
        return getFieldOptions($field);
    }
    
    // 获取父级字段（兼容 custom_fields 和 fields 表）
    $parentField = null;
    try {
        $parentField = Db::queryOne(
            "SELECT * FROM custom_fields WHERE id = :parent_id",
            ['parent_id' => $field['parent_field_id']]
        );
    } catch (Exception $e) {
        // 如果 custom_fields 表不存在，尝试从 fields 表查询
        try {
            $parentField = Db::queryOne(
                "SELECT * FROM fields WHERE id = :parent_id",
                ['parent_id' => $field['parent_field_id']]
            );
        } catch (Exception $e2) {
            // 忽略错误
        }
    }
    
    if (!$parentField) {
        return [];
    }
    
    // 查找父级选项ID
    $parentOption = Db::queryOne(
        "SELECT id FROM field_options WHERE field_id = :field_id AND option_value = :option_value AND status = 1",
        [
            'field_id' => $field['parent_field_id'],
            'option_value' => $parentValue
        ]
    );
    
    if (!$parentOption) {
        return [];
    }
    
    // 查询子选项
    $options = Db::query(
        "SELECT * FROM field_options 
         WHERE field_id = :field_id 
         AND parent_option_id = :parent_option_id
         AND status = 1
         ORDER BY sort_order, id",
        [
            'field_id' => $field['id'],
            'parent_option_id' => $parentOption['id']
        ]
    );
    
    $result = [];
    foreach ($options as $option) {
        $result[] = [
            'option_value' => $option['option_value'],
            'option_label' => $option['option_label']
        ];
    }
    
    return $result;
}

/**
 * 渲染单选框
 */
function renderRadioField($field, $value = null) {
    $options = getFieldOptions($field);
    
    if (empty($options)) {
        return '<p class="text-muted">无可用选项</p>';
    }
    
    $required = $field['is_required'] ? 'required' : '';
    $html = '';
    
    foreach ($options as $option) {
        $checked = ($value == $option['option_value']) ? 'checked' : '';
        $html .= sprintf(
            '<label><input type="radio" name="%s" value="%s" %s %s> %s</label>',
            htmlspecialchars($field['field_code']),
            htmlspecialchars($option['option_value']),
            $checked,
            $required,
            htmlspecialchars($option['option_label'])
        );
    }
    
    // 不再自动生成 _custom 输入框
    // 如果后台配置了独立的文本框字段（field_type='text'），会通过 renderTextField 函数正确渲染
    
    return $html;
}

/**
 * 渲染多选框
 */
function renderCheckboxField($field, $value = null) {
    $options = getFieldOptions($field);
    
    if (empty($options)) {
        return '<p class="text-muted">无可用选项</p>';
    }
    
    // 将保存的值（用"、"分隔）转换为数组
    $selectedValues = $value ? explode('、', $value) : [];
    
    $html = '';
    foreach ($options as $option) {
        $checked = in_array($option['option_label'], $selectedValues) ? 'checked' : '';
        $html .= sprintf(
            '<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
            htmlspecialchars($field['field_code']),
            htmlspecialchars($option['option_label']),
            $checked,
            htmlspecialchars($option['option_label'])
        );
    }
    
    // 不再自动生成 _custom 输入框
    // 如果后台配置了独立的文本框字段（field_type='text'），会通过 renderTextField 函数正确渲染
    
    return $html;
}

/**
 * 渲染日期选择器
 */
function renderDateField($field, $value = null) {
    $required = $field['is_required'] ? 'required' : '';
    $valueAttr = $value ? 'value="' . htmlspecialchars($value) . '"' : '';
    
    return sprintf(
        '<input type="date" name="%s" class="form-control" %s %s>',
        htmlspecialchars($field['field_code']),
        $valueAttr,
        $required
    );
}

/**
 * 渲染模块的所有字段（新三层结构：按维度分组）
 * @param string $menuCode 菜单代码
 * @param array $values 字段值数组（key为field_code）
 * @return string HTML代码
 */
function renderModuleFields($menuCode, $values = []) {
    $fields = getFieldsByModule($menuCode);
    
    if (empty($fields)) {
        return '<p class="text-muted">该模块暂无自定义字段</p>';
    }
    
    // 按维度分组
    $dimensionGroups = [];
    foreach ($fields as $field) {
        $dimId = $field['dimension_id'];
        if (!isset($dimensionGroups[$dimId])) {
            $dimensionGroups[$dimId] = [
                'dimension_id' => $dimId,
                'dimension_name' => $field['dimension_name'],
                'dimension_code' => $field['dimension_code'],
                'dimension_field_type' => $field['dimension_field_type'] ?? $field['field_type'], // 优先使用维度的field_type
                'dimension_sort_order' => $field['dimension_sort_order'] ?? 0,
                'fields' => []
            ];
        }
        $dimensionGroups[$dimId]['fields'][] = $field;
    }
    
    // 按sort_order排序
    uasort($dimensionGroups, function($a, $b) {
        return $a['dimension_sort_order'] - $b['dimension_sort_order'];
    });
    
    // 渲染每个维度
    $html = '';
    foreach ($dimensionGroups as $dimension) {
        $html .= renderDimension($dimension, $values);
    }
    
    return $html;
}

/**
 * 渲染一个维度（包含该维度下的所有字段）
 * @param array $dimension 维度信息（包含fields数组）
 * @param array $values 字段值数组
 * @return string HTML代码
 */
function renderDimension($dimension, $values = []) {
    $dimensionType = $dimension['dimension_field_type'] ?? 'radio';
    $dimensionCode = $dimension['dimension_code'];
    $value = $values[$dimensionCode] ?? null;
    
    $html = '<div class="field-row">';
    $html .= '<div class="field-label">' . htmlspecialchars($dimension['dimension_name']) . '</div>';
    
    // 根据维度类型决定渲染方式
    switch ($dimensionType) {
        case 'radio':
        case 'checkbox':
            // 多选项类型：渲染所有字段作为选项
            $html .= '<div class="field-options">';
            foreach ($dimension['fields'] as $field) {
                // 处理宽度
                $width = $field['width'] ?? 'auto';
                $widthStyle = ($width === 'auto' || $width === '') 
                    ? 'flex: 0 0 auto; min-width: fit-content;' 
                    : 'width:' . htmlspecialchars($width) . ';';
                
                if ($dimensionType === 'radio') {
                    $checked = ($value == $field['field_value']) ? 'checked' : '';
                    $html .= sprintf(
                        '<label style="%s"><input type="radio" name="%s" value="%s" %s> %s</label>',
                        $widthStyle,
                        htmlspecialchars($dimensionCode),
                        htmlspecialchars($field['field_value']),
                        $checked,
                        htmlspecialchars($field['field_name'])
                    );
                } else { // checkbox
                    $selectedValues = $value ? explode('、', $value) : [];
                    $checked = in_array($field['field_value'], $selectedValues) ? 'checked' : '';
                    $html .= sprintf(
                        '<label style="%s"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
                        $widthStyle,
                        htmlspecialchars($dimensionCode),
                        htmlspecialchars($field['field_value']),
                        $checked,
                        htmlspecialchars($field['field_name'])
                    );
                }
            }
            
            // 不再自动生成 _custom 输入框
            // 如果后台配置了独立的文本框字段（field_type='text'），会通过其他分支正确渲染
            
            $html .= '</div>';
            break;
            
        case 'select':
            // 下拉框：渲染select元素
            $html .= '<div class="field-options">';
            $html .= sprintf('<select name="%s" class="form-control form-control-sm" style="width:auto; min-width:200px;">', htmlspecialchars($dimensionCode));
            $html .= '<option value="">请选择</option>';
            foreach ($dimension['fields'] as $field) {
                $selected = ($value == $field['field_value']) ? 'selected' : '';
                $html .= sprintf(
                    '<option value="%s" %s>%s</option>',
                    htmlspecialchars($field['field_value']),
                    $selected,
                    htmlspecialchars($field['field_name'])
                );
            }
            $html .= '</select>';
            $html .= '</div>';
            break;
            
        case 'text':
        case 'textarea':
        case 'date':
            // 对于 text、textarea、date 类型，遍历所有字段，每个字段都渲染一个独立的输入框
            // 按照 row_order 和 col_order 来组织布局
            // 先按行分组
            $rows = [];
            foreach ($dimension['fields'] as $field) {
                $rowOrder = isset($field['row_order']) ? (int)$field['row_order'] : 0;
                if (!isset($rows[$rowOrder])) {
                    $rows[$rowOrder] = [];
                }
                $rows[$rowOrder][] = $field;
            }
            
            // 按行号排序
            ksort($rows);
            
            // 渲染每一行
            foreach ($rows as $rowOrder => $rowFields) {
                // 按列号排序
                usort($rowFields, function($a, $b) {
                    $colA = isset($a['col_order']) ? (int)$a['col_order'] : 0;
                    $colB = isset($b['col_order']) ? (int)$b['col_order'] : 0;
                    return $colA - $colB;
                });
                
                // 渲染行容器（使用 flex 布局支持多列）
                $html .= '<div class="field-options-row" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;" data-row="' . $rowOrder . '">';
                
                foreach ($rowFields as $field) {
                    $fieldCode = $field['field_code'];
                    $fieldType = $field['field_type'] ?? $dimensionType; // 优先使用字段自己的类型
                    // 优先使用字段代码获取值，如果不存在则尝试从维度代码获取（兼容旧数据）
                    $fieldValue = $values[$fieldCode] ?? ($values[$dimensionCode] ?? null);
                    $fieldName = $field['field_name'] ?? '';
                    $placeholder = $field['placeholder'] ?? '';
                    $isRequired = $field['is_required'] ?? false;
                    $requiredAttr = $isRequired ? 'required' : '';
                    
                    // 处理宽度和列位置
                    $width = $field['width'] ?? 'auto';
                    $colOrder = isset($field['col_order']) ? (int)$field['col_order'] : 0;
                    
                    // 如果宽度是 auto，根据列数自动计算（假设一行最多12列）
                    if ($width === 'auto' || $width === '') {
                        // 计算这一行有多少列
                        $colCount = count($rowFields);
                        if ($colCount > 0) {
                            $widthStyle = 'flex: 1 1 ' . (100 / $colCount) . '%; min-width: 200px;';
                        } else {
                            $widthStyle = 'width: 100%;';
                        }
                    } else {
                        $widthStyle = 'width:' . htmlspecialchars($width) . ';';
                    }
                    
                    // 根据字段类型渲染
                    switch ($fieldType) {
                        case 'text':
                            // 使用iOS样式类 form-input（手机版会自动应用iOS样式）
                            $html .= sprintf(
                                '<div style="%s" data-col="%d"><label class="form-label" style="display: block; margin-bottom: 6px; font-size: 12px; font-weight: 500; color: var(--text-secondary, #48484A); text-transform: uppercase; letter-spacing: 0.5px;">%s%s</label><input type="text" name="%s" value="%s" class="form-input" style="width: 100%%;" placeholder="%s" %s></div>',
                                $widthStyle,
                                $colOrder,
                                htmlspecialchars($fieldName),
                                $isRequired ? ' <span class="text-danger">*</span>' : '',
                                htmlspecialchars($fieldCode),
                                htmlspecialchars($fieldValue ?? ''),
                                htmlspecialchars($placeholder),
                                $requiredAttr
                            );
                            break;
                            
                        case 'textarea':
                            $textareaRows = isset($field['rows']) ? (int)$field['rows'] : 3;
                            // 使用iOS样式类 form-input（手机版会自动应用iOS样式）
                            $html .= sprintf(
                                '<div style="%s" data-col="%d"><label class="form-label" style="display: block; margin-bottom: 6px; font-size: 12px; font-weight: 500; color: var(--text-secondary, #48484A); text-transform: uppercase; letter-spacing: 0.5px;">%s%s</label><textarea name="%s" class="form-input" rows="%d" style="width: 100%%;" placeholder="%s" %s>%s</textarea></div>',
                                $widthStyle,
                                $colOrder,
                                htmlspecialchars($fieldName),
                                $isRequired ? ' <span class="text-danger">*</span>' : '',
                                htmlspecialchars($fieldCode),
                                $textareaRows,
                                htmlspecialchars($placeholder),
                                $requiredAttr,
                                htmlspecialchars($fieldValue ?? '')
                            );
                            break;
                            
                        case 'date':
                            $html .= sprintf(
                                '<div style="%s" data-col="%d"><label style="display: block; margin-bottom: 4px; font-size: 14px;">%s%s</label><input type="date" name="%s" value="%s" class="form-control form-control-sm" style="width: 100%%;" %s></div>',
                                $widthStyle,
                                $colOrder,
                                htmlspecialchars($fieldName),
                                $isRequired ? ' <span class="text-danger">*</span>' : '',
                                htmlspecialchars($fieldCode),
                                htmlspecialchars($fieldValue ?? ''),
                                $requiredAttr
                            );
                            break;
                            
                        case 'select':
                            // 获取下拉框的选项
                            $options = [];
                            
                            // 方法1：从 field_options 表查询选项（标准方式）
                            try {
                                $fieldOptions = Db::query(
                                    "SELECT option_value, option_label FROM field_options WHERE field_id = :field_id AND status = 1 ORDER BY sort_order",
                                    ['field_id' => $field['id']]
                                );
                                foreach ($fieldOptions as $option) {
                                    $options[] = [
                                        'option_value' => $option['option_value'],
                                        'option_label' => $option['option_label']
                                    ];
                                }
                            } catch (Exception $e) {
                                // 如果表不存在，忽略错误
                            }
                            
                            // 方法2：从数据库查询该字段的子字段作为选项（如果有级联关系）
                            if (empty($options)) {
                                try {
                                    $childFields = Db::query(
                                        "SELECT field_name, field_value FROM fields WHERE parent_field_id = :parent_id AND status = 1 ORDER BY sort_order",
                                        ['parent_id' => $field['id']]
                                    );
                                    foreach ($childFields as $childField) {
                                        if (isset($childField['field_value'])) {
                                            $options[] = [
                                                'option_value' => $childField['field_value'],
                                                'option_label' => $childField['field_name']
                                            ];
                                        }
                                    }
                                } catch (Exception $e) {
                                    // 忽略错误
                                }
                            }
                            
                            // 方法3：从同一维度下的其他字段获取选项（这些字段的 field_value 作为选项值，field_name 作为选项文本）
                            // 注意：只获取类型不是 text/textarea/date 的字段作为选项（因为这些是输入字段，不是选项）
                            if (empty($options)) {
                                foreach ($dimension['fields'] as $optionField) {
                                    // 如果字段有 field_value，且不是当前字段本身，且不是输入类型字段，则作为选项
                                    if (isset($optionField['field_value']) && 
                                        $optionField['id'] != $field['id'] &&
                                        !in_array($optionField['field_type'] ?? '', ['text', 'textarea', 'date', 'select'])) {
                                        $options[] = [
                                            'option_value' => $optionField['field_value'],
                                            'option_label' => $optionField['field_name']
                                        ];
                                    }
                                }
                            }
                            
                            // 方法4：如果还是没有选项，使用字段自己的 field_value（如果有）
                            if (empty($options) && isset($field['field_value'])) {
                                $options[] = [
                                    'option_value' => $field['field_value'],
                                    'option_label' => $field['field_name']
                                ];
                            }
                            
                            // 渲染下拉框
                            $html .= sprintf(
                                '<div style="%s" data-col="%d"><label style="display: block; margin-bottom: 4px; font-size: 14px;">%s%s</label><select name="%s" class="form-control form-control-sm" style="width: 100%%;" %s>',
                                $widthStyle,
                                $colOrder,
                                htmlspecialchars($fieldName),
                                $isRequired ? ' <span class="text-danger">*</span>' : '',
                                htmlspecialchars($fieldCode),
                                $requiredAttr
                            );
                            
                            $html .= '<option value="">请选择</option>';
                            foreach ($options as $option) {
                                $selected = ($fieldValue == $option['option_value']) ? 'selected' : '';
                                $html .= sprintf(
                                    '<option value="%s" %s>%s</option>',
                                    htmlspecialchars($option['option_value']),
                                    $selected,
                                    htmlspecialchars($option['option_label'])
                                );
                            }
                            $html .= '</select></div>';
                            break;
                            
                        case 'cascading_select':
                            // 级联下拉框：使用 renderCascadingSelectField 函数
                            $html .= sprintf(
                                '<div style="%s" data-col="%d"><label style="display: block; margin-bottom: 4px; font-size: 14px;">%s%s</label>%s</div>',
                                $widthStyle,
                                $colOrder,
                                htmlspecialchars($fieldName),
                                $isRequired ? ' <span class="text-danger">*</span>' : '',
                                renderCascadingSelectField($field, $fieldValue)
                            );
                            break;
                            
                        default:
                            // 如果字段类型不匹配，尝试使用维度类型
                            if ($dimensionType === 'text') {
                                // 使用iOS样式类 form-input（手机版会自动应用iOS样式）
                                $html .= sprintf(
                                    '<div style="%s" data-col="%d"><label class="form-label" style="display: block; margin-bottom: 6px; font-size: 12px; font-weight: 500; color: var(--text-secondary, #48484A); text-transform: uppercase; letter-spacing: 0.5px;">%s%s</label><input type="text" name="%s" value="%s" class="form-input" style="width: 100%%;" placeholder="%s" %s></div>',
                                    $widthStyle,
                                    $colOrder,
                                    htmlspecialchars($fieldName),
                                    $isRequired ? ' <span class="text-danger">*</span>' : '',
                                    htmlspecialchars($fieldCode),
                                    htmlspecialchars($fieldValue ?? ''),
                                    htmlspecialchars($placeholder),
                                    $requiredAttr
                                );
                            }
                            break;
                    }
                }
                
                $html .= '</div>';
            }
            break;
            
        default:
            $html .= '<div class="field-options"><p class="text-muted">不支持的字段类型: ' . htmlspecialchars($dimensionType) . '</p></div>';
            break;
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * 处理表单提交的字段值
 * @param string $module 模块名称
 * @param array $postData $_POST数据
 * @return array 处理后的字段值数组
 */
function processFieldValues($module, $postData) {
    $fields = getFieldsByModule($module);
    $result = [];

    if (empty($fields)) {
        return $result;
    }

    // 以维度为单位处理字段，这样 checkbox/radio 维度可以正确聚合
    $dimensionGroups = [];
    foreach ($fields as $field) {
        $dimCode = $field['dimension_code'] ?? $field['field_code'];
        if (!$dimCode) {
            continue;
        }

        if (!isset($dimensionGroups[$dimCode])) {
            $dimensionGroups[$dimCode] = [
                'type'   => $field['dimension_field_type'] ?? $field['field_type'] ?? 'text',
                'fields' => []
            ];
        }

        $dimensionGroups[$dimCode]['fields'][] = $field;
    }

    foreach ($dimensionGroups as $dimensionCode => $dimension) {
        $dimType = $dimension['type'] ?? 'text';
        $postKey = $dimensionCode;

        switch ($dimType) {
            case 'checkbox':
                $values = isset($postData[$postKey]) ? (array)$postData[$postKey] : [];
                $customValue = trim($postData[$postKey . '_custom'] ?? '');
                if ($customValue !== '') {
                    $values[] = $customValue;
                }

                // 过滤空字符串，避免出现连续分隔符
                $values = array_values(array_filter($values, function($val) {
                    return trim($val) !== '';
                }));

                $result[$dimensionCode] = implode('、', $values);
                break;

            case 'radio':
            case 'select':
                $value = trim($postData[$postKey] ?? '');
                $customValue = trim($postData[$postKey . '_custom'] ?? '');
                $result[$dimensionCode] = $customValue !== '' ? $customValue : $value;
                break;

            case 'textarea':
            case 'text':
            case 'date':
                // 对于 text/textarea/date 类型，每个字段都是独立的，使用字段代码作为 key
                foreach ($dimension['fields'] as $field) {
                    $fieldCode = $field['field_code'];
                    $fieldType = $field['field_type'] ?? $dimType;
                    $fieldValue = trim($postData[$fieldCode] ?? '');
                    
                    // 对于这些类型，直接使用字段代码作为 key
                    $result[$fieldCode] = $fieldValue;
                }
                break;
                
            default:
                // 默认情况：使用维度代码
                $result[$dimensionCode] = trim($postData[$postKey] ?? '');
                break;
        }
    }

    return $result;
}

/**
 * 保存维度字段值到数据库
 * @param string $targetType 目标类型（如 first_contact）
 * @param int $targetId 目标对象ID（如 first_contact 表的 id）
 * @param array $fieldValues 字段值数组，键为维度代码，值为字段值
 * @param int $timestamp 时间戳
 */
function saveDimensionFieldValues($targetType, $targetId, $fieldValues, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    
    if (empty($fieldValues)) {
        return;
    }
    
    // 先尝试按维度代码查找维度ID
    $dimensionCodes = array_keys($fieldValues);
    if (empty($dimensionCodes)) {
        return;
    }
    
    // 查询维度ID（按维度代码）
    $placeholders = implode(',', array_fill(0, count($dimensionCodes), '?'));
    $dimensions = Db::query(
        "SELECT id, dimension_code FROM dimensions WHERE dimension_code IN ($placeholders)",
        $dimensionCodes
    );
    
    // 构建维度代码到ID的映射
    $dimensionMap = [];
    foreach ($dimensions as $dim) {
        $dimensionMap[$dim['dimension_code']] = $dim['id'];
    }
    
    // 对于按字段代码保存的值，需要查找字段所属的维度
    $fieldCodes = [];
    $fieldToDimensionMap = [];
    foreach ($fieldValues as $key => $value) {
        if (!isset($dimensionMap[$key])) {
            // 可能是字段代码，记录下来
            $fieldCodes[] = $key;
        }
    }
    
    // 如果有关键字是字段代码，查询字段所属的维度
    if (!empty($fieldCodes)) {
        $fieldPlaceholders = implode(',', array_fill(0, count($fieldCodes), '?'));
        $fields = Db::query(
            "SELECT f.field_code, d.id as dimension_id, d.dimension_code 
             FROM fields f 
             INNER JOIN dimensions d ON f.dimension_id = d.id 
             WHERE f.field_code IN ($fieldPlaceholders)",
            $fieldCodes
        );
        
        foreach ($fields as $field) {
            $fieldToDimensionMap[$field['field_code']] = [
                'dimension_id' => $field['dimension_id'],
                'dimension_code' => $field['dimension_code']
            ];
        }
    }
    
    // 按维度分组处理字段值（对于 text/textarea/date 类型，一个维度可能有多个字段）
    $dimensionValueMap = [];
    foreach ($fieldValues as $key => $value) {
        $dimensionId = null;
        $dimensionCode = null;
        
        if (isset($dimensionMap[$key])) {
            // 是维度代码
            $dimensionId = $dimensionMap[$key];
            $dimensionCode = $key;
        } elseif (isset($fieldToDimensionMap[$key])) {
            // 是字段代码
            $dimensionId = $fieldToDimensionMap[$key]['dimension_id'];
            $dimensionCode = $fieldToDimensionMap[$key]['dimension_code'];
        } else {
            continue; // 跳过不存在的维度或字段
        }
        
        // 对于 text/textarea/date 类型，一个维度可能有多个字段，需要合并
        if (!isset($dimensionValueMap[$dimensionId])) {
            $dimensionValueMap[$dimensionId] = [
                'dimension_code' => $dimensionCode,
                'values' => []
            ];
        }
        
        // 如果 key 是字段代码，保存为 {field_code: value} 格式
        if (isset($fieldToDimensionMap[$key])) {
            $dimensionValueMap[$dimensionId]['values'][$key] = trim($value);
        } else {
            // 如果 key 是维度代码，直接保存值
            $dimensionValueMap[$dimensionId]['values'] = trim($value);
        }
    }
    
    // 保存或更新每个维度的值
    foreach ($dimensionValueMap as $dimensionId => $data) {
        $dimensionCode = $data['dimension_code'];
        $values = $data['values'];
        
        // 如果 values 是数组（多个字段），转换为 JSON
        // 如果 values 是字符串（单个值），直接使用
        if (is_array($values) && count($values) > 1) {
            $value = json_encode($values, JSON_UNESCAPED_UNICODE);
        } elseif (is_array($values) && count($values) === 1) {
            $value = reset($values);
        } else {
            $value = $values;
        }
        
        $value = trim($value);
        
        // 检查是否已存在
        $existing = Db::queryOne(
            'SELECT id FROM dimension_field_values WHERE dimension_id = ? AND target_type = ? AND target_id = ?',
            [$dimensionId, $targetType, $targetId]
        );
        
        if ($existing) {
            // 更新
            Db::execute(
                'UPDATE dimension_field_values SET dimension_value = ?, update_time = ? WHERE id = ?',
                [$value, $timestamp, $existing['id']]
            );
        } else {
            // 插入
            Db::execute(
                'INSERT INTO dimension_field_values (dimension_id, target_type, target_id, dimension_value, create_time, update_time) VALUES (?, ?, ?, ?, ?, ?)',
                [$dimensionId, $targetType, $targetId, $value, $timestamp, $timestamp]
            );
        }
    }
}

/**
 * 加载维度字段值
 * @param string $targetType 目标类型（如 first_contact）
 * @param int $targetId 目标对象ID（如 first_contact 表的 id）
 * @return array 字段值数组，键为维度代码，值为字段值
 */
function loadDimensionFieldValues($targetType, $targetId) {
    if ($targetId <= 0) {
        return [];
    }
    
    // 查询维度字段值
    $sql = "SELECT d.dimension_code, d.id as dimension_id, dfv.dimension_value
            FROM dimension_field_values dfv
            INNER JOIN dimensions d ON dfv.dimension_id = d.id
            WHERE dfv.target_type = ? AND dfv.target_id = ?";
    
    $results = Db::query($sql, [$targetType, $targetId]);
    
    // 转换为以维度代码或字段代码为键的数组
    $values = [];
    foreach ($results as $row) {
        $dimensionCode = $row['dimension_code'];
        $dimensionValue = $row['dimension_value'];
        
        // 尝试解析 JSON（如果是多个字段的值，会保存为 JSON）
        $decoded = json_decode($dimensionValue, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // 是 JSON 格式，说明是多个字段的值，按字段代码返回
            foreach ($decoded as $fieldCode => $fieldValue) {
                $values[$fieldCode] = $fieldValue;
            }
        } else {
            // 不是 JSON，按维度代码返回（兼容旧数据）
            $values[$dimensionCode] = $dimensionValue;
        }
    }
    
    return $values;
}
