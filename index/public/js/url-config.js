/**
 * 前端URL配置
 * 统一管理所有前端URL路径
 */

// 全局常量
window.BASE_URL = window.location.origin;
window.API_PATH = '/api';
window.JS_PATH = '/js';
window.CSS_PATH = '/css';
window.UPLOAD_PATH = '/uploads';

// URL辅助对象
window.AppUrl = {
    // API路径
    api: function(path) {
        return API_PATH + '/' + (path || '').replace(/^\//, '');
    },
    
    // JS路径
    js: function(file) {
        return JS_PATH + '/' + (file || '').replace(/^\//, '');
    },
    
    // CSS路径
    css: function(file) {
        return CSS_PATH + '/' + (file || '').replace(/^\//, '');
    },
    
    // 上传文件路径
    upload: function(file) {
        return UPLOAD_PATH + '/' + (file || '').replace(/^\//, '');
    },
    
    // 基础URL
    base: function() {
        return BASE_URL;
    },
    
    // 完整URL
    full: function(path) {
        return BASE_URL + '/' + (path || '').replace(/^\//, '');
    }
};

// 快捷方式
window.apiUrl = window.AppUrl.api;
