// Bootstrap 5 Modal 通用函数（简化版本）

// Alert 提示框（居中浮层，透明灰背景）
function showAlertModal(message, type = 'info', onClose = null, duration = 2000) {
    type = type || 'info';
    duration = typeof duration === 'number' ? duration : 2000;
    
    const iconMap = {
        'success': '✓',
        'error': '✗',
        'warning': '⚠',
        'info': 'ℹ'
    };
    const titleMap = {
        'success': '操作成功',
        'error': '操作失败',
        'warning': '重要提醒',
        'info': '温馨提示'
    };
    const detailMap = {
        'success': '系统已成功执行您的请求，可继续下一步操作。',
        'error': '请检查输入或稍后重试，如持续失败请联系管理员。',
        'warning': '请确认相关信息，必要时请与团队保持同步。',
        'info': '如需更多说明，请查看帮助中心或联系客服。'
    };
    
    // 注入样式
    if (!document.getElementById('alertOverlayStyles')) {
        const style = document.createElement('style');
        style.id = 'alertOverlayStyles';
        style.textContent = `
            .alert-overlay-container {
                position: fixed;
                top: 20px;
                right: 20px;
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                gap: 12px;
                z-index: 1100;
                pointer-events: none;
                max-width: 420px;
            }
            .alert-overlay-item {
                max-width: 420px;
                width: 100%;
                background: rgba(41, 45, 50, 0.74);
                border-radius: 14px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
                backdrop-filter: blur(12px);
                padding: 20px 24px;
                color: #f7fafc;
                transform: translateX(20px) scale(0.95) translateZ(0);
                opacity: 0;
                transition: opacity 0.25s ease, transform 0.25s ease;
                border-left: 6px solid #0d6efd;
                position: relative;
                pointer-events: auto;
            }
            .alert-overlay-item.show {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
            .alert-overlay-item.success { border-left-color: #198754; }
            .alert-overlay-item.error { border-left-color: #dc3545; }
            .alert-overlay-item.warning { border-left-color: #ffc107; }
            .alert-overlay-item.info { border-left-color: #0dcaf0; }
            .alert-overlay-content {
                display: flex;
                gap: 14px;
            }
            .alert-overlay-icon {
                width: 46px;
                height: 46px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                background: rgba(255, 255, 255, 0.08);
                color: #fff;
            }
            .alert-overlay-text h5 {
                margin: 0;
                font-size: 17px;
                font-weight: 600;
            }
            .alert-overlay-text p {
                margin: 6px 0 0;
                font-size: 15px;
                line-height: 1.5;
                color: #f1f5f9;
            }
            .alert-overlay-detail {
                margin-top: 10px;
                font-size: 13px;
                color: #cbd5f5;
            }
            .alert-overlay-close {
                position: absolute;
                top: 12px;
                right: 12px;
                border: none;
                background: transparent;
                font-size: 18px;
                line-height: 1;
                color: #e5e7eb;
                cursor: pointer;
            }
            .alert-overlay-close:hover {
                color: #ffffff;
            }
        `;
        document.head.appendChild(style);
    }
    
    // 创建/复用容器
    let overlay = document.getElementById('alertOverlayContainer');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'alertOverlayContainer';
        overlay.className = 'alert-overlay-container';
        document.body.appendChild(overlay);
    }
    
    const alertId = 'alertOverlay-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6);
    const alertHtml = `
        <div id="${alertId}" class="alert-overlay-item ${type}">
            <button class="alert-overlay-close" aria-label="关闭">&times;</button>
            <div class="alert-overlay-content">
                <div class="alert-overlay-icon">
                    ${iconMap[type] || 'ℹ'}
                </div>
                <div class="alert-overlay-text">
                    <h5>${titleMap[type] || '提示信息'}</h5>
                    <p>${message}</p>
                    <div class="alert-overlay-detail">${detailMap[type] || ''}</div>
                </div>
            </div>
        </div>
    `;
    
    overlay.insertAdjacentHTML('beforeend', alertHtml);
    const alertElement = document.getElementById(alertId);
    
    const removeAlert = () => {
        if (!alertElement) return;
        alertElement.classList.remove('show');
        setTimeout(() => {
            if (alertElement && alertElement.parentNode) {
                alertElement.remove();
            }
            if (overlay && overlay.children.length === 0) {
                overlay.remove();
            }
            if (onClose) {
                onClose();
            }
        }, 250);
    };
    
    alertElement.querySelector('.alert-overlay-close').addEventListener('click', removeAlert);
    
    requestAnimationFrame(() => {
        alertElement.classList.add('show');
    });
    
    setTimeout(removeAlert, duration);
}

