<?php
/**
 * 管理员查看已删除客户页面
 * 权限：仅系统管理员可访问
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

// 需要登录
auth_require();
$currentUser = current_user();

// 使用 RBAC 检查权限
if (!can('customer_delete') && !RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取筛选条件
$search = trim($_GET['search'] ?? '');
$deletedStart = $_GET['deleted_start'] ?? '';
$deletedEnd = $_GET['deleted_end'] ?? '';

// 分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50])) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

// 构建SQL - 只查询已删除的客户
$sql = 'SELECT c.*, u.realname as owner_name, 
        du.realname as deleted_by_name,
        (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND deleted_at IS NOT NULL) as deleted_file_count
        FROM customers c
        LEFT JOIN users u ON c.create_user_id = u.id
        LEFT JOIN users du ON c.deleted_by = du.id
        WHERE c.deleted_at IS NOT NULL';

$params = [];

if (!empty($search)) {
    $sql .= ' AND (c.name LIKE :search OR c.mobile LIKE :search OR c.customer_code LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if (!empty($deletedStart)) {
    $sql .= ' AND c.deleted_at >= :deleted_start';
    $params['deleted_start'] = strtotime($deletedStart);
}

if (!empty($deletedEnd)) {
    $sql .= ' AND c.deleted_at <= :deleted_end';
    $params['deleted_end'] = strtotime($deletedEnd . ' 23:59:59');
}

// 先查询总数
$countSql = str_replace('SELECT c.*, u.realname as owner_name, 
        du.realname as deleted_by_name,
        (SELECT COUNT(*) FROM customer_files WHERE customer_id = c.id AND deleted_at IS NOT NULL) as deleted_file_count', 
        'SELECT COUNT(*) as total', $sql);
$totalResult = Db::queryOne($countSql, $params);
$total = $totalResult['total'] ?? 0;
$totalPages = ceil($total / $perPage);

$sql .= ' ORDER BY c.deleted_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

$customers = Db::query($sql, $params);

layout_header('已删除客户管理');
?>

<style>
.customer-table {
    background: #fff;
    border: 1px solid #dee2e6;
}
.customer-table th {
    background: #f8f9fa;
    font-weight: 600;
    padding: 12px 8px;
    border-bottom: 2px solid #dee2e6;
    font-size: 14px;
}
.customer-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
    font-size: 13px;
}
.customer-table tr:hover {
    background: #f8f9fa;
}
.badge-deleted {
    background-color: #dc3545;
    color: white;
}
.text-muted-small {
    font-size: 12px;
    color: #6c757d;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="bi bi-trash text-danger"></i> 已删除客户管理
            </h2>
            <p class="text-muted mb-0">查看和管理已删除的客户（15天保留期）</p>
        </div>
        <div>
            <a href="index.php?page=admin_customers" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> 返回客户管理
            </a>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="admin_deleted_customers">
                
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="搜索姓名/手机/编号" value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-2">
                    <input type="date" class="form-control" name="deleted_start" placeholder="删除开始日期" value="<?= htmlspecialchars($deletedStart) ?>">
                </div>
                
                <div class="col-md-2">
                    <input type="date" class="form-control" name="deleted_end" placeholder="删除结束日期" value="<?= htmlspecialchars($deletedEnd) ?>">
                </div>
                
                <div class="col-md-2">
                    <select class="form-select" name="per_page">
                        <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>每页10条</option>
                        <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>每页20条</option>
                        <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>每页50条</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="index.php?page=admin_deleted_customers" class="btn btn-secondary">重置</a>
                </div>
            </form>
        </div>
    </div>

    <!-- 客户列表 -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover customer-table mb-0">
                    <thead>
                        <tr>
                            <th>客户ID</th>
                            <th>客户编号</th>
                            <th>姓名</th>
                            <th>手机</th>
                            <th>状态</th>
                            <th>创建人(归属)</th>
                            <th>已删除文件数</th>
                            <th>删除时间</th>
                            <th>删除人</th>
                            <th style="width: 200px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">暂无已删除客户</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <?php
                        $deletedAt = $customer['deleted_at'] ? date('Y-m-d H:i:s', $customer['deleted_at']) : '-';
                        $daysSinceDeleted = $customer['deleted_at'] ? floor((time() - $customer['deleted_at']) / 86400) : 0;
                        $remainingDays = max(0, 15 - $daysSinceDeleted);
                        ?>
                        <tr>
                            <td><?= $customer['id'] ?></td>
                            <td><code><?= htmlspecialchars($customer['customer_code'] ?? '-') ?></code></td>
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
                            <td><?= htmlspecialchars($customer['owner_name'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-warning"><?= $customer['deleted_file_count'] ?></span>
                            </td>
                            <td>
                                <div><?= $deletedAt ?></div>
                                <div class="text-muted-small">
                                    <?php if ($remainingDays > 0): ?>
                                        剩余 <?= $remainingDays ?> 天
                                    <?php else: ?>
                                        <span class="text-danger">已过期</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($customer['deleted_by_name'] ?? '-') ?></td>
                            <td>
                                <button class="btn btn-sm btn-success" onclick="restoreCustomer(<?= $customer['id'] ?>, '<?= htmlspecialchars($customer['name'], ENT_QUOTES) ?>')" title="恢复客户">
                                    <i class="bi bi-arrow-counterclockwise"></i> 恢复
                                </button>
                                <a href="index.php?page=admin_deleted_customer_files&customer_id=<?= $customer['id'] ?>" class="btn btn-sm btn-info" title="查看文件">
                                    <i class="bi bi-folder"></i> 文件
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- 分页 -->
    <?php if ($totalPages > 1): ?>
    <nav aria-label="分页导航" class="mt-3">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=admin_deleted_customers&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&deleted_start=<?= urlencode($deletedStart) ?>&deleted_end=<?= urlencode($deletedEnd) ?>&per_page=<?= $perPage ?>">上一页</a>
            </li>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1):
            ?>
            <li class="page-item"><a class="page-link" href="?page=admin_deleted_customers&page=1&search=<?= urlencode($search) ?>&deleted_start=<?= urlencode($deletedStart) ?>&deleted_end=<?= urlencode($deletedEnd) ?>&per_page=<?= $perPage ?>">1</a></li>
            <?php if ($startPage > 2): ?>
            <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=admin_deleted_customers&page=<?= $i ?>&search=<?= urlencode($search) ?>&deleted_start=<?= urlencode($deletedStart) ?>&deleted_end=<?= urlencode($deletedEnd) ?>&per_page=<?= $perPage ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
            <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" href="?page=admin_deleted_customers&page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&deleted_start=<?= urlencode($deletedStart) ?>&deleted_end=<?= urlencode($deletedEnd) ?>&per_page=<?= $perPage ?>"><?= $totalPages ?></a></li>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?page=admin_deleted_customers&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&deleted_start=<?= urlencode($deletedStart) ?>&deleted_end=<?= urlencode($deletedEnd) ?>&per_page=<?= $perPage ?>">下一页</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
    
    <div class="text-center mt-3 text-muted">
        共 <?= $total ?> 条记录，第 <?= $page ?> / <?= $totalPages ?> 页
    </div>
</div>

<script>
// API_URL 已在 layout.php 中全局声明，直接使用即可

// 恢复客户
function restoreCustomer(customerId, customerName) {
    showConfirmModal('恢复客户', `确定要恢复客户 "${customerName}" 吗？恢复后该客户及其文件将重新可见。`, function() {
        const formData = new FormData();
        formData.append('customer_id', customerId);
        
        fetch(`${API_URL}/customer_restore.php`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlertModal(`客户恢复成功！已恢复 ${data.data.files_restored} 个文件。`, 'success');
                location.reload();
            } else {
                showAlertModal('恢复失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlertModal('恢复失败，请稍后重试', 'error');
        });
    });
}
</script>

<?php
layout_footer();
?>

