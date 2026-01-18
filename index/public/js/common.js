/**
 * 公共工具函数库
 * @description 提供格式化、API调用、UI辅助等通用功能
 */

// ========== 时间格式化 ==========

/**
 * 格式化时间戳为日期字符串
 * @param {number} timestamp - Unix时间戳（秒）
 * @param {string} format - 格式类型：'date'|'datetime'|'time'
 * @returns {string}
 */
function formatTime(timestamp, format = 'date') {
    if (!timestamp) return '-';
    const date = new Date(timestamp * 1000);
    
    const options = {
        date: { year: 'numeric', month: '2-digit', day: '2-digit' },
        datetime: { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' },
        time: { hour: '2-digit', minute: '2-digit', second: '2-digit' }
    };
    
    return date.toLocaleString('zh-CN', options[format] || options.date);
}

/**
 * 格式化相对时间（如：3分钟前）
 * @param {number} timestamp - Unix时间戳（秒）
 * @returns {string}
 */
function formatRelativeTime(timestamp) {
    if (!timestamp) return '-';
    
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    
    if (diff < 60) return '刚刚';
    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
    if (diff < 2592000) return Math.floor(diff / 86400) + '天前';
    
    return formatTime(timestamp, 'date');
}

// ========== API调用封装 ==========

/**
 * 统一的API请求封装
 * @param {string} url - API地址
 * @param {Object} options - fetch选项
 * @returns {Promise<Object>}
 */
async function apiRequest(url, options = {}) {
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    const mergedOptions = {
        ...defaultOptions,
        ...options,
        headers: {
            ...defaultOptions.headers,
            ...options.headers
        }
    };
    
    try {
        const response = await fetch(url, mergedOptions);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || '操作失败');
        }
        
        return result;
    } catch (error) {
        console.error('[API_DEBUG]', url, error);
        throw error;
    }
}

/**
 * GET请求
 * @param {string} url 
 * @returns {Promise<Object>}
 */
function apiGet(url) {
    return apiRequest(url, { method: 'GET' });
}

/**
 * POST请求
 * @param {string} url 
 * @param {Object} data 
 * @returns {Promise<Object>}
 */
function apiPost(url, data) {
    return apiRequest(url, {
        method: 'POST',
        body: JSON.stringify(data)
    });
}

/**
 * PUT请求
 * @param {string} url 
 * @param {Object} data 
 * @returns {Promise<Object>}
 */
function apiPut(url, data) {
    return apiRequest(url, {
        method: 'PUT',
        body: JSON.stringify(data)
    });
}

/**
 * DELETE请求
 * @param {string} url 
 * @returns {Promise<Object>}
 */
function apiDelete(url) {
    return apiRequest(url, { method: 'DELETE' });
}

// ========== 剪贴板操作 ==========

/**
 * 复制文本到剪贴板
 * @param {string} text - 要复制的文本
 * @returns {Promise<boolean>}
 */
async function copyToClipboard(text) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        
        // 降级方案
        const input = document.createElement('textarea');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        input.value = text;
        document.body.appendChild(input);
        input.select();
        const success = document.execCommand('copy');
        document.body.removeChild(input);
        return success;
    } catch (error) {
        console.error('[CLIPBOARD_DEBUG]', error);
        return false;
    }
}

// ========== 数据格式化 ==========

/**
 * 格式化文件大小
 * @param {number} bytes 
 * @returns {string}
 */
function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

/**
 * 格式化金额
 * @param {number} amount 
 * @param {string} currency 
 * @returns {string}
 */
function formatCurrency(amount, currency = 'CNY') {
    return new Intl.NumberFormat('zh-CN', {
        style: 'currency',
        currency: currency
    }).format(amount || 0);
}

/**
 * 格式化百分比
 * @param {number} value 
 * @param {number} decimals 
 * @returns {string}
 */
function formatPercent(value, decimals = 0) {
    return (value || 0).toFixed(decimals) + '%';
}

// ========== DOM辅助 ==========

