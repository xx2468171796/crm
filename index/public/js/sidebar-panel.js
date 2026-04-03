/**
 * 侧边栏详情面板组件
 * 通用侧边栏组件，支持右侧滑入显示详情
 */

class SidebarPanel {
    constructor(options = {}) {
        this.options = {
            title: options.title || '详情',
            icon: options.icon || 'bi-info-circle',
            width: options.width || '480px',
            onOpen: options.onOpen || null,
            onClose: options.onClose || null,
            openPageUrl: options.openPageUrl || null,
            openPageText: options.openPageText || '打开详情页',
            ...options
        };
        
        this.isOpen = false;
        this.overlayEl = null;
        this.panelEl = null;
        this.bodyEl = null;
        
        this._init();
        this._bindEvents();
    }
    
    _init() {
        // 创建遮罩层
        this.overlayEl = document.createElement('div');
        this.overlayEl.className = 'sidebar-overlay';
        this.overlayEl.id = 'sidebarOverlay';
        
        // 创建侧边栏面板
        this.panelEl = document.createElement('div');
        this.panelEl.className = 'sidebar-panel';
        this.panelEl.id = 'sidebarPanel';
        this.panelEl.style.width = this.options.width;
        
        this.panelEl.innerHTML = `
            <div class="sidebar-header">
                <h4 class="sidebar-title">
                    <i class="bi ${this.options.icon}"></i>
                    <span id="sidebarTitleText">${this.options.title}</span>
                </h4>
                <button class="sidebar-close" id="sidebarClose" title="关闭">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="sidebar-body" id="sidebarBody">
                <!-- 内容将动态加载 -->
            </div>
            <div class="sidebar-footer" id="sidebarFooter" style="display: none;">
                <button class="sidebar-btn sidebar-btn-primary sidebar-btn-block" id="sidebarOpenPage">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span>${this.options.openPageText}</span>
                </button>
            </div>
        `;
        
        this.bodyEl = this.panelEl.querySelector('#sidebarBody');
        
        // 添加到 DOM
        document.body.appendChild(this.overlayEl);
        document.body.appendChild(this.panelEl);
    }
    
    _bindEvents() {
        // 关闭按钮
        this.panelEl.querySelector('#sidebarClose').addEventListener('click', () => this.close());
        
        // 点击遮罩关闭
        this.overlayEl.addEventListener('click', () => this.close());
        
        // ESC 键关闭
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen) {
                this.close();
            }
        });
        
        // 打开详情页按钮
        this.panelEl.querySelector('#sidebarOpenPage').addEventListener('click', () => {
            if (this._currentPageUrl) {
                window.location.href = this._currentPageUrl;
            }
        });
    }
    
    /**
     * 打开侧边栏
     * @param {Object} options - 打开选项
     * @param {string} options.title - 标题
     * @param {string} options.pageUrl - 详情页URL
     * @param {Function} options.loadContent - 加载内容的回调函数
     */
    open(options = {}) {
        // 更新标题
        if (options.title) {
            this.panelEl.querySelector('#sidebarTitleText').textContent = options.title;
        }
        
        // 设置详情页URL
        this._currentPageUrl = options.pageUrl || null;
        const footerEl = this.panelEl.querySelector('#sidebarFooter');
        if (this._currentPageUrl) {
            footerEl.style.display = 'block';
        } else {
            footerEl.style.display = 'none';
        }
        
        // 显示加载状态
        this.showLoading();
        
        // 显示侧边栏
        this.overlayEl.classList.add('active');
        this.panelEl.classList.add('active');
        this.isOpen = true;
        
        // 禁止 body 滚动
        document.body.style.overflow = 'hidden';
        
        // 加载内容
        if (options.loadContent) {
            options.loadContent(this);
        }
        
        if (this.options.onOpen) {
            this.options.onOpen();
        }
    }
    
    /**
     * 关闭侧边栏
     */
    close() {
        this.overlayEl.classList.remove('active');
        this.panelEl.classList.remove('active');
        this.isOpen = false;
        
        // 恢复 body 滚动
        document.body.style.overflow = '';
        
        if (this.options.onClose) {
            this.options.onClose();
        }
    }
    
    /**
     * 显示加载状态
     */
    showLoading() {
        this.bodyEl.innerHTML = `
            <div class="sidebar-loading">
                <div class="spinner"></div>
                <span>加载中...</span>
            </div>
        `;
    }
    
    /**
     * 显示错误状态
     * @param {string} message - 错误信息
     */
    showError(message = '加载失败') {
        this.bodyEl.innerHTML = `
            <div class="sidebar-error">
                <i class="bi bi-exclamation-triangle"></i>
                <p>${message}</p>
                <button class="sidebar-btn sidebar-btn-secondary" onclick="sidebarPanel.close()">
                    关闭
                </button>
            </div>
        `;
    }
    
    /**
     * 设置内容
     * @param {string} html - HTML内容
     */
    setContent(html) {
        this.bodyEl.innerHTML = html;
    }
    
    /**
     * 销毁组件
     */
    destroy() {
        if (this.overlayEl) {
            this.overlayEl.remove();
        }
        if (this.panelEl) {
            this.panelEl.remove();
        }
    }
}

// 辅助函数：创建信息网格
function createSidebarInfoGrid(items) {
    let html = '<div class="sidebar-info-grid">';
    items.forEach(item => {
        const fullWidth = item.fullWidth ? 'full-width' : '';
        html += `
            <div class="sidebar-info-item ${fullWidth}">
                <div class="sidebar-info-label">${item.label}</div>
                <div class="sidebar-info-value">${item.value || '-'}</div>
            </div>
        `;
    });
    html += '</div>';
    return html;
}

// 辅助函数：创建列表
function createSidebarList(items, emptyText = '暂无数据') {
    if (!items || items.length === 0) {
        return `<div class="sidebar-empty"><i class="bi bi-inbox"></i><span>${emptyText}</span></div>`;
    }
    
    let html = '<ul class="sidebar-list">';
    items.forEach(item => {
        html += `
            <li class="sidebar-list-item" ${item.onClick ? `onclick="${item.onClick}"` : ''}>
                <div class="sidebar-list-icon" style="background: ${item.iconBg || '#6366f1'}">
                    <i class="bi ${item.icon || 'bi-folder'}"></i>
                </div>
                <div class="sidebar-list-content">
                    <div class="sidebar-list-title">${item.title}</div>
                    ${item.subtitle ? `<div class="sidebar-list-subtitle">${item.subtitle}</div>` : ''}
                </div>
                ${item.badge ? `<span class="sidebar-badge ${item.badgeClass || 'sidebar-badge-info'}">${item.badge}</span>` : ''}
            </li>
        `;
    });
    html += '</ul>';
    return html;
}

// 辅助函数：创建区块
function createSidebarSection(title, content) {
    return `
        <div class="sidebar-section">
            <div class="sidebar-section-title">${title}</div>
            ${content}
        </div>
    `;
}

// 全局实例（可选）
let sidebarPanel = null;

function initSidebarPanel(options = {}) {
    if (sidebarPanel) {
        sidebarPanel.destroy();
    }
    sidebarPanel = new SidebarPanel(options);
    return sidebarPanel;
}
