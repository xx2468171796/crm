/**
 * 统一资源管理中心组件
 * 双栏布局：左侧目录树 + 右侧文件表格
 */
const ResourceCenter = (function() {
    let config = {
        container: null,
        projectId: 0,
        apiUrl: '/api/deliverables.php',
        isAdmin: false,
        onUploadSuccess: null
    };
    
    let state = {
        treeData: { customer_file: [], artwork_file: [], model_file: [] },
        currentFolder: null,
        currentCategory: 'artwork_file',
        filterStatus: '',
        searchText: '',
        selectedFiles: new Set(),
        expandedFolders: new Set()
    };
    
    // 初始化
    function init(options) {
        config = { ...config, ...options };
        config.container = document.querySelector(options.container);
        
        if (!config.container) {
            console.error('[RC_DEBUG] Container not found:', options.container);
            return;
        }
        
        render();
        setupDragDrop();
        loadTree('customer_file');
        loadTree('artwork_file');
        loadTree('model_file');
        // 初始化时加载当前分类的文件列表
        loadFiles();
    }
    
    // 渲染主布局
    function render() {
        config.container.innerHTML = `
        <div class="rc-container">
            <!-- 顶部工具栏 -->
            <div class="rc-toolbar">
                <!-- 文件分类Tab -->
                <div class="rc-category-tabs">
                    <button class="rc-category-tab ${state.currentCategory === 'customer_file' ? 'active' : ''}" data-category="customer_file" onclick="ResourceCenter.selectCategory('customer_file')">
                        <i class="bi bi-people"></i> 客户文件
                        <span class="rc-badge rc-badge-info" id="rcCustomerCount">0</span>
                    </button>
                    <button class="rc-category-tab ${state.currentCategory === 'artwork_file' ? 'active' : ''}" data-category="artwork_file" onclick="ResourceCenter.selectCategory('artwork_file')">
                        <i class="bi bi-palette"></i> 作品文件
                        <span class="rc-badge rc-badge-warning" id="rcArtworkCount">0</span>
                    </button>
                    <button class="rc-category-tab ${state.currentCategory === 'model_file' ? 'active' : ''}" data-category="model_file" onclick="ResourceCenter.selectCategory('model_file')">
                        <i class="bi bi-box"></i> 模型文件
                        <span class="rc-badge rc-badge-success" id="rcModelCount">0</span>
                    </button>
                </div>
                <div class="rc-actions">
                    <input type="text" class="rc-search" placeholder="搜索文件..." onkeyup="ResourceCenter.search(this.value)">
                    <!-- 上传下拉按钮 -->
                    <div class="rc-upload-dropdown">
                        <button class="rc-btn primary rc-upload-trigger" onclick="ResourceCenter.toggleUploadMenu()">
                            <i class="bi bi-upload"></i> 上传 <i class="bi bi-chevron-down"></i>
                        </button>
                        <div class="rc-upload-menu" id="rcUploadMenu">
                            <button class="rc-upload-option" onclick="ResourceCenter.openUploadDialog()">
                                <i class="bi bi-file-earmark"></i> 上传文件
                            </button>
                            <button class="rc-upload-option" onclick="ResourceCenter.openFolderDialog()">
                                <i class="bi bi-folder"></i> 上传文件夹
                            </button>
                        </div>
                    </div>
                    <button class="rc-btn small" onclick="ResourceCenter.createFolder()" title="新建文件夹">
                        <i class="bi bi-folder-plus"></i>
                    </button>
                    <input type="file" id="rcFileInput" multiple style="display:none" onchange="ResourceCenter.handleFileSelect(this)">
                    <input type="file" id="rcFolderInput" webkitdirectory directory multiple style="display:none" onchange="ResourceCenter.handleFolderSelect(this)">
                </div>
            </div>
            
            <!-- 状态筛选栏 -->
            <div class="rc-status-bar">
                <div class="rc-filter-tabs">
                    <button class="rc-tab ${state.filterStatus === '' ? 'active' : ''}" data-status="" onclick="ResourceCenter.filterByStatus('')">全部</button>
                    <button class="rc-tab ${state.filterStatus === 'pending' ? 'active' : ''}" data-status="pending" onclick="ResourceCenter.filterByStatus('pending')">
                        待审批 <span class="rc-badge rc-badge-warning" id="rcPendingCount">0</span>
                    </button>
                    <button class="rc-tab ${state.filterStatus === 'approved' ? 'active' : ''}" data-status="approved" onclick="ResourceCenter.filterByStatus('approved')">
                        已通过 <span class="rc-badge rc-badge-success" id="rcApprovedCount">0</span>
                    </button>
                    <button class="rc-tab ${state.filterStatus === 'rejected' ? 'active' : ''}" data-status="rejected" onclick="ResourceCenter.filterByStatus('rejected')">
                        已驳回 <span class="rc-badge rc-badge-danger" id="rcRejectedCount">0</span>
                    </button>
                </div>
                <!-- 批量操作栏 -->
                <div class="rc-batch-bar" id="rcBatchBar" style="display: none;">
                    <span>已选择 <strong id="rcSelectedCount">0</strong> 个文件</span>
                    <div class="rc-batch-actions">
                        <button class="rc-btn success small" onclick="ResourceCenter.batchApprove()">批量通过</button>
                        <button class="rc-btn danger small" onclick="ResourceCenter.batchReject()">批量驳回</button>
                        <button class="rc-btn danger small" onclick="ResourceCenter.batchDelete()">批量删除</button>
                        <button class="rc-btn secondary small" onclick="ResourceCenter.clearSelection()">取消选择</button>
                    </div>
                </div>
            </div>
            
            <!-- 主体区域 -->
            <div class="rc-main rc-main-single">
                <!-- 左侧目录树（当前分类） -->
                <div class="rc-sidebar">
                    <div class="rc-tree-section">
                        <div class="rc-tree-content" id="rcCurrentTree"></div>
                    </div>
                </div>
                
                <!-- 右侧文件列表 -->
                <div class="rc-content" id="rcFileList">
                    <div class="rc-empty-state" id="rcEmptyState">
                        <div class="rc-drop-zone-enhanced">
                            <i class="bi bi-cloud-arrow-up"></i>
                            <h4>暂无文件</h4>
                            <p>拖拽文件或文件夹到此处上传</p>
                            <div class="rc-empty-actions">
                                <button class="rc-btn primary" onclick="ResourceCenter.openUploadDialog()">
                                    <i class="bi bi-file-earmark-plus"></i> 上传文件
                                </button>
                                <button class="rc-btn secondary" onclick="ResourceCenter.openFolderDialog()">
                                    <i class="bi bi-folder-plus"></i> 上传文件夹
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
        
        // 隐藏旧的目录树容器
        ['rcCustomerTree', 'rcArtworkTree', 'rcModelTree'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
    }
    
    // 切换上传菜单
    function toggleUploadMenu() {
        const menu = document.getElementById('rcUploadMenu');
        if (menu) {
            menu.classList.toggle('show');
        }
    }
    
    // 点击外部关闭菜单
    document.addEventListener('click', (e) => {
        const menu = document.getElementById('rcUploadMenu');
        const trigger = e.target.closest('.rc-upload-trigger');
        if (menu && !trigger && !e.target.closest('.rc-upload-menu')) {
            menu.classList.remove('show');
        }
    });
    
    // 设置拖拽上传（使用事件委托，只绑定一次）
    function setupDragDrop() {
        const container = config.container;
        if (!container) return;
        
        // 使用事件委托绑定到容器
        container.addEventListener('dragover', (e) => {
            e.preventDefault();
            const content = container.querySelector('.rc-content');
            if (content) content.classList.add('dragover');
        });
        
        container.addEventListener('dragleave', (e) => {
            e.preventDefault();
            // 只有离开容器时才移除样式
            if (!container.contains(e.relatedTarget)) {
                const content = container.querySelector('.rc-content');
                if (content) content.classList.remove('dragover');
            }
        });
        
        container.addEventListener('drop', async (e) => {
            e.preventDefault();
            const content = container.querySelector('.rc-content');
            if (content) content.classList.remove('dragover');
            
            // 使用 DataTransferItemList API 处理文件夹拖拽
            const items = e.dataTransfer.items;
            if (items && items.length > 0) {
                const files = [];
                const folderPaths = [];
                let folderRoot = '';
                
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
                                folderPaths.push(path);
                                resolve();
                            }, (err) => {
                                console.warn('[RC_DEBUG] 无法读取文件:', entry.name, err);
                                resolve();
                            });
                        });
                    } else if (entry.isDirectory) {
                        if (!folderRoot) folderRoot = entry.name;
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
                                    console.warn('[RC_DEBUG] 读取目录失败:', entry.name, err);
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
                        if (file) {
                            files.push(file);
                            folderPaths.push('');
                        }
                    }
                }
                
                // 读取所有条目
                for (const entry of entries) {
                    await readEntry(entry, '');
                }
                
                console.log('[RC_DEBUG] 拖拽上传:', files.length, '个文件, 文件夹根:', folderRoot);
                
                if (files.length > 0) {
                    if (folderRoot) {
                        // 有文件夹结构，使用文件夹上传模式（带进度）
                        await uploadDraggedFolder(files, folderPaths, folderRoot);
                    } else {
                        // 普通文件上传
                        await uploadFiles(files);
                    }
                }
            }
        });
    }
    
    // 加载目录树
    async function loadTree(category) {
        try {
            const url = `${config.apiUrl}?action=tree&project_id=${config.projectId}&file_category=${category}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                state.treeData[category] = result.data || [];
                renderTree(category);
                updateCounts();
            }
        } catch (error) {
            console.error('[RC_DEBUG] Load tree error:', error);
        }
    }
    
    // 渲染目录树
    function renderTree(category) {
        // 同时更新当前分类的目录树
        if (category === state.currentCategory) {
            const currentContainer = document.getElementById('rcCurrentTree');
            if (currentContainer) {
                const nodes = state.treeData[category] || [];
                if (nodes.length === 0) {
                    currentContainer.innerHTML = '<div class="rc-tree-empty">暂无文件夹</div>';
                } else {
                    currentContainer.innerHTML = renderTreeNodes(nodes, category, 0);
                }
            }
        }
        
        // 更新分类Tab上的文件数徽标
        updateCategoryBadges();
    }
    
    // 更新分类徽标
    function updateCategoryBadges() {
        const countMap = {
            'customer_file': 'rcCustomerCount',
            'artwork_file': 'rcArtworkCount',
            'model_file': 'rcModelCount'
        };
        
        Object.keys(countMap).forEach(category => {
            const el = document.getElementById(countMap[category]);
            if (el) {
                const nodes = state.treeData[category] || [];
                const fileCount = countFilesInTree(nodes);
                el.textContent = fileCount;
            }
        });
    }
    
    // 递归统计文件数
    function countFilesInTree(nodes) {
        let count = 0;
        nodes.forEach(node => {
            if (node.is_folder != 1) {
                count++;
            }
            if (node.children) {
                count += countFilesInTree(node.children);
            }
        });
        return count;
    }
    
    // 递归渲染树节点
    function renderTreeNodes(nodes, category, level) {
        let html = '';
        const folders = nodes.filter(n => n.is_folder == 1);
        
        folders.forEach(node => {
            const isExpanded = state.expandedFolders.has(node.id);
            const isSelected = state.currentFolder === node.id && state.currentCategory === category;
            const indent = level * 16;
            const hasChildren = node.children && node.children.filter(c => c.is_folder == 1).length > 0;
            
            html += `
            <div class="rc-tree-node ${isSelected ? 'selected' : ''}" 
                 data-id="${node.id}" data-category="${category}"
                 style="padding-left: ${indent}px;"
                 onclick="ResourceCenter.selectFolder(${node.id}, '${category}')"
                 oncontextmenu="ResourceCenter.showFolderMenu(event, ${node.id}, '${category}')">
                <span class="rc-tree-toggle ${hasChildren ? '' : 'invisible'}" onclick="event.stopPropagation(); ResourceCenter.toggleFolder(${node.id})">
                    <i class="bi ${isExpanded ? 'bi-chevron-down' : 'bi-chevron-right'}"></i>
                </span>
                <i class="bi ${isExpanded ? 'bi-folder2-open' : 'bi-folder2'} text-warning"></i>
                <span class="rc-tree-name">${escapeHtml(node.deliverable_name)}</span>
                <span class="rc-folder-actions" onclick="event.stopPropagation();">
                    <button class="rc-folder-btn" title="重命名" onclick="ResourceCenter.renameFolder(${node.id})">✏️</button>
                    <button class="rc-folder-btn rc-folder-btn-danger" title="删除" onclick="ResourceCenter.deleteFolderConfirm(${node.id})">🗑️</button>
                </span>
            </div>`;
            
            if (hasChildren && isExpanded) {
                html += renderTreeNodes(node.children, category, level + 1);
            }
        });
        
        return html;
    }
    
    // 加载文件列表
    async function loadFiles() {
        try {
            let url = `${config.apiUrl}?project_id=${config.projectId}&file_category=${state.currentCategory}`;
            // 总是传递parent_folder_id，null时传0表示根目录
            url += `&parent_folder_id=${state.currentFolder || 0}`;
            if (state.filterStatus) {
                url += `&approval_status=${state.filterStatus}`;
            }
            // 添加时间戳防止缓存
            url += `&_t=${Date.now()}`;
            
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                renderFileList(result.data || []);
            }
        } catch (error) {
            console.error('[RC_DEBUG] Load files error:', error);
        }
    }
    
    // 渲染文件列表
    function renderFileList(files) {
        const container = document.getElementById('rcFileList');
        if (!container) {
            console.error('[RC_DEBUG] rcFileList container not found');
            return;
        }
        console.log('[RC_DEBUG] renderFileList called with', files.length, 'files');
        
        // 只显示文件，不显示文件夹
        const onlyFiles = files.filter(f => f.is_folder != 1);
        
        // 应用搜索过滤
        let filtered = onlyFiles;
        if (state.searchText) {
            const search = state.searchText.toLowerCase();
            filtered = onlyFiles.filter(f => f.deliverable_name.toLowerCase().includes(search));
        }
        
        if (filtered.length === 0) {
            container.innerHTML = `
            <div class="rc-empty-state">
                <div class="rc-drop-zone-enhanced">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <h4>暂无文件</h4>
                    <p>拖拽文件或文件夹到此处上传</p>
                    <div class="rc-empty-actions">
                        <button class="rc-btn primary" onclick="ResourceCenter.openUploadDialog()">
                            <i class="bi bi-file-earmark-plus"></i> 上传文件
                        </button>
                        <button class="rc-btn secondary" onclick="ResourceCenter.openFolderDialog()">
                            <i class="bi bi-folder-plus"></i> 上传文件夹
                        </button>
                    </div>
                </div>
            </div>`;
            return;
        }
        
        const isArtwork = state.currentCategory === 'artwork_file';
        
        let html = `
        <table class="rc-table">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" onchange="ResourceCenter.toggleSelectAll(this)"></th>
                    <th>文件名</th>
                    <th style="width: 100px;">类型</th>
                    <th style="width: 80px;">状态</th>
                    <th style="width: 100px;">大小</th>
                    <th style="width: 140px;">上传时间</th>
                    <th style="width: 220px;">操作</th>
                </tr>
            </thead>
            <tbody>`;
        
        filtered.forEach(file => {
            const statusHtml = renderStatus(file);
            const sizeHtml = formatFileSize(file.file_size);
            const timeHtml = file.submitted_at ? new Date(file.submitted_at * 1000).toLocaleDateString('zh-CN') : '-';
            const isChecked = state.selectedFiles.has(file.id);
            
            html += `
            <tr data-id="${file.id}">
                <td><input type="checkbox" class="rc-file-checkbox" value="${file.id}" ${isChecked ? 'checked' : ''} onchange="ResourceCenter.toggleFileSelect(${file.id})"></td>
                <td>
                    <i class="bi ${getFileIcon(file.file_path)}"></i>
                    <span class="rc-file-name" onclick="ResourceCenter.previewFile(${file.id})">${escapeHtml(file.deliverable_name)}</span>
                </td>
                <td>${file.deliverable_type || '-'}</td>
                <td>${statusHtml}</td>
                <td>${sizeHtml}</td>
                <td>${timeHtml}</td>
                <td class="rc-actions-cell">
                    <button class="rc-action-btn" onclick="ResourceCenter.previewFile(${file.id})">预览</button>
                    <button class="rc-action-btn" onclick="ResourceCenter.downloadFile(${file.id})">下载</button>
                    ${isArtwork && config.isAdmin && file.approval_status === 'pending' ? `
                    <button class="rc-action-btn rc-action-btn-success" onclick="ResourceCenter.approveFile(${file.id})">通过</button>
                    <button class="rc-action-btn rc-action-btn-danger" onclick="ResourceCenter.rejectFile(${file.id})">驳回</button>
                    ` : ''}
                    ${isArtwork && config.isAdmin && (file.approval_status === 'approved' || file.approval_status === 'rejected') ? `
                    <button class="rc-action-btn rc-action-btn-warning" onclick="ResourceCenter.resetApproval(${file.id})">重置</button>
                    ` : ''}
                    <button class="rc-action-btn rc-action-btn-danger" onclick="ResourceCenter.deleteFile(${file.id})">删除</button>
                </td>
            </tr>`;
        });
        
        html += '</tbody></table>';
        
        // 添加拖拽上传区域
        html += `
        <div class="rc-upload-hint">
            <i class="bi bi-cloud-upload"></i> 拖拽文件到表格区域上传
        </div>`;
        
        container.innerHTML = html;
    }
    
    // 渲染状态
    function renderStatus(file) {
        const statusMap = {
            'pending': { class: 'warning', text: '待审批' },
            'approved': { class: 'success', text: '已通过' },
            'rejected': { class: 'danger', text: '已驳回' }
        };
        const info = statusMap[file.approval_status] || { class: 'secondary', text: file.approval_status };
        let html = `<span class="rc-status ${info.class}">${info.text}</span>`;
        if (file.approval_status === 'rejected' && file.reject_reason) {
            html += `<i class="bi bi-info-circle text-danger ms-1" title="${escapeHtml(file.reject_reason)}"></i>`;
        }
        return html;
    }
    
    // 更新统计数
    function updateCounts() {
        // 计算各状态数量
        let pending = 0, approved = 0, rejected = 0;
        
        function countStatus(nodes) {
            nodes.forEach(node => {
                if (node.is_folder != 1) {
                    if (node.approval_status === 'pending') pending++;
                    else if (node.approval_status === 'approved') approved++;
                    else if (node.approval_status === 'rejected') rejected++;
                }
                if (node.children) countStatus(node.children);
            });
        }
        
        countStatus(state.treeData.artwork_file);
        
        const pendingEl = document.getElementById('rcPendingCount');
        const approvedEl = document.getElementById('rcApprovedCount');
        const rejectedEl = document.getElementById('rcRejectedCount');
        
        if (pendingEl) pendingEl.textContent = pending;
        if (approvedEl) approvedEl.textContent = approved;
        if (rejectedEl) rejectedEl.textContent = rejected;
    }
    
    // 选择分类
    function selectCategory(category) {
        state.currentCategory = category;
        state.currentFolder = null;
        
        // 更新分类Tab样式
        document.querySelectorAll('.rc-category-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.category === category);
        });
        
        // 更新树选中状态
        document.querySelectorAll('.rc-tree-node').forEach(el => el.classList.remove('selected'));
        
        // 重新渲染当前分类的目录树
        renderTree(category);
        
        loadFiles();
    }
    
    // 选择文件夹
    function selectFolder(folderId, category) {
        state.currentFolder = folderId;
        state.currentCategory = category;
        
        // 更新树选中状态
        document.querySelectorAll('.rc-tree-node').forEach(el => el.classList.remove('selected'));
        const node = document.querySelector(`.rc-tree-node[data-id="${folderId}"]`);
        if (node) node.classList.add('selected');
        
        loadFiles();
    }
    
    // 展开/收起文件夹
    function toggleFolder(folderId) {
        if (state.expandedFolders.has(folderId)) {
            state.expandedFolders.delete(folderId);
        } else {
            state.expandedFolders.add(folderId);
        }
        renderTree('artwork_file');
        renderTree('model_file');
    }
    
    // 状态筛选
    function filterByStatus(status) {
        state.filterStatus = status;
        
        // 更新Tab样式
        document.querySelectorAll('.rc-tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.status === status);
        });
        
        loadFiles();
    }
    
    // 搜索
    function search(text) {
        state.searchText = text;
        loadFiles();
    }
    
    // 统一文件传输实例（使用新的 FileTransfer SDK）
    let fileTransfer = null;
    function getFileTransfer() {
        if (!fileTransfer && typeof FileTransfer !== 'undefined') {
            fileTransfer = new FileTransfer({ apiBase: '/api/' });
        }
        return fileTransfer;
    }
    
    // 上传文件（使用统一文件传输服务）
    async function uploadFiles(files) {
        console.log('[RC_DEBUG] uploadFiles called, files:', files.length, 'projectId:', config.projectId, 'category:', state.currentCategory);
        
        if (!config.projectId || config.projectId <= 0) {
            console.error('[RC_DEBUG] 无效的projectId:', config.projectId);
            showAlertModal('上传失败: 项目ID无效', 'error');
            return;
        }
        
        // 大文件阈值 2GB（超过2GB才使用S3直连分片上传）
        const CHUNK_THRESHOLD = 2 * 1024 * 1024 * 1024;
        
        for (const file of files) {
            console.log('[RC_DEBUG] 上传文件:', file.name, 'size:', file.size);
            
            // 显示上传进度弹窗
            showUploadProgressModal(file.name, file.size);
            updateUploadProgress(0, '计算文件哈希...', '', '');
            
            try {
                // 1. 计算文件哈希
                const fileHash = await calculateFileHash(file);
                console.log('[RC_DEBUG] 文件哈希:', fileHash);
                
                // 2. 检查是否可以秒传
                const checkResult = await checkFileHash(file, fileHash);
                if (checkResult.instant) {
                    // 秒传成功
                    console.log('[RC_DEBUG] 秒传成功:', file.name);
                    updateUploadProgress(100, formatFileSize(file.size), formatFileSize(file.size), '秒传');
                    hideUploadProgressModal();
                    showAlertModal(`秒传成功: ${file.name}`, 'success');
                    continue;
                }
                
                // 3. 正常上传
                if (file.size > CHUNK_THRESHOLD) {
                    // 大文件使用分片上传
                    await uploadLargeFileWithProgress(file, fileHash);
                } else {
                    // 小文件使用传统方式
                    await uploadFileLegacy(file, fileHash);
                }
                
                hideUploadProgressModal();
                
            } catch (error) {
                console.error('[RC_DEBUG] Upload error:', error);
                hideUploadProgressModal();
                showAlertModal('上传失败: ' + error.message, 'error');
            }
        }
        
        // 刷新
        loadTree(state.currentCategory);
        loadFiles();
        
        if (config.onUploadSuccess) {
            config.onUploadSuccess();
        }
    }
    
    // 在数据库中创建交付文件记录
    async function createDeliverableRecord(file, storageKey, uploadResult) {
        const response = await fetch(config.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_from_upload',
                project_id: config.projectId,
                file_category: state.currentCategory,
                parent_folder_id: state.currentFolder || 0,
                filename: file.name,
                filesize: file.size,
                storage_key: storageKey,
                mime_type: file.type || 'application/octet-stream'
            })
        });
        return response.json();
    }
    
    // 计算文件 SHA256 哈希
    async function calculateFileHash(file) {
        const chunkSize = 2 * 1024 * 1024; // 2MB chunks for reading
        const chunks = Math.ceil(file.size / chunkSize);
        
        // 使用 SubtleCrypto API
        const hashBuffer = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }
    
    // 检查文件哈希（秒传检查）
    async function checkFileHash(file, fileHash) {
        const apiBase = config.apiUrl.replace('deliverables.php', '');
        const response = await fetch(`${apiBase}rc_upload_check_hash.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                file_hash: fileHash,
                project_id: config.projectId,
                file_category: state.currentCategory,
                parent_folder_id: state.currentFolder || 0,
                filename: file.name,
                filesize: file.size
            })
        });
        const result = await response.json();
        if (result.success && result.data) {
            return result.data;
        }
        return { exists: false, instant: false };
    }
    
    // 上传方式（使用 XMLHttpRequest 支持进度显示）
    function uploadFileLegacy(file, fileHash) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', config.projectId);
            formData.append('deliverable_name', file.name);
            formData.append('file_category', state.currentCategory);
            if (state.currentFolder) {
                formData.append('parent_folder_id', state.currentFolder);
            }
            if (fileHash) {
                formData.append('file_hash', fileHash);
            }
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', config.apiUrl, true);
            
            let startTime = Date.now();
            let lastLoaded = 0;
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    const elapsed = (Date.now() - startTime) / 1000;
                    const speed = elapsed > 0 ? e.loaded / elapsed : 0;
                    updateUploadProgress(
                        pct,
                        formatFileSize(e.loaded),
                        formatFileSize(e.total),
                        formatFileSize(speed) + '/s'
                    );
                }
            };
            
            xhr.onload = function() {
                console.log('[RC_DEBUG] xhr.onload triggered, status:', xhr.status, 'response:', xhr.responseText.substring(0, 200));
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (result.success) {
                        resolve(result);
                    } else {
                        reject(new Error(result.message || '上传失败'));
                    }
                } catch (e) {
                    console.error('[RC_DEBUG] JSON parse error:', e, 'response:', xhr.responseText);
                    reject(new Error('服务器响应异常'));
                }
            };
            
            xhr.onerror = function() {
                console.error('[RC_DEBUG] xhr.onerror triggered');
                reject(new Error('网络错误'));
            };
            
            xhr.onreadystatechange = function() {
                console.log('[RC_DEBUG] readyState:', xhr.readyState, 'status:', xhr.status);
            };
            
            xhr.send(formData);
        });
    }
    
    // 上传进度弹窗
    function showUploadProgressModal(filename, filesize) {
        let modal = document.getElementById('rcUploadProgressModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'rcUploadProgressModal';
            modal.className = 'modal fade';
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title">文件上传</h6>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2"><strong id="rcUploadFilename"></strong></div>
                            <div class="progress mb-2" style="height: 20px;">
                                <div id="rcUploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%">0%</div>
                            </div>
                            <div class="small text-muted">
                                <span id="rcUploadTransferred">0 B</span> / <span id="rcUploadTotal">0 B</span>
                                <span class="float-end" id="rcUploadSpeed"></span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        document.getElementById('rcUploadFilename').textContent = filename;
        document.getElementById('rcUploadTotal').textContent = formatFileSize(filesize);
        document.getElementById('rcUploadProgressBar').style.width = '0%';
        document.getElementById('rcUploadProgressBar').textContent = '0%';
        new bootstrap.Modal(modal).show();
    }
    
    function updateUploadProgress(progress, transferred, total, speed) {
        const bar = document.getElementById('rcUploadProgressBar');
        if (bar) {
            bar.style.width = progress + '%';
            bar.textContent = progress + '%';
        }
        const transferredEl = document.getElementById('rcUploadTransferred');
        if (transferredEl) transferredEl.textContent = transferred || '';
        const speedEl = document.getElementById('rcUploadSpeed');
        if (speedEl) speedEl.textContent = speed || '';
    }
    
    function hideUploadProgressModal() {
        const modal = document.getElementById('rcUploadProgressModal');
        if (modal) {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
            } else {
                // 如果没有实例，直接隐藏
                modal.classList.remove('show');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
                const backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) backdrop.remove();
            }
        }
    }
    
    // 大文件分片上传（带进度显示）
    async function uploadLargeFileWithProgress(file, fileHash) {
        const apiBase = config.apiUrl.replace('deliverables.php', '');
        const startTime = Date.now();
        
        // 1. 初始化分片上传
        const initResponse = await fetch(`${apiBase}rc_upload_init.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                project_id: config.projectId,
                filename: file.name,
                filesize: file.size,
                file_category: state.currentCategory,
                parent_folder_id: state.currentFolder || 0,
                mime_type: file.type || 'application/octet-stream'
            })
        });
        
        const initResult = await initResponse.json();
        if (!initResult.success) {
            throw new Error(initResult.message || '初始化上传失败');
        }
        
        const { upload_id, storage_key, part_size, total_parts, mode, presigned_urls } = initResult.data;
        console.log('[RC_DEBUG] 分片上传初始化:', upload_id, 'total_parts:', total_parts, 'mode:', mode);
        
        // 2. 上传各分片
        const parts = [];
        let totalUploaded = 0;
        const isDirect = mode === 'direct' && presigned_urls;
        
        for (let partNumber = 1; partNumber <= total_parts; partNumber++) {
            const start = (partNumber - 1) * part_size;
            const end = Math.min(start + part_size, file.size);
            const chunk = file.slice(start, end);
            const chunkSize = end - start;
            
            let etag = '';
            if (isDirect && presigned_urls[partNumber]) {
                // 直连模式：直接上传到 S3
                etag = await uploadChunkDirect(presigned_urls[partNumber], chunk, (loaded) => {
                    const currentTotal = totalUploaded + loaded;
                    const pct = Math.round((currentTotal / file.size) * 100);
                    const elapsed = (Date.now() - startTime) / 1000;
                    const speed = elapsed > 0 ? currentTotal / elapsed : 0;
                    updateUploadProgress(pct, formatFileSize(currentTotal), formatFileSize(file.size), formatFileSize(speed) + '/s');
                });
            } else {
                // 代理模式：通过后端代理上传
                const chunkResult = await uploadChunkWithProgress(apiBase, upload_id, storage_key, partNumber, chunk, (loaded) => {
                    const currentTotal = totalUploaded + loaded;
                    const pct = Math.round((currentTotal / file.size) * 100);
                    const elapsed = (Date.now() - startTime) / 1000;
                    const speed = elapsed > 0 ? currentTotal / elapsed : 0;
                    updateUploadProgress(pct, formatFileSize(currentTotal), formatFileSize(file.size), formatFileSize(speed) + '/s');
                });
                etag = chunkResult.data?.etag || '';
            }
            
            totalUploaded += chunkSize;
            parts.push({ PartNumber: partNumber, ETag: etag });
        }
        
        // 3. 完成上传
        const completeResponse = await fetch(`${apiBase}rc_upload_complete.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                upload_id,
                storage_key,
                parts,
                project_id: config.projectId,
                file_category: state.currentCategory,
                parent_folder_id: state.currentFolder || 0,
                filename: file.name,
                filesize: file.size,
                file_hash: fileHash || ''
            })
        });
        
        const completeResult = await completeResponse.json();
        if (!completeResult.success) {
            throw new Error(completeResult.message || '完成上传失败');
        }
        
        console.log('[RC_DEBUG] 大文件上传成功:', file.name);
    }
    
    // 上传单个分片（带进度回调）
    function uploadChunkWithProgress(apiBase, uploadId, storageKey, partNumber, chunk, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const proxyUrl = `${apiBase}rc_upload_part_proxy.php?upload_id=${encodeURIComponent(uploadId)}&storage_key=${encodeURIComponent(storageKey)}&part_number=${partNumber}`;
            
            xhr.open('POST', proxyUrl, true);
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable && onProgress) {
                    onProgress(e.loaded);
                }
            };
            
            xhr.onload = function() {
                try {
                    const result = JSON.parse(xhr.responseText);
                    if (result.success) {
                        resolve(result);
                    } else {
                        reject(new Error(result.message || `分片 ${partNumber} 上传失败`));
                    }
                } catch (e) {
                    reject(new Error('服务器响应异常'));
                }
            };
            
            xhr.onerror = function() {
                reject(new Error('网络错误'));
            };
            
            xhr.send(chunk);
        });
    }
    
    // 直连模式上传分片到 S3
    function uploadChunkDirect(presignedUrl, chunk, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', presignedUrl, true);
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable && onProgress) {
                    onProgress(e.loaded);
                }
            };
            
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    // 从响应头获取 ETag
                    const etag = xhr.getResponseHeader('ETag') || '';
                    resolve(etag.replace(/"/g, ''));
                } else {
                    reject(new Error(`S3 上传失败: HTTP ${xhr.status}`));
                }
            };
            
            xhr.onerror = function() {
                reject(new Error('网络错误'));
            };
            
            xhr.send(chunk);
        });
    }
    
    // 打开上传对话框
    function openUploadDialog() {
        const input = document.getElementById('rcFileInput');
        if (input) {
            input.click();
        }
    }
    
    // 打开文件夹上传对话框
    function openFolderDialog() {
        const input = document.getElementById('rcFolderInput');
        if (input) {
            input.click();
        }
    }
    
    // 处理文件选择
    async function handleFileSelect(input) {
        if (input.files.length > 0) {
            await uploadFiles(input.files);
            input.value = '';
        }
    }
    
    // 处理文件夹选择
    async function handleFolderSelect(input) {
        if (input.files.length > 0) {
            await uploadFolderFiles(input.files);
            input.value = '';
        }
    }
    
    // 解析文件夹结构（与customer-files.js保持一致）
    function analyzeFolderPayload(files) {
        const fileArray = Array.from(files);
        const folderPaths = [];
        let folderRoot = '';
        let hasFolderUpload = false;
        let totalBytes = 0;
        
        fileArray.forEach(file => {
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
        
        // 确保folderPaths长度与files一致
        while (folderPaths.length < fileArray.length) {
            folderPaths.push('');
        }
        
        return { hasFolderUpload, folderPaths, folderRoot, totalBytes };
    }
    
    // 分类到资产类型映射
    const CATEGORY_TO_ASSET_TYPE = {
        'customer_file': 'customer',
        'artwork_file': 'artwork',
        'model_file': 'model',
    };
    
    // 上传文件夹（使用统一API）
    async function uploadFolderFiles(files) {
        // 检查是否存在FolderUploader
        if (typeof FolderUploader === 'undefined') {
            console.error('[RC_DEBUG] FolderUploader未加载，回退到传统上传');
            return uploadFolderFilesLegacy(files);
        }
        
        // 提取文件列表
        const fileList = FolderUploader.extractFilesFromInput(document.getElementById('rcFolderInput'));
        
        if (fileList.length === 0) {
            showAlertModal('未选择任何文件', 'warning');
            return;
        }
        
        console.log('[RC_DEBUG] 使用统一API上传文件夹:', fileList.length, '个文件');
        
        // 显示文件夹上传进度弹窗
        showFolderUploadProgressModal(fileList.length);
        
        try {
            // 获取groupCode（从页面或API获取）
            const groupCode = await getProjectGroupCode(config.projectId);
            
            // 调用统一的文件夹上传API
            const results = await FolderUploader.uploadFolder(fileList, {
                groupCode: groupCode,
                projectId: config.projectId,
                assetType: CATEGORY_TO_ASSET_TYPE[state.currentCategory] || 'artwork',
            }, (progress) => {
                // 进度回调
                if (progress.type === 'file_start') {
                    console.log(`[RC_DEBUG] 开始上传 ${progress.current}/${progress.total}: ${progress.filename}`);
                    updateFolderUploadProgress(progress.current, progress.total, progress.filename, '上传中...');
                } else if (progress.type === 'file_complete') {
                    console.log(`[RC_DEBUG] 完成上传 ${progress.current}/${progress.total}: ${progress.filename}`);
                    updateFolderUploadProgress(progress.current, progress.total, progress.filename, '完成');
                }
            });
            
            // 统计结果
            const successCount = results.filter(r => r.success).length;
            const failCount = results.filter(r => !r.success).length;
            
            hideFolderUploadProgressModal();
            
            if (failCount === 0) {
                showAlertModal(`上传成功: ${successCount} 个文件`, 'success');
            } else {
                showAlertModal(`上传完成: ${successCount} 成功, ${failCount} 失败`, 'warning');
            }
            
            // 刷新列表
            loadTree(state.currentCategory);
            loadFiles();
            
            if (config.onUploadSuccess) {
                config.onUploadSuccess();
            }
            
        } catch (error) {
            console.error('[RC_DEBUG] 文件夹上传失败:', error);
            hideFolderUploadProgressModal();
            showAlertModal('上传失败: ' + error.message, 'error');
        }
    }
    
    // 显示文件夹上传进度弹窗
    function showFolderUploadProgressModal(totalFiles) {
        let modal = document.getElementById('rcFolderUploadProgressModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'rcFolderUploadProgressModal';
            modal.className = 'modal fade';
            modal.setAttribute('data-bs-backdrop', 'static');
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">文件夹上传</h5>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <span id="rcFolderCurrentFile">准备上传...</span>
                            </div>
                            <div class="progress mb-2" style="height: 20px;">
                                <div id="rcFolderProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                            </div>
                            <div class="d-flex justify-content-between text-muted small">
                                <span id="rcFolderProgressCount">0 / ${totalFiles}</span>
                                <span id="rcFolderProgressStatus">准备中...</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
    
    // 更新文件夹上传进度
    function updateFolderUploadProgress(current, total, filename, status) {
        const bar = document.getElementById('rcFolderProgressBar');
        const countEl = document.getElementById('rcFolderProgressCount');
        const statusEl = document.getElementById('rcFolderProgressStatus');
        const fileEl = document.getElementById('rcFolderCurrentFile');
        
        const pct = Math.round((current / total) * 100);
        if (bar) {
            bar.style.width = pct + '%';
            bar.textContent = pct + '%';
        }
        if (countEl) countEl.textContent = `${current} / ${total}`;
        if (statusEl) statusEl.textContent = status || '';
        if (fileEl) fileEl.textContent = filename || '';
    }
    
    // 隐藏文件夹上传进度弹窗
    function hideFolderUploadProgressModal() {
        const modal = document.getElementById('rcFolderUploadProgressModal');
        if (modal) {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) bsModal.hide();
        }
    }
    
    // 拖拽文件夹上传（带进度显示）
    async function uploadDraggedFolder(files, folderPaths, folderRoot) {
        const totalFiles = files.length;
        console.log('[RC_DEBUG] 拖拽文件夹上传:', totalFiles, '个文件');
        
        showFolderUploadProgressModal(totalFiles);
        
        let successCount = 0;
        let failCount = 0;
        
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const relativePath = folderPaths[i] || '';
            
            updateFolderUploadProgress(i + 1, totalFiles, file.name, '上传中...');
            
            try {
                // 计算哈希
                const fileHash = await calculateFileHash(file);
                
                // 检查秒传
                const checkResult = await checkFileHash(file, fileHash);
                if (checkResult.instant) {
                    successCount++;
                    updateFolderUploadProgress(i + 1, totalFiles, file.name, '秒传');
                    continue;
                }
                
                // 正常上传
                const CHUNK_THRESHOLD = 2 * 1024 * 1024 * 1024; // 2GB
                if (file.size > CHUNK_THRESHOLD) {
                    await uploadLargeFileWithProgress(file, fileHash);
                } else {
                    await uploadFileLegacy(file, fileHash);
                }
                successCount++;
                updateFolderUploadProgress(i + 1, totalFiles, file.name, '完成');
            } catch (error) {
                console.error('[RC_DEBUG] 文件上传失败:', file.name, error);
                failCount++;
                updateFolderUploadProgress(i + 1, totalFiles, file.name, '失败');
            }
        }
        
        hideFolderUploadProgressModal();
        
        if (failCount === 0) {
            showAlertModal(`上传成功: ${successCount} 个文件`, 'success');
        } else {
            showAlertModal(`上传完成: ${successCount} 成功, ${failCount} 失败`, 'warning');
        }
        
        // 刷新
        loadTree(state.currentCategory);
        loadFiles();
        
        if (config.onUploadSuccess) {
            config.onUploadSuccess();
        }
    }
    
    // 获取项目的groupCode
    async function getProjectGroupCode(projectId) {
        try {
            const response = await fetch(`/api/projects.php?action=get&id=${projectId}`, {
                credentials: 'include'
            });
            const result = await response.json();
            if (result.success && result.data) {
                return result.data.group_code || result.data.company_code || '';
            }
        } catch (error) {
            console.error('[RC_DEBUG] 获取项目groupCode失败:', error);
        }
        return '';
    }
    
    // 传统文件夹上传（回退方案）
    async function uploadFolderFilesLegacy(files) {
        const fileArray = Array.from(files);
        const folderInfo = analyzeFolderPayload(files);
        
        console.log('[RC_DEBUG] Legacy folder upload:', folderInfo);
        
        // 创建FormData
        const formData = new FormData();
        formData.append('project_id', config.projectId);
        formData.append('file_category', state.currentCategory);
        formData.append('upload_mode', 'folder');
        formData.append('folder_root', folderInfo.folderRoot);
        
        if (state.currentFolder) {
            formData.append('parent_folder_id', state.currentFolder);
        }
        
        // 添加文件夹路径
        folderInfo.folderPaths.forEach(path => {
            formData.append('folder_paths[]', path);
        });
        
        // 添加所有文件
        fileArray.forEach(file => {
            formData.append('files[]', file);
            // 保存相对路径用于后端处理
            if (file.webkitRelativePath) {
                formData.append('file_paths[]', file.webkitRelativePath);
            }
        });
        
        try {
            console.log('[RC_DEBUG] 开始上传', fileArray.length, '个文件到', config.apiUrl);
            showAlertModal(`正在上传 ${fileArray.length} 个文件...`, 'info');
            
            const response = await fetch(config.apiUrl, {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            console.log('[RC_DEBUG] 响应状态:', response.status, response.statusText);
            
            const responseText = await response.text();
            console.log('[RC_DEBUG] 响应内容:', responseText.substring(0, 500));
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('[RC_DEBUG] JSON解析失败:', parseError, '原始响应:', responseText);
                showAlertModal('服务器返回格式错误', 'error');
                return;
            }
            
            if (result.success) {
                showAlertModal(`上传成功: ${result.uploaded_count || fileArray.length} 个文件`, 'success');
                loadTree(state.currentCategory);
                loadFiles();
            } else {
                console.error('[RC_DEBUG] 上传失败:', result);
                showAlertModal('上传失败: ' + (result.message || '未知错误'), 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] 上传网络错误:', error);
            showAlertModal('上传失败: ' + error.message, 'error');
        }
    }
    
    // 创建文件夹
    function createFolder() {
        showPromptModal('请输入文件夹名称', '', async function(name) {
            if (!name || !name.trim()) return;
            
            try {
                const response = await fetch(`${config.apiUrl}?action=folder`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        project_id: config.projectId,
                        folder_name: name.trim(),
                        parent_folder_id: state.currentFolder,
                        file_category: state.currentCategory
                    })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal('文件夹创建成功', 'success');
                    loadTree(state.currentCategory);
                } else {
                    showAlertModal(result.message || '创建失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Create folder error:', error);
                showAlertModal('创建文件夹失败', 'error');
            }
        });
    }
    
    // 显示文件夹右键菜单
    function showFolderMenu(event, folderId, category) {
        event.preventDefault();
        showConfirmModal('文件夹操作', '请选择操作：<br><br><button class="btn btn-sm btn-outline-primary me-2" onclick="ResourceCenter.renameFolder(' + folderId + '); bootstrap.Modal.getInstance(document.getElementById(\'confirmModal\')).hide();">重命名</button><button class="btn btn-sm btn-outline-danger" onclick="ResourceCenter.deleteFolderConfirm(' + folderId + '); bootstrap.Modal.getInstance(document.getElementById(\'confirmModal\')).hide();">删除</button>', null, null);
    }
    
    // 重命名文件夹
    function renameFolder(folderId) {
        showPromptModal('请输入新名称', '', async function(newName) {
            if (!newName || !newName.trim()) return;
            
            try {
                const response = await fetch(`${config.apiUrl}?action=folder`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: folderId, folder_name: newName.trim() })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal('重命名成功', 'success');
                    loadTree(state.currentCategory);
                } else {
                    showAlertModal(result.message || '重命名失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Rename folder error:', error);
                showAlertModal('重命名失败', 'error');
            }
        });
    }
    
    // 删除文件夹确认
    function deleteFolderConfirm(folderId) {
        showConfirmModal('确认删除', '确定要删除此文件夹及其所有内容吗？删除后将移入回收站。', function() {
            deleteFolder(folderId);
        });
    }
    
    // 删除文件夹
    async function deleteFolder(folderId) {
        try {
            const response = await fetch(`${config.apiUrl}?action=folder&id=${folderId}`, {
                method: 'DELETE'
            });
            const result = await response.json();
            
            if (result.success) {
                showAlertModal('已移入回收站', 'success');
                if (state.currentFolder === folderId) {
                    state.currentFolder = null;
                }
                // 刷新两个类别的目录树
                loadTree('artwork_file');
                loadTree('model_file');
                loadFiles();
            } else {
                showAlertModal(result.message || '删除失败', 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Delete folder error:', error);
            showAlertModal('删除失败', 'error');
        }
    }
    
    // 预览文件
    async function previewFile(fileId) {
        if (!fileId) return;
        try {
            const response = await fetch(`${config.apiUrl}?action=download&id=${fileId}`);
            const result = await response.json();
            if (result.success && result.data && result.data.url) {
                window.open(result.data.url, '_blank');
            } else {
                showAlertModal(result.message || '预览失败', 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Preview error:', error);
            showAlertModal('预览失败', 'error');
        }
    }
    
    // 下载文件
    async function downloadFile(fileId) {
        try {
            const response = await fetch(`${config.apiUrl}?action=download&id=${fileId}`);
            const result = await response.json();
            
            if (result.success && result.data && result.data.url) {
                // 创建临时链接下载
                const a = document.createElement('a');
                a.href = result.data.url;
                a.download = result.data.filename || '';
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } else {
                showAlertModal(result.message || '下载失败', 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Download error:', error);
            showAlertModal('下载失败', 'error');
        }
    }
    
    // 重命名文件
    function renameFile(fileId, currentName) {
        showPromptModal('请输入新名称', currentName, async function(newName) {
            if (!newName || newName === currentName) return;
            
            try {
                const response = await fetch(`${config.apiUrl}?action=rename`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: fileId, new_name: newName })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal('重命名成功', 'success');
                    loadTree(state.currentCategory);
                    loadFiles();
                } else {
                    showAlertModal(result.message || '重命名失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Rename error:', error);
                showAlertModal('重命名失败', 'error');
            }
        });
    }
    
    // 删除文件
    function deleteFile(fileId) {
        showConfirmModal('确认删除', '确定要删除此文件吗？删除后将移入回收站。', async function() {
            try {
                const response = await fetch(`${config.apiUrl}?id=${fileId}`, {
                    method: 'DELETE'
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal('已移入回收站', 'success');
                    loadTree(state.currentCategory);
                    loadFiles();
                } else {
                    showAlertModal(result.message || '删除失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Delete file error:', error);
                showAlertModal('删除失败', 'error');
            }
        });
    }
    
    // 审批通过
    async function approveFile(fileId) {
        try {
            const response = await fetch(`${config.apiUrl}?action=approve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: fileId, approve_action: 'approve' })
            });
            const result = await response.json();
            
            if (result.success) {
                showAlertModal('审批通过', 'success');
                loadTree(state.currentCategory);
                loadFiles();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Approve error:', error);
        }
    }
    
    // 审批驳回
    function rejectFile(fileId) {
        showPromptModal('请输入驳回原因（可选）', '', async function(reason) {
            try {
                const response = await fetch(`${config.apiUrl}?action=approve`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: fileId, approve_action: 'reject', reject_reason: reason || '' })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal('已驳回', 'success');
                    loadTree(state.currentCategory);
                    loadFiles();
                } else {
                    showAlertModal(result.message || '驳回失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Reject error:', error);
                showAlertModal('驳回失败', 'error');
            }
        });
    }
    
    // 重置审批状态（调回待审批）
    async function resetApproval(fileId) {
        if (!confirm('确定要将此文件状态重置为"待审批"吗？')) return;
        
        try {
            const response = await fetch(`${config.apiUrl}?action=reset_approval`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: fileId })
            });
            const result = await response.json();
            
            if (result.success) {
                showAlertModal('已重置为待审批', 'success');
                loadTree(state.currentCategory);
                loadFiles();
            } else {
                showAlertModal(result.message || '重置失败', 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Reset approval error:', error);
            showAlertModal('重置失败', 'error');
        }
    }
    
    // 切换全选
    function toggleSelectAll(checkbox) {
        const checkboxes = document.querySelectorAll('.rc-file-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
            if (checkbox.checked) {
                state.selectedFiles.add(parseInt(cb.value));
            } else {
                state.selectedFiles.delete(parseInt(cb.value));
            }
        });
        updateBatchBar();
    }
    
    // 切换单个选择
    function toggleFileSelect(fileId) {
        if (state.selectedFiles.has(fileId)) {
            state.selectedFiles.delete(fileId);
        } else {
            state.selectedFiles.add(fileId);
        }
        updateBatchBar();
    }
    
    // 更新批量操作栏
    function updateBatchBar() {
        const bar = document.getElementById('rcBatchBar');
        const count = document.getElementById('rcSelectedCount');
        if (bar && count) {
            count.textContent = state.selectedFiles.size;
            bar.style.display = state.selectedFiles.size > 0 ? 'flex' : 'none';
        }
    }
    
    // 清除选择
    function clearSelection() {
        state.selectedFiles.clear();
        document.querySelectorAll('.rc-file-checkbox').forEach(cb => cb.checked = false);
        updateBatchBar();
    }
    
    // 批量通过
    async function batchApprove() {
        const ids = Array.from(state.selectedFiles);
        if (ids.length === 0) return;
        
        if (!confirm(`确定要通过这 ${ids.length} 个文件吗？`)) return;
        
        try {
            const response = await fetch(`${config.apiUrl}?action=batch_approve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids, approve_action: 'approve' })
            });
            const result = await response.json();
            
            if (result.success) {
                showAlertModal(`已通过 ${result.affected} 个文件`, 'success');
                clearSelection();
                loadTree(state.currentCategory);
                loadFiles();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Batch approve error:', error);
        }
    }
    
    // 批量驳回
    function batchReject() {
        const ids = Array.from(state.selectedFiles);
        if (ids.length === 0) return;
        
        showPromptModal(`请输入驳回原因（将应用到 ${ids.length} 个文件，可选）`, '', async function(reason) {
            try {
                const response = await fetch(`${config.apiUrl}?action=batch_approve`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids, approve_action: 'reject', reject_reason: reason || '' })
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlertModal(`已驳回 ${result.affected} 个文件`, 'success');
                    clearSelection();
                    loadTree(state.currentCategory);
                    loadFiles();
                } else {
                    showAlertModal(result.message || '批量驳回失败', 'error');
                }
            } catch (error) {
                console.error('[RC_DEBUG] Batch reject error:', error);
                showAlertModal('批量驳回失败', 'error');
            }
        });
    }
    
    // 显示提示消息（备用函数）
    function showAlertModal(message, type = 'info') {
        // 检查是否有全局的showAlertModal函数
        if (typeof window.showAlertModal === 'function') {
            window.showAlertModal(message, type);
            return;
        }
        // 备用方案：使用简单的alert或console
        console.log(`[RC_ALERT] [${type}] ${message}`);
        if (type === 'error') {
            alert('错误: ' + message);
        } else if (type === 'success') {
            // 创建简单的toast提示
            const toast = document.createElement('div');
            toast.className = 'rc-toast rc-toast-' + type;
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;top:20px;right:20px;padding:12px 20px;background:#10b981;color:white;border-radius:6px;z-index:10000;animation:fadeIn 0.3s;';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
    }
    
    // 工具函数
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function getFileIcon(filePath) {
        if (!filePath) return 'bi-file-earmark';
        const ext = filePath.split('.').pop().toLowerCase();
        const iconMap = {
            'jpg': 'bi-file-earmark-image', 'jpeg': 'bi-file-earmark-image', 'png': 'bi-file-earmark-image',
            'gif': 'bi-file-earmark-image', 'webp': 'bi-file-earmark-image', 'psd': 'bi-file-earmark-image',
            'pdf': 'bi-file-earmark-pdf', 'doc': 'bi-file-earmark-word', 'docx': 'bi-file-earmark-word',
            'xls': 'bi-file-earmark-excel', 'xlsx': 'bi-file-earmark-excel',
            'zip': 'bi-file-earmark-zip', 'rar': 'bi-file-earmark-zip', '7z': 'bi-file-earmark-zip',
            'max': 'bi-box', '3ds': 'bi-box', 'obj': 'bi-box', 'fbx': 'bi-box', 'blend': 'bi-box',
            'dwg': 'bi-file-earmark-richtext', 'dxf': 'bi-file-earmark-richtext'
        };
        return iconMap[ext] || 'bi-file-earmark';
    }
    
    function formatFileSize(bytes) {
        if (!bytes) return '-';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(1) + ' ' + units[i];
    }
    
    // 批量删除
    async function batchDelete() {
        const ids = Array.from(state.selectedFiles);
        if (ids.length === 0) {
            showAlertModal('请先选择要删除的文件', 'warning');
            return;
        }
        
        if (!confirm(`确定要删除选中的 ${ids.length} 个文件吗？`)) return;
        
        try {
            const response = await fetch(`${config.apiUrl}?action=batch_delete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids })
            });
            const result = await response.json();
            
            if (result.success) {
                showAlertModal(`已删除 ${result.deleted_count || ids.length} 个文件`, 'success');
                clearSelection();
                loadTree(state.currentCategory);
                loadFiles();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[RC_DEBUG] Batch delete error:', error);
            showAlertModal('批量删除失败', 'error');
        }
    }
    
    // 切换下拉菜单
    function toggleDropdown(btn) {
        const dropdown = btn.closest('.rc-dropdown');
        const menu = dropdown.querySelector('.rc-dropdown-menu');
        const isOpen = menu.classList.contains('show');
        
        // 关闭所有下拉菜单
        document.querySelectorAll('.rc-dropdown-menu.show').forEach(m => m.classList.remove('show'));
        
        if (!isOpen) {
            menu.classList.add('show');
            // 点击其他地方关闭
            setTimeout(() => {
                document.addEventListener('click', function closeDropdown(e) {
                    if (!dropdown.contains(e.target)) {
                        menu.classList.remove('show');
                        document.removeEventListener('click', closeDropdown);
                    }
                });
            }, 0);
        }
    }
    
    // 公开接口
    return {
        init,
        selectCategory,
        selectFolder,
        toggleFolder,
        filterByStatus,
        search,
        openUploadDialog,
        openFolderDialog,
        toggleUploadMenu,
        handleFileSelect,
        handleFolderSelect,
        createFolder,
        showFolderMenu,
        renameFolder,
        deleteFolderConfirm,
        previewFile,
        downloadFile,
        renameFile,
        deleteFile,
        approveFile,
        rejectFile,
        resetApproval,
        toggleSelectAll,
        toggleFileSelect,
        clearSelection,
        batchApprove,
        batchReject,
        batchDelete,
        toggleDropdown
    };
})();