/**
 * 安全获取元素
 * @param {string} selector 
 * @returns {HTMLElement|null}
 */
function $(selector) {
    if (selector.startsWith('#')) {
        return document.getElementById(selector.slice(1));
    }
    return document.querySelector(selector);
}

/**
 * 获取多个元素
 * @param {string} selector 
 * @returns {NodeList}
 */
function $$(selector) {
    return document.querySelectorAll(selector);
}

/**
 * 创建元素
 * @param {string} tag 
 * @param {Object} attrs 
 * @param {string|HTMLElement} content 
 * @returns {HTMLElement}
 */
function createElement(tag, attrs = {}, content = '') {
    const el = document.createElement(tag);
    
    Object.entries(attrs).forEach(([key, value]) => {
        if (key === 'className') {
            el.className = value;
        } else if (key === 'style' && typeof value === 'object') {
            Object.assign(el.style, value);
        } else if (key.startsWith('on') && typeof value === 'function') {
            el.addEventListener(key.slice(2).toLowerCase(), value);
        } else {
            el.setAttribute(key, value);
        }
    });
    
    if (typeof content === 'string') {
        el.innerHTML = content;
    } else if (content instanceof HTMLElement) {
        el.appendChild(content);
    }
    
    return el;
}

// ========== 防抖与节流 ==========

/**
 * 防抖函数
 * @param {Function} fn 
 * @param {number} delay 
 * @returns {Function}
 */
function debounce(fn, delay = 300) {
    let timer = null;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

/**
 * 节流函数
 * @param {Function} fn 
 * @param {number} limit 
 * @returns {Function}
 */
function throttle(fn, limit = 300) {
    let inThrottle = false;
    return function(...args) {
        if (!inThrottle) {
            fn.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// ========== 状态配置 ==========

/**
 * 项目状态列表
 */
const PROJECT_STATUSES = ['待沟通', '需求确认', '设计中', '设计核对', '设计完工', '设计评价'];

/**
 * 需求状态列表
 */
const REQUIREMENT_STATUSES = {
    pending: '待填写',
    communicating: '需求沟通',
    confirmed: '需求确认',
    modifying: '需求修改'
};

/**
 * 状态颜色映射
 */
const STATUS_COLORS = {
    // 项目状态
    '待沟通': { bg: '#f3f4f6', color: '#4b5563' },
    '需求确认': { bg: '#dbeafe', color: '#1d4ed8' },
    '设计中': { bg: '#fef3c7', color: '#92400e' },
    '设计核对': { bg: '#fed7aa', color: '#c2410c' },
    '设计完工': { bg: '#d1fae5', color: '#065f46' },
    '设计评价': { bg: '#e0e7ff', color: '#4338ca' },
    // 需求状态
    pending: { bg: '#f3f4f6', color: '#6b7280' },
    communicating: { bg: '#fef3c7', color: '#d97706' },
    confirmed: { bg: '#d1fae5', color: '#059669' },
    modifying: { bg: '#fee2e2', color: '#dc2626' }
};

/**
 * 获取状态进度百分比
 * @param {string} status 
 * @returns {number}
 */
function getStatusProgress(status) {
    const progress = {
        '待沟通': 10,
        '需求确认': 25,
        '设计中': 50,
        '设计核对': 75,
        '设计完工': 90,
        '设计评价': 100
    };
    return progress[status] || 0;
}

/**
 * 获取状态颜色
 * @param {string} status 
 * @returns {Object}
 */
function getStatusColor(status) {
    return STATUS_COLORS[status] || STATUS_COLORS['待沟通'];
}

// ========== 导出（如果使用ES模块） ==========
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        formatTime,
        formatRelativeTime,
        apiRequest,
        apiGet,
        apiPost,
        apiPut,
        apiDelete,
        copyToClipboard,
        formatFileSize,
        formatCurrency,
        formatPercent,
        debounce,
        throttle,
        PROJECT_STATUSES,
        REQUIREMENT_STATUSES,
        STATUS_COLORS,
        getStatusProgress,
        getStatusColor
    };
}
