/**
 * Finance Dashboard - API Module
 * 财务工作台API调用模块
 */

const FinanceDashboardApi = {
    /**
     * 通用请求方法
     */
    async request(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
            },
        };
        
        const mergedOptions = { ...defaultOptions, ...options };
        
        try {
            const response = await fetch(url, mergedOptions);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('[FinanceDashboardApi] Request error:', error);
            return { success: false, error: error.message };
        }
    },

    /**
     * GET请求
     */
    async get(path, params = {}) {
        const url = new URL(FinanceDashboardConfig.buildApiUrl(path), window.location.origin);
        Object.keys(params).forEach(key => {
            if (params[key] !== undefined && params[key] !== null) {
                url.searchParams.append(key, params[key]);
            }
        });
        return this.request(url.toString());
    },

    /**
     * POST请求
     */
    async post(path, data = {}) {
        return this.request(FinanceDashboardConfig.buildApiUrl(path), {
            method: 'POST',
            body: JSON.stringify(data),
        });
    },

    /**
     * 获取工作台数据
     */
    async fetchDashboardData(params) {
        return this.post('finance_dashboard_data.php', params);
    },

    /**
     * 获取合同分期列表
     */
    async fetchInstallments(contractId) {
        return this.get('finance_contract_installments_list.php', { contract_id: contractId });
    },

    /**
     * 更新分期状态
     */
    async updateInstallmentStatus(installmentId, status) {
        return this.post('finance_installment_status_update.php', {
            id: installmentId,
            status: status,
        });
    },

    /**
     * 更新合同状态
     */
    async updateContractStatus(contractId, status) {
        return this.post('finance_contract_status_update.php', {
            id: contractId,
            status: status,
        });
    },

    /**
     * 删除合同
     */
    async deleteContract(contractId) {
        return this.post('finance_contract_delete.php', { id: contractId });
    },

    /**
     * 上传文件
     */
    async uploadFile(formData) {
        return fetch(FinanceDashboardConfig.buildApiUrl('installment_file_upload.php'), {
            method: 'POST',
            body: formData,
        }).then(r => r.json());
    }
};

// 兼容旧代码
window.FinanceDashboardApi = FinanceDashboardApi;
