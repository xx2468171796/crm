<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';
require_once __DIR__ . '/../core/finance_status.php';
require_once __DIR__ . '/../core/rbac.php';

// 货币汇率（相对于TWD）
function getExchangeRates(): array {
    return [
        'TWD' => 1.0,
        'CNY' => 4.5,    // 1 CNY = 4.5 TWD
        'USD' => 32.0,   // 1 USD = 32 TWD
    ];
}

function convertCurrency(float $amount, string $fromCurrency, string $toCurrency): float {
    if ($fromCurrency === $toCurrency) return $amount;
    $rates = getExchangeRates();
    $fromRate = $rates[$fromCurrency] ?? 1.0;
    $toRate = $rates[$toCurrency] ?? 1.0;
    return $amount * $fromRate / $toRate;
}

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CONTRACT_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限。</div>';
    layout_footer();
    exit;
}

$contractId = (int)($_GET['id'] ?? 0);
if ($contractId <= 0) {
    layout_header('参数错误');
    echo '<div class="alert alert-danger">参数错误：合同ID</div>';
    layout_footer();
    exit;
}

$contract = Db::queryOne(
    'SELECT c.*, cu.name AS customer_name, cu.customer_code, cu.mobile AS customer_mobile, cu.activity_tag, cu.owner_user_id, u.realname AS sales_name, signer.realname AS signer_name, owner.realname AS owner_name
     FROM finance_contracts c
     INNER JOIN customers cu ON cu.id = c.customer_id
     LEFT JOIN users u ON u.id = c.sales_user_id
     LEFT JOIN users signer ON signer.id = c.signer_user_id
     LEFT JOIN users owner ON owner.id = cu.owner_user_id
     WHERE c.id = :id
     LIMIT 1',
    ['id' => $contractId]
);

if (!$contract) {
    layout_header('合同不存在');
    echo '<div class="alert alert-danger">合同不存在</div>';
    layout_footer();
    exit;
}

