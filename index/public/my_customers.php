<?php
// 我的客户列表页面

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

layout_header('我的客户');

$user = current_user();

$search = trim($_GET['search'] ?? '');
$timeFilter = trim($_GET['time_filter'] ?? ''); // 快捷时间筛选
$startDate = trim($_GET['start_date'] ?? ''); // 开始日期
$endDate = trim($_GET['end_date'] ?? ''); // 结束日期
$filterFields = $_GET['ff'] ?? []; // 自定义筛选字段 ff[field_id]=option_id
$sortField = trim($_GET['sort'] ?? 'create_time'); // 排序字段
$sortOrder = strtoupper(trim($_GET['order'] ?? 'DESC')); // 排序方向

// 验证排序参数
$allowedSortFields = ['create_time', 'update_time', 'name', 'customer_code'];
$sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'create_time';
$sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

// 加载自定义筛选字段（使用两次查询避免GROUP_CONCAT问题）
$customFilterFields = [];
try {
    // 先获取字段列表
    $customFilterFields = Db::query("
        SELECT * FROM customer_filter_fields 
        WHERE is_active = 1 
        ORDER BY sort_order ASC, id ASC
    ");
    
    // 再获取所有选项
    $allOptions = Db::query("
        SELECT * FROM customer_filter_options 
        WHERE is_active = 1 
        ORDER BY sort_order ASC, id ASC
    ");
    
    // 按field_id分组选项
    $optionsByField = [];
    foreach ($allOptions as $opt) {
        $fid = $opt['field_id'];
        if (!isset($optionsByField[$fid])) {
            $optionsByField[$fid] = [];
        }
        $optionsByField[$fid][] = [
            'id' => $opt['id'],
            'value' => $opt['option_value'],
            'label' => $opt['option_label'],
            'color' => $opt['color']
        ];
    }
    
    // 合并到字段数据
    foreach ($customFilterFields as &$field) {
        $field['options'] = $optionsByField[$field['id']] ?? [];
    }
    unset($field);
} catch (Exception $e) {
    // 表可能不存在，忽略错误
}

// 分页参数（使用p避免与路由page冲突）
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50])) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

// 构建查询条件
$where = '1=1';
$params = [];

// 权限过滤
if ($user['role'] === 'sales' || $user['role'] === 'service') {
    // 销售和客服只能看自己的客户
    $where .= ' AND c.create_user_id = :create_user_id';
    $params['create_user_id'] = $user['id'];
}
// 管理员不加限制，可以看所有客户

// 过滤已删除客户（默认不显示已删除客户）
$where .= ' AND c.deleted_at IS NULL';

// 搜索条件（支持项目编号、客户群搜索）
if ($search !== '') {
    $where .= ' AND (c.name LIKE :search OR c.mobile LIKE :search OR c.customer_code LIKE :search OR c.custom_id LIKE :search OR c.customer_group LIKE :search OR EXISTS (SELECT 1 FROM projects p WHERE p.customer_id = c.id AND p.deleted_at IS NULL AND (p.project_code LIKE :search OR p.project_name LIKE :search)))';
    $params['search'] = '%' . $search . '%';
}

