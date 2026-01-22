<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/dict.php';
require_once __DIR__ . '/../core/migrations.php';
require_once __DIR__ . '/../core/finance_status.php';
require_once __DIR__ . '/../core/finance_dashboard_service.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/finance_sidebar.php';

ensureCustomerGroupField();

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::FINANCE_VIEW)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">无权限访问财务工作台。</div>';
    layout_footer();
    exit;
}

layout_header('财务工作台');
echo '<script src="js/column-toggle.js"></script>';
// 模块化JS文件（按依赖顺序加载）
$jsVersion = time();
echo '<script src="js/finance/config.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/utils.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/api.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/components/table.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/components/installments.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/components/modals.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/finance/dashboard.js?v=' . $jsVersion . '"></script>';
// 旧版本兼容（保留直到完全迁移）
echo '<script src="js/finance-dashboard.js?v=' . $jsVersion . '"></script>';
echo '<script src="js/filter-fields.js"></script>';

$viewId = (int)($_GET['view_id'] ?? 0);
$viewFilters = [];
if ($viewId > 0) {
    $vr = Db::queryOne('SELECT filters_json FROM finance_saved_views WHERE id = :id AND user_id = :uid AND page_key = :pk AND status = 1 LIMIT 1', [
        'id' => $viewId,
        'uid' => (int)($user['id'] ?? 0),
        'pk' => 'finance_dashboard',
    ]);
    if ($vr && !empty($vr['filters_json'])) {
        $tmp = json_decode($vr['filters_json'], true);
        if (is_array($tmp)) {
            $viewFilters = $tmp;
        }
    }
}

$keyword = trim($_GET['keyword'] ?? '');
$customerGroup = trim($_GET['customer_group'] ?? '');
$activityTag = trim($_GET['activity_tag'] ?? '');
$status = trim($_GET['status'] ?? '');
$period = trim((string)($_GET['period'] ?? ''));
$dueStart = trim($_GET['due_start'] ?? '');
$dueEnd = trim($_GET['due_end'] ?? '');
$receiptStart = trim($_GET['receipt_start'] ?? '');
$receiptEnd = trim($_GET['receipt_end'] ?? '');
$dateType = trim($_GET['date_type'] ?? 'sign'); // sign=签约时间, receipt=实收时间
$viewMode = trim((string)($_GET['view_mode'] ?? 'contract'));
if (!in_array($viewMode, ['installment', 'contract', 'staff_summary'], true)) {
    $viewMode = 'contract';
}

// 根据日期类型处理本月/上月快捷选项
if ($period === 'this_month') {
    $y = (int)date('Y');
    $m = (int)date('n');
    $startDate = sprintf('%04d-%02d-01', $y, $m);
    $endDate = date('Y-m-t');
    if ($dateType === 'receipt') {
        $receiptStart = $startDate;
        $receiptEnd = $endDate;
        $dueStart = '';
        $dueEnd = '';
    } else {
        $dueStart = $startDate;
        $dueEnd = $endDate;
        $receiptStart = '';
        $receiptEnd = '';
    }
} elseif ($period === 'last_month') {
    $ts = strtotime(date('Y-m-01') . ' -1 month');
    if ($ts !== false) {
        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        $startDate = sprintf('%04d-%02d-01', $y, $m);
        $endDate = date('Y-m-t', $ts);
        if ($dateType === 'receipt') {
            $receiptStart = $startDate;
            $receiptEnd = $endDate;
            $dueStart = '';
            $dueEnd = '';
        } else {
            $dueStart = $startDate;
            $dueEnd = $endDate;
            $receiptStart = '';
            $receiptEnd = '';
        }
    }
} elseif ($period === 'custom' || ($dueStart !== '' || $dueEnd !== '' || $receiptStart !== '' || $receiptEnd !== '')) {
    // 自定义时间或用户手动填写了日期：保留用户输入
} elseif ($period === '') {
    // 所有时间且没有手动填写日期：清空日期筛选
    $dueStart = '';
    $dueEnd = '';
    $receiptStart = '';
    $receiptEnd = '';
}

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

// 使用服务类的静态方法作为函数别名，保持向后兼容
function mapContractStatusLabel($status, $manualStatus) {
    return FinanceDashboardService::mapContractStatusLabel($status, $manualStatus);
}

function mapInstallmentStatusLabel($amountDue, $amountPaid, $dueDate, $manualStatus) {
    return FinanceDashboardService::mapInstallmentStatusLabel($amountDue, $amountPaid, $dueDate, $manualStatus);
}

if ($viewId > 0 && !empty($viewFilters)) {
    if ($keyword === '' && isset($viewFilters['keyword'])) {
        $keyword = trim((string)$viewFilters['keyword']);
    }
    if ($activityTag === '' && isset($viewFilters['activity_tag'])) {
        $activityTag = trim((string)$viewFilters['activity_tag']);
    }
    if ($status === '' && isset($viewFilters['status'])) {
        $status = trim((string)$viewFilters['status']);
    }
    if ($dueStart === '' && isset($viewFilters['due_start'])) {
        $dueStart = trim((string)$viewFilters['due_start']);
    }
    if ($dueEnd === '' && isset($viewFilters['due_end'])) {
        $dueEnd = trim((string)$viewFilters['due_end']);
    }

    if (empty($salesUserIds) && isset($viewFilters['sales_user_ids']) && is_array($viewFilters['sales_user_ids'])) {
        $salesUserIds = array_values(array_unique(array_filter(array_map('intval', $viewFilters['sales_user_ids']), static fn($v) => $v > 0)));
    }
    if (empty($ownerUserIds) && isset($viewFilters['owner_user_ids']) && is_array($viewFilters['owner_user_ids'])) {
        $ownerUserIds = array_values(array_unique(array_filter(array_map('intval', $viewFilters['owner_user_ids']), static fn($v) => $v > 0)));
    }
}

