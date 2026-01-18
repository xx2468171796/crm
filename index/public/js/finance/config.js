/**
 * Finance Dashboard - Configuration Module
 * 财务工作台配置模块
 */

const FinanceDashboardConfig = {
    apiUrl: '',
    viewMode: 'contract',
    currentRole: '',
    currentUserId: 0,
    serverNowTs: 0,
    pageKey: 'finance_dashboard',
    initialViewId: 0,
    canReceipt: true,
    contractStatusOptions: ['已收几期', '剩余几期', '已结清', '作废'],
    installmentStatusOptions: ['待收', '催款', '已收'],
    focusUserType: '',
    focusUserId: 0,

    /**
     * 从DOM元素读取配置
     */
    init() {
        const configEl = document.getElementById('dashboardConfig');
        if (configEl) {
            this.apiUrl = configEl.dataset.apiUrl || '';
            this.viewMode = configEl.dataset.viewMode || 'contract';
            this.currentRole = configEl.dataset.currentRole || '';
            this.currentUserId = Number(configEl.dataset.currentUserId || 0);
            this.serverNowTs = Number(configEl.dataset.serverNowTs || 0);
            this.initialViewId = Number(configEl.dataset.initialViewId || 0);
            this.canReceipt = configEl.dataset.canReceipt === 'true';
            this.focusUserType = configEl.dataset.focusUserType || '';
            this.focusUserId = Number(configEl.dataset.focusUserId || 0);
            
            if (this.canReceipt) {
                this.installmentStatusOptions = ['待收', '催款', '已收'];
            }
        }
        return this;
    },

    /**
     * 构建API URL
     */
    buildApiUrl(path) {
        return this.apiUrl + '/' + path;
    }
};

// 兼容旧代码的别名
window.DashboardConfig = FinanceDashboardConfig;
