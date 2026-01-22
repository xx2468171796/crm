/**
 * Finance Dashboard JavaScript
 * 财务工作台前端逻辑
 */

// ==================== 配置 ====================
const DashboardConfig = {
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
    focusUserId: 0
};

// ==================== 汇率配置 ====================
let DashboardExchangeRate = {
    rates: {},  // 按货币代码存储汇率 { TWD: {fixed: 4.5, floating: 4.5}, USD: {...} }
    loaded: false
};

// 加载汇率
function loadDashboardExchangeRate() {
    if (DashboardExchangeRate.loaded) return Promise.resolve();
    return fetch((DashboardConfig.apiUrl || '/api') + '/exchange_rate_list.php')
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                res.data.forEach(c => {
                    DashboardExchangeRate.rates[c.code] = {
                        fixed: parseFloat(c.fixed_rate) || 1,
                        floating: parseFloat(c.floating_rate) || parseFloat(c.fixed_rate) || 1,
                        isBase: c.is_base
                    };
                });
                // CNY是基准货币，汇率为1
                if (!DashboardExchangeRate.rates['CNY']) {
                    DashboardExchangeRate.rates['CNY'] = { fixed: 1, floating: 1, isBase: true };
                }
            }
            DashboardExchangeRate.loaded = true;
        })
        .catch(() => { DashboardExchangeRate.loaded = true; });
}

// 获取货币汇率（转换到CNY）
function getCurrencyRate(currencyCode, useFloating = false) {
    const rates = DashboardExchangeRate.rates;
    const code = (currencyCode || 'TWD').toUpperCase();
    if (code === 'CNY') return 1;
    const rate = rates[code];
    if (!rate) return rates['TWD']?.fixed || 4.5;  // 默认用TWD汇率
    return useFloating ? rate.floating : rate.fixed;
}

// 将金额从原始货币转换到CNY
function convertToCNY(amount, currencyCode, useFloating = false) {
    const rate = getCurrencyRate(currencyCode, useFloating);
    return amount / rate;
}

