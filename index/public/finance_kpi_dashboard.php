<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限访问数据仪表盘。</div>';
    layout_footer();
    exit;
}

layout_header('财务数据仪表盘');

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$period = trim((string)($_GET['period'] ?? 'this_month'));
$signStart = trim((string)($_GET['sign_start'] ?? $monthStart));
$signEnd = trim((string)($_GET['sign_end'] ?? $today));
$recvStart = trim((string)($_GET['recv_start'] ?? $monthStart));
$recvEnd = trim((string)($_GET['recv_end'] ?? $today));
$dueStart = trim((string)($_GET['due_start'] ?? $monthStart));
$dueEnd = trim((string)($_GET['due_end'] ?? $today));

if ($period === 'this_month') {
    $signStart = $monthStart;
    $signEnd = $today;
    $recvStart = $monthStart;
    $recvEnd = $today;
    $dueStart = $monthStart;
    $dueEnd = $today;
} elseif ($period === 'last_month') {
    $ts = strtotime($monthStart . ' -1 month');
    $ms = date('Y-m-01', $ts);
    $me = date('Y-m-t', $ts);
    $signStart = $ms;
    $signEnd = $me;
    $recvStart = $ms;
    $recvEnd = $me;
    $dueStart = $ms;
    $dueEnd = $me;
} else {
    $period = 'custom';
}

$salesUsers = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname');

$salesUserIdsRaw = trim((string)($_GET['sales_user_ids'] ?? ''));
$selectedIds = [];
if ($salesUserIdsRaw !== '') {
    foreach (explode(',', $salesUserIdsRaw) as $p) {
        $v = (int)trim($p);
        if ($v > 0) $selectedIds[$v] = true;
    }
}

if (empty($selectedIds)) {
    $selectedIds = [];
}

// 销售只能查看自己的数据，忽略传入的 sales_user_ids
if (($user['role'] ?? '') === 'sales') {
    $selectedIds = [(int)($user['id'] ?? 0) => true];
    $salesUserIdsRaw = (string)((int)($user['id'] ?? 0));
}

function buildInClause(array $ids, string $prefix, array &$params): string {
    if (empty($ids)) return '';
    $keys = [];
    $i = 0;
    foreach ($ids as $id => $_) {
        $k = $prefix . $i;
        $keys[] = ':' . $k;
        $params[$k] = (int)$id;
        $i++;
    }
    return implode(',', $keys);
}

$baseWhere = ' WHERE 1=1';
$baseParams = [];

// 默认不统计作废合同
$baseWhere .= ' AND c.status <> "void"';
if (!empty($selectedIds)) {
    $in = buildInClause($selectedIds, 'sid_', $baseParams);
    $baseWhere .= ' AND c.sales_user_id IN (' . $in . ')';
}

// 1) 签约总价（合同总价 gross）
$grossSql = 'SELECT c.sales_user_id, SUM(c.gross_amount) AS gross_amount
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id'
    . $baseWhere
    . ' AND c.sign_date >= :sign_start AND c.sign_date <= :sign_end
    GROUP BY c.sales_user_id';
$grossParams = $baseParams;
$grossParams['sign_start'] = $signStart;
$grossParams['sign_end'] = $signEnd;
$grossRows = Db::query($grossSql, $grossParams);
$grossMap = [];
foreach ($grossRows as $r) {
    $grossMap[(int)$r['sales_user_id']] = (float)($r['gross_amount'] ?? 0);
}

// 2) 签约额（合同净额 net）
$signedSql = 'SELECT c.sales_user_id, SUM(c.net_amount) AS signed_amount
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id'
    . $baseWhere
    . ' AND c.sign_date >= :sign_start AND c.sign_date <= :sign_end
    GROUP BY c.sales_user_id';
$signedParams = $baseParams;
$signedParams['sign_start'] = $signStart;
$signedParams['sign_end'] = $signEnd;
$signedRows = Db::query($signedSql, $signedParams);
$signedMap = [];
foreach ($signedRows as $r) {
    $signedMap[(int)$r['sales_user_id']] = (float)($r['signed_amount'] ?? 0);
}

// 3) 回款额（按收款日期汇总，使用冲抵金额 amount_applied）
$recvSql = 'SELECT c.sales_user_id, SUM(r.amount_applied) AS received_amount
    FROM finance_receipts r
    INNER JOIN finance_contracts c ON c.id = r.contract_id
    INNER JOIN customers cu ON cu.id = r.customer_id'
    . $baseWhere
    . ' AND r.received_date >= :recv_start AND r.received_date <= :recv_end
    GROUP BY c.sales_user_id';
$recvParams = $baseParams;
$recvParams['recv_start'] = $recvStart;
$recvParams['recv_end'] = $recvEnd;
$recvRows = Db::query($recvSql, $recvParams);
$recvMap = [];
foreach ($recvRows as $r) {
    $recvMap[(int)$r['sales_user_id']] = (float)($r['received_amount'] ?? 0);
}

