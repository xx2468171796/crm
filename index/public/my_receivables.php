<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/dict.php';
require_once __DIR__ . '/../core/finance_status.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/migrations.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限访问此页面。</div>';
    layout_footer();
    exit;
}

ensureCustomerGroupField();

layout_header('我的应收/催款');
finance_sidebar_start('my_receivables');
echo '<script src="js/column-toggle.js"></script>';

$keyword = trim($_GET['keyword'] ?? '');
$customerGroup = trim($_GET['customer_group'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$status = trim($_GET['status'] ?? '');
$period = trim($_GET['period'] ?? '');
$dueStart = trim($_GET['due_start'] ?? '');
$dueEnd = trim($_GET['due_end'] ?? '');

if ($period === 'this_month') {
    $dueStart = date('Y-m-01');
    $dueEnd = date('Y-m-t');
} elseif ($period === 'last_month') {
    $ts = strtotime(date('Y-m-01') . ' -1 month');
    $dueStart = date('Y-m-01', $ts);
    $dueEnd = date('Y-m-t', $ts);
} else {
    $period = 'custom';
}

$page = max(1, intval($_GET['page_num'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT
    i.id AS installment_id,
    i.contract_id,
    i.customer_id,
    i.installment_no,
    i.create_time AS installment_create_time,
    i.due_date,
    i.amount_due,
    i.amount_paid,
    i.status AS installment_status,
    i.manual_status AS installment_manual_status,
    c.create_time AS contract_create_time,
    c.contract_no,
    c.title AS contract_title,
    c.sign_date,
    c.status AS contract_status,
    cu.name AS customer_name,
    cu.mobile AS customer_mobile,
    cu.customer_code,
    cu.customer_group,
    cu.activity_tag,
    u.realname AS sales_name,
    ragg.received_dates,
    ragg.last_received_date,
    CASE
        WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date)
        ELSE 0
    END AS overdue_days,
    (i.amount_due - i.amount_paid) AS amount_unpaid
FROM finance_installments i
INNER JOIN finance_contracts c ON c.id = i.contract_id
INNER JOIN customers cu ON cu.id = i.customer_id
LEFT JOIN (
    SELECT
        installment_id,
        GROUP_CONCAT(DISTINCT received_date ORDER BY received_date SEPARATOR \' ,\' ) AS received_dates,
        MAX(received_date) AS last_received_date
    FROM finance_receipts
    WHERE amount_applied > 0
    GROUP BY installment_id
) ragg ON ragg.installment_id = i.id
LEFT JOIN users u ON u.id = c.sales_user_id
WHERE 1=1 AND i.deleted_at IS NULL';

$params = [];

if ($user['role'] === 'sales') {
    $sql .= ' AND c.sales_user_id = :sales_user_id';
    $params['sales_user_id'] = (int)$user['id'];
}

if ($keyword !== '') {
    $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}

if ($customerGroup !== '') {
    $sql .= ' AND cu.customer_group LIKE :cg';
    $params['cg'] = '%' . $customerGroup . '%';
}

if ($activityTag !== '') {
    $sql .= ' AND cu.activity_tag = :activity_tag';
    $params['activity_tag'] = $activityTag;
}

if ($status !== '') {
    $sql .= ' AND c.status = :status';
    $params['status'] = $status;
}

if ($dueStart !== '') {
    $sql .= ' AND i.due_date >= :due_start';
    $params['due_start'] = $dueStart;
}

if ($dueEnd !== '') {
    $sql .= ' AND i.due_date <= :due_end';
    $params['due_end'] = $dueEnd;
}

$sumRow = Db::queryOne(
    'SELECT
        COUNT(DISTINCT c.id) AS contract_count,
        COUNT(*) AS installment_count,
        COALESCE(SUM(i.amount_due), 0) AS sum_due,
        COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
        COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid,
        COALESCE(SUM(CASE WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN GREATEST(i.amount_due - i.amount_paid, 0) ELSE 0 END), 0) AS sum_overdue_unpaid,
        COALESCE(SUM(CASE WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_installment_count
     FROM finance_installments i
     INNER JOIN finance_contracts c ON c.id = i.contract_id
     INNER JOIN customers cu ON cu.id = i.customer_id
     LEFT JOIN users u ON u.id = c.sales_user_id
     WHERE 1=1 AND i.deleted_at IS NULL'
        . ($user['role'] === 'sales' ? ' AND c.sales_user_id = :sales_user_id' : '')
        . ($keyword !== '' ? ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)' : '')
        . ($customerGroup !== '' ? ' AND cu.customer_group LIKE :cg' : '')
        . ($activityTag !== '' ? ' AND cu.activity_tag = :activity_tag' : '')
        . ($status !== '' ? ' AND c.status = :status' : '')
        . ($dueStart !== '' ? ' AND i.due_date >= :due_start' : '')
        . ($dueEnd !== '' ? ' AND i.due_date <= :due_end' : ''),
    $params
);

$countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') AS t';
$total = (int)(Db::queryOne($countSql, $params)['total'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

$sql .= ' ORDER BY i.due_date ASC, overdue_days DESC, i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$rows = Db::query($sql, $params);

// group by contract
$contracts = [];
foreach ($rows as $r) {
    $cid = (int)($r['contract_id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    if (!isset($contracts[$cid])) {
        $contracts[$cid] = [
            'contract_id' => $cid,
            'contract_create_time' => (int)($r['contract_create_time'] ?? 0),
            'contract_last_received_date' => '',
            'contract_no' => (string)($r['contract_no'] ?? ''),
            'contract_title' => (string)($r['contract_title'] ?? ''),
            'contract_status' => (string)($r['contract_status'] ?? ''),
            'sign_date' => (string)($r['sign_date'] ?? ''),
            'customer_id' => (int)($r['customer_id'] ?? 0),
            'customer_name' => (string)($r['customer_name'] ?? ''),
            'customer_code' => (string)($r['customer_code'] ?? ''),
            'customer_mobile' => (string)($r['customer_mobile'] ?? ''),
            'customer_group' => (string)($r['customer_group'] ?? ''),
            'activity_tag' => (string)($r['activity_tag'] ?? ''),
            'sales_name' => (string)($r['sales_name'] ?? ''),
            'sum_due' => 0.0,
            'sum_paid' => 0.0,
            'sum_unpaid' => 0.0,
            'max_overdue_days' => 0,
            'nearest_due_date' => '',
            'items' => [],
        ];
    }

    $contracts[$cid]['sum_due'] += (float)($r['amount_due'] ?? 0);
    $contracts[$cid]['sum_paid'] += (float)($r['amount_paid'] ?? 0);
    $contracts[$cid]['sum_unpaid'] += max(0.0, (float)($r['amount_unpaid'] ?? 0));
    $contracts[$cid]['max_overdue_days'] = max((int)$contracts[$cid]['max_overdue_days'], (int)($r['overdue_days'] ?? 0));
    $due = (string)($r['due_date'] ?? '');
    if ($contracts[$cid]['nearest_due_date'] === '' || ($due !== '' && $due < $contracts[$cid]['nearest_due_date'])) {
        $contracts[$cid]['nearest_due_date'] = $due;
    }
    $lrd = (string)($r['last_received_date'] ?? '');
    if ($lrd !== '' && ($contracts[$cid]['contract_last_received_date'] === '' || $lrd > $contracts[$cid]['contract_last_received_date'])) {
        $contracts[$cid]['contract_last_received_date'] = $lrd;
    }
    $contracts[$cid]['items'][] = $r;
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">我的应收/催款</h3>
    <div>
        <a class="btn btn-outline-primary btn-sm" href="index.php?page=finance_dashboard">财务工作台</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="my_receivables">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="text" class="form-control" name="keyword" placeholder="客户/手机/合同号" value="<?= htmlspecialchars($keyword) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="text" class="form-control" name="customer_group" placeholder="客户群" value="<?= htmlspecialchars($customerGroup) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="text" class="form-control" name="activity_tag" placeholder="活动标签" value="<?= htmlspecialchars($activityTag) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <select class="form-select" name="status">
                    <option value="">全部状态</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>未结清</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>已结清</option>
                    <option value="void" <?= $status === 'void' ? 'selected' : '' ?>>作废</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <select class="form-select" name="period" id="periodSelect">
                    <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>自定义</option>
                    <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>本月</option>
                    <option value="last_month" <?= $period === 'last_month' ? 'selected' : '' ?>>上月</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="date" class="form-control" name="due_start" value="<?= htmlspecialchars($dueStart) ?>" placeholder="开始日期">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <input type="date" class="form-control" name="due_end" value="<?= htmlspecialchars($dueEnd) ?>" placeholder="结束日期">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="index.php?page=my_receivables" class="btn btn-outline-secondary">重置</a>
                <span class="text-muted ms-2">共 <?= $total ?> 条</span>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">合同数</div>
                <div class="fw-semibold"><?= number_format((int)($sumRow['contract_count'] ?? 0)) ?></div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">分期数</div>
                <div class="fw-semibold"><?= number_format((int)($sumRow['installment_count'] ?? 0)) ?></div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">应收合计</div>
                <div class="fw-semibold"><?= number_format((float)($sumRow['sum_due'] ?? 0), 2) ?></div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">已收合计</div>
                <div class="fw-semibold"><?= number_format((float)($sumRow['sum_paid'] ?? 0), 2) ?></div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">未收合计</div>
                <div class="fw-semibold"><?= number_format((float)($sumRow['sum_unpaid'] ?? 0), 2) ?></div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <div class="small text-muted">逾期未收</div>
                <div class="fw-semibold"><?= number_format((float)($sumRow['sum_overdue_unpaid'] ?? 0), 2) ?></div>
                <div class="small text-muted">逾期分期：<?= number_format((int)($sumRow['overdue_installment_count'] ?? 0)) ?> 条</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <div class="input-group input-group-sm" style="width:auto;">
                <span class="input-group-text">分组1</span>
                <select class="form-select" id="recvGroup1">
                    <option value="">不分组</option>
                    <option value="status">状态</option>
                    <option value="create_month">创建月份</option>
                    <option value="receipt_month">收款月份</option>
                </select>
            </div>
            <div class="input-group input-group-sm" style="width:auto;">
                <span class="input-group-text">分组2</span>
                <select class="form-select" id="recvGroup2">
                    <option value="">不分组</option>
                    <option value="status">状态</option>
                    <option value="create_month">创建月份</option>
                    <option value="receipt_month">收款月份</option>
                </select>
            </div>
            <div id="columnToggleContainer" class="ms-2"></div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="myReceivablesTable">
                <thead>
                <tr>
                    <th style="width: 3%;"></th>
                    <th style="width: 10%;">客户</th>
                    <th style="width: 7%;">客户群</th>
                    <th style="width: 7%;">活动标签</th>
                    <th style="width: 18%;">
                        合同
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 recvSortBtn" data-sort="create_time" title="按创建时间排序">创</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 recvSortBtn" data-sort="receipt_time" title="按收款时间排序">收</button>
                    </th>
                    <th style="width: 8%;">最近到期</th>
                    <th style="width: 7%;">应收合计</th>
                    <th style="width: 7%;">已收合计</th>
                    <th style="width: 7%;">未收合计</th>
                    <th style="width: 6%;">最大逾期(天)</th>
                    <th style="width: 8%;">
                        合同状态
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 recvSortBtn" data-sort="status" title="按状态排序">状</button>
                    </th>
                    <th style="width: 12%;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($contracts)): ?>
                    <tr><td colspan="11" class="text-center text-muted">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($contracts as $c): ?>
                        <?php
                        $cStatus = FinanceStatus::getContractStatus(($c['contract_status'] ?? ''), ($c['contract_manual_status'] ?? ''));
                        $cStatusLabel = $cStatus['label'];
                        $cBadge = $cStatus['badge'];
                        $rowKey = 'contract-' . (int)$c['contract_id'];
                        ?>

                        <tr class="contract-row"
                            data-contract-row="<?= htmlspecialchars($rowKey) ?>"
                            data-contract-status-label="<?= htmlspecialchars($cStatusLabel) ?>"
                            data-contract-create-time="<?= (int)($c['contract_create_time'] ?? 0) ?>"
                            data-contract-last-received-date="<?= htmlspecialchars((string)($c['contract_last_received_date'] ?? '')) ?>"
                        >
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-secondary btnToggle" data-target="<?= htmlspecialchars($rowKey) ?>">▸</button>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($c['customer_name'] ?? '') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($c['customer_code'] ?? '') ?> <?= htmlspecialchars($c['customer_mobile'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($c['customer_group'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['activity_tag'] ?? '') ?></td>
                            <td>
                                <div><?= htmlspecialchars($c['contract_no'] ?? '') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($c['contract_title'] ?? '') ?></div>
                                <div class="small text-muted">签约：<?= htmlspecialchars($c['sign_date'] ?? '') ?> / 创建：<?= !empty($c['contract_create_time']) ? date('Y-m-d H:i', (int)$c['contract_create_time']) : '-' ?> / 销售：<?= htmlspecialchars($c['sales_name'] ?? '') ?> / 合同ID=<?= (int)$c['contract_id'] ?></div>
                            </td>
                            <td><?= htmlspecialchars($c['nearest_due_date'] ?? '') ?></td>
                            <td><?= number_format((float)($c['sum_due'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($c['sum_paid'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($c['sum_unpaid'] ?? 0), 2) ?></td>
                            <td><?= (int)($c['max_overdue_days'] ?? 0) ?></td>
                            <td>
                                <span class="badge bg-<?= htmlspecialchars($cBadge) ?>"><?= htmlspecialchars($cStatusLabel) ?></span>
                            </td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=customer_detail&id=<?= (int)($c['customer_id'] ?? 0) ?>#tab-finance">客户财务</a>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=finance_contract_detail&id=<?= (int)($c['contract_id'] ?? 0) ?>">合同详情</a>
                                </div>
                            </td>
                        </tr>

                        <?php foreach (($c['items'] ?? []) as $it): ?>
                            <?php
                            $amountDue = (float)($it['amount_due'] ?? 0);
                            $amountPaid = (float)($it['amount_paid'] ?? 0);
                            $unpaid = $amountDue - $amountPaid;
                            $isFullyPaid = ($amountDue > 0 && $unpaid <= 0.00001);
                            $iStatus = FinanceStatus::getInstallmentStatus($amountDue, $amountPaid, ($it['due_date'] ?? ''), ($it['installment_manual_status'] ?? ''));
                            $iStatusLabel = $iStatus['label'];
                            $iBadge = $iStatus['badge'];
                            ?>
                            <tr class="installment-row" data-parent="<?= htmlspecialchars($rowKey) ?>" style="display:none;">
                                <td></td>
                                <td class="text-muted small">分期：第 <?= (int)($it['installment_no'] ?? 0) ?> 期 / ID=<?= (int)($it['installment_id'] ?? 0) ?></td>
                                <td></td>
                                <td></td>
                                <td class="text-muted small">
                                    <div>到期：<?= htmlspecialchars($it['due_date'] ?? '') ?></div>
                                    <div>创建：<?= !empty($it['installment_create_time']) ? date('Y-m-d H:i', (int)$it['installment_create_time']) : '-' ?></div>
                                    <div>收款：<?= htmlspecialchars((string)($it['received_dates'] ?? '')) !== '' ? htmlspecialchars((string)($it['received_dates'] ?? '')) : '-' ?><?= !empty($it['last_received_date']) ? ('（最近：' . htmlspecialchars((string)$it['last_received_date']) . '）') : '' ?></div>
                                </td>
                                <td></td>
                                <td><?= number_format((float)($it['amount_due'] ?? 0), 2) ?></td>
                                <td><?= number_format((float)($it['amount_paid'] ?? 0), 2) ?></td>
                                <td><?= number_format(max(0.0, (float)($it['amount_unpaid'] ?? 0)), 2) ?></td>
                                <td><?= (int)($it['overdue_days'] ?? 0) ?></td>
                                <td>
                                    <span class="badge bg-<?= htmlspecialchars($iBadge) ?>"><?= htmlspecialchars($iStatusLabel) ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-warning ms-2" onclick="openReceivableStatusModal(<?= (int)($it['installment_id'] ?? 0) ?>)"<?= $isFullyPaid ? ' disabled' : '' ?>>改状态</button>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap align-items-center">
                                        <div class="inst-file-thumb" data-installment-id="<?= (int)($it['installment_id'] ?? 0) ?>" data-customer-id="<?= (int)($it['customer_id'] ?? 0) ?>" style="width:40px;height:40px;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:10px;color:#999;" title="点击查看/上传凭证">
                                            <span class="thumb-loading">...</span>
                                        </div>
                                        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=customer_detail&id=<?= (int)($it['customer_id'] ?? 0) ?>#tab-finance">客户财务</a>
                                        <a class="btn btn-sm btn-outline-primary" href="index.php?page=finance_contract_detail&id=<?= (int)($it['contract_id'] ?? 0) ?>">合同详情</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="receivableStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">调整分期状态</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="receivableStatusInstallmentId" value="">
                <div class="mb-3">
                    <label class="form-label">目标状态</label>
                    <select class="form-select" id="receivableStatusNewStatus" onchange="toggleReceivableMethodWrap()">
                        <option value="待收">待收</option>
                        <option value="催款">催款</option>
                        <option value="已收">已收</option>
                    </select>
                </div>
                <div class="mb-3" id="receivableMethodWrap" style="display:none;">
                    <label class="form-label">收款方式</label>
                    <select class="form-select" id="receivableStatusMethod">
                        <?= renderPaymentMethodOptions() ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">原因（必填）</label>
                    <input type="text" class="form-control" id="receivableStatusReason" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitReceivableStatusChange()">提交</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="collectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">催款记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="small text-muted">分期ID：<span id="collectionInstallmentIdText"></span></div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <form id="collectionForm" class="row g-3">
                            <input type="hidden" name="installment_id" id="collectionInstallmentId">
                            <div class="col-md-3">
                                <label class="form-label">方式</label>
                                <input type="text" class="form-control" name="method" maxlength="30" placeholder="电话/微信/上门等">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">结果</label>
                                <input type="text" class="form-control" name="result" maxlength="50" placeholder="承诺/失联/已付等">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">备注</label>
                                <input type="text" class="form-control" name="note" maxlength="255" placeholder="可选，最多255字">
                            </div>
                            <div class="col-12">
                                <button type="button" class="btn btn-success" id="btnSaveCollection">新增记录</button>
                                <button type="button" class="btn btn-outline-secondary" id="btnRefreshCollection">刷新</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                <tr>
                                    <th>时间</th>
                                    <th>催款人</th>
                                    <th>方式</th>
                                    <th>结果</th>
                                    <th>备注</th>
                                </tr>
                                </thead>
                                <tbody id="collectionTableBody">
                                <tr><td colspan="5" class="text-center text-muted">加载中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function apiUrl(path) {
    return API_URL + '/' + path;
}
function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
}

// 时间周期自动填充日期
document.getElementById('periodSelect')?.addEventListener('change', function() {
    const period = this.value;
    const dueStartInput = document.querySelector('input[name="due_start"]');
    const dueEndInput = document.querySelector('input[name="due_end"]');
    
    if (!dueStartInput || !dueEndInput) return;
    
    const now = new Date();
    const pad = (v) => String(v).padStart(2, '0');
    
    if (period === 'this_month') {
        // 本月：当月第一天到最后一天
        const year = now.getFullYear();
        const month = now.getMonth() + 1;
        const firstDay = year + '-' + pad(month) + '-01';
        const lastDay = new Date(year, month, 0).getDate();
        const lastDayStr = year + '-' + pad(month) + '-' + pad(lastDay);
        dueStartInput.value = firstDay;
        dueEndInput.value = lastDayStr;
    } else if (period === 'last_month') {
        // 上月：上月第一天到最后一天
        const lastMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const year = lastMonthDate.getFullYear();
        const month = lastMonthDate.getMonth() + 1;
        const firstDay = year + '-' + pad(month) + '-01';
        const lastDay = new Date(year, month, 0).getDate();
        const lastDayStr = year + '-' + pad(month) + '-' + pad(lastDay);
        dueStartInput.value = firstDay;
        dueEndInput.value = lastDayStr;
    }
    // custom 时不自动填充，保持用户手动输入的值
});

// 状态修改功能
function toggleReceivableMethodWrap() {
    const status = document.getElementById('receivableStatusNewStatus')?.value || '';
    const wrap = document.getElementById('receivableMethodWrap');
    if (wrap) wrap.style.display = (status === '已收') ? '' : 'none';
}

function openReceivableStatusModal(installmentId) {
    document.getElementById('receivableStatusInstallmentId').value = String(installmentId || 0);
    document.getElementById('receivableStatusNewStatus').value = '待收';
    document.getElementById('receivableStatusReason').value = '';
    toggleReceivableMethodWrap();
    const modalEl = document.getElementById('receivableStatusModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

function submitReceivableStatusChange() {
    const installmentId = Number(document.getElementById('receivableStatusInstallmentId')?.value || 0);
    const newStatus = document.getElementById('receivableStatusNewStatus')?.value || '';
    const reason = (document.getElementById('receivableStatusReason')?.value || '').trim();
    
    if (!installmentId) { showAlertModal('参数错误', 'error'); return; }
    if (!newStatus) { showAlertModal('请选择状态', 'warning'); return; }
    if (!reason) { showAlertModal('请填写原因', 'warning'); return; }

    // 关闭弹窗
    const modalEl = document.getElementById('receivableStatusModal');
    if (modalEl) bootstrap.Modal.getInstance(modalEl)?.hide();

    // 如果是已收，需要调用收款登记
    if (newStatus === '已收') {
        const receivedDate = new Date().toISOString().slice(0, 10);
        const method = document.getElementById('receivableStatusMethod')?.value || '';
        fetch(API_URL + '/finance_installment_get.php?id=' + installmentId)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { showAlertModal(res.message || '查询分期失败', 'error'); return; }
                const unpaid = Number(res.data?.amount_unpaid || 0);
                if (unpaid <= 0) { showAlertModal('该分期未收金额为0', 'warning'); return; }
                
                const fd = new FormData();
                fd.append('installment_id', String(installmentId));
                fd.append('received_date', receivedDate);
                fd.append('amount_received', String(unpaid.toFixed(2)));
                fd.append('method', method);
                fd.append('note', '状态改为已收：' + reason);
                
                fetch(API_URL + '/finance_receipt_save.php', { method: 'POST', body: fd })
                    .then(r2 => r2.json())
                    .then(res2 => {
                        if (!res2.success) { showAlertModal(res2.message || '登记收款失败', 'error'); return; }
                        showAlertModal('已登记收款并更新为已收', 'success', () => location.reload());
                    })
                    .catch(() => showAlertModal('登记收款失败', 'error'));
            })
            .catch(() => showAlertModal('查询分期失败', 'error'));
        return;
    }

    // 普通状态更新
    const fd = new FormData();
    fd.append('installment_id', String(installmentId));
    fd.append('new_status', newStatus);
    fd.append('reason', reason);
    
    fetch(API_URL + '/finance_installment_status_update.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { showAlertModal(res.message || '提交失败', 'error'); return; }
            showAlertModal('已更新状态', 'success', () => location.reload());
        })
        .catch(() => showAlertModal('提交失败', 'error'));
}

function fmtTime(ts) {
    const t = Number(ts || 0);
    if (!t) return '-';
    const d = new Date(t * 1000);
    const pad = (v) => String(v).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
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

function statusRank(label) {
    const v = String(label || '').trim();
    const order = {
        '未结清': 1,
        '已结清': 2,
        '作废': 3
    };
    return order[v] || 99;
}

let recvSortKey = '';
let recvSortDir = 'asc';

function getRecvSortVal(tr, key) {
    if (!tr) return '';
    if (key === 'create_time') return Number(tr.getAttribute('data-contract-create-time') || 0);
    if (key === 'receipt_time') return String(tr.getAttribute('data-contract-last-received-date') || '');
    if (key === 'status') return String(tr.getAttribute('data-contract-status-label') || '');
    return '';
}

function compareRecvBlocks(a, b) {
    const ia = parseInt(a.getAttribute('data-orig-index') || '0', 10) || 0;
    const ib = parseInt(b.getAttribute('data-orig-index') || '0', 10) || 0;
    if (!recvSortKey) return ia - ib;

    if (recvSortKey === 'create_time') {
        const va = Number(getRecvSortVal(a, recvSortKey) || 0);
        const vb = Number(getRecvSortVal(b, recvSortKey) || 0);
        const d = va - vb;
        return recvSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (recvSortKey === 'receipt_time') {
        const va = String(getRecvSortVal(a, recvSortKey) || '');
        const vb = String(getRecvSortVal(b, recvSortKey) || '');
        const da = va === '' ? '0000-00-00' : va;
        const db = vb === '' ? '0000-00-00' : vb;
        const d = da.localeCompare(db);
        return recvSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    if (recvSortKey === 'status') {
        const ra = statusRank(getRecvSortVal(a, recvSortKey));
        const rb = statusRank(getRecvSortVal(b, recvSortKey));
        const d = ra - rb;
        return recvSortDir === 'asc' ? (d || (ia - ib)) : ((-d) || (ia - ib));
    }

    return ia - ib;
}

function getRecvGroupVal(tr, key) {
    if (!key) return '';
    if (key === 'status') return String(tr.getAttribute('data-contract-status-label') || '').trim() || '未知';
    if (key === 'create_month') {
        const m = normalizeMonthByUnixTs(tr.getAttribute('data-contract-create-time'));
        return m || '未知';
    }
    if (key === 'receipt_month') {
        const m = normalizeMonthByDateStr(tr.getAttribute('data-contract-last-received-date'));
        return m || '未收款';
    }
    return '未知';
}

function buildRecvGroupLabel(key, val) {
    if (key === 'status') return '状态：' + val;
    if (key === 'create_month') return '创建：' + val;
    if (key === 'receipt_month') return '收款：' + val;
    return val;
}

function refreshReceivablesView() {
    const table = document.getElementById('myReceivablesTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    tbody.querySelectorAll('tr.recv-group-row').forEach(r => r.remove());

    const contractRows = Array.from(tbody.querySelectorAll('tr.contract-row'));
    if (!contractRows.length) return;
    contractRows.forEach((tr, idx) => {
        if (!tr.getAttribute('data-orig-index')) tr.setAttribute('data-orig-index', String(idx));
    });

    const g1 = (document.getElementById('recvGroup1')?.value || '').trim();
    const g2 = (document.getElementById('recvGroup2')?.value || '').trim();
    const groups = [g1, g2].filter(v => v);

    const blocks = contractRows.map(cr => {
        const key = cr.getAttribute('data-contract-row') || '';
        const children = key ? Array.from(tbody.querySelectorAll('tr.installment-row[data-parent="' + key.replace(/"/g, '') + '"]')) : [];
        return { key, head: cr, children };
    });

    blocks.forEach(b => {
        try { b.head.remove(); } catch (e) {}
        b.children.forEach(r => { try { r.remove(); } catch (e) {} });
    });

    const sortBlocks = (list) => list.slice().sort((a, b) => compareRecvBlocks(a.head, b.head));

    if (groups.length === 0) {
        sortBlocks(blocks).forEach(b => {
            tbody.appendChild(b.head);
            b.children.forEach(r => tbody.appendChild(r));
        });
        return;
    }

    const groupOnce = (list, key) => {
        const m = new Map();
        list.forEach(b => {
            const gv = getRecvGroupVal(b.head, key);
            if (!m.has(gv)) m.set(gv, []);
            m.get(gv).push(b);
        });
        return Array.from(m.entries());
    };

    const build = (list, level) => {
        const key = groups[level];
        const entries = groupOnce(list, key);
        const ordered = entries.sort((a, b) => String(a[0]).localeCompare(String(b[0])));
        ordered.forEach(([val, items]) => {
            const header = document.createElement('tr');
            header.className = 'table-light recv-group-row';
            const td = document.createElement('td');
            td.colSpan = 11;
            td.innerHTML = '<div class="d-flex justify-content-between align-items-center">'
                + '<div class="fw-semibold">' + esc(buildRecvGroupLabel(key, val)) + '</div>'
                + '<div class="small text-muted">' + String(items.length) + ' 条</div>'
                + '</div>';
            header.appendChild(td);
            tbody.appendChild(header);

            if (level + 1 < groups.length) {
                build(items, level + 1);
            } else {
                sortBlocks(items).forEach(b => {
                    tbody.appendChild(b.head);
                    b.children.forEach(r => tbody.appendChild(r));
                });
            }
        });
    };

    build(blocks, 0);
}

let collectionModal = null;
let currentCollectionInstallmentId = 0;

function openCollectionModal(installmentId) {
    currentCollectionInstallmentId = Number(installmentId || 0);
    document.getElementById('collectionInstallmentId').value = String(currentCollectionInstallmentId);
    document.getElementById('collectionInstallmentIdText').textContent = String(currentCollectionInstallmentId);
    document.getElementById('collectionForm').reset();
    document.getElementById('collectionInstallmentId').value = String(currentCollectionInstallmentId);
    if (!collectionModal) {
        collectionModal = new bootstrap.Modal(document.getElementById('collectionModal'));
    }
    collectionModal.show();
    refreshCollectionLogs();
}

function renderCollectionRows(rows) {
    const tbody = document.getElementById('collectionTableBody');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">暂无记录</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        return '<tr>'
            + '<td>' + esc(fmtTime(r.action_time)) + '</td>'
            + '<td>' + esc(r.actor_name || '') + '</td>'
            + '<td>' + esc(r.method || '') + '</td>'
            + '<td>' + esc(r.result || '') + '</td>'
            + '<td>' + esc(r.note || '') + '</td>'
            + '</tr>';
    }).join('');
}

function refreshCollectionLogs() {
    const iid = Number(currentCollectionInstallmentId || 0);
    if (!iid) return;
    const tbody = document.getElementById('collectionTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">加载中...</td></tr>';
    fetch(apiUrl('finance_collection_log_list.php?installment_id=' + iid + '&limit=50'))
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal(res.message || '加载失败', 'error');
                renderCollectionRows([]);
                return;
            }
            renderCollectionRows(res.data || []);
        })
        .catch(() => {
            showAlertModal('加载失败，请查看控制台错误信息', 'error');
            renderCollectionRows([]);
        });
}

document.querySelectorAll('.btnCollection').forEach(btn => {
    btn.addEventListener('click', function() {
        openCollectionModal(this.getAttribute('data-installment-id'));
    });
});

document.querySelectorAll('.btnToggle').forEach(btn => {
    btn.addEventListener('click', function() {
        const key = this.getAttribute('data-target');
        if (!key) return;
        const rows = document.querySelectorAll('tr.installment-row[data-parent="' + key.replace(/"/g, '') + '"]');
        const showing = rows.length > 0 && rows[0].style.display !== 'none';
        rows.forEach(r => r.style.display = showing ? 'none' : '');
        this.textContent = showing ? '▸' : '▾';
    });
});

document.querySelectorAll('.recvSortBtn').forEach(btn => {
    btn.addEventListener('click', function() {
        const key = String(this.getAttribute('data-sort') || '');
        if (!key) return;
        if (recvSortKey === key) {
            recvSortDir = (recvSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            recvSortKey = key;
            if (key === 'create_time' || key === 'receipt_time') recvSortDir = 'desc';
            else recvSortDir = 'asc';
        }
        refreshReceivablesView();
    });
});

document.getElementById('recvGroup1')?.addEventListener('change', refreshReceivablesView);
document.getElementById('recvGroup2')?.addEventListener('change', refreshReceivablesView);
refreshReceivablesView();

document.getElementById('btnRefreshCollection').addEventListener('click', refreshCollectionLogs);

document.getElementById('btnSaveCollection').addEventListener('click', function() {
    const form = document.getElementById('collectionForm');
    const fd = new FormData(form);
    const iid = Number(fd.get('installment_id') || 0);
    if (!iid) {
        showAlertModal('分期ID错误', 'error');
        return;
    }
    fetch(apiUrl('finance_collection_log_save.php'), {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            showAlertModal(res.message || '保存失败', 'error');
            return;
        }
        showAlertModal('已记录催款', 'success', function() {
            refreshCollectionLogs();
        });
    })
    .catch(() => {
        showAlertModal('保存失败，请查看控制台错误信息', 'error');
    });
});

// 列显隐功能
if (typeof initColumnToggle === 'function') {
    initColumnToggle({
        tableId: 'myReceivablesTable',
        storageKey: 'my_receivables_columns',
        columns: [
            { index: 1, name: '客户', default: true },
            { index: 2, name: '客户群', default: true },
            { index: 3, name: '活动标签', default: true },
            { index: 4, name: '合同', default: true },
            { index: 5, name: '最近到期', default: true },
            { index: 6, name: '应收合计', default: true },
            { index: 7, name: '已收合计', default: true },
            { index: 8, name: '未收合计', default: true },
            { index: 9, name: '最大逾期', default: true },
            { index: 10, name: '合同状态', default: true },
        ],
        buttonContainer: '#columnToggleContainer'
    });
}

// 加载分期凭证缩略图
document.querySelectorAll('.inst-file-thumb').forEach(function(thumb) {
    const instId = thumb.dataset.installmentId;
    if (!instId) return;
    fetch(apiUrl('finance_installment_files.php?installment_id=' + instId))
        .then(r => r.json())
        .then(res => {
            const files = (res.success && res.data) ? res.data : [];
            if (files.length === 0) {
                thumb.innerHTML = '<span style="font-size:9px;text-align:center;">点击<br>上传</span>';
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
            thumb.innerHTML = '<span style="font-size:9px;">加载失败</span>';
        });
    
    // 点击事件
    thumb.addEventListener('click', function() {
        const filesJson = this.dataset.filesJson;
        const files = filesJson ? JSON.parse(filesJson) : [];
        const instId = this.dataset.installmentId;
        if (files.length === 0) {
            // 无凭证时打开上传弹窗
            if (instId) {
                showUploadModal(instId);
            } else {
                showAlertModal('无法上传，缺少分期ID', 'error');
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

// 动态刷新凭证缩略图
function refreshInstallmentThumb(instId) {
    const thumb = document.querySelector('.inst-file-thumb[data-installment-id="' + instId + '"]');
    if (!thumb) return;
    thumb.innerHTML = '<span class="thumb-loading">...</span>';
    fetch(apiUrl('finance_installment_files.php?installment_id=' + instId))
        .then(r => r.json())
        .then(res => {
            const files = (res.success && res.data) ? res.data : [];
            if (files.length === 0) {
                thumb.innerHTML = '<span style="font-size:9px;text-align:center;">点击<br>上传</span>';
                thumb.style.color = '#999';
                thumb.style.borderColor = '#ccc';
                thumb.style.borderStyle = 'dashed';
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
            thumb.innerHTML = '<span style="font-size:9px;">加载失败</span>';
        });
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
        fetch(apiUrl('finance_installment_file_upload.php'), { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => { cleanup(); if (!res.success) { showAlertModal(res.message || '上传失败', 'error'); return; } showAlertModal('上传成功', 'success'); refreshInstallmentThumb(installmentId); })
            .catch(() => { cleanup(); showAlertModal('上传失败', 'error'); });
    }
}

// 灯箱预览图片
function showImageLightbox(url, files) {
    const existing = document.getElementById('imageLightbox');
    if (existing) existing.remove();
    
    let currentIndex = 0;
    const imageFiles = (files || []).filter(f => /^image\//i.test(f.file_type));
    if (imageFiles.length === 0) imageFiles.push({ file_id: 0, url: url });
    
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
    
    const escHandler = function(e) { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);
    document.body.appendChild(overlay);
}
</script>

<nav class="mt-3">
    <ul class="pagination justify-content-center">
            <?php
            $params = $_GET;
            $params['page'] = 'my_receivables';
            $params['per_page'] = $perPage;
            $params['page_num'] = 1;
            $firstUrl = 'index.php?' . http_build_query($params);
            $params['page_num'] = max(1, $page - 1);
            $prevUrl = 'index.php?' . http_build_query($params);
            $params['page_num'] = min($totalPages, $page + 1);
            $nextUrl = 'index.php?' . http_build_query($params);
            $params['page_num'] = $totalPages;
            $lastUrl = 'index.php?' . http_build_query($params);
            ?>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($firstUrl) ?>">首页</a></li>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($prevUrl) ?>">上一页</a></li>
            <li class="page-item disabled"><span class="page-link"><?= $page ?> / <?= $totalPages ?></span></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($nextUrl) ?>">下一页</a></li>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= htmlspecialchars($lastUrl) ?>">末页</a></li>
    </ul>
</nav>

<?php
finance_sidebar_end();
layout_footer();
