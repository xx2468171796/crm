/**
 * 客户自定义筛选字段组件
 * 提供可复用的筛选字段加载、渲染和交互功能
 */
const CustomerFilterFields = (function() {
    let fieldsData = [];
    let isLoaded = false;

    // XSS转义函数
    function escapeHtml(text) {
        if (text == null) return '';
        const map = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * 加载筛选字段定义
     * @returns {Promise<Array>} 字段列表
     */
    async function loadFields() {
        if (isLoaded && fieldsData.length > 0) {
            return fieldsData;
        }
        
        try {
            const response = await fetch('/api/customer_filter_fields.php?action=list');
            const result = await response.json();
            if (result.success) {
                fieldsData = result.data;
                isLoaded = true;
            }
        } catch (error) {
            console.error('[FILTER_FIELDS] 加载字段失败:', error);
        }
        
        return fieldsData;
    }

    /**
     * 渲染筛选下拉框到容器
     * @param {string} containerId 容器元素ID
     * @param {Object} options 配置选项
     * @param {Object} options.selectedValues 当前选中的值 {fieldId: optionId}
     * @param {Function} options.onChange 值变化回调 (fieldId, optionId, field, option) => void
     * @param {boolean} options.showLabel 是否显示字段标签
     * @param {string} options.size 尺寸: 'sm' | 'md'
     */
    async function render(containerId, options = {}) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error('[FILTER_FIELDS] 容器不存在:', containerId);
            return;
        }

        const fields = await loadFields();
        if (fields.length === 0) {
            container.innerHTML = '';
            return;
        }

        const {
            selectedValues = {},
            onChange = null,
            showLabel = true,
            size = 'sm'
        } = options;

        let html = '';
        fields.forEach(field => {
            const selectedOptionId = selectedValues[field.id] || '';
            
            html += `<div class="filter-field-wrapper" style="display: inline-flex; align-items: center; gap: 4px; margin-right: 8px;">`;
            
            if (showLabel) {
                html += `<label class="filter-field-label" style="font-size: 12px; color: #64748b; white-space: nowrap;">${escapeHtml(field.field_label)}:</label>`;
            }
            
            html += `<select class="form-select form-select-${size} filter-field-select" 
                        name="cf_${field.id}"
                        data-field-id="${field.id}" 
                        data-field-name="${escapeHtml(field.field_name)}"
                        style="min-width: 90px; font-size: 13px;">
                <option value="">全部</option>`;
            
            field.options.forEach(opt => {
                const selected = selectedOptionId == opt.id ? 'selected' : '';
                html += `<option value="${opt.id}" data-color="${escapeHtml(opt.color)}" ${selected}>${escapeHtml(opt.option_label)}</option>`;
            });
            
            html += `</select></div>`;
        });

        container.innerHTML = html;

        // 绑定change事件
        if (onChange) {
            container.querySelectorAll('.filter-field-select').forEach(select => {
                select.addEventListener('change', function() {
                    const fieldId = parseInt(this.dataset.fieldId);
                    const optionId = this.value ? parseInt(this.value) : null;
                    const field = fields.find(f => f.id === fieldId);
                    const option = optionId ? field?.options.find(o => o.id === optionId) : null;
                    onChange(fieldId, optionId, field, option);
                });
            });
        }
    }

    /**
     * 获取当前选中的筛选值
     * @param {string} containerId 容器元素ID
     * @returns {Object} {fieldId: optionId}
     */
    function getSelectedValues(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return {};

        const values = {};
        container.querySelectorAll('.filter-field-select').forEach(select => {
            const fieldId = select.dataset.fieldId;
            if (select.value) {
                values[fieldId] = parseInt(select.value);
            }
        });
        return values;
    }

    /**
     * 构建URL查询参数
     * @param {Object} values {fieldId: optionId}
     * @returns {string} 查询字符串 ff[1]=2&ff[3]=4
     */
    function buildQueryParams(values) {
        const params = new URLSearchParams();
        Object.entries(values).forEach(([fieldId, optionId]) => {
            if (optionId) {
                params.append(`ff[${fieldId}]`, optionId);
            }
        });
        return params.toString();
    }

    /**
     * 从URL解析筛选值
     * @returns {Object} {fieldId: optionId}
     */
    function parseUrlParams() {
        const params = new URLSearchParams(window.location.search);
        const values = {};
        params.forEach((value, key) => {
            // 支持 ff[id] 和 cf_id 两种格式
            let match = key.match(/^ff\[(\d+)\]$/);
            if (match) {
                values[match[1]] = parseInt(value);
                return;
            }
            match = key.match(/^cf_(\d+)$/);
            if (match && value) {
                values[match[1]] = parseInt(value);
            }
        });
        return values;
    }

    return {
        loadFields,
        render,
        getSelectedValues,
        buildQueryParams,
        parseUrlParams,
        get fields() { return fieldsData; }
    };
})();