// 4) 应收/逾期（按到期日区间汇总分期未收；逾期按当前日期判断）
$arSql = 'SELECT c.sales_user_id,
        SUM(GREATEST(i.amount_due - i.amount_paid, 0)) AS receivable_amount,
        SUM(CASE WHEN i.due_date < CURDATE() THEN GREATEST(i.amount_due - i.amount_paid, 0) ELSE 0 END) AS overdue_amount
    FROM finance_installments i
    INNER JOIN finance_contracts c ON c.id = i.contract_id
    INNER JOIN customers cu ON cu.id = i.customer_id'
    . $baseWhere . ' AND i.deleted_at IS NULL'
    . ' AND i.due_date >= :due_start AND i.due_date <= :due_end
    GROUP BY c.sales_user_id';
$arParams = $baseParams;
$arParams['due_start'] = $dueStart;
$arParams['due_end'] = $dueEnd;
$arRows = Db::query($arSql, $arParams);
$arMap = [];
foreach ($arRows as $r) {
    $sid = (int)$r['sales_user_id'];
    $arMap[$sid] = [
        'receivable' => (float)($r['receivable_amount'] ?? 0),
        'overdue' => (float)($r['overdue_amount'] ?? 0),
    ];
}

// 汇总销售列表
$salesSet = [];
foreach (array_keys($grossMap) as $sid) { $salesSet[$sid] = true; }
foreach (array_keys($signedMap) as $sid) { $salesSet[$sid] = true; }
foreach (array_keys($recvMap) as $sid) { $salesSet[$sid] = true; }
foreach (array_keys($arMap) as $sid) { $salesSet[$sid] = true; }

if (!empty($selectedIds)) {
    $salesSet = $selectedIds;
}

$salesIds = array_keys($salesSet);
sort($salesIds);

$nameMap = [];
foreach ($salesUsers as $su) {
    $nameMap[(int)$su['id']] = $su['realname'] ?? (string)$su['id'];
}