if (($user['role'] ?? '') === 'sales' && (int)($contract['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限：只能查看自己名下客户的合同</div>';
    layout_footer();
    exit;
}

$contractCreateTime = (int)($contract['create_time'] ?? 0);
$contractSalesUserId = (int)($contract['sales_user_id'] ?? 0);
$serverNowTs = time();

$installments = Db::query(
    'SELECT i.*, ragg.last_received_date, ragg.last_receipt_time, ragg.last_receipt_method,
            (i.amount_due - i.amount_paid) AS amount_unpaid,
            CASE WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days,
            collector.realname AS collector_name
     FROM finance_installments i
     LEFT JOIN users collector ON collector.id = i.collector_user_id
     LEFT JOIN (
        SELECT r1.installment_id, r1.received_date AS last_received_date, r1.create_time AS last_receipt_time, r1.method AS last_receipt_method
        FROM finance_receipts r1
        INNER JOIN (
            SELECT installment_id, MAX(id) AS max_id
            FROM finance_receipts
            WHERE amount_applied > 0
            GROUP BY installment_id
        ) r2 ON r1.id = r2.max_id
     ) ragg ON ragg.installment_id = i.id
     WHERE i.contract_id = :cid AND i.deleted_at IS NULL
     ORDER BY i.installment_no ASC, i.id ASC',
    ['cid' => $contractId]
);

$unpaidDefaults = [];
foreach ($installments as $inst) {
    if ((float)($inst['amount_paid'] ?? 0) <= 0.00001) {
        $unpaidDefaults[] = [
            'due_date' => (string)($inst['due_date'] ?? ''),
            'amount_due' => (float)($inst['amount_due'] ?? 0),
        ];
    }
}

$files = Db::query(
    'SELECT fcf.file_id, cf.filename, cf.filesize, cf.mime_type, cf.preview_supported
     FROM finance_contract_files fcf
     INNER JOIN customer_files cf ON cf.id = fcf.file_id
     WHERE fcf.contract_id = :cid AND cf.deleted_at IS NULL AND cf.category = "internal_solution"
     ORDER BY fcf.id DESC',
    ['cid' => $contractId]
);

$salesUsers = [];
$allUsers = [];
if (!in_array(($user['role'] ?? ''), ['sales'], true)) {
    $salesUsers = Db::query('SELECT id, realname FROM users WHERE status = 1 AND role = "sales" ORDER BY realname ASC, id ASC');
    $allUsers = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname ASC, id ASC');
}

layout_header('合同详情');

?>

<style>
.contract-detail-card {
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    border: none;
    border-radius: 12px;
}
.contract-detail-card .card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 12px 12px 0 0;
    padding: 16px 20px;
}
.contract-detail-card .card-body {
    padding: 24px;
}
.contract-amount {
    font-size: 28px;
    font-weight: 700;
    color: #1a1a2e;
}
.contract-amount-currency {
    font-size: 14px;
    color: #666;
    margin-left: 4px;
}
.info-label {
    font-size: 12px;
    color: #8c8c8c;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.info-value {
    font-size: 15px;
    font-weight: 600;
    color: #1a1a2e;
}
.installment-table {
    border-radius: 8px;
    overflow: hidden;
}
.installment-table thead {
    background: #f8f9fa;
}
.installment-table thead th {
    font-weight: 600;
    font-size: 13px;
    color: #495057;
    padding: 14px 12px;
    border-bottom: 2px solid #dee2e6;
}
.installment-table tbody tr {
    transition: background 0.2s;
}
.installment-table tbody tr:hover {
    background: #f8f9fa;
}
.installment-table tbody td {
    padding: 16px 12px;
    vertical-align: middle;
}
.installment-table .amount-cell {
    text-align: right;
    font-family: 'SF Mono', 'Monaco', monospace;
    font-weight: 500;
}
.installment-table .converted-amount {
    font-size: 12px;
    color: #6c757d;
}
.badge-received {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.badge-pending {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.badge-overdue {
    background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.badge-partial {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: #fff;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.btn-back-customer {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
}
.btn-back-customer:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    color: #fff;
}
.page-header {
    background: #fff;
    padding: 20px 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 24px;
}
.page-title {
    font-size: 24px;
    font-weight: 700;
    color: #1a1a2e;
    margin: 0;
}
.breadcrumb-item {
    font-size: 13px;
}
</style>

<div class="page-header">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="index.php?page=finance_dashboard">财务工作台</a></li>
            <li class="breadcrumb-item"><a href="index.php?page=customer_detail&id=<?= (int)($contract['customer_id'] ?? 0) ?>"><?= htmlspecialchars($contract['customer_name'] ?? '') ?></a></li>
            <li class="breadcrumb-item active">合同详情</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h3 class="page-title"><?= htmlspecialchars($contract['title'] ?? '合同详情') ?></h3>
            <div class="text-muted small mt-1">
                <span class="me-3"><i class="bi bi-hash"></i> <?= htmlspecialchars($contract['contract_no'] ?? '') ?></span>
                <span><i class="bi bi-person"></i> <?= htmlspecialchars($contract['customer_name'] ?? '') ?>（<?= htmlspecialchars($contract['customer_code'] ?? '') ?>）</span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-back-customer" href="index.php?page=customer_detail&id=<?= (int)($contract['customer_id'] ?? 0) ?>">
                <i class="bi bi-arrow-left me-1"></i>返回客户详情
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard&view_mode=contract">财务工作台</a>
        </div>
    </div>
</div>

<div class="card mb-4 contract-detail-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold"><i class="bi bi-file-earmark-text me-2"></i>合同信息</div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-light btn-sm" id="btnEditContract"><i class="bi bi-pencil me-1"></i>编辑</button>
            <button type="button" class="btn btn-light btn-sm" id="btnDeleteContract" data-create-time="<?= (int)$contractCreateTime ?>" data-sales-user-id="<?= (int)$contractSalesUserId ?>"><i class="bi bi-trash me-1"></i>删除</button>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6 col-lg-4">
                <div class="p-3 rounded-3" style="background:#f8f9fa;">
                    <div class="info-label">合同金额</div>
                    <div class="contract-amount"><?= number_format((float)($contract['net_amount'] ?? 0), 2) ?><span class="contract-amount-currency"><?= htmlspecialchars($contract['currency'] ?? 'TWD') ?></span></div>
                </div>
            </div>
            <div class="col-md-6 col-lg-8">
                <div class="row g-3">
                    <div class="col-6 col-md-4">
                        <div class="info-label">合同签约人</div>
                        <div class="info-value"><?= htmlspecialchars($contract['signer_name'] ?? $contract['sales_name'] ?? '-') ?></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="info-label">销售负责人</div>
                        <div class="info-value"><?= htmlspecialchars($contract['sales_name'] ?? '-') ?></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="info-label">客户归属人</div>
                        <div class="info-value"><?= htmlspecialchars($contract['owner_name'] ?? '-') ?></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="info-label">签约日期</div>
                        <div class="info-value"><?= htmlspecialchars($contract['sign_date'] ?? '-') ?></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="info-label">创建时间</div>
                        <div class="info-value"><?= !empty($contract['create_time']) ? date('Y-m-d H:i', (int)$contract['create_time']) : '-' ?></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="info-label">状态</div>
                        <div class="info-value">
                            <?php
                            $statusText = FinanceStatus::getContractLabel(($contract['status'] ?? ''), ($contract['manual_status'] ?? ''));
                            $statusClass = 'badge-pending';
                            if (strpos($statusText, '已收') !== false || strpos($statusText, '完成') !== false) $statusClass = 'badge-received';
                            elseif (strpos($statusText, '逾期') !== false) $statusClass = 'badge-overdue';
                            elseif (strpos($statusText, '部分') !== false) $statusClass = 'badge-partial';
                            ?>
                            <span class="<?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 contract-detail-card">
    <div class="card-header" style="background:linear-gradient(135deg,#11998e 0%,#38ef7d 100%);">
        <div class="fw-semibold"><i class="bi bi-calendar3 me-2"></i>分期管理</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle installment-table mb-0" id="instTable">
                <thead>
                <tr>
                    <th>期数</th>
                    <th>到期日</th>
                    <th>收款时间</th>
                    <th>收款人</th>
                    <th>收款方式</th>
                    <th>货币</th>
                    <th>应收</th>
                    <th>已收</th>
                    <th>未收</th>
                    <th>逾期</th>
                    <th>状态</th>
                    <th style="width:60px;">凭证</th>
                    <th style="width:100px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($installments)): ?>
                    <tr><td colspan="13" class="text-center text-muted">暂无分期</td></tr>
                <?php else: ?>
                    <?php foreach ($installments as $i): ?>
                        <?php
                        $amountDue = (float)($i['amount_due'] ?? 0);
                        $amountPaid = (float)($i['amount_paid'] ?? 0);
                        $unpaid = $amountDue - $amountPaid;
                        $isFullyPaid = ($amountDue > 0 && $unpaid <= 0.00001);
                        $instStatus = FinanceStatus::getInstallmentStatus($amountDue, $amountPaid, ($i['due_date'] ?? ''), ($i['manual_status'] ?? ''));
                        $statusLabel = $instStatus['label'];
                        $badge = $instStatus['badge'];
                        ?>
                        <tr data-installment-id="<?= (int)($i['id'] ?? 0) ?>">
                            <td>第 <?= (int)($i['installment_no'] ?? 0) ?> 期</td>
                            <td>
                                <div><?= htmlspecialchars($i['due_date'] ?? '') ?></div>
                                <div class="small text-muted">创建：<?= !empty($i['create_time']) ? date('Y-m-d H:i', (int)$i['create_time']) : '-' ?></div>
                            </td>
                            <td><?= !empty($i['last_received_date']) ? htmlspecialchars($i['last_received_date']) : '-' ?></td>
                            <td><?= htmlspecialchars($i['collector_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(getPaymentMethodLabel((string)($i['payment_method'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($i['currency'] ?? 'TWD') ?></td>
                            <?php 
                            $instCurrency = $i['currency'] ?? 'TWD';
                            $contractCurrency = $contract['currency'] ?? 'TWD';
                            $amtDue = (float)($i['amount_due'] ?? 0);
                            $amtPaid = (float)($i['amount_paid'] ?? 0);
                            $amtUnpaid = max(0.0, $amtDue - $amtPaid);
                            $convertedDue = convertCurrency($amtDue, $instCurrency, $contractCurrency);
                            $showConvert = ($instCurrency !== $contractCurrency);
                            ?>
                            <td>
                                <?= number_format($amtDue, 2) ?>
                                <?php if ($showConvert): ?>
                                <div class="small text-muted">≈<?= number_format($convertedDue, 2) ?> <?= $contractCurrency ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($amtPaid, 2) ?></td>
                            <td><?= number_format($amtUnpaid, 2) ?></td>
                            <td><?= (int)($i['overdue_days'] ?? 0) ?></td>
                            <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td>
                                <div class="inst-file-thumb" data-installment-id="<?= (int)($i['id'] ?? 0) ?>" data-customer-id="<?= (int)$customerId ?>" style="width:40px;height:40px;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:10px;color:#999;" title="点击查看/上传凭证">...</div>
                                <input type="file" class="instFileInput d-none" data-installment-id="<?= (int)($i['id'] ?? 0) ?>" multiple accept="image/*,.pdf">
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-warning btn-sm btnInstStatus" data-id="<?= (int)($i['id'] ?? 0) ?>" data-current-status="<?= htmlspecialchars($statusLabel) ?>"<?= $isFullyPaid ? ' disabled' : '' ?>>改状态</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">合同附件（公司文件）</div>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnUploadContractFiles">上传附件</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="fileTable">
                <thead>
                <tr>
                    <th>文件名</th>
                    <th>大小</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($files)): ?>
                    <tr><td colspan="3" class="text-center text-muted">暂无附件</td></tr>
                <?php else: ?>
                    <?php foreach ($files as $f): ?>
                        <tr data-file-id="<?= (int)($f['file_id'] ?? 0) ?>">
                            <td><?= htmlspecialchars($f['filename'] ?? '') ?></td>
                            <td><?= number_format(((float)($f['filesize'] ?? 0)) / 1024 / 1024, 2) ?> MB</td>
                            <td>
                                <?php if (((int)($f['preview_supported'] ?? 0)) === 1): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="javascript:void(0)" onclick="showFileLightbox(<?= (int)($f['file_id'] ?? 0) ?>, '<?= htmlspecialchars(addslashes($f['filename'] ?? '')) ?>')">预览</a>
                                <?php endif; ?>
                                <a class="btn btn-outline-secondary btn-sm" target="_blank" href="/api/customer_file_stream.php?id=<?= (int)($f['file_id'] ?? 0) ?>&mode=download">下载</a>
                                <button type="button" class="btn btn-outline-primary btn-sm btnRenameFile" data-id="<?= (int)($f['file_id'] ?? 0) ?>">重命名</button>
                                <button type="button" class="btn btn-outline-danger btn-sm btnDeleteFile" data-id="<?= (int)($f['file_id'] ?? 0) ?>">删除</button>
                                <button type="button" class="btn btn-outline-warning btn-sm btnDetachFile" data-id="<?= (int)($f['file_id'] ?? 0) ?>">解绑</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="editContractModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑合同</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editContractForm" class="row g-3">
                    <input type="hidden" name="contract_id" value="<?= (int)$contractId ?>">
                    <div class="col-md-4">
                        <label class="form-label">合同号</label>
                        <input type="text" class="form-control" name="contract_no" value="<?= htmlspecialchars($contract['contract_no'] ?? '') ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">标题</label>
                        <input type="text" class="form-control" name="title" value="<?= htmlspecialchars($contract['title'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">合同签约人</label>
                        <?php if (($user['role'] ?? '') === 'sales'): ?>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['sales_name'] ?? '') ?>" disabled>
                        <?php else: ?>
                            <select class="form-select" name="sales_user_id">
                                <option value="0">不变</option>
                                <?php foreach ($salesUsers as $su): ?>
                                    <option value="<?= (int)($su['id'] ?? 0) ?>" <?= (int)($contract['sales_user_id'] ?? 0) === (int)($su['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($su['realname'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">客户归属人</label>
                        <?php if (($user['role'] ?? '') === 'sales'): ?>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($contract['owner_name'] ?? '') ?>" disabled>
                        <?php else: ?>
                            <select class="form-select" name="owner_user_id">
                                <option value="0">不变</option>
                                <?php foreach ($allUsers as $u): ?>
                                    <option value="<?= (int)($u['id'] ?? 0) ?>" <?= (int)($contract['owner_user_id'] ?? 0) === (int)($u['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars($u['realname'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">签约日期 <?php if (($user['role'] ?? '') !== 'admin'): ?><small class="text-muted">(仅管理员可修改)</small><?php endif; ?></label>
                        <input type="date" class="form-control" name="sign_date" value="<?= htmlspecialchars($contract['sign_date'] ?? '') ?>" <?= ($user['role'] ?? '') !== 'admin' ? 'disabled' : '' ?>>
                        <?php if (($user['role'] ?? '') !== 'admin'): ?>
                            <input type="hidden" name="sign_date" value="<?= htmlspecialchars($contract['sign_date'] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">合同总价</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="gross_amount" value="<?= htmlspecialchars((string)($contract['gross_amount'] ?? '0')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">成交单价</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="unit_price" value="<?= htmlspecialchars((string)($contract['unit_price'] ?? '')) ?>" placeholder="可选">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">合同货币</label>
                        <select class="form-select" name="currency">
                            <option value="TWD" <?= ($contract['currency'] ?? 'TWD') === 'TWD' ? 'selected' : '' ?>>TWD（新台币）</option>
                            <option value="CNY" <?= ($contract['currency'] ?? '') === 'CNY' ? 'selected' : '' ?>>CNY（人民币）</option>
                            <option value="USD" <?= ($contract['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD（美元）</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">折扣参与计算</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="editDiscountInCalc" name="discount_in_calc" <?= ((int)($contract['discount_in_calc'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="editDiscountInCalc">是</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">折扣类型</label>
                        <select class="form-select" name="discount_type">
                            <option value="" <?= empty($contract['discount_type']) ? 'selected' : '' ?>>不填</option>
                            <option value="amount" <?= ($contract['discount_type'] ?? '') === 'amount' ? 'selected' : '' ?>>减免金额</option>
                            <option value="rate" <?= ($contract['discount_type'] ?? '') === 'rate' ? 'selected' : '' ?>>折扣比例</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">折扣值</label>
                        <input type="number" step="0.0001" min="0" class="form-control" name="discount_value" value="<?= htmlspecialchars((string)($contract['discount_value'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">折扣备注</label>
                        <input type="text" class="form-control" name="discount_note" value="<?= htmlspecialchars($contract['discount_note'] ?? '') ?>">
                    </div>
                </form>
                <div class="alert alert-info mt-3 mb-0">
                    注意：编辑合同不会自动改分期金额，必须保证“分期合计 = 折后金额”。
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnSaveContract">保存</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="statusModalTitle">状态调整</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="statusEntityType" value="">
                <input type="hidden" id="statusEntityId" value="">
                <div class="mb-3">
                    <label class="form-label">目标状态</label>
                    <select class="form-select" id="statusNewStatus"></select>
                </div>
                <div class="mb-3" id="receiptDateWrap" style="display:none;">
                    <label class="form-label">收款日期</label>
                    <input type="date" class="form-control" id="receiptDate">
                </div>
                <div class="mb-3" id="receiptMethodWrap" style="display:none;">
                    <label class="form-label">收款方式</label>
                    <select class="form-select" id="receiptMethod">
                        <?= renderPaymentMethodOptions() ?>
                    </select>
                </div>
                <div class="mb-3" id="receiptCollectorWrap" style="display:none;">
                    <label class="form-label">收款人</label>
                    <select class="form-select" id="receiptCollector">
                        <option value="">加载中...</option>
                    </select>
                </div>
                <div class="mb-3" id="receiptCurrencyWrap" style="display:none;">
                    <label class="form-label">收款货币</label>
                    <select class="form-select" id="receiptCurrency">
                        <option value="TWD" selected>TWD（新台币）</option>
                        <option value="CNY">CNY（人民币）</option>
                        <option value="USD">USD（美元）</option>
                    </select>
                </div>
                <div class="mb-3" id="receiptAmountWrap" style="display:none;">
                    <label class="form-label">实收金额 <span class="text-muted small" id="receiptAmountHint"></span></label>
                    <input type="number" step="0.01" min="0" class="form-control" id="receiptAmount" placeholder="可自定义金额">
                </div>
                <div class="mb-3">
                    <label class="form-label">原因（必填）</label>
                    <input type="text" class="form-control" id="statusReason" maxlength="255">
                </div>
                <div class="mb-3" id="receiptVoucherWrap" style="display:none;">
                    <label class="form-label">上传凭证（可拖拽）</label>
                    <div id="voucherDropZone" style="border:2px dashed #ccc;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all 0.3s;">
                        <div id="voucherDropText">拖拽文件到此处或点击上传</div>
                        <input type="file" id="voucherFileInput" class="d-none" multiple accept="image/*,.pdf">
                        <div id="voucherPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnSubmitStatus">提交</button>
            </div>
        </div>
    </div>
</div>

<script>
function apiUrl(path) { return API_URL + '/' + path; }
function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

const contractId = <?= (int)$contractId ?>;
const customerId = <?= (int)($contract['customer_id'] ?? 0) ?>;
const contractNo = '<?= htmlspecialchars($contract['contract_no'] ?? '') ?>';
const currentRole = '<?= htmlspecialchars((string)($user['role'] ?? ''), ENT_QUOTES) ?>';
const currentUserId = <?= (int)($user['id'] ?? 0) ?>;
const serverNowTs = <?= (int)$serverNowTs ?>;

const contractStatusOptions = ['作废'];
const canReceipt = <?= json_encode(in_array(($user['role'] ?? ''), ['sales', 'finance', 'admin', 'system_admin', 'super_admin'], true), JSON_UNESCAPED_UNICODE) ?>;
const installmentStatusOptions = canReceipt ? ['待收', '催款', '已收'] : ['待收', '催款'];

const unpaidDefaults = <?= json_encode($unpaidDefaults, JSON_UNESCAPED_UNICODE) ?>;

let modalEditContract = null;
let modalStatus = null;

function ensureModal(id) {
    const el = document.getElementById(id);
    return new bootstrap.Modal(el);
}

// 灯箱预览图片
function showImageLightbox(url, files) {
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
    
    if (imageFiles.length > 1) {
        const prevBtn = document.createElement('button');
        prevBtn.innerHTML = '◀';
        prevBtn.style.cssText = 'position:absolute;left:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:30px;padding:10px 15px;cursor:pointer;border-radius:5px;';
        prevBtn.onclick = function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex - 1 + imageFiles.length) % imageFiles.length;
            img.src = '/api/customer_file_stream.php?id=' + imageFiles[currentIndex].file_id + '&mode=preview';
            info.textContent = (currentIndex + 1) + ' / ' + imageFiles.length + ' - 点击图片放大，点击背景关闭';
            scale = 1; img.style.transform = 'scale(1)';
        };
        overlay.appendChild(prevBtn);
        
        const nextBtn = document.createElement('button');
        nextBtn.innerHTML = '▶';
        nextBtn.style.cssText = 'position:absolute;right:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:30px;padding:10px 15px;cursor:pointer;border-radius:5px;';
        nextBtn.onclick = function(e) {
            e.stopPropagation();
            currentIndex = (currentIndex + 1) % imageFiles.length;
            img.src = '/api/customer_file_stream.php?id=' + imageFiles[currentIndex].file_id + '&mode=preview';
            info.textContent = (currentIndex + 1) + ' / ' + imageFiles.length + ' - 点击图片放大，点击背景关闭';
            scale = 1; img.style.transform = 'scale(1)';
        };
        overlay.appendChild(nextBtn);
    }
    
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '✕';
    closeBtn.style.cssText = 'position:absolute;top:20px;right:20px;background:rgba(255,255,255,0.3);border:none;color:#fff;font-size:24px;padding:5px 12px;cursor:pointer;border-radius:5px;';
    closeBtn.onclick = function(e) { e.stopPropagation(); overlay.remove(); };
    
    overlay.appendChild(img);
    overlay.appendChild(info);
    overlay.appendChild(closeBtn);
    overlay.onclick = function() { overlay.remove(); };
    
    const escHandler = function(e) {
        if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); }
    };
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
}

// 单文件灯箱预览
function showFileLightbox(fileId, filename) {
    const url = '/api/customer_file_stream.php?id=' + fileId + '&mode=preview';
    showImageLightbox(url, [{ file_id: fileId, file_type: 'image/jpeg', filename: filename }]);
}

// 上传弹窗
function showUploadModal(installmentId, apiUrlFn) {
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
            <div style="font-size:13px;color:#999;">支持 jpg、png、gif、pdf 格式</div>
            <div style="font-size:13px;color:#999;margin-top:5px;">也可以 <strong>Ctrl+V</strong> 粘贴截图</div>
        </div>
        <input type="file" id="uploadFileInput" multiple accept="image/*,.pdf" style="display:none;">
        <div id="uploadProgress" style="margin-top:15px;display:none;"><div style="color:#3b82f6;">上传中...</div></div>
        <div style="margin-top:20px;"><button type="button" id="uploadCancelBtn" style="padding:8px 20px;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;">取消</button></div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const dropZone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadFileInput');
    const progressEl = document.getElementById('uploadProgress');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    
    dropZone.onclick = () => fileInput.click();
    dropZone.ondragover = (e) => { e.preventDefault(); dropZone.style.borderColor = '#3b82f6'; dropZone.style.background = '#f0f7ff'; };
    dropZone.ondragleave = () => { dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; };
    dropZone.ondrop = (e) => { e.preventDefault(); dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; if (e.dataTransfer.files.length > 0) doUpload(e.dataTransfer.files); };
    fileInput.onchange = () => { if (fileInput.files.length > 0) doUpload(fileInput.files); };
    
    const pasteHandler = (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        const files = [];
        for (let i = 0; i < items.length; i++) { if (items[i].kind === 'file') { const f = items[i].getAsFile(); if (f) files.push(f); } }
        if (files.length > 0) doUpload(files);
    };
    document.addEventListener('paste', pasteHandler);
    
    cancelBtn.onclick = () => cleanup();
    overlay.onclick = (e) => { if (e.target === overlay) cleanup(); };
    const escHandler = (e) => { if (e.key === 'Escape') cleanup(); };
    document.addEventListener('keydown', escHandler);
    
    function cleanup() { document.removeEventListener('paste', pasteHandler); document.removeEventListener('keydown', escHandler); overlay.remove(); }
    
    function doUpload(files) {
        progressEl.style.display = 'block';
        const fd = new FormData();
        fd.append('installment_id', installmentId);
        for (let i = 0; i < files.length; i++) fd.append('files[]', files[i]);
        const url = typeof apiUrlFn === 'function' ? apiUrlFn('finance_installment_file_upload.php') : (API_URL + '/finance_installment_file_upload.php');
        fetch(url, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { cleanup(); if (!res.success) { showAlertModal(res.message || '上传失败', 'error'); return; } showAlertModal('上传成功', 'success', () => location.reload()); })
            .catch(() => { cleanup(); showAlertModal('上传失败', 'error'); });
    }
}

function setToday(el) {
    const d = new Date();
    const pad = (v) => v.toString().padStart(2, '0');
    el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

let collectorsLoaded = false;

const exchangeRates = { TWD: 1.0, CNY: 4.5, USD: 32.0 };
function convertCurrency(amount, from, to) {
    if (from === to) return amount;
    const fromRate = exchangeRates[from] || 1.0;
    const toRate = exchangeRates[to] || 1.0;
    return amount * fromRate / toRate;
}

let currentInstallmentInfo = null;

function syncReceiptDateVisibility() {
    const type = document.getElementById('statusEntityType').value;
    const newStatus = document.getElementById('statusNewStatus').value || '';
    const wrap = document.getElementById('receiptDateWrap');
    const methodWrap = document.getElementById('receiptMethodWrap');
    const collectorWrap = document.getElementById('receiptCollectorWrap');
    const currencyWrap = document.getElementById('receiptCurrencyWrap');
    const amountWrap = document.getElementById('receiptAmountWrap');
    if (!wrap) return;
    if (type === 'installment' && newStatus === '已收') {
        wrap.style.display = '';
        if (methodWrap) methodWrap.style.display = '';
        if (collectorWrap) collectorWrap.style.display = '';
        if (currencyWrap) currencyWrap.style.display = '';
        if (amountWrap) amountWrap.style.display = '';
        const dateEl = document.getElementById('receiptDate');
        if (dateEl && !dateEl.value) setToday(dateEl);
        if (!collectorsLoaded) loadCollectors();
        loadInstallmentForReceipt();
    } else {
        wrap.style.display = 'none';
        if (methodWrap) methodWrap.style.display = 'none';
        if (collectorWrap) collectorWrap.style.display = 'none';
        if (currencyWrap) currencyWrap.style.display = 'none';
        if (amountWrap) amountWrap.style.display = 'none';
        currentInstallmentInfo = null;
    }
}

function loadInstallmentForReceipt() {
    const instId = document.getElementById('statusEntityId').value;
    if (!instId) return;
    fetch(apiUrl('finance_installment_get.php?id=' + instId))
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            currentInstallmentInfo = res.data;
            updateReceiptAmountDefault();
        }).catch(() => {});
}

function updateReceiptAmountDefault() {
    if (!currentInstallmentInfo) return;
    const instCurrency = currentInstallmentInfo.installment_currency || 'TWD';
    const receiveCurrency = document.getElementById('receiptCurrency').value || 'TWD';
    const unpaid = Number(currentInstallmentInfo.amount_unpaid || 0);
    const converted = convertCurrency(unpaid, instCurrency, receiveCurrency);
    document.getElementById('receiptAmount').value = converted.toFixed(2);
    const hint = document.getElementById('receiptAmountHint');
    if (hint) {
        if (instCurrency !== receiveCurrency) {
            hint.textContent = '(原始 ' + unpaid.toFixed(2) + ' ' + instCurrency + ' → ' + converted.toFixed(2) + ' ' + receiveCurrency + ')';
        } else {
            hint.textContent = '(未收 ' + unpaid.toFixed(2) + ' ' + instCurrency + ')';
        }
    }
}

function loadCollectors() {
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
            collectorsLoaded = true;
        })
        .catch(() => {});
}

function submitInstallmentReceipt(installmentId, receivedDate, method, note) {
    const collectorSelect = document.getElementById('receiptCollector');
    const collectorUserId = collectorSelect ? collectorSelect.value : '';
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
            if (!(unpaid > 0.00001)) {
                showAlertModal('该分期未收金额为0，无需登记收款', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('installment_id', String(installmentId));
            fd.append('received_date', receivedDate);
            fd.append('amount_received', String(unpaid.toFixed(2)));
            fd.append('method', method || '');
            fd.append('note', note || '');
            if (collectorUserId) fd.append('collector_user_id', collectorUserId);
            fetch(apiUrl('finance_receipt_save.php'), { method: 'POST', body: fd })
                .then(r2 => r2.json())
                .then(res2 => {
                    if (!res2.success) {
                        showAlertModal(res2.message || '登记收款失败', 'error');
                        return;
                    }
                    // 上传凭证文件（如果有）
                    if (voucherFiles.length > 0) {
                        const uploadFd = new FormData();
                        uploadFd.append('installment_id', String(installmentId));
                        voucherFiles.forEach((f, i) => uploadFd.append('files[]', f));
                        fetch(apiUrl('finance_installment_file_upload.php'), { method: 'POST', body: uploadFd })
                            .then(r3 => r3.json())
                            .then(res3 => {
                                voucherFiles = [];
                                showAlertModal('已登记收款并上传凭证', 'success', () => location.reload());
                            })
                            .catch(() => {
                                showAlertModal('收款已登记，但凭证上传失败', 'warning', () => location.reload());
                            });
                    } else {
                        showAlertModal('已登记收款并更新为已收', 'success', () => location.reload());
                    }
                })
                .catch(() => showAlertModal('登记收款失败，请查看控制台错误信息', 'error'));
        })
        .catch(() => showAlertModal('查询分期失败，请查看控制台错误信息', 'error'));
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

function setBadge(el, label) {
    if (!el) return;
    el.textContent = label;
    const cls = Array.from(el.classList);
    cls.forEach(c => {
        if (c.startsWith('bg-')) el.classList.remove(c);
    });
    el.classList.add('badge');
    if (label === '已收') el.classList.add('bg-success');
    else if (label === '部分已收' || label === '催款') el.classList.add('bg-warning');
    else if (label === '逾期') el.classList.add('bg-danger');
    else if (label === '待收') el.classList.add('bg-primary');
    else el.classList.add('bg-secondary');
}

function applyReceiptUI(installmentId, appliedAmount, receivedDate) {
    const tr = document.querySelector('tr[data-installment-id="' + String(installmentId) + '"]');
    if (!tr) throw new Error('row not found');
    const tds = tr.querySelectorAll('td');
    if (!tds || tds.length < 8) throw new Error('td missing');

    const amountDue = Number(String(tds[2].textContent || '').replace(/,/g, ''));
    const oldPaid = Number(String(tds[3].textContent || '').replace(/,/g, ''));
    const oldUnpaid = Number(String(tds[4].textContent || '').replace(/,/g, ''));

    const newPaid = oldPaid + Number(appliedAmount || 0);
    const newUnpaid = Math.max(0, amountDue - newPaid);

    tds[3].textContent = fmt2(newPaid);
    tds[4].textContent = fmt2(newUnpaid);

    const badge = tds[6].querySelector('span.badge');
    setBadge(badge, newUnpaid <= 0.00001 ? '已收' : (newPaid > 0.00001 ? '部分已收' : (badge ? badge.textContent : '待收')));

    if (receivedDate) {
        const lines = tds[1].querySelectorAll('div.small.text-muted');
        if (lines && lines.length >= 2) {
            lines[1].textContent = '最近收款：' + receivedDate;
        }
    }

    tds[7].querySelectorAll('button').forEach(b => { b.disabled = true; });
}

function openStatusModal(type, id, currentStatus = '') {
    if (type === 'contract') {
        showAlertModal('合同已改为删除操作，请使用"删除"按钮', 'warning');
        return;
    }
    document.getElementById('statusEntityType').value = type;
    document.getElementById('statusEntityId').value = String(id);
    document.getElementById('statusReason').value = '';
    const select = document.getElementById('statusNewStatus');
    const opts = installmentStatusOptions.filter(v => v !== currentStatus);
    select.innerHTML = opts.map(v => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
    document.getElementById('statusModalTitle').textContent = type === 'contract' ? '调整合同状态' : '调整分期状态';
    if (!modalStatus) modalStatus = ensureModal('statusModal');
    const dateEl = document.getElementById('receiptDate');
    if (dateEl) dateEl.value = '';
    modalStatus.show();
    syncReceiptDateVisibility();
}

document.getElementById('statusNewStatus').addEventListener('change', syncReceiptDateVisibility);

document.querySelectorAll('.btnInstStatus').forEach(btn => {
    btn.addEventListener('click', function() {
        const currentStatus = this.getAttribute('data-current-status') || '';
        openStatusModal('installment', Number(this.getAttribute('data-id') || 0), currentStatus);
    });
});

document.getElementById('receiptCurrency').addEventListener('change', updateReceiptAmountDefault);

(function() {
    const btn = document.getElementById('btnDeleteContract');
    if (!btn) return;
    const cts = Number(btn.getAttribute('data-create-time') || 0);
    const sid = Number(btn.getAttribute('data-sales-user-id') || 0);
    const isSalesSelf = (currentRole === 'sales' && currentUserId > 0 && sid > 0 && currentUserId === sid);
    if (!isSalesSelf) {
        btn.title = '删除后不可恢复（将同时删除分期、收款等数据）';
        return;
    }
    if (cts <= 0) {
        btn.title = '员工仅可在合同创建10分钟内删除';
        return;
    }
    const remain = 600 - (serverNowTs - cts);
    if (remain > 0) {
        const s = Math.max(0, Math.floor(remain));
        const m = Math.floor(s / 60);
        const r = s % 60;
        btn.title = '员工仅可在合同创建10分钟内删除，剩余 ' + String(m) + '分' + String(r) + '秒';
    } else {
        btn.disabled = true;
        btn.title = '已超过10分钟，无法删除（仅经理/管理员可删除）';
    }
})();

document.getElementById('btnSubmitStatus').addEventListener('click', () => {
    const type = document.getElementById('statusEntityType').value;
    const id = Number(document.getElementById('statusEntityId').value || 0);
    const newStatus = document.getElementById('statusNewStatus').value || '';
    const reason = (document.getElementById('statusReason').value || '').trim();
    if (!id || !newStatus || !reason) {
        showAlertModal('请填写完整信息（状态+原因）', 'warning');
        return;
    }

    if (type === 'installment' && newStatus === '已收') {
        const dateEl = document.getElementById('receiptDate');
        const receivedDate = (dateEl && dateEl.value) ? dateEl.value : localDateYmd(new Date());
        const methodEl = document.getElementById('receiptMethod');
        const method = (methodEl && methodEl.value) ? methodEl.value : '';
        submitInstallmentReceipt(id, receivedDate, method, '状态改为已收：' + reason);
        return;
    }

    const fd = new FormData();
    if (type === 'contract') fd.append('contract_id', String(id));
    else fd.append('installment_id', String(id));
    fd.append('new_status', newStatus);
    fd.append('reason', reason);
    const api = type === 'contract' ? 'finance_contract_status_update.php' : 'finance_installment_status_update.php';
    fetch(apiUrl(api), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '提交失败', 'error');
                return;
            }
            showAlertModal('已更新状态', 'success', () => location.reload());
        })
        .catch(() => showAlertModal('提交失败，请查看控制台错误信息', 'error'));
});

// 编辑合同

document.getElementById('btnEditContract').addEventListener('click', () => {
    if (!modalEditContract) modalEditContract = ensureModal('editContractModal');
    modalEditContract.show();
});

document.getElementById('btnSaveContract').addEventListener('click', () => {
    const form = document.getElementById('editContractForm');
    const fd = new FormData(form);
    fetch(apiUrl('finance_contract_update.php'), { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '保存失败', 'error');
                return;
            }
            showAlertModal('已保存合同', 'success', () => location.reload());
        })
        .catch(() => showAlertModal('保存失败，请查看控制台错误信息', 'error'));
});

