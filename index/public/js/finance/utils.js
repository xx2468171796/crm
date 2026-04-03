/**
 * Finance Dashboard - Utilities Module
 * 财务工作台工具函数模块
 */

const FinanceDashboardUtils = {
    /**
     * HTML转义防XSS
     */
    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    /**
     * 格式化金额
     */
    formatMoney(value, decimals = 2) {
        return parseFloat(value || 0).toFixed(decimals);
    },

    /**
     * 格式化日期时间
     */
    formatDateTime(timestamp) {
        if (!timestamp) return '-';
        const date = new Date(timestamp * 1000);
        return date.toLocaleString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    },

    /**
     * 格式化日期
     */
    formatDate(dateStr) {
        if (!dateStr) return '-';
        return dateStr;
    },

    /**
     * 获取状态徽章颜色
     */
    getStatusBadge(status) {
        if (['已结清', '已收'].includes(status)) return 'success';
        if (['部分已收', '催款'].includes(status)) return 'warning';
        if (['逾期'].includes(status)) return 'danger';
        if (['待收'].includes(status)) return 'primary';
        if (['作废'].includes(status)) return 'secondary';
        return 'secondary';
    },

    /**
     * 防抖函数
     */
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * 显示确认弹窗
     */
    showConfirmModal(message, onConfirm, onCancel) {
        if (typeof showConfirmModal === 'function') {
            showConfirmModal(message, onConfirm, onCancel);
        } else if (confirm(message)) {
            onConfirm && onConfirm();
        } else {
            onCancel && onCancel();
        }
    },

    /**
     * 显示Toast提示
     */
    showToast(message, type = 'info') {
        if (typeof showToast === 'function') {
            showToast(message, type);
        } else {
            alert(message);
        }
    }
};

// 兼容旧代码
window.FinanceDashboardUtils = FinanceDashboardUtils;
