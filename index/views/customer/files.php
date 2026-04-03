<?php
$customerId = $customer['id'] ?? $customerId ?? 0;
$canManageFiles = !$isReadonly;

$customerMeta = [
    '客户姓名' => $customer['name'] ?? '-',
    '客户ID'   => $customer['id'] ?? '-',
    '身份'     => $customer['identity'] ?? ($customer['identity_card'] ?? '-'),
    '性别'     => $customer['gender'] ?? '-',
    '年龄'     => $customer['age'] ?? '-',
    '客户需求' => $customer['needs'] ?? ($customer['demand'] ?? '-'),
    '客户关键词' => $customer['keywords'] ?? ($customer['tags'] ?? '-'),
];
$folderUploadConfig = $folderUploadConfig ?? [];
// 如果没有传入 storage config，则从全局获取
if (!isset($storageConfig)) {
    require_once __DIR__ . '/../core/storage/storage_provider.php';
    $storageConfig = storage_config();
}
$folderLimits = [
    'max_files' => (int)($folderUploadConfig['max_files'] ?? 500),
    'max_total_bytes' => (int)($folderUploadConfig['max_total_bytes'] ?? (2 * 1024 * 1024 * 1024)),
    'max_depth' => (int)($folderUploadConfig['max_depth'] ?? 5),
    'max_segment_length' => (int)($folderUploadConfig['max_segment_length'] ?? 40),
];
$maxSingleSize = (int)($storageConfig['limits']['max_single_size'] ?? (2 * 1024 * 1024 * 1024));
$maxTotalHintValue = $folderLimits['max_total_bytes'] >= 1073741824
    ? round($folderLimits['max_total_bytes'] / 1073741824, 1) . ' GB'
    : round($folderLimits['max_total_bytes'] / 1048576, 1) . ' MB';
$folderLimitHint = sprintf('%d 个文件 / %s', $folderLimits['max_files'], $maxTotalHintValue);
?>

