<?php
/**
 * 后台个人网盘管理页面
 */
require_once __DIR__ . '/../core/layout.php';

$pdo = Db::pdo();

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_limit':
            $driveId = intval($_POST['drive_id'] ?? 0);
            $limitGb = floatval($_POST['limit_gb'] ?? 50);
            $limitBytes = $limitGb * 1024 * 1024 * 1024;
            
            $stmt = $pdo->prepare("UPDATE personal_drives SET storage_limit = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$limitBytes, time(), $driveId]);
            echo json_encode(['success' => true]);
            exit;
            
        case 'delete_file':
            $fileId = intval($_POST['file_id'] ?? 0);
            
            $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($file) {
                try {
                    require_once __DIR__ . '/../core/storage/storage_provider.php';
                    $config = require __DIR__ . '/../config/storage.php';
                    $s3 = new S3StorageProvider($config['s3'] ?? [], []);
                    $s3->deleteObject($file['storage_key']);
                } catch (Exception $e) {
                    error_log("删除S3文件失败: " . $e->getMessage());
                }
                
                $stmt = $pdo->prepare("UPDATE personal_drives SET used_storage = used_storage - ? WHERE id = ?");
                $stmt->execute([$file['file_size'], $file['drive_id']]);
                
                $stmt = $pdo->prepare("DELETE FROM drive_files WHERE id = ?");
                $stmt->execute([$fileId]);
            }
            echo json_encode(['success' => true]);
            exit;
            
        case 'disable_drive':
            $driveId = intval($_POST['drive_id'] ?? 0);
            $status = $_POST['status'] ?? 'disabled';
            $stmt = $pdo->prepare("UPDATE personal_drives SET status = ?, update_time = ? WHERE id = ?");
            $stmt->execute([$status, time(), $driveId]);
            echo json_encode(['success' => true]);
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => '未知操作']);
    exit;
}