// 删除合同：将级联删除分期/收款/日志等，且不可恢复

document.getElementById('btnDeleteContract').addEventListener('click', () => {
    const btn = document.getElementById('btnDeleteContract');
    if (btn && btn.disabled) {
        showAlertModal(btn.title || '无权限删除', 'warning');
        return;
    }
    showConfirmModal('确定删除该合同？删除后将同时删除分期、收款等数据，且不可恢复。', function() {
        const fd = new FormData();
        fd.append('contract_id', String(contractId));
        fetch(apiUrl('finance_contract_delete.php'), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    showAlertModal(res.message || '删除失败', 'error');
                    return;
                }
                showAlertModal('合同已删除', 'success', () => {
                    location.href = 'index.php?page=customer_detail&id=' + customerId + '#tab-finance';
                });
            })
            .catch(() => showAlertModal('删除失败，请查看控制台错误信息', 'error'));
    });
});

// 合同附件上传弹窗
function showContractFileUploadModal() {
    const existing = document.getElementById('uploadModal');
    if (existing) existing.remove();
    
    const overlay = document.createElement('div');
    overlay.id = 'uploadModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;';
    
    const modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:8px;padding:30px;width:500px;max-width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
    
    modal.innerHTML = `
        <h5 style="margin-bottom:20px;font-weight:600;">上传合同附件</h5>
        <div id="uploadDropZone" style="border:2px dashed #ccc;border-radius:8px;padding:40px 20px;cursor:pointer;transition:all 0.2s;">
            <div style="width:60px;height:60px;background:#3b82f6;border-radius:12px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;">
                <svg width="30" height="30" fill="white" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/></svg>
            </div>
            <div style="font-size:16px;color:#333;margin-bottom:8px;">点击选择文件，或拖拽上传</div>
            <div style="font-size:13px;color:#999;">支持多种文件格式</div>
            <div style="font-size:13px;color:#999;margin-top:5px;">也可以 <strong>Ctrl+V</strong> 粘贴</div>
        </div>
        <input type="file" id="uploadFileInput" multiple style="display:none;">
        <div id="uploadProgress" style="margin-top:15px;display:none;"><div style="color:#3b82f6;">上传中...</div></div>
        <div style="margin-top:20px;"><button type="button" id="uploadCancelBtn" style="padding:8px 20px;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;">取消</button></div>
    `;
    
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    
    const dropZone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadFileInput');
    const progressEl = document.getElementById('uploadProgress');
    const cancelBtn = document.getElementById('uploadCancelBtn');
    
    dropZone.onclick = () => fileInput.click();
    dropZone.ondragover = (e) => { e.preventDefault(); dropZone.style.borderColor = '#3b82f6'; dropZone.style.background = '#f0f7ff'; };
    dropZone.ondragleave = () => { dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; };
    dropZone.ondrop = (e) => { e.preventDefault(); dropZone.style.borderColor = '#ccc'; dropZone.style.background = ''; if (e.dataTransfer.files.length > 0) doUpload(e.dataTransfer.files); };
    fileInput.onchange = () => { if (fileInput.files.length > 0) doUpload(fileInput.files); };
    
    const pasteHandler = (e) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        const files = [];
        for (let i = 0; i < items.length; i++) { if (items[i].kind === 'file') { const f = items[i].getAsFile(); if (f) files.push(f); } }
        if (files.length > 0) doUpload(files);
    };
    document.addEventListener('paste', pasteHandler);
    
    cancelBtn.onclick = () => cleanup();
    overlay.onclick = (e) => { if (e.target === overlay) cleanup(); };
    const escHandler = (e) => { if (e.key === 'Escape') cleanup(); };
    document.addEventListener('keydown', escHandler);
    
    function cleanup() { document.removeEventListener('paste', pasteHandler); document.removeEventListener('keydown', escHandler); overlay.remove(); }
    
    function doUpload(files) {
        progressEl.style.display = 'block';
        const fd = new FormData();
        fd.append('customer_id', String(customerId));
        fd.append('category', 'internal_solution');
        const folderPath = '合同/' + (contractNo || ('ID-' + String(contractId)));
        for (let i = 0; i < files.length; i++) {
            fd.append('files[]', files[i]);
            fd.append('folder_paths[]', folderPath);
        }
        fetch(apiUrl('customer_files.php'), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { cleanup(); showAlertModal(res.message || '上传失败', 'error'); return; }
                const ids = (res.files || []).map(it => Number(it.id || 0)).filter(v => v > 0);
                if (!ids.length) { cleanup(); showAlertModal('上传成功但未返回文件ID', 'warning'); return; }
                const fd2 = new FormData();
                fd2.append('contract_id', String(contractId));
                fd2.append('file_ids', JSON.stringify(ids));
                return fetch(apiUrl('finance_contract_file_attach.php'), { method: 'POST', body: fd2 })
                    .then(r2 => r2.json())
                    .then(res2 => {
                        cleanup();
                        if (!res2.success) { showAlertModal(res2.message || '绑定失败', 'error'); return; }
                        showAlertModal('已上传并绑定附件', 'success', () => location.reload());
                    });
            })
            .catch(() => { cleanup(); showAlertModal('上传失败', 'error'); });
    }
}