// Confirm 确认框（支持标题和内容两种调用方式）
function showConfirmModal(titleOrMessage, messageOrCallback, callbackOrCancel, cancelCallback) {
    let title, message, onConfirm, onCancel;
    
    // 判断调用方式
    if (typeof messageOrCallback === 'string') {
        // 四参数模式：showConfirmModal(title, message, confirmCallback, cancelCallback)
        title = titleOrMessage;
        message = messageOrCallback;
        onConfirm = callbackOrCancel;
        onCancel = cancelCallback || null;
    } else {
        // 两参数模式：showConfirmModal(message, callback)
        title = '⚠ 确认操作';
        message = titleOrMessage;
        onConfirm = messageOrCallback;
        onCancel = callbackOrCancel || null;
    }
    
    const modalHtml = `
        <div class="modal fade" id="confirmModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-warning text-dark border-0 py-2">
                        <h6 class="modal-title mb-0">${title}</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center py-4">
                        <div style="font-size: 15px; color: #333;">
                            ${message}
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 pb-3 justify-content-center">
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal" id="cancelBtn">取消</button>
                        <button type="button" class="btn btn-danger btn-sm px-3" id="confirmBtn">确定</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const oldModal = document.getElementById('confirmModal');
    if (oldModal) {
        try {
            oldModal.remove();
        } catch(e) {}
    }
    
    // 添加新弹窗
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalElement = document.getElementById('confirmModal');
    const modal = new bootstrap.Modal(modalElement);
    
    // 确定按钮
    document.getElementById('confirmBtn').onclick = function() {
        modal.hide();
        setTimeout(function() {
            if (modalElement && modalElement.parentNode) {
                modalElement.remove();
            }
            if (onConfirm) {
                onConfirm();
            }
        }, 300);
    };
    
    // 取消按钮
    document.getElementById('cancelBtn').onclick = function() {
        modal.hide();
        setTimeout(function() {
            if (modalElement && modalElement.parentNode) {
                modalElement.remove();
            }
            if (onCancel) {
                onCancel();
            }
        }, 300);
    };
    
    modal.show();
}

// Prompt 输入框
function showPromptModal(title, defaultValue, onConfirm, onCancel) {
    const modalHtml = `
        <div class="modal fade" id="promptModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-primary text-white border-0 py-2">
                        <h6 class="modal-title mb-0">${title}</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4">
                        <input type="text" class="form-control" id="promptInput" value="${defaultValue || ''}" placeholder="请输入...">
                    </div>
                    <div class="modal-footer border-0 pt-0 pb-3 justify-content-center">
                        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal" id="promptCancelBtn">取消</button>
                        <button type="button" class="btn btn-primary btn-sm px-3" id="promptConfirmBtn">确定</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧弹窗
    const oldModal = document.getElementById('promptModal');
    if (oldModal) {
        try { oldModal.remove(); } catch(e) {}
    }
    
    // 添加新弹窗
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalElement = document.getElementById('promptModal');
    const modal = new bootstrap.Modal(modalElement);
    const input = document.getElementById('promptInput');
    
    // 确定按钮
    document.getElementById('promptConfirmBtn').onclick = function() {
        const value = input.value.trim();
        modal.hide();
        setTimeout(function() {
            if (modalElement && modalElement.parentNode) {
                modalElement.remove();
            }
            if (onConfirm && value) {
                onConfirm(value);
            }
        }, 300);
    };
    
    // 取消按钮
    document.getElementById('promptCancelBtn').onclick = function() {
        modal.hide();
        setTimeout(function() {
            if (modalElement && modalElement.parentNode) {
                modalElement.remove();
            }
            if (onCancel) {
                onCancel();
            }
        }, 300);
    };
    
    // 回车确认
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('promptConfirmBtn').click();
        }
    });
    
    modal.show();
    setTimeout(() => input.focus(), 300);
}

console.log('modal.js loaded successfully');

// 覆盖原生 alert（可选）
// window.alert = function(message) {
//     showAlertModal(message, 'info');
// };

// 覆盖原生 confirm（可选）
// window.confirm = function(message) {
//     return new Promise((resolve) => {
//         showConfirmModal(message, () => resolve(true), () => resolve(false));
//     });
// };
