/**
 * 表格列显隐控件
 * 用法:
 * initColumnToggle({
 *   tableId: 'myTable',
 *   storageKey: 'my_table_columns',
 *   columns: [
 *     { index: 0, name: '列名', default: true },
 *     ...
 *   ],
 *   buttonContainer: '#columnToggleContainer'
 * });
 */
function initColumnToggle(config) {
    const table = document.getElementById(config.tableId);
    if (!table) return;

    const storageKey = config.storageKey || ('col_toggle_' + config.tableId);
    const columns = config.columns || [];
    const container = document.querySelector(config.buttonContainer);
    if (!container) return;

    // 从localStorage读取保存的设置
    let saved = null;
    try {
        const raw = localStorage.getItem(storageKey);
        if (raw) saved = JSON.parse(raw);
    } catch (e) {}

    // 初始化列状态
    const colState = {};
    columns.forEach(function(col) {
        if (saved && Array.isArray(saved)) {
            colState[col.index] = saved.includes(col.index);
        } else {
            colState[col.index] = col.default !== false;
        }
    });

    // 创建下拉按钮
    const wrapper = document.createElement('div');
    wrapper.className = 'dropdown d-inline-block';
    wrapper.innerHTML = '<button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">显示列</button>'
        + '<div class="dropdown-menu p-2" style="min-width:180px;max-height:300px;overflow:auto;"></div>';
    
    const menu = wrapper.querySelector('.dropdown-menu');
    columns.forEach(function(col) {
        const label = document.createElement('label');
        label.className = 'd-flex align-items-center gap-2 py-1 px-2';
        label.style.cursor = 'pointer';
        label.innerHTML = '<input type="checkbox" class="form-check-input m-0" data-col-idx="' + col.index + '"' + (colState[col.index] ? ' checked' : '') + '>'
            + '<span>' + (col.name || ('列' + col.index)) + '</span>';
        menu.appendChild(label);
    });

    container.appendChild(wrapper);

    // 应用列显隐
    function applyColumnVisibility() {
        const rows = table.querySelectorAll('tr');
        rows.forEach(function(row) {
            const cells = row.querySelectorAll('th, td');
            columns.forEach(function(col) {
                const cell = cells[col.index];
                if (cell) {
                    cell.style.display = colState[col.index] ? '' : 'none';
                }
            });
        });
    }

    // 保存设置
    function saveState() {
        const visible = [];
        columns.forEach(function(col) {
            if (colState[col.index]) visible.push(col.index);
        });
        try {
            localStorage.setItem(storageKey, JSON.stringify(visible));
        } catch (e) {}
    }

    // 绑定checkbox事件
    menu.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            const idx = parseInt(this.getAttribute('data-col-idx'), 10);
            colState[idx] = this.checked;
            applyColumnVisibility();
            saveState();
        });
    });

    // 初始应用
    applyColumnVisibility();
}