document.getElementById('btnUploadContractFiles').addEventListener('click', () => {
    showContractFileUploadModal();
});

// 附件：重命名/删除/解绑

document.querySelectorAll('.btnRenameFile').forEach(btn => {
    btn.addEventListener('click', function() {
        const fid = Number(this.getAttribute('data-id') || 0);
        const tr = document.querySelector('tr[data-file-id="' + fid + '"]');
        const currentName = tr ? (tr.querySelector('td')?.textContent.trim() || '') : '';
        const newName = prompt('请输入新文件名', currentName);
        if (!newName) return;
        const fd = new FormData();
        fd.append('action', 'rename_file');
        fd.append('file_id', String(fid));
        fd.append('new_name', newName);
        fetch(apiUrl('customer_file_rename.php'), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showAlertModal(res.message || '重命名失败', 'error'); return; }
                showAlertModal('已重命名', 'success', () => location.reload());
            })
            .catch(() => showAlertModal('重命名失败，请查看控制台错误信息', 'error'));
    });
});

document.querySelectorAll('.btnDeleteFile').forEach(btn => {
    btn.addEventListener('click', function() {
        const fid = Number(this.getAttribute('data-id') || 0);
        if (!fid) return;
        showConfirmModal('确定删除该文件？（软删除，可恢复）', () => {
            const fd = new FormData();
            fd.append('id', String(fid));
            fetch(apiUrl('customer_file_delete.php'), { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) { showAlertModal(res.message || '删除失败', 'error'); return; }
                    showAlertModal('已删除文件', 'success', () => location.reload());
                })
                .catch(() => showAlertModal('删除失败，请查看控制台错误信息', 'error'));
        });
    });
});

