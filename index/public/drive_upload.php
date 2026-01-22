<?php
/**
 * 网盘分享上传页面
 * 公开访问，无需登录
 */

$token = trim($_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文件上传 - 个人网盘</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        body {
            min-height: 100vh;
            background: var(--primary-gradient);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        .card-header-custom h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 10px; }
        .card-header-custom .owner-name { font-size: 1.1rem; opacity: 0.9; }
        .card-body-custom { padding: 30px; }
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
            border-color: #0891b2;
            background: rgba(8, 145, 178, 0.05);
        }
        .upload-zone .icon { font-size: 3rem; color: #0891b2; margin-bottom: 15px; }
        .upload-zone h5 { color: #333; margin-bottom: 10px; }
        .upload-zone p { color: #666; margin-bottom: 0; font-size: 0.9rem; }
        .file-list { max-height: 300px; overflow-y: auto; margin-top: 20px; }
        .file-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .file-item .file-icon { font-size: 1.5rem; color: #0891b2; margin-right: 15px; }
        .file-item .file-info { flex: 1; overflow: hidden; }
        .file-item .file-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-item .file-size { font-size: 0.85rem; color: #666; }
        .file-item .progress { height: 6px; margin-top: 8px; border-radius: 3px; }
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
        .btn-upload:hover { opacity: 0.9; }
        .password-section { margin-bottom: 20px; }
        .password-section .form-control { border-radius: 10px; padding: 12px 15px; }
        .error-page, .loading-page { text-align: center; padding: 60px 30px; }
        .error-page .icon { font-size: 4rem; color: #dc3545; margin-bottom: 20px; }
        .loading-page .icon { font-size: 4rem; color: #0891b2; margin-bottom: 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .spinner { animation: spin 1s linear infinite; }
        .success-toast { position: fixed; top: 20px; right: 20px; z-index: 1050; }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="upload-card">
            <div id="loadingPage" class="loading-page">
                <div class="icon"><i class="bi bi-arrow-repeat spinner"></i></div>
                <h4>正在加载...</h4>
            </div>
            
            <div id="errorPage" class="error-page" style="display: none;">
                <div class="icon"><i class="bi bi-exclamation-circle"></i></div>
                <h4 id="errorTitle">链接无效</h4>
                <p id="errorMessage" class="text-muted">此分享链接已失效或不存在</p>
            </div>
            
            <div id="mainPage" style="display: none;">
                <div class="card-header-custom">
                    <h1><i class="bi bi-cloud-upload me-2"></i>文件上传</h1>
                    <div class="owner-name" id="ownerName"></div>
                </div>
                
                <div class="card-body-custom">
                    <div id="passwordSection" class="password-section" style="display: none;">
                        <label class="form-label"><i class="bi bi-lock me-1"></i>此链接需要密码访问</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="passwordInput" placeholder="请输入访问密码">
                            <button class="btn btn-primary" type="button" id="verifyPasswordBtn">验证</button>
                        </div>
                        <div id="passwordError" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                    
                    <div id="uploadSection" style="display: none;">
                        <div class="upload-zone" id="dropZone">
                            <div class="icon"><i class="bi bi-cloud-arrow-up"></i></div>
                            <h5>拖拽文件到此处上传</h5>
                            <p>文件将自动重命名为"分享+文件名+时间"</p>
                            <input type="file" id="fileInput" multiple style="display: none;">
                        </div>
                        
                        <button class="btn btn-outline-primary w-100 mt-3" id="selectFilesBtn">
                            <i class="bi bi-files me-1"></i>选择文件
                        </button>
                        
                        <div class="file-list" id="fileList"></div>
                        
                        <button class="btn btn-primary btn-upload" id="uploadBtn" style="display: none;">
                            <i class="bi bi-upload me-2"></i>开始上传 (<span id="fileCount">0</span> 个文件)
                        </button>
                        
                        <div class="text-center text-muted mt-3" id="expireInfo" style="font-size: 0.85rem;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
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
        
        document.addEventListener('DOMContentLoaded', () => {
            if (!token) { showError('缺少参数', '链接参数不完整'); return; }
            loadLinkInfo();
        });
        
        async function loadLinkInfo() {
            try {
                const response = await fetch(`${API_BASE}/drive_share_info.php?token=${encodeURIComponent(token)}`);
                const data = await response.json();
                
                if (!data.valid) {
                    showError(data.reason === 'expired' ? '链接已过期' : '链接无效', data.message || '此分享链接已失效');
                    return;
                }
                
                linkInfo = data;
                showMainPage();
            } catch (error) {
                showError('加载失败', '无法加载链接信息');
            }
        }
        
        function showError(title, message) {
            document.getElementById('loadingPage').style.display = 'none';
            document.getElementById('mainPage').style.display = 'none';
            document.getElementById('errorPage').style.display = 'block';
            document.getElementById('errorTitle').textContent = title;
            document.getElementById('errorMessage').textContent = message;
        }
        
        function showMainPage() {
            document.getElementById('loadingPage').style.display = 'none';
            document.getElementById('errorPage').style.display = 'none';
            document.getElementById('mainPage').style.display = 'block';
            
            document.getElementById('ownerName').textContent = linkInfo.owner_name ? `上传到 ${linkInfo.owner_name} 的网盘` : '个人网盘上传';
            
            if (linkInfo.expires_at) {
                const expireDate = new Date(linkInfo.expires_at);
                document.getElementById('expireInfo').textContent = `链接有效期至: ${expireDate.toLocaleDateString('zh-CN')} ${expireDate.toLocaleTimeString('zh-CN')}`;
            }
            
            if (linkInfo.requires_password) {
                document.getElementById('passwordSection').style.display = 'block';
                document.getElementById('uploadSection').style.display = 'none';
            } else {
                document.getElementById('passwordSection').style.display = 'none';
                document.getElementById('uploadSection').style.display = 'block';
                initUploadHandlers();
            }
        }
        
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
        
        function initUploadHandlers() {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');
            
            dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
            dropZone.addEventListener('dragleave', () => { dropZone.classList.remove('dragover'); });
            dropZone.addEventListener('drop', (e) => { e.preventDefault(); dropZone.classList.remove('dragover'); handleFiles(e.dataTransfer.files); });
            dropZone.addEventListener('click', () => fileInput.click());
            
            document.getElementById('selectFilesBtn').addEventListener('click', (e) => { e.stopPropagation(); fileInput.click(); });
            fileInput.addEventListener('change', () => handleFiles(fileInput.files));
            document.getElementById('uploadBtn').addEventListener('click', startUpload);
        }
        
        function handleFiles(files) {
            for (const file of files) {
                if (!selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    selectedFiles.push(file);
                }
            }
            renderFileList();
        }
        
        function renderFileList() {
            const fileList = document.getElementById('fileList');
            const uploadBtn = document.getElementById('uploadBtn');
            
            if (selectedFiles.length === 0) { fileList.innerHTML = ''; uploadBtn.style.display = 'none'; return; }
            
            uploadBtn.style.display = 'block';
            document.getElementById('fileCount').textContent = selectedFiles.length;
            
            fileList.innerHTML = selectedFiles.map((file, index) => `
                <div class="file-item" id="file-${index}">
                    <div class="file-icon"><i class="bi bi-file-earmark"></i></div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatFileSize(file.size)}</div>
                        <div class="progress" style="display: none;"><div class="progress-bar" style="width: 0%"></div></div>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" onclick="removeFile(${index})"><i class="bi bi-x"></i></button>
                </div>
            `).join('');
        }
        
        function removeFile(index) { selectedFiles.splice(index, 1); renderFileList(); }
        
        async function startUpload() {
            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="bi bi-arrow-repeat spinner me-2"></i>上传中...';
            
            let successCount = 0, failCount = 0;
            
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const fileItem = document.getElementById(`file-${i}`);
                const progressBar = fileItem.querySelector('.progress');
                const progressInner = fileItem.querySelector('.progress-bar');
                const statusBtn = fileItem.querySelector('button');
                
                progressBar.style.display = 'block';
                statusBtn.style.display = 'none';
                
                try {
                    await uploadFile(file, (progress) => { progressInner.style.width = `${progress}%`; });
                    progressBar.style.display = 'none';
                    fileItem.querySelector('.file-info').innerHTML += '<div class="text-success"><i class="bi bi-check-circle-fill"></i> 上传成功</div>';
                    successCount++;
                } catch (error) {
                    progressBar.style.display = 'none';
                    fileItem.querySelector('.file-info').innerHTML += `<div class="text-danger"><i class="bi bi-x-circle-fill"></i> ${error.message}</div>`;
                    failCount++;
                }
            }
            
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload me-2"></i>重新上传';
            
            if (successCount > 0) showToast(`成功上传 ${successCount} 个文件` + (failCount > 0 ? `，${failCount} 个失败` : ''));
        }
        
        function uploadFile(file, onProgress) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('token', token);
                formData.append('password', verifiedPassword);
                formData.append('file', file);
                
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); });
                xhr.addEventListener('load', () => {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) resolve(response); else reject(new Error(response.error || '上传失败'));
                        } catch { reject(new Error('响应解析错误')); }
                    } else {
                        try { reject(new Error(JSON.parse(xhr.responseText).error || `HTTP ${xhr.status}`)); }
                        catch { reject(new Error(`HTTP ${xhr.status}`)); }
                    }
                });
                xhr.addEventListener('error', () => reject(new Error('网络错误')));
                xhr.open('POST', `${API_BASE}/drive_share_upload.php`);
                xhr.send(formData);
            });
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
        
        function showToast(message) {
            const toast = document.getElementById('successToast');
            document.getElementById('toastMessage').textContent = message;
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 5000);
        }
        
        function hideToast() { document.getElementById('successToast').style.display = 'none'; }
    </script>
</body>
</html>
