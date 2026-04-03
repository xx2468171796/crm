/**
 * Finance Dashboard - Modals Component
 * 财务工作台弹窗组件
 */

const FinanceModalsComponent = {
    /**
     * 显示上传弹窗
     */
    showUploadModal(installmentId, onSuccess) {
        const existing = document.getElementById('uploadModal');
        if (existing) existing.remove();
        
        const modal = document.createElement('div');
        modal.id = 'uploadModal';
        modal.className = 'modal fade show';
        modal.style.display = 'block';
        modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">上传凭证</h5>
                        <button type="button" class="btn-close" data-action="close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="file" id="uploadFileInput" class="form-control" multiple accept="image/*,.pdf">
                        <div id="uploadProgress" class="mt-2"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="close">取消</button>
                        <button type="button" class="btn btn-primary" data-action="upload">上传</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        modal.addEventListener('click', async (e) => {
            const action = e.target.dataset.action;
            if (action === 'close') {
                modal.remove();
            } else if (action === 'upload') {
                const fileInput = document.getElementById('uploadFileInput');
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('请选择文件');
                    return;
                }
                
                const formData = new FormData();
                formData.append('installment_id', installmentId);
                for (let i = 0; i < fileInput.files.length; i++) {
                    formData.append('files[]', fileInput.files[i]);
                }
                
                try {
                    const res = await FinanceDashboardApi.uploadFile(formData);
                    if (res.success) {
                        modal.remove();
                        onSuccess && onSuccess();
                        FinanceDashboardUtils.showToast('上传成功', 'success');
                    } else {
                        alert(res.message || '上传失败');
                    }
                } catch (error) {
                    alert('上传失败: ' + error.message);
                }
            }
        });
    },

    /**
     * 显示状态修改弹窗
     */
    showStatusModal(type, id) {
        const existing = document.getElementById('statusModal');
        if (existing) existing.remove();
        
        const options = type === 'installment' 
            ? FinanceDashboardConfig.installmentStatusOptions 
            : FinanceDashboardConfig.contractStatusOptions;
        
        const optionsHtml = options.map(opt => `<option value="${opt}">${opt}</option>`).join('');
        
        const modal = document.createElement('div');
        modal.id = 'statusModal';
        modal.className = 'modal fade show';
        modal.style.display = 'block';
        modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">修改状态</h5>
                        <button type="button" class="btn-close" data-action="close"></button>
                    </div>
                    <div class="modal-body">
                        <select id="statusSelect" class="form-select">
                            <option value="">请选择状态</option>
                            ${optionsHtml}
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="close">取消</button>
                        <button type="button" class="btn btn-primary" data-action="submit">确定</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        modal.addEventListener('click', async (e) => {
            const action = e.target.dataset.action;
            if (action === 'close') {
                modal.remove();
            } else if (action === 'submit') {
                const status = document.getElementById('statusSelect').value;
                if (!status) {
                    alert('请选择状态');
                    return;
                }
                
                try {
                    let res;
                    if (type === 'installment') {
                        res = await FinanceDashboardApi.updateInstallmentStatus(id, status);
                    } else {
                        res = await FinanceDashboardApi.updateContractStatus(id, status);
                    }
                    
                    if (res.success) {
                        modal.remove();
                        FinanceDashboardUtils.showToast('状态更新成功', 'success');
                        // 刷新数据
                        if (typeof AjaxDashboard !== 'undefined') {
                            AjaxDashboard.reload();
                        } else {
                            location.reload();
                        }
                    } else {
                        alert(res.message || '更新失败');
                    }
                } catch (error) {
                    alert('更新失败: ' + error.message);
                }
            }
        });
    },

    /**
     * 显示确认弹窗
     */
    showConfirmModal(message, onConfirm, onCancel) {
        if (confirm(message)) {
            onConfirm && onConfirm();
        } else {
            onCancel && onCancel();
        }
    }
};

// 兼容旧代码
window.FinanceModalsComponent = FinanceModalsComponent;
window.showUploadModal = function(installmentId, onSuccess) {
    FinanceModalsComponent.showUploadModal(installmentId, onSuccess);
};
// 注意：openStatusModal 由 finance-dashboard.js 提供完整实现（包含收款字段）
// 不要在这里覆盖它