$page = max(1, intval($_GET['page_num'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 9999999); // 默认全部显示
if ($perPage <= 0) {
    $perPage = 9999999; // 全部显示
}
$offset = ($page - 1) * $perPage;

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
    if ($groupBy === 'sales' && !empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':f_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($groupBy === 'owner' && !empty($ownerUserIds)) {
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
    if ($customerGroup !== '') {
        $sql .= ' AND cu.customer_group LIKE :cg';
        $params['cg'] = '%' . $customerGroup . '%';
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
    if ($groupBy === 'sales' && !empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':r_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($groupBy === 'owner' && !empty($ownerUserIds)) {
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
    if ($customerGroup !== '') {
        $sql .= ' AND cu.customer_group LIKE :cg';
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
    if ($groupBy === 'sales' && !empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = ':u_sales_' . $idx;
            $ps[] = $k;
            $params[ltrim($k, ':')] = $uid;
        }
        $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($groupBy === 'owner' && !empty($ownerUserIds)) {
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
    if ($customerGroup !== '') {
        $sql .= ' AND cu.customer_group LIKE :cg';
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
        c.currency AS contract_currency,
        c.create_time AS contract_create_time,
        c.sign_date AS contract_sign_date,
        c.status AS contract_status,
        c.manual_status AS contract_manual_status,
        c.net_amount,
        cu.name AS customer_name,
        cu.mobile AS customer_mobile,
        cu.customer_code,
        cu.owner_user_id,
        cu.activity_tag,
        u.realname AS signer_name,
        ou.realname AS owner_name,
        COUNT(i.id) AS installment_count,
        SUM(i.amount_due) AS total_due,
        SUM(i.amount_paid) AS total_paid,
        SUM(i.amount_due - i.amount_paid) AS total_unpaid,
        GROUP_CONCAT(DISTINCT cf.id ORDER BY cf.id DESC) AS contract_file_ids,
        GROUP_CONCAT(DISTINCT cf.filename ORDER BY cf.id DESC SEPARATOR "|||" ) AS contract_file_names,
        GROUP_CONCAT(DISTINCT cf.preview_supported ORDER BY cf.id DESC) AS contract_file_preview_supported,
        MAX(ragg.last_received_date) AS last_received_date,
        MAX(rmethod.last_payment_method) AS last_payment_method
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
    LEFT JOIN (
        SELECT r1.contract_id, r1.method AS last_payment_method
        FROM finance_receipts r1
        INNER JOIN (
            SELECT contract_id, MAX(id) AS max_id
            FROM finance_receipts
            WHERE amount_applied > 0
            GROUP BY contract_id
        ) r2 ON r1.id = r2.max_id
    ) rmethod ON rmethod.contract_id = c.id
    LEFT JOIN finance_contract_files fcf ON fcf.contract_id = c.id
    LEFT JOIN customer_files cf ON cf.id = fcf.file_id AND cf.deleted_at IS NULL AND cf.category = "internal_solution"
    WHERE 1=1';
} else {
    $sql = 'SELECT
        i.*, 
        c.title AS contract_title,
        c.contract_no,
        c.sales_user_id,
        c.currency AS contract_currency,
        c.status AS contract_status,
        c.manual_status AS contract_manual_status,
        cu.name AS customer_name,
        cu.mobile AS customer_mobile,
        cu.customer_code,
        cu.owner_user_id,
        cu.activity_tag,
        u.realname AS signer_name,
        ou.realname AS owner_name,
        coll.realname AS collector_name,
        ragg.last_received_date,
        ragg.last_receipt_time,
        rmethod.last_payment_method,
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
    LEFT JOIN users coll ON coll.id = i.collector_user_id
    LEFT JOIN (
        SELECT r1.installment_id, r1.received_date AS last_received_date, r1.create_time AS last_receipt_time
        FROM finance_receipts r1
        INNER JOIN (
            SELECT installment_id, MAX(id) AS max_id
            FROM finance_receipts
            WHERE amount_applied > 0
            GROUP BY installment_id
        ) r2 ON r1.id = r2.max_id
    ) ragg ON ragg.installment_id = i.id
    LEFT JOIN (
        SELECT r1.installment_id, r1.method AS last_payment_method
        FROM finance_receipts r1
        INNER JOIN (
            SELECT installment_id, MAX(id) AS max_id
            FROM finance_receipts
            WHERE amount_applied > 0
            GROUP BY installment_id
        ) r2 ON r1.id = r2.max_id
    ) rmethod ON rmethod.installment_id = i.id
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

if ($customerGroup !== '') {
    $sql .= ' AND cu.customer_group LIKE :cg';
    $params['cg'] = '%' . $customerGroup . '%';
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
        if ($status === '已结清') {
            // 已结清：合同状态为已结清，或手动状态为已结清
            $sql .= ' AND (c.status = "已结清" OR c.manual_status = "已结清")';
        } elseif ($status === '未结清') {
            // 未结清：合同状态不是已结清，且手动状态也不是已结清
            $sql .= ' AND (c.status <> "已结清" AND (c.manual_status IS NULL OR c.manual_status = "" OR c.manual_status <> "已结清"))';
        } else {
            $sql .= ' AND (
                (c.manual_status IS NOT NULL AND c.manual_status <> "" AND c.manual_status = :status)
                OR (
                    (c.manual_status IS NULL OR c.manual_status = "")
                    AND c.status = :status
                )
            )';
            $params['status'] = $status;
        }
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

// 按合同签约时间筛选
if ($dueStart !== '') {
    if ($viewMode === 'installment') {
        $sql .= ' AND c.sign_date >= :due_start';
        $params['due_start'] = $dueStart;
    } elseif ($viewMode === 'contract') {
        $sql .= ' AND c.sign_date >= :due_start';
        $params['due_start'] = $dueStart;
    }
}

if ($dueEnd !== '') {
    if ($viewMode === 'installment') {
        $sql .= ' AND c.sign_date <= :due_end';
        $params['due_end'] = $dueEnd;
    } elseif ($viewMode === 'contract') {
        $sql .= ' AND c.sign_date <= :due_end';
        $params['due_end'] = $dueEnd;
    }
}

// 按实收日期筛选（只显示在指定时间内有收款的合同）
if ($receiptStart !== '' || $receiptEnd !== '') {
    $receiptCondition = '1=1';
    if ($receiptStart !== '') {
        $receiptCondition .= ' AND r.received_date >= :receipt_start';
        $params['receipt_start'] = $receiptStart . ' 00:00:00';
    }
    if ($receiptEnd !== '') {
        $receiptCondition .= ' AND r.received_date <= :receipt_end';
        $params['receipt_end'] = $receiptEnd . ' 23:59:59';
    }
    $sql .= ' AND EXISTS (SELECT 1 FROM finance_receipts r WHERE r.contract_id = c.id AND ' . $receiptCondition . ')';
}

if ($viewMode === 'contract') {
    $sql .= ' GROUP BY c.id';
}

$countSql = 'SELECT COUNT(*) AS total FROM (' . $sql . ') AS t';
$total = (int)(Db::queryOne($countSql, $params)['total'] ?? 0);
$totalPages = (int)ceil($total / $perPage);

// 汇总统计
$sumRow = ['contract_count' => 0, 'installment_count' => 0, 'sum_due' => 0, 'sum_paid' => 0, 'sum_unpaid' => 0];
$sumByCurrency = [];  // 按货币分别统计
if ($viewMode === 'contract' || $viewMode === 'installment') {
    $sumParams = [];
    $sumSql = 'SELECT 
        COUNT(DISTINCT c.id) AS contract_count,
        COUNT(i.id) AS installment_count,
        COALESCE(SUM(i.amount_due), 0) AS sum_due,
        COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
        COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id
    LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
    LEFT JOIN users u ON u.id = c.sales_user_id
    WHERE 1=1';
    if ($user['role'] === 'sales') {
        $sumSql .= ' AND c.sales_user_id = :sales_user_id';
        $sumParams['sales_user_id'] = (int)$user['id'];
    }
    if ($keyword !== '') {
        $sumSql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
        $sumParams['kw'] = '%' . $keyword . '%';
    }
    if ($customerGroup !== '') {
        $sumSql .= ' AND cu.customer_group LIKE :cg';
        $sumParams['cg'] = '%' . $customerGroup . '%';
    }
    if ($activityTag !== '') {
        $sumSql .= ' AND cu.activity_tag = :activity_tag';
        $sumParams['activity_tag'] = $activityTag;
    }
    // 按合同签约时间筛选
    if ($dueStart !== '') {
        $sumSql .= ' AND c.sign_date >= :due_start';
        $sumParams['due_start'] = $dueStart;
    }
    if ($dueEnd !== '') {
        $sumSql .= ' AND c.sign_date <= :due_end';
        $sumParams['due_end'] = $dueEnd;
    }
    if ($status !== '') {
        $sumSql .= ' AND c.status = :status';
        $sumParams['status'] = $status;
    }
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = 'sum_sales_' . $idx;
            $ps[] = ':' . $k;
            $sumParams[$k] = $uid;
        }
        $sumSql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    } elseif (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = 'sum_owner_' . $idx;
            $ps[] = ':' . $k;
            $sumParams[$k] = $uid;
        }
        $sumSql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($focusUserType !== '' && $focusUserId > 0) {
        if ($focusUserType === 'sales') {
            $sumSql .= ' AND c.sales_user_id = :focus_user_id';
        } else {
            $sumSql .= ' AND cu.owner_user_id = :focus_user_id';
        }
        $sumParams['focus_user_id'] = $focusUserId;
    }
    // 按实收日期筛选
    if ($receiptStart !== '' || $receiptEnd !== '') {
        $receiptCondition = '1=1';
        if ($receiptStart !== '') {
            $receiptCondition .= ' AND r.received_date >= :receipt_start';
            $sumParams['receipt_start'] = $receiptStart . ' 00:00:00';
        }
        if ($receiptEnd !== '') {
            $receiptCondition .= ' AND r.received_date <= :receipt_end';
            $sumParams['receipt_end'] = $receiptEnd . ' 23:59:59';
        }
        $sumSql .= ' AND EXISTS (SELECT 1 FROM finance_receipts r WHERE r.contract_id = c.id AND ' . $receiptCondition . ')';
    }
    $sumRow = Db::queryOne($sumSql, $sumParams) ?: $sumRow;
    
    // 按货币分别统计（用于前端汇率转换）
    $sumByCurrencySql = str_replace(
        'SELECT',
        'SELECT c.currency,',
        $sumSql
    ) . ' GROUP BY c.currency';
    $currencyRows = Db::query($sumByCurrencySql, $sumParams);
    foreach ($currencyRows as $cr) {
        $code = $cr['currency'] ?: 'TWD';
        $sumByCurrency[$code] = [
            'sum_due' => (float)($cr['sum_due'] ?? 0),
            'sum_paid' => (float)($cr['sum_paid'] ?? 0),
            'sum_unpaid' => (float)($cr['sum_unpaid'] ?? 0),
        ];
    }
}

$sql .= ($viewMode === 'contract'
    ? ' ORDER BY c.id DESC'
    : ($viewMode === 'staff_summary'
        ? ' ORDER BY contract_amount DESC, receipt_amount DESC, unpaid_amount DESC, u.id DESC'
        : ' ORDER BY i.due_date ASC, overdue_days DESC, i.id DESC'));
// 不再使用分页，显示全部数据
$rows = Db::query($sql, $params);

// 查询完整的分组汇总数据（不受分页限制）
$groupStats = [];
if ($viewMode === 'contract') {
    $groupStatsSql = 'SELECT
        u.realname AS signer_name,
        c.currency AS contract_currency,
        COUNT(DISTINCT c.id) AS contract_count,
        COALESCE(SUM(i.amount_due), 0) AS sum_due,
        COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
        COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id
    LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
    LEFT JOIN users u ON u.id = c.sales_user_id
    WHERE 1=1';
    $groupStatsParams = [];
    if ($user['role'] === 'sales') {
        $groupStatsSql .= ' AND c.sales_user_id = :sales_user_id';
        $groupStatsParams['sales_user_id'] = (int)$user['id'];
    }
    if ($keyword !== '') {
        $groupStatsSql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
        $groupStatsParams['kw'] = '%' . $keyword . '%';
    }
    if ($customerGroup !== '') {
        $groupStatsSql .= ' AND cu.customer_group LIKE :cg';
        $groupStatsParams['cg'] = '%' . $customerGroup . '%';
    }
    if ($activityTag !== '') {
        $groupStatsSql .= ' AND cu.activity_tag = :activity_tag';
        $groupStatsParams['activity_tag'] = $activityTag;
    }
    if ($status !== '') {
        $groupStatsSql .= ' AND c.status = :status';
        $groupStatsParams['status'] = $status;
    }
    if (!empty($salesUserIds)) {
        $ps = [];
        foreach ($salesUserIds as $idx => $uid) {
            $k = 'gs_sales_' . $idx;
            $ps[] = ':' . $k;
            $groupStatsParams[$k] = $uid;
        }
        $groupStatsSql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
    } elseif (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = 'gs_owner_' . $idx;
            $ps[] = ':' . $k;
            $groupStatsParams[$k] = $uid;
        }
        $groupStatsSql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($focusUserType !== '' && $focusUserId > 0) {
        if ($focusUserType === 'sales') {
            $groupStatsSql .= ' AND c.sales_user_id = :focus_user_id';
        } else {
            $groupStatsSql .= ' AND cu.owner_user_id = :focus_user_id';
        }
        $groupStatsParams['focus_user_id'] = $focusUserId;
    }
    // 添加时间筛选条件
    if ($dueStart !== '') {
        $groupStatsSql .= ' AND c.sign_date >= :due_start';
        $groupStatsParams['due_start'] = $dueStart;
    }
    if ($dueEnd !== '') {
        $groupStatsSql .= ' AND c.sign_date <= :due_end';
        $groupStatsParams['due_end'] = $dueEnd;
    }
    // 按实收日期筛选
    if ($receiptStart !== '' || $receiptEnd !== '') {
        $receiptCondition = '1=1';
        if ($receiptStart !== '') {
            $receiptCondition .= ' AND r.received_date >= :receipt_start';
            $groupStatsParams['receipt_start'] = $receiptStart . ' 00:00:00';
        }
        if ($receiptEnd !== '') {
            $receiptCondition .= ' AND r.received_date <= :receipt_end';
            $groupStatsParams['receipt_end'] = $receiptEnd . ' 23:59:59';
        }
        $groupStatsSql .= ' AND EXISTS (SELECT 1 FROM finance_receipts r WHERE r.contract_id = c.id AND ' . $receiptCondition . ')';
    }
    $groupStatsSql .= ' GROUP BY u.id, u.realname, c.currency';
    $groupStatsRows = Db::query($groupStatsSql, $groupStatsParams);
    foreach ($groupStatsRows as $row) {
        $key = $row['signer_name'] ?: '未分配签约人';
        $currency = $row['contract_currency'] ?: 'TWD';
        if (!isset($groupStats[$key])) {
            $groupStats[$key] = [
                'count' => 0,
                'by_currency' => []
            ];
        }
        $groupStats[$key]['count'] += (int)$row['contract_count'];
        if (!isset($groupStats[$key]['by_currency'][$currency])) {
            $groupStats[$key]['by_currency'][$currency] = [
                'sum_due' => 0, 'sum_paid' => 0, 'sum_unpaid' => 0
            ];
        }
        $groupStats[$key]['by_currency'][$currency]['sum_due'] += (float)$row['sum_due'];
        $groupStats[$key]['by_currency'][$currency]['sum_paid'] += (float)$row['sum_paid'];
        $groupStats[$key]['by_currency'][$currency]['sum_unpaid'] += (float)$row['sum_unpaid'];
    }
    
    // 按归属人分组统计
    $ownerGroupStatsSql = 'SELECT
        ou.realname AS owner_name,
        c.currency AS contract_currency,
        COUNT(DISTINCT c.id) AS contract_count,
        COALESCE(SUM(i.amount_due), 0) AS sum_due,
        COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
        COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid
    FROM finance_contracts c
    INNER JOIN customers cu ON cu.id = c.customer_id
    LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
    LEFT JOIN users ou ON ou.id = cu.owner_user_id
    WHERE 1=1';
    $ownerGroupStatsParams = [];
    if ($user['role'] === 'sales') {
        $ownerGroupStatsSql .= ' AND c.sales_user_id = :sales_user_id';
        $ownerGroupStatsParams['sales_user_id'] = (int)$user['id'];
    }
    if ($keyword !== '') {
        $ownerGroupStatsSql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw)';
        $ownerGroupStatsParams['kw'] = '%' . $keyword . '%';
    }
    if ($customerGroup !== '') {
        $ownerGroupStatsSql .= ' AND cu.customer_group LIKE :cg';
        $ownerGroupStatsParams['cg'] = '%' . $customerGroup . '%';
    }
    if ($activityTag !== '') {
        $ownerGroupStatsSql .= ' AND cu.activity_tag = :activity_tag';
        $ownerGroupStatsParams['activity_tag'] = $activityTag;
    }
    if ($status !== '') {
        $ownerGroupStatsSql .= ' AND c.status = :status';
        $ownerGroupStatsParams['status'] = $status;
    }
    if (!empty($ownerUserIds)) {
        $ps = [];
        foreach ($ownerUserIds as $idx => $uid) {
            $k = 'ogs_owner_' . $idx;
            $ps[] = ':' . $k;
            $ownerGroupStatsParams[$k] = $uid;
        }
        $ownerGroupStatsSql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
    }
    if ($focusUserType === 'owner' && $focusUserId > 0) {
        $ownerGroupStatsSql .= ' AND cu.owner_user_id = :focus_user_id';
        $ownerGroupStatsParams['focus_user_id'] = $focusUserId;
    }
    // 添加时间筛选条件
    if ($dueStart !== '') {
        $ownerGroupStatsSql .= ' AND c.sign_date >= :due_start';
        $ownerGroupStatsParams['due_start'] = $dueStart;
    }
    if ($dueEnd !== '') {
        $ownerGroupStatsSql .= ' AND c.sign_date <= :due_end';
        $ownerGroupStatsParams['due_end'] = $dueEnd;
    }
    // 按实收日期筛选
    if ($receiptStart !== '' || $receiptEnd !== '') {
        $receiptCondition = '1=1';
        if ($receiptStart !== '') {
            $receiptCondition .= ' AND r.received_date >= :receipt_start';
            $ownerGroupStatsParams['receipt_start'] = $receiptStart . ' 00:00:00';
        }
        if ($receiptEnd !== '') {
            $receiptCondition .= ' AND r.received_date <= :receipt_end';
            $ownerGroupStatsParams['receipt_end'] = $receiptEnd . ' 23:59:59';
        }
        $ownerGroupStatsSql .= ' AND EXISTS (SELECT 1 FROM finance_receipts r WHERE r.contract_id = c.id AND ' . $receiptCondition . ')';
    }
    $ownerGroupStatsSql .= ' GROUP BY ou.id, ou.realname, c.currency';
    $ownerGroupStatsRows = Db::query($ownerGroupStatsSql, $ownerGroupStatsParams);
    $ownerGroupStats = [];
    foreach ($ownerGroupStatsRows as $row) {
        $key = $row['owner_name'] ?: '未分配归属人';
        $currency = $row['contract_currency'] ?: 'TWD';
        if (!isset($ownerGroupStats[$key])) {
            $ownerGroupStats[$key] = [
                'count' => 0,
                'by_currency' => []
            ];
        }
        $ownerGroupStats[$key]['count'] += (int)$row['contract_count'];
        if (!isset($ownerGroupStats[$key]['by_currency'][$currency])) {
            $ownerGroupStats[$key]['by_currency'][$currency] = [
                'sum_due' => 0, 'sum_paid' => 0, 'sum_unpaid' => 0
            ];
        }
        $ownerGroupStats[$key]['by_currency'][$currency]['sum_due'] += (float)$row['sum_due'];
        $ownerGroupStats[$key]['by_currency'][$currency]['sum_paid'] += (float)$row['sum_paid'];
        $ownerGroupStats[$key]['by_currency'][$currency]['sum_unpaid'] += (float)$row['sum_unpaid'];
    }
}

$salesUsers = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname');

$currentMonth = date('Y-m');
$isSalesRole = ($user['role'] === 'sales');
$currentUserId = (int)($user['id'] ?? 0);

if ($isSalesRole) {
    $prepayStats = Db::queryOne(
        'SELECT 
            COALESCE(SUM(CASE WHEN l.direction = "in" THEN l.amount ELSE -l.amount END), 0) AS total_balance,
            COALESCE(SUM(CASE WHEN l.direction = "in" AND FROM_UNIXTIME(l.created_at, "%Y-%m") = :m THEN l.amount ELSE 0 END), 0) AS month_in,
            COALESCE(SUM(CASE WHEN l.direction = "out" AND FROM_UNIXTIME(l.created_at, "%Y-%m") = :m THEN l.amount ELSE 0 END), 0) AS month_out
         FROM finance_prepay_ledger l
         INNER JOIN customers c ON c.id = l.customer_id
         WHERE c.owner_user_id = :uid',
        ['m' => $currentMonth, 'uid' => $currentUserId]
    );
} else {
    $prepayStats = Db::queryOne(
        'SELECT 
            COALESCE(SUM(CASE WHEN direction = "in" THEN amount ELSE -amount END), 0) AS total_balance,
            COALESCE(SUM(CASE WHEN direction = "in" AND FROM_UNIXTIME(created_at, "%Y-%m") = :m THEN amount ELSE 0 END), 0) AS month_in,
            COALESCE(SUM(CASE WHEN direction = "out" AND FROM_UNIXTIME(created_at, "%Y-%m") = :m THEN amount ELSE 0 END), 0) AS month_out
         FROM finance_prepay_ledger',
        ['m' => $currentMonth]
    );
}
$prepayTotalBalance = (float)($prepayStats['total_balance'] ?? 0);
$prepayMonthIn = (float)($prepayStats['month_in'] ?? 0);
$prepayMonthOut = (float)($prepayStats['month_out'] ?? 0);

finance_sidebar_start('finance_dashboard');
?>


<!-- 筛选区域：常用筛选 + 折叠高级筛选 -->
<div class="card mb-2">
    <div class="card-body py-2">
        <form method="GET" id="dashFilterForm">
            <input type="hidden" name="page" value="finance_dashboard">
            <input type="hidden" name="view_id" value="<?= (int)$viewId ?>">
            <?php if ($viewMode !== 'staff_summary'): ?>
                <input type="hidden" name="group_by" value="<?= htmlspecialchars($groupBy) ?>">
            <?php endif; ?>
            
            <!-- 常用筛选（始终显示） -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <select class="form-select form-select-sm" name="view_mode" style="width:auto;">
                    <option value="contract" <?= $viewMode === 'contract' ? 'selected' : '' ?>>合同视图</option>
                    <option value="installment" <?= $viewMode === 'installment' ? 'selected' : '' ?>>分期视图</option>
                    <option value="staff_summary" <?= $viewMode === 'staff_summary' ? 'selected' : '' ?>>按人员汇总</option>
                </select>
                <input type="text" class="form-control form-control-sm" name="keyword" placeholder="客户/合同号/项目编号" value="<?= htmlspecialchars($keyword) ?>" style="width:180px;">
                <select class="form-select form-select-sm" name="status" style="width:auto;">
                    <option value="">全部状态</option>
                    <?php if ($viewMode === 'contract'): ?>
                        <option value="已结清" <?= $status === '已结清' ? 'selected' : '' ?>>已结清</option>
                        <option value="未结清" <?= $status === '未结清' ? 'selected' : '' ?>>未结清</option>
                    <?php elseif ($viewMode === 'installment'): ?>
                        <option value="待收" <?= $status === '待收' ? 'selected' : '' ?>>待收</option>
                        <option value="催款" <?= $status === '催款' ? 'selected' : '' ?>>催款</option>
                        <option value="逾期" <?= $status === '逾期' ? 'selected' : '' ?>>逾期</option>
                        <option value="部分已收" <?= $status === '部分已收' ? 'selected' : '' ?>>部分已收</option>
                        <option value="已收" <?= $status === '已收' ? 'selected' : '' ?>>已收</option>
                    <?php else: ?>
                        <option value="" selected>（汇总不按状态筛）</option>
                    <?php endif; ?>
                </select>
                <select class="form-select form-select-sm" name="date_type" id="dashDateType" style="width:auto;">
                    <option value="sign" <?= $dateType === 'sign' ? 'selected' : '' ?>>签约时间</option>
                    <option value="receipt" <?= $dateType === 'receipt' ? 'selected' : '' ?>>实收时间</option>
                </select>
                <select class="form-select form-select-sm" name="period" id="dashboardPeriodSelect" style="width:auto;">
                    <option value="" <?= ($period === '' && $dueStart === '' && $dueEnd === '' && $receiptStart === '' && $receiptEnd === '') ? 'selected' : '' ?>>所有时间</option>
                    <option value="this_month" <?= $period === 'this_month' ? 'selected' : '' ?>>本月</option>
                    <option value="last_month" <?= $period === 'last_month' ? 'selected' : '' ?>>上月</option>
                    <option value="custom" <?= ($period === 'custom' || ($period === '' && ($dueStart !== '' || $dueEnd !== '' || $receiptStart !== '' || $receiptEnd !== ''))) ? 'selected' : '' ?>>自定义</option>
                </select>
                <input type="date" class="form-control form-control-sm" id="dashDateStart" style="width:130px;" placeholder="开始日期">
                <input type="date" class="form-control form-control-sm" id="dashDateEnd" style="width:130px;" placeholder="结束日期">
                <input type="hidden" name="due_start" id="dashDueStart" value="<?= htmlspecialchars($dueStart) ?>">
                <input type="hidden" name="due_end" id="dashDueEnd" value="<?= htmlspecialchars($dueEnd) ?>">
                <input type="hidden" name="receipt_start" id="dashReceiptStart" value="<?= htmlspecialchars($receiptStart) ?>">
                <input type="hidden" name="receipt_end" id="dashReceiptEnd" value="<?= htmlspecialchars($receiptEnd) ?>">
                <input type="hidden" name="per_page" value="9999999">
                <?php if (in_array(($user['role'] ?? ''), ['finance', 'admin', 'system_admin', 'super_admin'], true) && $viewMode !== 'staff_summary'): ?>
                <div class="dropdown" data-bs-auto-close="outside">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        签约人<?= count($salesUserIds) > 0 ? '(' . count($salesUserIds) . ')' : '' ?>
                    </button>
                    <div class="dropdown-menu p-2" style="max-height:260px; overflow:auto; min-width:150px;" onclick="event.stopPropagation();">
                        <?php foreach ($salesUsers as $su): ?>
                            <?php $uid = (int)($su['id'] ?? 0); ?>
                            <label class="dropdown-item d-flex align-items-center gap-2 py-1" style="white-space:normal; cursor:pointer;">
                                <input class="form-check-input m-0" type="checkbox" name="sales_user_ids[]" value="<?= $uid ?>" <?= in_array($uid, $salesUserIds, true) ? 'checked' : '' ?>>
                                <span class="small"><?= htmlspecialchars($su['realname'] ?? '') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dropdown" data-bs-auto-close="outside">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        归属人<?= count($ownerUserIds) > 0 ? '(' . count($ownerUserIds) . ')' : '' ?>
                    </button>
                    <div class="dropdown-menu p-2" style="max-height:260px; overflow:auto; min-width:150px;" onclick="event.stopPropagation();">
                        <?php foreach ($salesUsers as $su): ?>
                            <?php $uid = (int)($su['id'] ?? 0); ?>
                            <label class="dropdown-item d-flex align-items-center gap-2 py-1" style="white-space:normal; cursor:pointer;">
                                <input class="form-check-input m-0" type="checkbox" name="owner_user_ids[]" value="<?= $uid ?>" <?= in_array($uid, $ownerUserIds, true) ? 'checked' : '' ?>>
                                <span class="small"><?= htmlspecialchars($su['realname'] ?? '') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="applyDashboardFilters()"><i class="bi bi-search"></i> 筛选</button>
                <a href="index.php?page=finance_dashboard" class="btn btn-outline-secondary btn-sm">重置</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnToggleAdvFilter">
                    <i class="bi bi-sliders"></i> 高级
                </button>
                <button type="button" class="btn btn-success btn-sm" onclick="exportDashboard()">
                    <i class="bi bi-download"></i> 导出
                </button>
                <span class="text-muted small ms-2">共 <?= $total ?> 条</span>
            </div>
            
            <!-- 高级筛选（默认折叠） -->
            <div id="advancedFilterPanel" class="border-top pt-2 mt-1" style="display:none;">
                <div class="row g-2 align-items-end">
                    <?php if ($viewMode === 'staff_summary' && ($user['role'] ?? '') !== 'sales'): ?>
                        <div class="col-lg-2 col-md-4 col-sm-6">
                            <label class="form-label small text-muted mb-1">分组方式</label>
                            <select class="form-select form-select-sm" name="group_by">
                                <option value="sales" <?= $groupBy === 'sales' ? 'selected' : '' ?>>按合同签约人</option>
                                <option value="owner" <?= $groupBy === 'owner' ? 'selected' : '' ?>>按归属人</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 mt-2" id="customerFilterFieldsContainer"></div>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const advPanel = document.getElementById('advancedFilterPanel');
    const toggleBtn = document.getElementById('btnToggleAdvFilter');
    const STORAGE_KEY = 'finance_dash_adv_filter_expanded';
    
    // 恢复折叠状态
    if (localStorage.getItem(STORAGE_KEY) === 'true') {
        advPanel.style.display = 'block';
        toggleBtn.classList.add('active');
    }
    
    toggleBtn?.addEventListener('click', function() {
        const isHidden = advPanel.style.display === 'none';
        advPanel.style.display = isHidden ? 'block' : 'none';
        toggleBtn.classList.toggle('active', isHidden);
        localStorage.setItem(STORAGE_KEY, isHidden ? 'true' : 'false');
    });
})();
</script>

<?php if ($viewMode !== 'staff_summary'): ?>
<!-- 汇总统计卡片 -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="card border-0 bg-primary bg-opacity-10 h-100">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-primary bg-opacity-25 p-2 me-2">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                    </div>
                    <div>
                        <div class="text-muted small">合同数</div>
                        <div class="fw-bold fs-5"><?= number_format((int)($sumRow['contract_count'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 bg-info bg-opacity-10 h-100">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-info bg-opacity-25 p-2 me-2">
                        <i class="bi bi-calendar-check text-info"></i>
                    </div>
                    <div>
                        <div class="text-muted small">分期数</div>
                        <div class="fw-bold fs-5"><?= number_format((int)($sumRow['installment_count'] ?? 0)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 bg-secondary bg-opacity-10 h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">应收合计</div>
                <div class="fw-bold text-dark" id="sumDueDisplay"><?= number_format((float)($sumRow['sum_due'] ?? 0), 2) ?></div>
                <small id="sumDueCurrency" class="text-secondary"></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 bg-success bg-opacity-10 h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">已收合计</div>
                <div class="fw-bold text-success fs-4" id="sumPaidDisplay"><?= number_format((float)($sumRow['sum_paid'] ?? 0), 2) ?></div>
                <small id="sumPaidCurrency" class="text-secondary"></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card border-0 bg-danger bg-opacity-10 h-100">
            <div class="card-body py-2 px-3">
                <div class="text-muted small">未收合计</div>
                <div class="fw-bold text-danger fs-5" id="sumUnpaidDisplay"><?= number_format((float)($sumRow['sum_unpaid'] ?? 0), 2) ?></div>
                <small id="sumUnpaidCurrency" class="text-secondary"></small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-2">
        <div class="card border-0 bg-light h-100">
            <div class="card-body py-2 px-3">
                <div class="d-flex flex-column gap-1">
                    <select class="form-select form-select-sm" id="dashAmountMode">
                        <option value="original">原始(TWD)</option>
                        <option value="fixed" selected>固定汇率(CNY)</option>
                        <option value="floating">浮动汇率(CNY)</option>
                    </select>
                    <div class="d-flex gap-1">
                        <select class="form-select form-select-sm" id="dashGroup1">
                            <option value="">分组合计</option>
                            <option value="settlement_status">已结清/未结清</option>
                            <option value="status">状态</option>
                            <option value="create_month">创建月份</option>
                            <option value="receipt_month">收款月份</option>
                            <option value="sales_user">按签约人</option>
                            <option value="owner_user">按归属人</option>
                            <option value="payment_method">按收款方式</option>
                        </select>
                        <div id="dashColumnToggleContainer"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    // 按货币分别统计的金额
    const sumByCurrency = <?= json_encode($sumByCurrency, JSON_UNESCAPED_UNICODE) ?>;
    let rates = {};  // 各货币汇率
    
    fetch(API_URL + '/exchange_rate_list.php').then(r => r.json()).then(res => {
        if (res.success && res.data) {
            res.data.forEach(c => {
                rates[c.code] = {
                    fixed: parseFloat(c.fixed_rate) || 1,
                    floating: parseFloat(c.floating_rate) || parseFloat(c.fixed_rate) || 1
                };
            });
            rates['CNY'] = { fixed: 1, floating: 1 };
            updateAmountDisplay();
        }
    }).catch(() => {});
    
    function getRate(code, useFloating) {
        if (code === 'CNY') return 1;
        const r = rates[code] || rates['TWD'] || { fixed: 4.5, floating: 4.5 };
        return useFloating ? r.floating : r.fixed;
    }
    
    // 将金额从原始货币转换到目标货币
    function convertToTarget(amount, fromCode, toCode, useFloating) {
        if (fromCode === toCode) return amount;
        // 先转换到CNY，再转换到目标货币
        const fromRate = getRate(fromCode, useFloating);
        const toRate = getRate(toCode, useFloating);
        // 汇率是相对于CNY的，所以 amount / fromRate = CNY金额，CNY金额 * toRate = 目标货币金额
        return (amount / fromRate) * toRate;
    }
    
    function updateAmountDisplay() {
        const mode = document.getElementById('dashAmountMode')?.value || 'fixed';
        let due = 0, paid = 0, unpaid = 0, currency = '';
        const useFloating = (mode === 'floating');
        
        if (mode === 'original') {
            // 原始金额模式：统一转换为TWD
            Object.keys(sumByCurrency).forEach(code => {
                due += convertToTarget(sumByCurrency[code].sum_due, code, 'TWD', false);
                paid += convertToTarget(sumByCurrency[code].sum_paid, code, 'TWD', false);
                unpaid += convertToTarget(sumByCurrency[code].sum_unpaid, code, 'TWD', false);
            });
            currency = 'TWD';
        } else {
            // 转换到CNY
            Object.keys(sumByCurrency).forEach(code => {
                const rate = getRate(code, useFloating);
                due += sumByCurrency[code].sum_due / rate;
                paid += sumByCurrency[code].sum_paid / rate;
                unpaid += sumByCurrency[code].sum_unpaid / rate;
            });
            currency = 'CNY';
        }
        
        const fmt = (v) => v.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumDueDisplay').textContent = fmt(due);
        document.getElementById('sumPaidDisplay').textContent = fmt(paid);
        document.getElementById('sumUnpaidDisplay').textContent = fmt(unpaid);
        document.getElementById('sumDueCurrency').textContent = currency;
        document.getElementById('sumPaidCurrency').textContent = currency;
        document.getElementById('sumUnpaidCurrency').textContent = currency;
    }
    
    // 更新表格中的金额单元格显示
    function updateAmountCells() {
        const mode = document.getElementById('dashAmountMode')?.value || 'fixed';
        const useFloating = (mode === 'floating');
        const fmt = (v) => v.toLocaleString('zh-CN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        document.querySelectorAll('.amount-cell').forEach(cell => {
            const amount = parseFloat(cell.getAttribute('data-amount') || 0);
            const currency = (cell.getAttribute('data-currency') || 'TWD').toUpperCase();
            const convertedEl = cell.querySelector('.amount-converted');
            
            if (mode === 'original') {
                // 原始模式：非TWD货币显示转换后的TWD金额
                if (currency === 'TWD') {
                    if (convertedEl) convertedEl.style.display = 'none';
                } else {
                    const converted = convertToTarget(amount, currency, 'TWD', false);
                    if (convertedEl) {
                        convertedEl.textContent = '≈ ' + fmt(converted) + ' TWD';
                        convertedEl.style.display = 'block';
                    }
                }
            } else {
                // 折算模式：只有非CNY货币才显示折算金额
                if (currency === 'CNY') {
                    if (convertedEl) convertedEl.style.display = 'none';
                } else {
                    const rate = getRate(currency, useFloating);
                    const converted = amount / rate;
                    if (convertedEl) {
                        convertedEl.textContent = '≈ ' + fmt(converted) + ' CNY';
                        convertedEl.style.display = 'block';
                    }
                }
            }
        });
    }
    
    document.getElementById('dashAmountMode')?.addEventListener('change', function() {
        updateAmountDisplay();
        updateAmountCells();
        // 更新分组合计显示
        if (typeof updateGroupSumsDisplay === 'function') {
            updateGroupSumsDisplay();
        }
    });
    updateAmountDisplay();
    // 延迟执行以确保汇率已加载
    setTimeout(updateAmountCells, 500);
})();
</script>
<?php endif; ?>

<div id="dashboardConfig" 
    data-api-url="<?= htmlspecialchars(API_URL) ?>"
    data-view-mode="<?= htmlspecialchars($viewMode) ?>"
    data-current-role="<?= htmlspecialchars((string)($user['role'] ?? '')) ?>"
    data-current-user-id="<?= (int)($user['id'] ?? 0) ?>"
    data-server-now-ts="<?= (int)time() ?>"
    data-initial-view-id="<?= (int)$viewId ?>"
    data-group-stats="<?= htmlspecialchars(json_encode($groupStats)) ?>"
    data-owner-group-stats="<?= htmlspecialchars(json_encode($ownerGroupStats ?? [])) ?>"
    data-can-receipt="<?= in_array(($user['role'] ?? ''), ['sales', 'sales_manager', 'finance', 'admin', 'system_admin', 'super_admin', 'dept_admin', 'dept_leader', 'tech', 'tech_manager', 'service'], true) ? 'true' : 'false' ?>"
    data-focus-user-type="<?= htmlspecialchars($focusUserType) ?>"
    data-focus-user-id="<?= (int)$focusUserId ?>"
    style="display:none;"></div>

<div class="card">
    <div class="card-body">
        <!-- 无限滚动容器 -->
        <div id="dashboardScrollContainer" style="max-height: 840px; overflow-y: auto; position: relative;">
            <table class="table table-hover align-middle" id="financeDashboardTable" style="margin-bottom: 0;">
                <thead style="position: sticky; top: 0; background: #fff; z-index: 10;">
                <tr>
                    <?php if ($viewMode === 'contract'): ?>
                        <th style="width:40px;"></th>
                        <th style="width:50px;">序号</th>
                        <th>客户</th>
                        <th>活动标签</th>
                        <th>合同</th>
                        <th>签约人</th>
                        <th>客户归属</th>
                        <th>分期数</th>
                        <th>应收</th>
                        <th>已收</th>
                        <th>未收</th>
                        <th>
                            状态
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="status" title="按状态排序">状</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="create_time" title="按创建时间排序">创</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="receipt_time" title="按收款时间排序">收</button>
                        </th>
                        <th>附件</th>
                        <th>操作</th>
                    <?php elseif ($viewMode === 'staff_summary'): ?>
                        <th>人员</th>
                        <th>合同数</th>
                        <th>合同额</th>
                        <th>收款额</th>
                        <th>未收金额</th>
                        <th style="width:220px;">详情</th>
                    <?php else: ?>
                        <th>客户</th>
                        <th>活动标签</th>
                        <th>合同</th>
                        <th>签约人</th>
                        <th>客户归属</th>
                        <th>收款人</th>
                        <th>收款时间</th>
                        <th>
                            创建时间
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="create_time" title="按创建时间排序">创</button>
                        </th>
                        <th>到期日</th>
                        <th>应收</th>
                        <th>已收</th>
                        <th>未收</th>
                        <th>逾期(天)</th>
                        <th>
                            状态
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="status" title="按状态排序">状</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 dashSortBtn" data-sort="receipt_time" title="按收款时间排序">收</button>
                        </th>
                        <th style="width:260px;">操作</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= $viewMode === 'contract' ? 14 : ($viewMode === 'staff_summary' ? 6 : 15) ?>" class="text-center text-muted">暂无数据</td></tr>
                <?php else: ?>
                    <?php $rowIndex = 0; ?>
                    <?php foreach ($rows as $row): ?>
                    <?php $rowIndex++; ?>
                        <?php
                        $statusLabel = '';
                        $badge = 'secondary';
                        if ($viewMode === 'contract') {
                            $statusLabel = mapContractStatusLabel(($row['contract_status'] ?? ''), ($row['contract_manual_status'] ?? ''));
                        } elseif ($viewMode === 'installment') {
                            $statusLabel = mapInstallmentStatusLabel(($row['amount_due'] ?? 0), ($row['amount_paid'] ?? 0), ($row['due_date'] ?? ''), ($row['manual_status'] ?? ''));
                        }
                        if ($statusLabel !== '') {
                            if (in_array($statusLabel, ['已结清', '已收'], true)) {
                                $badge = 'success';
                            } elseif (in_array($statusLabel, ['部分已收', '催款'], true)) {
                                $badge = 'warning';
                            } elseif (in_array($statusLabel, ['逾期'], true)) {
                                $badge = 'danger';
                            } elseif (in_array($statusLabel, ['待收'], true)) {
                                $badge = 'primary';
                            } elseif (in_array($statusLabel, ['作废'], true)) {
                                $badge = 'secondary';
                            }
                        }
                        ?>
                        <?php
                        $rowCreateTs = 0;
                        $rowLastReceipt = '';
                        if ($viewMode === 'contract') {
                            $rowCreateTs = (int)($row['contract_create_time'] ?? 0);
                            $rowLastReceipt = (string)($row['last_received_date'] ?? '');
                        } elseif ($viewMode === 'installment') {
                            $rowCreateTs = (int)($row['create_time'] ?? 0);
                            $rowLastReceipt = (string)($row['last_received_date'] ?? '');
                        }
                        ?>
                        <tr
                            <?= $viewMode === 'contract' ? 'data-contract-row="1" data-contract-id="' . (int)($row['contract_id'] ?? 0) . '" data-total-due="' . (float)($row['total_due'] ?? 0) . '" data-total-paid="' . (float)($row['total_paid'] ?? 0) . '" data-due-date="' . htmlspecialchars((string)($row['first_due_date'] ?? '')) . '"' : '' ?>
                            <?= $viewMode === 'installment' ? 'data-installment-id="' . (int)($row['id'] ?? 0) . '" data-amount-due="' . (float)($row['amount_due'] ?? 0) . '" data-amount-paid="' . (float)($row['amount_paid'] ?? 0) . '" data-due-date="' . htmlspecialchars((string)($row['due_date'] ?? '')) . '"' : '' ?>
                            <?= $viewMode !== 'staff_summary' ? 'data-status-label="' . htmlspecialchars((string)$statusLabel) . '" data-create-time="' . (int)$rowCreateTs . '" data-last-received-date="' . htmlspecialchars($rowLastReceipt) . '" data-signer-name="' . htmlspecialchars((string)($row['signer_name'] ?? '')) . '" data-owner-name="' . htmlspecialchars((string)($row['owner_name'] ?? '')) . '" data-payment-method="' . htmlspecialchars(getPaymentMethodLabel((string)($row['last_payment_method'] ?? '')) ?: '未收款') . '"' : '' ?>
                        >
                            <?php if ($viewMode === 'contract'): ?>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-secondary btnToggleInstallments" data-contract-id="<?= (int)($row['contract_id'] ?? 0) ?>">▾</button>
                                </td>
                                <td class="text-muted small"><?= $rowIndex ?></td>
                            <?php endif; ?>
                            <?php if ($viewMode === 'staff_summary'): ?>
                                <td><?= htmlspecialchars($row['user_name'] ?? '') ?></td>
                                <td><?= (int)($row['contract_count'] ?? 0) ?></td>
                                <td><?= number_format((float)($row['contract_amount'] ?? 0), 2) ?></td>
                                <td><?= number_format((float)($row['receipt_amount'] ?? 0), 2) ?></td>
                                <td><?= number_format((float)($row['unpaid_amount'] ?? 0), 2) ?></td>
                                <td>
                                    <?php
                                    $ft = $groupBy === 'owner' ? 'owner' : 'sales';
                                    $fid = (int)($row['user_id'] ?? 0);
                                    $baseUrl = 'index.php?page=finance_dashboard'
                                        . '&focus_user_type=' . urlencode($ft)
                                        . '&focus_user_id=' . $fid
                                        . ($keyword !== '' ? '&keyword=' . urlencode($keyword) : '')
                                        . ($customerGroup !== '' ? '&customer_group=' . urlencode($customerGroup) : '')
                                        . ($activityTag !== '' ? '&activity_tag=' . urlencode($activityTag) : '')
                                        . ($dueStart !== '' ? '&due_start=' . urlencode($dueStart) : '')
                                        . ($dueEnd !== '' ? '&due_end=' . urlencode($dueEnd) : '');
                                    ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($baseUrl . '&view_mode=contract') ?>">看合同</a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($baseUrl . '&view_mode=installment') ?>">看分期</a>
                                </td>
                            <?php else: ?>
                                <td>
                                    <div><?= htmlspecialchars($row['customer_name'] ?? '') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($row['customer_code'] ?? '') ?> <?= htmlspecialchars($row['customer_mobile'] ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($row['activity_tag'] ?? '') ?></td>
                                <td>
                                    <div><?= htmlspecialchars($row['contract_no'] ?? '') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($row['contract_title'] ?? '') ?></div>
                                    <?php if ($viewMode === 'contract'): ?>
                                        <div class="small text-muted">签约：<?= !empty($row['contract_sign_date']) ? htmlspecialchars(substr($row['contract_sign_date'], 0, 16)) : '-' ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['signer_name'] ?? '') ?></td>
                                <?php if ($viewMode === 'contract'): ?>
                                    <td><?= htmlspecialchars($row['owner_name'] ?? '') ?></td>
                                <?php endif; ?>
                                <?php if ($viewMode === 'contract'): ?>
                                    <?php $contractCurrency = $row['contract_currency'] ?? 'TWD'; ?>
                                    <td><?= (int)($row['installment_count'] ?? 0) ?></td>
                                    <td class="amount-cell" data-amount="<?= (float)($row['total_due'] ?? 0) ?>" data-currency="<?= htmlspecialchars($contractCurrency) ?>">
                                        <span class="amount-original"><?= number_format((float)($row['total_due'] ?? 0), 2) ?></span>
                                        <small class="text-muted"><?= htmlspecialchars($contractCurrency) ?></small>
                                        <div class="amount-converted text-info small" style="display:none;"></div>
                                    </td>
                                    <td class="amount-cell" data-amount="<?= (float)($row['total_paid'] ?? 0) ?>" data-currency="<?= htmlspecialchars($contractCurrency) ?>">
                                        <span class="amount-original fw-semibold text-success"><?= number_format((float)($row['total_paid'] ?? 0), 2) ?></span>
                                        <small class="text-muted"><?= htmlspecialchars($contractCurrency) ?></small>
                                        <div class="amount-converted text-success small" style="display:none;"></div>
                                    </td>
                                    <td class="amount-cell" data-amount="<?= (float)($row['total_unpaid'] ?? 0) ?>" data-currency="<?= htmlspecialchars($contractCurrency) ?>">
                                        <span class="amount-original fw-semibold text-danger"><?= number_format((float)($row['total_unpaid'] ?? 0), 2) ?></span>
                                        <small class="text-muted"><?= htmlspecialchars($contractCurrency) ?></small>
                                        <div class="amount-converted text-danger small" style="display:none;"></div>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill bg-<?= $badge ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                        <div class="small text-muted">最近收款：<?= !empty($row['last_received_date']) ? htmlspecialchars((string)$row['last_received_date']) : '-' ?></div>
                                    </td>
                                    <td>
                                        <?php
                                        $fileIdsRaw = (string)($row['contract_file_ids'] ?? '');
                                        $fileNamesRaw = (string)($row['contract_file_names'] ?? '');
                                        $filePreviewRaw = (string)($row['contract_file_preview_supported'] ?? '');
                                        $fileIds = $fileIdsRaw !== '' ? array_values(array_filter(array_map('intval', explode(',', $fileIdsRaw)), static fn($v) => $v > 0)) : [];
                                        $fileNames = $fileNamesRaw !== '' ? explode('|||', $fileNamesRaw) : [];
                                        $filePreview = $filePreviewRaw !== '' ? array_map('intval', explode(',', $filePreviewRaw)) : [];
                                        ?>
                                        <?php if (empty($fileIds)): ?>
                                            <span class="text-muted">-</span>
                                        <?php else: ?>
                                            <?php foreach ($fileIds as $idx => $fid): ?>
                                                <?php
                                                $fname = $fileNames[$idx] ?? ('文件#' . $fid);
                                                $canPreview = ((int)($filePreview[$idx] ?? 0)) === 1;
                                                ?>
                                                <div class="small">
                                                    <?php if ($canPreview): ?>
                                                        <a href="javascript:void(0)" onclick="showFileLightbox(<?= (int)$fid ?>, '<?= htmlspecialchars(addslashes($fname)) ?>')">预览</a>
                                                        <span class="text-muted">/</span>
                                                    <?php endif; ?>
                                                    <a href="/api/customer_file_stream.php?id=<?= (int)$fid ?>&mode=download" target="_blank"><?= htmlspecialchars($fname) ?></a>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?page=finance_contract_detail&id=<?= (int)($row['contract_id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">合同详情</a>
                                        <button type="button" class="btn btn-sm btn-outline-danger btnContractDelete" data-contract-id="<?= (int)($row['contract_id'] ?? 0) ?>" data-create-time="<?= (int)($row['contract_create_time'] ?? 0) ?>" data-sales-user-id="<?= (int)($row['sales_user_id'] ?? 0) ?>">删除</button>
                                    </td>
                                <?php else: ?>
                                    <?php
                                    $instId = (int)($row['id'] ?? 0);
                                    $instDue = (string)($row['due_date'] ?? '');
                                    $instAmt = (string)($row['amount_due'] ?? '0');
                                    $instFullyPaid = ((float)($row['amount_due'] ?? 0) > 0 && (float)($row['amount_due'] ?? 0) - (float)($row['amount_paid'] ?? 0) <= 0.00001);
                                    ?>
                                    <td><?= htmlspecialchars($row['owner_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['collector_name'] ?? '-') ?></td>
                                    <td><?= !empty($row['last_received_date']) ? htmlspecialchars($row['last_received_date']) : '-' ?></td>
                                    <td><?= !empty($row['create_time']) ? date('Y-m-d H:i', (int)$row['create_time']) : '-' ?></td>
                                    <td><?= htmlspecialchars($instDue) ?></td>
                                    <td><?= number_format((float)$row['amount_due'], 2) ?></td>
                                    <td><?= number_format((float)$row['amount_paid'], 2) ?></td>
                                    <td><?= number_format((float)$row['amount_unpaid'], 2) ?></td>
                                    <td><?= (int)$row['overdue_days'] ?></td>
                                    <td>
                                        <span class="badge bg-<?= $badge ?> btnInstallmentStatusBadge" style="cursor:pointer;" data-installment-id="<?= $instId ?>" data-current-status="<?= htmlspecialchars($statusLabel) ?>" title="点击修改状态"><?= htmlspecialchars($statusLabel) ?></span>
                                        <div class="small text-muted">最近收款：<?= !empty($row['last_received_date']) ? htmlspecialchars((string)$row['last_received_date']) : '-' ?></div>
                                        <div class="small text-info">货币：<?= htmlspecialchars($row['contract_currency'] ?? 'TWD') ?></div>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-1 align-items-center flex-wrap">
                                            <div class="inst-file-thumb" data-installment-id="<?= $instId ?>" data-customer-id="<?= (int)($row['customer_id'] ?? 0) ?>" style="width:36px;height:36px;border:1px dashed #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:9px;color:#999;" title="点击查看/上传凭证">
                                                <span class="thumb-loading">...</span>
                                            </div>
                                            <a href="index.php?page=customer_detail&id=<?= (int)($row['customer_id'] ?? 0) ?>#tab-finance" class="btn btn-sm btn-outline-primary">客户财务</a>
                                            <?php if (!$instFullyPaid): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning btnInstallmentStatus" data-installment-id="<?= $instId ?>">改状态</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tr>
                        <?php if ($viewMode === 'contract'): ?>
                            <tr class="d-none" data-installments-holder="1" data-contract-id="<?= (int)($row['contract_id'] ?? 0) ?>">
                                <td colspan="15" class="bg-light p-0">
                                    <div class="text-muted small p-2">加载中...</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <!-- 无限滚动加载状态提示 -->
            <div id="dashboardLoadMoreStatus" class="text-center py-3" style="display: none;">
                <div id="loadingIndicator" class="d-none">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">加载中...</span>
                    </div>
                    <span class="ms-2 text-muted small">正在加载...</span>
                </div>
                <div id="allLoadedIndicator" class="d-none">
                    <span class="text-muted small">✓ 已加载全部数据</span>
                </div>
                <div id="loadedCountIndicator" class="d-none">
                    <span class="text-muted small">已加载 <span id="loadedCount">0</span> / <span id="totalCount">0</span> 条</span>
                </div>
            </div>
        </div>
    </div>
</div>


<?php include __DIR__ . '/../views/finance/dashboard_modals.php'; ?>

<script>
// 初始化客户分类筛选字段
document.addEventListener('DOMContentLoaded', function() {
    // 日期类型切换逻辑
    const dateType = document.getElementById('dashDateType');
    const dateStart = document.getElementById('dashDateStart');
    const dateEnd = document.getElementById('dashDateEnd');
    const periodSelect = document.getElementById('dashboardPeriodSelect');
    const dueStart = document.getElementById('dashDueStart');
    const dueEnd = document.getElementById('dashDueEnd');
    const receiptStart = document.getElementById('dashReceiptStart');
    const receiptEnd = document.getElementById('dashReceiptEnd');
    
    // 获取本月/上月日期范围
    function getMonthRange(type) {
        const now = new Date();
        let start, end;
        if (type === 'this_month') {
            start = new Date(now.getFullYear(), now.getMonth(), 1);
            end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        } else if (type === 'last_month') {
            start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            end = new Date(now.getFullYear(), now.getMonth(), 0);
        }
        const fmt = (d) => d.toISOString().split('T')[0];
        return { start: fmt(start), end: fmt(end) };
    }
    
    function syncDateFields() {
        const type = dateType?.value || 'sign';
        if (type === 'sign') {
            dueStart.value = dateStart.value;
            dueEnd.value = dateEnd.value;
            receiptStart.value = '';
            receiptEnd.value = '';
        } else {
            receiptStart.value = dateStart.value;
            receiptEnd.value = dateEnd.value;
            dueStart.value = '';
            dueEnd.value = '';
        }
    }
    
    function initDateFields() {
        const type = dateType?.value || 'sign';
        if (type === 'sign') {
            dateStart.value = dueStart?.value || '';
            dateEnd.value = dueEnd?.value || '';
        } else {
            dateStart.value = receiptStart?.value || '';
            dateEnd.value = receiptEnd?.value || '';
        }
    }
    
    // 时间段选择变化时自动填充日期
    function onPeriodChange() {
        const period = periodSelect?.value || '';
        if (period === 'this_month' || period === 'last_month') {
            const range = getMonthRange(period);
            dateStart.value = range.start;
            dateEnd.value = range.end;
            syncDateFields();
        } else if (period === '') {
            dateStart.value = '';
            dateEnd.value = '';
            syncDateFields();
        }
    }
    
    if (dateType && dateStart && dateEnd) {
        initDateFields();
        dateType.addEventListener('change', function() {
            initDateFields();
            // 如果当前选择了本月/上月，重新应用日期范围
            const period = periodSelect?.value || '';
            if (period === 'this_month' || period === 'last_month') {
                onPeriodChange();
            }
        });
        dateStart.addEventListener('change', function() {
            syncDateFields();
            // 手动修改日期时切换到自定义
            if (periodSelect) periodSelect.value = 'custom';
        });
        dateEnd.addEventListener('change', function() {
            syncDateFields();
            if (periodSelect) periodSelect.value = 'custom';
        });
    }
    
    if (periodSelect) {
        periodSelect.addEventListener('change', onPeriodChange);
    }
    
    if (typeof CustomerFilterFields !== 'undefined') {
        const selectedValues = CustomerFilterFields.parseUrlParams();
        CustomerFilterFields.render('customerFilterFieldsContainer', {
            selectedValues: selectedValues,
            showLabel: true,
            size: 'sm'
        });
    }
    
    // Ajax模式初始化
    console.log('[INFSC-DEBUG] 检查Ajax初始化条件:', {
        AjaxDashboard: typeof AjaxDashboard,
        viewMode: DashboardConfig.viewMode,
        canInit: typeof AjaxDashboard !== 'undefined' && DashboardConfig.viewMode === 'contract'
    });
    
    if (typeof AjaxDashboard !== 'undefined' && DashboardConfig.viewMode === 'contract') {
        console.log('[财务工作台] 启用Ajax模式 - 一次性加载全部数据');
        
        // 初始加载数据（不分页，加载全部）
        AjaxDashboard.reload();
        
        // 绑定筛选器事件
        ['keyword', 'customerGroup', 'activityTag', 'status', 'dueStart', 'dueEnd'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => {
                    AjaxDashboard.debounce(() => AjaxDashboard.reload());
                });
            }
        });
        
        // 绑定多选筛选器
        ['salesUsers', 'ownerUsers'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => AjaxDashboard.reload());
            }
        });
        
        // 绑定分组选择
        const groupEl = document.getElementById('dashGroup1');
        if (groupEl) {
            groupEl.addEventListener('change', () => AjaxDashboard.reload());
        }
        
        // 绑定搜索按钮
        const searchBtn = document.querySelector('button[type="submit"]');
        if (searchBtn) {
            searchBtn.addEventListener('click', (e) => {
                e.preventDefault();
                AjaxDashboard.reload();
            });
        }
    }
});
</script>
<?php
finance_sidebar_end();
layout_footer();