// 快捷时间筛选
if ($timeFilter !== '' && $startDate === '' && $endDate === '') {
    $now = time();
    $startTime = 0;
    
    switch ($timeFilter) {
        case 'today':
            $startTime = strtotime('today');
            $endTime = strtotime('tomorrow') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'yesterday':
            $startTime = strtotime('yesterday');
            $endTime = strtotime('today') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'day_before':
            $startTime = strtotime('-2 days', strtotime('today'));
            $endTime = strtotime('yesterday') - 1;
            $where .= ' AND c.create_time BETWEEN :time_start AND :time_end';
            $params['time_start'] = $startTime;
            $params['time_end'] = $endTime;
            break;
        case 'week':
            $startTime = strtotime('-7 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
        case 'two_weeks':
            $startTime = strtotime('-14 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
        case 'month':
            $startTime = strtotime('-30 days');
            $where .= ' AND c.create_time >= :time_start';
            $params['time_start'] = $startTime;
            break;
    }
}

// 自定义筛选字段过滤
if (!empty($filterFields) && is_array($filterFields)) {
    foreach ($filterFields as $fieldId => $optionId) {
        $fieldId = intval($fieldId);
        $optionId = intval($optionId);
        if ($fieldId > 0 && $optionId > 0) {
            $paramKey = "ff_{$fieldId}";
            $where .= " AND EXISTS (
                SELECT 1 FROM customer_filter_values cfv 
                WHERE cfv.customer_id = c.id 
                AND cfv.field_id = {$fieldId} 
                AND cfv.option_id = :{$paramKey}
            )";
            $params[$paramKey] = $optionId;
        }
    }
}

// 自定义日期范围筛选（优先级高于快捷筛选）
if ($startDate !== '' && $endDate !== '') {
    $startTime = strtotime($startDate . ' 00:00:00');
    $endTime = strtotime($endDate . ' 23:59:59');
    $where .= ' AND c.create_time BETWEEN :date_start AND :date_end';
    $params['date_start'] = $startTime;
    $params['date_end'] = $endTime;
} elseif ($startDate !== '') {
    $startTime = strtotime($startDate . ' 00:00:00');
    $where .= ' AND c.create_time >= :date_start';
    $params['date_start'] = $startTime;
} elseif ($endDate !== '') {
    $endTime = strtotime($endDate . ' 23:59:59');
    $where .= ' AND c.create_time <= :date_end';
    $params['date_end'] = $endTime;
}

// 先查询总数
$countSql = "SELECT COUNT(*) as total FROM customers c WHERE {$where}";
$totalResult = Db::queryOne($countSql, $params);
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

// 查询客户列表
$sql = "SELECT 
    c.*,
    u.realname as owner_name,
    fc.next_follow_time,
    (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND category = 'client_material' AND deleted_at IS NULL) as customer_file_count,
    (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND category = 'internal_solution' AND deleted_at IS NULL) as company_file_count,
    cl.enabled as link_enabled
FROM customers c
LEFT JOIN users u ON c.create_user_id = u.id
LEFT JOIN first_contact fc ON c.id = fc.customer_id
LEFT JOIN customer_links cl ON c.id = cl.customer_id
WHERE {$where}
ORDER BY c.{$sortField} {$sortOrder}
LIMIT {$perPage} OFFSET {$offset}";

$customers = Db::query($sql, $params);

// 加载客户的筛选字段值（用于看板分组显示）
$customerIds = array_column($customers, 'id');
$filterValuesMap = [];
if (!empty($customerIds)) {
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $filterValues = Db::query("
        SELECT cfv.customer_id, cfv.field_id, cfv.option_id, 
               cfo.option_label, cfo.color
        FROM customer_filter_values cfv
        JOIN customer_filter_options cfo ON cfv.option_id = cfo.id
        WHERE cfv.customer_id IN ({$placeholders})
    ", $customerIds);
    
    foreach ($filterValues as $fv) {
        if (!isset($filterValuesMap[$fv['customer_id']])) {
            $filterValuesMap[$fv['customer_id']] = [];
        }
        $filterValuesMap[$fv['customer_id']][$fv['field_id']] = [
            'option_id' => $fv['option_id'],
            'label' => $fv['option_label'],
            'color' => $fv['color']
        ];
    }
}

// 将筛选字段值附加到客户数据
foreach ($customers as &$customer) {
    $customer['filter_values'] = [];
    $customer['filter_values_display'] = [];
    if (isset($filterValuesMap[$customer['id']])) {
        foreach ($filterValuesMap[$customer['id']] as $fieldId => $val) {
            $customer['filter_values'][$fieldId] = $val['option_id'];
            $customer['filter_values_display'][$fieldId] = $val;
        }
    }
}
unset($customer);

// 转换为JSON供JS使用
$customersJson = json_encode($customers, JSON_UNESCAPED_UNICODE);
$customFilterFieldsJson = json_encode($customFilterFields, JSON_UNESCAPED_UNICODE);
?>

<!-- 看板样式 -->
<link rel="stylesheet" href="css/customer-kanban.css?v=1.0">

<style>
.search-bar {
    background: #f8f9fa;
    padding: 12px 15px;
    border-radius: 4px;
    margin-bottom: 15px;
    border: 1px solid #dee2e6;
}
.customer-table {
    background: #fff;
    border: 1px solid #dee2e6;
    overflow-x: auto;
}
.customer-table th {
    background: #f8f9fa;
    font-weight: 600;
    padding: 10px 8px;
    border-bottom: 2px solid #dee2e6;
    font-size: 15px;
    text-align: center;
    white-space: nowrap;
}
.customer-table th.sortable {
    cursor: pointer;
    user-select: none;
    transition: background 0.15s;
}
.customer-table th.sortable:hover {
    background: #e9ecef;
}
.customer-table th.sorted {
    background: #e3f2fd;
    color: #1976d2;
}
.customer-table td {
    padding: 8px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    text-align: center;
    font-size: 14px;
}
.customer-table tr:hover {
    background: #f8f9fa;
}
.customer-table td:nth-child(3) {
    text-align: left;
}
.customer-table code {
    font-size: 13px;
    color: #d63384;
}
.batch-action-bar {
    position: sticky;
    top: 0;
    z-index: 100;
}
.customer-table tr.selected {
    background-color: #e7f3ff !important;
}
.customer-checkbox {
    cursor: pointer;
}
</style>

<!-- 搜索栏 -->
<div class="search-bar">
    <form method="get" class="row g-2 align-items-center" id="searchForm">
        <input type="hidden" name="page" value="my_customers">
        
        <!-- 快捷时间筛选 -->
        <div class="col-auto">
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                    快捷时间筛选
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('today')">今天</a></li>
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('yesterday')">昨天</a></li>
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('day_before')">前天</a></li>
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('week')">一周内</a></li>
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('two_weeks')">2周内</a></li>
                    <li><a class="dropdown-item" href="#" onclick="setTimeFilter('month')">一个月内</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#" onclick="clearTimeFilter()">清除筛选</a></li>
                </ul>
            </div>
            <input type="hidden" name="time_filter" id="timeFilterInput" value="<?= htmlspecialchars($timeFilter) ?>">
        </div>
        
        <!-- 根据时间筛选 -->
        <div class="col-auto">
            <label class="form-label" style="font-size: 12px; margin-bottom: 2px;">开始日期</label>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>" style="width: 150px;">
        </div>
        <div class="col-auto d-flex align-items-end" style="padding-bottom: 8px;">
            <span>至</span>
        </div>
        <div class="col-auto">
            <label class="form-label" style="font-size: 12px; margin-bottom: 2px;">结束日期</label>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>" style="width: 150px;">
        </div>
        
        <!-- 搜索框 -->
        <div class="col-md-3">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="搜索客户/手机/项目编号/客户群" value="<?= htmlspecialchars($search) ?>">
        </div>
        
        <?php if (!empty($customFilterFields)): ?>
        <?php foreach ($customFilterFields as $field): ?>
        <div class="col-auto">
            <select name="ff[<?= $field['id'] ?>]" class="form-select form-select-sm" style="min-width: 100px;" onchange="this.form.submit()">
                <option value="">全部<?= htmlspecialchars($field['field_label']) ?></option>
                <?php foreach ($field['options'] as $opt): ?>
                <option value="<?= $opt['id'] ?>" <?= (isset($filterFields[$field['id']]) && $filterFields[$field['id']] == $opt['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($opt['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="index.php?page=my_customers" class="btn btn-outline-secondary btn-sm">重置</a>
            <a href="index.php?page=customer_detail" class="btn btn-success btn-sm">+ 新增客户</a>
        </div>
        
        <!-- 视图切换 -->
        <div class="col-auto ms-auto">
            <div class="view-toggle">
                <button type="button" id="btnKanbanView" class="active" onclick="CustomerKanban.switchView('kanban')">
                    <i class="bi bi-kanban"></i> 看板
                </button>
                <button type="button" id="btnTableView" onclick="CustomerKanban.switchView('table')">
                    <i class="bi bi-table"></i> 表格
                </button>
            </div>
        </div>
    </form>
    
    <!-- 当前筛选条件提示 -->
    <?php 
    $hasFilterFields = !empty(array_filter($filterFields, fn($v) => !empty($v)));
    if ($timeFilter || $startDate || $endDate || $search || $hasFilterFields): 
    ?>
    <div class="mt-2">
        <small class="text-muted">
            当前筛选: 
            <?php if ($timeFilter): ?>
                <span class="badge bg-info">
                    <?php 
                    $filterNames = [
                        'today' => '今天',
                        'yesterday' => '昨天',
                        'day_before' => '前天',
                        'week' => '一周内',
                        'two_weeks' => '2周内',
                        'month' => '一个月内'
                    ];
                    echo $filterNames[$timeFilter] ?? $timeFilter;
                    ?>
                </span>
            <?php endif; ?>
            <?php if ($startDate || $endDate): ?>
                <span class="badge bg-info">
                    <?= $startDate ? date('Y-m-d', strtotime($startDate)) : '...' ?> 
                    至 
                    <?= $endDate ? date('Y-m-d', strtotime($endDate)) : '...' ?>
                </span>
            <?php endif; ?>
            <?php if ($search): ?>
                <span class="badge bg-info">搜索: <?= htmlspecialchars($search) ?></span>
            <?php endif; ?>
            <?php 
            // 显示自定义筛选字段标签
            if ($hasFilterFields): 
                foreach ($filterFields as $fid => $oid):
                    if (empty($oid)) continue;
                    foreach ($customFilterFields as $cf):
                        if ($cf['id'] == $fid):
                            foreach ($cf['options'] as $opt):
                                if ($opt['id'] == $oid):
            ?>
                <span class="badge" style="background: <?= htmlspecialchars($opt['color']) ?>">
                    <?= htmlspecialchars($cf['field_label']) ?>: <?= htmlspecialchars($opt['label']) ?>
                </span>
            <?php 
                                endif;
                            endforeach;
                        endif;
                    endforeach;
                endforeach;
            endif; 
            ?>
        </small>
    </div>
    <?php endif; ?>
</div>

<!-- 分页和每页显示数量 -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <!-- 每页显示数量 -->
    <div>
        <span class="text-muted">共 <?= $total ?> 条记录，每页显示</span>
        <select class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="changePerPage(this.value)">
            <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
            <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20</option>
            <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
        </select>
        <span class="text-muted">条</span>
    </div>
    
    <!-- 分页导航 -->
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <!-- 首页 -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl(1) ?>">首页</a>
            </li>
            
            <!-- 上一页 -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($page - 1) ?>">上一页</a>
            </li>
            
            <!-- 页码 -->
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif;
            
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= buildPageUrl($i) ?>"><?= $i ?></a>
                </li>
            <?php endfor;
            
            if ($endPage < $totalPages): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            
            <!-- 下一页 -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($page + 1) ?>">下一页</a>
            </li>
            
            <!-- 末页 -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($totalPages) ?>">末页</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- 看板分组选择器 -->
<div class="d-flex align-items-center mb-2" id="kanbanFieldSelectorWrapper">
    <div id="kanbanFieldSelector" class="d-flex align-items-center"></div>
</div>

<!-- 看板视图 -->
<div class="customer-kanban-container" id="customerKanbanContainer"></div>

<!-- 表格视图容器（默认显示） -->
<div class="customer-table-view active" id="customerTableContainer">

<!-- 批量操作工具栏 -->
<div id="batchActionBar" class="batch-action-bar" style="display: none; background: #fff; padding: 12px 15px; border: 1px solid #dee2e6; border-bottom: none; border-radius: 4px 4px 0 0;">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <span class="text-muted">已选择 <strong id="selectedCount">0</strong> 个客户</span>
            <button type="button" class="btn btn-sm btn-link text-primary ms-2" onclick="clearSelection()">取消全选</button>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-danger" onclick="batchDeleteCustomers()">
                <i class="bi bi-trash"></i> 批量删除
            </button>
        </div>
    </div>
</div>

<!-- 列配置按钮 -->
<div class="d-flex justify-content-end mb-2">
    <div id="columnToggleContainer"></div>
</div>

<!-- 客户列表 -->
<div class="customer-table">
    <table class="table table-hover mb-0" id="customerTable">
        <thead>
            <tr>
                <th style="width: 50px;">
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this.checked)">
                </th>
                <th style="width: 80px;" class="sortable <?= $sortField === 'customer_code' ? 'sorted' : '' ?>" onclick="sortBy('customer_code')">
                    日/生成ID <?= $sortField === 'customer_code' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                </th>
                <th style="width: 80px;">填写ID</th>
                <th style="width: 120px;" class="sortable <?= $sortField === 'name' ? 'sorted' : '' ?>" onclick="sortBy('name')">
                    客户姓名 <?= $sortField === 'name' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                </th>
                <th style="width: 80px;">客户群</th>
                <th style="width: 70px;">分享链接</th>
                <th style="width: 70px;">链接启用</th>
                <th style="width: 70px;">链接停用</th>
                <th style="width: 70px;">客户详情</th>
                <th style="width: 70px;">客户文件</th>
                <th style="width: 80px;">我们的文件</th>
                <th style="width: 100px;" class="sortable <?= $sortField === 'update_time' ? 'sorted' : '' ?>" onclick="sortBy('update_time')">
                    更新时间 <?= $sortField === 'update_time' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                </th>
                <th style="width: 100px;" class="sortable <?= $sortField === 'create_time' ? 'sorted' : '' ?>" onclick="sortBy('create_time')">
                    创建时间 <?= $sortField === 'create_time' ? ($sortOrder === 'ASC' ? '↑' : '↓') : '' ?>
                </th>
                <th style="width: 120px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="14" class="text-center text-muted py-4">
                        暂无客户数据
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($customers as $index => $customer): ?>
                <tr data-customer-id="<?= $customer['id'] ?>">
                    <td>
                        <input type="checkbox" class="customer-checkbox" value="<?= $customer['id'] ?>" 
                               data-customer-name="<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>"
                               onchange="updateSelectionState()">
                    </td>
                    <td><code><?= htmlspecialchars($customer['customer_code']) ?></code></td>
                    <td><?= htmlspecialchars($customer['custom_id'] ?? '-') ?></td>
                    <td style="text-align: left;"><strong><?= htmlspecialchars($customer['name']) ?></strong></td>
                    <td><?= htmlspecialchars($customer['customer_group'] ?? '') ?></td>
                    <td>
                        <?php if ($customer['link_enabled'] !== null): ?>
                            <button class="btn btn-sm btn-info" onclick="copyLink('<?= $customer['customer_code'] ?>')">复制链接</button>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($customer['link_enabled'] !== null): ?>
                            <?php if ($customer['link_enabled']): ?>
                                <button class="btn btn-sm btn-success" disabled>已启用</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-success" onclick="toggleLink(<?= $customer['id'] ?>, 1)">启用</button>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($customer['link_enabled'] !== null): ?>
                            <?php if (!$customer['link_enabled']): ?>
                                <button class="btn btn-sm btn-secondary" disabled>停用</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleLink(<?= $customer['id'] ?>, 0)">停用</button>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" onclick="openCustomerSidebar(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">查看</button>
                        <a href="index.php?page=customer_detail&id=<?= $customer['id'] ?>" class="btn btn-sm btn-primary">编辑</a>
                    </td>
                    <td>
                        <a href="file_manager.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-info">
                            📁 <?= $customer['customer_file_count'] ?> 个
                        </a>
                    </td>
                    <td>
                        <a href="file_manager.php?customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-success">
                            📁 <?= $customer['company_file_count'] ?> 个
                        </a>
                    </td>
                    <td style="font-size: 13px;"><?= date('Y-m-d H:i', $customer['update_time']) ?></td>
                    <td style="font-size: 13px;"><?= date('Y-m-d H:i', $customer['create_time']) ?></td>
                    <td>
                        <a href="javascript:void(0)" class="text-primary small" onclick="openTransferCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">转移</a>
                        <button class="btn btn-sm btn-danger" onclick="deleteCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">
                            删除
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 分页和每页显示数量 -->
<div class="d-flex justify-content-between align-items-center mt-3">
    <!-- 每页显示数量 -->
    <div>
        <span class="text-muted">共 <?= $total ?> 条记录，每页显示</span>
        <select class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="changePerPage(this.value)">
            <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
            <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20</option>
            <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
        </select>
        <span class="text-muted">条</span>
    </div>
    
    <!-- 分页导航 -->
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <!-- 首页 -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl(1) ?>">首页</a>
            </li>
            
            <!-- 上一页 -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($page - 1) ?>">上一页</a>
            </li>
            
            <!-- 页码 -->
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif;
            
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= buildPageUrl($i) ?>"><?= $i ?></a>
                </li>
            <?php endfor;
            
            if ($endPage < $totalPages): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            
            <!-- 下一页 -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($page + 1) ?>">下一页</a>
            </li>
            
            <!-- 末页 -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= buildPageUrl($totalPages) ?>">末页</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

</div><!-- 关闭表格视图容器 -->

<?php
// 构建分页URL的辅助函数
function buildPageUrl($pageNum) {
    $params = $_GET;
    $params['p'] = $pageNum; // 使用p作为页码参数，避免与路由page冲突
    return 'index.php?' . http_build_query($params);
}
?>

<script>
// 设置快捷时间筛选
function setTimeFilter(filter) {
    document.getElementById('timeFilterInput').value = filter;
    document.getElementById('searchForm').submit();
    return false;
}

// 清除时间筛选
function clearTimeFilter() {
    document.getElementById('timeFilterInput').value = '';
    document.getElementById('searchForm').submit();
    return false;
}

// 改变每页显示数量
function changePerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('p', '1'); // 重置到第一页
    window.location.href = url.toString();
}

// 排序
function sortBy(field) {
    const url = new URL(window.location.href);
    const currentSort = url.searchParams.get('sort') || 'create_time';
    const currentOrder = url.searchParams.get('order') || 'DESC';
    
    if (currentSort === field) {
        // 同一字段，切换排序方向
        url.searchParams.set('order', currentOrder === 'ASC' ? 'DESC' : 'ASC');
    } else {
        // 新字段，默认降序
        url.searchParams.set('sort', field);
        url.searchParams.set('order', 'DESC');
    }
    url.searchParams.set('p', '1'); // 重置到第一页
    window.location.href = url.toString();
}

// 复制链接 - 显示多区域链接弹窗
function copyLink(customerCode) {
    fetch('/api/customer_link.php?action=get_region_urls&customer_code=' + encodeURIComponent(customerCode))
        .then(res => res.json())
        .then(data => {
            if (data.success && data.regions && data.regions.length > 0) {
                let html = '<div style="text-align:left;">';
                data.regions.forEach((r, idx) => {
                    html += `<div class="input-group mb-2">
                        <span class="input-group-text" style="min-width:80px;font-size:12px;">${r.is_default ? '⭐' : ''} ${r.region_name}</span>
                        <input type="text" class="form-control" id="regionLink_${idx}" value="${r.url}" readonly style="font-size:12px;">
                        <button class="btn btn-outline-primary btn-sm" onclick="copyRegionLinkInput('regionLink_${idx}')">复制</button>
                    </div>`;
                });
                html += '</div>';
                showAlertModal(html, 'info');
            } else {
                const shareUrl = BASE_URL + '/share.php?code=' + customerCode;
                const tempInput = document.createElement('input');
                tempInput.value = shareUrl;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                showAlertModal('链接已复制到剪贴板:<br><code>' + shareUrl + '</code>', 'success');
            }
        })
        .catch(err => {
            const shareUrl = BASE_URL + '/share.php?code=' + customerCode;
            showAlertModal('加载失败，默认链接:<br><code>' + shareUrl + '</code>', 'warning');
        });
}

// 复制指定输入框的链接
function copyRegionLinkInput(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        input.select();
        document.execCommand('copy');
        showAlertModal('链接已复制到剪贴板！', 'success');
    }
}

// 启用/停用链接
function toggleLink(customerId, enable) {
    showConfirmModal(
        enable ? '确定要启用此客户的分享链接吗？' : '确定要停用此客户的分享链接吗？',
        function() {
            $.ajax({
                url: '/api/customer_link.php',
                type: 'POST',
                data: {
                    action: 'toggle',
                    customer_id: customerId
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        // 清除权限相关缓存
                        if (data.version && data.cache_key) {
                            // 清除sessionStorage中的权限缓存
                            var cachePrefix = data.cache_key;
                            Object.keys(sessionStorage).forEach(function(key) {
                                if (key.startsWith(cachePrefix)) {
                                    sessionStorage.removeItem(key);
                                }
                            });
                            // 清除localStorage中的权限缓存
                            Object.keys(localStorage).forEach(function(key) {
                                if (key.startsWith(cachePrefix)) {
                                    localStorage.removeItem(key);
                                }
                            });
                            // 存储新的版本号
                            sessionStorage.setItem('link_permission_version_' + customerId, data.version);
                        }
                        showAlertModal(data.message, 'success');
                        // 2秒后刷新页面
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        showAlertModal('操作失败: ' + data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr, status, error);
                    showAlertModal('网络错误，请稍后重试', 'error');
                }
            });
        }
    );
}

// 全选/取消全选
function toggleSelectAll(checked) {
    const checkboxes = document.querySelectorAll('.customer-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checked;
    });
    updateSelectionState();
}

