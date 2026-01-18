/**
 * 模态框组件
 * @description 可复用的模态框UI组件
 */

/**
 * 显示通用模态框
 * @param {Object} options
 * @param {string} options.title - 标题
 * @param {string} options.content - 内容HTML
 * @param {string} options.size - 尺寸：'sm'|'md'|'lg'|'xl'
 * @param {Array} options.buttons - 按钮配置 [{text, type, onClick}]
 * @param {boolean} options.closable - 是否可关闭
 * @param {Function} options.onClose - 关闭回调
 * @returns {Object} 模态框控制对象
 */
function showModal(options) {
    const {
        title = '',
        content = '',
        size = 'md',
        buttons = [],
        closable = true,
        onClose = null
    } = options;

    const sizes = {
        sm: '400px',
        md: '560px',
        lg: '720px',
        xl: '960px'
    };
    const maxWidth = sizes[size] || sizes.md;

    // 创建遮罩层
    const overlay = document.createElement('div');
    overlay.className = 'custom-modal-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        transition: opacity 0.2s;
    `;

    // 创建模态框容器
    const modal = document.createElement('div');
    modal.className = 'custom-modal';
    modal.style.cssText = `
        background: white;
        border-radius: 16px;
        max-width: ${maxWidth};
        width: 90%;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        transform: scale(0.95);
        transition: transform 0.2s;
    `;

    // 头部
    let headerHtml = '';
    if (title) {
        headerHtml = `
            <div style="
                padding: 20px 24px;
                border-bottom: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                justify-content: space-between;
            ">
                <h3 style="margin: 0; font-size: 18px; font-weight: 600; color: #1e293b;">${title}</h3>
                ${closable ? '<button class="modal-close-btn" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #94a3b8; line-height: 1;">&times;</button>' : ''}
            </div>
        `;
    }

    // 内容区
    const contentHtml = `
        <div style="padding: 24px; max-height: calc(90vh - 180px); overflow-y: auto;">
            ${content}
        </div>
    `;

    // 底部按钮
    let footerHtml = '';
    if (buttons.length > 0) {
        const btnHtml = buttons.map(btn => {
            const btnStyle = btn.type === 'primary' 
                ? 'background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; border: none;'
                : btn.type === 'danger'
                ? 'background: #ef4444; color: white; border: none;'
                : 'background: white; color: #64748b; border: 1px solid #e2e8f0;';
            
            return `<button class="modal-btn" data-action="${btn.action || ''}" style="
                padding: 10px 20px;
                border-radius: 10px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
                ${btnStyle}
            ">${btn.text}</button>`;
        }).join('');

        footerHtml = `
            <div style="
                padding: 16px 24px;
                border-top: 1px solid #e2e8f0;
                display: flex;
                justify-content: flex-end;
                gap: 12px;
            ">
                ${btnHtml}
            </div>
        `;
    }

    modal.innerHTML = headerHtml + contentHtml + footerHtml;
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    // 关闭函数
    const close = () => {
        overlay.style.opacity = '0';
        modal.style.transform = 'scale(0.95)';
        setTimeout(() => {
            overlay.remove();
            if (onClose) onClose();
        }, 200);
    };

    // 绑定事件
    if (closable) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) close();
        });
        
        const closeBtn = modal.querySelector('.modal-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', close);
    }

    // 按钮事件
    buttons.forEach((btn, index) => {
        const btnEl = modal.querySelectorAll('.modal-btn')[index];
        if (btnEl && btn.onClick) {
            btnEl.addEventListener('click', () => btn.onClick(close));
        }
    });

    // 显示动画
    requestAnimationFrame(() => {
        overlay.style.opacity = '1';
        modal.style.transform = 'scale(1)';
    });

    return { close, modal, overlay };
}

/**
 * 显示确认框
 * @param {string} title - 标题
 * @param {string} message - 消息
 * @param {Function} onConfirm - 确认回调
 * @param {Function} onCancel - 取消回调
 */
function showConfirm(title, message, onConfirm, onCancel) {
    return showModal({
        title: title,
        content: `<p style="margin: 0; color: #64748b; font-size: 15px;">${message}</p>`,
        size: 'sm',
        buttons: [
            { text: '取消', type: 'secondary', onClick: (close) => { close(); if (onCancel) onCancel(); } },
            { text: '确认', type: 'primary', onClick: (close) => { close(); if (onConfirm) onConfirm(); } }
        ]
    });
}

/**
 * 显示提示框
 * @param {string} message - 消息
 * @param {string} type - 类型：'success'|'error'|'warning'|'info'
 * @param {number} duration - 自动关闭时间（毫秒），0表示不自动关闭
 */
function showToast(message, type = 'info', duration = 3000) {
    const colors = {
        success: { bg: '#10b981', icon: 'bi-check-circle' },
        error: { bg: '#ef4444', icon: 'bi-x-circle' },
        warning: { bg: '#f59e0b', icon: 'bi-exclamation-circle' },
        info: { bg: '#3b82f6', icon: 'bi-info-circle' }
    };
    const c = colors[type] || colors.info;

    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${c.bg};
        color: white;
        padding: 14px 20px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 10000;
        transform: translateX(120%);
        transition: transform 0.3s ease;
        font-size: 14px;
        max-width: 400px;
    `;
    toast.innerHTML = `<i class="${c.icon}"></i><span>${message}</span>`;
    document.body.appendChild(toast);

    // 显示
    requestAnimationFrame(() => {
        toast.style.transform = 'translateX(0)';
    });

    // 自动关闭
    if (duration > 0) {
        setTimeout(() => {
            toast.style.transform = 'translateX(120%)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    return toast;
}

/**
 * 显示加载中
 * @param {string} message - 加载提示
 * @returns {Object} 包含close方法
 */
function showLoading(message = '加载中...') {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    overlay.innerHTML = `
        <div style="
            width: 48px;
            height: 48px;
            border: 3px solid #e2e8f0;
            border-top-color: #6366f1;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        "></div>
        <div style="margin-top: 16px; color: #64748b; font-size: 14px;">${message}</div>
        <style>
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
        </style>
    `;
    document.body.appendChild(overlay);

    return {
        close: () => overlay.remove(),
        updateMessage: (msg) => overlay.querySelector('div:last-of-type').textContent = msg
    };
}

// 导出
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { showModal, showConfirm, showToast, showLoading };
}
