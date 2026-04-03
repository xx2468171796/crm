<?php
// 总客户管理页面
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('customer_view') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取筛选条件
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$assignedTo = intval($_GET['assigned_to'] ?? 0);
$filterFields = $_GET['ff'] ?? []; // 自定义筛选字段 ff[field_id]=option_id

// 加载自定义筛选字段
$customFilterFields = [];
try {
    $customFilterFields = Db::query("
        SELECT f.*, GROUP_CONCAT(
            CONCAT(o.id, ':', o.option_value, ':', o.option_label, ':', o.color) 
            ORDER BY o.sort_order SEPARATOR '|'
        ) as options_str
        FROM customer_filter_fields f
        LEFT JOIN customer_filter_options o ON f.id = o.field_id AND o.is_active = 1
        WHERE f.is_active = 1
        GROUP BY f.id
        ORDER BY f.sort_order
    ");
    foreach ($customFilterFields as &$field) {
        $field['options'] = [];
        if (!empty($field['options_str'])) {
            foreach (explode('|', $field['options_str']) as $optStr) {
                $parts = explode(':', $optStr);
                if (count($parts) >= 4) {
                    $field['options'][] = [
                        'id' => $parts[0],
                        'value' => $parts[1],
                        'label' => $parts[2],
                        'color' => $parts[3]
                    ];
                }
            }
        }
        unset($field['options_str']);
    }
} catch (Exception $e) {
    // 表可能不存在，忽略错误
}

// 分页参数
$page = max(1, intval($_GET['page_num'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50])) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

// 构建SQL
$sql = 'SELECT c.*, u.realname as owner_name,
        (SELECT COUNT(*) FROM files WHERE customer_id = c.id AND file_type = "customer") as customer_file_count,
        (SELECT COUNT(*) FROM files WHERE customer_id = c.id AND file_type = "company") as company_file_count
        FROM customers c
        LEFT JOIN users u ON c.create_user_id = u.id
        WHERE 1=1';

$params = [];

if (!empty($search)) {
    $sql .= ' AND (c.name LIKE :search OR c.mobile LIKE :search OR c.customer_code LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

// 自定义筛选字段过滤
if (!empty($filterFields) && is_array($filterFields)) {
    foreach ($filterFields as $fieldId => $optionId) {
        $fieldId = intval($fieldId);
        $optionId = intval($optionId);
        if ($fieldId > 0 && $optionId > 0) {
            $paramKey = "ff_{$fieldId}";
            $sql .= " AND EXISTS (
                SELECT 1 FROM customer_filter_values cfv 
                WHERE cfv.customer_id = c.id 
                AND cfv.field_id = {$fieldId} 
                AND cfv.option_id = :{$paramKey}
            )";
            $params[$paramKey] = $optionId;
        }
    }
}

if ($assignedTo > 0) {
    $sql .= ' AND c.create_user_id = :assigned_to';
    $params['assigned_to'] = $assignedTo;
}

// 过滤已删除客户（默认不显示已删除客户）
$sql .= ' AND c.deleted_at IS NULL';

// 先查询总数
$countSql = str_replace('SELECT c.*, u.realname as owner_name,
        (SELECT COUNT(*) FROM files WHERE customer_id = c.id AND file_type = "customer") as customer_file_count,
        (SELECT COUNT(*) FROM files WHERE customer_id = c.id AND file_type = "company") as company_file_count', 'SELECT COUNT(*) as total', $sql);
$totalResult = Db::queryOne($countSql, $params);
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

$sql .= ' ORDER BY c.create_time DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

$customers = Db::query($sql, $params);

// 获取所有员工用于筛选
$users = Db::query('SELECT id, realname FROM users WHERE status = 1 ORDER BY realname');

layout_header('总客户管理');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>👥 总客户管理</h3>
        <div>
            <button class="btn btn-success" onclick="exportCustomers()">
                <i class="bi bi-download"></i> 导出Excel
            </button>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="admin_customers">
                
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="搜索姓名/手机/编号" value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <?php if (!empty($customFilterFields)): ?>
                <?php foreach ($customFilterFields as $field): ?>
                <div class="col-md-2">
                    <select name="ff[<?= $field['id'] ?>]" class="form-select" onchange="this.form.submit()">
                        <option value=""><?= htmlspecialchars($field['field_label']) ?></option>
                        <?php foreach ($field['options'] as $opt): ?>
                        <option value="<?= $opt['id'] ?>" <?= (isset($filterFields[$field['id']]) && $filterFields[$field['id']] == $opt['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <select class="form-select" name="assigned_to">
                        <option value="0">全部员工</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $assignedTo == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['realname']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="index.php?page=admin_customers" class="btn btn-secondary">重置</a>
                </div>
                
                <div class="col-md-3 text-end">
                    <span class="text-muted">共 <?= $total ?> 条记录</span>
                </div>
            </form>
        </div>
    </div>

    <!-- 客户列表 -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>客户编号</th>
                            <th>姓名</th>
                            <th>手机</th>
                            <th>状态</th>
                            <th>创建人(归属)</th>
                            <th>文件数</th>
                            <th>创建时间</th>
                            <th>最后更新</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">暂无数据</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($customer['customer_code']) ?></code></td>
                            <td><?= htmlspecialchars($customer['name']) ?></td>
                            <td><?= htmlspecialchars($customer['mobile']) ?></td>
                            <td>
                                <?php
                                $statusMap = [
                                    0 => '<span class="badge bg-secondary">待跟进</span>',
                                    1 => '<span class="badge bg-primary">跟进中</span>',
                                    2 => '<span class="badge bg-success">已成交</span>',
                                    3 => '<span class="badge bg-danger">已放弃</span>',
                                ];
                                echo $statusMap[$customer['status']] ?? '';
                                ?>
                            </td>
                            <td><?= htmlspecialchars($customer['owner_name']) ?></td>
                            <td>
                                <span class="badge bg-info"><?= $customer['customer_file_count'] ?></span>
                                <span class="badge bg-warning"><?= $customer['company_file_count'] ?></span>
                            </td>
                            <td><?= date('Y-m-d H:i', $customer['create_time']) ?></td>
                            <td><?= date('Y-m-d H:i', $customer['update_time']) ?></td>
                            <td>
                                <a href="index.php?page=customer_detail&id=<?= $customer['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    查看
                                </a>
                                <button class="btn btn-sm btn-outline-warning" onclick="transferCustomer(<?= $customer['id'] ?>)">
                                    转移
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')">
                                    删除
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- 分页和每页显示数量 -->
    <div class="d-flex justify-content-between align-items-center mt-3">
        <!-- 每页显示数量 -->
        <div>
            <span class="text-muted">每页显示</span>
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
                    <a class="page-link" href="<?= buildAdminPageUrl(1) ?>">首页</a>
                </li>
                
                <!-- 上一页 -->
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildAdminPageUrl($page - 1) ?>">上一页</a>
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
                        <a class="page-link" href="<?= buildAdminPageUrl($i) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                
                if ($endPage < $totalPages): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                
                <!-- 下一页 -->
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildAdminPageUrl($page + 1) ?>">下一页</a>
                </li>
                
                <!-- 末页 -->
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildAdminPageUrl($totalPages) ?>">末页</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php
// 构建分页URL的辅助函数
function buildAdminPageUrl($pageNum) {
    $params = $_GET;
    $params['page_num'] = $pageNum;
    return 'index.php?' . http_build_query($params);
}
?>

<!-- 转移客户弹窗 -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">转移客户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="transferCustomerId">
                <div class="mb-3">
                    <label class="form-label">转移给</label>
                    <select class="form-select" id="transferToUserId">
                        <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['realname']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmTransfer()">确认转移</button>
            </div>
        </div>
    </div>
</div>

<script>
let transferModal;

document.addEventListener('DOMContentLoaded', function() {
    transferModal = new bootstrap.Modal(document.getElementById('transferModal'));
});

// 改变每页显示数量
function changePerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page_num', '1'); // 重置到第一页
    window.location.href = url.toString();
}

// 转移客户
function transferCustomer(customerId) {
    document.getElementById('transferCustomerId').value = customerId;
    transferModal.show();
}

// 确认转移
function confirmTransfer() {
    const customerId = document.getElementById('transferCustomerId').value;
    const toUserId = document.getElementById('transferToUserId').value;
    
    fetch(apiUrl('customer_transfer.php'), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `customer_id=${customerId}&to_user_id=${toUserId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlertModal(data.message, 'success');
            transferModal.hide();
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlertModal(data.message, 'error');
        }
    });
}

// 删除客户
function deleteCustomer(customerId, customerName) {
    showConfirmModal(
        '确认删除',
        '确定要删除客户 "' + customerName + '" 吗？<br><span class="text-danger">⚠️ 此操作不可恢复，将删除该客户的所有相关数据（首通、异议、成交、文件等）！</span>',
        function() {
            fetch(apiUrl('customer_delete.php'), {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'customer_id=' + customerId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlertModal(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showAlertModal(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                showAlertModal('删除失败，请稍后重试', 'error');
            });
        }
    );
}

// 导出Excel
function exportCustomers() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', '1');
    window.location.href = apiUrl('customer_export.php?' + params.toString());
}
</script>

<?php layout_footer(); ?>
