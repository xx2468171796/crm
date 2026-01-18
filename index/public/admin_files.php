<?php
// 总文件管理页面
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
$fileType = $_GET['file_type'] ?? '';
$customerId = intval($_GET['customer_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// 构建SQL
$sql = 'SELECT f.*, c.name as customer_name, c.customer_code, u.realname as uploader_name
        FROM files f
        LEFT JOIN customers c ON f.customer_id = c.id
        LEFT JOIN users u ON f.uploader_user_id = u.id
        WHERE 1=1';

$params = [];

if (!empty($fileType)) {
    $sql .= ' AND f.file_type = :file_type';
    $params['file_type'] = $fileType;
}

if ($customerId > 0) {
    $sql .= ' AND f.customer_id = :customer_id';
    $params['customer_id'] = $customerId;
}

if (!empty($search)) {
    $sql .= ' AND (f.file_name LIKE :search OR c.name LIKE :search OR c.customer_code LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$sql .= ' ORDER BY f.create_time DESC';

$files = Db::query($sql, $params);

// 统计信息
$stats = Db::queryOne('SELECT 
    COUNT(*) as total_count,
    SUM(CASE WHEN file_type = "customer" THEN 1 ELSE 0 END) as customer_count,
    SUM(CASE WHEN file_type = "company" THEN 1 ELSE 0 END) as company_count,
    SUM(file_size) as total_size
    FROM files');

layout_header('总文件管理');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3>📁 总文件管理</h3>
        <div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fileManagerModal">
                <i class="bi bi-folder2-open"></i> 独立文件管理
            </button>
        </div>
    </div>

    <!-- 独立文件管理入口模态框 -->
    <div class="modal fade" id="fileManagerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">跳转到独立文件管理</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="fileManagerForm" method="GET" action="file_manager.php">
                        <div class="mb-3">
                            <label class="form-label">客户ID</label>
                            <input type="text" class="form-control" name="customer_id" id="customerInput" placeholder="请输入客户ID（数字）" required>
                            <small class="form-text text-muted">输入客户ID（数字），可在"我的客户"页面查看</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="goToFileManager()">跳转</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function goToFileManager() {
        const input = document.getElementById('customerInput').value.trim();
        if (!input) {
            alert('请输入客户ID');
            return;
        }
        
        // 如果是纯数字，直接作为customer_id
        if (/^\d+$/.test(input)) {
            window.location.href = 'file_manager.php?customer_id=' + input;
        } else {
            alert('请输入数字格式的客户ID');
        }
    }
    </script>

    <!-- 统计信息 -->
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5>总文件数</h5>
                    <h2><?= $stats['total_count'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5>客户文件</h5>
                    <h2><?= $stats['customer_count'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5>公司文件</h5>
                    <h2><?= $stats['company_count'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5>总大小</h5>
                    <h2><?= formatFileSize($stats['total_size']) ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="admin_files">
                
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="搜索文件名/客户" value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-2">
                    <select class="form-select" name="file_type">
                        <option value="">全部类型</option>
                        <option value="customer" <?= $fileType === 'customer' ? 'selected' : '' ?>>客户文件</option>
                        <option value="company" <?= $fileType === 'company' ? 'selected' : '' ?>>公司文件</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="index.php?page=admin_files" class="btn btn-secondary">重置</a>
                </div>
                
                <div class="col-md-5 text-end">
                    <span class="text-muted">共 <?= count($files) ?> 个文件</span>
                </div>
            </form>
        </div>
    </div>

    <!-- 文件列表 -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>文件名</th>
                            <th>类型</th>
                            <th>所属客户</th>
                            <th>大小</th>
                            <th>上传人</th>
                            <th>上传时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($files)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">暂无文件</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($files as $file): ?>
                        <tr>
                            <td>
                                <i class="bi bi-file-earmark"></i>
                                <?= htmlspecialchars($file['file_name']) ?>
                            </td>
                            <td>
                                <?php if ($file['file_type'] === 'customer'): ?>
                                    <span class="badge bg-info">客户文件</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">公司文件</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="index.php?page=customer_detail&id=<?= $file['customer_id'] ?>">
                                    <?= htmlspecialchars($file['customer_name']) ?>
                                    <small class="text-muted">(<?= $file['customer_code'] ?>)</small>
                                </a>
                            </td>
                            <td><?= formatFileSize($file['file_size']) ?></td>
                            <td><?= htmlspecialchars($file['uploader_name']) ?></td>
                            <td><?= date('Y-m-d H:i', $file['create_time']) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/uploads/<?= $file['file_path'] ?>" class="btn btn-sm btn-outline-primary" download>
                                    下载
                                </a>
                                <a href="file_manager.php?customer_id=<?= $file['customer_id'] ?>" class="btn btn-sm btn-outline-info" title="独立文件管理">
                                    📁 文件管理
                                </a>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteFile(<?= $file['id'] ?>)">
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
</div>

<script>
// 删除文件
function deleteFile(fileId) {
    showConfirmModal('删除文件', '确定要删除这个文件吗？<strong>删除后无法恢复！</strong>', function() {
        fetch(apiUrl('file_delete.php'), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'file_id=' + fileId
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showAlertModal(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlertModal(data.message, 'error');
            }
        });
    });
}
</script>

<?php 
// 文件大小格式化函数
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

layout_footer(); 
?>