// 更新选择状态
function updateSelectionState() {
    const checkboxes = document.querySelectorAll('.customer-checkbox:checked');
    const selectedCount = checkboxes.length;
    const selectedCountEl = document.getElementById('selectedCount');
    const batchActionBar = document.getElementById('batchActionBar');
    const selectAllCheckbox = document.getElementById('selectAll');
    
    // 更新选中数量
    if (selectedCountEl) {
        selectedCountEl.textContent = selectedCount;
    }
    
    // 显示/隐藏批量操作工具栏
    if (batchActionBar) {
        if (selectedCount > 0) {
            batchActionBar.style.display = 'block';
        } else {
            batchActionBar.style.display = 'none';
        }
    }
    
    // 更新全选复选框状态
    if (selectAllCheckbox) {
        const totalCheckboxes = document.querySelectorAll('.customer-checkbox').length;
        selectAllCheckbox.checked = selectedCount > 0 && selectedCount === totalCheckboxes;
        selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
    }
    
    // 更新行样式
    const rows = document.querySelectorAll('tbody tr[data-customer-id]');
    rows.forEach(row => {
        const checkbox = row.querySelector('.customer-checkbox');
        if (checkbox && checkbox.checked) {
            row.classList.add('selected');
        } else {
            row.classList.remove('selected');
        }
    });
}

