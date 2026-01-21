// 客户文件树形浏览与下载交互
(function () {
    if (window.__CUSTOMER_FILES_APP_INITED) {
        return;
    }
    window.__CUSTOMER_FILES_APP_INITED = true;
    const PAGE_SIZE = 10;
    const app = document.getElementById('customerFilesApp');
    if (!app) return;

    const CATEGORY_MAP = {
        customer: 'client_material',
        company: 'internal_solution',
    };
    const CATEGORY_LABEL = {
        customer: '客户文件',
        company: '公司文件',
    };
    const folderInputProbe = document.createElement('input');
    const supportsFolderUpload = 'webkitdirectory' in folderInputProbe;

    const state = {
        customerId: parseInt(app.dataset.customerId, 10),
        canManage: app.dataset.canManage === '1',
        limits: {
            maxFiles: parseInt(app.dataset.maxFiles, 10) || 500,
            maxBytes: parseInt(app.dataset.maxBytes, 10) || (2 * 1024 * 1024 * 1024),
            maxSingleSize: parseInt(app.dataset.maxSingleSize, 10) || (2 * 1024 * 1024 * 1024),
            maxDepth: parseInt(app.dataset.maxDepth, 10) || 5,
            maxSegmentLength: parseInt(app.dataset.maxSegment, 10) || 40,
            limitHint: app.dataset.folderLimitHint || '',
        },
        uploadQueue: [],
        trees: {
            customer: createTreeState('customer'),
            company: createTreeState('company'),
        },
        views: {
            customer: createViewState(),
            company: createViewState(),
        },
    };

    const columnEls = {};
    app.querySelectorAll('[data-role="file-column"]').forEach((column) => {
        const type = column.dataset.type;
        const fileBrowser = column.querySelector('[data-role="file-browser"]');
        columnEls[type] = {
            treeContainer: fileBrowser ? fileBrowser.querySelector('[data-role="file-tree-container"]') : null,
            breadcrumb: fileBrowser ? fileBrowser.querySelector('[data-role="folder-breadcrumb"]') : null,
            viewSwitch: fileBrowser ? fileBrowser.querySelector('[data-role="view-switch"]') : null,
            searchInput: fileBrowser ? fileBrowser.querySelector('[data-role="file-search"]') : null,
            downloadCurrent: fileBrowser ? fileBrowser.querySelector('[data-action="download-current"]') : null,
            downloadSelected: fileBrowser ? fileBrowser.querySelector('[data-action="download-selected"]') : null,
            deleteSelected: fileBrowser ? fileBrowser.querySelector('[data-action="delete-selected"]') : null,
            selectAll: fileBrowser ? fileBrowser.querySelector('[data-role="select-all"]') : null,
            pagination: fileBrowser ? fileBrowser.querySelector('[data-role="file-pagination"]') : null,
            pageInfo: fileBrowser ? fileBrowser.querySelector('[data-role="page-info"]') : null,
            uploadZone: column.querySelector('[data-role="upload-zone"]'),
            uploadInput: column.querySelector('[data-role="upload-input"]'),
            uploadProgress: column.querySelector('[data-role="upload-progress"]'),
            folderInput: column.querySelector('[data-role="upload-folder-input"]'),
            folderButton: column.querySelector('[data-role="upload-folder-button"]'),
            folderSupport: column.querySelector('[data-role="folder-support-tip"]'),
        };
    });

    const searchTimers = {
        customer: null,
        company: null,
    };
    const uploadGuards = {
        customer: { signature: null, timer: null },
        company: { signature: null, timer: null },
    };
    let folderInputsBound = false;
    let uploadZonesBound = false;

    init();

    function init() {
        // 如果是新增客户（customerId 为 0），不加载文件数据
        if (state.customerId <= 0) {
            showNewCustomerMessage();
            return;
        }
        
        bindGlobalEvents();
        bindUploadZones();
        
        // 参考手机版：直接初始化并加载数据，不依赖 tab 激活状态
        // 如果 tab 未激活，数据仍会加载，只是不显示
        console.log('[CustomerFiles] 初始化文件管理模块');
        loadAllData();
    }
    
    function loadAllData() {
        console.log('[CustomerFiles] loadAllData 被调用');
        // 确保 columnEls 已正确初始化
        if (!columnEls.customer || !columnEls.customer.treeContainer) {
            console.warn('[CustomerFiles] columnEls 未正确初始化，重新初始化');
            // 重新初始化 columnEls
            app.querySelectorAll('[data-role="file-column"]').forEach((column) => {
                const type = column.dataset.type;
                const fileBrowser = column.querySelector('[data-role="file-browser"]');
                if (!columnEls[type]) {
                    columnEls[type] = {};
                }
                columnEls[type].treeContainer = fileBrowser ? fileBrowser.querySelector('[data-role="file-tree-container"]') : null;
                columnEls[type].breadcrumb = fileBrowser ? fileBrowser.querySelector('[data-role="folder-breadcrumb"]') : null;
                columnEls[type].viewSwitch = fileBrowser ? fileBrowser.querySelector('[data-role="view-switch"]') : null;
                columnEls[type].searchInput = fileBrowser ? fileBrowser.querySelector('[data-role="file-search"]') : null;
                columnEls[type].downloadCurrent = fileBrowser ? fileBrowser.querySelector('[data-action="download-current"]') : null;
                columnEls[type].downloadSelected = fileBrowser ? fileBrowser.querySelector('[data-action="download-selected"]') : null;
                columnEls[type].deleteSelected = fileBrowser ? fileBrowser.querySelector('[data-action="delete-selected"]') : null;
                columnEls[type].pagination = fileBrowser ? fileBrowser.querySelector('[data-role="file-pagination"]') : null;
                columnEls[type].pageInfo = fileBrowser ? fileBrowser.querySelector('[data-role="page-info"]') : null;
                columnEls[type].uploadZone = column.querySelector('[data-role="upload-zone"]');
                columnEls[type].uploadInput = column.querySelector('[data-role="upload-input"]');
                columnEls[type].uploadProgress = column.querySelector('[data-role="upload-progress"]');
            });
            console.log('[CustomerFiles] columnEls 重新初始化完成:', columnEls);
        }
        
        if (state.customerId <= 0) {
            console.warn('[CustomerFiles] customerId 无效，无法加载数据');
            showNewCustomerMessage();
            return;
        }
        
        console.log('[CustomerFiles] 开始加载文件数据，customerId:', state.customerId);
        // 桌面版重点：先加载文件夹树结构，这是核心功能
        // 然后加载根目录下的文件列表
        Object.keys(CATEGORY_MAP).forEach((type) => {
            console.log(`[CustomerFiles] 加载 ${type} 类型的数据`);
            // 先加载文件夹树（桌面版的核心功能）
            fetchTree(type, '');
            // 然后加载根目录的文件列表
            loadFiles(type, { resetPage: true });
        });
    }

    function showNewCustomerMessage() {
        // 显示提示信息：需要先保存客户才能上传文件
        Object.keys(CATEGORY_MAP).forEach((type) => {
            const column = columnEls[type];
            if (column && column.treeContainer) {
                column.treeContainer.innerHTML = `
                    <div class="file-empty-tip" style="padding: 60px 20px;">
                        <div style="font-size: 48px; margin-bottom: 16px;">📁</div>
                        <div style="font-size: 16px; color: #6b7280; margin-bottom: 8px;">
                            请先保存客户信息
                        </div>
                        <div style="font-size: 14px; color: #94a3b8;">
                            保存客户后即可上传和管理文件
                        </div>
                    </div>
                `;
            }
        });
    }

    function createTreeState(type) {
        return {
            nodes: {
                '': {
                    fullPath: '',
                    parent: null,
                    label: CATEGORY_LABEL[type],
                    hasChildren: true,
                    children: [],
                    childrenLoaded: false,
                    stats: null,
                    breadcrumbs: [{ label: CATEGORY_LABEL[type], full_path: '' }],
                },
            },
            expanded: new Set(['']),
            loading: new Set(),
        };
    }

    function createViewState() {
        return {
            folderPath: '',
            includeChildren: true,
            keyword: '',
            page: 1,
            pageSize: PAGE_SIZE,
            total: 0,
            items: [],
            loading: false,
            error: '',
            selected: new Set(),
        };
    }

    function bindGlobalEvents() {
        app.addEventListener('click', (event) => {
            const target = event.target;
            const refreshFilesBtn = target.closest('[data-action="refresh-files"]');
            if (refreshFilesBtn) {
                refreshAll();
                return;
            }

            const deleteBtn = target.closest('[data-action="delete"]');
            if (deleteBtn) {
                const fileId = deleteBtn.dataset.id;
                if (fileId) {
                    showConfirm('确定要删除该文件吗？删除后可在15天内恢复。', () => handleDelete(fileId));
                }
                return;
            }

            const downloadBtn = target.closest('[data-action="download"]');
            if (downloadBtn) {
                const fileId = downloadBtn.dataset.id;
                if (fileId) {
                    window.open(`/api/customer_file_stream.php?id=${fileId}&mode=download`, '_blank');
                }
                return;
            }

            const renameBtn = target.closest('[data-action="rename"]');
            if (renameBtn) {
                const fileId = parseInt(renameBtn.dataset.id, 10);
                if (fileId > 0) {
                    handleRenameFile(fileId);
                }
                return;
            }

            const previewBtn = target.closest('[data-action="preview"]');
            if (previewBtn) {
                const fileId = parseInt(previewBtn.dataset.id, 10);
                if (fileId > 0) {
                    handlePreviewFile(fileId);
                }
                return;
            }

            const shareBtn = target.closest('[data-action="share"]');
            if (shareBtn) {
                const fileId = shareBtn.dataset.id;
                if (fileId) {
                    handleFileShare(fileId);
                }
                return;
            }

            const downloadCurrentBtn = target.closest('[data-action="download-current"]');
            if (downloadCurrentBtn) {
                const type = downloadCurrentBtn.dataset.type;
                if (type) {
                    triggerDownload(type);
                }
                return;
            }

            const downloadSelectedBtn = target.closest('[data-action="download-selected"]');
            if (downloadSelectedBtn) {
                const type = downloadSelectedBtn.dataset.type;
                if (type) {
                    triggerDownload(type, true);
                }
                return;
            }

            const deleteSelectedBtn = target.closest('[data-action="delete-selected"]');
            if (deleteSelectedBtn) {
                const type = deleteSelectedBtn.dataset.type;
                if (type) {
                    handleBatchDelete(type);
                }
                return;
            }

            const refreshTreeBtn = target.closest('[data-action="refresh-tree"]');
            if (refreshTreeBtn) {
                const type = refreshTreeBtn.dataset.type;
                if (type) {
                    refreshTree(type);
                }
                return;
            }

            const renameFolderBtn = target.closest('[data-action="rename-folder"]');
            if (renameFolderBtn) {
                const type = renameFolderBtn.dataset.type;
                const path = renameFolderBtn.dataset.path || '';
                if (type && path) {
                    const segments = path.split('/').filter(Boolean);
                    const folderName = segments[segments.length - 1] || path;
                    showRenameDialog(path, folderName, 'folder');
                }
                return;
            }

            // 文件夹展开/折叠事件已由bindTreeEvents处理
            // 文件操作按钮
            if (target.matches('[data-action="download"], [data-action="preview"], [data-action="rename"], [data-action="share"], [data-action="delete"]')) {
                const fileId = parseInt(target.dataset.id, 10);
                const action = target.dataset.action;
                if (!fileId) return;
                
                if (action === 'download') {
                    window.open(`/api/customer_file_download.php?id=${fileId}`, '_blank');
                } else if (action === 'preview') {
                    handlePreview(fileId);
                } else if (action === 'rename') {
                    handleRename(fileId);
                } else if (action === 'share') {
                    handleFileShare(fileId);
                } else if (action === 'delete') {
                    handleDelete(fileId);
                }
                return;
            }

            const viewModeBtn = target.closest('[data-view-mode]');
            if (viewModeBtn) {
                const type = viewModeBtn.dataset.type;
                if (type) {
                    updateViewMode(type, viewModeBtn.dataset.viewMode === 'include');
                }
                return;
            }

            const paginationBtn = target.closest('[data-direction]');
            if (paginationBtn) {
                const type = paginationBtn.dataset.type;
                if (!type) return;
                // 检查按钮是否被禁用
                if (paginationBtn.disabled) return;
                const direction = paginationBtn.dataset.direction;
                changePage(type, direction);
                return;
            }
        });

        app.addEventListener('change', (event) => {
            const target = event.target;
            if (target.matches('[data-role="select-file-item"]')) {
                const type = target.dataset.type;
                const fileId = parseInt(target.dataset.id, 10);
                if (type && fileId) {
                    toggleSelection(type, fileId, target.checked);
                }
            }
        });

        app.addEventListener('input', (event) => {
            const target = event.target;
            if (target.matches('[data-role="file-search"]')) {
                const type = target.dataset.type;
                if (!type) return;
                clearTimeout(searchTimers[type]);
                searchTimers[type] = setTimeout(() => {
                    state.views[type].keyword = target.value.trim();
                    loadFiles(type, { resetPage: true });
                }, 300);
            }
        });
    }

    function refreshAll() {
        Object.keys(CATEGORY_MAP).forEach((type) => {
            refreshTree(type);
            loadFiles(type, { resetPage: false });
        });
    }
    
    // 暴露refreshAll到全局，供外部调用
    window.refreshFileList = function() {
        console.log('[CustomerFiles] refreshFileList被调用，刷新所有文件数据');
        // 参考手机版：直接刷新，不依赖 tab 激活状态
        refreshAll();
    };

    function refreshTree(type) {
        const view = state.views[type];
        fetchTree(type, '');
        if (view.folderPath && view.folderPath !== '') {
            fetchTree(type, view.folderPath);
        }
    }

    function fetchTree(type, parentPath) {
        // 如果是新增客户（customerId 为 0），不调用 API
        if (state.customerId <= 0) {
            return;
        }
        
        const treeState = state.trees[type];
        if (!treeState || treeState.loading.has(parentPath)) {
            return;
        }
        treeState.loading.add(parentPath);
        renderTree(type);
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[type],
            tree: '1',
            parent_path: parentPath || '',
        });
        fetch(`/api/customer_files.php?${params.toString()}`, { credentials: 'include' })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                return res.json();
            })
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.message || '加载目录失败');
                }
                applyTreeData(type, parentPath, data.data);
                console.log(`[CustomerFiles] ${type} 目录树加载成功: ${parentPath || '根目录'}`);
            })
            .catch((err) => {
                console.error(`[CustomerFiles] ${type} 目录树加载失败 (${parentPath || '根目录'}):`, err);
                showToast(err.message || '加载目录失败', 'error');
            })
            .finally(() => {
                treeState.loading.delete(parentPath);
                renderTree(type);
            });
    }

    function applyTreeData(type, parentPath, payload) {
        const treeState = state.trees[type];
        if (!treeState) return;
        const parentNode = treeState.nodes[parentPath] || {
            fullPath: parentPath,
            parent: resolveParentPath(parentPath),
            label: pathLabel(type, parentPath),
            children: [],
        };
        parentNode.hasChildren = payload.node?.has_children ?? parentNode.hasChildren;
        parentNode.stats = payload.node || parentNode.stats;
        parentNode.children = [];
        parentNode.childrenLoaded = true;
        parentNode.breadcrumbs = payload.node?.breadcrumbs || buildFallbackBreadcrumbs(type, parentPath);
        treeState.nodes[parentPath] = parentNode;

        (payload.children || []).forEach((child) => {
            parentNode.children.push(child.full_path);
            const existing = treeState.nodes[child.full_path] || {
                fullPath: child.full_path,
                parent: parentPath,
                children: [],
            };
            treeState.nodes[child.full_path] = {
                ...existing,
                fullPath: child.full_path,
                parent: parentPath,
                label: child.label || pathLabel(type, child.full_path),
                stats: child,
                hasChildren: child.has_children,
                children: existing.children || [],
                childrenLoaded: existing.childrenLoaded ?? false,
                breadcrumbs: buildFallbackBreadcrumbs(type, child.full_path),
            };
        });
        renderTree(type);
        updateBreadcrumb(type);
    }

    function renderTree(type) {
        // 树形结构现在由renderList统一渲染
        renderList(type);
    }

    function selectTreeNode(type, path) {
        state.views[type].folderPath = path;
        state.views[type].page = 1;
        ensureExpanded(type, path);
        updateBreadcrumb(type);
        loadFiles(type, { resetPage: true });
    }

    function toggleTreeNode(type, path) {
        const treeState = state.trees[type];
        const node = treeState.nodes[path];
        if (!node || !node.hasChildren) {
            return;
        }
        if (treeState.expanded.has(path)) {
            treeState.expanded.delete(path);
        } else {
            treeState.expanded.add(path);
            if (!node.childrenLoaded) {
                fetchTree(type, path);
            }
        }
        renderList(type);
    }

    function ensureExpanded(type, path) {
        const treeState = state.trees[type];
        if (!path) {
            treeState.expanded.add('');
            return;
        }
        const segments = path.split('/').filter(Boolean);
        let current = '';
        treeState.expanded.add('');
        segments.forEach((segment) => {
            current = current ? `${current}/${segment}` : segment;
            treeState.expanded.add(current);
            if (!treeState.nodes[current]?.childrenLoaded) {
                fetchTree(type, current);
            }
        });
    }

    function updateBreadcrumb(type) {
        const column = columnEls[type];
        if (!column || !column.breadcrumb) return;
        const view = state.views[type];
        const treeState = state.trees[type];
        const node = treeState.nodes[view.folderPath] || null;
        const crumbs = node?.breadcrumbs || buildFallbackBreadcrumbs(type, view.folderPath);
        column.breadcrumb.innerHTML = crumbs
            .map((item, index) => {
                if (index === crumbs.length - 1) {
                    return `<span class="breadcrumb-item active">${escapeHtml(item.label)}</span>`;
                }
                return `<span class="breadcrumb-item"><button type="button" data-breadcrumb="${escapeHtml(item.full_path)}" data-type="${type}">${escapeHtml(item.label)}</button></span>`;
            })
            .join('');

        column.breadcrumb.querySelectorAll('[data-breadcrumb]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const targetPath = btn.dataset.breadcrumb || '';
                selectTreeNode(type, targetPath);
            });
        });
    }

    function loadFiles(type, options = {}) {
        // 如果是新增客户（customerId 为 0），不调用 API
        if (state.customerId <= 0) {
            return;
        }
        
        const view = state.views[type];
        if (options.resetPage) {
            view.page = 1;
        }
        view.loading = true;
        view.error = '';
        if (columnEls[type] && columnEls[type].treeContainer) {
            columnEls[type].treeContainer.innerHTML = '<div class="file-tree-loading">加载中...</div>';
        }
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[type],
            page: view.page,
            page_size: view.pageSize,
            include_children: view.includeChildren ? '1' : '0',
            keyword: view.keyword || '',
            folder_path: view.folderPath ?? '',
        });

        console.log(`[CustomerFiles] 开始加载 ${type} 文件，参数:`, params.toString(), 'includeChildren:', view.includeChildren, 'folderPath:', view.folderPath);
        fetch(`/api/customer_files.php?${params.toString()}`, { credentials: 'include' })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                return res.json().catch((err) => {
                    console.error(`[CustomerFiles] JSON 解析失败:`, err);
                    throw new Error('服务器响应格式错误');
                });
            })
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.message || '加载文件失败');
                }
                const payload = data.data || {};
                view.items = payload.items || [];
                view.total = payload.pagination?.total || 0;
                view.page = payload.pagination?.page || view.page;
                view.pageSize = PAGE_SIZE;
                trimSelection(type);
                console.log(`[CustomerFiles] ${type} 文件加载成功，共 ${view.items.length} 个文件，总计 ${view.total} 个`);
            })
            .catch((err) => {
                console.error(`[CustomerFiles] ${type} 文件加载失败:`, err);
                view.error = err.message || '加载文件失败';
                view.items = [];
                view.total = 0;
                showToast(view.error, 'error');
            })
            .finally(() => {
                view.loading = false;
                renderList(type);
                updateActionButtons(type);
            });
    }

    function renderList(type) {
        const column = columnEls[type];
        const view = state.views[type];
        const treeState = state.trees[type];
        if (!column || !column.treeContainer) return;

        if (view.loading) {
            column.treeContainer.innerHTML = '<div class="file-tree-loading">加载中...</div>';
            return;
        }

        if (view.error) {
            column.treeContainer.innerHTML = `<div class="file-empty-tip text-danger">${escapeHtml(view.error)}</div>`;
            updatePagination(type);
            return;
        }

        // 构建树形结构：文件夹 + 文件
        const fragments = [];
        const rootNode = treeState.nodes[''] || { fullPath: '', children: [], label: pathLabel(type, '') };
        const currentPath = view.folderPath || '';
        
        // 遍历文件夹树 - 桌面版重点：以文件夹结构为核心
        function renderTreeNodes(path, level) {
            const node = treeState.nodes[path];
            if (!node) return;
            
            const isExpanded = treeState.expanded.has(path);
            const isSelected = currentPath === path;
            const hasChildren = node.hasChildren;
            
            // 显示文件夹节点（无论是否选中都要显示）
            fragments.push(renderFolderNode(type, node, level, isExpanded, isSelected, hasChildren));
            
            // 如果是当前选中的文件夹且已展开
            if (isSelected && isExpanded) {
                // 先递归显示所有子文件夹（文件夹在前面）
                if (hasChildren) {
                    (node.children || []).forEach((childPath) => {
                        renderTreeNodes(childPath, level + 1);
                    });
                }
                
                // 再显示该文件夹下的文件（文件在后面）
                if (view.items.length > 0) {
                    view.items.forEach((file) => {
                        // 对于根目录，需要检查文件是否真的在根目录（没有 folder_path 或为空）
                        // 对于非根目录，需要检查文件的 folder_path 是否匹配当前路径
                        const filePath = (file.folder_path || '').trim();
                        const shouldShow = path === '' 
                            ? !filePath  // 根目录：只显示 folder_path 为空的文件
                            : filePath === path;  // 非根目录：只显示 folder_path 匹配的文件
                        
                        if (shouldShow) {
                            const checked = view.selected.has(file.id);
                            // 文件显示在对应文件夹下，缩进层级 +1
                            fragments.push(renderFileItem(type, file, level + 1, checked));
                        }
                    });
                }
            } else {
                // 如果不是当前文件夹，只递归显示子文件夹（如果已展开）
                if (hasChildren && isExpanded) {
                    (node.children || []).forEach((childPath) => {
                        renderTreeNodes(childPath, level + 1);
                    });
                }
            }
        }
        
        // 如果没有文件且没有文件夹，显示空状态
        if (!view.items.length && (!rootNode.hasChildren || rootNode.children.length === 0)) {
            column.treeContainer.innerHTML = '<div class="file-empty-tip">暂无文件</div>';
        } else {
            renderTreeNodes('', 0);
            column.treeContainer.innerHTML = fragments.join('');
        }
        
        // 绑定事件
        bindTreeEvents(type);
        updatePagination(type);
        updateSelectionIndicators(type);
    }
    
    function renderFolderNode(type, node, level, isExpanded, isSelected, hasChildren) {
        const loading = state.trees[type].loading.has(node.fullPath);
        const fileCount = node.stats ? (node.stats.file_count_total || 0) : 0;
        const toggleClass = isExpanded ? 'expanded' : '';
        const childrenClass = isExpanded ? '' : 'collapsed';
        
        return `
            <div class="file-tree-folder" data-level="${level}" data-path="${escapeHtml(node.fullPath)}" data-type="${type}">
                <div class="folder-header" data-selected="${isSelected ? '1' : '0'}" data-has-children="${hasChildren ? '1' : '0'}">
                    <span class="folder-toggle ${toggleClass}">▶</span>
                    <span class="folder-icon">📁</span>
                    <span class="folder-name">${escapeHtml(node.label || pathLabel(type, node.fullPath))}</span>
                    <span class="folder-count">(${loading ? '…' : fileCount})</span>
                </div>
                ${hasChildren ? `<div class="folder-children ${childrenClass}"></div>` : ''}
            </div>
        `;
    }
    
    function renderFileItem(type, file, level, checked) {
        return `
            <div class="file-tree-item" data-level="${level}" data-file-id="${file.id}" data-type="${type}" data-selected="${checked ? '1' : '0'}">
                <input type="checkbox" data-role="select-file-item" data-type="${type}" data-id="${file.id}" ${checked ? 'checked' : ''}>
                ${file.thumbnail_url ? `
                    <img src="${escapeHtml(file.thumbnail_url)}" 
                         alt="${escapeHtml(file.filename)}" 
                         class="file-thumbnail"
                         onerror="this.onerror=null; this.style.display='none'; const placeholder=this.nextElementSibling; if(placeholder && placeholder.classList.contains('file-icon-placeholder')) placeholder.style.display='flex';">
                    <div class="file-icon-placeholder" style="display: none;">📄</div>
                ` : `
                    <div class="file-icon-placeholder">📄</div>
                `}
                <div class="file-info">
                    <div class="file-name">${escapeHtml(file.filename)}</div>
                    <div class="file-meta">
                        <span>${formatSize(file.filesize)}</span>
                        <span>•</span>
                        <span>${formatDate(file.uploaded_at)}</span>
                        ${file.uploaded_by_name ? `<span>•</span><span>${escapeHtml(file.uploaded_by_name)}</span>` : ''}
                    </div>
                </div>
                <div class="file-actions">
                    <button type="button" data-action="download" data-id="${file.id}">下载</button>
                    ${file.preview_supported ? `<button type="button" data-action="preview" data-id="${file.id}">预览</button>` : ''}
                    ${state.canManage ? `<button type="button" data-action="rename" data-id="${file.id}">重命名</button>` : ''}
                    ${state.canManage ? `<button type="button" data-action="share" data-id="${file.id}">分享</button>` : ''}
                    ${state.canManage ? `<button type="button" class="delete" data-action="delete" data-id="${file.id}">删除</button>` : ''}
                </div>
            </div>
        `;
    }
    
    function bindTreeEvents(type) {
        const column = columnEls[type];
        if (!column || !column.treeContainer) return;
        
        // 文件夹展开/折叠
        column.treeContainer.querySelectorAll('.folder-header').forEach((header) => {
            header.addEventListener('click', (e) => {
                e.stopPropagation();
                const folder = header.closest('.file-tree-folder');
                if (!folder) return;
                const path = folder.dataset.path || '';
                const hasChildren = header.dataset.hasChildren === '1';
                
                if (hasChildren) {
                    toggleTreeNode(type, path);
                } else {
                    selectTreeNode(type, path);
                }
            });
        });
    }

    function updatePagination(type) {
        const column = columnEls[type];
        const view = state.views[type];
        if (!column || !column.pagination) return;
        const totalPages = Math.max(1, Math.ceil(view.total / view.pageSize));
        column.pageInfo && (column.pageInfo.textContent = `第 ${view.page} / ${totalPages} 页 · 共 ${view.total} 个`);
        const buttons = column.pagination.querySelectorAll('[data-direction]');
        buttons.forEach((btn) => {
            const dir = btn.dataset.direction;
            if (dir === 'prev') {
                btn.disabled = view.page <= 1;
            } else {
                btn.disabled = view.page >= totalPages;
            }
        });
    }

    function updateSelectionIndicators(type) {
        const column = columnEls[type];
        const view = state.views[type];
        // selectAll checkbox已移除，不再需要更新
        updateActionButtons(type);
    }

    function handleSelectAll(type, checked) {
        const view = state.views[type];
        if (checked) {
            view.items.forEach((file) => view.selected.add(file.id));
        } else {
            view.items.forEach((file) => view.selected.delete(file.id));
        }
        renderList(type);
        updateSelectionIndicators(type);
    }

    function toggleSelection(type, fileId, checked) {
        const view = state.views[type];
        if (checked) {
            view.selected.add(fileId);
        } else {
            view.selected.delete(fileId);
        }
        updateSelectionIndicators(type);
    }

    function trimSelection(type) {
        const view = state.views[type];
        const ids = new Set(view.items.map((file) => file.id));
        Array.from(view.selected).forEach((id) => {
            if (!ids.has(id)) {
                view.selected.delete(id);
            }
        });
    }

    function updateActionButtons(type) {
        const column = columnEls[type];
        if (!column) return;
        const view = state.views[type];
        if (column.downloadCurrent) {
            column.downloadCurrent.disabled = view.loading;
        }
        if (column.downloadSelected) {
            column.downloadSelected.disabled = view.selected.size === 0;
        }
        if (column.deleteSelected) {
            column.deleteSelected.disabled = view.selected.size === 0;
        }
    }

    function changePage(type, direction) {
        const view = state.views[type];
        if (!view) return;
        const totalPages = Math.max(1, Math.ceil(view.total / view.pageSize));
        if (direction === 'prev') {
            if (view.page > 1) {
                view.page -= 1;
                loadFiles(type, { resetPage: false });
            }
        } else if (direction === 'next') {
            if (view.page < totalPages) {
                view.page += 1;
                loadFiles(type, { resetPage: false });
            }
        }
    }

    function updateViewMode(type, includeChildren) {
        const view = state.views[type];
        if (view.includeChildren === includeChildren) return;
        view.includeChildren = includeChildren;
        view.page = 1;
        if (columnEls[type]?.viewSwitch) {
            columnEls[type].viewSwitch.querySelectorAll('[data-view-mode]').forEach((btn) => {
                const isInclude = btn.dataset.viewMode === 'include';
                btn.classList.toggle('active', includeChildren === isInclude);
            });
        }
        loadFiles(type, { resetPage: true });
    }

    function triggerDownload(type, selectedOnly = false) {
        const view = state.views[type];
        const params = new URLSearchParams({
            customer_id: state.customerId,
            category: CATEGORY_MAP[type],
            include_children: view.includeChildren ? '1' : '0',
            folder_path: view.folderPath ?? '',
        });
        if (selectedOnly) {
            const ids = Array.from(view.selected);
            if (!ids.length) {
                showToast('请先选择文件', 'warning');
                return;
            }
            params.set('file_ids', ids.join(','));
            params.set('selection_type', 'selection');
        } else {
            params.set('selection_type', 'tree_node');
        }
        window.open(`/api/customer_files_download.php?${params.toString()}`, '_blank');
    }

    function handleDelete(fileId) {
        fetch('/api/customer_file_delete.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(fileId)}`,
        })
            .then((res) => res.json())
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.message || '删除失败');
                }
                showToast('文件已删除', 'success');
                refreshAll();
            })
            .catch((err) => showToast(err.message || '删除失败', 'error'));
    }

    function handleBatchDelete(type) {
        const view = state.views[type];
        const selectedIds = Array.from(view.selected);
        if (selectedIds.length === 0) {
            showToast('请先选择要删除的文件', 'warning');
            return;
        }
        showConfirm(`确定要删除选中的 ${selectedIds.length} 个文件吗？删除后可在15天内恢复。`, () => {
            fetch('/api/customer_file_batch_delete.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: selectedIds }),
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data.success) {
                        throw new Error(data.message || '批量删除失败');
                    }
                    const deletedCount = data.deleted_count || selectedIds.length;
                    showToast(`已成功删除 ${deletedCount} 个文件`, 'success');
                    // 清空选择
                    view.selected.clear();
                    refreshAll();
                })
                .catch((err) => showToast(err.message || '批量删除失败', 'error'));
        });
    }

    function handleFileShare(fileId) {
        // 先检查是否已有分享链接
        fetch('/api/file_link.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get&file_id=${encodeURIComponent(fileId)}`,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success && data.data) {
                    // 已有链接，显示管理界面
                    showFileShareModal(fileId, data.data, data.share_url, data.region_urls || []);
                } else {
                    // 没有链接，显示创建界面
                    showFileShareModal(fileId, null, null, []);
                }
            })
            .catch((err) => {
                showToast('加载分享链接信息失败: ' + err.message, 'error');
            });
    }

    function showFileShareModal(fileId, linkData, shareUrl, regionUrls = []) {
        const baseUrl = window.location.origin;
        const modalId = 'fileShareModal';
        
        // 移除已存在的模态框
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }
        
        // 生成多区域链接HTML（占位容器，通过API动态加载）
        function buildRegionLinksHtml(token) {
            return `<div class="mb-3" id="regionLinksContainer">
                <label class="form-label"><strong>分享链接（多区域）</strong></label>
                <div id="regionLinksList"><div class="text-muted small">加载中...</div></div>
            </div>`;
        }
        
        // 调用统一的ShareRegionService获取区域链接
        function loadRegionLinksFromApi(token) {
            fetch('/api/share_region_urls.php?token=' + encodeURIComponent(token))
                .then(res => res.json())
                .then(data => {
                    const container = document.getElementById('regionLinksList');
                    if (!container) return;
                    
                    if (data.success && data.data && data.data.length > 0) {
                        let html = '';
                        data.data.forEach((r, idx) => {
                            const isDefault = r.is_default ? '<span class="badge bg-success ms-2">推荐</span>' : '';
                            html += `
                            <div class="card mb-2 ${r.is_default ? 'border-success' : ''}">
                                <div class="card-body py-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong>${r.region_name}${isDefault}</strong>
                                    </div>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="regionUrl_${idx}" value="${r.url}" readonly style="font-size:12px;">
                                        <button class="btn btn-outline-primary" onclick="copyRegionLink(${idx})">复制</button>
                                    </div>
                                </div>
                            </div>`;
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="text-muted small">暂无可用节点</div>';
                    }
                })
                .catch(err => {
                    console.error('[CSREGION] 加载区域链接失败:', err);
                    const container = document.getElementById('regionLinksList');
                    if (container) container.innerHTML = '<div class="text-danger small">加载失败</div>';
                });
        }
        
        // 创建模态框
        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">文件分享链接</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            ${linkData ? `
                                ${buildRegionLinksHtml(linkData.token)}
                                <div class="mb-3">
                                    <label class="form-label"><strong>链接状态</strong></label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="fileLinkEnabledSwitch" 
                                               ${linkData.enabled ? 'checked' : ''}>
                                        <label class="form-check-label" for="fileLinkEnabledSwitch">
                                            启用分享链接
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>访问密码</strong></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="fileLinkPasswordInput" 
                                               placeholder="留空表示无密码访问" value="${linkData.password || ''}">
                                        <button class="btn btn-outline-primary" onclick="setFileLinkPassword(${fileId})">保存密码</button>
                                        <button class="btn btn-outline-secondary" onclick="clearFileLinkPassword(${fileId})">清除密码</button>
                                    </div>
                                    <small class="text-muted">当前: ${linkData.has_password ? (linkData.password ? '密码: ' + linkData.password : '已设置密码') : '无密码'}</small>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-warning btn-sm" onclick="updateFileLink(${fileId})">更新设置</button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteFileLink(${fileId})">删除链接</button>
                                </div>
                            ` : `
                                <div class="text-center py-4">
                                    <p class="text-muted">该文件还未生成分享链接</p>
                                    <button class="btn btn-primary" onclick="createFileLink(${fileId})">生成分享链接</button>
                                </div>
                            `}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        modal.show();
        
        // 从API加载区域链接
        if (linkData && linkData.token) {
            loadRegionLinksFromApi(linkData.token);
        }
        
        // 绑定事件
        const enabledSwitch = document.getElementById('fileLinkEnabledSwitch');
        if (enabledSwitch) {
            enabledSwitch.addEventListener('change', function() {
                updateFileLink(fileId);
            });
        }
        
        // 全局函数
        window.copyFileShareLink = function() {
            const input = document.getElementById('fileShareUrlInput');
            if (input) {
                input.select();
                document.execCommand('copy');
                showToast('链接已复制到剪贴板', 'success');
            }
        };
        
        window.copyRegionLink = function(idx) {
            const input = document.getElementById('regionUrl_' + idx);
            if (input) {
                input.select();
                document.execCommand('copy');
                showToast('链接已复制到剪贴板', 'success');
            }
        };
        
        window.createFileLink = function(fileId) {
            fetch('/api/file_link.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=create&file_id=${encodeURIComponent(fileId)}&enabled=1&org_permission=edit&password_permission=editable`,
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) {
                        showToast('分享链接生成成功', 'success');
                        bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                        setTimeout(() => handleFileShare(fileId), 500);
                    } else {
                        showToast(data.message || '生成失败', 'error');
                    }
                })
                .catch((err) => showToast('生成失败: ' + err.message, 'error'));
        };
        
        window.updateFileLink = function(fileId) {
            const enabled = document.getElementById('fileLinkEnabledSwitch')?.checked ? 1 : 0;
            fetch('/api/file_link.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update&file_id=${encodeURIComponent(fileId)}&enabled=${enabled}`,
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) {
                        showToast('设置已更新', 'success');
                    } else {
                        showToast(data.message || '更新失败', 'error');
                    }
                })
                .catch((err) => showToast('更新失败: ' + err.message, 'error'));
        };
        
        window.deleteFileLink = function(fileId) {
            showConfirm('确定要删除此文件的分享链接吗？', function() {
                fetch('/api/file_link.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete&file_id=${encodeURIComponent(fileId)}`,
                })
                    .then((res) => res.json())
                    .then((data) => {
                        if (data.success) {
                            showToast('分享链接已删除', 'success');
                            bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                        } else {
                            showToast(data.message || '删除失败', 'error');
                        }
                    })
                    .catch((err) => showToast('删除失败: ' + err.message, 'error'));
            });
        };
        
        window.setFileLinkPassword = function(fileId) {
            const password = document.getElementById('fileLinkPasswordInput')?.value.trim() || '';
            fetch('/api/file_link.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update&file_id=${encodeURIComponent(fileId)}&password=${encodeURIComponent(password)}`,
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) {
                        showToast('密码设置成功', 'success');
                        document.getElementById('fileLinkPasswordInput').value = '';
                        setTimeout(() => handleFileShare(fileId), 500);
                    } else {
                        showToast(data.message || '设置失败', 'error');
                    }
                })
                .catch((err) => showToast('设置失败: ' + err.message, 'error'));
        };
        
        window.clearFileLinkPassword = function(fileId) {
            showConfirm('确定要清除密码吗？', function() {
                fetch('/api/file_link.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update&file_id=${encodeURIComponent(fileId)}&password=`,
            })
                .then((res) => res.json())
                .then((data) => {
                    if (data.success) {
                        showToast('密码已清除', 'success');
                        document.getElementById('fileLinkPasswordInput').value = '';
                        setTimeout(() => handleFileShare(fileId), 500);
                    } else {
                        showToast(data.message || '清除失败', 'error');
                    }
                })
                .catch((err) => showToast('清除失败: ' + err.message, 'error'));
            });
        };
    }

    function bindUploadZones() {
        if (!state.canManage) return;
        // 防止重复绑定事件监听器
        if (uploadZonesBound) {
            console.warn('[CustomerFiles] uploadZones 已经绑定，跳过重复绑定');
            return;
        }
        uploadZonesBound = true;
        
        Object.entries(columnEls).forEach(([type, column]) => {
            if (!column.uploadZone || !column.uploadInput) return;

            // 双击触发文件或文件夹选择对话框
            // 优先尝试文件夹选择（如果支持），否则使用文件选择
            column.uploadZone.addEventListener('dblclick', () => {
                if (supportsFolderUpload && column.folderInput) {
                    // 如果支持文件夹上传且有文件夹输入框，优先使用文件夹选择
                    column.folderInput.click();
                } else {
                    // 否则使用普通文件选择
                    column.uploadInput.click();
                }
            });

            // 单击用于获取焦点（为粘贴功能准备）
            column.uploadZone.addEventListener('click', (event) => {
                // 如果双击事件已触发，不处理单击
                if (event.detail === 1) {
                    // 单次点击：使上传区域获得焦点，以便接收粘贴事件
                    column.uploadZone.focus();
                }
            });

            // 文件选择后处理
            column.uploadInput.addEventListener('change', (event) => {
                if (!event.target.files.length) return;
                handleUpload(event.target.files, type);
                event.target.value = '';
            });

            // 剪贴板粘贴上传
            // 检测浏览器是否支持剪贴板API
            const supportsClipboardAPI = typeof ClipboardEvent !== 'undefined' && 
                                         typeof DataTransfer !== 'undefined';
            
            if (supportsClipboardAPI) {
                column.uploadZone.addEventListener('paste', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    const clipboardData = event.clipboardData;
                    if (!clipboardData || !clipboardData.items) {
                        showToast('剪贴板中没有可上传的文件', 'warning');
                        return;
                    }

                    const files = [];
                    for (let i = 0; i < clipboardData.items.length; i++) {
                        const item = clipboardData.items[i];
                        if (item.kind === 'file') {
                            const file = item.getAsFile();
                            if (file) {
                                files.push(file);
                            }
                        }
                    }

                    if (files.length === 0) {
                        showToast('剪贴板中没有可上传的文件', 'warning');
                        return;
                    }

                    // 将 File[] 转换为 FileList 格式
                    try {
                        const dataTransfer = new DataTransfer();
                        files.forEach(file => dataTransfer.items.add(file));
                        handleUpload(dataTransfer.files, type);
                    } catch (error) {
                        console.error('粘贴上传失败:', error);
                        showToast('粘贴上传失败，请使用双击或拖拽方式上传', 'error');
                    }
                });
            } else {
                // 浏览器不支持剪贴板API，在用户尝试粘贴时显示提示
                column.uploadZone.addEventListener('keydown', (event) => {
                    if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
                        event.preventDefault();
                        showToast('您的浏览器不支持剪贴板文件上传，请使用双击或拖拽方式上传', 'warning');
                    }
                });
            }

            // 拖拽上传
            ['dragenter', 'dragover'].forEach((evt) => {
                column.uploadZone.addEventListener(evt, (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    column.uploadZone.classList.add('dragover');
                });
            });

            ['dragleave', 'drop'].forEach((evt) => {
                column.uploadZone.addEventListener(evt, async (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    column.uploadZone.classList.remove('dragover');
                    
                    if (evt === 'drop') {
                        // 使用 DataTransferItemList API 正确处理文件夹拖拽
                        const items = event.dataTransfer.items;
                        if (items && items.length > 0) {
                            const files = [];
                            
                            // 递归读取文件夹内容
                            async function readEntry(entry, path = '') {
                                if (entry.isFile) {
                                    return new Promise((resolve) => {
                                        entry.file((file) => {
                                            // 添加 webkitRelativePath 模拟
                                            Object.defineProperty(file, 'webkitRelativePath', {
                                                value: path ? `${path}/${file.name}` : file.name,
                                                writable: false
                                            });
                                            files.push(file);
                                            resolve();
                                        }, (err) => {
                                            console.warn('[CustomerFiles] 无法读取文件:', entry.name, err);
                                            resolve();
                                        });
                                    });
                                } else if (entry.isDirectory) {
                                    const dirPath = path ? `${path}/${entry.name}` : entry.name;
                                    const reader = entry.createReader();
                                    
                                    return new Promise((resolve) => {
                                        const readEntries = () => {
                                            reader.readEntries(async (entries) => {
                                                if (entries.length === 0) {
                                                    resolve();
                                                    return;
                                                }
                                                for (const subEntry of entries) {
                                                    await readEntry(subEntry, dirPath);
                                                }
                                                readEntries(); // 继续读取（可能有多批）
                                            }, (err) => {
                                                console.warn('[CustomerFiles] 读取目录失败:', entry.name, err);
                                                resolve();
                                            });
                                        };
                                        readEntries();
                                    });
                                }
                            }
                            
                            // 处理所有拖入的项目
                            const entries = [];
                            for (let i = 0; i < items.length; i++) {
                                const item = items[i];
                                if (item.webkitGetAsEntry) {
                                    const entry = item.webkitGetAsEntry();
                                    if (entry) entries.push(entry);
                                } else if (item.kind === 'file') {
                                    const file = item.getAsFile();
                                    if (file) files.push(file);
                                }
                            }
                            
                            // 读取所有条目
                            for (const entry of entries) {
                                await readEntry(entry, '');
                            }
                            
                            console.log('[CustomerFiles] 拖拽上传:', files.length, '个文件');
                            
                            if (files.length > 0) {
                                handleUpload(files, type);
                            }
                        }
                    }
                });
            });
        });

        setupFolderInputs();
    }

    function setupFolderInputs() {
        if (folderInputsBound || !state.canManage) return;
        folderInputsBound = true;
        Object.entries(columnEls).forEach(([type, column]) => {
            if (!column.folderInput || !column.folderButton) return;
            if (!supportsFolderUpload) {
                column.folderButton.classList.add('d-none');
                column.folderSupport?.classList.remove('d-none');
                return;
            }
            column.folderSupport?.classList.add('d-none');
            column.folderButton.classList.remove('d-none');
            column.folderButton.addEventListener('click', () => column.folderInput.click());
            column.folderInput.addEventListener('change', (event) => {
                if (!event.target.files.length) return;
                handleUpload(event.target.files, type);
                event.target.value = '';
            });
        });
    }

    function handleUpload(fileList, type) {
        // 如果是新增客户（customerId 为 0），阻止上传
        if (state.customerId <= 0) {
            showToast('请先保存客户信息，保存后即可上传文件', 'error');
            return;
        }
        
        const files = Array.from(fileList);
        if (!files.length) return;
        const uploadSignature = buildFileSignature(files);
        if (shouldSkipDuplicateUpload(type, uploadSignature)) {
            console.warn('忽略重复的上传请求', type);
            return;
        }
        const folderInfo = analyzeFolderPayload(files);
        try {
            enforceSingleFileLimits(files);
            enforceBatchLimits(files.length, folderInfo.totalBytes);
            const pathsToValidate = folderInfo.hasFolderUpload
                ? [...folderInfo.folderPaths, folderInfo.folderRoot]
                : [];
            validateFolderPaths(pathsToValidate);
        } catch (error) {
            showToast(error.message, 'error');
            return;
        }

        setUploadGuard(type, uploadSignature);

        const formData = new FormData();
        formData.append('customer_id', state.customerId);
        formData.append('category', CATEGORY_MAP[type] || 'client_material');
        files.forEach((file) => formData.append('files[]', file));
        folderInfo.folderPaths.forEach((path) => formData.append('folder_paths[]', path));
        formData.append('folder_root', folderInfo.folderRoot || '');
        formData.append('upload_mode', folderInfo.hasFolderUpload ? 'folder' : 'files');

        const jobLabel = folderInfo.hasFolderUpload
            ? `文件夹 ${folderInfo.folderRoot || '根目录'} · ${files.length} 个文件`
            : `${files.length} 个文件`;
        const jobId = addUploadQueue(type, jobLabel);

        // 计算文件总大小（用于错误提示）
        const totalSize = files.reduce((sum, file) => sum + file.size, 0);
        const formatFileSize = (bytes) => {
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
            return bytes + ' bytes';
        };

        // 根据文件大小计算超时时间（每MB给10秒，最少60秒，最多30分钟）
        const timeoutMs = Math.max(60000, Math.min(30 * 60 * 1000, (totalSize / 1024 / 1024) * 10 * 1000));
        const abortController = new AbortController();
        const timeoutId = setTimeout(() => {
            abortController.abort();
        }, timeoutMs);

        // 记录上传开始信息
        console.log(`[上传] 开始上传 ${files.length} 个文件，总大小: ${formatFileSize(totalSize)}, 超时时间: ${Math.round(timeoutMs / 1000)}秒`);
        const uploadStartTime = Date.now();

        // 使用 XMLHttpRequest 以支持上传进度监听
        const xhr = new XMLHttpRequest();
        
        // 监听上传进度
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                const progress = Math.round((event.loaded / event.total) * 100);
                updateUploadProgress(jobId, progress);
            }
        });

        // 设置超时
        xhr.timeout = timeoutMs;
        
        // 创建 Promise 来处理响应
        const uploadPromise = new Promise((resolve, reject) => {
            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const contentType = xhr.getResponseHeader('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            reject(new Error(`服务器返回了非 JSON 响应: ${xhr.responseText.substring(0, 200)}`));
                            return;
                        }
                        const data = JSON.parse(xhr.responseText);
                        resolve(data);
                    } catch (e) {
                        reject(new Error('解析响应失败: ' + e.message));
                    }
                } else {
                    // 处理错误响应
                    let errorMessage = '上传失败';
                    let jsonData = null;
                    
                    try {
                        const responseText = xhr.responseText || '';
                        if (responseText.trim().startsWith('{') || responseText.trim().startsWith('[')) {
                            jsonData = JSON.parse(responseText);
                        }
                    } catch (e) {
                        // 忽略解析错误
                    }
                    
                    if (jsonData && jsonData.message) {
                        errorMessage = jsonData.message;
                        
                        if (xhr.status === 413 && jsonData.config) {
                            const configInfo = jsonData.config;
                            const fileSizeInfo = `文件大小：${formatFileSize(totalSize)}`;
                            const configInfoText = `当前配置：PHP post_max_size=${configInfo.post_max_size}, upload_max_filesize=${configInfo.upload_max_filesize}`;
                            errorMessage = `${jsonData.message}\n\n${fileSizeInfo}\n${configInfoText}\n\n可在"后台管理 > 运维诊断 > 上传配置诊断"中查看详细配置。`;
                        } else if (xhr.status === 413) {
                            errorMessage = `文件太大（${formatFileSize(totalSize)}），超过服务器限制。\n\n` +
                                `可能的原因：\n` +
                                `1. Nginx client_max_body_size 限制\n` +
                                `2. PHP post_max_size 限制\n` +
                                `3. PHP upload_max_filesize 限制\n\n` +
                                `可在"后台管理 > 运维诊断 > 上传配置诊断"中查看详细配置信息。`;
                        }
                    } else {
                        if (xhr.status === 413) {
                            errorMessage = `文件太大（${formatFileSize(totalSize)}），超过服务器限制。\n\n` +
                                `可能的原因：\n` +
                                `1. Nginx client_max_body_size 限制（请求在到达 PHP 前被拦截）\n` +
                                `2. PHP post_max_size 限制\n` +
                                `3. PHP upload_max_filesize 限制\n\n` +
                                `可在"后台管理 > 运维诊断 > 上传配置诊断"中查看详细配置信息。`;
                        } else if (xhr.status === 502 || xhr.status === 504) {
                            errorMessage = `服务器响应超时（文件大小：${formatFileSize(totalSize)}），可能是文件太大或上传时间过长`;
                        } else if (xhr.status === 500) {
                            errorMessage = '服务器内部错误，请查看服务器日志';
                        } else {
                            errorMessage = `服务器错误 (${xhr.status}): ${xhr.responseText?.substring(0, 200) || ''}`;
                        }
                    }
                    
                    reject(new Error(errorMessage));
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error(`网络连接失败（文件大小：${formatFileSize(totalSize)}）。\n\n` +
                    `可能的原因：\n` +
                    `1. 网络连接中断\n` +
                    `2. 服务器无响应\n` +
                    `3. Nginx 或 PHP 超时\n\n` +
                    `建议检查服务器日志或联系管理员。`));
            });

            xhr.addEventListener('timeout', () => {
                reject(new Error(`上传超时（文件大小：${formatFileSize(totalSize)}）。\n\n` +
                    `可能的原因：\n` +
                    `1. 网络连接不稳定\n` +
                    `2. 文件太大，上传时间过长\n` +
                    `3. 服务器响应超时\n\n` +
                    `建议：\n` +
                    `- 检查网络连接\n` +
                    `- 尝试上传较小的文件\n` +
                    `- 检查服务器超时配置（max_execution_time, max_input_time）`));
            });

            xhr.addEventListener('abort', () => {
                reject(new Error('上传已取消'));
            });

            // 开始上传
            xhr.open('POST', '/api/customer_files.php', true);
            xhr.withCredentials = true;
            xhr.send(formData);
        });

        uploadPromise
            .then((data) => {
                const uploadDuration = Date.now() - uploadStartTime;
                console.log(`[上传] 上传成功，耗时: ${Math.round(uploadDuration / 1000)}秒`);
                
                if (!data.success) {
                    throw new Error(data.message || '上传失败');
                }
                markUploadQueue(jobId, 'success');
                showToast('上传成功', 'success');
                refreshAll();
            })
            .catch((err) => {
                let errorMsg = err.message || '上传失败';
                markUploadQueue(jobId, 'error', errorMsg);
                showToast(errorMsg, 'error');
                console.error('上传错误:', err);
            })
            .finally(() => {
                clearUploadGuard(type, uploadSignature);
            });
    }

    function addUploadQueue(type, label) {
        const jobId = `${type}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        state.uploadQueue.push({
            id: jobId,
            type,
            name: label,
            status: 'pending',
            message: '',
            progress: 0,
        });
        renderUploadProgress(type);
        return jobId;
    }

    function markUploadQueue(jobId, status, message = '') {
        const job = state.uploadQueue.find((item) => item.id === jobId);
        if (!job) {
            return;
        }
        job.status = status;
        job.message = message;
        if (status === 'success') {
            job.progress = 100;
        }
        renderUploadProgress(job.type);
        setTimeout(() => {
            state.uploadQueue = state.uploadQueue.filter((item) => item.id !== jobId || item.status === 'pending');
            renderUploadProgress(job.type);
        }, 2000);
    }

    function updateUploadProgress(jobId, progress) {
        const job = state.uploadQueue.find((item) => item.id === jobId);
        if (!job) {
            return;
        }
        job.progress = Math.min(100, Math.max(0, progress));
        renderUploadProgress(job.type);
    }

    function renderUploadProgress(type) {
        const column = columnEls[type];
        if (!column || !column.uploadProgress) return;
        const list = state.uploadQueue.filter((item) => item.type === type);
        if (!list.length) {
            column.uploadProgress.classList.add('d-none');
            column.uploadProgress.innerHTML = '';
            return;
        }
        column.uploadProgress.classList.remove('d-none');
        column.uploadProgress.innerHTML = list.map((item) => {
            const progress = item.progress || 0;
            const statusClass = item.status === 'success' ? 'success' : item.status === 'error' ? 'error' : 'uploading';
            const statusText = item.status === 'success' ? '上传完成' : item.status === 'error' ? (item.message || '上传失败') : `上传中 ${progress}%`;
            
            return `
            <li class="upload-progress-item upload-progress-item-${statusClass}">
                <div class="upload-progress-info">
                    <span class="upload-progress-name">${escapeHtml(item.name)}</span>
                    <span class="upload-progress-status">${escapeHtml(statusText)}</span>
                </div>
                <div class="upload-progress-bar-container">
                    <div class="upload-progress-bar" style="width: ${progress}%"></div>
                </div>
            </li>
            `;
        }).join('');
    }

    function enforceBatchLimits(count, totalBytes) {
        if (count > state.limits.maxFiles) {
            throw new Error(`单次最多上传 ${state.limits.maxFiles} 个文件，请拆分后重试`);
        }
        if (totalBytes > state.limits.maxBytes) {
            throw new Error(`单次上传总大小不可超过 ${formatSize(state.limits.maxBytes)}`);
        }
    }

    function enforceSingleFileLimits(files) {
        const maxSingleSize = state.limits.maxSingleSize;
        for (const file of files) {
            if (file.size > maxSingleSize) {
                throw new Error(`文件 "${file.name}" 大小 ${formatSize(file.size)} 超过单文件限制 ${formatSize(maxSingleSize)}`);
            }
        }
    }

    function validateFolderPaths(paths) {
        const { maxDepth, maxSegmentLength } = state.limits;
        paths.forEach((path) => {
            if (!path) return;
            const segments = path.split('/').filter(Boolean);
            if (segments.length > maxDepth) {
                throw new Error(`子目录层级不可超过 ${maxDepth} 层：${path}`);
            }
            segments.forEach((segment) => {
                if (segment.length > maxSegmentLength) {
                    throw new Error(`子目录“${segment}”长度不可超过 ${maxSegmentLength} 个字符`);
                }
            });
        });
    }

    function analyzeFolderPayload(files) {
        const folderPaths = [];
        let folderRoot = '';
        let hasFolderUpload = false;
        let totalBytes = 0;
        files.forEach((file) => {
            totalBytes += file.size || 0;
            const relativePath = (file.webkitRelativePath || '').trim();
            if (relativePath) {
                hasFolderUpload = true;
                const normalized = relativePath.replace(/\\/g, '/').split('/').filter(Boolean);
                if (!folderRoot && normalized.length) {
                    folderRoot = normalized[0];
                }
                const dirSegments = normalized.slice(0, -1);
                folderPaths.push(dirSegments.join('/'));
            } else {
                folderPaths.push('');
            }
        });
        while (folderPaths.length < files.length) {
            folderPaths.push('');
        }
        return {
            folderPaths,
            folderRoot,
            hasFolderUpload,
            totalBytes,
        };
    }

    function renderFolderPath(path) {
        if (!path) {
            return '<span class="folder-path-pill is-root">根目录</span>';
        }
        return `<span class="folder-path-pill">${escapeHtml(path)}</span>`;
    }

    function formatSize(bytes) {
        if (!bytes) return '0B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let size = bytes;
        let idx = 0;
        while (size >= 1024 && idx < units.length - 1) {
            size /= 1024;
            idx += 1;
        }
        return `${size.toFixed(size >= 10 || idx === 0 ? 0 : 1)} ${units[idx]}`;
    }

    function formatDate(timestamp) {
        if (!timestamp) return '-';
        const date = new Date(timestamp * 1000);
        return `${date.getFullYear()}-${padZero(date.getMonth() + 1)}-${padZero(date.getDate())} ${padZero(date.getHours())}:${padZero(date.getMinutes())}`;
    }

    function padZero(num) {
        return num < 10 ? `0${num}` : `${num}`;
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.innerText = str;
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        if (typeof showAlertModal === 'function') {
            showAlertModal(message, type);
        } else {
            alert(message);
        }
    }

    function showConfirm(message, onConfirm) {
        if (typeof showConfirmModal === 'function') {
            showConfirmModal(message, onConfirm);
        } else if (confirm(message)) {
            onConfirm();
        }
    }

    function resolveParentPath(path) {
        if (!path) return null;
        const idx = path.lastIndexOf('/');
        return idx === -1 ? '' : path.slice(0, idx);
    }

    function pathLabel(type, path) {
        if (!path) return CATEGORY_LABEL[type] || '根目录';
        const segments = path.split('/').filter(Boolean);
        return segments[segments.length - 1] || CATEGORY_LABEL[type];
    }

    function buildFallbackBreadcrumbs(type, path) {
        const crumbs = [{ label: CATEGORY_LABEL[type], full_path: '' }];
        if (!path) return crumbs;
        const segments = path.split('/').filter(Boolean);
        let current = '';
        segments.forEach((segment) => {
            current = current ? `${current}/${segment}` : segment;
            crumbs.push({ label: segment, full_path: current });
        });
        return crumbs;
    }

    function buildFileSignature(files) {
        if (!files || !files.length) return '';
        return files
            .map((file) => `${file.name || ''}:${file.size || 0}:${file.lastModified || 0}`)
            .join('|');
    }

    function shouldSkipDuplicateUpload(type, signature) {
        if (!signature) {
            return false;
        }
        const guard = uploadGuards[type];
        return !!guard && guard.signature === signature;
    }

    function setUploadGuard(type, signature) {
        if (!signature) return;
        if (!uploadGuards[type]) {
            uploadGuards[type] = { signature: null, timer: null };
        }
        const guard = uploadGuards[type];
        clearTimeout(guard.timer);
        guard.signature = signature;
        guard.timer = setTimeout(() => {
            if (uploadGuards[type]?.signature === signature) {
                uploadGuards[type].signature = null;
                uploadGuards[type].timer = null;
            }
        }, 5000);
    }

    function clearUploadGuard(type, signature) {
        const guard = uploadGuards[type];
        if (!guard || guard.signature !== signature) {
            return;
        }
        clearTimeout(guard.timer);
        guard.signature = null;
        guard.timer = null;
    }

    function handleRenameFile(fileId) {
        // 先尝试从当前加载的文件列表中查找
        let file = null;
        for (const type of Object.keys(CATEGORY_MAP)) {
            const view = state.views[type];
            file = view.items.find(f => f.id === fileId);
            if (file) break;
        }

        // 如果找不到，从后端获取文件信息
        if (!file) {
            fetch(`/api/customer_files.php?customer_id=${state.customerId}&action=get_file&file_id=${fileId}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success || !data.data) {
                        throw new Error(data.message || '文件不存在');
                    }
                    showRenameDialog(fileId, data.data.filename, 'file');
                })
                .catch(err => {
                    showToast('获取文件信息失败: ' + err.message, 'error');
                });
            return;
        }

        showRenameDialog(fileId, file.filename, 'file');
    }

    function handlePreviewFile(fileId) {
        fetch(`/api/customer_files.php?customer_id=${state.customerId}&action=preview&file_id=${fileId}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || '获取预览链接失败');
                }
                // 使用后端返回的文件信息，如果后端没有返回，则从当前列表查找
                let file = data.data?.file;
                if (!file) {
                    // 如果后端没有返回文件信息，尝试从当前列表查找
                    for (const type of Object.keys(CATEGORY_MAP)) {
                        const view = state.views[type];
                        file = view.items.find(f => f.id === fileId);
                        if (file) break;
                    }
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
                showToast('预览失败: ' + err.message, 'error');
            });
    }

    function showRenameDialog(id, currentName, type) {
        const modalId = 'renameModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }

        const isFile = type === 'file';
        const title = isFile ? '重命名文件' : '重命名文件夹';
        const label = isFile ? '文件名' : '文件夹名称';

        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${title}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">${label}</label>
                                <input type="text" class="form-control" id="renameInput" value="${escapeHtml(currentName)}" autocomplete="off">
                                <div class="form-text">请输入新的${label}</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="button" class="btn btn-primary" id="renameConfirmBtn">确认</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        const input = document.getElementById('renameInput');
        const confirmBtn = document.getElementById('renameConfirmBtn');

        // 如果是文件，选中文件名部分（不包括扩展名）
        if (isFile) {
            const dotPos = currentName.lastIndexOf('.');
            if (dotPos > 0) {
                input.setSelectionRange(0, dotPos);
            } else {
                input.select();
            }
        } else {
            input.select();
        }

        input.focus();

        const handleConfirm = () => {
            const newName = input.value.trim();
            if (!newName) {
                showToast(`${label}不能为空`, 'warning');
                return;
            }

            if (newName === currentName) {
                modal.hide();
                return;
            }

            confirmBtn.disabled = true;
            const formData = new FormData();
            formData.append('action', isFile ? 'rename_file' : 'rename_folder');
            if (isFile) {
                formData.append('file_id', id);
            } else {
                formData.append('customer_id', state.customerId);
                formData.append('old_folder_path', id); // 对于文件夹，id 就是路径
            }
            formData.append('new_name', newName);

            fetch('/api/customer_file_rename.php', {
                method: 'POST',
                body: formData,
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || '重命名失败');
                    }
                    showToast('重命名成功', 'success');
                    modal.hide();
                    // 刷新文件列表和目录树
                    Object.keys(CATEGORY_MAP).forEach(type => {
                        loadFiles(type, { resetPage: false });
                        fetchTree(type, '');
                    });
                })
                .catch(err => {
                    showToast('重命名失败: ' + err.message, 'error');
                    confirmBtn.disabled = false;
                });
        };

        confirmBtn.addEventListener('click', handleConfirm);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                handleConfirm();
            }
        });

        modal.show();
    }

    function showPreviewModal(file, previewUrl, siblingImages = [], prevFileId = null, nextFileId = null) {
        const modalId = 'previewModal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) {
            existingModal.remove();
        }

        const mimeType = file.mime_type || '';
        const isImage = mimeType.startsWith('image/');
        const isVideo = mimeType.startsWith('video/');
        const isAudio = mimeType.startsWith('audio/');
        const hasSiblings = isImage && siblingImages.length > 1;

        let contentHtml = '';
        if (isImage) {
            // 添加上一张/下一张导航按钮
            const navButtons = hasSiblings ? `
                <button class="preview-nav-btn preview-nav-prev" id="previewNavPrev" ${!prevFileId ? 'disabled' : ''} title="上一张 (←)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
                <button class="preview-nav-btn preview-nav-next" id="previewNavNext" ${!nextFileId ? 'disabled' : ''} title="下一张 (→)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            ` : '';
            
            contentHtml = `
                <div class="preview-image-container">
                    ${navButtons}
                    <img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(file.filename)}" class="preview-image" id="previewImage">
                    <div class="preview-controls">
                        <button class="btn btn-sm btn-outline-secondary" id="previewZoomIn">放大</button>
                        <button class="btn btn-sm btn-outline-secondary" id="previewZoomOut">缩小</button>
                        <button class="btn btn-sm btn-outline-secondary" id="previewRotate">旋转</button>
                        <button class="btn btn-sm btn-outline-secondary" id="previewFullscreen">全屏</button>
                    </div>
                </div>
            `;
        } else if (isVideo) {
            contentHtml = `
                <div class="preview-video-container">
                    <video controls class="preview-video" id="previewVideo">
                        <source src="${escapeHtml(previewUrl)}" type="${escapeHtml(mimeType)}">
                        您的浏览器不支持视频播放。
                    </video>
                </div>
            `;
        } else if (isAudio) {
            contentHtml = `
                <div class="preview-audio-container">
                    <audio controls class="preview-audio" id="previewAudio">
                        <source src="${escapeHtml(previewUrl)}" type="${escapeHtml(mimeType)}">
                        您的浏览器不支持音频播放。
                    </audio>
                </div>
            `;
        } else {
            contentHtml = '<div class="alert alert-warning">不支持预览此文件类型</div>';
        }

        const modalHtml = `
            <div class="modal fade" id="${modalId}" tabindex="-1">
                <div class="modal-dialog modal-lg modal-fullscreen-lg-down">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">${escapeHtml(file.filename)}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            ${contentHtml}
                        </div>
                        <div class="modal-footer">
                            <a href="/api/customer_file_stream.php?id=${file.id}&mode=download" class="btn btn-primary" download>下载</a>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        const modalElement = document.getElementById(modalId);

        // 图片预览控制
        if (isImage) {
            const img = document.getElementById('previewImage');
            let scale = 1;
            let rotation = 0;

            document.getElementById('previewZoomIn')?.addEventListener('click', () => {
                scale = Math.min(scale * 1.2, 5);
                img.style.transform = `scale(${scale}) rotate(${rotation}deg)`;
            });

            document.getElementById('previewZoomOut')?.addEventListener('click', () => {
                scale = Math.max(scale / 1.2, 0.2);
                img.style.transform = `scale(${scale}) rotate(${rotation}deg)`;
            });

            document.getElementById('previewRotate')?.addEventListener('click', () => {
                rotation = (rotation + 90) % 360;
                img.style.transform = `scale(${scale}) rotate(${rotation}deg)`;
            });

            document.getElementById('previewFullscreen')?.addEventListener('click', () => {
                if (img.requestFullscreen) {
                    img.requestFullscreen();
                } else if (img.webkitRequestFullscreen) {
                    img.webkitRequestFullscreen();
                } else if (img.mozRequestFullScreen) {
                    img.mozRequestFullScreen();
                } else if (img.msRequestFullscreen) {
                    img.msRequestFullscreen();
                }
            });

            // 鼠标滚轮缩放
            img.addEventListener('wheel', (e) => {
                e.preventDefault();
                const delta = e.deltaY > 0 ? 0.9 : 1.1;
                scale = Math.max(0.2, Math.min(5, scale * delta));
                img.style.transform = `scale(${scale}) rotate(${rotation}deg)`;
            });

            // 上一张/下一张导航
            if (hasSiblings) {
                const prevBtn = document.getElementById('previewNavPrev');
                const nextBtn = document.getElementById('previewNavNext');

                const navigateToFile = (targetFileId) => {
                    if (!targetFileId) return;
                    // 重置缩放和旋转
                    scale = 1;
                    rotation = 0;
                    img.style.transform = '';
                    // 加载新文件
                    handlePreviewFile(targetFileId);
                };

                prevBtn?.addEventListener('click', () => {
                    if (prevFileId) {
                        navigateToFile(prevFileId);
                    }
                });

                nextBtn?.addEventListener('click', () => {
                    if (nextFileId) {
                        navigateToFile(nextFileId);
                    }
                });

                // 键盘快捷键支持
                const handleKeyDown = (e) => {
                    // 只在模态框显示时响应
                    if (!modalElement.classList.contains('show')) return;
                    
                    // 如果焦点在输入框等元素上，不处理
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                    
                    if (e.key === 'ArrowLeft' && prevFileId) {
                        e.preventDefault();
                        navigateToFile(prevFileId);
                    } else if (e.key === 'ArrowRight' && nextFileId) {
                        e.preventDefault();
                        navigateToFile(nextFileId);
                    }
                };

                document.addEventListener('keydown', handleKeyDown);
                
                // 模态框关闭时移除键盘事件监听
                modalElement.addEventListener('hidden.bs.modal', () => {
                    document.removeEventListener('keydown', handleKeyDown);
                }, { once: true });
            }
        }

        modal.show();
    }
})();