<style>
.customer-files-layout {
    display: flex;
    flex-direction: column;
    gap: 24px;
    padding: 20px 0 32px;
    width: 100%;
    max-width: none;
    margin: 0;
}
.files-columns {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
}
.file-column {
    background: #fff;
    border: 1px solid #e6ecf5;
    border-radius: 24px;
    padding: 28px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    box-shadow: 0 26px 55px rgba(15, 23, 42, 0.06);
}
.file-column-header {
    font-size: 22px;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 4px;
}
.upload-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.upload-actions .btn {
    padding: 8px 18px;
    font-size: 13px;
}
.folder-support-tip {
    font-size: 12px;
    color: #dc2626;
}
.upload-dropzone {
    border: 1px dashed #cfd7ff;
    border-radius: 22px;
    padding: 32px 22px 36px;
    text-align: center;
    background: #f9fbff;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 160px;
}
.upload-dropzone:hover,
.upload-dropzone.dragover {
    background: #f1f5ff;
    border-color: #94b4ff;
    box-shadow: 0 8px 24px rgba(148, 180, 255, 0.25);
}
.upload-dropzone .icon {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 30px;
    color: #fff;
    background: linear-gradient(135deg, #1d4ed8, #5b8dff);
}
.upload-dropzone .tip {
    margin-top: 12px;
    color: #6b7280;
    font-size: 13px;
    line-height: 1.6;
}
.upload-progress-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.upload-progress-item {
    background: #f3f4f6;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 13px;
    border: 1px solid #e5e7eb;
}
.upload-progress-item-uploading {
    background: #f0f9ff;
    border-color: #93c5fd;
}
.upload-progress-item-success {
    background: #f0fdf4;
    border-color: #86efac;
}
.upload-progress-item-error {
    background: #fef2f2;
    border-color: #fca5a5;
}
.upload-progress-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.upload-progress-name {
    font-weight: 500;
    color: #111827;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    margin-right: 12px;
}
.upload-progress-status {
    color: #6b7280;
    font-size: 12px;
    white-space: nowrap;
}
.upload-progress-item-uploading .upload-progress-status {
    color: #2563eb;
    font-weight: 500;
}
.upload-progress-item-success .upload-progress-status {
    color: #16a34a;
    font-weight: 500;
}
.upload-progress-item-error .upload-progress-status {
    color: #dc2626;
    font-weight: 500;
}
.upload-progress-bar-container {
    width: 100%;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    position: relative;
}
.upload-progress-item-uploading .upload-progress-bar-container {
    background: #dbeafe;
}
.upload-progress-item-success .upload-progress-bar-container {
    background: #dcfce7;
}
.upload-progress-item-error .upload-progress-bar-container {
    background: #fee2e2;
}
.upload-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #60a5fa);
    border-radius: 3px;
    transition: width 0.3s ease;
    position: relative;
    overflow: hidden;
}
.upload-progress-item-uploading .upload-progress-bar {
    background: linear-gradient(90deg, #2563eb, #3b82f6);
    animation: progress-shimmer 1.5s infinite;
}
.upload-progress-item-success .upload-progress-bar {
    background: linear-gradient(90deg, #16a34a, #22c55e);
}
.upload-progress-item-error .upload-progress-bar {
    background: linear-gradient(90deg, #dc2626, #ef4444);
}
@keyframes progress-shimmer {
    0% {
        background-position: -100% 0;
    }
    100% {
        background-position: 100% 0;
    }
}
.file-card-section {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.file-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 8px;
}
.file-section-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
}
/* 统一的树形视图容器 */
.file-tree-view {
    display: flex;
    flex-direction: column;
    gap: 16px;
    background: #f6f8fd;
    border: 1px solid #e3e9f5;
    border-radius: 20px;
    padding: 20px;
}

/* 文件树容器 - 整合文件夹和文件显示 */
.file-tree-container {
    flex: 1;
    min-height: 400px;
    max-height: 600px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e4e9f3;
    border-radius: 16px;
    padding: 12px;
}

.file-tree-loading {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 14px;
}

/* 文件夹节点样式 */
.file-tree-folder {
    margin-bottom: 4px;
}

.folder-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s ease;
    user-select: none;
}

.folder-header:hover {
    background: #f0f4ff;
}

.folder-header[data-selected="1"] {
    background: #e0edff;
    color: #1d4ed8;
    font-weight: 600;
}

.folder-toggle {
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #64748b;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}

.folder-toggle.expanded {
    transform: rotate(90deg);
}

.folder-icon {
    font-size: 16px;
    flex-shrink: 0;
}

.folder-name {
    flex: 1;
    font-size: 14px;
    color: #1e293b;
}

.folder-count {
    font-size: 12px;
    color: #94a3b8;
    margin-left: auto;
}

.folder-children {
    margin-left: 24px;
    overflow: hidden;
    transition: max-height 0.3s ease, opacity 0.2s ease;
}

.folder-children.collapsed {
    max-height: 0;
    opacity: 0;
}

/* 文件项样式 - 卡片式布局 */
.file-tree-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    margin-bottom: 6px;
    background: #fff;
    border: 1px solid #eef2f6;
    border-radius: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.file-tree-item:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.file-tree-item[data-selected="1"] {
    background: #eff6ff;
    border-color: #3b82f6;
}

.file-tree-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    flex-shrink: 0;
}

.file-tree-item .file-thumbnail,
.file-tree-item .file-icon-placeholder {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    border: 1px solid #e4e9f3;
    flex-shrink: 0;
    object-fit: cover;
}

.file-tree-item .file-icon-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f6f8fd;
    font-size: 24px;
}

.file-tree-item .file-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.file-tree-item .file-name {
    font-weight: 600;
    font-size: 14px;
    color: #1e293b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-tree-item .file-meta {
    font-size: 12px;
    color: #64748b;
    display: flex;
    gap: 12px;
    align-items: center;
}

.file-tree-item .file-actions {
    display: flex;
    gap: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
    flex-shrink: 0;
}

.file-tree-item:hover .file-actions {
    opacity: 1;
}

