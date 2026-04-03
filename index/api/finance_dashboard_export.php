<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    http_response_code(403);
    echo '无权限';
    exit;
}

$viewMode = trim((string)($_GET['view_mode'] ?? 'contract'));
if (!in_array($viewMode, ['installment', 'contract', 'staff_summary'], true)) {
    $viewMode = 'contract';
}

$keyword = trim($_GET['keyword'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$status = trim($_GET['status'] ?? '');
$dueStart = trim($_GET['due_start'] ?? '');
$dueEnd = trim($_GET['due_end'] ?? '');

$groupBy = trim((string)($_GET['group_by'] ?? 'sales'));
if (!in_array($groupBy, ['sales', 'owner'], true)) {
    $groupBy = 'sales';
}

$focusUserType = trim((string)($_GET['focus_user_type'] ?? ''));
if (!in_array($focusUserType, ['sales', 'owner'], true)) {
    $focusUserType = '';
}
$focusUserId = (int)($_GET['focus_user_id'] ?? 0);

$salesUserIds = $_GET['sales_user_ids'] ?? [];
if (!is_array($salesUserIds)) {
    $salesUserIds = [];
}
$salesUserIds = array_values(array_unique(array_filter(array_map('intval', $salesUserIds), static fn($v) => $v > 0)));

$ownerUserIds = $_GET['owner_user_ids'] ?? [];
if (!is_array($ownerUserIds)) {
    $ownerUserIds = [];
}
$ownerUserIds = array_values(array_unique(array_filter(array_map('intval', $ownerUserIds), static fn($v) => $v > 0)));

function mapContractStatusLabelExport($status, $manualStatus) {
    $ms = trim((string)($manualStatus ?? ''));
    if ($ms !== '') {
        return $ms;
    }
    $s = (string)($status ?? '');
    if ($s === 'active') {
        return '剩余几期';
    }
    return $s;
}

function mapInstallmentStatusLabelExport($amountDue, $amountPaid, $dueDate, $manualStatus) {
    $due = (float)($amountDue ?? 0);
    $paid = (float)($amountPaid ?? 0);
    $unpaid = $due - $paid;

    $ms = trim((string)($manualStatus ?? ''));
    if ($ms !== '') {
        return $ms;
    }

    if ($due > 0 && $unpaid <= 0.00001) {
        return '已收';
    }

    if ($paid > 0.00001 && $unpaid > 0.00001) {
        return '部分已收';
    }

    $dt = (string)($dueDate ?? '');
    if ($dt !== '' && strtotime($dt) !== false && strtotime($dt) < strtotime(date('Y-m-d'))) {
        return '逾期';
    }

    return '待收';
}

$params = [];

if ($viewMode === 'staff_summary') {
    $sql = 'SELECT
        u.id AS user_id,
        u.realname AS user_name,
        COALESCE(cs.contract_amount, 0) AS contract_amount,
        COALESCE(rs.receipt_amount, 0) AS receipt_amount,
        COALESCE(us.unpaid_amount, 0) AS unpaid_amount,
        COALESCE(cs.contract_count, 0) AS contract_count
    FROM users u
    LEFT JOIN (
        SELECT
            ' . ($groupBy === 'owner' ? 'cu.owner_user_id' : 'c.sales_user_id') . ' AS uid,
            COUNT(DISTINCT c.id) AS contract_count,
            SUM(c.net_amount) AS contract_amount
        FROM finance_contracts c
        INNER JOIN customers cu ON cu.id = c.customer_id
        WHERE 1=1
            AND c.net_amount IS NOT NULL
    ';
    if (($user['role'] ?? '') === 'sales') {
        $sql .= ' AND c.sales_user_id = :sales_user_id';
        $params['sales_user_id'] = (int)($user['id'] ?? 0);
    }
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':f_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = ':f_owner_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($keyword !== '') {
        $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
        $params['kw'] = '%' . $keyword . '%';
    }
    if ($activityTag !== '') {
        $sql .= ' AND cu.activity_tag = :activity_tag';
        $params['activity_tag'] = $activityTag;
    }
    if ($dueStart !== '') {
        $sql .= ' AND c.sign_date >= :due_start';
        $params['due_start'] = $dueStart;
    }
    if ($dueEnd !== '') {
        $sql .= ' AND c.sign_date <= :due_end';
        $params['due_end'] = $dueEnd;
    }
    $sql .= ' GROUP BY uid
    ) cs ON cs.uid = u.id
    LEFT JOIN (
        SELECT
            ' . ($groupBy === 'owner' ? 'cu.owner_user_id' : 'c.sales_user_id') . ' AS uid,
            SUM(r.amount_applied) AS receipt_amount
        FROM finance_receipts r
        INNER JOIN finance_contracts c ON c.id = r.contract_id
        INNER JOIN customers cu ON cu.id = r.customer_id
        WHERE 1=1
            AND r.amount_applied > 0
    ';
    if (($user['role'] ?? '') === 'sales') {
        $sql .= ' AND c.sales_user_id = :sales_user_id';
    }
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':r_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = ':r_owner_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($keyword !== '') {
        $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
    }
    if ($activityTag !== '') {
        $sql .= ' AND cu.activity_tag = :activity_tag';
    }
    if ($dueStart !== '') {
        $sql .= ' AND r.received_date >= :due_start';
    }
    if ($dueEnd !== '') {
        $sql .= ' AND r.received_date <= :due_end';
    }
    $sql .= ' GROUP BY uid
    ) rs ON rs.uid = u.id
    LEFT JOIN (
        SELECT
            ' . ($groupBy === 'owner' ? 'cu.owner_user_id' : 'c.sales_user_id') . ' AS uid,
            SUM(GREATEST(i.amount_due - i.amount_paid, 0)) AS unpaid_amount
        FROM finance_installments i
        INNER JOIN finance_contracts c ON c.id = i.contract_id
        INNER JOIN customers cu ON cu.id = i.customer_id
        WHERE 1=1
            AND i.deleted_at IS NULL
            AND (i.amount_due - i.amount_paid) > 0.00001
    ';
    if (($user['role'] ?? '') === 'sales') {
        $sql .= ' AND c.sales_user_id = :sales_user_id';
    }
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':u_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = ':u_owner_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($keyword !== '') {
        $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
    }
    if ($activityTag !== '') {
        $sql .= ' AND cu.activity_tag = :activity_tag';
    }
    if ($dueStart !== '') {
        $sql .= ' AND i.due_date >= :due_start';
    }
    if ($dueEnd !== '') {
        $sql .= ' AND i.due_date <= :due_end';
    }
    $sql .= ' GROUP BY uid
    ) us ON us.uid = u.id
    WHERE u.status = 1
        AND (cs.uid IS NOT NULL OR rs.uid IS NOT NULL OR us.uid IS NOT NULL)';
} elseif ($viewMode === 'contract') {
    $sql = 'SELECT
        c.id AS contract_id,
        c.customer_id,
        c.contract_no,
        c.title AS contract_title,
        c.sales_user_id,
        c.create_time AS contract_create_time,
        c.status AS contract_status,
        c.manual_status AS contract_manual_status,
        c.net_amount,
        cu.name AS customer_name,
        cu.mobile AS customer_mobile,
        cu.customer_code,
        cu.owner_user_id,
        cu.activity_tag,
        u.realname AS sales_name,
        ou.realname AS owner_name,
        COUNT(i.id) AS installment_count,
        SUM(i.amount_due) AS total_due,
        SUM(i.amount_paid) AS total_paid,
        SUM(i.amount_due - i.amount_paid) AS total_unpaid,
        MAX(ragg.last_received_date) AS last_received_date
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id
    LEFT JOIN users u ON u.id = c.sales_user_id
    LEFT JOIN users ou ON ou.id = cu.owner_user_id
    LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
    LEFT JOIN (
        SELECT contract_id, MAX(received_date) AS last_received_date
        FROM finance_receipts
        WHERE amount_applied > 0
        GROUP BY contract_id
    ) ragg ON ragg.contract_id = c.id
    WHERE 1=1';
} else {
    $sql = 'SELECT
        i.*, 
        c.title AS contract_title,
        c.contract_no,
        c.sales_user_id,
        c.status AS contract_status,
        c.manual_status AS contract_manual_status,
        cu.name AS customer_name,
        cu.mobile AS customer_mobile,
        cu.customer_code,
        cu.owner_user_id,
        cu.activity_tag,
        u.realname AS sales_name,
        ou.realname AS owner_name,
        ragg.last_received_date,
        CASE
            WHEN i.amount_due > i.amount_paid AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date)
            ELSE 0
        END AS overdue_days,
        (i.amount_due - i.amount_paid) AS amount_unpaid
    FROM finance_installments i
    INNER JOIN finance_contracts c ON c.id = i.contract_id
    INNER JOIN customers cu ON cu.id = i.customer_id
    LEFT JOIN users u ON u.id = c.sales_user_id
    LEFT JOIN users ou ON ou.id = cu.owner_user_id
    LEFT JOIN (
        SELECT installment_id, MAX(received_date) AS last_received_date
        FROM finance_receipts
        WHERE amount_applied > 0
        GROUP BY installment_id
    ) ragg ON ragg.installment_id = i.id
    WHERE 1=1 AND i.deleted_at IS NULL';
}

if ($viewMode !== 'staff_summary' && ($user['role'] ?? '') === 'sales') {
    $sql .= ' AND c.sales_user_id = :sales_user_id';
    $params['sales_user_id'] = (int)($user['id'] ?? 0);
}

if ($viewMode !== 'staff_summary') {
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':m_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = ':m_owner_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
}

if ($keyword !== '') {
    $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
    $params['kw'] = '%' . $keyword . '%';
}

if ($activityTag !== '') {
    $sql .= ' AND cu.activity_tag = :activity_tag';
    $params['activity_tag'] = $activityTag;
}

if ($viewMode !== 'staff_summary' && $focusUserId > 0 && $focusUserType !== '') {
    if ($focusUserType === 'sales') {
        $sql .= ' AND c.sales_user_id = :focus_user_id';
        $params['focus_user_id'] = $focusUserId;
    } elseif ($focusUserType === 'owner') {
        $sql .= ' AND cu.owner_user_id = :focus_user_id';
        $params['focus_user_id'] = $focusUserId;
    }
}

if ($status !== '') {
    if ($viewMode === 'contract') {
        $sql .= ' AND (
            (c.manual_status IS NOT NULL AND c.manual_status <> "" AND c.manual_status = :status)
            OR (
                (c.manual_status IS NULL OR c.manual_status = "")
                AND (CASE WHEN c.status = "active" THEN "剩余几期" ELSE c.status END) = :status
            )
        )';
        $params['status'] = $status;
    } elseif ($viewMode === 'installment') {
        if ($status === '已收') {
            $sql .= ' AND (i.amount_due > 0 AND (i.amount_due - i.amount_paid) <= 0.00001)';
        } elseif ($status === '部分已收') {
            $sql .= ' AND (i.amount_paid > 0.00001 AND (i.amount_due - i.amount_paid) > 0.00001)';
        } elseif ($status === '催款') {
            $sql .= ' AND (i.amount_paid <= 0.00001 AND (i.amount_due - i.amount_paid) > 0.00001 AND i.manual_status = "催款")';
        } elseif ($status === '逾期') {
            $sql .= ' AND (i.amount_paid <= 0.00001 AND (i.amount_due - i.amount_paid) > 0.00001'
                . ' AND (i.manual_status IS NULL OR i.manual_status = "" OR i.manual_status = "待收")'
                . ' AND i.due_date < CURDATE())';
        } elseif ($status === '待收') {
            $sql .= ' AND (i.amount_paid <= 0.00001 AND (i.amount_due - i.amount_paid) > 0.00001'
                . ' AND (i.manual_status IS NULL OR i.manual_status = "" OR i.manual_status = "待收")'
                . ' AND (i.due_date >= CURDATE()))';
        } else {
            $sql .= ' AND 1=0';
        }
    }
}

