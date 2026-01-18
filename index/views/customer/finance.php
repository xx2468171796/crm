<?php
require_once __DIR__ . '/../../core/dict.php';
require_once __DIR__ . '/../../core/finance_status.php';

$customerId = intval($_GET['id'] ?? 0);

if ($customerId <= 0) {
    echo '<div class="alert alert-warning">请先选择客户</div>';
    return;
}

$cust = Db::queryOne('SELECT id, name, mobile, customer_code, activity_tag FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
if (!$cust) {
    echo '<div class="alert alert-danger">客户不存在</div>';
    return;
}

$prepayRow = Db::queryOne('SELECT COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS balance
    FROM finance_prepay_ledger WHERE customer_id = :cid', ['cid' => $customerId]);
$prepayBalance = (float)($prepayRow['balance'] ?? 0);

$prepayLedger = Db::query(
    'SELECT id, direction, amount, source_type, source_id, note, created_at
     FROM finance_prepay_ledger
     WHERE customer_id = :cid
     ORDER BY created_at DESC, id DESC
     LIMIT 50',
    ['cid' => $customerId]
);

$contracts = Db::query(
    'SELECT c.*, u.realname AS sales_name
     FROM finance_contracts c
     LEFT JOIN users u ON u.id = c.sales_user_id
     WHERE c.customer_id = :cid
     ORDER BY c.id DESC',
    ['cid' => $customerId]
);

$contractFilesMap = [];
$contractIds = array_values(array_filter(array_map(static fn($c) => (int)($c['id'] ?? 0), $contracts), static fn($v) => $v > 0));
if (!empty($contractIds)) {
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
    $rows = Db::query(
        'SELECT fcf.contract_id, cf.id AS file_id, cf.filename
         FROM finance_contract_files fcf
         INNER JOIN customer_files cf ON cf.id = fcf.file_id
         WHERE fcf.customer_id = ?
           AND fcf.contract_id IN (' . $placeholders . ')
           AND cf.deleted_at IS NULL
           AND cf.category = "internal_solution"
         ORDER BY fcf.id DESC',
        array_merge([$customerId], $contractIds)
    );
    foreach ($rows as $r) {
        $cid = (int)($r['contract_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        if (!isset($contractFilesMap[$cid])) {
            $contractFilesMap[$cid] = [];
        }
        $contractFilesMap[$cid][] = [
            'file_id' => (int)($r['file_id'] ?? 0),
            'filename' => (string)($r['filename'] ?? ''),
        ];
    }
}

$contractLastReceivedMap = [];
if (!empty($contractIds)) {
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
    $rows = Db::query(
        'SELECT contract_id, MAX(received_date) AS last_received_date
         FROM finance_receipts
         WHERE customer_id = ?
           AND amount_applied > 0
           AND contract_id IN (' . $placeholders . ')
         GROUP BY contract_id',
        array_merge([$customerId], $contractIds)
    );
    foreach ($rows as $r) {
        $cid = (int)($r['contract_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $contractLastReceivedMap[$cid] = (string)($r['last_received_date'] ?? '');
    }
}

$installments = Db::query(
    'SELECT i.*, ragg.last_received_date, c.contract_no, c.title AS contract_title, c.sales_user_id,
            u.realname AS sales_name,
            (i.amount_due - i.amount_paid) AS amount_unpaid,
            CASE
                WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date)
                ELSE 0
            END AS overdue_days
     FROM finance_installments i
     INNER JOIN finance_contracts c ON c.id = i.contract_id
     LEFT JOIN users u ON u.id = c.sales_user_id
     LEFT JOIN (
        SELECT installment_id, MAX(received_date) AS last_received_date
        FROM finance_receipts
        WHERE amount_applied > 0
        GROUP BY installment_id
     ) ragg ON ragg.installment_id = i.id
     WHERE i.customer_id = :cid AND i.deleted_at IS NULL
     ORDER BY i.due_date ASC, i.id ASC',
    ['cid' => $customerId]
);

$receipts = Db::query(
    'SELECT r.*, c.contract_no, c.title AS contract_title
     FROM finance_receipts r
     INNER JOIN finance_contracts c ON c.id = r.contract_id
     WHERE r.customer_id = :cid
     ORDER BY r.create_time DESC, r.id DESC
     LIMIT 50',
    ['cid' => $customerId]
);

$collectionLogs = Db::query(
    'SELECT
        l.*, u.realname AS actor_name,
        i.installment_no, i.due_date, c.contract_no
     FROM finance_collection_logs l
     LEFT JOIN users u ON u.id = l.actor_user_id
     LEFT JOIN finance_installments i ON i.id = l.installment_id
     LEFT JOIN finance_contracts c ON c.id = l.contract_id
     WHERE l.customer_id = :cid
     ORDER BY l.id DESC
     LIMIT 50',
    ['cid' => $customerId]
);

$sumDue = 0.0;
$sumPaid = 0.0;
$sumUnpaid = 0.0;
$overdueCount = 0;
foreach ($installments as $it) {
    $sumDue += (float)($it['amount_due'] ?? 0);
    $sumPaid += (float)($it['amount_paid'] ?? 0);
    $sumUnpaid += max(0.0, (float)($it['amount_unpaid'] ?? 0));
    if (((int)($it['overdue_days'] ?? 0)) > 0 && max(0.0, (float)($it['amount_unpaid'] ?? 0)) > 0.00001) {
        $overdueCount++;
    }
}

?>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div>客户财务</div>
            <div class="small text-muted">合同 / 分期 / 收款 / 预收 / 催款</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_contract_create&customer_id=<?= (int)$customerId ?>">新建合同</a>
            <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_prepay&customer_id=<?= (int)$customerId ?>">预收台账</a>
            <?php if (canOrAdmin(PermissionCode::FINANCE_PREPAY)): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" id="btnCustomerPrepayAdd">新增预收</button>
            <?php endif; ?>
            <a class="btn btn-outline-primary btn-sm" href="index.php?page=my_receivables">我的应收/催款</a>
            <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_dashboard">财务工作台</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="fw-semibold"><?= htmlspecialchars($cust['name'] ?? '') ?><?= ($cust['activity_tag'] ?? '') !== '' ? ('（' . htmlspecialchars($cust['activity_tag']) . '）') : '' ?></div>
                <div class="small text-muted"><?= htmlspecialchars(($cust['customer_code'] ?? '') . ' ' . ($cust['mobile'] ?? '')) ?></div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="small text-muted">预收余额</div>
                <div class="fs-4 fw-bold"><?= number_format($prepayBalance, 2) ?></div>
            </div>
        </div>

        <hr>

        <div class="row g-3">
            <div class="col-md-3">
                <div class="small text-muted">分期应收合计</div>
                <div class="fw-semibold" id="financeSumDue" data-value="<?= htmlspecialchars((string)$sumDue) ?>"><?= number_format($sumDue, 2) ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">已收合计</div>
                <div class="fw-semibold" id="financeSumPaid" data-value="<?= htmlspecialchars((string)$sumPaid) ?>"><?= number_format($sumPaid, 2) ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">未收合计</div>
                <div class="fw-semibold" id="financeSumUnpaid" data-value="<?= htmlspecialchars((string)$sumUnpaid) ?>"><?= number_format($sumUnpaid, 2) ?></div>
            </div>
            <div class="col-md-3">
                <div class="small text-muted">逾期分期数</div>
                <div class="fw-semibold" id="financeOverdueCount" data-value="<?= (int)$overdueCount ?>"><?= (int)$overdueCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">合同</div>
        <div class="small text-muted">共 <?= count($contracts) ?> 份</div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="customerContractsTable">
                <thead>
                <tr>
                    <th>合同号</th>
                    <th>标题</th>
                    <th>销售</th>
                    <th>
                        签约日期
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 contractSortBtn" data-sort="create_time" title="按创建时间排序">创</button>
                    </th>
                    <th>折后金额</th>
                    <th>
                        状态
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 contractSortBtn" data-sort="status" title="按状态排序">状</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 contractSortBtn" data-sort="receipt_time" title="按收款时间排序">收</button>
                    </th>
                    <th>附件</th>
                    <th style="width:240px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($contracts)): ?>
                    <tr><td colspan="8" class="text-center text-muted">暂无合同</td></tr>
                <?php else: ?>
                    <?php foreach ($contracts as $c): ?>
                        <?php
                        $cid = (int)($c['id'] ?? 0);
                        $cStatusLabel = FinanceStatus::getContractLabel(($c['status'] ?? ''), ($c['manual_status'] ?? ''));
                        $cLastReceivedDate = (string)($contractLastReceivedMap[$cid] ?? '');
                        ?>
                        <tr
                            data-contract-id="<?= (int)($c['id'] ?? 0) ?>"
                            data-status-label="<?= htmlspecialchars((string)$cStatusLabel) ?>"
                            data-create-time="<?= (int)($c['create_time'] ?? 0) ?>"
                            data-last-received-date="<?= htmlspecialchars($cLastReceivedDate) ?>"
                        >
                            <td><?= htmlspecialchars($c['contract_no'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['title'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['sales_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['sign_date'] ?? '') ?></td>
                            <td><?= number_format((float)($c['net_amount'] ?? 0), 2) ?></td>
                            <td>
                                <?= htmlspecialchars($cStatusLabel) ?>
                                <div class="small text-muted">创建：<?= !empty($c['create_time']) ? date('Y-m-d H:i', (int)$c['create_time']) : '-' ?></div>
                                <div class="small text-muted">最近收款：<?= $cLastReceivedDate !== '' ? htmlspecialchars($cLastReceivedDate) : '-' ?></div>
                            </td>
                            <td>
                                <?php
                                $files = $contractFilesMap[$cid] ?? [];
                                ?>
                                <?php if (empty($files)): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($files as $f): ?>
                                        <?php if ((int)($f['file_id'] ?? 0) > 0): ?>
                                            <div>
                                                <a href="/api/customer_file_stream.php?id=<?= (int)$f['file_id'] ?>&mode=download" target="_blank">
                                                    <?= htmlspecialchars($f['filename'] ?? '') ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_contract_detail&id=<?= (int)($c['id'] ?? 0) ?>">详情</a>
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
        <div class="fw-semibold">分期</div>
        <div class="d-flex align-items-center gap-2">
            <div class="small text-muted">共 <?= count($installments) ?> 条</div>
            <div class="input-group input-group-sm" style="width:auto;">
                <span class="input-group-text">分组1</span>
                <select class="form-select" id="instGroup1">
                    <option value="">不分组</option>
                    <option value="status">状态</option>
                    <option value="create_month">创建月份</option>
                    <option value="receipt_month">收款月份</option>
                </select>
            </div>
            <div class="input-group input-group-sm" style="width:auto;">
                <span class="input-group-text">分组2</span>
                <select class="form-select" id="instGroup2">
                    <option value="">不分组</option>
                    <option value="status">状态</option>
                    <option value="create_month">创建月份</option>
                    <option value="receipt_month">收款月份</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="customerInstallmentsTable">
                <thead>
                <tr>
                    <th>合同</th>
                    <th>分期</th>
                    <th>
                        到期日
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 instSortBtn" data-sort="create_time" title="按创建时间排序">创</button>
                    </th>
                    <th>应收</th>
                    <th>已收</th>
                    <th>未收</th>
                    <th>逾期(天)</th>
                    <th>
                        状态
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 instSortBtn" data-sort="status" title="按状态排序">状</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 instSortBtn" data-sort="receipt_time" title="按收款时间排序">收</button>
                    </th>
                    <th style="width:60px;">凭证</th>
                    <th style="width:100px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($installments)): ?>
                    <tr><td colspan="10" class="text-center text-muted">暂无分期</td></tr>
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
                        <tr
                            data-installment-id="<?= (int)($i['id'] ?? 0) ?>"
                            data-due-date="<?= htmlspecialchars($i['due_date'] ?? '') ?>"
                            data-amount-due="<?= htmlspecialchars((string)($i['amount_due'] ?? 0)) ?>"
                            data-status-label="<?= htmlspecialchars((string)$statusLabel) ?>"
                            data-create-time="<?= (int)($i['create_time'] ?? 0) ?>"
                            data-last-received-date="<?= htmlspecialchars((string)($i['last_received_date'] ?? '')) ?>"
                        >
                            <td>
                                <div><?= htmlspecialchars($i['contract_no'] ?? '') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($i['contract_title'] ?? '') ?></div>
                            </td>
                            <td>第 <?= (int)($i['installment_no'] ?? 0) ?> 期 / ID=<?= (int)($i['id'] ?? 0) ?></td>
                            <td>
                                <div><?= htmlspecialchars($i['due_date'] ?? '') ?></div>
                                <div class="small text-muted">创建：<?= !empty($i['create_time']) ? date('Y-m-d H:i', (int)$i['create_time']) : '-' ?></div>
                            </td>
                            <td><?= number_format((float)($i['amount_due'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($i['amount_paid'] ?? 0), 2) ?></td>
                            <td><?= number_format(max(0.0, (float)($i['amount_unpaid'] ?? 0)), 2) ?></td>
                            <td><?= (int)($i['overdue_days'] ?? 0) ?></td>
                            <td>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                <div class="small text-muted" data-last-received-date="<?= htmlspecialchars((string)($i['last_received_date'] ?? '')) ?>">最近收款：<?= !empty($i['last_received_date']) ? htmlspecialchars((string)$i['last_received_date']) : '-' ?></div>
                            </td>
                            <td>
                                <div class="inst-file-thumb" data-installment-id="<?= (int)($i['id'] ?? 0) ?>" data-customer-id="<?= (int)$customerId ?>" style="width:40px;height:40px;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:10px;color:#999;" title="点击查看/上传凭证">...</div>
                                <input type="file" class="instFileInput d-none" data-installment-id="<?= (int)($i['id'] ?? 0) ?>" multiple accept="image/*,.pdf">
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-warning btn-sm" onclick="openFinanceStatusModal('installment', <?= (int)($i['id'] ?? 0) ?>)"<?= $isFullyPaid ? ' disabled' : '' ?>>改状态</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="customerPrepayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增预收</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="customerPrepayForm" class="row g-3">
                    <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">
                    <div class="col-md-4" id="customerPrepayDirectionWrap">
                        <label class="form-label">方向</label>
                        <select class="form-select" name="direction" id="customerPrepayDirection">
                            <option value="in" selected>入（新增预收）</option>
                            <option value="out">出（扣减预收）</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">金额</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="amount" id="customerPrepayAmount">
                    </div>
                    <div class="col-md-4" id="customerPrepayMethodWrap">
                        <label class="form-label">收款方式</label>
                        <select class="form-select" name="method" id="customerPrepayMethod">
                            <?= renderPaymentMethodOptions('', true, '请选择') ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">备注</label>
                        <input type="text" class="form-control" name="note" id="customerPrepayNote" maxlength="255" placeholder="可选，最多255字">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="btnCustomerPrepaySubmit" onclick="submitCustomerPrepay()">提交</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="financeStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="financeStatusModalTitle">状态调整</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="financeStatusEntityType" value="">
                <input type="hidden" id="financeStatusEntityId" value="">
                <div class="mb-3">
                    <label class="form-label">目标状态</label>
                    <select class="form-select" id="financeStatusNewStatus"></select>
                </div>
                <div class="mb-3" id="financeReceiptDateWrap" style="display:none;">
                    <label class="form-label">收款日期</label>
                    <input type="date" class="form-control" id="financeReceiptDate">
                </div>
                <div class="mb-3" id="financeReceiptMethodWrap" style="display:none;">
                    <label class="form-label">收款方式</label>
                    <select class="form-select" id="financeReceiptMethod">
                        <?= renderPaymentMethodOptions() ?>
                    </select>
                </div>
                <div class="mb-3" id="financeReceiptCollectorWrap" style="display:none;">
                    <label class="form-label">收款人</label>
                    <select class="form-select" id="financeReceiptCollector">
                        <option value="">加载中...</option>
                    </select>
                </div>
                <div class="mb-3" id="financePrepayApplyWrap" style="display:none;">
                    <label class="form-label">预收核销金额 <span class="text-primary" id="financePrepayBalanceHint">(余额: <?= number_format($prepayBalance, 2) ?>)</span></label>
                    <input type="number" step="0.01" min="0" class="form-control" id="financePrepayAmount" placeholder="0 表示不使用预收">
                    <div class="form-text">可与现金混合支付，留空或填0表示不使用预收</div>
                </div>
                <div class="mb-3" id="financeReceiptAttachmentWrap" style="display:none;">
                    <label class="form-label">收款凭证（可选）</label>
                    <input type="file" class="form-control" id="financeReceiptAttachment" accept=".jpg,.jpeg,.png,.gif,.pdf">
                    <div class="form-text">支持jpg、png、gif、pdf格式，最大10MB</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">原因（必填）</label>
                    <input type="text" class="form-control" id="financeStatusReason" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitFinanceStatusChange()">提交</button>
            </div>
        </div>
    </div>
</div>

<script>
// 先定义全局函数，确保 onclick 可以调用
function openFinanceStatusModal(entityType, entityId) {
    const typeEl = document.getElementById('financeStatusEntityType');
    const idEl = document.getElementById('financeStatusEntityId');
    if (!typeEl || !idEl) { console.error('financeStatusModal elements not found'); return; }
    typeEl.value = String(entityType || '');
    idEl.value = String(entityId || '');
    const reasonEl = document.getElementById('financeStatusReason');
    if (reasonEl) reasonEl.value = '';

    const select = document.getElementById('financeStatusNewStatus');
    const opts = (entityType === 'contract') ? (window.contractStatusOptions || ['作废']) : (window.installmentStatusOptions || ['待收', '催款', '已收']);
    if (select) {
        select.innerHTML = opts.map(v => '<option value="' + v.replace(/"/g, '&quot;') + '">' + v + '</option>').join('');
        select.onchange = toggleFinanceReceiptFields;
    }
    const title = document.getElementById('financeStatusModalTitle');
    if (title) title.textContent = entityType === 'contract' ? '调整合同状态' : '调整分期状态';

    const dateEl = document.getElementById('financeReceiptDate');
    if (dateEl) dateEl.value = '';
    
    // 显示/隐藏收款日期、方式和预收选项
    toggleFinanceReceiptFields();
    // 重置预收金额
    const prepayAmountEl = document.getElementById('financePrepayAmount');
    if (prepayAmountEl) prepayAmountEl.value = '';
    
    const modalEl = document.getElementById('financeStatusModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

let financeCollectorsLoaded = false;

function toggleFinanceReceiptFields() {
    const status = document.getElementById('financeStatusNewStatus')?.value || '';
    const wrap = document.getElementById('financeReceiptDateWrap');
    const methodWrap = document.getElementById('financeReceiptMethodWrap');
    const collectorWrap = document.getElementById('financeReceiptCollectorWrap');
    const show = (status === '已收');
    if (wrap) wrap.style.display = show ? '' : 'none';
    if (methodWrap) methodWrap.style.display = show ? '' : 'none';
    if (collectorWrap) collectorWrap.style.display = show ? '' : 'none';
    if (show && !financeCollectorsLoaded) loadFinanceCollectors();
}

function loadFinanceCollectors() {
    const select = document.getElementById('financeReceiptCollector');
    if (!select) return;
    fetch(API_URL + '/finance_collector_list.php')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            const list = res.data.collectors || [];
            const currentId = res.data.current_user_id || 0;
            select.innerHTML = list.map(c => 
                '<option value="' + c.id + '"' + (c.id === currentId ? ' selected' : '') + '>' + (c.name || '').replace(/</g, '&lt;') + '</option>'
            ).join('');
            financeCollectorsLoaded = true;
        })
        .catch(() => {});
}

function submitFinanceStatusChange() {
    const entityType = document.getElementById('financeStatusEntityType')?.value || '';
    const entityId = Number(document.getElementById('financeStatusEntityId')?.value || 0);
    const newStatus = document.getElementById('financeStatusNewStatus')?.value || '';
    const reason = (document.getElementById('financeStatusReason')?.value || '').trim();
    
    if (!entityId) {
        showAlertModal('参数错误', 'error');
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

    // 关闭弹窗
    const modalEl = document.getElementById('financeStatusModal');
    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();

    // 如果是已收，需要调用收款登记（支持混合支付：现金+预收）
    if (entityType === 'installment' && newStatus === '已收') {
        const dateEl = document.getElementById('financeReceiptDate');
        const receivedDate = (dateEl && dateEl.value) ? dateEl.value : (new Date().toISOString().slice(0, 10));
        const methodEl = document.getElementById('financeReceiptMethod');
        const method = (methodEl && methodEl.value) ? methodEl.value : '';
        const prepayAmountEl = document.getElementById('financePrepayAmount');
        const prepayAmount = Number(prepayAmountEl?.value || 0);
        const collectorEl = document.getElementById('financeReceiptCollector');
        const collectorUserId = collectorEl ? collectorEl.value : '';
        
        // 先查询分期未收金额
        fetch(API_URL + '/finance_installment_get.php?id=' + entityId)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showAlertModal(res.message || '查询分期失败', 'error'); return; }
                const unpaid = Number(res.data?.amount_unpaid || 0);
                if (unpaid <= 0) { showAlertModal('该分期未收金额为0', 'warning'); return; }
                
                // 统一使用混合收款API
                const cashAmount = Math.max(0, unpaid - prepayAmount);
                const fd = new FormData();
                fd.append('installment_id', String(entityId));
                fd.append('received_date', receivedDate);
                fd.append('amount_received', String(cashAmount.toFixed(2)));
                fd.append('prepay_amount', String(prepayAmount.toFixed(2)));
                fd.append('method', method);
                fd.append('note', '状态改为已收：' + reason);
                if (collectorUserId) fd.append('collector_user_id', collectorUserId);
                    
                    fetch(API_URL + '/finance_receipt_save.php', { method: 'POST', body: fd })
                        .then(r2 => r2.json())
                        .then(res2 => {
                            if (!res2.success) { showAlertModal(res2.message || '登记收款失败', 'error'); return; }
                            const receiptId = res2.data?.receipt_id || res2.data?.id || 0;
                            const attachEl = document.getElementById('financeReceiptAttachment');
                            if (attachEl && attachEl.files && attachEl.files.length > 0 && receiptId > 0) {
                                const fileFd = new FormData();
                                fileFd.append('receipt_id', String(receiptId));
                                fileFd.append('file', attachEl.files[0]);
                                fetch(API_URL + '/finance_receipt_file_upload.php', { method: 'POST', body: fileFd })
                                    .then(r3 => r3.json())
                                    .then(res3 => {
                                        showAlertModal('已登记收款' + (res3.success ? '并上传凭证' : '') + '，更新为已收', 'success', () => location.reload());
                                    })
                                    .catch(() => showAlertModal('已登记收款（凭证上传失败）', 'success', () => location.reload()));
                            } else {
                                showAlertModal(res2.message || '已登记收款并更新为已收', 'success', () => location.reload());
                            }
                        })
                        .catch(() => showAlertModal('登记收款失败', 'error'));
            })
            .catch(() => showAlertModal('查询分期失败', 'error'));
        return;
    }

    // 普通状态更新
    const fd = new FormData();
    if (entityType === 'contract') fd.append('contract_id', String(entityId));
    else fd.append('installment_id', String(entityId));
    fd.append('new_status', newStatus);
    fd.append('reason', reason);
    
    const api = entityType === 'contract' ? 'finance_contract_status_update.php' : 'finance_installment_status_update.php';
    fetch(API_URL + '/' + api, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { showAlertModal(res.message || '提交失败', 'error'); return; }
            showAlertModal('已更新状态', 'success', () => location.reload());
        })
        .catch(() => showAlertModal('提交失败', 'error'));
}

function apiUrl(path) {
    return API_URL + '/' + path;
}

function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const contractStatusOptions = ['作废'];
const canReceipt = <?= json_encode(in_array(($user['role'] ?? ''), ['sales', 'finance', 'admin', 'system_admin', 'super_admin'], true), JSON_UNESCAPED_UNICODE) ?>;
const installmentStatusOptions = canReceipt ? ['待收', '催款', '已收'] : ['待收', '催款'];
const currentRole = '<?= htmlspecialchars((string)($user['role'] ?? ''), ENT_QUOTES) ?>';
const currentUserId = <?= (int)($user['id'] ?? 0) ?>;
const serverNowTs = <?= (int)time() ?>;

const canManualPrepayOut = <?= json_encode(in_array(($user['role'] ?? ''), ['finance', 'admin', 'system_admin', 'super_admin'], true), JSON_UNESCAPED_UNICODE) ?>;

function fmtMoney(n) {
    const x = Number(n || 0);
    return x.toFixed(2);
}

function submitCustomerPrepay() {
    var btn = document.getElementById('btnCustomerPrepaySubmit');
    if (btn && btn.disabled) return;
    
    var customerId = '<?= (int)$customerId ?>';
    var direction = document.getElementById('customerPrepayDirection').value || 'in';
    var amount = document.getElementById('customerPrepayAmount').value || '';
    var method = document.getElementById('customerPrepayMethod').value || '';
    var note = document.getElementById('customerPrepayNote').value || '';
    
    var amt = Number(amount);
    if (!amt || amt <= 0) {
        if (typeof showAlertModal === 'function') showAlertModal('金额必须大于0', 'warning');
        else alert('金额必须大于0');
        return;
    }
    if (!canManualPrepayOut) {
        direction = 'in';
    }
    
    if (btn) { btn.disabled = true; btn.textContent = '提交中...'; }
    
    // 关闭弹窗
    var modalEl = document.getElementById('customerPrepayModal');
    if (modalEl) { try { bootstrap.Modal.getInstance(modalEl).hide(); } catch(e){} }
    
    var fd = new FormData();
    fd.append('customer_id', customerId);
    fd.append('direction', direction);
    fd.append('amount', amount);
    fd.append('method', method);
    fd.append('note', note);
    
    fetch(API_URL + '/finance_prepay_manual_adjust.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                if (btn) { btn.disabled = false; btn.textContent = '提交'; }
                if (typeof showAlertModal === 'function') showAlertModal(res.message || '提交失败', 'error');
                else alert(res.message || '提交失败');
                return;
            }
            var d = res.data || {};
            var msg = '已记账：' + d.amount + '（余额 ' + d.balance_before + ' → ' + d.balance_after + '）';
            if (typeof showAlertModal === 'function') {
                showAlertModal(msg, 'success', function() { location.reload(); });
            } else {
                alert(msg);
                location.reload();
            }
        })
        .catch(function() { 
            if (btn) { btn.disabled = false; btn.textContent = '提交'; } 
            if (typeof showAlertModal === 'function') showAlertModal('提交失败', 'error');
            else alert('提交失败'); 
        });
}

window._initCustomerPrepayAdd = function() {
    const btn = document.getElementById('btnCustomerPrepayAdd');
    if (!btn) return;

    const modalEl = document.getElementById('customerPrepayModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    const directionWrap = document.getElementById('customerPrepayDirectionWrap');
    const directionSel = document.getElementById('customerPrepayDirection');

    btn.addEventListener('click', function() {
        document.getElementById('customerPrepayAmount').value = '';
        document.getElementById('customerPrepayNote').value = '';
        if (canManualPrepayOut) {
            if (directionWrap) directionWrap.style.display = '';
            if (directionSel) directionSel.value = 'in';
        } else {
            if (directionWrap) directionWrap.style.display = 'none';
            if (directionSel) directionSel.value = 'in';
        }
        modal.show();
    });
};

let financeStatusModal = null;

function ensureModal(id) {
    return new bootstrap.Modal(document.getElementById(id));
}

function setToday(el) {
    if (!el) return;
    const d = new Date();
    const pad = (v) => v.toString().padStart(2, '0');
    el.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function syncFinanceReceiptVisibility() {
    const type = document.getElementById('financeStatusEntityType')?.value || '';
    const newStatus = document.getElementById('financeStatusNewStatus')?.value || '';
    const wrap = document.getElementById('financeReceiptDateWrap');
    const methodWrap = document.getElementById('financeReceiptMethodWrap');
    const prepayWrap = document.getElementById('financePrepayApplyWrap');
    const attachWrap = document.getElementById('financeReceiptAttachmentWrap');
    if (!wrap) return;

    if (type === 'installment' && newStatus === '已收') {
        wrap.style.display = '';
        if (methodWrap) methodWrap.style.display = '';
        if (prepayWrap) prepayWrap.style.display = (<?= $prepayBalance > 0.00001 ? 'true' : 'false' ?>) ? '' : 'none';
        if (attachWrap) attachWrap.style.display = '';
        const dateEl = document.getElementById('financeReceiptDate');
        if (dateEl && !dateEl.value) setToday(dateEl);
    } else {
        wrap.style.display = 'none';
        if (methodWrap) methodWrap.style.display = 'none';
        if (prepayWrap) prepayWrap.style.display = 'none';
        if (attachWrap) attachWrap.style.display = 'none';
    }
}

function ensureFinanceStatusModal() {
    if (!financeStatusModal) {
        financeStatusModal = ensureModal('financeStatusModal');
    }
    return financeStatusModal;
}

function submitInstallmentReceipt(installmentId, receivedDate, method, note) {
    console.log('[FINANCE_STATUS]', 'submitInstallmentReceipt', installmentId, receivedDate, method);
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
            fetch(apiUrl('finance_receipt_save.php'), { method: 'POST', body: fd })
                .then(r2 => r2.json())
                .then(res2 => {
                    if (!res2.success) {
                        showAlertModal(res2.message || '登记收款失败', 'error');
                        return;
                    }
                    try { ensureFinanceStatusModal().hide(); } catch (e) {}
                    showAlertModal('已登记收款并更新为已收', 'success', function() {
                        location.reload();
                    });
                })
                .catch(() => showAlertModal('登记收款失败，请查看控制台错误信息', 'error'));
        })
        .catch(() => showAlertModal('查询分期失败，请查看控制台错误信息', 'error'));
}

document.addEventListener('change', function(e) {
    const t = e.target;
    if (!t) return;
    if (t.id === 'financeStatusNewStatus') {
        syncFinanceReceiptVisibility();
    }
});


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

function statusRank(label) {
    const v = String(label || '').trim();
    const order = {
        '催款': 1,
        '逾期': 2,
        '待收': 3,
        '部分已收': 4,
        '已收': 5
    };
    return order[v] || 99;
}

function contractStatusRank(label) {
    const v = String(label || '').trim();
    const order = {
        '未结清': 1,
        '剩余几期': 2,
        '已结清': 3,
        '作废': 99
    };
    return order[v] || 50;
}

let instSortKey = '';
let instSortDir = 'asc';

let contractSortKey = '';
let contractSortDir = 'asc';

function getInstSortVal(tr, key) {
    if (!tr) return '';
    if (key === 'create_time') return Number(tr.getAttribute('data-create-time') || 0);
    if (key === 'receipt_time') return String(tr.getAttribute('data-last-received-date') || '');
    if (key === 'status') return String(tr.getAttribute('data-status-label') || '');
    return '';
}

function compareInstRows(a, b) {
    const ia = parseInt(a.getAttribute('data-orig-index') || '0', 10) || 0;
    const ib = parseInt(b.getAttribute('data-orig-index') || '0', 10) || 0;
    if (!instSortKey) return ia - ib;

    if (instSortKey === 'create_time') {
        const va = Number(getInstSortVal(a, instSortKey) || 0);
        const vb = Number(getInstSortVal(b, instSortKey) || 0);
        const d = va - vb;
        return instSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (instSortKey === 'receipt_time') {
        const va = String(getInstSortVal(a, instSortKey) || '');
        const vb = String(getInstSortVal(b, instSortKey) || '');
        const da = va === '' ? '0000-00-00' : va;
        const db = vb === '' ? '0000-00-00' : vb;
        const d = da.localeCompare(db);
        return instSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (instSortKey === 'status') {
        const ra = statusRank(getInstSortVal(a, instSortKey));
        const rb = statusRank(getInstSortVal(b, instSortKey));
        const d = ra - rb;
        return instSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    return ia - ib;
}

function getGroupVal(tr, key) {
    if (!key) return '';
    if (key === 'status') return String(tr.getAttribute('data-status-label') || '').trim() || '未知';
    if (key === 'create_month') {
        const m = normalizeMonthByUnixTs(tr.getAttribute('data-create-time'));
        return m || '未知';
    }
    if (key === 'receipt_month') {
        const m = normalizeMonthByDateStr(tr.getAttribute('data-last-received-date'));
        return m || '未收款';
    }
    return '未知';
}

function buildGroupLabel(key, val) {
    if (key === 'status') return '状态：' + val;
    if (key === 'create_month') return '创建：' + val;
    if (key === 'receipt_month') return '收款：' + val;
    return val;
}

function refreshInstallmentsView() {
    const table = document.getElementById('customerInstallmentsTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    tbody.querySelectorAll('tr.inst-group-row').forEach(r => r.remove());

    const rows = Array.from(tbody.querySelectorAll('tr[data-installment-id]'));
    if (!rows.length) return;
    rows.forEach((tr, idx) => {
        if (!tr.getAttribute('data-orig-index')) tr.setAttribute('data-orig-index', String(idx));
    });

    const g1 = (document.getElementById('instGroup1')?.value || '').trim();
    const g2 = (document.getElementById('instGroup2')?.value || '').trim();
    const groups = [g1, g2].filter(v => v);

    const detach = (list) => {
        list.forEach(n => { try { n.remove(); } catch (e) {} });
    };
    detach(rows);

    const sortList = (list) => list.slice().sort(compareInstRows);

    if (groups.length === 0) {
        sortList(rows).forEach(tr => tbody.appendChild(tr));
        return;
    }

    const groupOnce = (list, key) => {
        const m = new Map();
        list.forEach(tr => {
            const gv = getGroupVal(tr, key);
            if (!m.has(gv)) m.set(gv, []);
            m.get(gv).push(tr);
        });
        return Array.from(m.entries());
    };

    const build = (list, level) => {
        const key = groups[level];
        const entries = groupOnce(list, key);
        const ordered = entries.sort((a, b) => String(a[0]).localeCompare(String(b[0])));
        ordered.forEach(([val, items]) => {
            const header = document.createElement('tr');
            header.className = 'table-light inst-group-row';
            const td = document.createElement('td');
            td.colSpan = 9;
            td.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                + '<div class="fw-semibold">' + esc(buildGroupLabel(key, val)) + '</div>'
                + '<div class="small text-muted">' + String(items.length) + ' 条</div>'
                + '</div>';
            header.appendChild(td);
            tbody.appendChild(header);

            if (level + 1 < groups.length) {
                build(items, level + 1);
            } else {
                sortList(items).forEach(tr => tbody.appendChild(tr));
            }
        });
    };

    build(rows, 0);
}

function getContractSortVal(tr, key) {
    if (!tr) return '';
    if (key === 'create_time') return Number(tr.getAttribute('data-create-time') || 0);
    if (key === 'receipt_time') return String(tr.getAttribute('data-last-received-date') || '');
    if (key === 'status') return String(tr.getAttribute('data-status-label') || '');
    return '';
}

function compareContractRows(a, b) {
    const ia = parseInt(a.getAttribute('data-orig-index') || '0', 10) || 0;
    const ib = parseInt(b.getAttribute('data-orig-index') || '0', 10) || 0;
    if (!contractSortKey) return ia - ib;

    if (contractSortKey === 'create_time') {
        const va = Number(getContractSortVal(a, contractSortKey) || 0);
        const vb = Number(getContractSortVal(b, contractSortKey) || 0);
        const d = va - vb;
        return contractSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (contractSortKey === 'receipt_time') {
        const va = String(getContractSortVal(a, contractSortKey) || '');
        const vb = String(getContractSortVal(b, contractSortKey) || '');
        const da = va === '' ? '0000-00-00' : va;
        const db = vb === '' ? '0000-00-00' : vb;
        const d = da.localeCompare(db);
        return contractSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (contractSortKey === 'status') {
        const ra = contractStatusRank(getContractSortVal(a, contractSortKey));
        const rb = contractStatusRank(getContractSortVal(b, contractSortKey));
        const d = ra - rb;
        return contractSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    return ia - ib;
}

function refreshContractsView() {
    const table = document.getElementById('customerContractsTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr[data-contract-id]'));
    if (!rows.length) return;
    rows.forEach((tr, idx) => {
        if (!tr.getAttribute('data-orig-index')) tr.setAttribute('data-orig-index', String(idx));
    });
    rows.forEach(tr => { try { tr.remove(); } catch (e) {} });
    rows.sort(compareContractRows).forEach(tr => tbody.appendChild(tr));
}

document.querySelectorAll('.instSortBtn').forEach(btn => {
    btn.addEventListener('click', function() {
        const key = String(this.getAttribute('data-sort') || '');
        if (!key) return;
        if (instSortKey === key) {
            instSortDir = (instSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            instSortKey = key;
            if (key === 'create_time' || key === 'receipt_time') instSortDir = 'desc';
            else instSortDir = 'asc';
        }
        refreshInstallmentsView();
    });
});

document.querySelectorAll('.contractSortBtn').forEach(btn => {
    btn.addEventListener('click', function() {
        const key = String(this.getAttribute('data-sort') || '');
        if (!key) return;
        if (contractSortKey === key) {
            contractSortDir = (contractSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            contractSortKey = key;
            if (key === 'create_time' || key === 'receipt_time') contractSortDir = 'desc';
            else contractSortDir = 'asc';
        }
        refreshContractsView();
    });
});

document.getElementById('instGroup1')?.addEventListener('change', refreshInstallmentsView);
document.getElementById('instGroup2')?.addEventListener('change', refreshInstallmentsView);
refreshInstallmentsView();
refreshContractsView();

</script>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">收款流水</div>
        <div class="small text-muted">最近 50 条</div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>收款日期</th>
                    <th>登记时间</th>
                    <th>合同</th>
                    <th>分期ID</th>
                    <th>实收</th>
                    <th>冲抵</th>
                    <th>超收</th>
                    <th>方式</th>
                    <th>凭证</th>
                    <th>备注</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($receipts)): ?>
                    <tr><td colspan="10" class="text-center text-muted">暂无收款</td></tr>
                <?php else: ?>
                    <?php foreach ($receipts as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['received_date'] ?? '') ?></td>
                            <td class="small text-muted"><?= !empty($r['create_time']) ? date('Y-m-d H:i', (int)$r['create_time'] + 8*3600) : '-' ?></td>
                            <td>
                                <div><?= htmlspecialchars($r['contract_no'] ?? '') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($r['contract_title'] ?? '') ?></div>
                            </td>
                            <td><?= (int)($r['installment_id'] ?? 0) ?></td>
                            <td><?= number_format((float)($r['amount_received'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($r['amount_applied'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($r['amount_overflow'] ?? 0), 2) ?></td>
                            <td><?= htmlspecialchars(getPaymentMethodLabel((string)($r['method'] ?? ''))) ?></td>
                            <td>
                                <div class="receipt-file-thumb" data-receipt-id="<?= (int)($r['id'] ?? 0) ?>" style="width:36px;height:36px;border:1px dashed #ccc;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:9px;color:#999;" title="点击查看/上传凭证">
                                    <span class="thumb-loading">...</span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($r['note'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="fw-semibold">催款记录</div>
        <div class="small text-muted">最近 50 条</div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>时间</th>
                    <th>合同</th>
                    <th>分期</th>
                    <th>催款人</th>
                    <th>方式</th>
                    <th>结果</th>
                    <th>备注</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($collectionLogs)): ?>
                    <tr><td colspan="7" class="text-center text-muted">暂无记录</td></tr>
                <?php else: ?>
                    <?php foreach ($collectionLogs as $l): ?>
                        <?php
                        $t = (int)($l['action_time'] ?? 0);
                        $timeText = $t ? date('Y-m-d H:i', $t) : '-';
                        $instText = '';
                        if (!empty($l['installment_id'])) {
                            $instText = '第' . (int)($l['installment_no'] ?? 0) . '期 / ID=' . (int)($l['installment_id'] ?? 0);
                            if (!empty($l['due_date'])) {
                                $instText .= ' / 到期:' . (string)$l['due_date'];
                            }
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($timeText) ?></td>
                            <td><?= htmlspecialchars($l['contract_no'] ?? '') ?></td>
                            <td><?= htmlspecialchars($instText) ?></td>
                            <td><?= htmlspecialchars($l['actor_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars(getCollectionMethodLabel((string)($l['method'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars(getCollectionResultLabel((string)($l['result'] ?? ''))) ?></td>
                            <td><?= htmlspecialchars($l['note'] ?? '') ?></td>
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
        <div class="fw-semibold">预收出入记录</div>
        <div>
            <span class="badge bg-primary me-2">当前余额: ¥<?= number_format($prepayBalance, 2) ?></span>
            <a href="index.php?page=finance_prepay&customer_id=<?= $customerId ?>" class="btn btn-outline-primary btn-sm">查看台账</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>时间</th>
                    <th>方向</th>
                    <th>金额</th>
                    <th>来源</th>
                    <th>备注</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($prepayLedger)): ?>
                    <tr><td colspan="5" class="text-center text-muted">暂无预收记录</td></tr>
                <?php else: ?>
                    <?php foreach ($prepayLedger as $pl): ?>
                        <?php
                        $plTime = (int)($pl['created_at'] ?? 0);
                        $plTimeText = $plTime ? date('Y-m-d H:i', $plTime) : '-';
                        $plDir = (string)($pl['direction'] ?? '');
                        $plDirText = $plDir === 'in' ? '入' : '出';
                        $plAmt = (float)($pl['amount'] ?? 0);
                        $plSource = (string)($pl['source_type'] ?? '');
                        $plSourceText = $plSource === 'manual_adjust' ? '手工调整' : ($plSource === 'apply_to_installment' ? '核销分期' : $plSource);
                        ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($plTimeText) ?></td>
                            <td><span class="badge <?= $plDir === 'in' ? 'bg-success' : 'bg-danger' ?>"><?= $plDirText ?></span></td>
                            <td class="<?= $plDir === 'in' ? 'text-success' : 'text-danger' ?> fw-semibold">¥<?= number_format($plAmt, 2) ?></td>
                            <td class="small"><?= htmlspecialchars($plSourceText) ?></td>
                            <td class="small"><?= htmlspecialchars($pl['note'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window._initCustomerPrepayAdd === 'function') window._initCustomerPrepayAdd();
    
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

    // 上传弹窗
    function showUploadModal(installmentId) {
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
            fetch(API_URL + '/finance_installment_file_upload.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => { cleanup(); if (!res.success) { showAlertModal(res.message || '上传失败', 'error'); return; } showAlertModal('上传成功', 'success', () => location.reload()); })
                .catch(() => { cleanup(); showAlertModal('上传失败', 'error'); });
        }
    }

    // 分期凭证：加载缩略图
    document.querySelectorAll('.inst-file-thumb').forEach(function(thumb) {
        const instId = thumb.dataset.installmentId;
        if (!instId) return;
        fetch(API_URL + '/finance_installment_files.php?installment_id=' + instId)
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
            fetch(API_URL + '/finance_installment_file_upload.php', { method: 'POST', body: fd })
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

    // 加载收款凭证缩略图
    document.querySelectorAll('.receipt-file-thumb').forEach(function(thumb) {
        const receiptId = thumb.dataset.receiptId;
        if (!receiptId) return;
        fetch(API_URL + '/finance_receipt_files.php?receipt_id=' + receiptId)
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
        
        // 点击事件
        thumb.addEventListener('click', function() {
            const filesJson = this.dataset.filesJson;
            const files = filesJson ? JSON.parse(filesJson) : [];
            const receiptId = this.dataset.receiptId;
            if (files.length === 0) {
                // 无凭证时打开上传弹窗
                if (receiptId) {
                    showReceiptUploadModal(receiptId);
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

    // 收款凭证上传弹窗
    function showReceiptUploadModal(receiptId) {
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
            fd.append('receipt_id', receiptId);
            for (let i = 0; i < files.length; i++) fd.append('files[]', files[i]);
            fetch(API_URL + '/finance_receipt_file_upload.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => { cleanup(); if (!res.success) { showAlertModal(res.message || '上传失败', 'error'); return; } showAlertModal('上传成功', 'success', () => location.reload()); })
                .catch(() => { cleanup(); showAlertModal('上传失败', 'error'); });
        }
    }
});
</script>
