<?php
// 简单仪表盘示例

layout_header('仪表盘');
$user = current_user();
$folderUploads = [];
if (($user['role'] ?? '') === 'admin') {
    $folderUploads = Db::query(
        'SELECT cl.*, c.name AS customer_name, u.realname AS actor_name
         FROM customer_logs cl
         LEFT JOIN customers c ON c.id = cl.customer_id
         LEFT JOIN users u ON u.id = cl.actor_id
         WHERE cl.action = :action
         ORDER BY cl.created_at DESC
         LIMIT 5',
        ['action' => 'folder_upload']
    );
}
?>
<div class="row">
    <div class="col-12">
        <div class="alert alert-success">
            欢迎回来, <?= htmlspecialchars($user['name'] ?? $user['username']) ?>!
        </div>
    </div>
</div>
<?php if (($user['role'] ?? '') === 'admin'): ?>
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>📂 最近文件夹导入</span>
                <small class="text-muted">最多展示 5 条</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>客户</th>
                            <th>子目录</th>
                            <th>文件数</th>
                            <th>总大小</th>
                            <th>操作人</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($folderUploads)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">暂无文件夹导入记录</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($folderUploads as $log):
                                $extra = json_decode($log['extra'] ?? '[]', true) ?: [];
                                $folderPath = $extra['folder_path'] ?? '';
                                $fileCount = $extra['file_count'] ?? '-';
                                $totalBytes = isset($extra['total_bytes']) ? formatFolderBytes((int)$extra['total_bytes']) : '-';
                            ?>
                                <tr>
                                    <td><?= date('Y-m-d H:i', $log['created_at']) ?></td>
                                    <td>
                                        <?php if (!empty($log['customer_id'])): ?>
                                            <a href="index.php?page=customer_detail&id=<?= (int)$log['customer_id'] ?>">
                                                <?= htmlspecialchars($log['customer_name'] ?? ('客户#' . $log['customer_id'])) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($folderPath ?: '根目录') ?></td>
                                    <td><?= htmlspecialchars((string)$fileCount) ?></td>
                                    <td><?= htmlspecialchars($totalBytes) ?></td>
                                    <td><?= htmlspecialchars($log['actor_name'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
layout_footer();

function formatFolderBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