// 清除选择
function clearSelection() {
    const checkboxes = document.querySelectorAll('.customer-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
    });
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
    updateSelectionState();
}

// 批量删除客户
function batchDeleteCustomers() {
    const checkboxes = document.querySelectorAll('.customer-checkbox:checked');
    if (checkboxes.length === 0) {
        showAlertModal('请先选择要删除的客户', 'warning');
        return;
    }
    
    const customerIds = [];
    const customerNames = [];
    checkboxes.forEach(cb => {
        customerIds.push(cb.value);
        customerNames.push(cb.dataset.customerName || '');
    });
    
    const customerList = customerNames.slice(0, 5).join('、');
    const moreText = customerIds.length > 5 ? ' 等 ' + customerIds.length + ' 个客户' : '';
    
    showConfirmModal(
        '确认批量删除',
        '确定要删除以下客户吗？<br><strong>' + customerList + moreText + '</strong><br><span class="text-danger">⚠️ 此操作不可恢复，将删除这些客户的所有相关数据（首通、异议、成交、文件等）！</span>',
        function() {
            // 显示加载提示
            showAlertModal('正在删除，请稍候...', 'info');
            
            $.ajax({
                url: API_URL + '/customer_delete.php',
                type: 'POST',
                data: {
                    customer_ids: customerIds.join(',')
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showAlertModal(data.message || '成功删除 ' + customerIds.length + ' 个客户', 'success');
                        // 1.5秒后刷新页面
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlertModal('删除失败: ' + (data.message || '未知错误'), 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr, status, error);
                    console.error('Response:', xhr.responseText);
                    showAlertModal('网络错误，请稍后重试', 'error');
                }
            });
        }
    );
}