document.querySelectorAll('.btnDetachFile').forEach(btn => {
    btn.addEventListener('click', function() {
        const fid = Number(this.getAttribute('data-id') || 0);
        if (!fid) return;
        showConfirmModal('确定解绑该文件？（文件仍保留在公司文件中）', () => {
            const fd = new FormData();
            fd.append('contract_id', String(contractId));
            fd.append('file_id', String(fid));
            fetch(apiUrl('finance_contract_file_detach.php'), { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) { showAlertModal(res.message || '解绑失败', 'error'); return; }
                    showAlertModal('已解绑', 'success', () => location.reload());
                })
                .catch(() => showAlertModal('解绑失败，请查看控制台错误信息', 'error'));
        });
    });
});

// Deep links
document.addEventListener('DOMContentLoaded', () => {
    const h = (location.hash || '').toLowerCase();
    if (h === '#regenerate') {
        document.getElementById('btnRegenerate')?.click();
    }
});

// 分期凭证：加载缩略图
document.querySelectorAll('.inst-file-thumb').forEach(function(thumb) {
    const instId = thumb.dataset.installmentId;
    if (!instId) return;
    fetch(apiUrl('finance_installment_files.php?installment_id=' + instId))
        .then(r => r.json())
        .then(res => {
            const files = (res.success && res.data) ? res.data : [];
            if (files.length === 0) {
                thumb.innerHTML = '<span style="font-size:9px;">无</span>';
                thumb.style.color = '#999';
            } else {
                const f = files[0];
                const isImage = /^image\//i.test(f.file_type);
                const url = '/api/customer_file_stream.php?id=' + f.file_id + '&mode=preview';
                if (isImage) {
                    thumb.innerHTML = '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;border-radius:3px;">';
                } else {
                    thumb.innerHTML = '<span style="font-size:16px;">📄</span>';
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

// 分期凭证：点击查看/上传
document.querySelectorAll('.inst-file-thumb').forEach(function(thumb) {
    thumb.addEventListener('click', function() {
        const filesJson = this.dataset.filesJson;
        const files = filesJson ? JSON.parse(filesJson) : [];
        if (files.length === 0) {
            // 无凭证时打开上传弹窗
            const instId = this.dataset.installmentId;
            if (instId) {
                showUploadModal(instId, apiUrl);
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
    });
});

// 分期凭证：上传
document.querySelectorAll('.instFileInput').forEach(function(input) {
    input.addEventListener('change', function() {
        const instId = this.dataset.installmentId;
        const files = this.files;
        if (!instId || !files || files.length === 0) return;
        const fd = new FormData();
        fd.append('installment_id', instId);
        for (let i = 0; i < files.length; i++) {
            fd.append('files[]', files[i]);
        }
        fetch(apiUrl('finance_installment_file_upload.php'), { method: 'POST', body: fd })
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

// 凭证拖拽上传
let voucherFiles = [];
const dropZone = document.getElementById('voucherDropZone');
const voucherInput = document.getElementById('voucherFileInput');
const voucherPreview = document.getElementById('voucherPreview');

if (dropZone) {
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
    document.addEventListener('paste', (e) => {
        if (document.getElementById('receiptVoucherWrap').style.display !== 'none') {
            const items = e.clipboardData?.items;
            if (items) {
                const files = [];
                for (let item of items) {
                    if (item.type.startsWith('image/')) {
                        const file = item.getAsFile();
                        if (file) files.push(file);
                    }
                }
                if (files.length > 0) handleVoucherFiles(files);
            }
        }
    });
}

function handleVoucherFiles(files) {
    for (let f of files) {
        if (voucherFiles.length >= 5) break;
        voucherFiles.push(f);
    }
    renderVoucherPreview();
}

function renderVoucherPreview() {
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

// 状态选择变化时显示/隐藏凭证上传
document.getElementById('statusNewStatus').addEventListener('change', function() {
    const isReceived = this.value === '已收';
    document.getElementById('receiptVoucherWrap').style.display = isReceived ? 'block' : 'none';
    if (!isReceived) {
        voucherFiles = [];
        renderVoucherPreview();
    }
});
</script>

<?php
layout_footer();
