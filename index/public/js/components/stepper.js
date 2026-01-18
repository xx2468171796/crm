/**
 * 状态步骤条组件
 * @description 可复用的水平步骤条，支持点击切换状态
 */

/**
 * 渲染状态步骤条
 * @param {Object} options - 配置选项
 * @param {string} options.currentStatus - 当前状态
 * @param {Array<string>} options.statuses - 状态列表
 * @param {Function} options.onStatusChange - 状态变更回调
 * @param {boolean} options.clickable - 是否可点击
 * @param {string} options.theme - 主题：'default'|'portal'
 * @returns {string} HTML字符串
 */
function renderStepper(options) {
    const {
        currentStatus,
        statuses = PROJECT_STATUSES,
        onStatusChange = null,
        clickable = true,
        theme = 'default'
    } = options;

    let currentIndex = statuses.indexOf(currentStatus);
    if (currentIndex === -1) currentIndex = 0;
    
    const progressWidth = currentIndex > 0 
        ? ((currentIndex) / (statuses.length - 1) * 100) 
        : 0;

    // 样式配置
    const themes = {
        default: {
            primary: '#6366f1',
            primaryLight: '#818cf8',
            border: '#e2e8f0',
            textMuted: '#94a3b8',
            textDark: '#1e293b',
            bg: 'white'
        },
        portal: {
            primary: '#6366f1',
            primaryLight: '#8b5cf6',
            border: '#e2e8f0',
            textMuted: '#94a3b8',
            textDark: '#1e293b',
            bg: 'white'
        }
    };

    const t = themes[theme] || themes.default;

    // 容器样式
    const containerStyle = `
        display: flex;
        justify-content: space-between;
        position: relative;
        padding: 10px 0;
    `.replace(/\s+/g, ' ').trim();

    // 轨道样式
    const trackStyle = `
        position: absolute;
        top: 28px;
        left: 8%;
        right: 8%;
        height: 3px;
        background: ${t.border};
        border-radius: 2px;
        z-index: 1;
    `.replace(/\s+/g, ' ').trim();

    // 进度条样式
    const progressStyle = `
        position: absolute;
        top: 28px;
        left: 8%;
        height: 3px;
        background: linear-gradient(135deg, ${t.primary} 0%, ${t.primaryLight} 100%);
        border-radius: 2px;
        z-index: 2;
        width: ${progressWidth * 0.84}%;
        transition: width 0.5s ease;
    `.replace(/\s+/g, ' ').trim();

    // 构建步骤HTML
    let stepsHtml = '';
    statuses.forEach((status, index) => {
        const isCompleted = index < currentIndex;
        const isActive = index === currentIndex;

        // 圆圈样式
        let circleStyle = `
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
        `.replace(/\s+/g, ' ').trim();

        // 标签样式
        let labelStyle = `
            margin-top: 8px;
            font-size: 11px;
            font-weight: 500;
            text-align: center;
            max-width: 60px;
        `.replace(/\s+/g, ' ').trim();

        if (isCompleted) {
            circleStyle += ` background: linear-gradient(135deg, ${t.primary} 0%, ${t.primaryLight} 100%); border: 3px solid ${t.primary}; color: white;`;
            labelStyle += ` color: ${t.textDark};`;
        } else if (isActive) {
            circleStyle += ` background: ${t.bg}; border: 3px solid ${t.primary}; color: ${t.primary}; box-shadow: 0 0 0 4px rgba(99,102,241,0.15);`;
            labelStyle += ` color: ${t.textDark};`;
        } else {
            circleStyle += ` background: ${t.bg}; border: 3px solid ${t.border}; color: ${t.textMuted};`;
            labelStyle += ` color: ${t.textMuted};`;
        }

        // 步骤容器样式
        const stepStyle = `
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 3;
            flex: 1;
            ${clickable ? 'cursor: pointer;' : ''}
        `.replace(/\s+/g, ' ').trim();

        const checkIcon = isCompleted ? '✓' : (index + 1);
        const clickHandler = clickable && onStatusChange 
            ? `onclick="(${onStatusChange.toString()})('${status}')"` 
            : '';
        const title = clickable ? `title="点击切换到此状态"` : '';

        stepsHtml += `
            <div style="${stepStyle}" ${clickHandler} ${title}>
                <div style="${circleStyle}">${checkIcon}</div>
                <div style="${labelStyle}">${status}</div>
            </div>
        `;
    });

    return `
        <div style="${containerStyle}">
            <div style="${trackStyle}"></div>
            <div style="${progressStyle}"></div>
            ${stepsHtml}
        </div>
    `;
}

/**
 * 创建步骤条DOM元素
 * @param {Object} options - 同renderStepper
 * @returns {HTMLElement}
 */
function createStepper(options) {
    const container = document.createElement('div');
    container.className = 'stepper-container';
    container.innerHTML = renderStepper(options);
    return container;
}

/**
 * 更新步骤条状态
 * @param {HTMLElement} container - 步骤条容器
 * @param {string} newStatus - 新状态
 * @param {Array<string>} statuses - 状态列表
 */
function updateStepper(container, newStatus, statuses = PROJECT_STATUSES) {
    if (!container) return;
    
    container.innerHTML = renderStepper({
        currentStatus: newStatus,
        statuses: statuses,
        clickable: true
    });
}

// 导出
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { renderStepper, createStepper, updateStepper };
}