// ========== 转移客户 ==========
function openTransferCustomer(customerId, customerName) {
    // 获取用户列表
    fetch(API_URL + '/users.php')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                showAlertModal('获取用户列表失败', 'error');
                return;
            }
            const users = res.data || [];
            const options = users.map(u => `<option value="${u.id}">${u.realname || u.username}</option>`).join('');
            
            const modalHtml = `
                <div class="modal fade" id="transferCustomerModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">转移客户 - ${customerName}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">转移给</label>
                                    <select class="form-select" id="transferToUserId">
                                        <option value="">请选择</option>
                                        ${options}
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" onclick="submitTransferCustomer(${customerId})">确认转移</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('transferCustomerModal')?.remove();
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('transferCustomerModal'));
            modal.show();
        })
        .catch(e => {
            console.error(e);
            showAlertModal('获取用户列表失败', 'error');
        });
}

function submitTransferCustomer(customerId) {
    const userId = document.getElementById('transferToUserId').value;
    if (!userId) {
        showAlertModal('请选择要转移给的用户', 'warning');
        return;
    }
    
    const fd = new FormData();
    fd.append('customer_id', customerId);
    fd.append('owner_user_id', userId);
    
    fetch(API_URL + '/customer_transfer.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showAlertModal('转移成功', 'success');
                bootstrap.Modal.getInstance(document.getElementById('transferCustomerModal'))?.hide();
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showAlertModal(res.message || '转移失败', 'error');
            }
        })
        .catch(e => {
            console.error(e);
            showAlertModal('转移失败', 'error');
        });
}

// ========== 快速分配技术 ==========
function openTechAssign(customerId, customerName) {
    // 先获取技术人员列表和当前分配情况
    Promise.all([
        fetch(API_URL + '/users.php?role=tech').then(r => r.json()),
        fetch(API_URL + '/customer_tech_assign.php?action=get&customer_id=' + customerId).then(r => r.json())
    ])
    .then(([techResponse, assignResponse]) => {
        if (!techResponse.success) {
            showAlertModal('获取技术人员列表失败', 'error');
            return;
        }
        
        const techUsers = techResponse.data || [];
        const assigned = assignResponse.success ? (assignResponse.data?.assignments || []) : [];
        const assignedIds = assigned.map(a => a.tech_user_id);
        
        let techListHtml = '';
        if (techUsers.length === 0) {
            techListHtml = '<p class="text-muted">暂无技术人员</p>';
        } else {
            techUsers.forEach(tech => {
                const isAssigned = assignedIds.includes(tech.id);
                techListHtml += `
                    <div class="form-check mb-2">
                        <input class="form-check-input tech-checkbox" type="checkbox" 
                               value="${tech.id}" id="tech_${tech.id}" ${isAssigned ? 'checked' : ''}>
                        <label class="form-check-label" for="tech_${tech.id}">
                            ${tech.realname || tech.username}
                            ${isAssigned ? '<span class="badge bg-success ms-2">已分配</span>' : ''}
                        </label>
                    </div>
                `;
            });
        }
        
        const modalHtml = `
            <div class="modal fade" id="techAssignModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">👨‍💻 分配技术 - ${customerName}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">选择要分配给此客户的技术人员：</p>
                            ${techListHtml}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-primary" onclick="saveTechAssign(${customerId})">保存</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // 移除旧模态框
        const oldModal = document.getElementById('techAssignModal');
        if (oldModal) oldModal.remove();
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        new bootstrap.Modal(document.getElementById('techAssignModal')).show();
    })
    .catch(err => {
        showAlertModal('加载失败: ' + err.message, 'error');
    });
}