// 获取所有用户网盘
$stmt = $pdo->query("
    SELECT pd.*, u.name as user_name, u.username, d.name as dept_name,
           (SELECT COUNT(*) FROM drive_files WHERE drive_id = pd.id) as file_count
    FROM personal_drives pd
    JOIN users u ON u.id = pd.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    ORDER BY pd.used_storage DESC
");
$drives = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 选中的网盘
$selectedDriveId = intval($_GET['drive_id'] ?? 0);
$selectedDriveFiles = [];
if ($selectedDriveId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM drive_files WHERE drive_id = ? ORDER BY create_time DESC");
    $stmt->execute([$selectedDriveId]);
    $selectedDriveFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

renderHeader('个人网盘管理');
?>

<style>
    .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .drive-card { background: white; border-radius: 10px; padding: 15px; margin-bottom: 10px; border: 1px solid #e5e7eb; cursor: pointer; transition: all 0.2s; }
    .drive-card:hover { border-color: #3b82f6; box-shadow: 0 2px 8px rgba(59,130,246,0.15); }
    .drive-card.active { border-color: #3b82f6; background: #eff6ff; }
    .progress-sm { height: 6px; }
    .file-item { padding: 12px 15px; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; gap: 12px; }
    .file-item:hover { background: #f9fafb; }
    .file-icon { font-size: 1.5rem; }
</style>

<div class="container-fluid py-4">
    <h4 class="mb-4"><i class="bi bi-hdd-stack me-2"></i>个人网盘管理</h4>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="text-muted small">总用户数</div>
                <div class="h3 mb-0"><?php echo count($drives); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="text-muted small">总文件数</div>
                <div class="h3 mb-0"><?php echo array_sum(array_column($drives, 'file_count')); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="text-muted small">总存储使用</div>
                <div class="h3 mb-0"><?php echo round(array_sum(array_column($drives, 'used_storage')) / 1024 / 1024 / 1024, 2); ?> GB</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="text-muted small">总配额</div>
                <div class="h3 mb-0"><?php echo round(array_sum(array_column($drives, 'storage_limit')) / 1024 / 1024 / 1024, 2); ?> GB</div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-people me-2"></i>用户网盘列表</div>
                <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($drives as $drive): 
                        $percent = $drive['storage_limit'] > 0 ? round($drive['used_storage'] / $drive['storage_limit'] * 100, 1) : 0;
                        $usedGb = round($drive['used_storage'] / 1024 / 1024 / 1024, 2);
                        $limitGb = round($drive['storage_limit'] / 1024 / 1024 / 1024, 2);
                    ?>
                    <div class="drive-card <?php echo $selectedDriveId == $drive['id'] ? 'active' : ''; ?>" 
                         onclick="location.href='index.php?page=admin_personal_drives&drive_id=<?php echo $drive['id']; ?>'">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <strong><?php echo htmlspecialchars($drive['user_name'] ?? $drive['username']); ?></strong>
                                <div class="text-muted small"><?php echo htmlspecialchars($drive['dept_name'] ?? '未分配部门'); ?></div>
                            </div>
                            <span class="badge <?php echo $drive['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $drive['status'] === 'active' ? '正常' : '已禁用'; ?>
                            </span>
                        </div>
                        <div class="progress progress-sm mb-2">
                            <div class="progress-bar <?php echo $percent > 90 ? 'bg-danger' : ($percent > 70 ? 'bg-warning' : 'bg-primary'); ?>" 
                                 style="width: <?php echo $percent; ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between text-muted small">
                            <span><?php echo $usedGb; ?> / <?php echo $limitGb; ?> GB</span>
                            <span><?php echo $drive['file_count']; ?> 个文件</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($drives)): ?>
                    <div class="text-center text-muted py-5">暂无网盘数据</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-7">
            <?php if ($selectedDriveId > 0): 
                $selectedDrive = array_filter($drives, fn($d) => $d['id'] == $selectedDriveId);
                $selectedDrive = reset($selectedDrive);
            ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder me-2"></i><?php echo htmlspecialchars($selectedDrive['user_name'] ?? $selectedDrive['username']); ?> 的网盘</span>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="showLimitModal(<?php echo $selectedDriveId; ?>, <?php echo round($selectedDrive['storage_limit'] / 1024 / 1024 / 1024, 2); ?>)">
                            <i class="bi bi-gear"></i> 设置容量
                        </button>
                        <button class="btn btn-sm btn-outline-<?php echo $selectedDrive['status'] === 'active' ? 'danger' : 'success'; ?>" 
                                onclick="toggleDriveStatus(<?php echo $selectedDriveId; ?>, '<?php echo $selectedDrive['status'] === 'active' ? 'disabled' : 'active'; ?>')">
                            <?php echo $selectedDrive['status'] === 'active' ? '禁用' : '启用'; ?>
                        </button>
                    </div>
                </div>
                <div class="card-body" style="max-height: 550px; overflow-y: auto;">
                    <?php foreach ($selectedDriveFiles as $file): ?>
                    <div class="file-item">
                        <div class="file-icon">📄</div>
                        <div class="flex-grow-1">
                            <div class="fw-medium"><?php echo htmlspecialchars($file['filename']); ?></div>
                            <div class="text-muted small">
                                <?php echo round($file['file_size'] / 1024 / 1024, 2); ?> MB · 
                                <?php echo date('Y-m-d H:i', $file['create_time']); ?>
                                <?php if ($file['upload_source'] === 'share'): ?>
                                <span class="badge bg-success-subtle text-success">分享上传</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteFile(<?php echo $file['id']; ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($selectedDriveFiles)): ?>
                    <div class="text-center text-muted py-5">暂无文件</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-arrow-left-circle" style="font-size: 3rem;"></i>
                    <div class="mt-3">选择左侧用户查看网盘详情</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 容量设置弹窗 -->
<div class="modal fade" id="limitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">设置存储容量</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">存储上限 (GB)</label>
                    <input type="number" class="form-control" id="limitInput" min="1" max="1000" step="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveLimit()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentDriveId = null;
    let limitModal = null;
    
    document.addEventListener('DOMContentLoaded', function() {
        limitModal = new bootstrap.Modal(document.getElementById('limitModal'));
    });
    
    function showLimitModal(driveId, currentLimit) {
        currentDriveId = driveId;
        document.getElementById('limitInput').value = currentLimit;
        limitModal.show();
    }
    
    function saveLimit() {
        const limitGb = document.getElementById('limitInput').value;
        fetch('index.php?page=admin_personal_drives', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_limit&drive_id=${currentDriveId}&limit_gb=${limitGb}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('操作失败');
            }
        });
    }
    
    function deleteFile(fileId) {
        if (!confirm('确定要删除此文件吗？用户将无法看到此文件。')) return;
        
        fetch('index.php?page=admin_personal_drives', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_file&file_id=${fileId}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('删除失败');
            }
        });
    }
    
    function toggleDriveStatus(driveId, status) {
        if (!confirm(status === 'disabled' ? '确定要禁用此网盘吗？' : '确定要启用此网盘吗？')) return;
        
        fetch('index.php?page=admin_personal_drives', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=disable_drive&drive_id=${driveId}&status=${status}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('操作失败');
            }
        });
    }
</script>

<?php renderFooter(); ?>
