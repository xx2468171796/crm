/**
 * 文件树组件
 * 支持：目录树展示、文件夹操作、拖拽上传
 */
const FileTree = (function() {
    let config = {
        container: null,
        projectId: 0,
        fileCategory: 'artwork_file',
        requireApproval: true,
        apiUrl: '/api/deliverables.php',
        onUploadSuccess: null,
        onFileClick: null
    };
    
    let treeData = [];
    let expandedFolders = new Set();
    
    // 初始化
    function init(options) {
        config = { ...config, ...options };
        config.container = document.querySelector(options.container);
        
        if (!config.container) {
            console.error('[FILE_TREE] Container not found:', options.container);
            return;
        }
        
        setupDropZone();
        loadTree();
    }
    
    // 加载目录树数据
    async function loadTree() {
        try {
            const url = `${config.apiUrl}?action=tree&project_id=${config.projectId}&file_category=${config.fileCategory}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                treeData = result.data || [];
                render();
            } else {
                showError(result.message || '加载失败');
            }
        } catch (error) {
            console.error('[FILE_TREE] Load error:', error);
            showError('加载目录树失败');
        }
    }
    
    // 渲染目录树
    function render() {
        if (!config.container) return;
        
        let html = '<div class="file-tree-wrapper">';
        html += '<div class="file-tree-toolbar">';
        html += `<button type="button" class="btn btn-sm btn-outline-primary" onclick="FileTree.createFolder()">
            <i class="bi bi-folder-plus"></i> 新建文件夹
        </button>`;
        html += `<button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="FileTree.refresh()">
            <i class="bi bi-arrow-clockwise"></i> 刷新
        </button>`;
        html += '</div>';
        
        html += '<div class="file-tree-content" id="fileTreeContent">';
        if (treeData.length === 0) {
            html += '<div class="file-tree-empty">暂无文件，拖拽文件到此处上传</div>';
        } else {
            html += renderNodes(treeData, 0);
        }
        html += '</div>';
        html += '</div>';
        
        config.container.innerHTML = html;
        bindEvents();
    }
    
    // 递归渲染节点
    function renderNodes(nodes, level) {
        let html = '';
        nodes.forEach(node => {
            const isFolder = node.is_folder == 1;
            const isExpanded = expandedFolders.has(node.id);
            const hasChildren = node.children && node.children.length > 0;
            const indent = level * 20;
            
            html += `<div class="file-tree-node ${isFolder ? 'folder' : 'file'}" data-id="${node.id}" data-is-folder="${isFolder ? 1 : 0}" style="padding-left: ${indent}px;">`;
            
            if (isFolder) {
                html += `<span class="tree-toggle ${isExpanded ? 'expanded' : ''}" onclick="FileTree.toggleFolder(${node.id})">
                    <i class="bi ${isExpanded ? 'bi-chevron-down' : 'bi-chevron-right'}"></i>
                </span>`;
                html += `<span class="tree-icon folder-icon"><i class="bi bi-folder${isExpanded ? '-open' : ''}-fill text-warning"></i></span>`;
                html += `<span class="tree-name" ondblclick="FileTree.renameFolder(${node.id}, '${escapeHtml(node.deliverable_name)}')">${escapeHtml(node.deliverable_name)}</span>`;
                html += `<span class="tree-actions">
                    <button class="btn btn-sm btn-link" onclick="FileTree.createFolder(${node.id})" title="新建子文件夹"><i class="bi bi-folder-plus"></i></button>
                    <button class="btn btn-sm btn-link" onclick="FileTree.uploadToFolder(${node.id})" title="上传文件"><i class="bi bi-upload"></i></button>
                    <button class="btn btn-sm btn-link text-danger" onclick="FileTree.deleteNode(${node.id}, true)" title="删除"><i class="bi bi-trash"></i></button>
                </span>`;
            } else {
                html += `<span class="tree-toggle invisible"><i class="bi bi-dot"></i></span>`;
                html += `<span class="tree-icon file-icon"><i class="bi ${getFileIcon(node.file_path)}"></i></span>`;
                html += `<span class="tree-name" onclick="FileTree.openFile(${node.id})">${escapeHtml(node.deliverable_name)}</span>`;
                html += renderStatusBadge(node.approval_status, node.reject_reason);
                html += `<span class="tree-actions">
                    <button class="btn btn-sm btn-link" onclick="FileTree.previewFile(${node.id})" title="预览"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-sm btn-link text-danger" onclick="FileTree.deleteNode(${node.id}, false)" title="删除"><i class="bi bi-trash"></i></button>
                </span>`;
            }
            
            html += '</div>';
            
            // 渲染子节点
            if (isFolder && hasChildren && isExpanded) {
                html += renderNodes(node.children, level + 1);
            }
        });
        return html;
    }
    
    // 渲染状态徽章
    function renderStatusBadge(status, reason) {
        const statusMap = {
            'pending': { class: 'bg-warning text-dark', text: '待审批' },
            'reviewing': { class: 'bg-info', text: '审批中' },
            'approved': { class: 'bg-success', text: '已通过' },
            'rejected': { class: 'bg-danger', text: '已驳回' }
        };
        const info = statusMap[status] || { class: 'bg-secondary', text: status };
        let html = `<span class="badge ${info.class} ms-2">${info.text}</span>`;
        if (status === 'rejected' && reason) {
            html += `<span class="text-danger small ms-1" title="${escapeHtml(reason)}"><i class="bi bi-info-circle"></i></span>`;
        }
        return html;
    }
    
    // 获取文件图标
    function getFileIcon(filePath) {
        if (!filePath) return 'bi-file-earmark';
        const ext = filePath.split('.').pop().toLowerCase();
        const iconMap = {
            'jpg': 'bi-file-earmark-image', 'jpeg': 'bi-file-earmark-image', 'png': 'bi-file-earmark-image', 'gif': 'bi-file-earmark-image', 'webp': 'bi-file-earmark-image',
            'pdf': 'bi-file-earmark-pdf', 'doc': 'bi-file-earmark-word', 'docx': 'bi-file-earmark-word',
            'xls': 'bi-file-earmark-excel', 'xlsx': 'bi-file-earmark-excel',
            'ppt': 'bi-file-earmark-ppt', 'pptx': 'bi-file-earmark-ppt',
            'zip': 'bi-file-earmark-zip', 'rar': 'bi-file-earmark-zip', '7z': 'bi-file-earmark-zip',
            'mp4': 'bi-file-earmark-play', 'mov': 'bi-file-earmark-play', 'avi': 'bi-file-earmark-play',
            'mp3': 'bi-file-earmark-music', 'wav': 'bi-file-earmark-music',
            'psd': 'bi-file-earmark-image', 'ai': 'bi-file-earmark-image',
            'dwg': 'bi-file-earmark-richtext', 'dxf': 'bi-file-earmark-richtext',
            'max': 'bi-box', '3ds': 'bi-box', 'obj': 'bi-box', 'fbx': 'bi-box', 'blend': 'bi-box'
        };
        return iconMap[ext] || 'bi-file-earmark';
    }
    
    // 设置拖拽上传区域
    function setupDropZone() {
        config.container.addEventListener('dragover', (e) => {
            e.preventDefault();
            config.container.classList.add('file-tree-dragover');
        });
        
        config.container.addEventListener('dragleave', (e) => {
            e.preventDefault();
            config.container.classList.remove('file-tree-dragover');
        });
        
        config.container.addEventListener('drop', async (e) => {
            e.preventDefault();
            config.container.classList.remove('file-tree-dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // 检查是否拖到某个文件夹上
                const target = e.target.closest('.file-tree-node.folder');
                const folderId = target ? target.dataset.id : null;
                await uploadFiles(files, folderId);
            }
        });
    }
    
    // 绑定事件
    function bindEvents() {
        // 文件夹节点也可接收拖拽
        const folderNodes = config.container.querySelectorAll('.file-tree-node.folder');
        folderNodes.forEach(node => {
            node.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                node.classList.add('folder-dragover');
            });
            node.addEventListener('dragleave', (e) => {
                e.preventDefault();
                node.classList.remove('folder-dragover');
            });
            node.addEventListener('drop', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                node.classList.remove('folder-dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    await uploadFiles(files, node.dataset.id);
                }
            });
        });
    }
    
    // 上传文件
    async function uploadFiles(files, folderId) {
        for (const file of files) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('project_id', config.projectId);
            formData.append('deliverable_name', file.name);
            formData.append('file_category', config.fileCategory);
            if (folderId) {
                formData.append('parent_folder_id', folderId);
            }
            
            try {
                const response = await fetch(config.apiUrl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    if (config.onUploadSuccess) {
                        config.onUploadSuccess(result.data);
                    }
                } else {
                    showAlertModal('上传失败: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('[FILE_TREE] Upload error:', error);
                showAlertModal('上传失败', 'error');
            }
        }
        loadTree();
    }
    
    // 展开/收起文件夹
    function toggleFolder(folderId) {
        if (expandedFolders.has(folderId)) {
            expandedFolders.delete(folderId);
        } else {
            expandedFolders.add(folderId);
        }
        render();
    }
    
    // 创建文件夹
    async function createFolder(parentId = null) {
        const name = prompt('请输入文件夹名称:');
        if (!name || !name.trim()) return;
        
        try {
            const response = await fetch(`${config.apiUrl}?action=folder`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: config.projectId,
                    folder_name: name.trim(),
                    parent_folder_id: parentId,
                    file_category: config.fileCategory
                })
            });
            const result = await response.json();
            
            if (result.success) {
                if (parentId) expandedFolders.add(parentId);
                loadTree();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[FILE_TREE] Create folder error:', error);
            showAlertModal('创建文件夹失败', 'error');
        }
    }
    
    // 重命名文件夹
    async function renameFolder(folderId, currentName) {
        const newName = prompt('请输入新名称:', currentName);
        if (!newName || !newName.trim() || newName === currentName) return;
        
        try {
            const response = await fetch(`${config.apiUrl}?action=folder`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: folderId,
                    folder_name: newName.trim()
                })
            });
            const result = await response.json();
            
            if (result.success) {
                loadTree();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[FILE_TREE] Rename folder error:', error);
            showAlertModal('重命名失败', 'error');
        }
    }
    
    // 删除节点
    async function deleteNode(nodeId, isFolder) {
        const msg = isFolder ? '确定要删除此文件夹及其所有内容吗？' : '确定要删除此文件吗？';
        if (!confirm(msg)) return;
        
        try {
            const url = isFolder ? `${config.apiUrl}?action=folder&id=${nodeId}` : `${config.apiUrl}?id=${nodeId}`;
            const response = await fetch(url, { method: 'DELETE' });
            const result = await response.json();
            
            if (result.success) {
                loadTree();
            } else {
                showAlertModal(result.message, 'error');
            }
        } catch (error) {
            console.error('[FILE_TREE] Delete error:', error);
            showAlertModal('删除失败', 'error');
        }
    }
    
    // 上传到指定文件夹
    function uploadToFolder(folderId) {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.onchange = async () => {
            if (input.files.length > 0) {
                await uploadFiles(input.files, folderId);
            }
        };
        input.click();
    }
    
    // 打开文件详情
    function openFile(fileId) {
        if (config.onFileClick) {
            config.onFileClick(fileId);
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
            console.error('[FILE_TREE] Preview error:', error);
            showAlertModal('预览失败', 'error');
        }
    }
    
    // 刷新
    function refresh() {
        loadTree();
    }
    
    // 显示错误
    function showError(msg) {
        if (config.container) {
            config.container.innerHTML = `<div class="alert alert-danger">${escapeHtml(msg)}</div>`;
        }
    }
    
    // HTML转义
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    return {
        init,
        loadTree,
        refresh,
        toggleFolder,
        createFolder,
        renameFolder,
        deleteNode,
        uploadToFolder,
        openFile,
        previewFile
    };
})();