.file-tree-item .file-actions button {
    padding: 6px 12px;
    font-size: 12px;
    border: none;
    background: transparent;
    color: #3b82f6;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.2s ease;
}

.file-tree-item .file-actions button:hover {
    background: #eff6ff;
}

.file-tree-item .file-actions button.delete {
    color: #dc2626;
}

.file-tree-item .file-actions button.delete:hover {
    background: #fef2f2;
}

/* 层级缩进 */
.file-tree-item[data-level="1"],
.file-tree-folder[data-level="1"] {
    margin-left: 24px;
}

.file-tree-item[data-level="2"],
.file-tree-folder[data-level="2"] {
    margin-left: 48px;
}

.file-tree-item[data-level="3"],
.file-tree-folder[data-level="3"] {
    margin-left: 72px;
}

.file-tree-item[data-level="4"],
.file-tree-folder[data-level="4"] {
    margin-left: 96px;
}

.file-tree-item[data-level="5"],
.file-tree-folder[data-level="5"] {
    margin-left: 120px;
}
.file-toolbar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 4px 0 6px;
}
.file-toolbar-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.file-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.file-controls input[type="search"] {
    flex: 1;
    min-width: 220px;
    max-width: 420px;
    padding: 8px 12px;
    border-radius: 12px;
}
.file-action-buttons {
    display: flex;
    gap: 10px;
}
.file-action-buttons .btn {
    padding: 6px 18px;
    font-size: 13px;
}
.folder-breadcrumb {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
    min-height: 32px;
}
.folder-breadcrumb .breadcrumb-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 999px;
    background: #eef2ff;
    color: #1d4ed8;
    font-weight: 500;
}
.folder-breadcrumb .breadcrumb-item button {
    border: none;
    background: transparent;
    padding: 0;
    color: inherit;
    font-size: inherit;
    cursor: pointer;
}
.view-switch .btn {
    min-width: 110px;
    padding: 6px 14px;
    font-size: 13px;
    border-radius: 999px;
}
/* 保留文件空状态提示样式 */
.file-empty-tip {
    text-align: center;
    padding: 60px 10px;
    color: #94a3b8;
    font-size: 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.file-empty-tip::before {
    content: '📁';
    font-size: 44px;
    line-height: 1;
}
.file-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 14px 4px 0;
    border-top: 1px solid #eef2f6;
    margin-top: 4px;
}
.file-pagination .btn {
    padding: 6px 16px;
    font-size: 13px;
}
.folder-path-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: #eef2ff;
    color: #4c1d95;
    font-size: 12px;
}
.folder-path-pill.is-root {
    background: #f1f5f9;
    color: #475569;
}
.files-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding-top: 8px;
}
.files-footer button {
    min-width: 160px;
    padding: 10px 24px;
    font-size: 15px;
}
.fw-semibold {
    font-weight: 600;
    font-size: 16px;
    margin-bottom: 12px;
    color: #1f2937;
}
@media (max-width: 1280px) {
    .files-columns {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .customer-files-layout {
        padding: 16px 0 24px;
    }
    .files-columns {
        grid-template-columns: 1fr;
        gap: 24px;
    }
    .upload-dropzone {
        padding: 28px 16px 32px;
    }
    .file-controls {
        flex-direction: column;
        align-items: stretch;
    }
    .file-action-buttons {
        width: 100%;
        justify-content: flex-end;
    }
    .view-switch {
        width: 100%;
    }
}
/* 目录树重命名按钮样式 */
.tree-node {
    position: relative;
}

.tree-rename-btn {
    opacity: 0;
    transition: opacity 0.2s ease;
    font-size: 12px;
    padding: 2px 4px !important;
    margin-left: 8px;
    vertical-align: middle;
}

.tree-node:hover .tree-rename-btn {
    opacity: 1;
}

/* 预览模态框样式 */
.preview-image-container {
    text-align: center;
    overflow: auto;
    max-height: 70vh;
    padding: 20px;
    position: relative;
}

.preview-image {
    max-width: 100%;
    max-height: 60vh;
    object-fit: contain;
    transition: transform 0.3s ease;
    cursor: zoom-in;
}

.preview-nav-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0, 0, 0, 0.6);
    color: white;
    border: none;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    z-index: 10;
    padding: 0;
}

