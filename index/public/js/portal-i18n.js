/**
 * 客户门户国际化模块
 * 自动将简体中文转换为繁体中文
 */

(function(global) {
  'use strict';

  // 确保 OpenCCLite 已加载
  const cc = global.OpenCCLite;
  if (!cc) {
    console.warn('[Portal-i18n] OpenCCLite not loaded');
    return;
  }

  /**
   * 翻译函数（简写）
   * @param {string} text - 原文
   * @returns {string} 繁体中文
   */
  function t(text) {
    return cc.toTraditional(text);
  }

  /**
   * 转换页面所有静态文本
   */
  function convertPageText() {
    cc.convertElement(document.body);
  }

  /**
   * 转换 API 响应数据中的文本字段
   * @param {object} data - API 响应数据
   * @returns {object} 转换后的数据
   */
  function convertApiData(data) {
    // 需要转换的字段列表
    const textFields = [
      'project_name',
      'project_code', 
      'customer_name',
      'current_status',
      'instance_name',
      'template_name',
      'requirement_status_label',
      'deliverable_name',
      'deliverable_type',
      'description',
      'remark',
      'note',
      'message',
      'title',
      'label',
      'name'
    ];
    
    return cc.convertObject(data, textFields);
  }

  /**
   * 页面加载后自动转换静态文本
   */
  function init() {
    // 等待 DOM 完全加载
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function() {
        setTimeout(convertPageText, 50);
      });
    } else {
      setTimeout(convertPageText, 50);
    }
  }

  // 自动初始化
  init();

  // 导出到全局
  global.PortalI18n = {
    t: t,
    convertPageText: convertPageText,
    convertApiData: convertApiData
  };

  // 全局快捷函数
  global.t = t;

})(typeof window !== 'undefined' ? window : this);
