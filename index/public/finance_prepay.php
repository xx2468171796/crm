<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_PREPAY)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅财务/管理员可访问预收款台账。</div>';
    layout_footer();
    exit;
}

layout_header('预收款台账');
finance_sidebar_start('finance_prepay');

$keyword = trim($_GET['keyword'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$minBalanceStr = trim($_GET['min_balance'] ?? '');
$customerId = (int)($_GET['customer_id'] ?? 0);

$page = max(1, (int)($_GET['page_num'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

$minBalance = null;
if ($minBalanceStr !== '' && is_numeric($minBalanceStr)) {
    $minBalance = (float)$minBalanceStr;
}

echo '<div class="d-flex justify-content-between align-items-center mb-3">'
    . '<h3 class="mb-0">预收款台账</h3>'
    . '<div>'
    . '<a class="btn btn-outline-primary btn-sm me-2" href="index.php?page=finance_receipts">收款登记</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_dashboard">返回财务工作台</a>'
    . '</div>'
    . '</div>';

if ($customerId > 0) {
    $cust = Db::queryOne('SELECT id, name, mobile, customer_code, activity_tag FROM customers WHERE id = :id LIMIT 1', ['id' => $customerId]);
    if (!$cust) {
        echo '<div class="alert alert-danger">客户不存在</div>';
        layout_footer();
        exit;
    }

    $balanceRow = Db::queryOne('SELECT
            COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS balance
        FROM finance_prepay_ledger
        WHERE customer_id = :cid', ['cid' => $customerId]);
    $balance = (float)($balanceRow['balance'] ?? 0);

    $openInstallments = Db::query(
        'SELECT
            i.id,
            i.installment_no,
            i.due_date,
            i.amount_due,
            i.amount_paid,
            i.status,
            c.contract_no,
            c.title AS contract_title
        FROM finance_installments i
        INNER JOIN finance_contracts c ON c.id = i.contract_id
        WHERE i.customer_id = :cid AND i.deleted_at IS NULL AND (i.amount_due - i.amount_paid) > 0.00001
        ORDER BY i.due_date ASC, i.id ASC',
        ['cid' => $customerId]
    );

    $sql = 'SELECT * FROM finance_prepay_ledger WHERE customer_id = :cid ORDER BY id DESC';
    $params = ['cid' => $customerId];

    $countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') AS t';
    $total = (int)(Db::queryOne($countSql, $params)['total'] ?? 0);
    $totalPages = (int)ceil($total / $perPage);

    $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $rows = Db::query($sql, $params);

    echo '<div class="card mb-3"><div class="card-body">'
        . '<div class="d-flex justify-content-between align-items-center">'
        . '<div>'
        . '<div class="fw-semibold">' . htmlspecialchars($cust['name'] ?? '') . '</div>'
        . '<div class="small text-muted">' . htmlspecialchars(($cust['customer_code'] ?? '') . ' ' . ($cust['mobile'] ?? '') . ' ' . ($cust['activity_tag'] ?? '')) . '</div>'
        . '</div>'
        . '<div class="text-end">'
        . '<div class="small text-muted">当前预收余额</div>'
        . '<div class="fs-4 fw-bold">' . number_format($balance, 2) . '</div>'
        . '</div>'
        . '</div>'
        . '<div class="mt-2">'
        . '<button type="button" class="btn btn-outline-primary btn-sm me-2" id="btnManualAdjust">新增预收/手工调整</button>'
        . '<a class="btn btn-outline-secondary btn-sm" href="index.php?page=finance_prepay">返回列表</a>'
        . '</div>'
        . '</div></div>';

    ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div class="fw-semibold">预收核销到分期</div>
                    <div class="small text-muted">从该客户预收余额中扣减（out），冲抵某一期分期已收金额，并留痕。</div>
                </div>
            </div>

            <?php if ($balance <= 0.00001): ?>
                <div class="alert alert-warning mb-0">当前预收余额为 0，无法核销。</div>
            <?php elseif (empty($openInstallments)): ?>
                <div class="alert alert-info mb-0">该客户暂无未结清分期。</div>
            <?php else: ?>
                <form id="prepayApplyForm" class="row g-3">
                    <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

                    <div class="col-md-6">
                        <label class="form-label">选择分期</label>
                        <select class="form-select" name="installment_id" id="applyInstallmentId" required>
                            <?php foreach ($openInstallments as $it): ?>
                                <?php
                                $unpaid = max(0.0, (float)$it['amount_due'] - (float)$it['amount_paid']);
                                $label =
                                    '分期ID=' . (int)$it['id']
                                    . ' | ' . ($it['contract_no'] ?? '')
                                    . ' | 第' . (int)$it['installment_no'] . '期'
                                    . ' | 到期:' . ($it['due_date'] ?? '')
                                    . ' | 未收:' . number_format($unpaid, 2);
                                ?>
                                <option value="<?= (int)$it['id'] ?>" data-unpaid="<?= htmlspecialchars((string)$unpaid) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">核销金额</label>
                        <input type="number" step="0.01" min="0" class="form-control" name="amount" id="applyAmount" required>
                        <div class="small text-muted" id="applyHint"></div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">备注</label>
                        <input type="text" class="form-control" name="note" id="applyNote" maxlength="255" placeholder="可选，最多255字">
                    </div>

                    <div class="col-12">
                        <button type="button" class="btn btn-success" id="btnApply">提交核销</button>
                    </div>
                </form>

<script data-prepay-apply="1">
                window._prepayApplyInit = function() {
                    function apiUrl(path) { return API_URL + '/' + path; }
                    function fmt(n) { return Number(n || 0).toFixed(2); }
                    const prepayBalance = Number('<?= htmlspecialchars((string)$balance) ?>');
                    function updateApplyHint() {
                        const sel = document.getElementById('applyInstallmentId');
                        const opt = sel && sel.selectedOptions && sel.selectedOptions[0];
                        const unpaid = Number((opt && opt.getAttribute('data-unpaid')) || 0);
                        const amt = Number(document.getElementById('applyAmount').value || 0);
                        const maxAllow = Math.max(0, Math.min(prepayBalance, unpaid));
                        const hint = document.getElementById('applyHint');
                        hint.textContent = '可用预收：' + fmt(prepayBalance) + '；该分期未收：' + fmt(unpaid) + '；本次最大可核销：' + fmt(maxAllow);
                        if (amt > maxAllow + 0.00001) hint.textContent += '；当前输入超出可核销范围';
                    }
                    document.getElementById('applyInstallmentId').addEventListener('change', function() {
                        updateApplyHint();
                        const opt = this.selectedOptions[0];
                        const unpaid = Number(opt.getAttribute('data-unpaid') || 0);
                        document.getElementById('applyAmount').value = fmt(Math.min(prepayBalance, unpaid));
                        updateApplyHint();
                    });
                    document.getElementById('applyAmount').addEventListener('input', updateApplyHint);
                    document.getElementById('applyAmount').value = fmt(Math.min(prepayBalance, Number(document.getElementById('applyInstallmentId').selectedOptions[0].getAttribute('data-unpaid') || 0)));
                    updateApplyHint();
                    document.getElementById('btnApply').addEventListener('click', function() {
                        const btn = this;
                        if (btn.disabled) return;
                        const fd = new FormData(document.getElementById('prepayApplyForm'));
                        const amt = Number(fd.get('amount') || 0);
                        if (!amt || amt <= 0) { showAlertModal('核销金额必须大于0', 'warning'); return; }
                        btn.disabled = true;
                        btn.textContent = '提交中...';
                        fetch(apiUrl('finance_prepay_apply.php'), { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(res => {
                                if (!res.success) { showAlertModal(res.message || '核销失败', 'error'); return; }
                                const d = res.data || {};
                                showAlertModal('核销成功：' + fmt(d.amount) + '（余额 ' + fmt(d.balance_before) + ' → ' + fmt(d.balance_after) + '）', 'success', function() { location.reload(); });
                            })
                            .catch(() => { btn.disabled = false; btn.textContent = '提交核销'; showAlertModal('核销失败，请查看控制台错误信息', 'error'); });
                    });
                };
                </script>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="manualAdjustModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">新增预收/手工调整</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="manualAdjustForm" class="row g-3">
                        <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">
                        <div class="col-md-4">
                            <label class="form-label">方向</label>
                            <select class="form-select" name="direction" id="manualDirection" required>
                                <option value="in" selected>入（新增预收）</option>
                                <option value="out">出（扣减预收）</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">金额</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="amount" id="manualAmount" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">付款方式</label>
                            <select class="form-select" name="method" id="manualMethod">
                                <option value="">请选择</option>
                                <?php foreach (getDictOptions('payment_method') as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['value']) ?>"><?= htmlspecialchars($opt['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">备注</label>
                            <input type="text" class="form-control" name="note" id="manualNote" maxlength="255" placeholder="可选">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="btnManualSubmit">提交</button>
                </div>
            </div>
        </div>
    </div>

    <script data-prepay-manual="1">
    window._prepayManualInit = function() {
        function apiUrl(path) { return API_URL + '/' + path; }
        function fmt(n) { return Number(n || 0).toFixed(2); }
        const modalEl = document.getElementById('manualAdjustModal');
        if (!modalEl) return;
        const modal = new bootstrap.Modal(modalEl);
        const btn = document.getElementById('btnManualAdjust');
        if (btn) {
            btn.addEventListener('click', function() {
                document.getElementById('manualDirection').value = 'in';
                document.getElementById('manualAmount').value = '';
                document.getElementById('manualMethod').value = '';
                document.getElementById('manualNote').value = '';
                modal.show();
            });
        }
        const autoOpen = String('<?= htmlspecialchars((string)($_GET['open_manual'] ?? '')) ?>');
        if (autoOpen === '1') {
            try { document.getElementById('manualDirection').value = 'in'; document.getElementById('manualMethod').value = ''; modal.show(); } catch (e) {}
        }
        document.getElementById('btnManualSubmit').addEventListener('click', function() {
            const btn = this;
            if (btn.disabled) return;
            const fd = new FormData(document.getElementById('manualAdjustForm'));
            const amt = Number(fd.get('amount') || 0);
            if (!amt || amt <= 0) { showAlertModal('金额必须大于0', 'warning'); return; }
            btn.disabled = true;
            btn.textContent = '提交中...';
            fetch(apiUrl('finance_prepay_manual_adjust.php'), { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) { btn.disabled = false; btn.textContent = '提交'; showAlertModal(res.message || '提交失败', 'error'); return; }
                    const d = res.data || {};
                    showAlertModal('已记账：' + fmt(d.amount) + '（余额 ' + fmt(d.balance_before) + ' → ' + fmt(d.balance_after) + '）', 'success', function() {
                        location.href = 'index.php?page=finance_prepay&customer_id=<?= (int)$customerId ?>';
                    });
                })
                .catch(() => { btn.disabled = false; btn.textContent = '提交'; showAlertModal('提交失败，请查看控制台错误信息', 'error'); });
        });
    };
    </script>

    <?php

    echo '<div class="card"><div class="card-body">'
        . '<div class="table-responsive">'
        . '<table class="table table-hover align-middle">'
        . '<thead><tr>'
        . '<th>时间</th><th>方向</th><th>金额</th><th>来源</th><th>备注</th>'
        . '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="5" class="text-center text-muted">暂无流水</td></tr>';
    } else {
        foreach ($rows as $r) {
            $dir = $r['direction'] ?? '';
            $dirLabel = $dir === 'in' ? '入' : '出';
            $dirBadge = $dir === 'in' ? 'success' : 'danger';
            $amt = number_format((float)($r['amount'] ?? 0), 2);
            $sourceType = $r['source_type'] ?? '';
            $sourceId = $r['source_id'] ?? '';
            // 来源类型中文映射
            $sourceTypeMap = [
                'apply_to_installment' => '核销到分期',
                'manual_adjust' => '手工调整',
                'receipt' => '收款',
                'refund' => '退款',
            ];
            $sourceLabel = $sourceTypeMap[$sourceType] ?? $sourceType;
            $sourceText = htmlspecialchars($sourceLabel . ($sourceId ? ('：' . $sourceId) : ''));
            $createdAt = (int)($r['created_at'] ?? 0);
            $timeText = $createdAt ? date('Y-m-d H:i', $createdAt) : '-';
            echo '<tr>'
                . '<td>' . htmlspecialchars($timeText) . '</td>'
                . '<td><span class="badge bg-' . $dirBadge . '">' . htmlspecialchars($dirLabel) . '</span></td>'
                . '<td>' . $amt . '</td>'
                . '<td>' . $sourceText . '</td>'
                . '<td>' . htmlspecialchars($r['note'] ?? '') . '</td>'
                . '</tr>';
        }
    }

    echo '</tbody></table></div>'
        . '<div class="text-muted small">共 ' . $total . ' 条</div>'
        . '</div></div>';

    if ($totalPages > 1) {
        $params = $_GET;
        $params['page'] = 'finance_prepay';
        $params['customer_id'] = $customerId;
        $params['per_page'] = $perPage;
        $params['page_num'] = 1;
        $firstUrl = 'index.php?' . http_build_query($params);
        $params['page_num'] = max(1, $page - 1);
        $prevUrl = 'index.php?' . http_build_query($params);
        $params['page_num'] = min($totalPages, $page + 1);
        $nextUrl = 'index.php?' . http_build_query($params);
        $params['page_num'] = $totalPages;
        $lastUrl = 'index.php?' . http_build_query($params);
        echo '<nav class="mt-3"><ul class="pagination justify-content-center">'
            . '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($firstUrl) . '">首页</a></li>'
            . '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($prevUrl) . '">上一页</a></li>'
            . '<li class="page-item disabled"><span class="page-link">' . $page . ' / ' . $totalPages . '</span></li>'
            . '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($nextUrl) . '">下一页</a></li>'
            . '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($lastUrl) . '">末页</a></li>'
            . '</ul></nav>';
    }

    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window._prepayApplyInit === 'function') window._prepayApplyInit();
        if (typeof window._prepayManualInit === 'function') window._prepayManualInit();
    });
    </script>
    <?php
    layout_footer();
    exit;
}

$sql = 'SELECT
    cu.id AS customer_id,
    cu.name AS customer_name,
    cu.mobile AS customer_mobile,
    cu.customer_code,
    cu.activity_tag,
    COALESCE(SUM(CASE WHEN l.direction = "in" THEN l.amount ELSE -l.amount END), 0) AS balance
FROM customers cu
LEFT JOIN finance_prepay_ledger l ON l.customer_id = cu.id
WHERE 1=1';

$params = [];

if ($keyword !== '') {
    $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}

if ($activityTag !== '') {
    $sql .= ' AND cu.activity_tag = :activity_tag';
    $params['activity_tag'] = $activityTag;
}

$sql .= ' GROUP BY cu.id';

if ($minBalance !== null) {
    $sql .= ' HAVING balance >= :min_balance';
    $params['min_balance'] = $minBalance;
}

$countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') AS t';
$total = (int)(Db::queryOne($countSql, $params)['total'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

$sql .= ' ORDER BY balance DESC, cu.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$rows = Db::query($sql, $params);

?>

<div class="card mb-2">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="finance_prepay">
            <input type="text" class="form-control form-control-sm" name="keyword" placeholder="客户/手机/编号" value="<?= htmlspecialchars($keyword) ?>" style="width:180px;">
            <input type="text" class="form-control form-control-sm" name="activity_tag" placeholder="活动标签" value="<?= htmlspecialchars($activityTag) ?>" style="width:120px;">
            <input type="number" step="0.01" class="form-control form-control-sm" name="min_balance" placeholder="最低余额" value="<?= htmlspecialchars($minBalanceStr) ?>" style="width:100px;">
            <select class="form-select form-select-sm" name="per_page" style="width:70px;">
                <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
                <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">筛选</button>
            <a href="index.php?page=finance_prepay" class="btn btn-outline-secondary btn-sm">重置</a>
            <span class="text-muted small">共 <?= $total ?> 条</span>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>客户</th>
                    <th>活动标签</th>
                    <th>余额</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div><?= htmlspecialchars($r['customer_name'] ?? '') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars(($r['customer_code'] ?? '') . ' ' . ($r['customer_mobile'] ?? '')) ?></div>
                            </td>
                            <td><?= htmlspecialchars($r['activity_tag'] ?? '') ?></td>
                            <td><?= number_format((float)($r['balance'] ?? 0), 2) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="index.php?page=finance_prepay&customer_id=<?= (int)$r['customer_id'] ?>">查看流水</a>
                                <a class="btn btn-sm btn-outline-secondary" href="index.php?page=finance_prepay&customer_id=<?= (int)$r['customer_id'] ?>&open_manual=1">新增预收</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <?php
            $params = $_GET;
            $params['page'] = 'finance_prepay';
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
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window._prepayApplyInit === 'function') window._prepayApplyInit();
    if (typeof window._prepayManualInit === 'function') window._prepayManualInit();
});
</script>
<?php
finance_sidebar_end();
layout_footer();
