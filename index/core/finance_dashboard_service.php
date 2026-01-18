<?php
/**
 * Finance Dashboard Service
 * 财务工作台业务逻辑层
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/finance_status.php';

class FinanceDashboardService
{
    /**
     * 映射合同状态标签
     */
    public static function mapContractStatusLabel($status, $manualStatus): string
    {
        $ms = trim((string)($manualStatus ?? ''));
        if ($ms !== '') {
            return $ms;
        }
        $s = (string)($status ?? '');
        $map = [
            'active' => '进行中',
            'closed' => '已结清',
            'cancelled' => '已取消',
            'pending' => '待收',
            'overdue' => '逾期',
        ];
        return $map[$s] ?? $s;
    }

    /**
     * 映射分期状态标签
     */
    public static function mapInstallmentStatusLabel($amountDue, $amountPaid, $dueDate, $manualStatus): string
    {
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

    /**
     * 获取状态对应的徽章样式
     */
    public static function getStatusBadge(string $statusLabel): string
    {
        if (in_array($statusLabel, ['已结清', '已收'], true)) {
            return 'success';
        }
        if (in_array($statusLabel, ['部分已收', '催款'], true)) {
            return 'warning';
        }
        if (in_array($statusLabel, ['逾期'], true)) {
            return 'danger';
        }
        if (in_array($statusLabel, ['待收'], true)) {
            return 'primary';
        }
        if (in_array($statusLabel, ['作废'], true)) {
            return 'secondary';
        }
        return 'secondary';
    }

    /**
     * 解析筛选器参数
     */
    public static function parseFilters(array $get, array $viewFilters = []): array
    {
        $filters = [
            'keyword' => trim((string)($get['keyword'] ?? '')),
            'customer_group' => trim((string)($get['customer_group'] ?? '')),
            'activity_tag' => trim((string)($get['activity_tag'] ?? '')),
            'status' => trim((string)($get['status'] ?? '')),
            'due_start' => trim((string)($get['due_start'] ?? '')),
            'due_end' => trim((string)($get['due_end'] ?? '')),
            'sales_user_ids' => [],
            'owner_user_ids' => [],
        ];

        // 解析sales_user_ids
        $salesUserIds = $get['sales_user_ids'] ?? [];
        if (!is_array($salesUserIds)) {
            $salesUserIds = [];
        }
        $filters['sales_user_ids'] = array_values(array_unique(array_filter(array_map('intval', $salesUserIds), static fn($v) => $v > 0)));

        // 解析owner_user_ids
        $ownerUserIds = $get['owner_user_ids'] ?? [];
        if (!is_array($ownerUserIds)) {
            $ownerUserIds = [];
        }
        $filters['owner_user_ids'] = array_values(array_unique(array_filter(array_map('intval', $ownerUserIds), static fn($v) => $v > 0)));

        // 从视图筛选中补充
        if (!empty($viewFilters)) {
            if ($filters['keyword'] === '' && isset($viewFilters['keyword'])) {
                $filters['keyword'] = trim((string)$viewFilters['keyword']);
            }
            if ($filters['activity_tag'] === '' && isset($viewFilters['activity_tag'])) {
                $filters['activity_tag'] = trim((string)$viewFilters['activity_tag']);
            }
            if ($filters['status'] === '' && isset($viewFilters['status'])) {
                $filters['status'] = trim((string)$viewFilters['status']);
            }
            if ($filters['due_start'] === '' && isset($viewFilters['due_start'])) {
                $filters['due_start'] = trim((string)$viewFilters['due_start']);
            }
            if ($filters['due_end'] === '' && isset($viewFilters['due_end'])) {
                $filters['due_end'] = trim((string)$viewFilters['due_end']);
            }
            if (empty($filters['sales_user_ids']) && isset($viewFilters['sales_user_ids']) && is_array($viewFilters['sales_user_ids'])) {
                $filters['sales_user_ids'] = array_values(array_unique(array_filter(array_map('intval', $viewFilters['sales_user_ids']), static fn($v) => $v > 0)));
            }
            if (empty($filters['owner_user_ids']) && isset($viewFilters['owner_user_ids']) && is_array($viewFilters['owner_user_ids'])) {
                $filters['owner_user_ids'] = array_values(array_unique(array_filter(array_map('intval', $viewFilters['owner_user_ids']), static fn($v) => $v > 0)));
            }
        }

        return $filters;
    }

    /**
     * 获取销售用户列表
     */
    public static function getSalesUsers(): array
    {
        return Db::queryAll(
            "SELECT id, realname FROM users WHERE role IN ('sales', 'finance', 'admin', 'system_admin', 'tech', 'tech_manager', 'service', 'sales_manager', 'dept_admin', 'dept_leader') AND status = 1 ORDER BY realname"
        );
    }

    /**
     * 加载保存的视图筛选器
     */
    public static function loadViewFilters(int $viewId, int $userId): array
    {
        if ($viewId <= 0) {
            return [];
        }
        $vr = Db::queryOne(
            'SELECT filters_json FROM finance_saved_views WHERE id = :id AND user_id = :uid AND page_key = :pk AND status = 1 LIMIT 1',
            [
                'id' => $viewId,
                'uid' => $userId,
                'pk' => 'finance_dashboard',
            ]
        );
        if ($vr && !empty($vr['filters_json'])) {
            $decoded = json_decode($vr['filters_json'], true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private $user;

    public function __construct(array $user)
    {
        $this->user = $user;
    }

    /**
     * 获取财务工作台数据（Ajax API）
     */
    public function getData(array $options): array
    {
        $viewMode = $options['viewMode'] ?? 'contract';
        $filters = $options['filters'] ?? [];
        $groupBy = $options['groupBy'] ?? [];
        $sortBy = $options['sortBy'] ?? '';
        $sortDir = $options['sortDir'] ?? 'asc';
        $page = max(1, (int)($options['page'] ?? 1));
        $perPage = min(100, max(10, (int)($options['perPage'] ?? 20)));

        $offset = ($page - 1) * $perPage;

        list($sql, $params, $countSql, $countParams) = $this->buildDataQuery($viewMode, $filters, $groupBy, $sortBy, $sortDir, $perPage, $offset);

        $rows = Db::query($sql, $params);
        $totalRow = Db::queryOne($countSql, $countParams);
        $total = (int)($totalRow['total'] ?? 0);

        $summary = [];
        $groupStats = [];

        if ($viewMode !== 'staff_summary') {
            $summary = $this->getSummary($viewMode, $filters);
            
            if (!empty($groupBy) && $groupBy[0] === 'sales_user') {
                $groupStats = $this->getGroupStats($viewMode, $filters);
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => $summary,
            'groupStats' => $groupStats
        ];
    }

    /**
     * 构建数据查询SQL
     */
    private function buildDataQuery(string $viewMode, array $filters, array $groupBy, string $sortBy, string $sortDir, int $perPage, int $offset): array
    {
        $params = [];
        $countParams = [];

        $keyword = trim((string)($filters['keyword'] ?? ''));
        $customerGroup = trim((string)($filters['customer_group'] ?? ''));
        $activityTag = trim((string)($filters['activity_tag'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $dueStart = trim((string)($filters['due_start'] ?? ''));
        $dueEnd = trim((string)($filters['due_end'] ?? ''));
        $salesUserIds = $filters['sales_user_ids'] ?? [];
        $ownerUserIds = $filters['owner_user_ids'] ?? [];

        if (!is_array($salesUserIds)) $salesUserIds = [];
        if (!is_array($ownerUserIds)) $ownerUserIds = [];

        if ($viewMode === 'contract') {
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
                SUM(i.amount_due - i.amount_paid) AS total_unpaid
            FROM finance_contracts c
            INNER JOIN customers cu ON cu.id = c.customer_id
            LEFT JOIN users u ON u.id = c.sales_user_id
            LEFT JOIN users ou ON ou.id = cu.owner_user_id
            LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
            LEFT JOIN projects p ON p.customer_id = cu.id AND p.deleted_at IS NULL
            WHERE 1=1';

            $countSql = 'SELECT COUNT(DISTINCT c.id) AS total
            FROM finance_contracts c
            INNER JOIN customers cu ON cu.id = c.customer_id
            LEFT JOIN projects p ON p.customer_id = cu.id AND p.deleted_at IS NULL
            WHERE 1=1';

            if ($this->user['role'] === 'sales') {
                $sql .= ' AND c.sales_user_id = :sales_user_id';
                $countSql .= ' AND c.sales_user_id = :sales_user_id';
                $params['sales_user_id'] = (int)$this->user['id'];
                $countParams['sales_user_id'] = (int)$this->user['id'];
            }

            if ($keyword !== '') {
                $sql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw OR p.project_code LIKE :kw OR p.project_name LIKE :kw)';
                $countSql .= ' AND (cu.name LIKE :kw OR cu.mobile LIKE :kw OR cu.customer_code LIKE :kw OR c.contract_no LIKE :kw OR p.project_code LIKE :kw OR p.project_name LIKE :kw)';
                $params['kw'] = '%' . $keyword . '%';
                $countParams['kw'] = '%' . $keyword . '%';
            }

            if ($customerGroup !== '') {
                $sql .= ' AND cu.customer_group LIKE :cg';
                $countSql .= ' AND cu.customer_group LIKE :cg';
                $params['cg'] = '%' . $customerGroup . '%';
                $countParams['cg'] = '%' . $customerGroup . '%';
            }

            if ($activityTag !== '') {
                $sql .= ' AND cu.activity_tag = :activity_tag';
                $countSql .= ' AND cu.activity_tag = :activity_tag';
                $params['activity_tag'] = $activityTag;
                $countParams['activity_tag'] = $activityTag;
            }

            if ($status !== '') {
                $sql .= ' AND c.status = :status';
                $countSql .= ' AND c.status = :status';
                $params['status'] = $status;
                $countParams['status'] = $status;
            }

            if (!empty($salesUserIds)) {
                $ps = [];
                foreach ($salesUserIds as $idx => $uid) {
                    $k = 'sales_' . $idx;
                    $ps[] = ':' . $k;
                    $params[$k] = $uid;
                    $countParams[$k] = $uid;
                }
                $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
                $countSql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
            } elseif (!empty($ownerUserIds)) {
                $ps = [];
                foreach ($ownerUserIds as $idx => $uid) {
                    $k = 'owner_' . $idx;
                    $ps[] = ':' . $k;
                    $params[$k] = $uid;
                    $countParams[$k] = $uid;
                }
                $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
                $countSql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
            }

            $sql .= ' GROUP BY c.id';
            $sql .= ' ORDER BY c.id DESC';
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        return [$sql, $params, $countSql, $countParams];
    }

    /**
     * 获取汇总统计
     */
    private function getSummary(string $viewMode, array $filters): array
    {
        $params = [];

        $keyword = trim((string)($filters['keyword'] ?? ''));
        $customerGroup = trim((string)($filters['customer_group'] ?? ''));
        $activityTag = trim((string)($filters['activity_tag'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $salesUserIds = $filters['sales_user_ids'] ?? [];
        $ownerUserIds = $filters['owner_user_ids'] ?? [];

        if (!is_array($salesUserIds)) $salesUserIds = [];
        if (!is_array($ownerUserIds)) $ownerUserIds = [];

        $sql = 'SELECT 
            COUNT(DISTINCT c.id) AS contract_count,
            COUNT(i.id) AS installment_count,
            COALESCE(SUM(i.amount_due), 0) AS sum_due,
            COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
            COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid
        FROM finance_contracts c
        INNER JOIN customers cu ON cu.id = c.customer_id
        LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
        WHERE 1=1';

        if ($this->user['role'] === 'sales') {
            $sql .= ' AND c.sales_user_id = :sales_user_id';
            $params['sales_user_id'] = (int)$this->user['id'];
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

        if (!empty($salesUserIds)) {
            $ps = [];
            foreach ($salesUserIds as $idx => $uid) {
                $k = 'sum_sales_' . $idx;
                $ps[] = ':' . $k;
                $params[$k] = $uid;
            }
            $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
        } elseif (!empty($ownerUserIds)) {
            $ps = [];
            foreach ($ownerUserIds as $idx => $uid) {
                $k = 'sum_owner_' . $idx;
                $ps[] = ':' . $k;
                $params[$k] = $uid;
            }
            $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
        }

        $row = Db::queryOne($sql, $params);
        return $row ?: ['contract_count' => 0, 'installment_count' => 0, 'sum_due' => 0, 'sum_paid' => 0, 'sum_unpaid' => 0];
    }

    /**
     * 获取分组统计
     */
    private function getGroupStats(string $viewMode, array $filters): array
    {
        $params = [];

        $keyword = trim((string)($filters['keyword'] ?? ''));
        $customerGroup = trim((string)($filters['customer_group'] ?? ''));
        $activityTag = trim((string)($filters['activity_tag'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $salesUserIds = $filters['sales_user_ids'] ?? [];
        $ownerUserIds = $filters['owner_user_ids'] ?? [];

        if (!is_array($salesUserIds)) $salesUserIds = [];
        if (!is_array($ownerUserIds)) $ownerUserIds = [];

        $sql = 'SELECT
            u.realname AS sales_name,
            COUNT(DISTINCT c.id) AS contract_count,
            COALESCE(SUM(i.amount_due), 0) AS sum_due,
            COALESCE(SUM(i.amount_paid), 0) AS sum_paid,
            COALESCE(SUM(GREATEST(i.amount_due - i.amount_paid, 0)), 0) AS sum_unpaid
        FROM finance_contracts c
        INNER JOIN customers cu ON cu.id = c.customer_id
        LEFT JOIN finance_installments i ON i.contract_id = c.id AND i.deleted_at IS NULL
        LEFT JOIN users u ON u.id = c.sales_user_id
        WHERE 1=1';

        if ($this->user['role'] === 'sales') {
            $sql .= ' AND c.sales_user_id = :sales_user_id';
            $params['sales_user_id'] = (int)$this->user['id'];
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

        if (!empty($salesUserIds)) {
            $ps = [];
            foreach ($salesUserIds as $idx => $uid) {
                $k = 'gs_sales_' . $idx;
                $ps[] = ':' . $k;
                $params[$k] = $uid;
            }
            $sql .= ' AND c.sales_user_id IN (' . implode(',', $ps) . ')';
        } elseif (!empty($ownerUserIds)) {
            $ps = [];
            foreach ($ownerUserIds as $idx => $uid) {
                $k = 'gs_owner_' . $idx;
                $ps[] = ':' . $k;
                $params[$k] = $uid;
            }
            $sql .= ' AND cu.owner_user_id IN (' . implode(',', $ps) . ')';
        }

        $sql .= ' GROUP BY u.id, u.realname';

        $rows = Db::query($sql, $params);
        $stats = [];
        foreach ($rows as $row) {
            $key = $row['sales_name'] ?: '未分配销售';
            $stats[$key] = [
                'count' => (int)$row['contract_count'],
                'sum_due' => (float)$row['sum_due'],
                'sum_paid' => (float)$row['sum_paid'],
                'sum_unpaid' => (float)$row['sum_unpaid']
            ];
        }
        return $stats;
    }
}
