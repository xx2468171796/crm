<?php
/**
 * 文件分享上传页面 - Portal风格重构版
 * 公开访问，无需登录
 */

$token = trim($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件上传</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/css/portal-theme.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        .upload-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .upload-card {
            max-width: 560px;
            width: 100%;
        }
        
        .card-header-gradient {
            background: var(--portal-gradient-bg);
            color: white;
            padding: 32px;
            border-radius: var(--portal-radius-lg) var(--portal-radius-lg) 0 0;
            text-align: center;
        }
        
        .card-header-gradient h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 8px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .card-header-gradient .project-name {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 500;
        }
        
        .card-body-section {
            background: var(--portal-card-solid);
            padding: 32px;
            border-radius: 0 0 var(--portal-radius-lg) var(--portal-radius-lg);
            box-shadow: var(--portal-shadow-lg);
        }
        
        /* 错误/加载页面样式 */
        .status-page {
            text-align: center;
            padding: 60px 30px;
        }
        
        .status-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
        }
        
        .status-icon.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--portal-error);
        }
        
        .status-icon.loading {
            background: rgba(99, 102, 241, 0.1);
            color: var(--portal-primary);
        }
        
        .status-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--portal-text);
            margin-bottom: 8px;
        }
        
        .status-message {
            font-size: 14px;
            color: var(--portal-text-secondary);
        }
        
        /* 密码输入区域 */
        .password-section {
            text-align: center;
        }
        
        .password-icon {
            width: 64px;
            height: 64px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
            color: var(--portal-primary);
        }
        
        .password-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--portal-text);
            margin-bottom: 20px;
        }
        
        .password-input-group {
            display: flex;
            gap: 10px;
            max-width: 320px;
            margin: 0 auto;
        }
        
        .password-input-group input {
            flex: 1;
        }
        
        /* 上传区域 */
        .upload-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        
        .upload-actions button {
            flex: 1;
        }
        
        /* 文件列表 */
        .file-list {
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--portal-bg);
            border-radius: var(--portal-radius);
            margin-bottom: 8px;
            transition: all 0.2s ease;
        }
        
        .file-item:hover {
            background: rgba(99, 102, 241, 0.05);
        }
        
        .file-icon {
            font-size: 24px;
            color: var(--portal-primary);
            flex-shrink: 0;
        }
        
        .file-info {
            flex: 1;
            min-width: 0;
        }
        
        .file-name {
            font-weight: 500;
            color: var(--portal-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 14px;
        }
        
        .file-size {
            font-size: 12px;
            color: var(--portal-text-muted);
            margin-top: 2px;
        }
        
        .file-progress {
            height: 4px;
            background: var(--portal-border);
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
            display: none;
        }
        
        .file-progress-bar {
            height: 100%;
            background: var(--portal-gradient);
            border-radius: 2px;
            transition: width 0.3s ease;
        }
        
        .file-status {
            flex-shrink: 0;
        }
        
        .file-remove {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: var(--portal-text-muted);
            cursor: pointer;
            border-radius: var(--portal-radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .file-remove:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--portal-error);
        }
        
        /* 上传按钮 */
        .upload-submit {
            margin-top: 20px;
        }
        
        /* 过期信息 */
        .expire-info {
            text-align: center;
            font-size: 13px;
            color: var(--portal-text-muted);
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--portal-border);
        }
        
        /* Toast提示 */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .toast-item {
            background: var(--portal-card-solid);
            border-radius: var(--portal-radius);
            box-shadow: var(--portal-shadow-lg);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 280px;
            animation: slideInRight 0.3s ease;
        }
        
        .toast-item.success .toast-icon {
            color: var(--portal-success);
        }
        
        .toast-item.error .toast-icon {
            color: var(--portal-error);
        }
        
        .toast-icon {
            font-size: 20px;
        }
        
        .toast-message {
            flex: 1;
            font-size: 14px;
            color: var(--portal-text);
        }
        
        .toast-close {
            background: none;
            border: none;
            color: var(--portal-text-muted);
            cursor: pointer;
            padding: 4px;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .spin {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="portal-page">
    <!-- 背景装饰 -->
    <div class="portal-bg-decoration"></div>
    <div class="portal-bg-decoration-extra"></div>
    
    <div class="upload-container">
        <div class="upload-card">
            <!-- 加载状态 -->
            <div id="loadingPage" class="portal-card">
                <div class="status-page">
                    <div class="status-icon loading">
                        <i class="bi bi-arrow-repeat spin"></i>
                    </div>
                    <div class="status-title">正在加载...</div>
                    <div class="status-message">请稍候</div>
                </div>
            </div>
            
            <!-- 错误页面 -->
            <div id="errorPage" style="display: none;">
                <div class="portal-card">
                    <div class="status-page">
                        <div class="status-icon error">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>
                        <div class="status-title" id="errorTitle">链接无效</div>
                        <div class="status-message" id="errorMessage">此分享链接已失效</div>
                    </div>
                </div>
            </div>
            
            <!-- 主上传界面 -->
            <div id="mainPage" style="display: none;">
                <div class="card-header-gradient">
                    <h1><i class="bi bi-cloud-arrow-up"></i> 文件上传</h1>
                    <div class="project-name" id="projectName"></div>
                </div>
                
                <div class="card-body-section">
                    <!-- 密码输入 -->
                    <div id="passwordSection" class="password-section" style="display: none;">
                        <div class="password-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <div class="password-title">此链接需要密码访问</div>
                        <div class="password-input-group">
                            <input type="password" class="portal-input" id="passwordInput" placeholder="请输入访问密码">
                            <button class="portal-btn portal-btn-primary" id="verifyPasswordBtn">验证</button>
                        </div>
                        <div id="passwordError" class="portal-form-error" style="display: none;"></div>
                    </div>
                    
                    <!-- 上传区域 -->
                    <div id="uploadSection" style="display: none;">
                        <div class="portal-upload-zone" id="dropZone">
                            <div class="portal-upload-icon">
                                <i class="bi bi-cloud-arrow-up"></i>
                            </div>
                            <div class="portal-upload-text">拖拽文件到此处上传</div>
                            <div class="portal-upload-hint">或点击选择文件，支持批量上传</div>
                            <input type="file" id="fileInput" multiple style="display: none;">
                            <input type="file" id="folderInput" webkitdirectory style="display: none;">
                        </div>
                        
                        <div class="upload-actions">
                            <button class="portal-btn portal-btn-secondary" id="selectFilesBtn">
                                <i class="bi bi-files"></i> 选择文件
                            </button>
                            <button class="portal-btn portal-btn-ghost" id="selectFolderBtn">
                                <i class="bi bi-folder"></i> 选择文件夹
                            </button>
                        </div>
                        
                        <div class="file-list" id="fileList"></div>
                        
                        <div class="upload-submit">
                            <button class="portal-btn portal-btn-primary portal-btn-block" id="uploadBtn" style="display: none;">
                                <i class="bi bi-upload"></i> 开始上传 (<span id="fileCount">0</span> 个文件)
                            </button>
                        </div>
                        
                        <div class="expire-info" id="expireInfo"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast提示容器 -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script>
        const token = '<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>';
        const API_BASE = '/api';
        
        let linkInfo = null;
        let verifiedPassword = '';
        let selectedFiles = [];
        
        // 初始化
        document.addEventListener('DOMContentLoaded', () => {
            if (!token) {
                showError('缺少参数', '链接参数不完整');
                return;
            }
            loadLinkInfo();
        });
        
        // 加载链接信息
        async function loadLinkInfo() {
            try {
                const response = await fetch(`${API_BASE}/file_share_info.php?token=${encodeURIComponent(token)}`);
                const data = await response.json();
                
                if (!data.valid) {
                    let title = '链接无效';
                    let message = data.message || '此分享链接已失效';
                    
                    switch (data.reason) {
                        case 'expired':
                            title = '链接已过期';
                            message = '此分享链接已超过有效期';
                            break;
                        case 'max_visits_reached':
                            title = '访问次数已用完';
                            message = '此链接已达到最大访问次数';
                            break;
                        case 'disabled':
                            title = '链接已禁用';
                            message = '此分享链接已被管理员禁用';
                            break;
                    }
                    
                    showError(title, message);
                    return;
                }
                
                linkInfo = data;
                showMainPage();
                
            } catch (error) {
                console.error('加载链接信息失败:', error);
                showError('加载失败', '无法加载链接信息，请稍后重试');
            }
        }
        
        // 显示错误页面
        function showError(title, message) {
            document.getElementById('loadingPage').style.display = 'none';
            document.getElementById('mainPage').style.display = 'none';
            document.getElementById('errorPage').style.display = 'block';
            document.getElementById('errorTitle').textContent = title;
            document.getElementById('errorMessage').textContent = message;
        }
        
        // 显示主页面
        function showMainPage() {
            document.getElementById('loadingPage').style.display = 'none';
            document.getElementById('errorPage').style.display = 'none';
            document.getElementById('mainPage').style.display = 'block';
            
            // 显示项目名
            document.getElementById('projectName').textContent = linkInfo.project_name || '文件上传';
            
            // 显示过期时间
            if (linkInfo.expires_at) {
                const expireDate = new Date(linkInfo.expires_at);
                document.getElementById('expireInfo').innerHTML = 
                    `<i class="bi bi-clock me-1"></i> 链接有效期至: ${expireDate.toLocaleDateString('zh-CN')} ${expireDate.toLocaleTimeString('zh-CN')}`;
            }
            
            // 根据是否需要密码显示不同界面
            if (linkInfo.requires_password) {
                document.getElementById('passwordSection').style.display = 'block';
                document.getElementById('uploadSection').style.display = 'none';
            } else {
                document.getElementById('passwordSection').style.display = 'none';
                document.getElementById('uploadSection').style.display = 'block';
                initUploadHandlers();
            }
        }
        
        // 密码验证
        document.getElementById('verifyPasswordBtn')?.addEventListener('click', () => {
            const password = document.getElementById('passwordInput').value;
            if (!password) {
                document.getElementById('passwordError').textContent = '请输入密码';
                document.getElementById('passwordError').style.display = 'block';
                return;
            }
            
            verifiedPassword = password;
            document.getElementById('passwordSection').style.display = 'none';
            document.getElementById('uploadSection').style.display = 'block';
            initUploadHandlers();
        });
        
        // 回车键验证密码
        document.getElementById('passwordInput')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                document.getElementById('verifyPasswordBtn').click();
            }
        });
        
        // 初始化上传处理器
        function initUploadHandlers() {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            const folderInput = document.getElementById('folderInput');
            
            // 拖拽事件
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });
            
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });
            
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                handleFiles(e.dataTransfer.files);
            });
            
            dropZone.addEventListener('click', () => fileInput.click());
            
            // 文件选择
            document.getElementById('selectFilesBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                fileInput.click();
            });
            
            document.getElementById('selectFolderBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                folderInput.click();
            });
            
            fileInput.addEventListener('change', () => handleFiles(fileInput.files));
            folderInput.addEventListener('change', () => handleFiles(folderInput.files));
            
            // 上传按钮
            document.getElementById('uploadBtn').addEventListener('click', startUpload);
        }
        
        // 处理选择的文件
        const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024; // 2GB
        
        function handleFiles(files) {
            let oversizedFiles = [];
            for (const file of files) {
                if (file.size > MAX_FILE_SIZE) {
                    oversizedFiles.push(file.name);
                    continue;
                }
                if (!selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    selectedFiles.push(file);
                }
            }
            if (oversizedFiles.length > 0) {
                showToast(`以下文件超过2GB限制: ${oversizedFiles.join(', ')}`, 'error');
            }
            renderFileList();
        }
        
        // 渲染文件列表
        function renderFileList() {
            const fileList = document.getElementById('fileList');
            const uploadBtn = document.getElementById('uploadBtn');
            
            if (selectedFiles.length === 0) {
                fileList.innerHTML = '';
                uploadBtn.style.display = 'none';
                return;
            }
            
            uploadBtn.style.display = 'block';
            document.getElementById('fileCount').textContent = selectedFiles.length;
            
            fileList.innerHTML = selectedFiles.map((file, index) => `
                <div class="file-item" id="file-${index}">
                    <div class="file-icon">
                        <i class="bi ${getFileIcon(file.name)}"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                        <div class="file-progress">
                            <div class="file-progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="file-status">
                        <button class="file-remove" onclick="removeFile(${index})">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }
        
        // 移除文件
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            renderFileList();
        }
        
        // 开始上传
        async function startUpload() {
            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> 上传中...';
            
            let successCount = 0;
            let failCount = 0;
            
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const fileItem = document.getElementById(`file-${i}`);
                const progressBar = fileItem.querySelector('.file-progress');
                const progressInner = fileItem.querySelector('.file-progress-bar');
                const statusDiv = fileItem.querySelector('.file-status');
                
                progressBar.style.display = 'block';
                statusDiv.innerHTML = '';
                
                try {
                    await uploadFile(file, (progress) => {
                        progressInner.style.width = `${progress}%`;
                    });
                    
                    progressBar.style.display = 'none';
                    statusDiv.innerHTML = '<i class="bi bi-check-circle-fill" style="color: var(--portal-success); font-size: 20px;"></i>';
                    successCount++;
                    
                } catch (error) {
                    progressBar.style.display = 'none';
                    statusDiv.innerHTML = `<i class="bi bi-x-circle-fill" style="color: var(--portal-error); font-size: 20px;" title="${escapeHtml(error.message)}"></i>`;
                    failCount++;
                }
            }
            
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> 重新上传';
            
            if (successCount > 0) {
                showToast(`成功上传 ${successCount} 个文件` + (failCount > 0 ? `，${failCount} 个失败` : ''), 'success');
            } else if (failCount > 0) {
                showToast(`上传失败: ${failCount} 个文件`, 'error');
            }
        }
        
        // 上传单个文件
        function uploadFile(file, onProgress) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('token', token);
                formData.append('password', verifiedPassword);
                formData.append('file', file);
                
                const xhr = new XMLHttpRequest();
                
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        onProgress(Math.round(e.loaded / e.total * 100));
                    }
                });
                
                xhr.addEventListener('load', () => {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                resolve(response);
                            } else {
                                reject(new Error(response.error || '上传失败'));
                            }
                        } catch {
                            reject(new Error('响应解析错误'));
                        }
                    } else {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            reject(new Error(response.error || `HTTP ${xhr.status}`));
                        } catch {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    }
                });
                
                xhr.addEventListener('error', () => reject(new Error('网络错误')));
                xhr.addEventListener('abort', () => reject(new Error('上传已取消')));
                
                xhr.open('POST', `${API_BASE}/file_share_upload.php`);
                xhr.send(formData);
            });
        }
        
        // 工具函数
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'pdf': 'bi-file-earmark-pdf-fill',
                'doc': 'bi-file-earmark-word-fill', 'docx': 'bi-file-earmark-word-fill',
                'xls': 'bi-file-earmark-excel-fill', 'xlsx': 'bi-file-earmark-excel-fill',
                'ppt': 'bi-file-earmark-ppt-fill', 'pptx': 'bi-file-earmark-ppt-fill',
                'jpg': 'bi-file-earmark-image-fill', 'jpeg': 'bi-file-earmark-image-fill',
                'png': 'bi-file-earmark-image-fill', 'gif': 'bi-file-earmark-image-fill',
                'zip': 'bi-file-earmark-zip-fill', 'rar': 'bi-file-earmark-zip-fill', '7z': 'bi-file-earmark-zip-fill',
                'mp4': 'bi-file-earmark-play-fill', 'mov': 'bi-file-earmark-play-fill', 'avi': 'bi-file-earmark-play-fill',
                'mp3': 'bi-file-earmark-music-fill', 'wav': 'bi-file-earmark-music-fill',
                'txt': 'bi-file-earmark-text-fill',
                'psd': 'bi-file-earmark-image-fill', 'ai': 'bi-file-earmark-image-fill',
            };
            return icons[ext] || 'bi-file-earmark-fill';
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast-item ${type}`;
            toast.innerHTML = `
                <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-x-circle-fill'} toast-icon"></i>
                <span class="toast-message">${escapeHtml(message)}</span>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="bi bi-x"></i>
                </button>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }
    </script>
</body>
</html>
