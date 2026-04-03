<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权访问预收报表。</div>';
    layout_footer();
    exit;
}

$isSalesOnly = ($user['role'] === 'sales');
$currentUserId = (int)($user['id'] ?? 0);

layout_header('预收报表');
finance_sidebar_start('finance_prepay_report');

$keyword = trim($_GET['keyword'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$balanceFilter = trim($_GET['balance_filter'] ?? '');

$page = max(1, (int)($_GET['page_num'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

?>


<div class="card mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex align-items-center gap-2 flex-wrap">
            <input type="hidden" name="page" value="finance_prepay_report">
            <input type="text" class="form-control form-control-sm" name="keyword" value="<?= htmlspecialchars($keyword) ?>" placeholder="客户名/编号/手机" style="width:160px;">
            <input type="text" class="form-control form-control-sm" name="activity_tag" value="<?= htmlspecialchars($activityTag) ?>" placeholder="活动标签" style="width:120px;">
            <select class="form-select form-select-sm" name="balance_filter" style="width:100px;">
                <option value="">全部</option>
                <option value="positive" <?= $balanceFilter === 'positive' ? 'selected' : '' ?>>有余额</option>
                <option value="zero" <?= $balanceFilter === 'zero' ? 'selected' : '' ?>>零余额</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="index.php?page=finance_prepay_report" class="btn btn-outline-secondary btn-sm">重置</a>
        </form>
    </div>
</div>

<?php

$sql = 'SELECT
    c.id AS customer_id,
    c.name AS customer_name,
    c.mobile,
    c.customer_code,
    c.activity_tag,
    c.owner_user_id,
    u.realname AS owner_name,
    COALESCE(SUM(CASE WHEN l.direction = "in" THEN l.amount ELSE -l.amount END), 0) AS balance,
    COUNT(l.id) AS record_count,
    MAX(l.created_at) AS last_record_time
FROM customers c
LEFT JOIN finance_prepay_ledger l ON l.customer_id = c.id
LEFT JOIN users u ON u.id = c.owner_user_id
WHERE c.deleted_at IS NULL';

$params = [];

if ($isSalesOnly) {
    $sql .= ' AND c.owner_user_id = :owner_uid';
    $params['owner_uid'] = $currentUserId;
}

if ($keyword !== '') {
    $sql .= ' AND (c.name LIKE :kw OR c.mobile LIKE :kw OR c.customer_code LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}

if ($activityTag !== '') {
    $sql .= ' AND c.activity_tag = :activity_tag';
    $params['activity_tag'] = $activityTag;
}

$sql .= ' GROUP BY c.id';

if ($balanceFilter === 'positive') {
    $sql .= ' HAVING balance > 0.00001';
} elseif ($balanceFilter === 'zero') {
    $sql .= ' HAVING balance >= -0.00001 AND balance <= 0.00001';
} else {
    $sql .= ' HAVING balance != 0 OR record_count > 0';
}

$countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') AS t';
$total = (int)(Db::queryOne($countSql, $params)['total'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

$sql .= ' ORDER BY balance DESC, c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$rows = Db::query($sql, $params);

if ($isSalesOnly) {
    $totalBalance = Db::queryOne(
        'SELECT COALESCE(SUM(CASE WHEN l.direction = "in" THEN l.amount ELSE -l.amount END), 0) AS total 
         FROM finance_prepay_ledger l
         INNER JOIN customers c ON c.id = l.customer_id
         WHERE c.owner_user_id = :uid',
        ['uid' => $currentUserId]
    )['total'] ?? 0;
} else {
    $totalBalance = Db::queryOne(
        'SELECT COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS total FROM finance_prepay_ledger'
    )['total'] ?? 0;
}

?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>客户预收余额列表</span>
        <span class="badge bg-primary">总预收余额: ¥<?= number_format((float)$totalBalance, 2) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>客户编号</th>
                        <th>客户名称</th>
                        <th>手机</th>
                        <th>活动标签</th>
                        <th>归属销售</th>
                        <th>预收余额</th>
                        <th>记录数</th>
                        <th>最近记录</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted">暂无数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $balance = (float)($r['balance'] ?? 0);
                            $balanceClass = $balance > 0.00001 ? 'text-success' : ($balance < -0.00001 ? 'text-danger' : 'text-muted');
                            $lastTime = (int)($r['last_record_time'] ?? 0);
                            $lastTimeText = $lastTime > 0 ? date('Y-m-d H:i', $lastTime) : '-';
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($r['customer_code'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['customer_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['mobile'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['activity_tag'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['owner_name'] ?? '-') ?></td>
                                <td class="<?= $balanceClass ?> fw-semibold">¥<?= number_format($balance, 2) ?></td>
                                <td><?= (int)($r['record_count'] ?? 0) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($lastTimeText) ?></td>
                                <td>
                                    <a href="index.php?page=finance_prepay&customer_id=<?= (int)$r['customer_id'] ?>" class="btn btn-outline-primary btn-sm">查看台账</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-muted small">共 <?= $total ?> 条</div>
    </div>
</div>

<?php
if ($totalPages > 1) {
    $baseParams = $_GET;
    $baseParams['page'] = 'finance_prepay_report';
    $baseParams['per_page'] = $perPage;
    
    $baseParams['page_num'] = 1;
    $firstUrl = 'index.php?' . http_build_query($baseParams);
    $baseParams['page_num'] = max(1, $page - 1);
    $prevUrl = 'index.php?' . http_build_query($baseParams);
    $baseParams['page_num'] = min($totalPages, $page + 1);
    $nextUrl = 'index.php?' . http_build_query($baseParams);
    $baseParams['page_num'] = $totalPages;
    $lastUrl = 'index.php?' . http_build_query($baseParams);
    
    echo '<nav class="mt-3"><ul class="pagination justify-content-center">'
        . '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($firstUrl) . '">首页</a></li>'
        . '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($prevUrl) . '">上一页</a></li>'
        . '<li class="page-item disabled"><span class="page-link">' . $page . ' / ' . $totalPages . '</span></li>'
        . '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($nextUrl) . '">下一页</a></li>'
        . '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="' . htmlspecialchars($lastUrl) . '">末页</a></li>'
        . '</ul></nav>';
}

finance_sidebar_end();
layout_footer();
