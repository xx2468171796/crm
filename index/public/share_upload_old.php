<?php
/**
 * 文件分享上传页面
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        body {
            min-height: 100vh;
            background: var(--primary-gradient);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .main-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .upload-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }
        
        .card-header-custom {
            background: var(--primary-gradient);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .card-header-custom h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .card-header-custom .project-name {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .card-body-custom {
            padding: 30px;
        }
        
        .upload-zone {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            background: #f8f9fa;
        }
        
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        
        .upload-zone .icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 15px;
        }
        
        .upload-zone h5 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .upload-zone p {
            color: #666;
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .file-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .file-item .file-icon {
            font-size: 1.5rem;
            color: #667eea;
            margin-right: 15px;
        }
        
        .file-item .file-info {
            flex: 1;
            overflow: hidden;
        }
        
        .file-item .file-name {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .file-item .file-size {
            font-size: 0.85rem;
            color: #666;
        }
        
        .file-item .file-status {
            margin-left: 10px;
        }
        
        .file-item .progress {
            height: 6px;
            margin-top: 8px;
            border-radius: 3px;
        }
        
        .btn-upload {
            background: var(--primary-gradient);
            border: none;
            padding: 12px 30px;
            font-size: 1rem;
            font-weight: 500;
            border-radius: 10px;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn-upload:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .password-section {
            margin-bottom: 20px;
        }
        
        .password-section .form-control {
            border-radius: 10px;
            padding: 12px 15px;
        }
        
        .error-page, .loading-page {
            text-align: center;
            padding: 60px 30px;
        }
        
        .error-page .icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
        
        .loading-page .icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 20px;
        }
        
        .success-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        
        .expire-info {
            font-size: 0.85rem;
            color: #666;
            text-align: center;
            margin-top: 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="upload-card">
            <!-- 加载状态 -->
            <div id="loadingPage" class="loading-page">
                <div class="icon">
                    <i class="bi bi-arrow-repeat spinner"></i>
                </div>
                <h4>正在加载...</h4>
            </div>
            
            <!-- 错误页面 -->
            <div id="errorPage" class="error-page" style="display: none;">
                <div class="icon">
                    <i class="bi bi-exclamation-circle"></i>
                </div>
                <h4 id="errorTitle">链接无效</h4>
                <p id="errorMessage" class="text-muted">此分享链接已失效或不存在</p>
            </div>
            
            <!-- 主上传界面 -->
            <div id="mainPage" style="display: none;">
                <div class="card-header-custom">
                    <h1><i class="bi bi-cloud-upload me-2"></i>文件上传</h1>
                    <div class="project-name" id="projectName"></div>
                </div>
                
                <div class="card-body-custom">
                    <!-- 密码输入 -->
                    <div id="passwordSection" class="password-section" style="display: none;">
                        <label class="form-label"><i class="bi bi-lock me-1"></i>此链接需要密码访问</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="passwordInput" placeholder="请输入访问密码">
                            <button class="btn btn-primary" type="button" id="verifyPasswordBtn">验证</button>
                        </div>
                        <div id="passwordError" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                    
                    <!-- 上传区域 -->
                    <div id="uploadSection" style="display: none;">
                        <div class="upload-zone" id="dropZone">
                            <div class="icon">
                                <i class="bi bi-cloud-arrow-up"></i>
                            </div>
                            <h5>拖拽文件到此处上传</h5>
                            <p>或点击选择文件，支持批量上传和文件夹</p>
                            <input type="file" id="fileInput" multiple style="display: none;">
                            <input type="file" id="folderInput" webkitdirectory style="display: none;">
                        </div>
                        
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-outline-primary flex-fill" id="selectFilesBtn">
                                <i class="bi bi-files me-1"></i>选择文件
                            </button>
                            <button class="btn btn-outline-secondary flex-fill" id="selectFolderBtn">
                                <i class="bi bi-folder me-1"></i>选择文件夹
                            </button>
                        </div>
                        
                        <div class="file-list" id="fileList"></div>
                        
                        <button class="btn btn-primary btn-upload" id="uploadBtn" style="display: none;">
                            <i class="bi bi-upload me-2"></i>开始上传 (<span id="fileCount">0</span> 个文件)
                        </button>
                        
                        <div class="expire-info" id="expireInfo"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 成功提示 -->
    <div class="success-toast" id="successToast" style="display: none;">
        <div class="toast show" role="alert">
            <div class="toast-header bg-success text-white">
                <i class="bi bi-check-circle me-2"></i>
                <strong class="me-auto">上传成功</strong>
                <button type="button" class="btn-close btn-close-white" onclick="hideToast()"></button>
            </div>
            <div class="toast-body" id="toastMessage">文件已上传成功</div>
        </div>
    </div>
    
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
                            break;
                        case 'max_visits_reached':
                            title = '链接已失效';
                            break;
                        case 'disabled':
                            title = '链接已禁用';
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
                document.getElementById('expireInfo').textContent = 
                    `此链接有效期至: ${expireDate.toLocaleDateString('zh-CN')} ${expireDate.toLocaleTimeString('zh-CN')}`;
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
        function handleFiles(files) {
            for (const file of files) {
                if (!selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    selectedFiles.push(file);
                }
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
                        <div class="progress" style="display: none;">
                            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="file-status">
                        <button class="btn btn-sm btn-outline-danger" onclick="removeFile(${index})">
                            <i class="bi bi-x"></i>
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
            uploadBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner me-2"></i>上传中...';
            
            let successCount = 0;
            let failCount = 0;
            
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const fileItem = document.getElementById(`file-${i}`);
                const progressBar = fileItem.querySelector('.progress');
                const progressInner = fileItem.querySelector('.progress-bar');
                const statusBtn = fileItem.querySelector('.file-status button');
                
                progressBar.style.display = 'block';
                statusBtn.style.display = 'none';
                
                try {
                    await uploadFile(file, (progress) => {
                        progressInner.style.width = `${progress}%`;
                    });
                    
                    progressBar.style.display = 'none';
                    fileItem.querySelector('.file-status').innerHTML = 
                        '<i class="bi bi-check-circle-fill text-success" style="font-size: 1.2rem;"></i>';
                    successCount++;
                    
                } catch (error) {
                    progressBar.style.display = 'none';
                    fileItem.querySelector('.file-status').innerHTML = 
                        `<i class="bi bi-x-circle-fill text-danger" style="font-size: 1.2rem;" title="${escapeHtml(error.message)}"></i>`;
                    failCount++;
                }
            }
            
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload me-2"></i>重新上传';
            
            if (successCount > 0) {
                showToast(`成功上传 ${successCount} 个文件` + (failCount > 0 ? `，${failCount} 个失败` : ''));
            } else if (failCount > 0) {
                showToast(`上传失败: ${failCount} 个文件`, 'danger');
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
                'pdf': 'bi-file-earmark-pdf',
                'doc': 'bi-file-earmark-word', 'docx': 'bi-file-earmark-word',
                'xls': 'bi-file-earmark-excel', 'xlsx': 'bi-file-earmark-excel',
                'ppt': 'bi-file-earmark-ppt', 'pptx': 'bi-file-earmark-ppt',
                'jpg': 'bi-file-earmark-image', 'jpeg': 'bi-file-earmark-image',
                'png': 'bi-file-earmark-image', 'gif': 'bi-file-earmark-image',
                'zip': 'bi-file-earmark-zip', 'rar': 'bi-file-earmark-zip', '7z': 'bi-file-earmark-zip',
                'mp4': 'bi-file-earmark-play', 'mov': 'bi-file-earmark-play', 'avi': 'bi-file-earmark-play',
                'mp3': 'bi-file-earmark-music', 'wav': 'bi-file-earmark-music',
                'txt': 'bi-file-earmark-text',
                'psd': 'bi-file-earmark-image', 'ai': 'bi-file-earmark-image',
            };
            return icons[ext] || 'bi-file-earmark';
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
            const toast = document.getElementById('successToast');
            const toastHeader = toast.querySelector('.toast-header');
            const toastMessage = document.getElementById('toastMessage');
            
            toastHeader.className = `toast-header bg-${type} text-white`;
            toastMessage.textContent = message;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 5000);
        }
        
        function hideToast() {
            document.getElementById('successToast').style.display = 'none';
        }
    </script>
</body>
</html>