function saveTechAssign(customerId) {
    const checkboxes = document.querySelectorAll('#techAssignModal .tech-checkbox:checked');
    const techUserIds = Array.from(checkboxes).map(cb => cb.value);
    
    fetch(API_URL + '/customer_tech_assign.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'sync',
            customer_id: customerId,
            tech_user_ids: techUserIds
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlertModal('分配成功', 'success');
            bootstrap.Modal.getInstance(document.getElementById('techAssignModal')).hide();
        } else {
            showAlertModal('分配失败: ' + (data.message || '未知错误'), 'error');
        }
    })
    .catch(err => {
        showAlertModal('保存失败: ' + err.message, 'error');
    });
}

// 删除客户（单个）
function deleteCustomer(customerId, customerName) {
    showConfirmModal(
        '确认删除',
        '确定要删除客户 "' + customerName + '" 吗？<br><span class="text-danger">⚠️ 此操作不可恢复，将删除该客户的所有相关数据（首通、异议、成交、文件等）！</span>',
        function() {
            $.ajax({
                url: API_URL + '/customer_delete.php',
                type: 'POST',
                data: {
                    customer_id: customerId
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        showAlertModal(data.message, 'success');
                        // 1.5秒后刷新页面
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlertModal('删除失败: ' + data.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', xhr, status, error);
                    console.error('Response:', xhr.responseText);
                    showAlertModal('网络错误，请稍后重试', 'error');
                }
            });
        }
    );
}
</script>

<!-- 侧边栏组件 -->
<link rel="stylesheet" href="css/sidebar-panel.css?v=1.2">
<script src="js/sidebar-panel.js?v=1.0"></script>
<!-- 看板组件 -->
<script src="js/customer-kanban.js?v=1.1"></script>
<!-- 列配置组件 -->
<script src="js/column-toggle.js?v=1.0"></script>
<script>
let customerSidebar = null;

document.addEventListener('DOMContentLoaded', function() {
    customerSidebar = initSidebarPanel({
        title: '客户详情',
        icon: 'bi-person',
        openPageText: '打开客户详情页'
    });
    
    // 初始化看板组件
    const customersData = <?= $customersJson ?>;
    CustomerKanban.init({
        customers: customersData,
        containerId: 'customerKanbanContainer',
        tableContainerId: 'customerTableContainer',
        onCustomerClick: function(customerId, customerName) {
            openCustomerSidebar(customerId, customerName);
        },
        onCopyLink: function(customerCode) {
            copyLink(customerCode);
        },
        onDelete: function(customerId, customerName) {
            deleteCustomer(customerId, customerName);
        }
    });
    
    // 初始化表格列配置
    initColumnToggle({
        tableId: 'customerTable',
        storageKey: 'my_customers_columns',
        columns: [
            { index: 0, name: '选择', default: true },
            { index: 1, name: '日/生成ID', default: true },
            { index: 2, name: '填写ID', default: false },
            { index: 3, name: '客户姓名', default: true },
            { index: 4, name: '客户群', default: true },
            { index: 5, name: '分享链接', default: true },
            { index: 6, name: '链接启用', default: false },
            { index: 7, name: '链接停用', default: false },
            { index: 8, name: '客户详情', default: false },
            { index: 9, name: '客户文件', default: true },
            { index: 10, name: '我们的文件', default: true },
            { index: 11, name: '更新时间', default: true },
            { index: 12, name: '创建时间', default: false },
            { index: 13, name: '操作', default: true }
        ],
        buttonContainer: '#columnToggleContainer'
    });
});

function openCustomerSidebar(customerId, customerName) {
    customerSidebar.open({
        title: customerName || '客户详情',
        pageUrl: 'index.php?page=customer_detail&id=' + customerId,
        loadContent: function(panel) {
            loadCustomerDetail(customerId, panel);
        }
    });
}

async function loadCustomerDetail(customerId, panel) {
    try {
        const res = await fetch(API_URL + '/customers.php?id=' + customerId);
        const data = await res.json();
        
        if (!data.success) {
            panel.showError(data.message || '加载失败');
            return;
        }
        
        const customer = data.data;
        let html = '';
        
        // 基本信息
        html += createSidebarSection('基本信息', createSidebarInfoGrid([
            { label: '客户编号', value: customer.customer_code || '-' },
            { label: '客户名称', value: customer.name || '-' },
            { label: '手机号码', value: customer.mobile || '-' },
            { label: '客户类型', value: customer.customer_type || '-' },
            { label: '创建时间', value: customer.create_time ? new Date(customer.create_time * 1000).toLocaleString() : '-', fullWidth: true },
            { label: '地址', value: customer.address || '-', fullWidth: true }
        ]));
        
        // 联系信息
        if (customer.wechat || customer.email) {
            html += createSidebarSection('联系方式', createSidebarInfoGrid([
                { label: '微信', value: customer.wechat || '-' },
                { label: '邮箱', value: customer.email || '-' }
            ]));
        }
        
        // 备注
        if (customer.remark) {
            html += createSidebarSection('备注', `<div class="sidebar-info-item full-width"><div class="sidebar-info-value">${customer.remark}</div></div>`);
        }
        
        // 加载项目列表
        try {
            const projectRes = await fetch(API_URL + '/projects.php?customer_id=' + customerId);
            const projectData = await projectRes.json();
            
            if (projectData.success && projectData.data && projectData.data.length > 0) {
                const projectItems = projectData.data.map(p => ({
                    title: p.project_name,
                    subtitle: p.current_status || '未开始',
                    icon: 'bi-folder',
                    iconBg: p.current_status === '已完成' ? '#10b981' : '#6366f1',
                    onClick: `window.location.href='index.php?page=project_detail&id=${p.id}'`
                }));
                html += createSidebarSection(`项目列表 (${projectData.data.length})`, createSidebarList(projectItems));
            } else {
                html += createSidebarSection('项目列表', '<div class="sidebar-empty"><i class="bi bi-inbox"></i><span>暂无项目</span></div>');
            }
        } catch (e) {
            console.error('[SIDEBAR_DEBUG] 加载项目失败:', e);
        }
        
        // 快速操作按钮
        const hasLink = customer.link_enabled !== null;
        const linkEnabled = customer.link_enabled === 1 || customer.link_enabled === '1';
        
        html += `
            <div class="sidebar-section">
                <div class="sidebar-section-title">快速操作</div>
                <div class="sidebar-actions">
                    <button class="sidebar-action-btn" onclick="window.location.href='index.php?page=customer_detail&id=${customerId}'">
                        <i class="bi bi-pencil"></i> 编辑客户
                    </button>
                    <button class="sidebar-action-btn" onclick="window.location.href='file_manager.php?customer_id=${customerId}'">
                        <i class="bi bi-folder"></i> 文件管理
                    </button>
                    <button class="sidebar-action-btn" onclick="openTransferCustomer(${customerId}, '${customer.name?.replace(/'/g, "\\'")}')">
                        <i class="bi bi-arrow-right-circle"></i> 转移客户
                    </button>
                    ${hasLink ? `
                        <button class="sidebar-action-btn" onclick="copyLink('${customer.customer_code}')">
                            <i class="bi bi-link-45deg"></i> 复制链接
                        </button>
                        <button class="sidebar-action-btn" onclick="toggleLink(${customerId}, ${linkEnabled ? 0 : 1}); customerSidebar.close();">
                            <i class="bi bi-${linkEnabled ? 'pause' : 'play'}"></i> ${linkEnabled ? '停用链接' : '启用链接'}
                        </button>
                    ` : ''}
                    <button class="sidebar-action-btn sidebar-action-btn-danger" onclick="deleteCustomer(${customerId}, '${customer.name?.replace(/'/g, "\\'")}'); customerSidebar.close();">
                        <i class="bi bi-trash"></i> 删除客户
                    </button>
                </div>
            </div>
        `;
        
        panel.setContent(html);
        
    } catch (e) {
        console.error('[SIDEBAR_DEBUG] 加载客户详情失败:', e);
        panel.showError('加载失败: ' + e.message);
    }
}
</script>

<?php
layout_footer();