$totalGross = 0.0;
$totalSigned = 0.0;
$totalRecv = 0.0;
$totalAr = 0.0;
$totalOverdue = 0.0;
foreach ($salesIds as $sid) {
    $totalGross += (float)($grossMap[$sid] ?? 0);
    $totalSigned += (float)($signedMap[$sid] ?? 0);
    $totalRecv += (float)($recvMap[$sid] ?? 0);
    $totalAr += (float)($arMap[$sid]['receivable'] ?? 0);
    $totalOverdue += (float)($arMap[$sid]['overdue'] ?? 0);
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">财务数据仪表盘</h3>
    <div>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">返回财务工作台</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="finance_kpi_dashboard">

            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">时间范围</label>
                <select class="form-select" name="period" id="kpiPeriodSelect">
                    <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>本月</option>
                    <option value="last_month" <?= $period === 'last_month' ? 'selected' : '' ?>>上月</option>
                    <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>自定义</option>
                </select>
            </div>

            <div class="col-lg-4 col-md-6 col-sm-12">
                <label class="form-label">人员（可多选）</label>
                <?php if (($user['role'] ?? '') === 'sales'): ?>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($user['realname'] ?? '本人') ?>" disabled>
                    <input type="hidden" name="sales_user_ids" id="salesUserIds" value="<?= htmlspecialchars($salesUserIdsRaw) ?>">
                    <div class="small text-muted">销售仅可查看本人数据</div>
                <?php else: ?>
                    <select class="form-select" name="sales_user_ids_select[]" multiple size="6" id="salesUserSelect">
                        <?php foreach ($salesUsers as $su): ?>
                            <?php $sid = (int)($su['id'] ?? 0); ?>
                            <option value="<?= $sid ?>" <?= isset($selectedIds[$sid]) ? 'selected' : '' ?>><?= htmlspecialchars($su['realname'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="sales_user_ids" id="salesUserIds" value="<?= htmlspecialchars($salesUserIdsRaw) ?>">
                    <div class="small text-muted">不选=全部销售</div>
                <?php endif; ?>
            </div>

            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">签约起</label>
                <input type="date" class="form-control" name="sign_start" value="<?= htmlspecialchars($signStart) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">签约止</label>
                <input type="date" class="form-control" name="sign_end" value="<?= htmlspecialchars($signEnd) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">收款起</label>
                <input type="date" class="form-control" name="recv_start" value="<?= htmlspecialchars($recvStart) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">收款止</label>
                <input type="date" class="form-control" name="recv_end" value="<?= htmlspecialchars($recvEnd) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">到期起</label>
                <input type="date" class="form-control" name="due_start" value="<?= htmlspecialchars($dueStart) ?>">
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6">
                <label class="form-label">到期止</label>
                <input type="date" class="form-control" name="due_end" value="<?= htmlspecialchars($dueEnd) ?>">
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-primary" id="btnFilter">筛选</button>
                <a class="btn btn-outline-secondary" href="index.php?page=finance_kpi_dashboard">重置</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="small text-muted">签约总价（区间）</div>
            <div class="fs-4 fw-bold"><?= number_format($totalGross, 2) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($signStart) ?> ~ <?= htmlspecialchars($signEnd) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="small text-muted">签约折后（区间）</div>
            <div class="fs-4 fw-bold"><?= number_format($totalSigned, 2) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($signStart) ?> ~ <?= htmlspecialchars($signEnd) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="small text-muted">回款额（区间）</div>
            <div class="fs-4 fw-bold"><?= number_format($totalRecv, 2) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($recvStart) ?> ~ <?= htmlspecialchars($recvEnd) ?></div>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="small text-muted">应收未收（到期区间）</div>
            <div class="fs-4 fw-bold"><?= number_format($totalAr, 2) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($dueStart) ?> ~ <?= htmlspecialchars($dueEnd) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card"><div class="card-body">
            <div class="small text-muted">逾期未收（到期区间且已逾期）</div>
            <div class="fs-4 fw-bold"><?= number_format($totalOverdue, 2) ?></div>
            <div class="small text-muted">逾期判定：到期日 < 今天</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>销售</th>
                    <th class="text-end">签约总价</th>
                    <th class="text-end">签约折后</th>
                    <th class="text-end">回款额</th>
                    <th class="text-end">未收应收</th>
                    <th class="text-end">逾期未收</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($salesIds)): ?>
                    <tr><td colspan="6" class="text-center text-muted">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($salesIds as $sid): ?>
                        <?php
                        $nm = $nameMap[(int)$sid] ?? (string)$sid;
                        $g = (float)($grossMap[$sid] ?? 0);
                        $a = (float)($signedMap[$sid] ?? 0);
                        $b = (float)($recvMap[$sid] ?? 0);
                        $c = (float)($arMap[$sid]['receivable'] ?? 0);
                        $d = (float)($arMap[$sid]['overdue'] ?? 0);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($nm) ?></td>
                            <td class="text-end"><?= number_format($g, 2) ?></td>
                            <td class="text-end"><?= number_format($a, 2) ?></td>
                            <td class="text-end"><?= number_format($b, 2) ?></td>
                            <td class="text-end"><?= number_format($c, 2) ?></td>
                            <td class="text-end"><?= number_format($d, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light">
                        <td class="fw-semibold">合计</td>
                        <td class="text-end fw-semibold"><?= number_format($totalGross, 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($totalSigned, 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($totalRecv, 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($totalAr, 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($totalOverdue, 2) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// 时间周期自动填充日期
document.getElementById('kpiPeriodSelect')?.addEventListener('change', function() {
    const period = this.value;
    const now = new Date();
    const pad = (v) => String(v).padStart(2, '0');
    
    let firstDay, lastDay;
    
    if (period === 'this_month') {
        const year = now.getFullYear();
        const month = now.getMonth() + 1;
        firstDay = year + '-' + pad(month) + '-01';
        const lastDayNum = new Date(year, month, 0).getDate();
        lastDay = year + '-' + pad(month) + '-' + pad(lastDayNum);
    } else if (period === 'last_month') {
        const lastMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const year = lastMonthDate.getFullYear();
        const month = lastMonthDate.getMonth() + 1;
        firstDay = year + '-' + pad(month) + '-01';
        const lastDayNum = new Date(year, month, 0).getDate();
        lastDay = year + '-' + pad(month) + '-' + pad(lastDayNum);
    } else {
        return;
    }
    
    document.querySelector('input[name="sign_start"]').value = firstDay;
    document.querySelector('input[name="sign_end"]').value = lastDay;
    document.querySelector('input[name="recv_start"]').value = firstDay;
    document.querySelector('input[name="recv_end"]').value = lastDay;
    document.querySelector('input[name="due_start"]').value = firstDay;
    document.querySelector('input[name="due_end"]').value = lastDay;
});

(function() {
    function syncSelected() {
        var sel = document.getElementById('salesUserSelect');
        var ids = [];
        if (sel && sel.selectedOptions) {
            for (var i = 0; i < sel.selectedOptions.length; i++) {
                ids.push(sel.selectedOptions[i].value);
            }
        }
        document.getElementById('salesUserIds').value = ids.join(',');
    }

    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            syncSelected();
        });
    }

    var sel = document.getElementById('salesUserSelect');
    if (sel) {
        sel.addEventListener('change', syncSelected);
        syncSelected();
    }
})();
</script>

<?php
layout_footer();
