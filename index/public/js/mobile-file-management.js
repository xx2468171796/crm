/**
 * 手机版文件管理模块
 * 复用电脑版 customer-files.js 的API逻辑，适配移动端UI
 */
(function() {
    'use strict';
    
    // 防止重复初始化
    if (window.__MOBILE_FILE_MANAGEMENT_INITED) {
        return;
    }
    window.__MOBILE_FILE_MANAGEMENT_INITED = true;
    
    const app = document.getElementById('mobileFileManagementApp');
    if (!app) return;
    
    const PAGE_SIZE = 20; // 手机版每页显示更多
    
    const CATEGORY_MAP = {
        customer: 'client_material',
        company: 'internal_solution',
    };
    
    const CATEGORY_LABEL = {
        customer: '客户发送的资料',
        company: '我们提供的资料',
    };
    
    // 状态管理
    const state = {
        customerId: parseInt(app.dataset.customerId, 10),
        canManage: app.dataset.canManage === '1',
        currentType: 'customer', // 当前选中的分类
        currentPath: '', // 当前文件夹路径
        includeChildren: true, // 是否包含子文件夹
        keyword: '', // 搜索关键词
        page: 1,
        pageSize: PAGE_SIZE,
        total: 0,
        items: [],
        loading: false,
        error: '',
        selected: new Set(), // 选中的文件ID
        multiSelectMode: false, // 多选模式
        tree: null, // 文件夹树数据
        uploading: false, // 是否正在上传
        uploadSignature: null, // 当前上传的文件签名
        viewMode: 'list', // 视图模式: 'list' 列表, 'grid' 看板
    };
    
    // 防止重复绑定事件监听器
    let eventsBound = false;
    
    // DOM元素引用
    const els = {
        segmentCustomer: app.querySelector('.segment[data-type="customer"]'),
        segmentCompany: app.querySelector('.segment[data-type="company"]'),
        uploadBtn: app.querySelector('#mobileFileUploadBtn'),
        fileInput: app.querySelector('#mobileFileInput'),
        folderInput: app.querySelector('#mobileFolderInput'),
        cameraInput: app.querySelector('#mobileCameraInput'),
        uploadProgress: app.querySelector('#mobileUploadProgress'),
        searchInput: app.querySelector('#fileSearchInput'),
        folderTreeBtn: app.querySelector('#folderTreeBtn'),
        folderBreadcrumb: app.querySelector('#folderBreadcrumb'),
        fileList: app.querySelector('#fileList'),
        filePagination: app.querySelector('#filePagination'),
        prevPage: app.querySelector('#prevPage'),
        nextPage: app.querySelector('#nextPage'),
        pageInfo: app.querySelector('#pageInfo'),
        multiSelectBar: app.querySelector('#multiSelectBar'),
        selectAllBtn: app.querySelector('#selectAllBtn'),
        selectedCount: app.querySelector('#selectedCount'),
        batchDownloadBtn: app.querySelector('#batchDownloadBtn'),
        batchDeleteBtn: app.querySelector('#batchDeleteBtn'),
        folderTreeModal: app.querySelector('#folderTreeModal'),
        folderTree: app.querySelector('#folderTree'),
        folderTreeClose: app.querySelector('#folderTreeClose'),
        viewModeBtn: app.querySelector('#viewModeBtn'),
        viewModeIcon: app.querySelector('#viewModeIcon'),
    };
    
    // 初始化
    init();
    
    function init() {
        if (state.customerId <= 0) {
            showEmptyMessage('请先保存客户信息');
            return;
        }
        
        bindEvents();
        loadFiles();
        loadFolderTree();
    }
    
    function bindEvents() {
        // 防止重复绑定事件监听器
        if (eventsBound) {
            console.warn('[MobileFileManagement] events 已经绑定，跳过重复绑定');
            return;
        }
        eventsBound = true;
        
        // 分类切换
        els.segmentCustomer?.addEventListener('click', () => switchCategory('customer'));
        els.segmentCompany?.addEventListener('click', () => switchCategory('company'));
        
        // 上传按钮 - 长按显示更多选项
        let longPressTimer;
        els.uploadBtn?.addEventListener('touchstart', (e) => {
            if (!state.canManage) return;
            longPressTimer = setTimeout(() => {
                showUploadOptions();
            }, 500);
        });
        els.uploadBtn?.addEventListener('touchend', () => {
            clearTimeout(longPressTimer);
        });
        els.uploadBtn?.addEventListener('touchmove', () => {
            clearTimeout(longPressTimer);
        });
        els.uploadBtn?.addEventListener('click', (e) => {
            if (!state.canManage) return;
            e.preventDefault();
            els.fileInput?.click();
        });
        
        // 文件选择
        els.fileInput?.addEventListener('change', handleFileSelect);
        els.folderInput?.addEventListener('change', handleFileSelect);
        els.cameraInput?.addEventListener('change', handleFileSelect);
        
        // 搜索
        els.searchInput?.addEventListener('input', debounce(handleSearch, 300));
        
        // 文件夹树
        els.folderTreeBtn?.addEventListener('click', showFolderTreeModal);
        els.folderTreeClose?.addEventListener('click', hideFolderTreeModal);
        
        // 视图模式切换
        els.viewModeBtn?.addEventListener('click', toggleViewMode);
        
        // 分页
        els.prevPage?.addEventListener('click', () => changePage(-1));
        els.nextPage?.addEventListener('click', () => changePage(1));
        
        // 多选模式
        els.selectAllBtn?.addEventListener('click', toggleSelectAll);
        els.batchDownloadBtn?.addEventListener('click', handleBatchDownload);
        els.batchDeleteBtn?.addEventListener('click', handleBatchDelete);
        
        // 点击模态框背景关闭
        els.folderTreeModal?.addEventListener('click', (e) => {
            if (e.target === els.folderTreeModal) {
                hideFolderTreeModal();
            }
        });
        
        // 监听退出多选模式（点击外部区域）
        document.addEventListener('click', (e) => {
            if (state.multiSelectMode && !e.target.closest('#mobileFileManagementApp')) {
                exitMultiSelectMode();
            }
        });
    }
    
    // 显示上传选项（拍照/文件夹上传）
    function showUploadOptions() {
        if (!state.canManage) return;
        
        const options = ['选择文件', '选择文件夹', '拍照'];
        if (options.length === 0) return;
        
        const optionText = options.join('\n');
        const choice = prompt(`请选择上传方式：\n1. 选择文件\n2. 选择文件夹\n3. 拍照`, '1');
        
        if (choice === '1' || choice === null) {
            els.fileInput?.click();
        } else if (choice === '2') {
            // 检查浏览器是否支持文件夹上传
            if (els.folderInput && 'webkitdirectory' in els.folderInput) {
                els.folderInput.click();
            } else {
                showToast('您的浏览器不支持文件夹上传');
            }
        } else if (choice === '3') {
            els.cameraInput?.click();
        }
    }
    
    // 切换分类
    function switchCategory(type) {
        if (state.currentType === type) return;
        
        state.currentType = type;
        state.currentPath = '';
        state.page = 1;
        state.selected.clear();
        state.multiSelectMode = false;
        
        // 更新UI
        els.segmentCustomer?.classList.toggle('active', type === 'customer');
        els.segmentCompany?.classList.toggle('active', type === 'company');
        
        updateBreadcrumb();
        hideMultiSelectBar();
        loadFiles();
        loadFolderTree(); // 重新加载文件夹树
    }
    
    // 加载文件列表
    function loadFiles() {
        if (state.loading) return;
        
        state.loading = true;
        state.error = '';
        
        renderLoading();
        
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[state.currentType],
            page: state.page,
            page_size: state.pageSize,
            include_children: state.includeChildren ? '1' : '0',
            keyword: state.keyword || '',
            folder_path: state.currentPath || '',
        });
        
        fetch(`/api/customer_files.php?${params.toString()}`, {
            credentials: 'include'
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || '加载文件失败');
            }
            
            const payload = data.data || {};
            state.items = payload.items || [];
            state.total = payload.pagination?.total || 0;
            state.page = payload.pagination?.page || state.page;
            
            renderFileList();
            updatePagination();
        })
        .catch(err => {
            state.error = err.message || '加载文件失败';
            renderError(state.error);
        })
        .finally(() => {
            state.loading = false;
        });
    }
    
    // 切换视图模式
    function toggleViewMode() {
        state.viewMode = state.viewMode === 'list' ? 'grid' : 'list';
        updateViewModeIcon();
        renderFileList();
    }
    
    // 更新视图模式图标
    function updateViewModeIcon() {
        if (!els.viewModeIcon) return;
        if (state.viewMode === 'grid') {
            // 列表图标 (当前是看板模式，点击切换到列表)
            els.viewModeIcon.innerHTML = `
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            `;
        } else {
            // 看板图标 (当前是列表模式，点击切换到看板)
            els.viewModeIcon.innerHTML = `
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
            `;
        }
    }
    
    // 渲染文件列表
    function renderFileList() {
        if (!els.fileList) return;
        
        if (state.items.length === 0) {
            els.fileList.innerHTML = '<div class="file-empty-tip">暂无文件</div>';
            return;
        }
        
        // 更新容器的视图模式类
        els.fileList.className = state.viewMode === 'grid' ? 'file-list file-grid-view' : 'file-list';
        
        els.fileList.innerHTML = state.items.map(file => {
            const isSelected = state.selected.has(file.id);
            const fileIcon = getFileIcon(file.filename);
            const isImage = isImageFile(file.filename);
            const hasThumbnail = file.thumbnail_url && isImage;
            
            // 看板模式：大缩略图，只显示文件名
            if (state.viewMode === 'grid') {
                return `
                    <div class="file-grid-card" data-file-id="${file.id}">
                        <div class="file-grid-thumb ${hasThumbnail ? 'has-thumbnail' : ''}">
                            ${hasThumbnail 
                                ? `<img src="${escapeHtml(file.thumbnail_url)}" alt="${escapeHtml(file.filename)}" class="file-grid-img" onerror="this.parentElement.innerHTML='<span class=\\'file-grid-icon\\'>${fileIcon}</span>'">`
                                : `<span class="file-grid-icon">${fileIcon}</span>`
                            }
                        </div>
                        <div class="file-grid-name" title="${escapeHtml(file.filename)}">${escapeHtml(file.filename)}</div>
                    </div>
                `;
            }
            
            // 列表模式：原有布局
            return `
                <div class="file-card ${state.multiSelectMode ? 'multi-select' : ''}" 
                     data-file-id="${file.id}">
                    ${state.multiSelectMode ? `
                        <input type="checkbox" class="file-checkbox" 
                               ${isSelected ? 'checked' : ''}>
                    ` : ''}
                    <div class="file-icon ${hasThumbnail ? 'has-thumbnail' : ''}">${hasThumbnail ? `<img src="${escapeHtml(file.thumbnail_url)}" alt="${escapeHtml(file.filename)}" class="file-thumbnail" onerror="this.parentElement.innerHTML='${fileIcon}'">` : fileIcon}</div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(file.filename)}</div>
                        ${file.folder_path ? `
                            <div class="file-folder-path" style="font-size: 12px; color: #94a3b8; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                                <span>${escapeHtml(file.display_folder || file.folder_path)}</span>
                            </div>
                        ` : ''}
                        <div class="file-meta">
                            ${formatSize(file.filesize)} · ${formatDate(file.uploaded_at)}
                        </div>
                    </div>
                    ${!state.multiSelectMode ? `
                        <div class="file-actions">
                            ${file.preview_supported ? `
                                <button class="file-action-btn" data-action="preview" 
                                        data-file-id="${file.id}" title="预览">👁️</button>
                            ` : ''}
                            <button class="file-action-btn" data-action="download" 
                                    data-file-id="${file.id}" title="下载">⬇️</button>
                            ${state.canManage ? `
                                <button class="file-action-btn" data-action="rename" 
                                        data-file-id="${file.id}" title="重命名">✏️</button>
                                <button class="file-action-btn" data-action="share" 
                                        data-file-id="${file.id}" title="分享">🔗</button>
                                <button class="file-action-btn" data-action="delete" 
                                        data-file-id="${file.id}" title="删除">🗑️</button>
                            ` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
        
        // 绑定事件 - 看板模式卡片点击预览
        els.fileList.querySelectorAll('.file-grid-card').forEach(card => {
            const fileId = parseInt(card.dataset.fileId, 10);
            card.addEventListener('click', () => {
                handlePreviewFile(fileId);
            });
        });
        
        // 绑定事件 - 列表模式
        els.fileList.querySelectorAll('.file-card').forEach(card => {
            const fileId = parseInt(card.dataset.fileId, 10);
            
            // 多选模式下的复选框
            const checkbox = card.querySelector('.file-checkbox');
            if (checkbox) {
                checkbox.addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleFileSelect(fileId);
                });
            }
            
            // 点击卡片
            card.addEventListener('click', (e) => {
                if (state.multiSelectMode) {
                    // 多选模式：切换选中状态（如果点击的不是复选框）
                    if (e.target.closest('.file-checkbox')) return;
                    toggleFileSelect(fileId);
                } else {
                    // 普通模式：不做处理（由操作按钮处理）
                }
            });
            
            // 长按进入多选（仅在非多选模式下）
            if (!state.multiSelectMode) {
                let longPressTimer;
                let longPressStart = false;
                card.addEventListener('touchstart', (e) => {
                    longPressStart = true;
                    longPressTimer = setTimeout(() => {
                        if (longPressStart) {
                            enterMultiSelectMode(fileId);
                            // 震动反馈（如果支持）
                            if (navigator.vibrate) {
                                navigator.vibrate(50);
                            }
                            longPressStart = false;
                        }
                    }, 500);
                });
                card.addEventListener('touchend', () => {
                    clearTimeout(longPressTimer);
                    longPressStart = false;
                });
                card.addEventListener('touchmove', () => {
                    clearTimeout(longPressTimer);
                    longPressStart = false;
                });
            }
            
            // 操作按钮
            card.querySelectorAll('[data-action]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const action = btn.dataset.action;
                    const id = parseInt(btn.dataset.fileId, 10);
                    
                    if (action === 'preview') {
                        handlePreviewFile(id);
                    } else if (action === 'download') {
                        downloadFile(id);
                    } else if (action === 'rename') {
                        handleRenameFile(id);
                    } else if (action === 'share') {
                        handleShareFile(id);
                    } else if (action === 'delete') {
                        deleteFile(id);
                    }
                });
            });
        });
    }
    
    // 加载文件夹树
    function loadFolderTree() {
        if (state.customerId <= 0) return;
        
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[state.currentType],
            tree: '1',
            parent_path: '',
        });
        
        fetch(`/api/customer_files.php?${params.toString()}`, {
            credentials: 'include'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                state.tree = data.data;
                renderFolderTree();
            }
        })
        .catch(err => {
            console.error('加载文件夹树失败:', err);
        });
    }
    
    // 渲染文件夹树
    function renderFolderTree() {
        if (!els.folderTree) return;
        
        if (!state.tree || !state.tree.folders || state.tree.folders.length === 0) {
            els.folderTree.innerHTML = '<div class="file-empty-tip" style="padding: 20px;">暂无文件夹</div>';
            return;
        }
        
        // 渲染文件夹列表
        const renderFolderItem = (folder, level = 0) => {
            const paddingLeft = level * 16 + 12;
            return `
                <div class="folder-tree-item" 
                     style="padding-left: ${paddingLeft}px;"
                     data-path="${escapeHtml(folder.full_path || '')}"
                     data-has-children="${folder.has_children ? '1' : '0'}">
                    <span class="icon">${folder.has_children ? '📁' : '📂'}</span>
                    <span class="name">${escapeHtml(folder.label || folder.path || '')}</span>
                    ${folder.file_count !== undefined ? `<span class="count">${folder.file_count} 个文件</span>` : ''}
                </div>
            `;
        };
        
        const renderFolders = (folders, level = 0) => {
            return folders.map(folder => {
                let html = renderFolderItem(folder, level);
                if (folder.children && folder.children.length > 0) {
                    html += renderFolders(folder.children, level + 1);
                }
                return html;
            }).join('');
        };
        
        els.folderTree.innerHTML = `
            <div class="folder-tree-item" 
                 style="padding-left: 12px; font-weight: 600;"
                 data-path="">
                <span class="icon">📂</span>
                <span class="name">全部</span>
            </div>
            ${renderFolders(state.tree.folders || [])}
        `;
        
        // 绑定点击事件
        els.folderTree.querySelectorAll('.folder-tree-item').forEach(item => {
            item.addEventListener('click', () => {
                const path = item.dataset.path || '';
                navigateToFolder(path);
                hideFolderTreeModal();
            });
        });
    }
    
    // 更新面包屑导航
    function updateBreadcrumb() {
        if (!els.folderBreadcrumb) return;
        
        const crumbs = [
            { label: '全部', path: '' }
        ];
        
        if (state.currentPath) {
            const paths = state.currentPath.split('/').filter(p => p);
            paths.forEach((path, index) => {
                const fullPath = paths.slice(0, index + 1).join('/');
                crumbs.push({ label: path, path: fullPath });
            });
        }
        
        els.folderBreadcrumb.innerHTML = crumbs.map((crumb, index) => {
            const isActive = index === crumbs.length - 1;
            return `
                ${index > 0 ? '<span class="separator">/</span>' : ''}
                <button class="crumb ${isActive ? 'active' : ''}" 
                        data-path="${crumb.path}">
                    ${escapeHtml(crumb.label)}
                </button>
            `;
        }).join('');
        
        // 绑定点击事件
        els.folderBreadcrumb.querySelectorAll('.crumb').forEach(btn => {
            btn.addEventListener('click', () => {
                const path = btn.dataset.path;
                navigateToFolder(path);
            });
        });
    }
    
    // 导航到文件夹
    function navigateToFolder(path) {
        state.currentPath = path;
        state.page = 1;
        state.selected.clear();
        updateBreadcrumb();
        loadFiles();
    }
    
    // 文件上传
    function handleFileSelect(e) {
        const files = Array.from(e.target.files || []);
        if (files.length === 0) return;
        
        uploadFiles(files);
        e.target.value = ''; // 清空input，允许重复选择相同文件
    }
    
    function uploadFiles(files) {
        // 如果正在上传，阻止重复上传
        if (state.uploading) {
            showToast('文件正在上传中，请稍候...', 'warning');
            return;
        }
        
        // 构建文件签名（用于防重复上传）
        const uploadSignature = buildFileSignature(files);
        if (state.uploadSignature === uploadSignature) {
            showToast('该文件正在上传中，请勿重复上传', 'warning');
            return;
        }
        
        // 检查客户ID
        if (state.customerId <= 0) {
            showToast('请先保存客户信息，保存后即可上传文件', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('customer_id', state.customerId);
        formData.append('category', CATEGORY_MAP[state.currentType]);
        if (state.currentPath) {
            formData.append('folder_path', state.currentPath);
        }
        
        files.forEach(file => {
            formData.append('files[]', file);
        });
        
        // 设置上传状态
        state.uploading = true;
        state.uploadSignature = uploadSignature;
        
        // 显示上传进度
        showUploadProgress(files.length);
        
        fetch('/api/customer_files.php', {
            method: 'POST',
            credentials: 'include',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            hideUploadProgress();
            state.uploading = false;
            state.uploadSignature = null;
            
            if (data.success) {
                showToast(`成功上传 ${data.data?.length || files.length} 个文件`);
                loadFiles();
                loadFolderTree();
            } else {
                showToast(data.message || '上传失败');
            }
        })
        .catch(err => {
            hideUploadProgress();
            state.uploading = false;
            state.uploadSignature = null;
            showToast('上传失败，请重试');
            console.error('Upload error:', err);
        });
    }
    
    // 构建文件签名（用于防重复上传）
    function buildFileSignature(files) {
        if (!files || !files.length) return '';
        return files
            .map((file) => `${file.name || ''}:${file.size || 0}:${file.lastModified || 0}`)
            .join('|');
    }
    
    // 显示上传进度
    function showUploadProgress(fileCount) {
        if (!els.uploadProgress) return;
        els.uploadProgress.style.display = 'block';
        els.uploadProgress.innerHTML = `
            <div style="text-align: center; padding: 16px;">
                <div style="font-size: 15px; margin-bottom: 8px;">正在上传 ${fileCount} 个文件...</div>
                <div style="width: 100%; height: 4px; background: #E5E5EA; border-radius: 2px; overflow: hidden;">
                    <div style="width: 60%; height: 100%; background: var(--primary-color); animation: loading 1.5s infinite;"></div>
                </div>
            </div>
        `;
    }
    
    function hideUploadProgress() {
        if (els.uploadProgress) {
            els.uploadProgress.style.display = 'none';
        }
    }
    
    // 下载文件
    function downloadFile(fileId) {
        window.open(`/api/customer_file_stream.php?id=${fileId}&mode=download`, '_blank');
    }
    
    // 删除文件
    function deleteFile(fileId) {
        showMobileConfirm('确定要删除该文件吗？删除后可在15天内恢复。', function() {
            fetch('/api/customer_file_delete.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${encodeURIComponent(fileId)}`,
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('文件已删除');
                    loadFiles();
                } else {
                    showToast(data.message || '删除失败');
                }
            })
            .catch(err => {
                showToast('删除失败，请重试');
                console.error('Delete error:', err);
            });
        });
    }
    
    // 搜索
    function handleSearch(e) {
        state.keyword = e.target.value.trim();
        state.page = 1;
        loadFiles();
    }
    
    // 分页
    function changePage(delta) {
        const totalPages = Math.ceil(state.total / state.pageSize);
        const newPage = state.page + delta;
        if (newPage >= 1 && newPage <= totalPages) {
            state.page = newPage;
            loadFiles();
            // 滚动到顶部
            els.fileList?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    
    function updatePagination() {
        if (!els.filePagination) return;
        
        const totalPages = Math.ceil(state.total / state.pageSize);
        
        if (totalPages <= 1) {
            els.filePagination.style.display = 'none';
            return;
        }
        
        els.filePagination.style.display = 'flex';
        
        if (els.pageInfo) {
            els.pageInfo.textContent = `第 ${state.page} / ${totalPages} 页`;
        }
        
        els.prevPage.disabled = state.page <= 1;
        els.nextPage.disabled = state.page >= totalPages;
    }
    
    // 多选模式
    function enterMultiSelectMode(initialFileId) {
        state.multiSelectMode = true;
        if (initialFileId) {
            state.selected.add(initialFileId);
        }
        renderFileList();
        showMultiSelectBar();
    }
    
    function exitMultiSelectMode() {
        state.multiSelectMode = false;
        state.selected.clear();
        renderFileList();
        hideMultiSelectBar();
    }
    
    function toggleFileSelect(fileId) {
        if (state.selected.has(fileId)) {
            state.selected.delete(fileId);
        } else {
            state.selected.add(fileId);
        }
        // 更新复选框状态
        const card = els.fileList?.querySelector(`[data-file-id="${fileId}"]`);
        const checkbox = card?.querySelector('.file-checkbox');
        if (checkbox) {
            checkbox.checked = state.selected.has(fileId);
        }
        updateMultiSelectBar();
    }
    
    function toggleSelectAll() {
        const allSelected = state.items.every(file => state.selected.has(file.id));
        if (allSelected) {
            state.selected.clear();
        } else {
            state.items.forEach(file => state.selected.add(file.id));
        }
        renderFileList();
        updateMultiSelectBar();
    }
    
    function showMultiSelectBar() {
        if (els.multiSelectBar) {
            els.multiSelectBar.style.display = 'flex';
        }
        updateMultiSelectBar();
    }
    
    function hideMultiSelectBar() {
        if (els.multiSelectBar) {
            els.multiSelectBar.style.display = 'none';
        }
    }
    
    function updateMultiSelectBar() {
        const count = state.selected.size;
        if (els.selectedCount) {
            els.selectedCount.textContent = `已选择 ${count} 项`;
        }
        if (els.batchDownloadBtn) {
            els.batchDownloadBtn.disabled = count === 0;
        }
        if (els.batchDeleteBtn) {
            els.batchDeleteBtn.disabled = count === 0;
        }
    }
    
    // 批量下载
    function handleBatchDownload() {
        const ids = Array.from(state.selected);
        if (ids.length === 0) {
            showToast('请先选择文件');
            return;
        }
        
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[state.currentType],
            file_ids: ids.join(','),
            selection_type: 'selection',
        });
        
        window.open(`/api/customer_files_download.php?${params.toString()}`, '_blank');
    }
    
    // 批量删除
    function handleBatchDelete() {
        const ids = Array.from(state.selected);
        if (ids.length === 0) {
            showToast('请先选择文件');
            return;
        }
        
        showMobileConfirm(`确定要删除选中的 ${ids.length} 个文件吗？删除后可在15天内恢复。`, function() {
            doBatchDelete(ids);
        });
    }
    
    function doBatchDelete(ids) {
        fetch('/api/customer_file_batch_delete.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids }),
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(`已成功删除 ${data.deleted_count || ids.length} 个文件`);
                exitMultiSelectMode();
                loadFiles();
            } else {
                showToast(data.message || '批量删除失败');
            }
        })
        .catch(err => {
            showToast('批量删除失败，请重试');
            console.error('Batch delete error:', err);
        });
    }
    
    // 文件夹树模态框
    function showFolderTreeModal() {
        if (els.folderTreeModal) {
            els.folderTreeModal.classList.add('show');
        }
    }
    
    function hideFolderTreeModal() {
        if (els.folderTreeModal) {
            els.folderTreeModal.classList.remove('show');
        }
    }
    
    // 工具函数
    function renderLoading() {
        if (els.fileList) {
            els.fileList.innerHTML = '<div class="file-empty-tip">正在加载...</div>';
        }
    }
    
    function renderError(message) {
        if (els.fileList) {
            els.fileList.innerHTML = `<div class="file-empty-tip" style="color: #FF3B30;">${escapeHtml(message)}</div>`;
        }
    }
    
    function showEmptyMessage(message) {
        if (els.fileList) {
            els.fileList.innerHTML = `
                <div class="file-empty-tip">
                    <div style="font-size: 48px; margin-bottom: 16px;">📁</div>
                    <div style="font-size: 15px; color: var(--text-secondary);">${escapeHtml(message)}</div>
                </div>
            `;
        }
    }
    
    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const iconMap = {
            'pdf': '📄',
            'doc': '📝', 'docx': '📝',
            'xls': '📊', 'xlsx': '📊',
            'ppt': '📊', 'pptx': '📊',
            'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️',
            'mp4': '🎬', 'avi': '🎬', 'mov': '🎬',
            'zip': '📦', 'rar': '📦',
            'mp3': '🎵', 'wav': '🎵',
        };
        return iconMap[ext] || '📄';
    }
    
    function isImageFile(filename) {
        const ext = (filename || '').split('.').pop().toLowerCase();
        return ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'heic', 'heif'].includes(ext);
    }
    
    function formatSize(bytes) {
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2) + ' GB';
        } else if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        }
        return bytes + ' B';
    }
    
    function formatDate(timestamp) {
        const date = new Date(timestamp * 1000);
        const now = new Date();
        const diff = now - date;
        const days = Math.floor(diff / 86400000);
        
        if (days === 0) {
            return '今天 ' + date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        } else if (days === 1) {
            return '昨天 ' + date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        } else if (days < 7) {
            return days + '天前';
        } else {
            return date.toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' });
        }
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
    
    function showToast(message, type = 'info') {
        // 使用页面已有的toast功能
        const toast = document.getElementById('toast');
        if (toast) {
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2000);
        } else {
            alert(message);
        }
    }
    
    function showMobileConfirm(message, onConfirm) {
        if (typeof showConfirmModal === 'function') {
            showConfirmModal('确认操作', message, onConfirm);
        } else if (confirm(message)) {
            onConfirm();
        }
    }
    
    // ========== 文件预览功能 ==========
    // 防止并发请求：记录正在进行的预览请求
    let pendingPreviewRequests = new Map();
    let previewLoadingTimer = null;
    
    function handlePreviewFile(fileId) {
        // 如果已经有相同的请求在进行，取消它
        if (pendingPreviewRequests.has(fileId)) {
            const controller = pendingPreviewRequests.get(fileId);
            controller.abort();
        }
        
        // 创建新的AbortController来控制请求
        const controller = new AbortController();
        pendingPreviewRequests.set(fileId, controller);
        
        fetch(`/api/customer_files.php?customer_id=${state.customerId}&action=preview&file_id=${fileId}`, {
            signal: controller.signal
        })
            .then(res => res.json())
            .then(data => {
                // 请求完成后从pending中移除
                pendingPreviewRequests.delete(fileId);
                
                if (!data.success) {
                    throw new Error(data.message || '获取预览链接失败');
                }
                let file = data.data?.file;
                if (!file) {
                    file = state.items.find(f => f.id === fileId);
                    if (!file) {
                        throw new Error('文件不存在');
                    }
                }
                showPreviewModal(
                    file,
                    data.data.preview_url,
                    data.data.sibling_images || [],
                    data.data.prev_file_id,
                    data.data.next_file_id
                );
            })
            .catch(err => {
                // 请求完成后从pending中移除
                pendingPreviewRequests.delete(fileId);
                
                // 如果是主动取消的请求，不显示错误
                if (err.name === 'AbortError') {
                    return;
                }
                showToast('预览失败: ' + err.message, 'error');
            });
    }
    
    function showPreviewModal(file, previewUrl, siblingImages = [], prevFileId = null, nextFileId = null) {
        const modalId = 'mobileFilePreviewModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        const mimeType = file.mime_type || '';
        const isImage = mimeType.startsWith('image/');
        const isVideo = mimeType.startsWith('video/');
        const isAudio = mimeType.startsWith('audio/');
        const isPdf = mimeType === 'application/pdf' || file.filename.toLowerCase().endsWith('.pdf');
        const hasSiblings = isImage && siblingImages.length > 1;
        
        let contentHtml = '';
        if (isImage) {
            contentHtml = `
                <div class="preview-image-container">
                    <img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(file.filename)}" class="preview-image" id="mobilePreviewImage" style="display: block; opacity: 1;">
                    ${hasSiblings ? `
                        <button class="preview-nav-btn prev-btn" id="mobilePreviewPrev" ${!prevFileId ? 'disabled' : ''}>←</button>
                        <button class="preview-nav-btn next-btn" id="mobilePreviewNext" ${!nextFileId ? 'disabled' : ''}>→</button>
                    ` : ''}
                    <div class="preview-zoom-controls">
                        <button class="preview-zoom-btn" id="mobilePreviewZoomOut" title="缩小">−</button>
                        <button class="preview-zoom-btn" id="mobilePreviewZoomReset" title="重置">⌂</button>
                        <button class="preview-zoom-btn" id="mobilePreviewZoomIn" title="放大">+</button>
                    </div>
                </div>
            `;
        } else if (isVideo) {
            contentHtml = `
                <div class="preview-video-container">
                    <video controls class="preview-video">
                        <source src="${escapeHtml(previewUrl)}" type="${escapeHtml(mimeType)}">
                    </video>
                </div>
            `;
        } else if (isAudio) {
            contentHtml = `
                <div class="preview-audio-container">
                    <audio controls class="preview-audio">
                        <source src="${escapeHtml(previewUrl)}" type="${escapeHtml(mimeType)}">
                    </audio>
                </div>
            `;
        } else if (isPdf) {
            contentHtml = `
                <div class="preview-pdf-container">
                    <iframe src="${escapeHtml(previewUrl)}" class="preview-pdf" id="mobilePreviewPdf"></iframe>
                    <div class="preview-zoom-controls" id="pdfZoomControls">
                        <button class="preview-zoom-btn" id="mobilePreviewZoomOut" title="缩小">−</button>
                        <button class="preview-zoom-btn" id="mobilePreviewZoomReset" title="重置">⌂</button>
                        <button class="preview-zoom-btn" id="mobilePreviewZoomIn" title="放大">+</button>
                    </div>
                </div>
            `;
        } else {
            contentHtml = '<div class="file-empty-tip">不支持预览此文件类型</div>';
        }
        
        const modalHtml = `
            <div class="file-preview-modal" id="${modalId}">
                <div class="preview-header">
                    <button class="preview-close-btn" id="mobilePreviewClose">✕</button>
                    <div class="preview-title">${escapeHtml(file.filename)}</div>
                </div>
                <div class="preview-content">
                    ${contentHtml}
                </div>
                <div class="preview-footer">
                    <div class="preview-info">
                        ${formatSize(file.filesize)} · ${formatDate(file.uploaded_at)}
                    </div>
                    <a href="/api/customer_file_stream.php?id=${file.id}&mode=download" class="btn btn-primary btn-sm">下载</a>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = document.getElementById(modalId);
        
        // 显示模态框动画
        requestAnimationFrame(() => {
            modal.classList.add('show');
        });
        
        // 关闭模态框时清理资源
        function cleanupOnClose() {
            // 清除加载检查定时器
            if (previewLoadingTimer) {
                clearInterval(previewLoadingTimer);
                previewLoadingTimer = null;
            }
        }
        
        // 关闭函数（带动画和清理）
        function closeModal() {
            cleanupOnClose();
            modal.classList.remove('show');
            setTimeout(() => {
                modal.remove();
            }, 350);
        }
        
        // 关闭按钮
        const closeBtn = document.getElementById('mobilePreviewClose');
        closeBtn?.addEventListener('click', () => {
            closeModal();
        });
        
        // 点击背景关闭
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });
        
        // 图片预览控制
        if (isImage) {
            const img = document.getElementById('mobilePreviewImage');
            if (img) {
                // 确保图片可见
                img.style.display = 'block';
                img.style.opacity = '1';
                img.style.visibility = 'visible';
                
                // 添加图片加载错误处理
                img.onerror = function() {
                    console.error('图片加载失败:', previewUrl);
                    showToast('图片加载失败，请稍后重试', 'error');
                    // 保持显示占位符，不隐藏容器
                    this.style.opacity = '0.5';
                };
                
                // 添加图片加载成功处理
                img.onload = function() {
                    this.style.display = 'block';
                    this.style.opacity = '1';
                    this.style.visibility = 'visible';
                    console.log('图片加载成功:', previewUrl);
                };
                
                // 如果图片已经有src，确保显示
                if (img.src && img.complete && img.naturalWidth > 0) {
                    img.style.display = 'block';
                    img.style.opacity = '1';
                    img.style.visibility = 'visible';
                } else if (img.src) {
                    // 清除之前的加载检查定时器（防止重复）
                    if (previewLoadingTimer) {
                        clearInterval(previewLoadingTimer);
                        previewLoadingTimer = null;
                    }
                    
                    // 如果图片正在加载，等待加载完成
                    let checkCount = 0;
                    const maxChecks = 50; // 最多检查50次（5秒）
                    previewLoadingTimer = setInterval(() => {
                        checkCount++;
                        if (img.complete) {
                            clearInterval(previewLoadingTimer);
                            previewLoadingTimer = null;
                            if (img.naturalWidth > 0) {
                                img.style.display = 'block';
                                img.style.opacity = '1';
                                img.style.visibility = 'visible';
                            } else {
                                img.onerror();
                            }
                        } else if (checkCount >= maxChecks) {
                            // 超时
                            clearInterval(previewLoadingTimer);
                            previewLoadingTimer = null;
                            console.warn('图片加载超时:', previewUrl);
                            img.onerror();
                        }
                    }, 100);
                }
                let scale = 1;
                let startDistance = 0;
                let isDragging = false;
                let startX = 0;
                let startY = 0;
                let offsetX = 0;
                let offsetY = 0;
                
                // 获取容器尺寸，用于限制拖拽范围
                const container = img.parentElement;
                let containerWidth = container.clientWidth;
                let containerHeight = container.clientHeight;
                
                // 限制拖拽位置的函数
                const constrainPosition = () => {
                    const imgRect = img.getBoundingClientRect();
                    const containerRect = container.getBoundingClientRect();
                    const scaledWidth = imgRect.width;
                    const scaledHeight = imgRect.height;
                    
                    const maxX = Math.max(0, (scaledWidth - containerRect.width) / 2);
                    const maxY = Math.max(0, (scaledHeight - containerRect.height) / 2);
                    
                    offsetX = Math.max(-maxX, Math.min(maxX, offsetX));
                    offsetY = Math.max(-maxY, Math.min(maxY, offsetY));
                };
                
                // 触摸缩放
                img.addEventListener('touchstart', (e) => {
                    // 更新容器尺寸
                    containerWidth = container.clientWidth;
                    containerHeight = container.clientHeight;
                    
                    if (e.touches.length === 2) {
                        e.preventDefault();
                        // 显示缩放控制按钮
                        const zoomControls = document.querySelector('.preview-zoom-controls');
                        if (zoomControls) zoomControls.classList.add('show');
                        
                        const touch1 = e.touches[0];
                        const touch2 = e.touches[1];
                        startDistance = Math.hypot(
                            touch2.clientX - touch1.clientX,
                            touch2.clientY - touch1.clientY
                        );
                    } else if (e.touches.length === 1 && scale > 1) {
                        isDragging = true;
                        startX = e.touches[0].clientX - offsetX;
                        startY = e.touches[0].clientY - offsetY;
                    }
                });
                
                img.addEventListener('touchmove', (e) => {
                    if (e.touches.length === 2) {
                        e.preventDefault();
                        const touch1 = e.touches[0];
                        const touch2 = e.touches[1];
                        const distance = Math.hypot(
                            touch2.clientX - touch1.clientX,
                            touch2.clientY - touch1.clientY
                        );
                        const newScale = Math.max(1, Math.min(5, scale * (distance / startDistance)));
                        if (newScale !== scale) {
                            scale = newScale;
                            startDistance = distance;
                            img.style.transform = `scale(${scale}) translate(${offsetX}px, ${offsetY}px)`;
                            img.style.transition = 'none'; // 缩放时禁用过渡
                            updateZoomButtons();
                            constrainPosition();
                        }
                    } else if (e.touches.length === 1 && isDragging && scale > 1) {
                        e.preventDefault();
                        offsetX = e.touches[0].clientX - startX;
                        offsetY = e.touches[0].clientY - startY;
                        constrainPosition();
                        img.style.transform = `scale(${scale}) translate(${offsetX}px, ${offsetY}px)`;
                        img.style.transition = 'none'; // 拖拽时禁用过渡
                    }
                });
                
                // 双击检测
                let lastTap = 0;
                let tapTimer = null;
                
                img.addEventListener('touchend', (e) => {
                    isDragging = false;
                    img.style.transition = ''; // 恢复过渡效果
                    
                    const currentTime = new Date().getTime();
                    const tapLength = currentTime - lastTap;
                    
                    // 双击检测（300ms内两次点击）
                    if (tapLength < 300 && tapLength > 0 && !isDragging) {
                        // 清除之前的单次点击定时器
                        if (tapTimer) {
                            clearTimeout(tapTimer);
                            tapTimer = null;
                        }
                        
                        e.preventDefault();
                        // 双击操作
                        if (scale > 1) {
                            // 如果已放大，双击重置
                            resetZoom();
                        } else {
                            // 如果未放大，双击放大
                            scale = 2;
                            img.style.transition = 'transform 0.3s cubic-bezier(0.32, 0.72, 0, 1)';
                            img.style.transform = `scale(${scale})`;
                            updateZoomButtons();
                            const zoomControls = document.querySelector('.preview-zoom-controls');
                            if (zoomControls) zoomControls.classList.add('show');
                            setTimeout(() => {
                                img.style.transition = '';
                            }, 300);
                        }
                        lastTap = 0; // 重置，避免连续触发
                    } else {
                        // 单次点击
                        if (scale === 1) {
                            offsetX = 0;
                            offsetY = 0;
                            img.style.transform = '';
                            // 隐藏缩放控制按钮
                            const zoomControls = document.querySelector('.preview-zoom-controls');
                            if (zoomControls) zoomControls.classList.remove('show');
                        } else {
                            constrainPosition();
                            img.style.transform = `scale(${scale}) translate(${offsetX}px, ${offsetY}px)`;
                        }
                        lastTap = currentTime;
                    }
                });
                
                // 缩放控制按钮
                const updateZoomButtons = () => {
                    const zoomOutBtn = document.getElementById('mobilePreviewZoomOut');
                    const zoomInBtn = document.getElementById('mobilePreviewZoomIn');
                    const zoomResetBtn = document.getElementById('mobilePreviewZoomReset');
                    if (zoomOutBtn) zoomOutBtn.disabled = scale <= 1;
                    if (zoomInBtn) zoomInBtn.disabled = scale >= 5;
                };
                
                const resetZoom = () => {
                    scale = 1;
                    offsetX = 0;
                    offsetY = 0;
                    img.style.transform = '';
                    img.style.transition = 'transform 0.3s cubic-bezier(0.32, 0.72, 0, 1)';
                    updateZoomButtons();
                    const zoomControls = document.querySelector('.preview-zoom-controls');
                    if (zoomControls) zoomControls.classList.remove('show');
                    setTimeout(() => {
                        img.style.transition = '';
                    }, 300);
                };
                
                const zoomIn = () => {
                    if (scale < 5) {
                        const oldScale = scale;
                        scale = Math.min(5, scale * 1.5);
                        // 计算缩放中心点
                        const rect = img.getBoundingClientRect();
                        const centerX = rect.left + rect.width / 2;
                        const centerY = rect.top + rect.height / 2;
                        
                        img.style.transition = 'transform 0.25s cubic-bezier(0.32, 0.72, 0, 1)';
                        img.style.transformOrigin = 'center';
                        img.style.transform = `scale(${scale}) translate(${offsetX}px, ${offsetY}px)`;
                        updateZoomButtons();
                        
                        const zoomControls = document.querySelector('.preview-zoom-controls');
                        if (zoomControls) zoomControls.classList.add('show');
                        
                        setTimeout(() => {
                            img.style.transition = '';
                        }, 250);
                    }
                };
                
                const zoomOut = () => {
                    if (scale > 1) {
                        const oldScale = scale;
                        scale = Math.max(1, scale / 1.5);
                        
                        img.style.transition = 'transform 0.25s cubic-bezier(0.32, 0.72, 0, 1)';
                        
                        if (scale === 1) {
                            offsetX = 0;
                            offsetY = 0;
                            img.style.transform = '';
                            const zoomControls = document.querySelector('.preview-zoom-controls');
                            if (zoomControls) zoomControls.classList.remove('show');
                        } else {
                            img.style.transform = `scale(${scale}) translate(${offsetX}px, ${offsetY}px)`;
                        }
                        
                        updateZoomButtons();
                        
                        setTimeout(() => {
                            img.style.transition = '';
                        }, 250);
                    }
                };
                
                document.getElementById('mobilePreviewZoomIn')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    zoomIn();
                });
                
                document.getElementById('mobilePreviewZoomOut')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    zoomOut();
                });
                
                document.getElementById('mobilePreviewZoomReset')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    resetZoom();
                });
                
                updateZoomButtons();
            }
            
            // 上一张/下一张导航（防止并发请求）
            if (hasSiblings) {
                let isNavigating = false; // 防止快速点击导致多次请求
                
                const navigateToFile = (targetFileId) => {
                    if (!targetFileId || isNavigating) return;
                    
                    isNavigating = true;
                    
                    // 先清理资源
                    cleanupOnClose();
                    
                    // 关闭当前模态框
                    modal.classList.remove('show');
                    setTimeout(() => {
                        modal.remove();
                        // 延迟一下再加载新图片，避免并发过多
                        setTimeout(() => {
                            handlePreviewFile(targetFileId);
                            isNavigating = false;
                        }, 100);
                    }, 200);
                };
                
                document.getElementById('mobilePreviewPrev')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (prevFileId && !isNavigating) {
                        navigateToFile(prevFileId);
                    }
                });
                
                document.getElementById('mobilePreviewNext')?.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (nextFileId && !isNavigating) {
                        navigateToFile(nextFileId);
                    }
                });
                
                // 禁用左右滑动切换，只保留按钮切换（避免误触）
            }
        }
        
        // PDF预览缩放控制
        if (isPdf) {
            const iframe = document.getElementById('mobilePreviewPdf');
            let pdfZoom = 100;
            const minZoom = 50;
            const maxZoom = 300;
            
            const updatePdfZoom = () => {
                if (iframe) {
                    iframe.style.transition = 'transform 0.25s cubic-bezier(0.32, 0.72, 0, 1)';
                    iframe.style.transform = `scale(${pdfZoom / 100})`;
                    iframe.style.transformOrigin = 'top left';
                    // 调整容器大小以适配缩放
                    const container = iframe.closest('.preview-pdf-container');
                    if (container) {
                        container.style.width = `${pdfZoom}%`;
                        container.style.height = `${pdfZoom}%`;
                        container.style.overflow = 'auto';
                        container.style.transition = 'width 0.25s cubic-bezier(0.32, 0.72, 0, 1), height 0.25s cubic-bezier(0.32, 0.72, 0, 1)';
                    }
                    setTimeout(() => {
                        iframe.style.transition = '';
                        if (container) {
                            container.style.transition = '';
                        }
                    }, 250);
                }
            };
            
            const resetPdfZoom = () => {
                pdfZoom = 100;
                updatePdfZoom();
                updatePdfZoomButtons();
                const zoomControls = document.getElementById('pdfZoomControls');
                if (zoomControls) zoomControls.classList.remove('show');
            };
            
            const zoomInPdf = () => {
                if (pdfZoom < maxZoom) {
                    pdfZoom = Math.min(maxZoom, pdfZoom + 25);
                    updatePdfZoom();
                    updatePdfZoomButtons();
                    const zoomControls = document.getElementById('pdfZoomControls');
                    if (zoomControls) zoomControls.classList.add('show');
                }
            };
            
            const zoomOutPdf = () => {
                if (pdfZoom > minZoom) {
                    pdfZoom = Math.max(minZoom, pdfZoom - 25);
                    updatePdfZoom();
                    updatePdfZoomButtons();
                    if (pdfZoom === 100) {
                        const zoomControls = document.getElementById('pdfZoomControls');
                        if (zoomControls) zoomControls.classList.remove('show');
                    }
                }
            };
            
            const updatePdfZoomButtons = () => {
                const zoomOutBtn = document.getElementById('mobilePreviewZoomOut');
                const zoomInBtn = document.getElementById('mobilePreviewZoomIn');
                const zoomResetBtn = document.getElementById('mobilePreviewZoomReset');
                if (zoomOutBtn) zoomOutBtn.disabled = pdfZoom <= minZoom;
                if (zoomInBtn) zoomInBtn.disabled = pdfZoom >= maxZoom;
            };
            
            document.getElementById('mobilePreviewZoomIn')?.addEventListener('click', (e) => {
                e.stopPropagation();
                zoomInPdf();
            });
            
            document.getElementById('mobilePreviewZoomOut')?.addEventListener('click', (e) => {
                e.stopPropagation();
                zoomOutPdf();
            });
            
            document.getElementById('mobilePreviewZoomReset')?.addEventListener('click', (e) => {
                e.stopPropagation();
                resetPdfZoom();
            });
            
            updatePdfZoomButtons();
        }
        
        // 显示模态框
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);
    }
    
    // ========== 文件重命名功能 ==========
    function handleRenameFile(fileId) {
        let file = state.items.find(f => f.id === fileId);
        
        if (!file) {
            showToast('文件不存在');
            return;
        }
        
        showRenameDialog(fileId, file.filename);
    }
    
    function showRenameDialog(fileId, currentFilename) {
        const dialogId = 'mobileRenameDialog';
        const existingDialog = document.getElementById(dialogId);
        if (existingDialog) {
            existingDialog.remove();
        }
        
        const dotPos = currentFilename.lastIndexOf('.');
        const nameWithoutExt = dotPos > 0 ? currentFilename.substring(0, dotPos) : currentFilename;
        const ext = dotPos > 0 ? currentFilename.substring(dotPos) : '';
        
        const dialogHtml = `
            <div class="rename-dialog" id="${dialogId}">
                <div class="dialog-overlay"></div>
                <div class="dialog-content">
                    <h3 class="dialog-title">重命名文件</h3>
                    <div class="rename-input-group">
                        <input type="text" class="form-input rename-input" id="mobileRenameInput" value="${escapeHtml(nameWithoutExt)}" autocomplete="off">
                        ${ext ? `<span class="file-extension">${escapeHtml(ext)}</span>` : ''}
                    </div>
                    <div class="dialog-actions">
                        <button class="btn btn-outline" id="mobileRenameCancel">取消</button>
                        <button class="btn btn-primary" id="mobileRenameConfirm">确认</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', dialogHtml);
        const dialog = document.getElementById(dialogId);
        const input = document.getElementById('mobileRenameInput');
        const cancelBtn = document.getElementById('mobileRenameCancel');
        const confirmBtn = document.getElementById('mobileRenameConfirm');
        
        // 自动聚焦并选中文本
        input.focus();
        input.select();
        
        const closeDialog = () => {
            dialog.remove();
        };
        
        // 关闭按钮
        cancelBtn.addEventListener('click', closeDialog);
        dialog.querySelector('.dialog-overlay')?.addEventListener('click', closeDialog);
        
        // 确认按钮
        confirmBtn.addEventListener('click', () => {
            const newName = input.value.trim();
            if (!newName) {
                showToast('文件名不能为空');
                return;
            }
            
            // 验证文件名（不能包含特殊字符）
            const invalidChars = /[<>:"/\\|?*]/;
            if (invalidChars.test(newName)) {
                showToast('文件名不能包含以下字符: < > : " / \\ | ? *');
                return;
            }
            
            const fullName = newName + ext;
            if (fullName === currentFilename) {
                closeDialog();
                return;
            }
            
            confirmBtn.disabled = true;
            const formData = new FormData();
            formData.append('action', 'rename_file');
            formData.append('file_id', fileId);
            formData.append('new_name', fullName);
            
            fetch('/api/customer_file_rename.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('重命名成功');
                    closeDialog();
                    loadFiles();
                } else {
                    throw new Error(data.message || '重命名失败');
                }
            })
            .catch(err => {
                showToast('重命名失败: ' + err.message);
                confirmBtn.disabled = false;
            });
        });
        
        // 回车确认
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                confirmBtn.click();
            }
        });
        
        // 显示对话框
        setTimeout(() => {
            dialog.classList.add('show');
        }, 10);
    }
    
    // ========== 文件分享功能 ==========
    function handleShareFile(fileId) {
        fetch('/api/file_link.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get&file_id=${encodeURIComponent(fileId)}`,
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data) {
                showShareModal(fileId, data.data, data.share_url);
            } else {
                showShareModal(fileId, null, null);
            }
        })
        .catch(err => {
            showToast('加载分享链接信息失败: ' + err.message);
        });
    }
    
    function showShareModal(fileId, linkData, shareUrl) {
        const modalId = 'mobileShareModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        const baseUrl = window.location.origin;
        const modalHtml = `
            <div class="share-modal" id="${modalId}">
                <div class="modal-overlay"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">分享文件</h3>
                        <button class="modal-close" id="mobileShareClose">✕</button>
                    </div>
                    <div class="modal-body">
                        ${linkData ? `
                            <div class="share-link-display">
                                <input type="text" class="form-input" id="mobileShareLinkInput" value="${escapeHtml(shareUrl || '')}" readonly>
                                <button class="btn btn-primary" id="mobileCopyLinkBtn">复制</button>
                            </div>
                            <div class="share-options">
                                <div class="option-row">
                                    <label>启用分享</label>
                                    <label class="switch">
                                        <input type="checkbox" id="mobileShareEnabledSwitch" ${linkData.enabled ? 'checked' : ''}>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="option-row">
                                    <label>密码保护</label>
                                    <input type="text" class="form-input" id="mobileSharePasswordInput" 
                                           placeholder="可选" value="${escapeHtml(linkData.password || '')}">
                                </div>
                            </div>
                            <div class="modal-actions">
                                <button class="btn btn-primary" id="mobileUpdateShareBtn">保存设置</button>
                                <button class="btn btn-danger" id="mobileDeleteShareBtn">删除链接</button>
                            </div>
                        ` : `
                            <div class="share-empty">
                                <p>该文件还未生成分享链接</p>
                                <button class="btn btn-primary" id="mobileCreateShareBtn">生成分享链接</button>
                            </div>
                        `}
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = document.getElementById(modalId);
        
        // 关闭按钮
        const closeBtn = document.getElementById('mobileShareClose');
        closeBtn?.addEventListener('click', () => {
            modal.remove();
        });
        modal.querySelector('.modal-overlay')?.addEventListener('click', () => {
            modal.remove();
        });
        
        if (linkData) {
            // 复制链接
            document.getElementById('mobileCopyLinkBtn')?.addEventListener('click', () => {
                const input = document.getElementById('mobileShareLinkInput');
                if (input) {
                    input.select();
                    document.execCommand('copy');
                    showToast('链接已复制');
                }
            });
            
            // 更新设置
            document.getElementById('mobileUpdateShareBtn')?.addEventListener('click', () => {
                const enabled = document.getElementById('mobileShareEnabledSwitch')?.checked ? 1 : 0;
                const password = document.getElementById('mobileSharePasswordInput')?.value.trim() || '';
                
                fetch('/api/file_link.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=update&file_id=${encodeURIComponent(fileId)}&enabled=${enabled}&password=${encodeURIComponent(password)}`,
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('设置已更新');
                        setTimeout(() => handleShareFile(fileId), 500);
                    } else {
                        throw new Error(data.message || '更新失败');
                    }
                })
                .catch(err => {
                    showToast('更新失败: ' + err.message);
                });
            });
            
            // 删除链接
            document.getElementById('mobileDeleteShareBtn')?.addEventListener('click', () => {
                showMobileConfirm('确定要删除此文件的分享链接吗？', function() {
                    fetch('/api/file_link.php', {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=delete&file_id=${encodeURIComponent(fileId)}`,
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showToast('分享链接已删除');
                            modal.remove();
                        } else {
                            throw new Error(data.message || '删除失败');
                        }
                    })
                    .catch(err => {
                        showToast('删除失败: ' + err.message);
                    });
                });
            });
        } else {
            // 创建链接
            document.getElementById('mobileCreateShareBtn')?.addEventListener('click', () => {
                fetch('/api/file_link.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=create&file_id=${encodeURIComponent(fileId)}&enabled=1&org_permission=edit&password_permission=editable`,
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('分享链接生成成功');
                        modal.remove();
                        setTimeout(() => handleShareFile(fileId), 500);
                    } else {
                        throw new Error(data.message || '生成失败');
                    }
                })
                .catch(err => {
                    showToast('生成失败: ' + err.message);
                });
            });
        }
        
        // 显示模态框
        setTimeout(() => {
            modal.classList.add('show');
        }, 10);
    }
    
    // 添加加载动画样式
    if (!document.getElementById('mobile-file-management-styles')) {
        const style = document.createElement('style');
        style.id = 'mobile-file-management-styles';
        style.textContent = `
            @keyframes loading {
                0% { transform: translateX(-100%); }
                100% { transform: translateX(400%); }
            }
        `;
        document.head.appendChild(style);
    }
    
})();

