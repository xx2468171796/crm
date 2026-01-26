/**
 * Finance Dashboard - Table Component
 * 财务工作台表格渲染组件
 */

const FinanceTableComponent = {
    /**
     * 渲染合同行
     */
    renderContractRow(row, groupId = null) {
        const contractId = parseInt(row.contract_id || 0);
        const utils = FinanceDashboardUtils;
        
        let html = `<tr data-contract-row="1" data-contract-id="${contractId}"`;
        if (groupId) html += ` data-group-member="${groupId}"`;
        html += ` data-signer-name="${utils.escapeHtml(row.signer_name || row.sales_name || '')}"`;
        html += ` data-owner-name="${utils.escapeHtml(row.owner_name || '')}">`;
        
        // 1. 展开按钮列
        html += `<td><button type="button" class="btn btn-sm btn-outline-secondary btnToggleInstallments" data-contract-id="${contractId}">▾</button></td>`;
        
        // 2. 客户信息
        html += `<td>`;
        html += `<div>${utils.escapeHtml(row.customer_name || '')}</div>`;
        if (row.customer_group_name) {
            html += `<div class="small"><span class="badge bg-info text-white cursor-pointer" style="cursor:pointer;" onclick="FinanceTableComponent.copyGroupName('${utils.escapeHtml(row.customer_group_name)}')" title="点击复制群名称">${utils.escapeHtml(row.customer_group_name)}</span></div>`;
        }
        html += `<div class="small text-muted">${utils.escapeHtml(row.customer_code || '')} ${utils.escapeHtml(row.customer_mobile || '')}</div>`;
        html += `</td>`;
        
        // 3. 活动标签
        html += `<td>${utils.escapeHtml(row.activity_tag || '')}</td>`;
        
        // 4. 合同信息
        html += `<td>`;
        html += `<div>${utils.escapeHtml(row.contract_no || '')}</div>`;
        html += `<div class="small text-muted">${utils.escapeHtml(row.contract_title || '')}</div>`;
        html += `<div class="small text-muted">创建：${utils.formatDateTime(row.contract_create_time)}</div>`;
        html += `</td>`;
        
        // 5. 销售
        html += `<td>${utils.escapeHtml(row.sales_name || '')}</td>`;
        
        // 6. 创建人
        html += `<td>${utils.escapeHtml(row.owner_name || '')}</td>`;
        
        // 7. 分期数
        html += `<td>${parseInt(row.installment_count || 0)}</td>`;
        
        // 8-10. 金额
        html += `<td>${utils.formatMoney(row.total_due)}</td>`;
        html += `<td>${utils.formatMoney(row.total_paid)}</td>`;
        html += `<td>${utils.formatMoney(row.total_unpaid)}</td>`;
        
        // 11. 状态
        const statusLabel = row.status_label || row.contract_status || '-';
        const badge = utils.getStatusBadge(statusLabel);
        html += `<td>`;
        html += `<span class="badge bg-${badge}">${utils.escapeHtml(statusLabel)}</span>`;
        html += `<div class="small text-muted">最近收款：${utils.escapeHtml(row.last_received_date || '-')}</div>`;
        html += `</td>`;
        
        // 12. 附件
        html += `<td><span class="text-muted">-</span></td>`;
        
        // 13. 操作
        html += `<td>`;
        html += `<a href="index.php?page=finance_contract_detail&id=${contractId}" class="btn btn-sm btn-outline-secondary">合同详情</a> `;
        html += `<button type="button" class="btn btn-sm btn-outline-danger btnContractDelete" data-contract-id="${contractId}">删除</button>`;
        html += `</td>`;
        
        html += `</tr>`;
        
        // 分期明细隐藏行
        html += `<tr class="d-none" data-installments-holder="1" data-contract-id="${contractId}">`;
        html += `<td colspan="13" class="bg-light p-0"><div class="text-muted small p-2">加载中...</div></td>`;
        html += `</tr>`;
        
        return html;
    },

    /**
     * 渲染分期表格
     */
    renderInstallmentsTable(contractId, installments) {
        if (!installments || installments.length === 0) {
            return '<div class="text-muted small p-2">暂无分期数据</div>';
        }
        
        const utils = FinanceDashboardUtils;
        let html = '<table class="table table-sm table-bordered mb-0">';
        html += '<thead><tr>';
        html += '<th>期数</th><th>应收日期</th><th>应收金额</th><th>已收金额</th><th>未收金额</th><th>状态</th><th>操作</th>';
        html += '</tr></thead><tbody>';
        
        installments.forEach((inst, idx) => {
            const badge = utils.getStatusBadge(inst.status_label || '');
            html += `<tr>`;
            html += `<td>${idx + 1}</td>`;
            html += `<td>${utils.formatDate(inst.due_date)}</td>`;
            html += `<td>${utils.formatMoney(inst.amount_due)}</td>`;
            html += `<td>${utils.formatMoney(inst.amount_paid)}</td>`;
            html += `<td>${utils.formatMoney(inst.amount_unpaid)}</td>`;
            html += `<td><span class="badge bg-${badge}">${utils.escapeHtml(inst.status_label || '-')}</span></td>`;
            html += `<td>`;
            html += `<button class="btn btn-sm btn-outline-warning btnInstallmentStatus" data-installment-id="${inst.id}">改状态</button>`;
            html += `</td>`;
            html += `</tr>`;
        });
        
        html += '</tbody></table>';
        return html;
    },

    /**
     * 渲染汇总信息
     */
    renderSummary(summary) {
        if (!summary) return;
        
        const el1 = document.getElementById('summaryContractCount');
        if (el1) el1.textContent = summary.contract_count || 0;
        
        const el2 = document.getElementById('summarySumDue');
        if (el2) el2.textContent = FinanceDashboardUtils.formatMoney(summary.sum_due);
        
        const el3 = document.getElementById('summarySumPaid');
        if (el3) el3.textContent = FinanceDashboardUtils.formatMoney(summary.sum_paid);
        
        const el4 = document.getElementById('summarySumUnpaid');
        if (el4) el4.textContent = FinanceDashboardUtils.formatMoney(summary.sum_unpaid);
    },

    /**
     * 复制群名称到剪贴板
     */
    copyGroupName(groupName) {
        if (!groupName) return;
        navigator.clipboard.writeText(groupName).then(() => {
            // 显示复制成功提示
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `<div class="toast show" role="alert">
                <div class="toast-body bg-success text-white rounded">
                    已复制: ${groupName}
                </div>
            </div>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2000);
        }).catch(err => {
            console.error('复制失败:', err);
            alert('复制失败，请手动复制: ' + groupName);
        });
    }
};

// 兼容旧代码
window.FinanceTableComponent = FinanceTableComponent;