if ($dueStart !== '') {
    if ($viewMode === 'installment') {
        $sql .= ' AND i.due_date >= :due_start';
        $params['due_start'] = $dueStart;
    } elseif ($viewMode === 'contract') {
        $sql .= ' AND i.due_date >= :due_start';
        $params['due_start'] = $dueStart;
    }
}

if ($dueEnd !== '') {
    if ($viewMode === 'installment') {
        $sql .= ' AND i.due_date <= :due_end';
        $params['due_end'] = $dueEnd;
    } elseif ($viewMode === 'contract') {
        $sql .= ' AND i.due_date <= :due_end';
        $params['due_end'] = $dueEnd;
    }
}

if ($viewMode === 'contract') {
    $sql .= ' GROUP BY c.id';
}

$sql .= ($viewMode === 'contract'
    ? ' ORDER BY c.id DESC'
    : ($viewMode === 'staff_summary'
        ? ' ORDER BY contract_amount DESC, receipt_amount DESC, unpaid_amount DESC, u.id DESC'
        : ' ORDER BY i.due_date ASC, overdue_days DESC, i.id DESC'));

$rows = Db::query($sql, $params);

$filename = 'finance_dashboard_' . $viewMode . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

if ($viewMode === 'staff_summary') {
    fputcsv($out, ['人员', '合同数', '合同额', '收款额', '未收金额']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['user_name'] ?? ''),
            (int)($row['contract_count'] ?? 0),
            number_format((float)($row['contract_amount'] ?? 0), 2, '.', ''),
            number_format((float)($row['receipt_amount'] ?? 0), 2, '.', ''),
            number_format((float)($row['unpaid_amount'] ?? 0), 2, '.', ''),
        ]);
    }
} elseif ($viewMode === 'contract') {
    fputcsv($out, ['客户名称', '客户编号', '活动标签', '合同号', '合同标题', '销售', '创建人(归属)', '分期数', '应收', '已收', '未收', '状态', '最近收款日期']);
    foreach ($rows as $row) {
        $statusLabel = mapContractStatusLabelExport(($row['contract_status'] ?? ''), ($row['contract_manual_status'] ?? ''));
        fputcsv($out, [
            (string)($row['customer_name'] ?? ''),
            (string)($row['customer_code'] ?? ''),
            (string)($row['activity_tag'] ?? ''),
            (string)($row['contract_no'] ?? ''),
            (string)($row['contract_title'] ?? ''),
            (string)($row['sales_name'] ?? ''),
            (string)($row['owner_name'] ?? ''),
            (int)($row['installment_count'] ?? 0),
            number_format((float)($row['total_due'] ?? 0), 2, '.', ''),
            number_format((float)($row['total_paid'] ?? 0), 2, '.', ''),
            number_format((float)($row['total_unpaid'] ?? 0), 2, '.', ''),
            $statusLabel,
            (string)($row['last_received_date'] ?? ''),
        ]);
    }
} else {
    fputcsv($out, ['客户名称', '客户编号', '活动标签', '合同号', '合同标题', '销售', '创建人(归属)', '创建时间', '到期日', '应收', '已收', '未收', '逾期天数', '状态', '最近收款日期']);
    foreach ($rows as $row) {
        $statusLabel = mapInstallmentStatusLabelExport(($row['amount_due'] ?? 0), ($row['amount_paid'] ?? 0), ($row['due_date'] ?? ''), ($row['manual_status'] ?? ''));
        $createTime = !empty($row['create_time']) ? date('Y-m-d H:i', (int)$row['create_time']) : '';
        fputcsv($out, [
            (string)($row['customer_name'] ?? ''),
            (string)($row['customer_code'] ?? ''),
            (string)($row['activity_tag'] ?? ''),
            (string)($row['contract_no'] ?? ''),
            (string)($row['contract_title'] ?? ''),
            (string)($row['sales_name'] ?? ''),
            (string)($row['owner_name'] ?? ''),
            $createTime,
            (string)($row['due_date'] ?? ''),
            number_format((float)($row['amount_due'] ?? 0), 2, '.', ''),
            number_format((float)($row['amount_paid'] ?? 0), 2, '.', ''),
            number_format((float)($row['amount_unpaid'] ?? 0), 2, '.', ''),
            (int)($row['overdue_days'] ?? 0),
            $statusLabel,
            (string)($row['last_received_date'] ?? ''),
        ]);
    }
}

fclose($out);
