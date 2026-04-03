/**
 * Finance Dashboard - Installments Component
 * 财务工作台分期展开组件
 */

const FinanceInstallmentsComponent = {
    /**
     * 展开/收起合同分期
     */
    async toggle(contractId) {
        const holder = document.querySelector(`tr[data-installments-holder="1"][data-contract-id="${contractId}"]`);
        if (!holder) return;
        
        const btn = document.querySelector(`.btnToggleInstallments[data-contract-id="${contractId}"]`);
        const isHidden = holder.classList.contains('d-none');
        
        // 如果已展开，则收起
        if (!isHidden) {
            holder.classList.add('d-none');
            if (btn) btn.textContent = '▾';
            return;
        }
        
        // 展开
        holder.classList.remove('d-none');
        if (btn) btn.textContent = '▴';
        
        // 如果已加载过，直接返回
        if (holder.getAttribute('data-loaded') === '1') return;
        
        // 加载分期数据
        const cell = holder.querySelector('td');
        if (!cell) return;
        
        cell.innerHTML = '<div class="text-muted small p-2">加载中...</div>';
        
        try {
            const res = await FinanceDashboardApi.fetchInstallments(contractId);
            
            if (!res.success) {
                cell.innerHTML = `<div class="text-danger small p-2">${res.message || '加载失败'}</div>`;
                return;
            }
            
            cell.innerHTML = FinanceTableComponent.renderInstallmentsTable(contractId, res.data || []);
            holder.setAttribute('data-loaded', '1');
            
            // 绑定文件上传事件
            this.bindFileUploadEvents(cell);
        } catch (error) {
            cell.innerHTML = `<div class="text-danger small p-2">加载失败: ${error.message}</div>`;
        }
    },

    /**
     * 绑定文件上传事件
     */
    bindFileUploadEvents(container) {
        container.querySelectorAll('.inst-file-input-sub').forEach(input => {
            input.addEventListener('change', async function() {
                const instId = this.dataset.installmentId;
                const files = this.files;
                if (!instId || !files || files.length === 0) return;
                
                const formData = new FormData();
                formData.append('installment_id', instId);
                for (let i = 0; i < files.length; i++) {
                    formData.append('files[]', files[i]);
                }
                
                try {
                    const res = await FinanceDashboardApi.uploadFile(formData);
                    if (res.success) {
                        FinanceDashboardUtils.showToast('上传成功', 'success');
                    } else {
                        FinanceDashboardUtils.showToast(res.message || '上传失败', 'error');
                    }
                } catch (error) {
                    FinanceDashboardUtils.showToast('上传失败: ' + error.message, 'error');
                }
            });
        });
    }
};

// 兼容旧代码
window.FinanceInstallmentsComponent = FinanceInstallmentsComponent;
window.toggleContractInstallments = function(contractId) {
    FinanceInstallmentsComponent.toggle(contractId);
};
