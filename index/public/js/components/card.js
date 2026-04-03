/**
 * 卡片组件
 * @description 可复用的卡片UI组件
 */

/**
 * 渲染信息卡片
 * @param {Object} options
 * @param {string} options.title - 卡片标题
 * @param {string} options.icon - 图标类名（如 bi-person）
 * @param {Array} options.items - 信息项数组 [{label, value}]
 * @param {string} options.theme - 主题：'default'|'portal'
 * @returns {string}
 */
function renderInfoCard(options) {
    const { title, icon = '', items = [], theme = 'default' } = options;
    
    const themes = {
        default: {
            bg: 'white',
            border: '#f1f5f9',
            shadow: '0 1px 3px rgba(0,0,0,0.05)',
            radius: '16px',
            titleColor: '#1e293b',
            labelColor: '#94a3b8',
            valueColor: '#1e293b',
            iconColor: '#6366f1'
        },
        portal: {
            bg: 'rgba(255,255,255,0.85)',
            border: '#e2e8f0',
            shadow: '0 4px 6px rgba(0,0,0,0.07)',
            radius: '16px',
            titleColor: '#1e293b',
            labelColor: '#94a3b8',
            valueColor: '#1e293b',
            iconColor: '#6366f1'
        }
    };
    
    const t = themes[theme] || themes.default;
    
    const cardStyle = `
        background: ${t.bg};
        border-radius: ${t.radius};
        padding: 24px;
        box-shadow: ${t.shadow};
        border: 1px solid ${t.border};
        margin-bottom: 20px;
    `.replace(/\s+/g, ' ').trim();
    
    const titleStyle = `
        font-size: 15px;
        color: ${t.titleColor};
        margin-bottom: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    `.replace(/\s+/g, ' ').trim();
    
    const iconStyle = `color: ${t.iconColor};`;
    
    let itemsHtml = items.map(item => `
        <div style="margin-bottom: 16px;">
            <div style="font-size: 12px; color: ${t.labelColor}; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px;">${item.label}</div>
            <div style="font-size: 15px; color: ${t.valueColor}; font-weight: 500;">${item.value || '-'}</div>
        </div>
    `).join('');
    
    return `
        <div style="${cardStyle}">
            <div style="${titleStyle}">
                ${icon ? `<i class="${icon}" style="${iconStyle}"></i>` : ''}
                ${title}
            </div>
            ${itemsHtml}
        </div>
    `;
}

/**
 * 渲染统计卡片
 * @param {Object} options
 * @param {string} options.title - 标题
 * @param {string|number} options.value - 数值
 * @param {string} options.icon - 图标
 * @param {string} options.color - 主题色
 * @param {string} options.trend - 趋势：'up'|'down'|null
 * @param {string} options.trendValue - 趋势值
 * @returns {string}
 */
function renderStatCard(options) {
    const { 
        title, 
        value, 
        icon = '', 
        color = '#6366f1',
        trend = null,
        trendValue = ''
    } = options;
    
    const cardStyle = `
        background: white;
        border-radius: 16px;
        padding: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #f1f5f9;
    `.replace(/\s+/g, ' ').trim();
    
    const iconWrapStyle = `
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: ${color}15;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
    `.replace(/\s+/g, ' ').trim();
    
    const trendHtml = trend ? `
        <span style="
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: ${trend === 'up' ? '#10b981' : '#ef4444'};
            margin-left: 8px;
        ">
            <i class="bi bi-arrow-${trend}"></i>
            ${trendValue}
        </span>
    ` : '';
    
    return `
        <div style="${cardStyle}">
            <div style="${iconWrapStyle}">
                <i class="${icon}" style="font-size: 24px; color: ${color};"></i>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-bottom: 4px;">${title}</div>
            <div style="font-size: 28px; font-weight: 700; color: #1e293b;">
                ${value}${trendHtml}
            </div>
        </div>
    `;
}

/**
 * 渲染列表卡片
 * @param {Object} options
 * @param {string} options.title - 标题
 * @param {Array} options.items - 列表项
 * @param {Function} options.renderItem - 渲染单项的函数
 * @param {string} options.emptyText - 空状态文本
 * @returns {string}
 */
function renderListCard(options) {
    const { 
        title, 
        items = [], 
        renderItem = (item) => `<div>${item}</div>`,
        emptyText = '暂无数据'
    } = options;
    
    const cardStyle = `
        background: white;
        border-radius: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #f1f5f9;
        overflow: hidden;
    `.replace(/\s+/g, ' ').trim();
    
    const headerStyle = `
        padding: 16px 24px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 15px;
        font-weight: 600;
        color: #1e293b;
    `.replace(/\s+/g, ' ').trim();
    
    let contentHtml;
    if (items.length === 0) {
        contentHtml = `
            <div style="padding: 40px 24px; text-align: center; color: #94a3b8;">
                ${emptyText}
            </div>
        `;
    } else {
        contentHtml = items.map((item, index) => `
            <div style="padding: 16px 24px; border-bottom: ${index < items.length - 1 ? '1px solid #f1f5f9' : 'none'};">
                ${renderItem(item)}
            </div>
        `).join('');
    }
    
    return `
        <div style="${cardStyle}">
            <div style="${headerStyle}">${title}</div>
            ${contentHtml}
        </div>
    `;
}

// 导出
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { renderInfoCard, renderStatCard, renderListCard };
}
