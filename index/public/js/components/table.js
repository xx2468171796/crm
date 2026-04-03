/**
 * 表格组件
 * @description 可复用的数据表格组件
 */

/**
 * 渲染数据表格
 * @param {Object} options
 * @param {Array} options.columns - 列定义 [{key, title, width, render}]
 * @param {Array} options.data - 数据数组
 * @param {string} options.emptyText - 空状态文本
 * @param {boolean} options.striped - 是否斑马纹
 * @param {boolean} options.hover - 是否hover效果
 * @param {string} options.size - 尺寸：'sm'|'md'|'lg'
 * @returns {string}
 */
function renderTable(options) {
    const {
        columns = [],
        data = [],
        emptyText = '暂无数据',
        striped = true,
        hover = true,
        size = 'md'
    } = options;

    const sizes = {
        sm: { padding: '8px 12px', fontSize: '13px' },
        md: { padding: '12px 16px', fontSize: '14px' },
        lg: { padding: '16px 20px', fontSize: '15px' }
    };
    const s = sizes[size] || sizes.md;

    const tableStyle = `
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    `.replace(/\s+/g, ' ').trim();

    const thStyle = `
        padding: ${s.padding};
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: #64748b;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    `.replace(/\s+/g, ' ').trim();

    const tdStyle = `
        padding: ${s.padding};
        font-size: ${s.fontSize};
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
    `.replace(/\s+/g, ' ').trim();

    // 表头
    let headerHtml = columns.map(col => `
        <th style="${thStyle}${col.width ? ` width: ${col.width};` : ''}">${col.title}</th>
    `).join('');

    // 表体
    let bodyHtml;
    if (data.length === 0) {
        bodyHtml = `
            <tr>
                <td colspan="${columns.length}" style="padding: 40px; text-align: center; color: #94a3b8;">
                    ${emptyText}
                </td>
            </tr>
        `;
    } else {
        bodyHtml = data.map((row, rowIndex) => {
            const rowStyle = striped && rowIndex % 2 === 1 ? 'background: #f8fafc;' : '';
            const hoverStyle = hover ? 'transition: background 0.2s;' : '';
            
            const cells = columns.map(col => {
                const value = row[col.key];
                const content = col.render ? col.render(value, row, rowIndex) : (value ?? '-');
                return `<td style="${tdStyle}">${content}</td>`;
            }).join('');
            
            return `<tr style="${rowStyle}${hoverStyle}" ${hover ? 'onmouseover="this.style.background=\'#f1f5f9\'" onmouseout="this.style.background=\'' + (striped && rowIndex % 2 === 1 ? '#f8fafc' : 'white') + '\'"' : ''}>${cells}</tr>`;
        }).join('');
    }

    return `
        <table style="${tableStyle}">
            <thead><tr>${headerHtml}</tr></thead>
            <tbody>${bodyHtml}</tbody>
        </table>
    `;
}

/**
 * 渲染简单列表
 * @param {Object} options
 * @param {Array} options.items - 列表项
 * @param {Function} options.renderItem - 渲染函数
 * @param {string} options.emptyText - 空状态
 * @returns {string}
 */
function renderSimpleList(options) {
    const { items = [], renderItem, emptyText = '暂无数据' } = options;

    if (items.length === 0) {
        return `<div style="padding: 40px; text-align: center; color: #94a3b8;">${emptyText}</div>`;
    }

    return items.map((item, index) => `
        <div style="padding: 12px 0; border-bottom: ${index < items.length - 1 ? '1px solid #f1f5f9' : 'none'};">
            ${renderItem ? renderItem(item, index) : item}
        </div>
    `).join('');
}

/**
 * 渲染分页器
 * @param {Object} options
 * @param {number} options.current - 当前页
 * @param {number} options.total - 总页数
 * @param {Function} options.onChange - 页码变更回调
 * @returns {string}
 */
function renderPagination(options) {
    const { current = 1, total = 1, onChange = null } = options;

    if (total <= 1) return '';

    const btnStyle = `
        padding: 8px 14px;
        border: 1px solid #e2e8f0;
        background: white;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
    `.replace(/\s+/g, ' ').trim();

    const activeBtnStyle = `
        ${btnStyle}
        background: #6366f1;
        border-color: #6366f1;
        color: white;
    `.replace(/\s+/g, ' ').trim();

    const disabledBtnStyle = `
        ${btnStyle}
        opacity: 0.5;
        cursor: not-allowed;
    `.replace(/\s+/g, ' ').trim();

    let pages = [];
    const maxVisible = 5;
    let start = Math.max(1, current - Math.floor(maxVisible / 2));
    let end = Math.min(total, start + maxVisible - 1);
    
    if (end - start + 1 < maxVisible) {
        start = Math.max(1, end - maxVisible + 1);
    }

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    const onClickHandler = onChange ? `onclick="(${onChange.toString()})(this.dataset.page)"` : '';

    let html = `<div style="display: flex; gap: 8px; justify-content: center; margin-top: 20px;">`;
    
    // 上一页
    html += `<button style="${current === 1 ? disabledBtnStyle : btnStyle}" ${current > 1 ? `data-page="${current - 1}" ${onClickHandler}` : 'disabled'}>上一页</button>`;
    
    // 页码
    pages.forEach(page => {
        html += `<button style="${page === current ? activeBtnStyle : btnStyle}" data-page="${page}" ${onClickHandler}>${page}</button>`;
    });
    
    // 下一页
    html += `<button style="${current === total ? disabledBtnStyle : btnStyle}" ${current < total ? `data-page="${current + 1}" ${onClickHandler}` : 'disabled'}>下一页</button>`;
    
    html += `</div>`;
    
    return html;
}

// 导出
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { renderTable, renderSimpleList, renderPagination };
}
