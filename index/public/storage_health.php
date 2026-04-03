<?php

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../services/StorageDiagnostics.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    layout_header('无权访问');
    echo '<div class="alert alert-danger">仅管理员可查看存储诊断结果。</div>';
    layout_footer();
    exit;
}

$health = StorageDiagnostics::run();

layout_header('对象存储健康检查');
?>

<div class="row">
    <div class="col-lg-10 col-xl-8">
        <div class="card mb-4 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1">对象存储连通性</h5>
                    <small class="text-muted">
                        驱动: <?= htmlspecialchars($health['driver']) ?>
                        ・ 最近检测: <?= htmlspecialchars($health['timestamp']) ?>
                    </small>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php?page=storage_health" class="btn btn-outline-secondary btn-sm">重新检测</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($health['status'] === 'ok'): ?>
                    <div class="alert alert-success mb-4">
                        ✅ 存储连接正常，可继续进行上传/下载等操作。
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger mb-4">
                        ⚠️ 检测失败：<?= htmlspecialchars($health['error'] ?? '存在未通过的检测项，请查看下方详细信息。') ?>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                        <tr>
                            <th style="width: 20%;">检测项</th>
                            <th style="width: 10%;">状态</th>
                            <th>说明</th>
                            <th style="width: 15%;">耗时(ms)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($health['tests'] as $test): ?>
                            <tr>
                                <td><?= htmlspecialchars($test['name']) ?></td>
                                <td>
                                    <?php if ($test['status'] === 'pass'): ?>
                                        <span class="badge bg-success">PASS</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">FAIL</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($test['details']) ?>
                                    <?php if (!empty($test['extra'])): ?>
                                        <div class="text-muted small mt-1">
                                            <?= htmlspecialchars(json_encode($test['extra'], JSON_UNESCAPED_UNICODE)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= isset($test['duration_ms']) ? number_format($test['duration_ms'], 2) : '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (isset($health['trace'])): ?>
                    <details class="mt-3">
                        <summary class="text-danger">查看异常堆栈</summary>
                        <pre class="mt-2 bg-light p-3 small"><?= htmlspecialchars($health['trace']) ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <div class="alert alert-info">
            <strong>提示：</strong>
            <ul class="mb-0">
                <?php if ($health['driver'] === 's3'): ?>
                    <li>若"对象写入"失败，请检查对象存储服务（MinIO / S3）是否可达（防火墙、AK/SK、bucket、endpoint）以及 Nginx 是否允许上传大文件。</li>
                    <li><strong>HTTP 403 错误排查：</strong>
                        <ul>
                            <li>检查 Access Key 和 Secret Key 是否正确</li>
                            <li>确认 MinIO/S3 用户具有 bucket 的读写权限</li>
                            <li>检查 bucket 策略是否允许 PUT 操作</li>
                            <li>对于 MinIO，建议在配置中设置 <code>use_path_style=1</code>（通过环境变量 <code>S3_USE_PATH_STYLE=1</code>）</li>
                            <li>确认 bucket 名称正确且已存在</li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li>若"对象写入"失败，请检查存储配置和文件系统权限。</li>
                <?php endif; ?>
                <li>通过本页确认连通性后，再回到客户详情页重新尝试上传。</li>
            </ul>
        </div>
    </div>
</div>

<?php
layout_footer();

