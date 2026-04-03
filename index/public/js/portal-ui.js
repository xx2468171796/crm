/**
 * 客户门户 UI 组件库
 * redesign-customer-portal
 */

const PortalUI = (function() {
    'use strict';

    // ========== Toast 通知组件 ==========
    const Toast = {
        container: null,
        
        init() {
            if (this.container) return;
            this.container = document.createElement('div');
            this.container.className = 'portal-toast-container';
            this.container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 360px;
            `;
            document.body.appendChild(this.container);
        },
        
        show(message, type = 'info', duration = 3000) {
            this.init();
            
            const toast = document.createElement('div');
            toast.className = `portal-toast portal-toast-${type}`;
            toast.style.cssText = `
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 14px 18px;
                background: var(--portal-card-solid, #fff);
                border-radius: var(--portal-radius, 12px);
                box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                animation: slideInRight 0.3s ease;
                cursor: pointer;
            `;
            
            const iconMap = {
                success: { icon: '✓', color: 'var(--portal-success, #10b981)' },
                error: { icon: '✗', color: 'var(--portal-error, #ef4444)' },
                warning: { icon: '⚠', color: 'var(--portal-warning, #f59e0b)' },
                info: { icon: 'ℹ', color: 'var(--portal-info, #3b82f6)' }
            };
            
            const config = iconMap[type] || iconMap.info;
            
            toast.innerHTML = `
                <span style="
                    width: 28px;
                    height: 28px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: ${config.color}15;
                    color: ${config.color};
                    border-radius: 50%;
                    font-size: 14px;
                    font-weight: bold;
                ">${config.icon}</span>
                <span style="flex: 1; font-size: 14px; color: var(--portal-text, #1e293b);">${message}</span>
            `;
            
            toast.onclick = () => this.remove(toast);
            this.container.appendChild(toast);
            
            if (duration > 0) {
                setTimeout(() => this.remove(toast), duration);
            }
            
            return toast;
        },
        
        remove(toast) {
            toast.style.animation = 'slideOutRight 0.2s ease forwards';
            setTimeout(() => toast.remove(), 200);
        },
        
        success(message, duration) { return this.show(message, 'success', duration); },
        error(message, duration) { return this.show(message, 'error', duration); },
        warning(message, duration) { return this.show(message, 'warning', duration); },
        info(message, duration) { return this.show(message, 'info', duration); }
    };

    // ========== Modal 模态框组件 ==========
    const Modal = {
        stack: [],
        
        show(options) {
            const {
                title = '',
                content = '',
                html = '',
                footer = true,
                confirmText = '确定',
                cancelText = '取消',
                onConfirm = null,
                onCancel = null,
                closable = true,
                width = '480px',
                className = ''
            } = options;
            
            const overlay = document.createElement('div');
            overlay.className = 'portal-modal-overlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(15, 23, 42, 0.6);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                animation: fadeIn 0.2s ease;
                padding: 20px;
            `;
            
            const modal = document.createElement('div');
            modal.className = `portal-modal ${className}`;
            modal.style.cssText = `
                background: var(--portal-card-solid, #fff);
                border-radius: var(--portal-radius-lg, 16px);
                box-shadow: 0 25px 50px rgba(0,0,0,0.2);
                width: 100%;
                max-width: ${width};
                max-height: 90vh;
                overflow: hidden;
                animation: scaleIn 0.25s var(--portal-ease-spring, cubic-bezier(0.34, 1.56, 0.64, 1));
            `;
            
            let modalHTML = '';
            
            // Header
            if (title || closable) {
                modalHTML += `
                    <div class="portal-modal-header" style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 20px 24px;
                        border-bottom: 1px solid var(--portal-border, #e2e8f0);
                    ">
                        <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: var(--portal-text, #1e293b);">${title}</h3>
                        ${closable ? `
                            <button class="portal-modal-close" style="
                                width: 32px;
                                height: 32px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                background: transparent;
                                border: none;
                                border-radius: 8px;
                                cursor: pointer;
                                color: var(--portal-text-muted, #94a3b8);
                                font-size: 20px;
                                transition: all 0.15s ease;
                            " onmouseover="this.style.background='var(--portal-bg, #f8fafc)'" onmouseout="this.style.background='transparent'">×</button>
                        ` : ''}
                    </div>
                `;
            }
            
            // Body
            modalHTML += `
                <div class="portal-modal-body" style="
                    padding: 24px;
                    overflow-y: auto;
                    max-height: calc(90vh - 140px);
                ">
                    ${html || `<p style="margin: 0; color: var(--portal-text-secondary, #64748b); font-size: 15px; line-height: 1.6;">${content}</p>`}
                </div>
            `;
            
            // Footer
            if (footer) {
                modalHTML += `
                    <div class="portal-modal-footer" style="
                        display: flex;
                        justify-content: flex-end;
                        gap: 12px;
                        padding: 16px 24px;
                        border-top: 1px solid var(--portal-border, #e2e8f0);
                    ">
                        ${cancelText ? `<button class="portal-modal-cancel portal-btn portal-btn-secondary" style="padding: 10px 20px;">${cancelText}</button>` : ''}
                        ${confirmText ? `<button class="portal-modal-confirm portal-btn portal-btn-primary" style="padding: 10px 20px;">${confirmText}</button>` : ''}
                    </div>
                `;
            }
            
            modal.innerHTML = modalHTML;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            
            this.stack.push(overlay);
            
            // 绑定事件
            const closeModal = () => {
                overlay.style.animation = 'fadeOut 0.2s ease forwards';
                modal.style.animation = 'scaleOut 0.2s ease forwards';
                setTimeout(() => {
                    overlay.remove();
                    this.stack.pop();
                    if (this.stack.length === 0) {
                        document.body.style.overflow = '';
                    }
                }, 200);
            };
            
            if (closable) {
                overlay.querySelector('.portal-modal-close')?.addEventListener('click', () => {
                    closeModal();
                    onCancel?.();
                });
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        closeModal();
                        onCancel?.();
                    }
                });
            }
            
            overlay.querySelector('.portal-modal-cancel')?.addEventListener('click', () => {
                closeModal();
                onCancel?.();
            });
            
            overlay.querySelector('.portal-modal-confirm')?.addEventListener('click', () => {
                if (onConfirm) {
                    const result = onConfirm();
                    if (result !== false) closeModal();
                } else {
                    closeModal();
                }
            });
            
            return {
                close: closeModal,
                element: modal
            };
        },
        
        alert(message, title = '提示') {
            return new Promise(resolve => {
                this.show({
                    title,
                    content: message,
                    cancelText: '',
                    confirmText: '知道了',
                    onConfirm: resolve
                });
            });
        },
        
        confirm(message, title = '确认') {
            return new Promise((resolve, reject) => {
                this.show({
                    title,
                    content: message,
                    confirmText: '确定',
                    cancelText: '取消',
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false)
                });
            });
        },
        
        prompt(message, defaultValue = '', title = '请输入') {
            return new Promise((resolve) => {
                this.show({
                    title,
                    html: `
                        <p style="margin: 0 0 16px; color: var(--portal-text-secondary, #64748b);">${message}</p>
                        <input type="text" class="portal-input portal-prompt-input" value="${defaultValue}" style="width: 100%;">
                    `,
                    onConfirm: () => {
                        const input = document.querySelector('.portal-prompt-input');
                        resolve(input?.value || '');
                    },
                    onCancel: () => resolve(null)
                });
                setTimeout(() => document.querySelector('.portal-prompt-input')?.focus(), 100);
            });
        }
    };

    // ========== Loading 加载组件 ==========
    const Loading = {
        element: null,
        
        show(text = '加载中...') {
            if (this.element) return;
            
            this.element = document.createElement('div');
            this.element.className = 'portal-loading-overlay';
            this.element.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.85);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 16px;
                z-index: 10001;
                animation: fadeIn 0.2s ease;
            `;
            
            this.element.innerHTML = `
                <div class="portal-spinner portal-spinner-lg"></div>
                <span style="font-size: 15px; color: var(--portal-text-secondary, #64748b);">${text}</span>
            `;
            
            document.body.appendChild(this.element);
        },
        
        hide() {
            if (!this.element) return;
            this.element.style.animation = 'fadeOut 0.2s ease forwards';
            setTimeout(() => {
                this.element?.remove();
                this.element = null;
            }, 200);
        }
    };

    // ========== Tabs 标签切换 ==========
    const Tabs = {
        init(container) {
            const tabsEl = container.querySelector('.portal-tabs');
            const contents = container.querySelectorAll('.portal-tab-content');
            
            if (!tabsEl) return;
            
            tabsEl.querySelectorAll('.portal-tab').forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    const target = tab.dataset.tab;
                    
                    // 更新 tab 状态
                    tabsEl.querySelectorAll('.portal-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    
                    // 更新内容
                    contents.forEach(content => {
                        content.classList.toggle('active', content.id === target || content.dataset.tab === target);
                    });
                    
                    // 触发自定义事件
                    container.dispatchEvent(new CustomEvent('tabChange', { detail: { tab: target } }));
                });
            });
        }
    };

    // ========== 注入全局样式 ==========
    function injectStyles() {
        if (document.getElementById('portal-ui-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'portal-ui-styles';
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; }
                to { opacity: 0; }
            }
            @keyframes scaleIn {
                from { opacity: 0; transform: scale(0.9); }
                to { opacity: 1; transform: scale(1); }
            }
            @keyframes scaleOut {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.9); }
            }
            @keyframes slideInRight {
                from { opacity: 0; transform: translateX(100%); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes slideOutRight {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100%); }
            }
            @keyframes slideInUp {
                from { opacity: 0; transform: translateY(100%); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .portal-spinner {
                width: 24px;
                height: 24px;
                border: 3px solid var(--portal-border, #e2e8f0);
                border-top-color: var(--portal-primary, #6366f1);
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }
            .portal-spinner-lg {
                width: 40px;
                height: 40px;
                border-width: 4px;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            
            /* Mobile toast position */
            @media (max-width: 767px) {
                .portal-toast-container {
                    top: auto !important;
                    bottom: 80px !important;
                    right: 16px !important;
                    left: 16px !important;
                    max-width: none !important;
                }
            }
        `;
        document.head.appendChild(style);
    }

    // ========== 初始化 ==========
    function init() {
        injectStyles();
        
        // 自动初始化 Tabs
        document.querySelectorAll('[data-portal-tabs]').forEach(Tabs.init);
    }

    // DOM Ready 时初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // 公开 API
    return {
        Toast,
        Modal,
        Loading,
        Tabs,
        toast: Toast.show.bind(Toast),
        alert: Modal.alert.bind(Modal),
        confirm: Modal.confirm.bind(Modal),
        prompt: Modal.prompt.bind(Modal),
        loading: {
            show: Loading.show.bind(Loading),
            hide: Loading.hide.bind(Loading)
        }
    };
})();

// 兼容全局函数调用
function showPortalToast(message, type, duration) {
    return PortalUI.Toast.show(message, type, duration);
}

function showPortalModal(options) {
    return PortalUI.Modal.show(options);
}

function showPortalAlert(message, title) {
    return PortalUI.Modal.alert(message, title);
}

function showPortalConfirm(message, title) {
    return PortalUI.Modal.confirm(message, title);
}
