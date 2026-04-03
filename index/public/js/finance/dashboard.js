/**
 * Finance Dashboard - Main Entry Point
 * 财务工作台主入口模块
 */

const FinanceDashboard = {
    /**
     * 初始化
     */
    init() {
        console.log('[FinanceDashboard] 初始化开始');
        
        // 初始化配置
        FinanceDashboardConfig.init();
        
        // 绑定事件委托
        this.bindGlobalEvents();
        
        // 如果是合同视图，启用Ajax模式
        if (FinanceDashboardConfig.viewMode === 'contract') {
            console.log('[FinanceDashboard] 启用Ajax模式');
            this.initAjaxMode();
        }
        
        console.log('[FinanceDashboard] 初始化完成');
    },

    /**
     * 绑定全局事件委托
     */
    bindGlobalEvents() {
        document.addEventListener('click', (e) => {
            const target = e.target;
            if (!target) return;
            
            // 展开/收起按钮
            const toggleBtn = target.closest('.btnToggleInstallments');
            if (toggleBtn) {
                const contractId = Number(toggleBtn.dataset.contractId || 0);
                if (contractId) {
                    e.preventDefault();
                    FinanceInstallmentsComponent.toggle(contractId);
                }
                return;
            }
            
            // 合同行点击展开
            const contractRow = target.closest('tr[data-contract-row="1"][data-contract-id]');
            if (contractRow && !target.closest('a,button,input,select,textarea,label,.inst-file-thumb')) {
                const contractId = Number(contractRow.dataset.contractId || 0);
                if (contractId) {
                    FinanceInstallmentsComponent.toggle(contractId);
                }
                return;
            }
            
            // 删除合同按钮
            const deleteBtn = target.closest('.btnContractDelete');
            if (deleteBtn) {
                const contractId = Number(deleteBtn.dataset.contractId || 0);
                if (contractId) {
                    e.preventDefault();
                    this.handleDeleteContract(contractId);
                }
                return;
            }
            
            // 分期状态修改按钮
            const statusBtn = target.closest('.btnInstallmentStatus');
            if (statusBtn) {
                const installmentId = Number(statusBtn.dataset.installmentId || 0);
                if (installmentId) {
                    e.preventDefault();
                    // 使用finance-dashboard.js中的完整版openStatusModal（包含收款字段）
                    if (typeof openStatusModal === 'function') {
                        openStatusModal('installment', installmentId);
                    } else {
                        FinanceModalsComponent.showStatusModal('installment', installmentId);
                    }
                }
                return;
            }
            
            // 文件缩略图点击
            const fileThumb = target.closest('.inst-file-thumb, .inst-file-thumb-sub');
            if (fileThumb) {
                e.preventDefault();
                this.handleFileThumbClick(fileThumb);
                return;
            }
        });
    },

    /**
     * 处理删除合同
     */
    handleDeleteContract(contractId) {
        FinanceModalsComponent.showConfirmModal(
            '确认删除该合同？删除后不可恢复（将同时删除分期、收款等数据）',
            async () => {
                try {
                    const res = await FinanceDashboardApi.deleteContract(contractId);
                    if (res.success) {
                        FinanceDashboardUtils.showToast('删除成功', 'success');
                        if (typeof AjaxDashboard !== 'undefined') {
                            AjaxDashboard.reload();
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(res.message || '删除失败');
                    }
                } catch (error) {
                    alert('删除失败: ' + error.message);
                }
            }
        );
    },

    /**
     * 处理文件缩略图点击
     */
    handleFileThumbClick(thumb) {
        const filesJson = thumb.dataset.filesJson;
        const files = filesJson ? JSON.parse(filesJson) : [];
        
        if (files.length === 0) {
            const instId = thumb.dataset.installmentId;
            if (instId) {
                FinanceModalsComponent.showUploadModal(instId);
            }
            return;
        }
        
        // 灯箱预览
        const f = files[0];
        const isImage = /^image\//i.test(f.file_type);
        const url = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
        
        if (isImage && typeof showImageLightbox === 'function') {
            showImageLightbox(url, files);
        } else {
            window.open(url, '_blank');
        }
    },

    /**
     * 初始化Ajax模式
     */
    initAjaxMode() {
        // 初始加载数据
        if (typeof AjaxDashboard !== 'undefined') {
            AjaxDashboard.reload();
            
            // 绑定筛选器事件
            this.bindFilterEvents();
        }
    },

    /**
     * 绑定筛选器事件
     */
    bindFilterEvents() {
        const debounce = FinanceDashboardUtils.debounce;
        
        ['keyword', 'customerGroup', 'activityTag', 'status', 'dueStart', 'dueEnd'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', debounce(() => AjaxDashboard.reload()));
            }
        });
        
        ['salesUsers', 'ownerUsers'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => AjaxDashboard.reload());
            }
        });
        
        ['dashGroup1', 'dashGroup2'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => AjaxDashboard.reload());
            }
        });
    }
};

// DOM加载完成后初始化
document.addEventListener('DOMContentLoaded', () => {
    FinanceDashboard.init();
});

// 兼容旧代码
window.FinanceDashboard = FinanceDashboard;
