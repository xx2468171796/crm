<?php
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
requirePermission(PermissionCode::FINANCE_VIEW, false);

$canEdit = canOrAdmin(PermissionCode::FINANCE_EDIT);

layout_header('汇率管理');
finance_sidebar_start('exchange_rate');
?>

<style>
.currency-card {
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    transition: all 0.3s;
}
.currency-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.currency-card.is-base {
    background: linear-gradient(135deg, #667eea15, #764ba215);
    border-color: #667eea;
}
.rate-value {
    font-size: 24px;
    font-weight: bold;
    color: #1890ff;
}
.rate-label {
    font-size: 12px;
    color: #999;
}
.rate-diff {
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 4px;
}
.rate-diff.positive { background: #f6ffed; color: #52c41a; }
.rate-diff.negative { background: #fff1f0; color: #f5222d; }
.sync-info {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h4><i class="bi bi-currency-exchange me-2"></i>汇率管理</h4>
            <p class="text-muted mb-0">基准货币：人民币 (CNY)，所有汇率表示 1 CNY = X 外币</p>
        </div>
        <div class="col-auto">
            <?php if ($canEdit): ?>
            <button class="btn btn-primary" onclick="syncRates()">
                <i class="bi bi-arrow-repeat me-1"></i>立即同步汇率
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="sync-info">
        <div class="row align-items-center">
            <div class="col">
                <strong>汇率数据源：</strong>exchangerate-api.com（免费版）
                <span class="ms-3"><strong>同步频率：</strong>每10分钟自动更新</span>
            </div>
            <div class="col-auto">
                <span class="text-muted">最后同步：<span id="lastSyncTime">-</span></span>
            </div>
        </div>
    </div>

    <div class="row" id="currencyList">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-primary"></div>
            <p class="mt-2 text-muted">加载中...</p>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i>汇率变更历史</span>
            <select class="form-select form-select-sm" style="width:150px;" id="historyFilter" onchange="loadHistory()">
                <option value="">全部货币</option>
            </select>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>货币</th>
                            <th>类型</th>
                            <th>汇率</th>
                            <th>操作人</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr><td colspan="5" class="text-center py-3 text-muted">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editRateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">设置固定汇率</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>货币：<strong id="editCurrencyName"></strong> (<span id="editCurrencyCode"></span>)</p>
                <p class="text-muted small">当前浮动汇率：<span id="editFloatingRate">-</span></p>
                <div class="mb-3">
                    <label class="form-label">固定汇率 (1 CNY = ?)</label>
                    <input type="number" class="form-control" id="editFixedRate" step="0.0001" min="0.0001">
                    <div class="form-text">用于财务结算的固定汇率</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveFixedRate()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
let currencies = [];

document.addEventListener('DOMContentLoaded', function() {
    loadCurrencies();
    loadHistory();
});

function loadCurrencies() {
    fetch('<?= BASE_URL ?>/api/exchange_rate_list.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currencies = data.data;
                renderCurrencies();
                updateFilterOptions();
                updateLastSyncTime();
            }
        });
}

function renderCurrencies() {
    const container = document.getElementById('currencyList');
    let html = '';
    
    currencies.forEach(c => {
        const isBase = c.is_base;
        const diff = c.floating_rate && c.fixed_rate ? 
            ((c.fixed_rate - c.floating_rate) / c.floating_rate * 100).toFixed(2) : null;
        
        html += `
        <div class="col-md-4 col-lg-3">
            <div class="currency-card ${isBase ? 'is-base' : ''}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h5 class="mb-0">${c.symbol} ${c.code}</h5>
                        <small class="text-muted">${c.name}</small>
                    </div>
                    ${isBase ? '<span class="badge bg-primary">基准</span>' : ''}
                </div>
                
                <div class="row mt-3">
                    <div class="col-6">
                        <div class="rate-label">浮动汇率</div>
                        <div class="rate-value" style="font-size:18px;">${c.floating_rate ? c.floating_rate.toFixed(4) : '-'}</div>
                    </div>
                    <div class="col-6">
                        <div class="rate-label">固定汇率</div>
                        <div class="rate-value" style="font-size:18px;color:#52c41a;">${c.fixed_rate ? c.fixed_rate.toFixed(4) : '-'}</div>
                    </div>
                </div>
                
                ${diff !== null ? `
                <div class="mt-2">
                    <span class="rate-diff ${parseFloat(diff) >= 0 ? 'positive' : 'negative'}">
                        差异 ${diff > 0 ? '+' : ''}${diff}%
                    </span>
                </div>
                ` : ''}
                
                ${!isBase && canEdit ? `
                <div class="mt-3">
                    <button class="btn btn-sm btn-outline-primary" onclick="openEditModal('${c.code}')">
                        <i class="bi bi-pencil me-1"></i>设置固定汇率
                    </button>
                </div>
                ` : ''}
            </div>
        </div>`;
    });
    
    container.innerHTML = html;
}

function updateLastSyncTime() {
    const times = currencies.map(c => c.updated_at).filter(Boolean);
    if (times.length > 0) {
        const latest = Math.max(...times);
        document.getElementById('lastSyncTime').textContent = 
            currencies.find(c => c.updated_at === latest)?.updated_at_formatted || '-';
    }
}

function updateFilterOptions() {
    const select = document.getElementById('historyFilter');
    currencies.filter(c => !c.is_base).forEach(c => {
        select.innerHTML += `<option value="${c.code}">${c.code} - ${c.name}</option>`;
    });
}

function loadHistory() {
    const code = document.getElementById('historyFilter').value;
    let url = '<?= BASE_URL ?>/api/exchange_rate_history.php?limit=30';
    if (code) url += '&code=' + code;
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderHistory(data.data);
            }
        });
}