// 根据汇率模式格式化金额（支持多货币）
function formatAmountByRate(amount, currencyCode) {
    const mode = document.getElementById('dashAmountMode')?.value || 'fixed';
    let result = amount;
    const code = (currencyCode || 'TWD').toUpperCase();
    
    if (mode === 'original') {
        // 原始金额不转换
    } else if (mode === 'fixed') {
        result = convertToCNY(amount, code, false);
    } else {
        result = convertToCNY(amount, code, true);
    }
    
    return result.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// 兼容旧调用（假设TWD）
function formatAmountByRateTWD(amountTWD) {
    return formatAmountByRate(amountTWD, 'TWD');
}

// 更新所有分组合计显示（汇率切换时调用）
function updateGroupSumsDisplay() {
    const mode = document.getElementById('dashAmountMode')?.value || 'fixed';
    const useFloating = (mode === 'floating');
    const targetCurrency = (mode === 'original') ? 'TWD' : 'CNY';
    
    document.querySelectorAll('tr.dash-group-row[data-by-currency]').forEach(header => {
        const byCurrencyStr = header.getAttribute('data-by-currency') || '{}';
        let byCurrency = {};
        try { byCurrency = JSON.parse(byCurrencyStr); } catch(e) {}
        
        // 按目标货币汇总
        let sumDue = 0, sumPaid = 0, sumUnpaid = 0;
        Object.keys(byCurrency).forEach(code => {
            const data = byCurrency[code];
            const rate = getExchangeRate(code, useFloating);
            if (targetCurrency === 'TWD') {
                // 转换到TWD
                const twdRate = getExchangeRate('TWD', useFloating);
                sumDue += (data.sum_due / rate) * twdRate;
                sumPaid += (data.sum_paid / rate) * twdRate;
                sumUnpaid += (data.sum_unpaid / rate) * twdRate;
            } else {
                // 转换到CNY
                sumDue += data.sum_due / rate;
                sumPaid += data.sum_paid / rate;
                sumUnpaid += data.sum_unpaid / rate;
            }
        });
        
        const fmt = (v) => v.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const dueEl = header.querySelector('.group-sum-due');
        const paidEl = header.querySelector('.group-sum-paid');
        const unpaidEl = header.querySelector('.group-sum-unpaid');
        
        if (dueEl) dueEl.textContent = fmt(sumDue) + ' ' + targetCurrency;
        if (paidEl) paidEl.textContent = fmt(sumPaid) + ' ' + targetCurrency;
        if (unpaidEl) unpaidEl.textContent = fmt(sumUnpaid) + ' ' + targetCurrency;
    });
}

// 获取汇率（相对于CNY）
function getExchangeRate(code, useFloating) {
    if (code === 'CNY') return 1;
    const r = DashboardExchangeRate.rates[code] || DashboardExchangeRate.rates['TWD'] || { fixed: 4.5, floating: 4.5 };
    return useFloating ? r.floating : r.fixed;
}

// ==================== 工具函数 ====================
function apiUrl(path) {
    return DashboardConfig.apiUrl + '/' + path;
}

// 收款方式英文转中文映射
const PaymentMethodLabels = {
    'cash': '现金',
    'transfer': '转账',
    'wechat': '微信',
    'alipay': '支付宝',
    'pos': 'POS',
    'other': '其他',
    'taiwanxu': '台湾徐',
    'prepay': '预付款',
    'zhongguopaypal': '中国PayPal',
    'guoneiweixin': '国内微信',
    'guoneiduigong': '国内对公',
    'xiapi': '下批'
};

function getPaymentMethodLabel(code) {
    if (!code) return '-';
    return PaymentMethodLabels[code] || code;
}

// 上传弹窗
function showUploadModal(installmentId, onSuccess) {
    const existing = document.getElementById('uploadModal');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'uploadModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:8px;padding:30px;width:500px;max-width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
    
    modal.innerHTML = `
        <h5 style="margin-bottom:20px;font-weight:600;">上传收款凭证</h5>
        <div id="uploadDropZone" style="border:2px dashed #ccc;border-radius:8px;padding:40px 20px;cursor:pointer;transition:all 0.2s;">
            <div style="width:60px;height:60px;background:#3b82f6;border-radius:12px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;">
                <svg width="30" height="30" fill="white" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
            </div>
            <div style="font-size:16px;color:#333;margin-bottom:8px;">点击选择文件，或拖拽上传</div>
            <div style="font-size:13px;color:#999;">支持 jpg、png、gif、pdf 格式，单次可选多个文件</div>
            <div style="font-size:13px;color:#999;margin-top:5px;">也可以 <strong>Ctrl+V</strong> 粘贴截图</div>
        </div>
        <input type="file" id="uploadFileInput" multiple accept="image/*,.pdf" style="display:none;">
        <div id="uploadProgress" style="margin-top:15px;display:none;">
            <div style="color:#3b82f6;">上传中...</div>
        </div>
        <div style="margin-top:20px;">
            <button type="button" id="uploadCancelBtn" style="padding:8px 20px;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;">取消</button>
        </div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const dropZone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadFileInput');
    const progressEl = document.getElementById('uploadProgress');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    
    // 点击选择文件
    dropZone.onclick = () => fileInput.click();
    
    // 拖拽
    dropZone.ondragover = (e) => { e.preventDefault(); dropZone.style.borderColor = '#3b82f6'; dropZone.style.background = '#f0f7ff'; };
    dropZone.ondragleave = () => { dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; };
    dropZone.ondrop = (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = '';
        if (e.dataTransfer.files.length > 0) doUpload(e.dataTransfer.files);
    };
    
    // 文件选择
    fileInput.onchange = () => { if (fileInput.files.length > 0) doUpload(fileInput.files); };
    
    // 粘贴
    const pasteHandler = (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        const files = [];
        for (let i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                const f = items[i].getAsFile();
                if (f) files.push(f);
            }
        }
        if (files.length > 0) doUpload(files);
    };
    document.addEventListener('paste', pasteHandler);
    
    // 取消/关闭
    cancelBtn.onclick = () => { cleanup(); };
    overlay.onclick = (e) => { if (e.target === overlay) cleanup(); };
    const escHandler = (e) => { if (e.key === 'Escape') cleanup(); };
    document.addEventListener('keydown', escHandler);
    
    function cleanup() {
        document.removeEventListener('paste', pasteHandler);
        document.removeEventListener('keydown', escHandler);
        overlay.remove();
    }
    
    function doUpload(files) {
        progressEl.style.display = 'block';
        const fd = new FormData();
        fd.append('installment_id', installmentId);
        for (let i = 0; i < files.length; i++) {
            fd.append('files[]', files[i]);
        }
        fetch(DashboardConfig.apiUrl + '/finance_installment_file_upload.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                cleanup();
                if (!res.success) {
                    showAlertModal(res.message || '上传失败', 'error');
                    return;
                }
                showAlertModal('上传成功', 'success', onSuccess || (() => location.reload()));
            })
            .catch(() => {
                cleanup();
                showAlertModal('上传失败', 'error');
            });
    }
}

// 单文件灯箱预览
function showFileLightbox(fileId, filename) {
    const url = '/api/customer_file_stream.php?id=' + fileId + '&mode=preview';
    showImageLightbox(url, [{ file_id: fileId, file_type: 'image/jpeg', filename: filename }]);
}

// 灯箱预览图片
function showImageLightbox(url, files) {
    // 移除已有灯箱
    const existing = document.getElementById('imageLightbox');
    if (existing) existing.remove();
    
    let currentIndex = 0;
    const imageFiles = (files || []).filter(f => /^image\//i.test(f.file_type));
    if (imageFiles.length === 0) {
        imageFiles.push({ file_id: 0, url: url });
    }
    
    const overlay = document.createElement('div');
    overlay.id = 'imageLightbox';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;';
    
    const img = document.createElement('img');
    img.style.cssText = 'max-width:90%;max-height:80%;object-fit:contain;cursor:zoom-in;transition:transform 0.2s;';
    img.src = url;
    
    let scale = 1;
    img.onclick = function(e) {
        e.stopPropagation();
        scale = scale === 1 ? 2 : 1;
        img.style.transform = 'scale(' + scale + ')';
        img.style.cursor = scale === 1 ? 'zoom-in' : 'zoom-out';
    };
    
    const info = document.createElement('div');
    info.style.cssText = 'color:#fff;margin-top:10px;font-size:14px;';
    info.textContent = imageFiles.length > 1 ? ('1 / ' + imageFiles.length + ' - 点击图片放大，点击背景关闭') : '点击图片放大，点击背景关闭';
    
    // 导航按钮
    if (imageFiles.length > 1) {
        const prevBtn = document.createElement('button');
        prevBtn.innerHTML = '◀';
        prevBtn.style.cssText = 'position:absolute;left:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:30px;padding:10px 15px;cursor:pointer;border-radius:5px;';
        prevBtn.onclick = function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex - 1 + imageFiles.length) % imageFiles.length;
            const f = imageFiles[currentIndex];
            img.src = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
            info.textContent = (currentIndex + 1) + ' / ' + imageFiles.length + ' - 点击图片放大，点击背景关闭';
            scale = 1;
            img.style.transform = 'scale(1)';
        };
        overlay.appendChild(prevBtn);
        
        const nextBtn = document.createElement('button');
        nextBtn.innerHTML = '▶';
        nextBtn.style.cssText = 'position:absolute;right:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:30px;padding:10px 15px;cursor:pointer;border-radius:5px;';
        nextBtn.onclick = function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex + 1) % imageFiles.length;
            const f = imageFiles[currentIndex];
            img.src = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
            info.textContent = (currentIndex + 1) + ' / ' + imageFiles.length + ' - 点击图片放大，点击背景关闭';
            scale = 1;
            img.style.transform = 'scale(1)';
        };
        overlay.appendChild(nextBtn);
    }
    
    // 关闭按钮
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '✕';
    closeBtn.style.cssText = 'position:absolute;top:20px;right:20px;background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:24px;padding:5px 12px;cursor:pointer;border-radius:5px;';
    closeBtn.onclick = function(e) {
        e.stopPropagation();
        overlay.remove();
    };
    
    overlay.appendChild(img);
    overlay.appendChild(info);
    overlay.appendChild(closeBtn);
    overlay.onclick = function() { overlay.remove(); };
    
    // ESC关闭
    const escHandler = function(e) {
        if (e.key === 'Escape') {
            overlay.remove();
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);
    
    document.body.appendChild(overlay);
}

function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
}

function fmt2(n) {
    const x = Number(n || 0);
    return x.toFixed(2);
}

function pad2(n) {
    return String(n).padStart(2, '0');
}

function localDateYmd(d) {
    const dt = d instanceof Date ? d : new Date();
    return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate());
}

function localDateTimeYmdHiByUnixTs(tsSeconds) {
    const t = Number(tsSeconds || 0);
    if (!t) return '-';
    const dt = new Date(t * 1000);
    return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate()) + ' ' + pad2(dt.getHours()) + ':' + pad2(dt.getMinutes());
}

function formatRemain(sec) {
    const s = Math.max(0, Math.floor(sec));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return String(m) + '分' + String(r) + '秒';
}

function normalizeMonthByUnixTs(ts) {
    const t = Number(ts || 0);
    if (!t) return '';
    const d = new Date(t * 1000);
    const m = String(d.getMonth() + 1).padStart(2, '0');
    return String(d.getFullYear()) + '-' + m;
}

function normalizeMonthByDateStr(s) {
    const v = String(s || '').trim();
    if (!v || v.length < 7) return '';
    return v.slice(0, 7);
}

// ==================== 导出功能 ====================
function exportDashboard() {
    const params = new URLSearchParams();
    params.set('view_mode', document.querySelector('select[name="view_mode"]')?.value || 'contract');
    params.set('keyword', document.querySelector('input[name="keyword"]')?.value || '');
    params.set('customer_group', document.querySelector('input[name="customer_group"]')?.value || '');
    params.set('activity_tag', document.querySelector('input[name="activity_tag"]')?.value || '');
    params.set('status', document.querySelector('select[name="status"]')?.value || '');
    params.set('due_start', document.querySelector('input[name="due_start"]')?.value || '');
    params.set('due_end', document.querySelector('input[name="due_end"]')?.value || '');
    params.set('group_by', document.querySelector('select[name="group_by"]')?.value || document.querySelector('input[name="group_by"]')?.value || 'sales');
    params.set('focus_user_type', DashboardConfig.focusUserType);
    params.set('focus_user_id', String(DashboardConfig.focusUserId));
    
    const salesChecks = document.querySelectorAll('input[name="sales_user_ids[]"]:checked');
    salesChecks.forEach(el => params.append('sales_user_ids[]', el.value));
    
    const ownerChecks = document.querySelectorAll('input[name="owner_user_ids[]"]:checked');
    ownerChecks.forEach(el => params.append('owner_user_ids[]', el.value));
    
    window.location.href = apiUrl('finance_dashboard_export.php?' + params.toString());
}

// ==================== 筛选器功能 ====================
// Ajax动态筛选
function applyDashboardFilters() {
    const form = document.getElementById('dashFilterForm');
    if (!form) {
        console.error('筛选表单未找到');
        return;
    }
    
    // 收集所有筛选参数
    const params = new URLSearchParams();
    params.set('page', 'finance_dashboard');
    
    // 获取表单中的所有输入
    const viewMode = form.querySelector('select[name="view_mode"]')?.value || 'contract';
    params.set('view_mode', viewMode);
    
    const keyword = form.querySelector('input[name="keyword"]')?.value || '';
    if (keyword) params.set('keyword', keyword);
    
    const status = form.querySelector('select[name="status"]')?.value || '';
    if (status) params.set('status', status);
    
    const period = form.querySelector('select[name="period"]')?.value || '';
    if (period) params.set('period', period);
    
    const dueStart = form.querySelector('input[name="due_start"]')?.value || '';
    if (dueStart) params.set('due_start', dueStart);
    
    const dueEnd = form.querySelector('input[name="due_end"]')?.value || '';
    if (dueEnd) params.set('due_end', dueEnd);
    
    const perPage = form.querySelector('select[name="per_page"]')?.value || '20';
    params.set('per_page', perPage);
    
    const groupBy = form.querySelector('input[name="group_by"]')?.value || form.querySelector('select[name="group_by"]')?.value || 'sales';
    params.set('group_by', groupBy);
    
    const viewId = form.querySelector('input[name="view_id"]')?.value || '0';
    if (viewId && viewId !== '0') params.set('view_id', viewId);
    
    // 签约人筛选
    const salesChecks = form.querySelectorAll('input[name="sales_user_ids[]"]:checked');
    salesChecks.forEach(el => params.append('sales_user_ids[]', el.value));
    
    // 归属人筛选
    const ownerChecks = form.querySelectorAll('input[name="owner_user_ids[]"]:checked');
    ownerChecks.forEach(el => params.append('owner_user_ids[]', el.value));
    
    // 使用URL导航实现刷新（保持简单可靠）
    window.location.href = 'index.php?' + params.toString();
}

function getCurrentFilters() {
    const salesChecks = Array.from(document.querySelectorAll('input[name="sales_user_ids[]"]:checked'));
    const ownerChecks = Array.from(document.querySelectorAll('input[name="owner_user_ids[]"]:checked'));
    return {
        keyword: document.querySelector('input[name="keyword"]').value || '',
        customer_group: document.querySelector('input[name="customer_group"]').value || '',
        activity_tag: document.querySelector('input[name="activity_tag"]').value || '',
        status: document.querySelector('select[name="status"]').value || '',
        due_start: document.querySelector('input[name="due_start"]').value || '',
        due_end: document.querySelector('input[name="due_end"]').value || '',
        sales_user_ids: salesChecks.map(el => String(el.value || '')).filter(v => v !== ''),
        owner_user_ids: ownerChecks.map(el => String(el.value || '')).filter(v => v !== '')
    };
}

function buildUrlFromFilters(viewId, filters) {
    const params = new URLSearchParams();
    params.set('page', DashboardConfig.pageKey);
    if (viewId && Number(viewId) > 0) {
        params.set('view_id', String(viewId));
    }
    Object.keys(filters || {}).forEach(k => {
        const v = filters[k];
        if (Array.isArray(v)) {
            v.forEach(it => {
                const s = (it == null ? '' : String(it)).trim();
                if (s !== '') {
                    params.append(k + '[]', s);
                }
            });
            return;
        }
        const s = (v == null ? '' : String(v)).trim();
        if (s !== '') params.set(k, s);
    });
    return 'index.php?' + params.toString();
}

// ==================== 视图管理 ====================
function loadViews() {
    fetch(apiUrl('finance_saved_view_list.php?page_key=' + encodeURIComponent(DashboardConfig.pageKey)))
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '加载视图失败', 'error');
                return;
            }
            const select = document.getElementById('viewSelect');
            const views = res.data || [];
            const options = ['<option value="0">（不使用视图）</option>']
                .concat(views.map(v => {
                    const name = (v.is_default ? '【默认】' : '') + (v.name || ('视图#' + v.id));
                    return '<option value="' + esc(v.id) + '">' + esc(name) + '</option>';
                }));
            select.innerHTML = options.join('');
            if (DashboardConfig.initialViewId > 0) {
                select.value = String(DashboardConfig.initialViewId);
            } else {
                const def = views.find(v => Number(v.is_default) === 1);
                if (def && def.id) {
                    select.value = String(def.id);
                }
            }
        })
        .catch(() => {
            showAlertModal('加载视图失败，请查看控制台错误信息', 'error');
        });
}

// ==================== 合同删除 ====================
function applyContractDeleteHint(btn) {
    if (!btn) return;
    const cts = Number(btn.getAttribute('data-create-time') || 0);
    const sid = Number(btn.getAttribute('data-sales-user-id') || 0);
    const isSalesSelf = (DashboardConfig.currentRole === 'sales' && DashboardConfig.currentUserId > 0 && sid > 0 && DashboardConfig.currentUserId === sid);
    if (!isSalesSelf) {
        btn.title = '删除后不可恢复（将同时删除分期、收款等数据）';
        return;
    }
    if (cts <= 0) {
        btn.title = '员工仅可在合同创建10分钟内删除';
        return;
    }
    const remain = 600 - (DashboardConfig.serverNowTs - cts);
    if (remain > 0) {
        btn.title = '员工仅可在合同创建10分钟内删除，剩余 ' + formatRemain(remain);
    } else {
        btn.disabled = true;
        btn.title = '已超过10分钟，无法删除（仅经理/管理员可删除）';
    }
}

function submitContractDelete(contractId) {
    const fd = new FormData();
    fd.append('contract_id', String(contractId));
    fetch(apiUrl('finance_contract_delete.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '删除失败', 'error');
                return;
            }
            showAlertModal('合同已删除', 'success', function() {
                location.reload();
            });
        })
        .catch(() => {
            showAlertModal('删除失败，请查看控制台错误信息', 'error');
        });
}

// ==================== 状态弹窗 ====================
let statusModal = null;

function ensureStatusModal() {
    if (!statusModal) {
        statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
    }
    return statusModal;
}

function openStatusModal(entityType, entityId, presetStatus = '') {
    if (entityType === 'contract') {
        showAlertModal('合同已改为删除操作，请使用"删除"按钮', 'warning');
        return;
    }
    document.getElementById('statusEntityType').value = entityType;
    document.getElementById('statusEntityId').value = String(entityId);
    document.getElementById('statusReason').value = '';

    const select = document.getElementById('statusNewStatus');
    // 确保始终包含"已收"选项
    let opts = DashboardConfig.installmentStatusOptions || [];
    if (!opts.includes('已收')) {
        opts = ['待收', '催款', '已收'];
    }
    select.innerHTML = opts.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
    if (presetStatus) {
        select.value = presetStatus;
    }

    document.getElementById('statusModalTitle').textContent = entityType === 'contract' ? '调整合同状态' : '调整分期状态';
    ensureStatusModal().show();
    const dateEl = document.getElementById('receiptDate');
    if (dateEl) dateEl.value = '';
    syncReceiptDateVisibility();
}

function setToday(el) {
    const d = new Date();
    const pad = (v) => v.toString().padStart(2, '0');
    el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

let dashboardCollectorsLoaded = false;
let voucherFiles = [];
let voucherDropZoneInited = false;

function syncReceiptDateVisibility() {
    const type = document.getElementById('statusEntityType').value;
    const newStatus = document.getElementById('statusNewStatus').value || '';
    const wrap = document.getElementById('receiptDateWrap');
    const methodWrap = document.getElementById('receiptMethodWrap');
    const collectorWrap = document.getElementById('receiptCollectorWrap');
    const currencyWrap = document.getElementById('receiptCurrencyWrap');
    const amountWrap = document.getElementById('receiptAmountWrap');
    
    // 调试：检查模态框结构
    const modal = document.getElementById('statusModal');
    const modalBody = modal ? modal.querySelector('.modal-body') : null;
    console.log('[DASH-DEBUG] syncReceiptDateVisibility called, type:', type, 'newStatus:', newStatus);
    console.log('[DASH-DEBUG] modal:', !!modal, 'modalBody:', !!modalBody, 'modalBodyHTML:', modalBody ? modalBody.innerHTML.substring(0, 200) : 'N/A');
    console.log('[DASH-DEBUG] wrap:', !!wrap, 'currencyWrap:', !!currencyWrap, 'amountWrap:', !!amountWrap);
    
    if (!wrap) {
        console.log('[DASH-DEBUG] receiptDateWrap not found! All IDs in modal:', modal ? Array.from(modal.querySelectorAll('[id]')).map(el => el.id).join(', ') : 'modal not found');
        return;
    }
    const voucherWrap = document.getElementById('receiptVoucherWrap');
    if (type === 'installment' && newStatus === '已收') {
        console.log('[DASH-DEBUG] Showing receipt fields');
        wrap.style.display = '';
        if (methodWrap) methodWrap.style.display = '';
        if (collectorWrap) collectorWrap.style.display = '';
        if (currencyWrap) currencyWrap.style.display = '';
        if (amountWrap) amountWrap.style.display = '';
        if (voucherWrap) voucherWrap.style.display = '';
        const dateEl = document.getElementById('receiptDate');
        if (dateEl && !dateEl.value) setToday(dateEl);
        if (!dashboardCollectorsLoaded) loadDashboardCollectors();
        loadInstallmentAmountHint();
        initVoucherDropZone();
    } else {
        wrap.style.display = 'none';
        if (methodWrap) methodWrap.style.display = 'none';
        if (collectorWrap) collectorWrap.style.display = 'none';
        if (currencyWrap) currencyWrap.style.display = 'none';
        if (amountWrap) amountWrap.style.display = 'none';
        if (voucherWrap) {
            voucherWrap.style.display = 'none';
            voucherFiles = [];
            renderVoucherPreview();
        }
    }
}

function initVoucherDropZone() {
    if (voucherDropZoneInited) return;
    const dropZone = document.getElementById('voucherDropZone');
    const voucherInput = document.getElementById('voucherFileInput');
    const dropText = document.getElementById('voucherDropText');
    if (!dropZone || !voucherInput) return;
    voucherDropZoneInited = true;
    
    // 更新提示文字
    if (dropText) {
        dropText.innerHTML = '拖拽文件到此处、点击上传或 <strong>Ctrl+V</strong> 粘贴图片';
    }
    
    dropZone.addEventListener('click', () => voucherInput.click());
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#0d6efd';
        dropZone.style.background = '#f0f7ff';
    });
    dropZone.addEventListener('dragleave', () => {
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = 'transparent';
    });
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#ccc';
        dropZone.style.background = 'transparent';
        handleVoucherFiles(e.dataTransfer.files);
    });
    voucherInput.addEventListener('change', () => handleVoucherFiles(voucherInput.files));
    
    // Ctrl+V 粘贴图片功能
    document.addEventListener('paste', handleVoucherPaste);
}

function handleVoucherPaste(e) {
    // 只在模态框显示且凭证上传区域可见时处理
    const voucherWrap = document.getElementById('receiptVoucherWrap');
    if (!voucherWrap || voucherWrap.style.display === 'none') return;
    
    const items = e.clipboardData?.items;
    if (!items) return;
    
    const imageFiles = [];
    for (let i = 0; i < items.length; i++) {
        if (items[i].type.startsWith('image/')) {
            const file = items[i].getAsFile();
            if (file) imageFiles.push(file);
        }
    }
    
    if (imageFiles.length > 0) {
        e.preventDefault();
        handleVoucherFiles(imageFiles);
        // 高亮提示
        const dropZone = document.getElementById('voucherDropZone');
        if (dropZone) {
            dropZone.style.borderColor = '#198754';
            dropZone.style.background = '#d1e7dd';
            setTimeout(() => {
                dropZone.style.borderColor = '#ccc';
                dropZone.style.background = 'transparent';
            }, 300);
        }
    }
}

function handleVoucherFiles(files) {
    for (let f of files) {
        if (voucherFiles.length >= 5) break;
        voucherFiles.push(f);
    }
    renderVoucherPreview();
}

function renderVoucherPreview() {
    const voucherPreview = document.getElementById('voucherPreview');
    if (!voucherPreview) return;
    voucherPreview.innerHTML = '';
    voucherFiles.forEach((f, idx) => {
        const div = document.createElement('div');
        div.style.cssText = 'position:relative;width:60px;height:60px;border:1px solid #ddd;border-radius:4px;overflow:hidden;';
        if (f.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
            div.appendChild(img);
        } else {
            div.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:10px;color:#666;">PDF</div>';
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = '×';
        btn.style.cssText = 'position:absolute;top:0;right:0;width:18px;height:18px;border:none;background:#dc3545;color:#fff;font-size:12px;cursor:pointer;border-radius:0 0 0 4px;';
        btn.onclick = () => { voucherFiles.splice(idx, 1); renderVoucherPreview(); };
        div.appendChild(btn);
        voucherPreview.appendChild(div);
    });
}

function loadInstallmentAmountHint() {
    const entityId = document.getElementById('statusEntityId').value;
    const hintEl = document.getElementById('receiptAmountHint');
    const amountEl = document.getElementById('receiptAmount');
    if (!entityId || !hintEl) return;
    fetch(apiUrl('finance_installment_get.php?id=' + encodeURIComponent(entityId)))
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const d = res.data || {};
            const unpaid = Number(d.amount_unpaid || 0);
            hintEl.textContent = '（未收：' + unpaid.toFixed(2) + '）';
            if (amountEl && !amountEl.value) amountEl.value = unpaid.toFixed(2);
        })
        .catch(() => {});
}

function loadDashboardCollectors() {
    const select = document.getElementById('receiptCollector');
    if (!select) return;
    fetch(apiUrl('finance_collector_list.php'))
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const list = res.data.collectors || [];
            const currentId = res.data.current_user_id || 0;
            select.innerHTML = list.map(c => 
                '<option value="' + c.id + '"' + (c.id === currentId ? ' selected' : '') + '>' + esc(c.name) + '</option>'
            ).join('');
            dashboardCollectorsLoaded = true;
        })
        .catch(() => {});
}

function submitInstallmentReceipt(installmentId, receivedDate, method, note) {
    const collectorSelect = document.getElementById('receiptCollector');
    const collectorUserId = collectorSelect ? collectorSelect.value : '';
    const currencySelect = document.getElementById('receiptCurrency');
    const currency = currencySelect ? currencySelect.value : '';
    const amountInput = document.getElementById('receiptAmount');
    const customAmount = amountInput ? parseFloat(amountInput.value) : 0;
    
    const url = apiUrl('finance_installment_get.php?id=' + encodeURIComponent(String(installmentId)));
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '查询分期失败', 'error');
                return;
            }
            const d = res.data || {};
            const unpaid = Number(d.amount_unpaid || 0);
            const amountToReceive = (customAmount > 0) ? customAmount : unpaid;
            if (!(amountToReceive > 0.00001)) {
                showAlertModal('收款金额必须大于0', 'warning');
                return;
            }
            
            // 先保存收款记录
            const fd = new FormData();
            fd.append('installment_id', String(installmentId));
            fd.append('received_date', receivedDate);
            fd.append('amount_received', String(amountToReceive.toFixed(2)));
            fd.append('method', method || '');
            fd.append('note', note || '');
            if (collectorUserId) fd.append('collector_user_id', collectorUserId);
            if (currency) fd.append('currency', currency);
            
            fetch(apiUrl('finance_receipt_save.php'), { method: 'POST', body: fd })
                .then(r2 => r2.json())
                .then(res2 => {
                    if (!res2.success) {
                        showAlertModal(res2.message || '登记收款失败', 'error');
                        return;
                    }
                    
                    // 如果有凭证文件，上传凭证
                    if (voucherFiles.length > 0) {
                        const fd2 = new FormData();
                        fd2.append('installment_id', String(installmentId));
                        for (let i = 0; i < voucherFiles.length; i++) {
                            fd2.append('files[]', voucherFiles[i]);
                        }
                        fetch(apiUrl('finance_installment_file_upload.php'), { method: 'POST', body: fd2 })
                            .then(r3 => r3.json())
                            .then(res3 => {
                                if (!res3.success) {
                                    console.warn('[DASH-DEBUG] 凭证上传失败:', res3.message);
                                }
                            })
                            .catch(e => console.warn('[DASH-DEBUG] 凭证上传异常:', e));
                    }
                    
                    // 清空凭证文件
                    voucherFiles = [];
                    renderVoucherPreview();
                    
                    // 关闭模态框
                    try { ensureStatusModal().hide(); } catch (e) {}
                    
                    // 尝试动态更新UI
                    let uiUpdated = false;
                    try {
                        applyReceiptUI(installmentId, unpaid, receivedDate);
                        uiUpdated = true;
                    } catch (e) {
                        console.warn('[DASH-DEBUG] applyReceiptUI failed:', e.message);
                    }
                    
                    // 如果动态更新失败，使用Ajax刷新或局部刷新
                    if (!uiUpdated) {
                        if (typeof AjaxDashboard !== 'undefined') {
                            AjaxDashboard.reload();
                        } else {
                            location.reload();
                            return;
                        }
                    }
                    showAlertModal('已登记收款并更新为已收', 'success');
                })
                .catch(() => showAlertModal('登记收款失败，请查看控制台错误信息', 'error'));
        })
        .catch(() => showAlertModal('查询分期失败，请查看控制台错误信息', 'error'));
}

function applyReceiptUI(installmentId, appliedAmount, receivedDate) {
    const idStr = String(installmentId);
    const delta = Number(appliedAmount || 0);
    const rd = receivedDate || '';

    // 1) 分期视图：直接更新当前行
    const instRow = document.querySelector('tr[data-installment-id="' + idStr + '"]');
    if (instRow) {
        const tds = instRow.querySelectorAll('td');
        if (!tds || tds.length < 11) throw new Error('installment row td missing');

        const amountDue = Number(String(tds[6].textContent || '').replace(/,/g, ''));
        const oldPaid = Number(String(tds[7].textContent || '').replace(/,/g, ''));
        const oldUnpaid = Number(String(tds[8].textContent || '').replace(/,/g, ''));
        const overdueDays = parseInt(String(tds[9].textContent || '0'), 10) || 0;
        const wasOverdueUnpaid = overdueDays > 0 && oldUnpaid > 0.00001;

        const newPaid = oldPaid + delta;
        const newUnpaid = Math.max(0, amountDue - newPaid);
        tds[7].textContent = fmt2(newPaid);
        tds[8].textContent = fmt2(newUnpaid);

        const badgeSpan = tds[10].querySelector('span.badge');
        if (badgeSpan) {
            badgeSpan.textContent = '已收';
            Array.from(badgeSpan.classList).forEach(c => { if (c.startsWith('bg-')) badgeSpan.classList.remove(c); });
            badgeSpan.classList.add('bg-success');
        }

        if (rd) {
            const info = tds[10].querySelector('div.small.text-muted');
            if (info) info.textContent = '最近收款：' + rd;
        }
        return;
    }

    // 2) 合同视图：更新展开分期表行 + 同步合同汇总行
    const subRow = document.querySelector('.btnInstallmentStatus[data-installment-id="' + idStr + '"]')?.closest('tr');
    if (!subRow) throw new Error('sub installment row not found');
    const table = subRow.closest('table');
    const holder = subRow.closest('tr[data-installments-holder="1"]');
    const contractId = holder ? String(holder.getAttribute('data-contract-id') || '') : '';
    if (!table || !contractId) throw new Error('contract context missing');

    const tds = subRow.querySelectorAll('td');
    if (!tds || tds.length < 9) throw new Error('sub td missing');

    const amountDue = Number(String(tds[3].textContent || '').replace(/,/g, ''));
    const oldPaid = Number(String(tds[4].textContent || '').replace(/,/g, ''));
    const oldUnpaid = Number(String(tds[5].textContent || '').replace(/,/g, ''));
    const newPaid = oldPaid + delta;
    const newUnpaid = Math.max(0, amountDue - newPaid);
    tds[4].textContent = fmt2(newPaid);
    tds[5].textContent = fmt2(newUnpaid);

    if (rd) {
        const timeCell = tds[2];
        const lines = timeCell ? timeCell.querySelectorAll('div.small.text-muted') : null;
        if (lines && lines.length >= 2) {
            lines[1].textContent = '最近收款：' + rd;
        }
    }

    const badgeSpan = tds[7].querySelector('span.badge');
    if (badgeSpan) {
        badgeSpan.textContent = '已收';
        Array.from(badgeSpan.classList).forEach(c => { if (c.startsWith('bg-')) badgeSpan.classList.remove(c); });
        badgeSpan.classList.add('bg-success');
    }

    // 同步合同汇总行
    const contractRow = document.querySelector('tr[data-contract-row="1"][data-contract-id="' + contractId + '"]');
    if (contractRow) {
        const cTds = contractRow.querySelectorAll('td');
        if (cTds && cTds.length >= 11) {
            const oldCPaid = Number(String(cTds[8].textContent || '').replace(/,/g, ''));
            const oldCUnpaid = Number(String(cTds[9].textContent || '').replace(/,/g, ''));
            const newCPaid = oldCPaid + delta;
            const newCUnpaid = Math.max(0, oldCUnpaid - delta);
            cTds[8].textContent = fmt2(newCPaid);
            cTds[9].textContent = fmt2(newCUnpaid);

            const statusSpan = cTds[10].querySelector('span.badge');
            if (statusSpan) {
                if (newCUnpaid <= 0.00001) {
                    statusSpan.textContent = '已结清';
                    Array.from(statusSpan.classList).forEach(c => { if (c.startsWith('bg-')) statusSpan.classList.remove(c); });
                    statusSpan.classList.add('bg-success');
                }
            }
        }
    }
}

function submitStatusChange() {
    const entityType = document.getElementById('statusEntityType').value;
    const entityId = Number(document.getElementById('statusEntityId').value || 0);
    const newStatus = document.getElementById('statusNewStatus').value || '';
    const reason = (document.getElementById('statusReason').value || '').trim();
    if (!entityId) {
        showAlertModal('参数错误', 'error');
        return;
    }
    if (entityType === 'contract') {
        showAlertModal('合同已改为删除操作，请使用"删除"按钮', 'warning');
        return;
    }
    if (!newStatus) {
        showAlertModal('请选择状态', 'warning');
        return;
    }
    if (!reason) {
        showAlertModal('请填写原因', 'warning');
        return;
    }

    if (entityType === 'installment' && newStatus === '已收') {
        const dateEl = document.getElementById('receiptDate');
        const receivedDate = (dateEl && dateEl.value) ? dateEl.value : localDateYmd(new Date());
        const methodEl = document.getElementById('receiptMethod');
        const method = (methodEl && methodEl.value) ? methodEl.value : '';
        submitInstallmentReceipt(entityId, receivedDate, method, '状态改为已收：' + reason);
        return;
    }

    const fd = new FormData();
    if (entityType === 'contract') {
        fd.append('contract_id', String(entityId));
    } else {
        fd.append('installment_id', String(entityId));
    }
    fd.append('new_status', newStatus);
    fd.append('reason', reason);
    const api = entityType === 'contract' ? 'finance_contract_status_update.php' : 'finance_installment_status_update.php';
    fetch(apiUrl(api), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '提交失败', 'error');
                return;
            }
            showAlertModal('已更新状态', 'success', function() {
                try { ensureStatusModal().hide(); } catch (e) {}
                location.reload();
            });
        })
        .catch(() => {
            showAlertModal('提交失败，请查看控制台错误信息', 'error');
        });
}

// ==================== 合同分期展开 ====================
function renderInstallmentsTable(contractId, rows) {
    if (!rows || rows.length === 0) {
        return '<div class="text-muted small">该合同暂无分期</div>';
    }
    const head = '<div class="table-responsive">'
        + '<table class="table table-sm table-hover align-middle mb-0">'
        + '<thead><tr>'
        + '<th style="width:60px;">期数</th>'
        + '<th style="width:90px;">到期日</th>'
        + '<th style="width:100px;">签约时间</th>'
        + '<th style="width:90px;">收款时间</th>'
        + '<th style="width:70px;">收款方式</th>'
        + '<th style="width:70px;">收款人</th>'
        + '<th>应收</th>'
        + '<th>已收</th>'
        + '<th>未收</th>'
        + '<th style="width:50px;">逾期</th>'
        + '<th style="width:60px;">状态</th>'
        + '<th style="width:40px;">凭证</th>'
        + '<th style="width:100px;">操作</th>'
        + '</tr></thead><tbody>';
    const body = rows.map(r => {
        const due = Number(r.amount_due || 0);
        const paid = Number(r.amount_paid || 0);
        const unpaid = due - paid;
        const isFullyPaid = due > 0 && unpaid <= 0.00001;
        let label = '待收';
        const ms = String(r.manual_status || '').trim();
        if (ms) {
            label = ms;
        } else if (isFullyPaid) {
            label = '已收';
        } else if (paid > 0.00001 && unpaid > 0.00001) {
            label = '部分已收';
        } else {
            const dd = String(r.due_date || '');
            if (dd && !Number.isNaN(Date.parse(dd)) && dd < localDateYmd(new Date())) {
                label = '逾期';
            }
        }
        const badge = (label === '已收') ? 'success' : (label === '部分已收' ? 'warning' : (label === '催款' ? 'warning' : (label === '逾期' ? 'danger' : 'primary')));
        return '<tr data-installment-id="' + esc(r.id) + '">' 
            + '<td>' + esc('第' + String(r.installment_no || '') + '期') + '</td>'
            + '<td>' + esc(r.due_date || '') + '</td>'
            + '<td class="small text-muted">' + (r.contract_sign_date ? esc(r.contract_sign_date.replace('T', ' ').substring(0, 16)) : '-') + '</td>'
            + '<td class="small text-muted">' + (r.last_receipt_time ? esc(localDateTimeYmdHiByUnixTs(r.last_receipt_time)) : '-') + '</td>'
            + '<td class="small text-muted">' + esc(getPaymentMethodLabel(r.last_receipt_method || r.payment_method)) + '</td>'
            + '<td class="small text-muted">' + esc(r.collector_name || '-') + '</td>'
            + '<td>' + esc(Number(r.amount_due || 0).toFixed(2)) + '</td>'
            + '<td>' + esc(Number(r.amount_paid || 0).toFixed(2)) + '</td>'
            + '<td>' + esc(Number(r.amount_unpaid || 0).toFixed(2)) + '</td>'
            + '<td>' + esc(String(r.overdue_days || 0)) + '</td>'
            + '<td><span class="badge bg-' + esc(badge) + '">' + esc(label) + '</span></td>'
            + '<td>'
            + '<div class="inst-file-thumb-sub" data-installment-id="' + esc(r.id) + '" data-customer-id="' + esc(r.customer_id) + '" style="width:32px;height:32px;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:8px;color:#999;" title="点击查看/上传凭证">...</div>'
            + '<input type="file" class="inst-file-input-sub d-none" data-installment-id="' + esc(r.id) + '" multiple accept="image/*,.pdf">'
            + '</td>'
            + '<td>'
            + '<a href="index.php?page=customer_detail&id=' + esc(r.customer_id) + '#tab-finance" class="btn btn-sm btn-outline-primary">客户财务</a>'
            + (isFullyPaid ? '' : ' <button type="button" class="btn btn-sm btn-outline-warning btnInstallmentStatus" data-installment-id="' + esc(r.id) + '">改状态</button>')
            + '</td>'
            + '</tr>';
    }).join('');
    const foot = '</tbody></table></div>';
    return head + body + foot;
}

function toggleContractInstallments(contractId) {
    const holder = document.querySelector('tr[data-installments-holder="1"][data-contract-id="' + contractId + '"]');
    if (!holder) {
        console.error('[分期明细] holder不存在, contractId:', contractId);
        return;
    }
    const btn = document.querySelector('.btnToggleInstallments[data-contract-id="' + contractId + '"]');
    const isHidden = holder.classList.contains('d-none');
    if (!isHidden) {
        holder.classList.add('d-none');
        if (btn) btn.textContent = '▾';
        return;
    }
    holder.classList.remove('d-none');
    if (btn) btn.textContent = '▴';
    if (holder.getAttribute('data-loaded') === '1') return;
    const cell = holder.querySelector('td');
    if (!cell) {
        console.error('[分期明细] cell不存在, contractId:', contractId);
        return;
    }
    cell.innerHTML = '<div class="text-muted small p-2">加载中...</div>';
    const apiUrl = (DashboardConfig.apiUrl || '/api') + '/finance_contract_installments_list.php?contract_id=' + encodeURIComponent(contractId);
    console.log('[分期明细] 请求:', apiUrl);
    fetch(apiUrl)
        .then(r => {
            console.log('[分期明细] 响应状态:', r.status);
            if (!r.ok) {
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(res => {
            console.log('[分期明细] 响应数据:', res);
            if (!res.success) {
                cell.innerHTML = '<div class="text-danger small p-2">' + (res.message || '加载失败') + '</div>';
                return;
            }
            cell.innerHTML = renderInstallmentsTable(contractId, res.data || []);
            holder.setAttribute('data-loaded', '1');
            // 绑定文件上传事件
            cell.querySelectorAll('.inst-file-input-sub').forEach(function(input) {
                input.addEventListener('change', function() {
                    const instId = this.dataset.installmentId;
                    const files = this.files;
                    if (!instId || !files || files.length === 0) return;
                    const fd = new FormData();
                    fd.append('installment_id', instId);
                    for (let i = 0; i < files.length; i++) {
                        fd.append('files[]', files[i]);
                    }
                    fetch(DashboardConfig.apiUrl + '/finance_installment_file_upload.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (!res.success) {
                                showAlertModal(res.message || '上传失败', 'error');
                                return;
                            }
                            showAlertModal('上传成功', 'success', () => location.reload());
                        })
                        .catch(() => showAlertModal('上传失败', 'error'));
                    this.value = '';
                });
            });
            // 加载凭证缩略图
            cell.querySelectorAll('.inst-file-thumb-sub').forEach(function(thumb) {
                const instId = thumb.dataset.installmentId;
                if (!instId) return;
                fetch(DashboardConfig.apiUrl + '/finance_installment_files.php?installment_id=' + instId)
                    .then(r => r.json())
                    .then(res => {
                        const files = (res.success && res.data) ? res.data : [];
                        if (files.length === 0) {
                            thumb.innerHTML = '<span style="font-size:7px;text-align:center;">无</span>';
                            thumb.style.color = '#999';
                        } else {
                            const f = files[0];
                            const isImage = /^image\//i.test(f.file_type);
                            const url = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
                            if (isImage) {
                                thumb.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;border-radius:3px;">';
                            } else {
                                thumb.innerHTML = '<span style="font-size:12px;">📄</span>';
                            }
                            thumb.style.borderColor = '#28a745';
                            thumb.style.borderStyle = 'solid';
                        }
                        thumb.dataset.fileCount = files.length;
                        thumb.dataset.filesJson = JSON.stringify(files);
                    })
                    .catch(() => {
                        thumb.innerHTML = '<span style="font-size:7px;">失败</span>';
                    });
            });
        })
        .catch(err => {
            console.error('[分期明细] 加载失败:', err);
            cell.innerHTML = '<div class="text-danger small p-2">加载失败: ' + (err.message || '网络错误') + '</div>';
        });
}

// ==================== 分组排序 ====================
let dashSortKey = '';
let dashSortDir = 'asc';

function dashStatusRank(label) {
    const v = String(label || '').trim();
    const order = {
        '催款': 1,
        '逾期': 2,
        '待收': 3,
        '部分已收': 4,
        '已收': 5,
        '已收几期': 6,
        '剩余几期': 7,
        '已结清': 8,
        '作废': 99,
    };
    return order[v] || 50;
}

function dashGetGroupVal(el, key) {
    if (!key) return '';
    if (key === 'settlement_status') {
        // 按已结清/未结清分组
        const statusLabel = String(el.getAttribute('data-status-label') || '').trim();
        return (statusLabel === '已结清') ? '已结清' : '未结清';
    }
    if (key === 'status') return String(el.getAttribute('data-status-label') || '').trim() || '未知状态';
    if (key === 'create_month') {
        const m = normalizeMonthByUnixTs(el.getAttribute('data-create-time'));
        return m || '无创建时间';
    }
    if (key === 'receipt_month') {
        const m = normalizeMonthByDateStr(el.getAttribute('data-last-received-date'));
        return m || '未收款';
    }
    if (key === 'sales_user') {
        return String(el.getAttribute('data-signer-name') || el.getAttribute('data-sales-name') || '').trim() || '未分配签约人';
    }
    if (key === 'owner_user') {
        return String(el.getAttribute('data-owner-name') || '').trim() || '未分配归属人';
    }
    if (key === 'payment_method') {
        return String(el.getAttribute('data-payment-method') || '').trim() || '未收款';
    }
    // 添加调试信息
    console.log('[CASCADE_DEBUG] dashGetGroupVal unknown key:', key, 'el:', el);
    return '未知分组(' + key + ')';
}

function dashBuildGroupLabel(key, val) {
    if (key === 'settlement_status') return '结清状态：' + val;
    if (key === 'status') return '状态：' + val;
    if (key === 'create_month') return '创建：' + val;
    if (key === 'receipt_month') return '收款：' + val;
    if (key === 'sales_user') return '签约人：' + val;
    if (key === 'owner_user') return '归属人：' + val;
    if (key === 'payment_method') return '收款方式：' + val;
    return val;
}

// 计算分组金额汇总
function dashCalcGroupSums(items, viewMode) {
    let sumDue = 0, sumPaid = 0, sumUnpaid = 0, count = 0;
    if (viewMode === 'contract') {
        items.forEach(b => {
            const tr = b.head;
            const due = parseFloat(tr.getAttribute('data-total-due') || 0);
            const paid = parseFloat(tr.getAttribute('data-total-paid') || 0);
            sumDue += due;
            sumPaid += paid;
            count++;
        });
    } else {
        items.forEach(tr => {
            const due = parseFloat(tr.getAttribute('data-amount-due') || 0);
            const paid = parseFloat(tr.getAttribute('data-amount-paid') || 0);
            sumDue += due;
            sumPaid += paid;
            count++;
        });
    }
    sumUnpaid = sumDue - sumPaid;
    return { sumDue, sumPaid, sumUnpaid, count };
}

// 分组展开/收起状态
const dashGroupCollapsed = new Set();

function dashToggleGroup(groupId) {
    // [SIDEBAR_COLLAPSE_FIX] 临时禁用无限滚动，避免展开/收起时误触发加载
    if (typeof FinanceDashboardController !== 'undefined' && FinanceDashboardController.infiniteScrollObserver) {
        FinanceDashboardController.infiniteScrollObserver.disconnect();
    }
    
    const rows = document.querySelectorAll('[data-group-id="' + groupId + '"]');
    const header = document.querySelector('[data-group-header="' + groupId + '"]');
    const icon = header?.querySelector('.group-toggle-icon');
    if (dashGroupCollapsed.has(groupId)) {
        dashGroupCollapsed.delete(groupId);
        rows.forEach(r => {
            // 分期明细holder保持隐藏，只展开合同行
            if (!r.hasAttribute('data-installments-holder')) {
                r.classList.remove('d-none');
            }
        });
        if (icon) icon.textContent = '▾';
    } else {
        dashGroupCollapsed.add(groupId);
        rows.forEach(r => r.classList.add('d-none'));
        if (icon) icon.textContent = '▸';
    }
    
    // [SIDEBAR_COLLAPSE_FIX] 延迟重新启用无限滚动
    setTimeout(() => {
        if (typeof FinanceDashboardController !== 'undefined') {
            FinanceDashboardController.initInfiniteScroll();
        }
    }, 100);
}

// 分组内排序状态
let dashGroupSortKey = '';
let dashGroupSortDir = 'desc';

function dashGetSortVal(el, key) {
    if (!el) return '';
    if (key === 'create_time') return Number(el.getAttribute('data-create-time') || 0);
    if (key === 'receipt_time') return String(el.getAttribute('data-last-received-date') || '');
    if (key === 'status') return String(el.getAttribute('data-status-label') || '');
    return '';
}

function dashCompareEls(a, b) {
    const ia = parseInt(a.getAttribute('data-orig-index') || '0', 10) || 0;
    const ib = parseInt(b.getAttribute('data-orig-index') || '0', 10) || 0;
    if (!dashSortKey) return ia - ib;

    if (dashSortKey === 'create_time') {
        const va = Number(dashGetSortVal(a, dashSortKey) || 0);
        const vb = Number(dashGetSortVal(b, dashSortKey) || 0);
        const d = va - vb;
        return dashSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (dashSortKey === 'receipt_time') {
        const va = String(dashGetSortVal(a, dashSortKey) || '');
        const vb = String(dashGetSortVal(b, dashSortKey) || '');
        const da = va === '' ? '0000-00-00' : va;
        const db = vb === '' ? '0000-00-00' : vb;
        const d = da.localeCompare(db);
        return dashSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (dashSortKey === 'status') {
        const ra = dashStatusRank(dashGetSortVal(a, dashSortKey));
        const rb = dashStatusRank(dashGetSortVal(b, dashSortKey));
        const d = ra - rb;
        return dashSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    return ia - ib;
}

function dashRefreshView() {
    const table = document.getElementById('financeDashboardTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    tbody.querySelectorAll('tr.dash-group-row').forEach(r => r.remove());

    const g1 = (document.getElementById('dashGroup1')?.value || '').trim();
    const g2 = (document.getElementById('dashGroup2')?.value || '').trim();
    const groups = [g1, g2].filter(v => v);

    const colCount = table.querySelectorAll('thead th').length || 1;

    if (DashboardConfig.viewMode === 'contract') {
        const contractRows = Array.from(tbody.querySelectorAll('tr[data-contract-row="1"][data-contract-id]'));
        if (!contractRows.length) return;
        contractRows.forEach((tr, idx) => {
            if (!tr.getAttribute('data-orig-index')) tr.setAttribute('data-orig-index', String(idx));
        });
        const blocks = contractRows.map(tr => {
            const cid = String(tr.getAttribute('data-contract-id') || '');
            const holder = tbody.querySelector('tr[data-installments-holder="1"][data-contract-id="' + cid + '"]');
            return { head: tr, rows: holder ? [tr, holder] : [tr] };
        });
        blocks.forEach(b => b.rows.forEach(r => { try { r.remove(); } catch (e) {} }));

        const sortBlocks = (list) => list.slice().sort((a, b) => dashCompareEls(a.head, b.head));

        const groupOnce = (list, key) => {
            const m = new Map();
            list.forEach(b => {
                const gv = dashGetGroupVal(b.head, key);
                if (!m.has(gv)) m.set(gv, []);
                m.get(gv).push(b);
            });
            return Array.from(m.entries());
        };

        const appendBlocks = (list) => {
            list.forEach(b => {
                b.rows.forEach(r => {
                    // 确保分期明细holder保持隐藏
                    if (r.hasAttribute('data-installments-holder')) {
                        r.classList.add('d-none');
                    }
                    tbody.appendChild(r);
                });
            });
        };

        if (groups.length === 0) {
            appendBlocks(sortBlocks(blocks));
            return;
        }

        let groupCounter = 0;
        const build = (list, level) => {
            const key = groups[level];
            const entries = groupOnce(list, key);
            const ordered = entries.sort((a, b) => String(a[0]).localeCompare(String(b[0])));
            ordered.forEach(([val, items]) => {
                const groupId = 'g_' + (++groupCounter);
                const sums = dashCalcGroupSums(items, 'contract');
                
                // 优先使用后端传递的完整分组统计，如果不存在则使用当前页计算的数据
                const configEl = document.getElementById('dashboardConfig');
                let finalSums = sums;
                if (key === 'sales_user') {
                    const groupStatsJson = configEl?.getAttribute('data-group-stats');
                    if (groupStatsJson) {
                        try {
                            const groupStats = JSON.parse(groupStatsJson);
                            if (groupStats[val]) {
                                finalSums = {
                                    count: groupStats[val].count,
                                    sumDue: groupStats[val].sum_due,
                                    sumPaid: groupStats[val].sum_paid,
                                    sumUnpaid: groupStats[val].sum_unpaid
                                };
                            }
                        } catch (e) {
                            console.error('解析分组统计数据失败:', e);
                        }
                    }
                } else if (key === 'owner_user') {
                    const ownerGroupStatsJson = configEl?.getAttribute('data-owner-group-stats');
                    if (ownerGroupStatsJson) {
                        try {
                            const ownerGroupStats = JSON.parse(ownerGroupStatsJson);
                            if (ownerGroupStats[val]) {
                                finalSums = {
                                    count: ownerGroupStats[val].count,
                                    sumDue: ownerGroupStats[val].sum_due,
                                    sumPaid: ownerGroupStats[val].sum_paid,
                                    sumUnpaid: ownerGroupStats[val].sum_unpaid
                                };
                            }
                        } catch (e) {
                            console.error('解析归属人分组统计数据失败:', e);
                        }
                    }
                }
                
                const header = document.createElement('tr');
                header.className = 'table-light dash-group-row';
                header.setAttribute('data-group-header', groupId);
                header.setAttribute('data-sum-due', finalSums.sumDue);
                header.setAttribute('data-sum-paid', finalSums.sumPaid);
                header.setAttribute('data-sum-unpaid', finalSums.sumUnpaid);
                header.style.cursor = 'pointer';
                const td = document.createElement('td');
                td.colSpan = colCount;
                td.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                    + '<div class="fw-semibold"><span class="group-toggle-icon me-2">▾</span>' + esc(dashBuildGroupLabel(key, val)) + '</div>'
                    + '<div class="d-flex gap-3 align-items-center">'
                    + '<span class="small text-muted">' + String(finalSums.count || items.length) + ' 条</span>'
                    + '<span class="small">应收 <span class="text-dark fw-semibold group-sum-due">' + formatAmountByRate(finalSums.sumDue) + '</span></span>'
                    + '<span class="small">已收 <span class="text-success fw-semibold group-sum-paid">' + formatAmountByRate(finalSums.sumPaid) + '</span></span>'
                    + '<span class="small">未收 <span class="text-danger fw-semibold group-sum-unpaid">' + formatAmountByRate(finalSums.sumUnpaid) + '</span></span>'
                    + '<div class="btn-group btn-group-sm ms-2">'
                    + '<button type="button" class="btn btn-outline-secondary py-0 px-1 group-sort-btn" data-group-id="' + groupId + '" data-sort-key="due_date" title="按应收时间排序">期</button>'
                    + '<button type="button" class="btn btn-outline-secondary py-0 px-1 group-sort-btn" data-group-id="' + groupId + '" data-sort-key="receipt_time" title="按收款时间排序">收</button>'
                    + '</div>'
                    + '</div>'
                    + '</div>';
                header.appendChild(td);
                header.onclick = function(e) {
                    if (e.target.closest('.group-sort-btn')) return;
                    dashToggleGroup(groupId);
                };
                tbody.appendChild(header);

                if (level + 1 < groups.length) {
                    build(items, level + 1);
                } else {
                    const sorted = sortBlocks(items);
                    sorted.forEach(b => {
                        b.rows.forEach(r => {
                            r.setAttribute('data-group-id', groupId);
                            // 确保分期明细holder保持隐藏
                            if (r.hasAttribute('data-installments-holder')) {
                                r.classList.add('d-none');
                            }
                            tbody.appendChild(r);
                        });
                    });
                }
            });
        };

        build(blocks, 0);
        bindGroupSortButtons();
        return;
    }

    const rows = Array.from(tbody.querySelectorAll('tr[data-installment-id]'));
    if (!rows.length) return;
    rows.forEach((tr, idx) => {
        if (!tr.getAttribute('data-orig-index')) tr.setAttribute('data-orig-index', String(idx));
    });
    rows.forEach(tr => { try { tr.remove(); } catch (e) {} });

    const sortRows = (list) => list.slice().sort(dashCompareEls);

    if (groups.length === 0) {
        sortRows(rows).forEach(tr => tbody.appendChild(tr));
        return;
    }

    const groupOnce = (list, key) => {
        const m = new Map();
        list.forEach(tr => {
            const gv = dashGetGroupVal(tr, key);
            if (!m.has(gv)) m.set(gv, []);
            m.get(gv).push(tr);
        });
        return Array.from(m.entries());
    };

    let groupCounter = 0;
    const build = (list, level) => {
        const key = groups[level];
        const entries = groupOnce(list, key);
        const ordered = entries.sort((a, b) => String(a[0]).localeCompare(String(b[0])));
        ordered.forEach(([val, items]) => {
            const groupId = 'gi_' + (++groupCounter);
            const sums = dashCalcGroupSums(items, 'installment');
            const header = document.createElement('tr');
            header.className = 'table-light dash-group-row';
            header.setAttribute('data-group-header', groupId);
            header.setAttribute('data-sum-due', sums.sumDue);
            header.setAttribute('data-sum-paid', sums.sumPaid);
            header.setAttribute('data-sum-unpaid', sums.sumUnpaid);
            header.style.cursor = 'pointer';
            const td = document.createElement('td');
            td.colSpan = colCount;
            td.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                + '<div class="fw-semibold"><span class="group-toggle-icon me-2">▾</span>' + esc(dashBuildGroupLabel(key, val)) + '</div>'
                + '<div class="d-flex gap-3 align-items-center">'
                + '<span class="small text-muted">' + String(items.length) + ' 条</span>'
                + '<span class="small">应收 <span class="text-dark fw-semibold group-sum-due">' + formatAmountByRate(sums.sumDue) + '</span></span>'
                + '<span class="small">已收 <span class="text-success fw-semibold group-sum-paid">' + formatAmountByRate(sums.sumPaid) + '</span></span>'
                + '<span class="small">未收 <span class="text-danger fw-semibold group-sum-unpaid">' + formatAmountByRate(sums.sumUnpaid) + '</span></span>'
                + '<div class="btn-group btn-group-sm ms-2">'
                + '<button type="button" class="btn btn-outline-secondary py-0 px-1 group-sort-btn" data-group-id="' + groupId + '" data-sort-key="due_date" title="按应收时间排序">期</button>'
                + '<button type="button" class="btn btn-outline-secondary py-0 px-1 group-sort-btn" data-group-id="' + groupId + '" data-sort-key="receipt_time" title="按收款时间排序">收</button>'
                + '</div>'
                + '</div>'
                + '</div>';
            header.appendChild(td);
            header.onclick = function(e) {
                if (e.target.closest('.group-sort-btn')) return;
                dashToggleGroup(groupId);
            };
            tbody.appendChild(header);

            if (level + 1 < groups.length) {
                build(items, level + 1);
            } else {
                sortRows(items).forEach(tr => {
                    tr.setAttribute('data-group-id', groupId);
                    tbody.appendChild(tr);
                });
            }
        });
    };

    build(rows, 0);
    bindGroupSortButtons();
}

// 分组内排序按钮事件绑定
function bindGroupSortButtons() {
    document.querySelectorAll('.group-sort-btn').forEach(btn => {
        btn.onclick = function(e) {
            e.stopPropagation();
            const groupId = this.dataset.groupId;
            const sortKey = this.dataset.sortKey;
            const rows = Array.from(document.querySelectorAll('[data-group-id="' + groupId + '"]'));
            if (!rows.length) return;
            
            // 切换排序方向
            const currentDir = this.dataset.sortDir || 'desc';
            const newDir = currentDir === 'desc' ? 'asc' : 'desc';
            this.dataset.sortDir = newDir;
            
            // 更新按钮样式
            this.classList.toggle('btn-primary', true);
            this.classList.toggle('btn-outline-secondary', false);
            this.textContent = (sortKey === 'due_date' ? '期' : '收') + (newDir === 'asc' ? '↑' : '↓');
            
            // 排序
            rows.sort((a, b) => {
                let va, vb;
                if (sortKey === 'due_date') {
                    va = a.getAttribute('data-due-date') || '';
                    vb = b.getAttribute('data-due-date') || '';
                } else {
                    va = a.getAttribute('data-last-received-date') || '';
                    vb = b.getAttribute('data-last-received-date') || '';
                }
                const cmp = va.localeCompare(vb);
                return newDir === 'asc' ? cmp : -cmp;
            });
            
            // 重新插入行
            const header = document.querySelector('[data-group-header="' + groupId + '"]');
            if (header) {
                rows.forEach(r => header.parentNode.insertBefore(r, header.nextSibling));
                rows.reverse().forEach(r => header.parentNode.insertBefore(r, header.nextSibling));
            }
        };
    });
}

// ==================== 初始化 ====================
function initDashboard() {
    // 读取配置
    const configEl = document.getElementById('dashboardConfig');
    if (configEl) {
        DashboardConfig.apiUrl = configEl.dataset.apiUrl || '';
        DashboardConfig.viewMode = configEl.dataset.viewMode || 'contract';
        DashboardConfig.currentRole = configEl.dataset.currentRole || '';
        DashboardConfig.currentUserId = Number(configEl.dataset.currentUserId || 0);
        DashboardConfig.serverNowTs = Number(configEl.dataset.serverNowTs || 0);
        DashboardConfig.initialViewId = Number(configEl.dataset.initialViewId || 0);
        DashboardConfig.canReceipt = configEl.dataset.canReceipt === 'true';
        DashboardConfig.focusUserType = configEl.dataset.focusUserType || '';
        DashboardConfig.focusUserId = Number(configEl.dataset.focusUserId || 0);
        
        if (DashboardConfig.canReceipt) {
            DashboardConfig.installmentStatusOptions = ['待收', '催款', '已收'];
        }
    }
    
    // 加载汇率数据
    loadDashboardExchangeRate();

    // 时间周期自动填充日期
    document.getElementById('dashboardPeriodSelect')?.addEventListener('change', function() {
        const period = this.value;
        const dueStartInput = document.querySelector('input[name="due_start"]');
        const dueEndInput = document.querySelector('input[name="due_end"]');
        
        if (!dueStartInput || !dueEndInput) return;
        
        const now = new Date();
        const pad = (v) => String(v).padStart(2, '0');
        
        if (period === 'this_month') {
            const year = now.getFullYear();
            const month = now.getMonth() + 1;
            const firstDay = year + '-' + pad(month) + '-01';
            const lastDay = new Date(year, month, 0).getDate();
            const lastDayStr = year + '-' + pad(month) + '-' + pad(lastDay);
            dueStartInput.value = firstDay;
            dueEndInput.value = lastDayStr;
        } else if (period === 'last_month') {
            const lastMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const year = lastMonthDate.getFullYear();
            const month = lastMonthDate.getMonth() + 1;
            const firstDay = year + '-' + pad(month) + '-01';
            const lastDay = new Date(year, month, 0).getDate();
            const lastDayStr = year + '-' + pad(month) + '-' + pad(lastDay);
            dueStartInput.value = firstDay;
            dueEndInput.value = lastDayStr;
        } else if (period === '') {
            dueStartInput.value = '';
            dueEndInput.value = '';
        }
    });

    // 用户筛选器按钮更新
    (function() {
        const salesBtn = document.getElementById('salesUserFilterBtn');
        const ownerBtn = document.getElementById('ownerUserFilterBtn');
        function updateBtn(btn, selector) {
            if (!btn) return;
            const cnt = document.querySelectorAll(selector).length;
            btn.textContent = cnt > 0 ? ('已选' + String(cnt)) : '未选择';
        }
        document.querySelectorAll('input[name="sales_user_ids[]"]').forEach(el => {
            el.addEventListener('change', function() {
                updateBtn(salesBtn, 'input[name="sales_user_ids[]"]:checked');
            });
        });
        document.querySelectorAll('input[name="owner_user_ids[]"]').forEach(el => {
            el.addEventListener('change', function() {
                updateBtn(ownerBtn, 'input[name="owner_user_ids[]"]:checked');
            });
        });
    })();

    // 视图选择
    document.getElementById('viewSelect')?.addEventListener('change', function() {
        const vid = Number(this.value || 0);
        if (!vid) {
            location.href = 'index.php?page=' + DashboardConfig.pageKey;
            return;
        }
        location.href = buildUrlFromFilters(vid, {});
    });

    // 保存视图
    document.getElementById('btnSaveView')?.addEventListener('click', function() {
        const name = prompt('请输入视图名称（用于保存当前筛选）');
        if (!name) return;
        const filters = getCurrentFilters();
        const payload = new FormData();
        payload.append('page_key', DashboardConfig.pageKey);
        payload.append('name', name);
        payload.append('filters_json', JSON.stringify(filters));
        payload.append('is_default', '0');
        fetch(apiUrl('finance_saved_view_save.php'), {
            method: 'POST',
            body: payload
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '保存失败', 'error');
                return;
            }
            showAlertModal('已保存视图', 'success', function() {
                loadViews();
            });
        })
        .catch(() => {
            showAlertModal('保存失败，请查看控制台错误信息', 'error');
        });
    });

    // 删除视图
    document.getElementById('btnDeleteView')?.addEventListener('click', function() {
        const vid = Number(document.getElementById('viewSelect').value || 0);
        if (!vid) {
            showAlertModal('请先选择要删除的视图', 'warning');
            return;
        }
        showConfirmModal('确认删除该视图？', function() {
            const payload = new FormData();
            payload.append('id', String(vid));
            fetch(apiUrl('finance_saved_view_delete.php'), { method: 'POST', body: payload })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        showAlertModal(res.message || '删除失败', 'error');
                        return;
                    }
                    showAlertModal('已删除', 'success', function() {
                        location.href = 'index.php?page=' + DashboardConfig.pageKey;
                    });
                })
                .catch(() => {
                    showAlertModal('删除失败，请查看控制台错误信息', 'error');
                });
        });
    });

    // 设为默认视图
    document.getElementById('btnSetDefaultView')?.addEventListener('click', function() {
        const vid = Number(document.getElementById('viewSelect').value || 0);
        if (!vid) {
            showAlertModal('请先选择要设为默认的视图', 'warning');
            return;
        }
        const payload = new FormData();
        payload.append('id', String(vid));
        fetch(apiUrl('finance_saved_view_set_default.php'), { method: 'POST', body: payload })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showAlertModal(res.message || '设置失败', 'error');
                    return;
                }
                showAlertModal('已设为默认', 'success', function() {
                    loadViews();
                });
            })
            .catch(() => {
                showAlertModal('设置失败，请查看控制台错误信息', 'error');
            });
    });

    // 加载视图列表
    loadViews();

    // 合同删除提示
    document.querySelectorAll('.btnContractDelete').forEach(btn => applyContractDeleteHint(btn));

    // 状态变更事件
    document.addEventListener('change', function(e) {
        const t = e.target;
        if (t && t.id === 'statusNewStatus') {
            syncReceiptDateVisibility();
        }
    });

    // 全局点击事件代理
    document.addEventListener('click', function(e) {
        const target = e.target;
        if (!target) return;

        const submitBtn = target.closest ? target.closest('#btnSubmitStatus') : null;
        if (submitBtn) {
            e.preventDefault();
            submitStatusChange();
            return;
        }

        const delBtn = target.closest ? target.closest('.btnContractDelete') : null;
        if (delBtn) {
            const cid = Number(delBtn.getAttribute('data-contract-id') || 0);
            if (!cid) return;
            e.preventDefault();
            showConfirmModal('确认删除该合同？删除后不可恢复（将同时删除分期、收款等数据）', function() {
                submitContractDelete(cid);
            });
            return;
        }

        const toggleBtn = target.closest ? target.closest('.btnToggleInstallments') : null;
        console.log('[INFSC-DEBUG] 点击事件 - toggleBtn:', toggleBtn, 'target:', target.className);
        if (toggleBtn) {
            const cid = Number(toggleBtn.getAttribute('data-contract-id') || 0);
            console.log('[INFSC-DEBUG] 找到展开按钮 - contractId:', cid);
            if (!cid) {
                console.warn('[INFSC-DEBUG] contractId为0，跳过');
                return;
            }
            e.preventDefault();
            console.log('[INFSC-DEBUG] 调用toggleContractInstallments:', cid);
            toggleContractInstallments(cid);
            return;
        }

        const instBtn = target.closest ? (target.closest('.btnInstallmentStatus') || target.closest('.btnInstallmentStatusBadge')) : null;
        if (instBtn) {
            const id = Number(instBtn.getAttribute('data-installment-id') || 0);
            if (!id) return;
            e.preventDefault();
            openStatusModal('installment', id);
            return;
        }

        const instFileThumb = target.closest ? (target.closest('.inst-file-thumb') || target.closest('.inst-file-thumb-sub')) : null;
        if (instFileThumb) {
            e.preventDefault();
            const filesJson = instFileThumb.dataset.filesJson;
            const files = filesJson ? JSON.parse(filesJson) : [];
            if (files.length === 0) {
                // 无凭证时打开上传弹窗
                const instId = instFileThumb.dataset.installmentId;
                if (instId) {
                    showUploadModal(instId);
                }
                return;
            }
            // 灯箱预览
            const f = files[0];
            const isImage = /^image\//i.test(f.file_type);
            const url = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
            if (isImage) {
                showImageLightbox(url, files);
            } else {
                window.open(url, '_blank');
            }
            return;
        }

        const tr = target.closest ? target.closest('tr[data-contract-row="1"][data-contract-id]') : null;
        if (tr) {
            if (target.closest && target.closest('a,button,input,select,textarea,label,.inst-file-thumb')) {
                return;
            }
            const cid = Number(tr.getAttribute('data-contract-id') || 0);
            if (!cid) return;
            toggleContractInstallments(cid);
            return;
        }
    });

    // 直接给展开按钮绑定点击事件
    document.querySelectorAll('.btnToggleInstallments').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const cid = Number(this.getAttribute('data-contract-id') || 0);
            if (cid) toggleContractInstallments(cid);
        });
    });

    // 列显隐功能
    if (typeof initColumnToggle === 'function' && DashboardConfig.viewMode !== 'staff_summary') {
        const cols = DashboardConfig.viewMode === 'contract' 
            ? [
                { index: 1, name: '客户', default: true },
                { index: 2, name: '活动标签', default: true },
                { index: 3, name: '合同', default: true },
                { index: 4, name: '销售', default: true },
                { index: 5, name: '创建人', default: true },
                { index: 6, name: '分期数', default: true },
                { index: 7, name: '应收', default: true },
                { index: 8, name: '已收', default: true },
                { index: 9, name: '未收', default: true },
                { index: 10, name: '状态', default: true },
            ]
            : [
                { index: 0, name: '客户', default: true },
                { index: 1, name: '活动标签', default: true },
                { index: 2, name: '合同', default: true },
                { index: 3, name: '销售', default: true },
                { index: 4, name: '创建人', default: true },
                { index: 5, name: '创建时间', default: true },
                { index: 6, name: '到期日', default: true },
                { index: 7, name: '应收', default: true },
                { index: 8, name: '已收', default: true },
                { index: 9, name: '未收', default: true },
                { index: 10, name: '逾期天数', default: true },
                { index: 11, name: '状态', default: true },
            ];
        initColumnToggle({
            tableId: 'financeDashboardTable',
            storageKey: 'finance_dashboard_columns_' + DashboardConfig.viewMode,
            columns: cols,
            buttonContainer: '#dashColumnToggleContainer'
        });
    }

    // 加载分期凭证缩略图
    document.querySelectorAll('.inst-file-thumb').forEach(function(thumb) {
        const instId = thumb.dataset.installmentId;
        if (!instId) return;
        fetch(DashboardConfig.apiUrl + '/finance_installment_files.php?installment_id=' + instId)
            .then(r => r.json())
            .then(res => {
                const files = (res.success && res.data) ? res.data : [];
                if (files.length === 0) {
                    thumb.innerHTML = '<span style="font-size:8px;text-align:center;">点击<br>上传</span>';
                    thumb.style.color = '#999';
                } else {
                    const f = files[0];
                    const isImage = /^image\//i.test(f.file_type);
                    const url = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
                    if (isImage) {
                        thumb.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;border-radius:3px;">';
                    } else {
                        thumb.innerHTML = '<span style="font-size:14px;">📄</span>';
                    }
                    thumb.style.borderColor = '#28a745';
                    thumb.style.borderStyle = 'solid';
                }
                thumb.dataset.fileCount = files.length;
                thumb.dataset.filesJson = JSON.stringify(files);
            })
            .catch(() => {
                thumb.innerHTML = '<span style="font-size:8px;">失败</span>';
            });
    });

    // 排序按钮
    document.querySelectorAll('.dashSortBtn').forEach(btn => {
        btn.addEventListener('click', function() {
            const key = String(this.getAttribute('data-sort') || '');
            if (!key) return;
            if (dashSortKey === key) {
                dashSortDir = (dashSortDir === 'asc') ? 'desc' : 'asc';
            } else {
                dashSortKey = key;
                if (key === 'create_time' || key === 'receipt_time') dashSortDir = 'desc';
                else dashSortDir = 'asc';
            }
            dashRefreshView();
        });
    });

    // 分组下拉
    document.getElementById('dashGroup1')?.addEventListener('change', dashRefreshView);
    document.getElementById('dashGroup2')?.addEventListener('change', dashRefreshView);
    
    // 初始刷新视图
    if (DashboardConfig.viewMode !== 'staff_summary') {
        dashRefreshView();
    }
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', initDashboard);

// ==================== Ajax模块 ====================
const AjaxDashboard = {
    loading: false,
    currentRequest: null,
    debounceTimer: null,
    currentPage: 1,
    totalRecords: 0,
    loadedRecords: 0,
    allLoaded: false,
    infiniteScrollObserver: null,
    isAppendMode: false,

    initInfiniteScroll() {
        const container = document.getElementById('dashboardScrollContainer');
        const statusEl = document.getElementById('dashboardLoadMoreStatus');
        
        if (!container || !statusEl) {
            console.warn('[InfiniteScroll] 容器或状态元素未找到');
            return;
        }

        statusEl.style.display = 'block';

        if (this.infiniteScrollObserver) {
            this.infiniteScrollObserver.disconnect();
        }

        this.infiniteScrollObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !this.loading && !this.allLoaded) {
                    console.log('[InfiniteScroll] 触发加载下一页');
                    this.loadNextPage();
                }
            });
        }, {
            root: container,
            rootMargin: '100px',
            threshold: 0.1
        });

        this.infiniteScrollObserver.observe(statusEl);
        console.log('[InfiniteScroll] 已初始化 Intersection Observer');
    },

    async loadNextPage() {
        if (this.loading || this.allLoaded) return;
        
        this.currentPage += 1;
        this.isAppendMode = true;
        
        console.log(`[InfiniteScroll] 加载第 ${this.currentPage} 页`);
        const data = await this.fetchData({ page: this.currentPage });
        
        if (data) {
            this.renderTable(data);
        }
    },

    resetPagination() {
        console.log('[InfiniteScroll] 重置分页状态');
        this.currentPage = 1;
        this.loadedRecords = 0;
        this.allLoaded = false;
        this.isAppendMode = false;
        
        const container = document.getElementById('dashboardScrollContainer');
        if (container) {
            container.scrollTop = 0;
        }
    },

    async fetchData(options = {}) {
        if (this.loading) {
            console.log('[Ajax] 请求进行中，跳过');
            return null;
        }

        const configEl = document.getElementById('dashboardConfig');
        if (!configEl) return null;

        const viewMode = configEl.getAttribute('data-view-mode') || 'contract';
        const filters = this.collectFilters();
        const groupBy = this.collectGroupBy();
        const page = options.page || 1;
        const perPage = options.perPage || 999999;  // 一次性加载所有数据

        const payload = {
            viewMode,
            filters,
            groupBy,
            sortBy: options.sortBy || '',
            sortDir: options.sortDir || 'asc',
            page,
            perPage
        };

        const startTime = performance.now();
        console.log('[Ajax] 发起请求:', payload);
        this.loading = true;
        this.showLoading();

        try {
            const response = await fetch('/api/finance_dashboard_data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();
            const endTime = performance.now();
            const duration = Math.round(endTime - startTime);
            console.log(`[Ajax] 响应成功 (${duration}ms, 服务端: ${result.meta?.queryTime}ms):`, result);

            if (!result.success) {
                throw new Error(result.error || 'API返回失败');
            }

            this.loading = false;
            this.hideLoading();
            return result.data;

        } catch (error) {
            console.error('[Ajax] 请求失败:', error);
            this.loading = false;
            this.showError(error.message);
            return null;
        }
    },

    collectFilters() {
        const filters = {};
        
        const keyword = document.getElementById('keyword')?.value?.trim();
        if (keyword) filters.keyword = keyword;

        const customerGroup = document.getElementById('customerGroup')?.value?.trim();
        if (customerGroup) filters.customer_group = customerGroup;

        const activityTag = document.getElementById('activityTag')?.value?.trim();
        if (activityTag) filters.activity_tag = activityTag;

        const status = document.getElementById('status')?.value?.trim();
        if (status) filters.status = status;

        const dueStart = document.getElementById('dueStart')?.value?.trim();
        if (dueStart) filters.due_start = dueStart;

        const dueEnd = document.getElementById('dueEnd')?.value?.trim();
        if (dueEnd) filters.due_end = dueEnd;

        const salesSelect = document.getElementById('salesUsers');
        if (salesSelect) {
            const selected = Array.from(salesSelect.selectedOptions).map(opt => parseInt(opt.value)).filter(v => v > 0);
            if (selected.length) filters.sales_user_ids = selected;
        }

        const ownerSelect = document.getElementById('ownerUsers');
        if (ownerSelect) {
            const selected = Array.from(ownerSelect.selectedOptions).map(opt => parseInt(opt.value)).filter(v => v > 0);
            if (selected.length) filters.owner_user_ids = selected;
        }

        return filters;
    },

    collectGroupBy() {
        const groups = [];
        const group1 = document.getElementById('dashGroup1')?.value?.trim();
        if (group1) groups.push(group1);
        const group2 = document.getElementById('dashGroup2')?.value?.trim();
        if (group2) groups.push(group2);
        return groups;
    },

    showLoading() {
        if (!this.isAppendMode) {
            const tbody = document.getElementById('dashboardTableBody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="20" class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">加载中...</span></div></td></tr>';
            }
        } else {
            this.updateLoadStatus('loading');
        }
    },

    hideLoading() {
    },

    showError(message) {
        const tbody = document.getElementById('dashboardTableBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="20" class="text-center py-5 text-danger">加载失败: ${message}<br><button class="btn btn-sm btn-primary mt-2" onclick="AjaxDashboard.reload()">重试</button></td></tr>`;
        }
    },

    renderTable(data) {
        if (!data || !data.rows) return;

        const tbody = document.getElementById('dashboardTableBody');
        if (!tbody) return;

        this.totalRecords = data.total || 0;

        if (data.rows.length === 0 && !this.isAppendMode) {
            tbody.innerHTML = '<tr><td colspan="20" class="text-center py-5 text-muted">暂无数据</td></tr>';
            this.updateLoadStatus('nodata');
            return;
        }

        const groupBy = this.collectGroupBy();

        if (this.isAppendMode) {
            if (groupBy.length > 0 && groupBy[0] === 'sales_user') {
                this.appendGroupedRows(data, tbody);
            } else {
                this.appendFlatRows(data, tbody);
            }
        } else {
            if (groupBy.length > 0 && groupBy[0] === 'sales_user') {
                this.renderGroupedTable(data, tbody);
            } else {
                this.renderFlatTable(data, tbody);
            }
        }

        this.loadedRecords += data.rows.length;

        if (data.rows.length < 20) {
            this.allLoaded = true;
            this.updateLoadStatus('complete');
        } else {
            this.updateLoadStatus('hasmore');
        }

        this.renderSummary(data.summary);
        
        console.log('[INFSC-DEBUG] 渲染完成，检查按钮:', document.querySelectorAll('.btnToggleInstallments').length, '个');
    },

    appendFlatRows(data, tbody) {
        let html = '';
        data.rows.forEach(row => {
            html += this.renderContractRow(row);
        });
        tbody.insertAdjacentHTML('beforeend', html);
    },

    appendGroupedRows(data, tbody) {
        const existingRows = tbody.querySelectorAll('tr[data-contract-id]');
        let html = '';
        data.rows.forEach(row => {
            html += this.renderContractRow(row, null);
        });
        tbody.insertAdjacentHTML('beforeend', html);
    },

    updateLoadStatus(status) {
        const loadingEl = document.getElementById('loadingIndicator');
        const allLoadedEl = document.getElementById('allLoadedIndicator');
        const countEl = document.getElementById('loadedCountIndicator');
        const loadedCountSpan = document.getElementById('loadedCount');
        const totalCountSpan = document.getElementById('totalCount');

        if (!loadingEl || !allLoadedEl || !countEl) return;

        loadingEl.classList.add('d-none');
        allLoadedEl.classList.add('d-none');
        countEl.classList.add('d-none');

        if (status === 'loading') {
            loadingEl.classList.remove('d-none');
        } else if (status === 'complete' || status === 'nodata') {
            allLoadedEl.classList.remove('d-none');
        } else if (status === 'hasmore') {
            countEl.classList.remove('d-none');
            if (loadedCountSpan) loadedCountSpan.textContent = this.loadedRecords;
            if (totalCountSpan) totalCountSpan.textContent = this.totalRecords;
        }
    },

    renderGroupedTable(data, tbody) {
        const groups = {};
        data.rows.forEach(row => {
            const key = row.sales_name || '未分配销售';
            if (!groups[key]) groups[key] = [];
            groups[key].push(row);
        });

        const groupStats = data.groupStats || {};
        let html = '';
        let groupCounter = 0;
        
        // 获取汇率转换函数
        const mode = document.getElementById('dashAmountMode')?.value || 'fixed';
        const useFloating = (mode === 'floating');
        const targetCurrency = (mode === 'original') ? 'TWD' : 'CNY';
        
        const calcGroupSums = (byCurrency) => {
            let sumDue = 0, sumPaid = 0, sumUnpaid = 0;
            Object.keys(byCurrency || {}).forEach(code => {
                const data = byCurrency[code];
                const rate = getExchangeRate(code, useFloating);
                if (targetCurrency === 'TWD') {
                    const twdRate = getExchangeRate('TWD', useFloating);
                    sumDue += (data.sum_due / rate) * twdRate;
                    sumPaid += (data.sum_paid / rate) * twdRate;
                    sumUnpaid += (data.sum_unpaid / rate) * twdRate;
                } else {
                    sumDue += data.sum_due / rate;
                    sumPaid += data.sum_paid / rate;
                    sumUnpaid += data.sum_unpaid / rate;
                }
            });
            return { sumDue, sumPaid, sumUnpaid };
        };
        
        const fmt = (v) => v.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        Object.keys(groups).sort().forEach(groupName => {
            const items = groups[groupName];
            const statsData = groupStats[groupName] || {};
            const byCurrency = statsData.by_currency || {};
            
            // 如果没有by_currency数据，使用旧的方式计算
            let stats;
            if (Object.keys(byCurrency).length > 0) {
                const sums = calcGroupSums(byCurrency);
                stats = { count: statsData.count || items.length, ...sums };
            } else {
                stats = {
                    count: items.length,
                    sumDue: items.reduce((sum, r) => sum + parseFloat(r.total_due || 0), 0),
                    sumPaid: items.reduce((sum, r) => sum + parseFloat(r.total_paid || 0), 0),
                    sumUnpaid: items.reduce((sum, r) => sum + parseFloat(r.total_unpaid || 0), 0)
                };
            }

            const groupId = 'g_' + (++groupCounter);
            const byCurrencyJson = JSON.stringify(byCurrency).replace(/"/g, '&quot;');
            
            html += `<tr class="table-light dash-group-row" data-group-header="${groupId}" data-by-currency="${byCurrencyJson}" style="cursor:pointer;">`;
            html += `<td colspan="20">`;
            html += `<div class="d-flex justify-content-between align-items-center">`;
            html += `<div class="fw-semibold"><span class="group-toggle-icon me-2">▾</span>${groupName}</div>`;
            html += `<div class="d-flex gap-3 align-items-center">`;
            html += `<span class="small text-muted">${stats.count} 条</span>`;
            html += `<span class="small">应收 <span class="text-dark fw-semibold group-sum-due">${fmt(stats.sumDue)} ${targetCurrency}</span></span>`;
            html += `<span class="small">已收 <span class="text-success fw-semibold group-sum-paid">${fmt(stats.sumPaid)} ${targetCurrency}</span></span>`;
            html += `<span class="small">未收 <span class="text-danger fw-semibold group-sum-unpaid">${fmt(stats.sumUnpaid)} ${targetCurrency}</span></span>`;
            html += `</div></div></td></tr>`;

            items.forEach(row => {
                html += this.renderContractRow(row, groupId);
            });
        });

        tbody.innerHTML = html;

        Object.keys(groups).forEach((_, index) => {
            const groupId = 'g_' + (index + 1);
            const headerEl = document.querySelector(`[data-group-header="${groupId}"]`);
            if (headerEl) {
                headerEl.addEventListener('click', () => dashToggleGroup(groupId));
            }
        });
    },

    renderFlatTable(data, tbody) {
        console.log('[INFSC-DEBUG] renderFlatTable开始，行数:', data.rows.length);
        let html = '';
        data.rows.forEach((row, idx) => {
            const rowHtml = this.renderContractRow(row);
            console.log(`[INFSC-DEBUG] 第${idx}行HTML长度:`, rowHtml.length, 'contractId:', row.contract_id);
            html += rowHtml;
        });
        console.log('[INFSC-DEBUG] 总HTML长度:', html.length, '包含btnToggleInstallments:', html.includes('btnToggleInstallments'));
        tbody.innerHTML = html;
        console.log('[INFSC-DEBUG] innerHTML设置完成，tbody.children.length:', tbody.children.length);
    },

    renderContractRow(row, groupId = null) {
        const contractId = parseInt(row.contract_id || 0);
        let html = `<tr data-contract-row="1" data-contract-id="${contractId}"`;
        if (groupId) html += ` data-group-member="${groupId}"`;
        html += ` data-signer-name="${this.escapeHtml(row.signer_name || row.sales_name || '')}"`;
        html += ` data-owner-name="${this.escapeHtml(row.owner_name || '')}">`;
        
        // 1. 展开按钮列
        html += `<td><button type="button" class="btn btn-sm btn-outline-secondary btnToggleInstallments" data-contract-id="${contractId}">▾</button></td>`;
        
        // 2. 客户信息
        html += `<td>`;
        html += `<div>${this.escapeHtml(row.customer_name || '')}</div>`;
        html += `<div class="small text-muted">${this.escapeHtml(row.customer_code || '')} ${this.escapeHtml(row.customer_mobile || '')}</div>`;
        html += `</td>`;
        
        // 3. 活动标签
        html += `<td>${this.escapeHtml(row.activity_tag || '')}</td>`;
        
        // 4. 合同信息
        html += `<td>`;
        html += `<div>${this.escapeHtml(row.contract_no || '')}</div>`;
        html += `<div class="small text-muted">${this.escapeHtml(row.contract_title || '')}</div>`;
        const createTime = row.contract_create_time ? new Date(row.contract_create_time * 1000).toLocaleString('zh-CN', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'}) : '-';
        html += `<div class="small text-muted">创建：${createTime}</div>`;
        html += `</td>`;
        
        // 5. 销售
        html += `<td>${this.escapeHtml(row.sales_name || '')}</td>`;
        
        // 6. 创建人
        html += `<td>${this.escapeHtml(row.owner_name || '')}</td>`;
        
        // 7. 分期数
        html += `<td>${parseInt(row.installment_count || 0)}</td>`;
        
        // 8-10. 金额
        html += `<td>${parseFloat(row.total_due || 0).toFixed(2)}</td>`;
        html += `<td>${parseFloat(row.total_paid || 0).toFixed(2)}</td>`;
        html += `<td>${parseFloat(row.total_unpaid || 0).toFixed(2)}</td>`;
        
        // 11. 状态
        const statusLabel = row.status_label || row.contract_status || '-';
        const badge = this.getStatusBadge(statusLabel);
        html += `<td>`;
        html += `<span class="badge bg-${badge}">${this.escapeHtml(statusLabel)}</span>`;
        html += `<div class="small text-muted">最近收款：${this.escapeHtml(row.last_received_date || '-')}</div>`;
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

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    getStatusBadge(status) {
        if (['已结清', '已收'].includes(status)) return 'success';
        if (['部分已收', '催款'].includes(status)) return 'warning';
        if (['逾期'].includes(status)) return 'danger';
        if (['待收'].includes(status)) return 'primary';
        if (['作废'].includes(status)) return 'secondary';
        return 'secondary';
    },

    renderSummary(summary) {
        if (!summary) return;
        
        const el1 = document.getElementById('summaryContractCount');
        if (el1) el1.textContent = summary.contract_count || 0;
        
        const el2 = document.getElementById('summarySumDue');
        if (el2) el2.textContent = parseFloat(summary.sum_due || 0).toFixed(2);
        
        const el3 = document.getElementById('summarySumPaid');
        if (el3) el3.textContent = parseFloat(summary.sum_paid || 0).toFixed(2);
        
        const el4 = document.getElementById('summarySumUnpaid');
        if (el4) el4.textContent = parseFloat(summary.sum_unpaid || 0).toFixed(2);
    },

    renderPagination(total, current, perPage) {
        const paginationEl = document.getElementById('dashboardPagination');
        if (!paginationEl) return;

        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) {
            paginationEl.innerHTML = '';
            return;
        }

        let html = '<ul class="pagination mb-0">';
        
        if (current > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${current - 1}">上一页</a></li>`;
        }

        for (let i = 1; i <= Math.min(totalPages, 10); i++) {
            const active = i === current ? 'active' : '';
            html += `<li class="page-item ${active}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        if (current < totalPages) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${current + 1}">下一页</a></li>`;
        }

        html += '</ul>';
        paginationEl.innerHTML = html;

        paginationEl.querySelectorAll('a[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(link.getAttribute('data-page'));
                this.loadPage(page);
            });
        });
    },

    async loadPage(page) {
        const data = await this.fetchData({ page });
        if (data) {
            this.renderTable(data);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    },

    async reload() {
        this.resetPagination();
        const data = await this.fetchData({ page: 1 });
        if (data) {
            this.renderTable(data);
        }
    },

    debounce(func, delay = 300) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(func, delay);
    }
};

window.AjaxDashboard = AjaxDashboard;
window.syncReceiptDateVisibility = syncReceiptDateVisibility;
window.openStatusModal = openStatusModal;