.preview-nav-btn:hover:not(:disabled) {
    background: rgba(0, 0, 0, 0.8);
    transform: translateY(-50%) scale(1.1);
}

.preview-nav-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.preview-nav-prev {
    left: 20px;
}

.preview-nav-next {
    right: 20px;
}

.preview-nav-btn svg {
    width: 24px;
    height: 24px;
}

.preview-controls {
    margin-top: 15px;
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}

.preview-video-container {
    text-align: center;
    padding: 20px;
}

.preview-video {
    max-width: 100%;
    max-height: 70vh;
    width: auto;
    height: auto;
}

.preview-audio-container {
    text-align: center;
    padding: 40px 20px;
}

.preview-audio {
    width: 100%;
    max-width: 600px;
}

#previewModal .modal-body {
    padding: 0;
    overflow: hidden;
}

#previewModal .modal-footer {
    border-top: 1px solid #dee2e6;
}

/* 全屏预览时的样式 */
.preview-image:fullscreen {
    max-width: 100vw;
    max-height: 100vh;
    object-fit: contain;
}
</style>

<div id="customerFilesApp"
     class="customer-files-layout"
     data-customer-id="<?= (int)$customerId ?>"
     data-can-manage="<?= $canManageFiles ? '1' : '0' ?>"
     data-max-files="<?= $folderLimits['max_files'] ?>"
     data-max-bytes="<?= $folderLimits['max_total_bytes'] ?>"
     data-max-single-size="<?= $maxSingleSize ?>"
     data-max-depth="<?= $folderLimits['max_depth'] ?>"
     data-max-segment="<?= $folderLimits['max_segment_length'] ?>"
     data-folder-limit-hint="<?= htmlspecialchars($folderLimitHint) ?>">


    <div class="files-columns">
        <div class="file-column" data-role="file-column" data-type="customer">
            <div class="file-column-header">客户发送的资料</div>
            <?php if ($canManageFiles): ?>
                <div class="upload-dropzone" data-role="upload-zone" data-type="customer" tabindex="0" style="outline: none; cursor: pointer;">
                    <div class="icon">⬆️</div>
                    <p class="mb-1"><strong>双击选择文件/文件夹，或拖拽上传，或单击后按 Ctrl+V 粘贴</strong></p>
                    <p class="tip">
                        支持文件或文件夹上传；系统会自动命名为"图片/视频/文件-原名"，单次最多 <?= htmlspecialchars($folderLimitHint) ?>，子目录深度 ≤ <?= $folderLimits['max_depth'] ?> 层。
                    </p>
                    <input type="file" data-role="upload-input" data-type="customer" multiple hidden>
                </div>
                <ul class="upload-progress-list d-none" data-role="upload-progress" data-type="customer"></ul>
            <?php else: ?>
                <div class="alert alert-light border-0 text-muted">当前仅支持查看，无法上传客户资料。</div>
            <?php endif; ?>

            <div class="file-card-section">
                <div class="file-section-header">
                    <div class="file-section-title">下载客户资料</div>
                </div>
                <div class="file-tree-view" data-role="file-browser" data-type="customer">
                    <div class="file-toolbar">
                        <div class="file-toolbar-meta">
                            <div class="folder-breadcrumb" data-role="folder-breadcrumb" data-type="customer"></div>
                            <div class="view-switch btn-group" data-role="view-switch" data-type="customer">
                                <button type="button" class="btn btn-light btn-sm active" data-view-mode="include" data-type="customer">包含子层</button>
                                <button type="button" class="btn btn-light btn-sm" data-view-mode="current" data-type="customer">仅当前层</button>
                            </div>
                        </div>
                        <div class="file-controls">
                            <input type="search" class="form-control form-control-sm" placeholder="搜索文件名" data-role="file-search" data-type="customer">
                            <div class="file-action-buttons">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="download-current" data-type="customer">下载当前目录</button>
                                <button type="button" class="btn btn-primary btn-sm" data-action="download-selected" data-type="customer" disabled>打包所选</button>
                                <?php if ($canManageFiles): ?>
                                <button type="button" class="btn btn-danger btn-sm" data-action="delete-selected" data-type="customer" disabled>批量删除</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="file-tree-container" data-role="file-tree-container" data-type="customer">
                        <div class="file-tree-loading">正在加载...</div>
                    </div>
                    <div class="file-pagination" data-role="file-pagination" data-type="customer">
                        <button type="button" class="btn btn-sm btn-light" data-direction="prev" data-type="customer">上一页</button>
                        <span class="text-muted small" data-role="page-info" data-type="customer"></span>
                        <button type="button" class="btn btn-sm btn-light" data-direction="next" data-type="customer">下一页</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="file-column" data-role="file-column" data-type="company">
            <div class="file-column-header">我们提供的资料</div>
            <?php if ($canManageFiles): ?>
                <div class="upload-dropzone" data-role="upload-zone" data-type="company" tabindex="0" style="outline: none; cursor: pointer;">
                    <div class="icon">⬆️</div>
                    <p class="mb-1"><strong>双击选择文件/文件夹，或拖拽上传，或单击后按 Ctrl+V 粘贴</strong></p>
                    <p class="tip">
                        支持文件或文件夹上传；单次最多 <?= htmlspecialchars($folderLimitHint) ?>，系统将保留原文件名并写入"公司文件/子目录"。
                    </p>
                    <input type="file" data-role="upload-input" data-type="company" multiple hidden>
                </div>
                <ul class="upload-progress-list d-none" data-role="upload-progress" data-type="company"></ul>
            <?php else: ?>
                <div class="alert alert-light border-0 text-muted">当前仅支持查看，无法上传公司资料。</div>
            <?php endif; ?>

            <div class="file-card-section">
                <div class="file-section-header">
                    <div class="file-section-title">下载公司资料</div>
                </div>
                <div class="file-tree-view" data-role="file-browser" data-type="company">
                    <div class="file-toolbar">
                        <div class="file-toolbar-meta">
                            <div class="folder-breadcrumb" data-role="folder-breadcrumb" data-type="company"></div>
                            <div class="view-switch btn-group" data-role="view-switch" data-type="company">
                                <button type="button" class="btn btn-light btn-sm active" data-view-mode="include" data-type="company">包含子层</button>
                                <button type="button" class="btn btn-light btn-sm" data-view-mode="current" data-type="company">仅当前层</button>
                            </div>
                        </div>
                        <div class="file-controls">
                            <input type="search" class="form-control form-control-sm" placeholder="搜索文件名" data-role="file-search" data-type="company">
                            <div class="file-action-buttons">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="download-current" data-type="company">下载当前目录</button>
                                <button type="button" class="btn btn-primary btn-sm" data-action="download-selected" data-type="company" disabled>打包所选</button>
                                <?php if ($canManageFiles): ?>
                                <button type="button" class="btn btn-danger btn-sm" data-action="delete-selected" data-type="company" disabled>批量删除</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="file-tree-container" data-role="file-tree-container" data-type="company">
                        <div class="file-tree-loading">正在加载...</div>
                    </div>
                    <div class="file-pagination" data-role="file-pagination" data-type="company">
                        <button type="button" class="btn btn-sm btn-light" data-direction="prev" data-type="company">上一页</button>
                        <span class="text-muted small" data-role="page-info" data-type="company"></span>
                        <button type="button" class="btn btn-sm btn-light" data-direction="next" data-type="company">下一页</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="files-footer">
        <a href="file_manager.php?customer_id=<?= $customerId ?>" class="btn btn-primary" target="_blank">独立文件管理页面</a>
        <button type="button" class="btn btn-primary" data-action="refresh-files">保存上传</button>
    </div>
</div>

<script src="/js/customer-files.js?v=20251121"></script>
