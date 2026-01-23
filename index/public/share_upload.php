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
    <title>檔案上傳 - 安科帝設計空間</title>
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
        
        .brand-title {
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0.85;
            margin-bottom: 8px;
            letter-spacing: 2px;
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
            max-height: 400px;
            overflow-y: auto;
        }
        
        .file-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            background: var(--portal-bg);
            border-radius: var(--portal-radius);
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }
        
        .file-item:hover {
            background: rgba(99, 102, 241, 0.05);
        }
        
        .file-item.uploading {
            border-left: 3px solid var(--portal-primary);
        }
        
        .file-item.success {
            border-left: 3px solid var(--portal-success);
        }
        
        .file-item.error {
            border-left: 3px solid var(--portal-error);
        }
        
        .file-icon {
            font-size: 24px;
            color: var(--portal-primary);
            flex-shrink: 0;
            margin-top: 2px;
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
        
        /* 进度条区域 */
        .file-progress-area {
            margin-top: 10px;
            display: none;
        }
        
        .file-progress-area.active {
            display: block;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--portal-text-muted);
            margin-bottom: 4px;
        }
        
        .progress-label .chunk-info {
            color: var(--portal-primary);
            font-weight: 500;
        }
        
        .file-progress {
            height: 6px;
            background: var(--portal-border);
            border-radius: 3px;
            overflow: hidden;
            position: relative;
        }
        
        .file-progress-bar {
            height: 100%;
            background: var(--portal-gradient);
            border-radius: 3px;
            transition: width 0.2s ease;
            position: relative;
        }
        
        .file-progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .progress-percent {
            font-size: 12px;
            font-weight: 600;
            color: var(--portal-primary);
            margin-top: 4px;
            text-align: right;
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
        
        /* 总体进度 */
        .overall-progress {
            background: var(--portal-bg);
            border-radius: var(--portal-radius);
            padding: 16px;
            margin-top: 16px;
            display: none;
        }
        
        .overall-progress.active {
            display: block;
        }
        
        .overall-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .overall-progress-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--portal-text);
        }
        
        .overall-progress-stats {
            font-size: 12px;
            color: var(--portal-text-muted);
        }
        
        .overall-progress-bar {
            height: 8px;
            background: var(--portal-border);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .overall-progress-fill {
            height: 100%;
            background: var(--portal-gradient);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        /* 上传限制提示 */
        .upload-limit-notice {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.2);
            border-radius: var(--portal-radius);
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            color: var(--portal-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .upload-limit-notice i {
            font-size: 16px;
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
        
        /* 联系客服弹框 */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.2s ease;
        }
        
        .modal-dialog {
            background: var(--portal-card-solid);
            border-radius: var(--portal-radius-lg);
            padding: 32px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: var(--portal-shadow-lg);
            animation: scaleIn 0.2s ease;
        }
        
        .modal-icon {
            width: 64px;
            height: 64px;
            background: rgba(245, 158, 11, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        
        .modal-icon i {
            font-size: 32px;
            color: #f59e0b;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--portal-text);
            margin-bottom: 12px;
        }
        
        .modal-content {
            font-size: 14px;
            color: var(--portal-text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .modal-actions button {
            min-width: 120px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
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
                    <div class="status-title">正在載入...</div>
                    <div class="status-message">請稍候</div>
                </div>
            </div>
            
            <!-- 错误页面 -->
            <div id="errorPage" style="display: none;">
                <div class="portal-card">
                    <div class="status-page">
                        <div class="status-icon error">
                            <i class="bi bi-exclamation-circle"></i>
                        </div>
                        <div class="status-title" id="errorTitle">連結無效</div>
                        <div class="status-message" id="errorMessage">此分享連結已失效</div>
                    </div>
                </div>
            </div>
            
            <!-- 主上传界面 -->
            <div id="mainPage" style="display: none;">
                <div class="card-header-gradient">
                    <div class="brand-title">安科帝設計空間</div>
                    <h1><i class="bi bi-cloud-arrow-up"></i> 檔案上傳</h1>
                    <div class="project-name" id="projectName"></div>
                </div>
                
                <div class="card-body-section">
                    <!-- 密码输入 -->
                    <div id="passwordSection" class="password-section" style="display: none;">
                        <div class="password-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <div class="password-title">此連結需要密碼訪問</div>
                        <div class="password-input-group">
                            <input type="password" class="portal-input" id="passwordInput" placeholder="請輸入訪問密碼">
                            <button class="portal-btn portal-btn-primary" id="verifyPasswordBtn">驗證</button>
                        </div>
                        <div id="passwordError" class="portal-form-error" style="display: none;"></div>
                    </div>
                    
                    <!-- 上传区域 -->
                    <div id="uploadSection" style="display: none;">
                        <div class="upload-limit-notice">
                            <i class="bi bi-info-circle"></i>
                            單次上傳檔案總大小上限為 <strong>3GB</strong>
                        </div>
                        
                        <div class="portal-upload-zone" id="dropZone">
                            <div class="portal-upload-icon">
                                <i class="bi bi-cloud-arrow-up"></i>
                            </div>
                            <div class="portal-upload-text">拖曳檔案到此處上傳</div>
                            <div class="portal-upload-hint">或點擊選擇檔案，支援批量上傳（單次總計上限 3GB）</div>
                            <input type="file" id="fileInput" multiple style="display: none;">
                            <input type="file" id="folderInput" webkitdirectory style="display: none;">
                        </div>
                        
                        <div class="upload-actions">
                            <button class="portal-btn portal-btn-secondary" id="selectFilesBtn">
                                <i class="bi bi-files"></i> 選擇檔案
                            </button>
                            <button class="portal-btn portal-btn-ghost" id="selectFolderBtn">
                                <i class="bi bi-folder"></i> 選擇資料夾
                            </button>
                        </div>
                        
                        <div class="file-list" id="fileList"></div>
                        
                        <!-- 总体上传进度 -->
                        <div class="overall-progress" id="overallProgress">
                            <div class="overall-progress-header">
                                <span class="overall-progress-title">總體上傳進度</span>
                                <span class="overall-progress-stats" id="overallStats">0 / 0 檔案</span>
                            </div>
                            <div class="overall-progress-bar">
                                <div class="overall-progress-fill" id="overallProgressFill" style="width: 0%"></div>
                            </div>
                        </div>
                        
                        <div class="upload-submit">
                            <button class="portal-btn portal-btn-primary portal-btn-block" id="uploadBtn" style="display: none;">
                                <i class="bi bi-upload"></i> 開始上傳 (<span id="fileCount">0</span> 個檔案)
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
    
    <!-- 联系客服弹框 -->
    <div class="modal-overlay" id="contactModal" style="display: none;">
        <div class="modal-dialog">
            <div class="modal-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="modal-title">上傳遇到問題</div>
            <div class="modal-content">
                您的檔案已連續上傳失敗多次，可能是網絡不穩定或檔案過大導致。<br><br>
                建議您聯繫客服人員，我們將協助您透過其他方式完成檔案傳輸。
            </div>
            <div class="modal-actions">
                <button class="portal-btn portal-btn-secondary" onclick="closeContactModal()">稍後再試</button>
                <button class="portal-btn portal-btn-primary" onclick="contactSupport()"><i class="bi bi-headset"></i> 聯繫客服</button>
            </div>
        </div>
    </div>
    
    <script>
        const token = '<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>';
        const API_BASE = '/api';
        const CHUNK_SIZE = 90 * 1024 * 1024; // 90MB 分片大小
        const MAX_TOTAL_SIZE = 3 * 1024 * 1024 * 1024; // 3GB 单次上传总大小限制
        
        let linkInfo = null;
        let verifiedPassword = '';
        let selectedFiles = [];
        let consecutiveFailures = 0; // 连续失败计数
        
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
                    let title = '連結無效';
                    let message = data.message || '此分享連結已失效';
                    
                    switch (data.reason) {
                        case 'expired':
                            title = '連結已過期';
                            message = '此分享連結已超過有效期';
                            break;
                        case 'max_visits_reached':
                            title = '訪問次數已用完';
                            message = '此連結已達到最大訪問次數';
                            break;
                        case 'disabled':
                            title = '連結已停用';
                            message = '此分享連結已被管理員停用';
                            break;
                    }
                    
                    showError(title, message);
                    return;
                }
                
                linkInfo = data;
                showMainPage();
                
            } catch (error) {
                console.error('加载链接信息失败:', error);
                showError('載入失敗', '無法載入連結資訊，請稍後重試');
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
            document.getElementById('projectName').textContent = linkInfo.project_name || '檔案上傳';
            
            // 显示过期时间
            if (linkInfo.expires_at) {
                const expireDate = new Date(linkInfo.expires_at);
                document.getElementById('expireInfo').innerHTML = 
                    `<i class="bi bi-clock me-1"></i> 連結有效期至: ${expireDate.toLocaleDateString('zh-TW')} ${expireDate.toLocaleTimeString('zh-TW')}`;
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
                document.getElementById('passwordError').textContent = '請輸入密碼';
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
        function handleFiles(files) {
            for (const file of files) {
                if (!selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    selectedFiles.push(file);
                }
            }
            // 检查总大小
            const totalSize = selectedFiles.reduce((sum, f) => sum + f.size, 0);
            if (totalSize > MAX_TOTAL_SIZE) {
                showToast(`單次上傳總大小超過3GB限制！當前: ${formatFileSize(totalSize)}`, 'error');
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
            
            fileList.innerHTML = selectedFiles.map((file, index) => {
                const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                return `
                <div class="file-item" id="file-${index}">
                    <div class="file-icon">
                        <i class="bi ${getFileIcon(file.name)}"></i>
                    </div>
                    <div class="file-info">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${formatFileSize(file.size)} · ${totalChunks} 個分片</div>
                        <div class="file-progress-area" id="progress-area-${index}">
                            <div class="progress-label">
                                <span class="chunk-info" id="chunk-info-${index}">準備中...</span>
                                <span id="progress-percent-${index}">0%</span>
                            </div>
                            <div class="file-progress">
                                <div class="file-progress-bar" id="progress-bar-${index}" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="file-status" id="file-status-${index}">
                        <button class="file-remove" onclick="removeFile(${index})">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            `}).join('');
        }
        
        // 移除文件
        function removeFile(index) {
            selectedFiles.splice(index, 1);
            renderFileList();
        }
        
        // 开始上传
        async function startUpload() {
            // 检查总大小限制
            const totalSize = selectedFiles.reduce((sum, f) => sum + f.size, 0);
            if (totalSize > MAX_TOTAL_SIZE) {
                showToast(`單次上傳總大小超過3GB限制！當前: ${formatFileSize(totalSize)}，請移除部分檔案`, 'error');
                return;
            }
            
            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> 上傳中...';
            
            // 显示总体进度
            document.getElementById('overallProgress').classList.add('active');
            
            let successCount = 0;
            let failCount = 0;
            const totalFiles = selectedFiles.length;
            
            console.log('%c[上傳開始] 共 ' + totalFiles + ' 個檔案待上傳', 'color: #6366f1; font-weight: bold;');
            
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const fileItem = document.getElementById(`file-${i}`);
                const progressArea = document.getElementById(`progress-area-${i}`);
                const chunkInfo = document.getElementById(`chunk-info-${i}`);
                const progressPercent = document.getElementById(`progress-percent-${i}`);
                const progressBar = document.getElementById(`progress-bar-${i}`);
                const statusDiv = document.getElementById(`file-status-${i}`);
                
                // 显示进度区域
                progressArea.classList.add('active');
                fileItem.classList.add('uploading');
                statusDiv.innerHTML = '<i class="bi bi-arrow-repeat spin" style="color: var(--portal-primary); font-size: 18px;"></i>';
                
                // 更新总体进度
                updateOverallProgress(i, totalFiles, 0);
                
                console.log(`%c[檔案 ${i + 1}/${totalFiles}] 開始上傳: ${file.name} (${formatFileSize(file.size)})`, 'color: #0891b2;');
                
                try {
                    await uploadFileChunked(file, i, {
                        onChunkProgress: (chunkIndex, totalChunks, chunkPercent) => {
                            chunkInfo.textContent = `分片 ${chunkIndex + 1}/${totalChunks}`;
                            const overallFilePercent = ((chunkIndex + chunkPercent / 100) / totalChunks) * 100;
                            progressBar.style.width = `${overallFilePercent}%`;
                            progressPercent.textContent = `${Math.round(overallFilePercent)}%`;
                            
                            // 更新总体进度
                            updateOverallProgress(i, totalFiles, overallFilePercent);
                        },
                        onChunkComplete: (chunkIndex, totalChunks) => {
                            console.log(`  ✓ 分片 ${chunkIndex + 1}/${totalChunks} 上傳完成`);
                        }
                    });
                    
                    // 上传成功
                    fileItem.classList.remove('uploading');
                    fileItem.classList.add('success');
                    chunkInfo.textContent = '上傳完成';
                    progressPercent.textContent = '100%';
                    progressBar.style.width = '100%';
                    statusDiv.innerHTML = '<i class="bi bi-check-circle-fill" style="color: var(--portal-success); font-size: 20px;"></i>';
                    successCount++;
                    consecutiveFailures = 0; // 成功后重置连续失败计数
                    
                    console.log(`%c[檔案 ${i + 1}/${totalFiles}] ✓ 上傳成功: ${file.name}`, 'color: #10b981; font-weight: bold;');
                    
                } catch (error) {
                    // 上传失败
                    fileItem.classList.remove('uploading');
                    fileItem.classList.add('error');
                    chunkInfo.textContent = '上傳失敗';
                    statusDiv.innerHTML = `<i class="bi bi-x-circle-fill" style="color: var(--portal-error); font-size: 20px;" title="${escapeHtml(error.message)}"></i>`;
                    failCount++;
                    consecutiveFailures++;
                    
                    console.error(`%c[檔案 ${i + 1}/${totalFiles}] ✗ 上傳失敗: ${file.name}`, 'color: #ef4444; font-weight: bold;');
                    console.error('  錯誤詳情:', error.message);
                    console.log(`  連續失敗次數: ${consecutiveFailures}`);
                    
                    // 连续失败3次，弹出联系客服提示
                    if (consecutiveFailures >= 3) {
                        console.warn('%c[警告] 連續失敗3次，建議聯繫客服', 'color: #f59e0b; font-weight: bold;');
                        showContactModal();
                        break; // 停止继续上传
                    }
                }
                
                // 更新总体进度（文件完成）
                updateOverallProgress(i + 1, totalFiles, 0);
            }
            
            // 上传完成总结
            console.log('%c[上傳完成] 成功: ' + successCount + ', 失敗: ' + failCount, 
                failCount === 0 ? 'color: #10b981; font-weight: bold;' : 'color: #f59e0b; font-weight: bold;');
            
            if (successCount > 0 && failCount === 0) {
                showToast(`成功上傳 ${successCount} 個檔案！`, 'success');
                console.log('%c1.5秒後自動刷新頁面...', 'color: #6366f1;');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else if (successCount > 0 && failCount > 0) {
                showToast(`成功 ${successCount} 個，失敗 ${failCount} 個`, 'warning');
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else if (failCount > 0) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="bi bi-upload"></i> 重新上傳';
                showToast(`上傳失敗: ${failCount} 個檔案`, 'error');
            }
        }
        
        // 更新总体进度
        function updateOverallProgress(completedFiles, totalFiles, currentFilePercent) {
            const overallPercent = ((completedFiles + currentFilePercent / 100) / totalFiles) * 100;
            document.getElementById('overallProgressFill').style.width = `${overallPercent}%`;
            document.getElementById('overallStats').textContent = `${Math.min(completedFiles + 1, totalFiles)} / ${totalFiles} 檔案 (${Math.round(overallPercent)}%)`;
        }
        
        // 分片上传单个文件
        async function uploadFileChunked(file, fileIndex, callbacks) {
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            
            console.log(`  檔案大小: ${formatFileSize(file.size)}, 分片數: ${totalChunks}, 分片大小: ${formatFileSize(CHUNK_SIZE)}`);
            
            // 1. 初始化上传
            console.log('  → 初始化分片上傳...');
            const initResponse = await fetch(`${API_BASE}/file_share_chunk_upload.php`, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'init',
                    token: token,
                    password: verifiedPassword,
                    file_name: file.name,
                    file_size: file.size,
                    file_type: file.type || 'application/octet-stream',
                    total_chunks: totalChunks
                })
            });
            
            const initData = await initResponse.json();
            if (!initData.success) {
                throw new Error(initData.error || '初始化上傳失敗');
            }
            
            const uploadId = initData.upload_id;
            console.log(`  ✓ 初始化成功, upload_id: ${uploadId}`);
            
            // 2. 逐个上传分片
            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);
                
                console.log(`  → 上傳分片 ${chunkIndex + 1}/${totalChunks} (${formatFileSize(chunk.size)})`);
                
                // 上传分片并监控进度
                await uploadChunk(uploadId, chunkIndex, chunk, (percent) => {
                    callbacks.onChunkProgress(chunkIndex, totalChunks, percent);
                });
                
                callbacks.onChunkComplete(chunkIndex, totalChunks);
            }
            
            // 3. 完成上传（合并分片）
            console.log('  → 合併分片中...');
            const completeResponse = await fetch(`${API_BASE}/file_share_chunk_upload.php`, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'complete',
                    token: token,
                    password: verifiedPassword,
                    upload_id: uploadId
                })
            });
            
            const completeData = await completeResponse.json();
            if (!completeData.success) {
                throw new Error(completeData.error || '合併分片失敗');
            }
            
            console.log('  ✓ 合併完成');
            return completeData;
        }
        
        // 上传单个分片
        function uploadChunk(uploadId, chunkIndex, chunkBlob, onProgress) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('action', 'upload_chunk');
                formData.append('token', token);
                formData.append('password', verifiedPassword);
                formData.append('upload_id', uploadId);
                formData.append('chunk_index', chunkIndex);
                formData.append('chunk', chunkBlob, 'chunk');
                
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
                                reject(new Error(response.error || '分片上傳失敗'));
                            }
                        } catch {
                            reject(new Error('響應解析錯誤'));
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
                
                xhr.addEventListener('error', () => reject(new Error('網絡錯誤')));
                xhr.addEventListener('abort', () => reject(new Error('上傳已取消')));
                
                xhr.open('POST', `${API_BASE}/file_share_chunk_upload.php`);
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
        
        // 显示联系客服弹框
        function showContactModal() {
            document.getElementById('contactModal').style.display = 'flex';
        }
        
        // 关闭联系客服弹框
        function closeContactModal() {
            document.getElementById('contactModal').style.display = 'none';
            consecutiveFailures = 0; // 重置失败计数
            
            // 重新启用上传按钮
            const uploadBtn = document.getElementById('uploadBtn');
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = '<i class="bi bi-upload"></i> 重新上傳';
        }
        
        // 联系客服
        function contactSupport() {
            // 可以改为实际的客服链接或弹出客服窗口
            const supportEmail = 'support@example.com';
            const subject = encodeURIComponent('檔案上傳問題 - ' + (linkInfo?.project_name || ''));
            const body = encodeURIComponent('您好，我在上傳檔案時遇到問題，請協助處理。\n\n連結: ' + window.location.href);
            
            window.open(`mailto:${supportEmail}?subject=${subject}&body=${body}`, '_blank');
            closeContactModal();
        }
    </script>
</body>
</html>