function renderHistory(history) {
    const tbody = document.getElementById('historyBody');
    if (history.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">暂无数据</td></tr>';
        return;
    }
    
    tbody.innerHTML = history.map(h => `
        <tr>
            <td><strong>${h.currency_code}</strong> ${h.currency_name || ''}</td>
            <td><span class="badge ${h.rate_type === 'fixed' ? 'bg-success' : 'bg-info'}">${h.rate_type === 'fixed' ? '固定' : '浮动'}</span></td>
            <td>${h.rate.toFixed(6)}</td>
            <td>${h.operator}</td>
            <td>${h.created_at_formatted}</td>
        </tr>
    `).join('');
}

function syncRates() {
    if (!confirm('确定要立即同步汇率吗？')) return;
    
    fetch('<?= BASE_URL ?>/api/exchange_rate_sync.php?manual=1')
        .then(r => r.json())
        .then(data => {
            alert(data.message);
            if (data.success) {
                loadCurrencies();
                loadHistory();
            }
        });
}

function openEditModal(code) {
    const c = currencies.find(x => x.code === code);
    if (!c) return;
    
    document.getElementById('editCurrencyCode').textContent = c.code;
    document.getElementById('editCurrencyName').textContent = c.name;
    document.getElementById('editFloatingRate').textContent = c.floating_rate ? c.floating_rate.toFixed(6) : '-';
    document.getElementById('editFixedRate').value = c.fixed_rate || c.floating_rate || '';
    
    new bootstrap.Modal(document.getElementById('editRateModal')).show();
}

function saveFixedRate() {
    const code = document.getElementById('editCurrencyCode').textContent;
    const fixedRate = parseFloat(document.getElementById('editFixedRate').value);
    
    if (!fixedRate || fixedRate <= 0) {
        alert('请输入有效的汇率');
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/exchange_rate_fixed_save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, fixed_rate: fixedRate })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('editRateModal')).hide();
            loadCurrencies();
            loadHistory();
        }
    });
}
</script>

<?php
finance_sidebar_end();
layout_footer();
?>
