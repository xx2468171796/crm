<?php
/**
 * 客户需求文档编辑页面 - 使用 EasyMDE 编辑器
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();

$customerId = intval($_GET['customer_id'] ?? 0);
if (!$customerId) {
    echo '<div class="alert alert-danger">缺少客户ID</div>';
    exit;
}

// 获取客户信息
$customer = Db::queryOne('SELECT * FROM customers WHERE id = ?', [$customerId]);
if (!$customer) {
    echo '<div class="alert alert-danger">客户不存在</div>';
    exit;
}

layout_header('需求文档 - ' . $customer['name']);
?>

<!-- EasyMDE CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">

<style>
.requirement-editor-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.editor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e0e0e0;
}

.editor-header h2 {
    margin: 0;
    color: #333;
}

.editor-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.save-status {
    font-size: 14px;
    color: #666;
    padding: 5px 10px;
}

.save-status.saving {
    color: #ffc107;
}

.save-status.saved {
    color: #28a745;
}

.save-status.error {
    color: #dc3545;
}

/* EasyMDE 自定义样式 */
.EasyMDEContainer {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.EasyMDEContainer .CodeMirror {
    min-height: 500px;
    height: calc(100vh - 300px);
    font-size: 14px;
    line-height: 1.6;
}

.editor-toolbar {
    border-bottom: 1px solid #ddd;
    background: #f8f9fa;
}

.editor-toolbar button {
    color: #333 !important;
}

.editor-toolbar button:hover {
    background: #e9ecef !important;
}

.editor-toolbar.fullscreen {
    background: #f8f9fa;
}

.CodeMirror-fullscreen {
    z-index: 9999;
}

/* Markdown 预览样式 */
.editor-preview {
    padding: 20px;
    font-size: 14px;
    line-height: 1.8;
}

.editor-preview h1 {
    font-size: 2em;
    font-weight: bold;
    margin-top: 1.5em;
    margin-bottom: 0.5em;
    padding-bottom: 0.3em;
    border-bottom: 2px solid #eee;
}

.editor-preview h2 {
    font-size: 1.5em;
    font-weight: bold;
    margin-top: 1.2em;
    margin-bottom: 0.5em;
    padding-bottom: 0.2em;
    border-bottom: 1px solid #eee;
}

.editor-preview h3 {
    font-size: 1.25em;
    font-weight: bold;
    margin-top: 1em;
    margin-bottom: 0.5em;
}

.editor-preview table {
    border-collapse: collapse;
    width: 100%;
    margin: 1em 0;
}

.editor-preview table th,
.editor-preview table td {
    border: 1px solid #ddd;
    padding: 8px 12px;
    text-align: left;
}

.editor-preview table th {
    background: #f8f9fa;
    font-weight: bold;
}

.editor-preview img {
    max-width: 100%;
    height: auto;
    border-radius: 4px;
    margin: 1em 0;
}

.editor-preview blockquote {
    border-left: 4px solid #ddd;
    padding-left: 1em;
    margin: 1em 0;
    color: #666;
}

.editor-preview code {
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', monospace;
    font-size: 0.9em;
}

.editor-preview pre {
    background: #f5f5f5;
    padding: 1em;
    border-radius: 4px;
    overflow-x: auto;
}

.editor-preview pre code {
    background: none;
    padding: 0;
}
</style>

<div class="requirement-editor-container">
    <div class="editor-header">
        <div>
            <h2>📝 编辑需求文档</h2>
            <p style="margin: 5px 0 0 0; color: #666;">
                客户: <?= htmlspecialchars($customer['name']) ?>
                <?php if ($customer['customer_code']): ?>
                    (<?= htmlspecialchars($customer['customer_code']) ?>)
                <?php endif; ?>
            </p>
        </div>
        <div class="editor-actions">
            <span id="saveStatus" class="save-status"></span>
            <button type="button" class="btn btn-info" onclick="loadCustomerInfo()">
                📋 一键读取客户信息
            </button>
            <button type="button" class="btn btn-success" onclick="saveDocument()">
                💾 保存
            </button>
            <a href="/public/customer_detail.php?id=<?= $customerId ?>" class="btn btn-secondary">
                ← 返回
            </a>
        </div>
    </div>

    <textarea id="markdown-editor"></textarea>
</div>

<!-- EasyMDE JS -->
<script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>

<script>
const customerId = <?= $customerId ?>;
let easyMDE;
let autoSaveTimer;

// 初始化编辑器
document.addEventListener('DOMContentLoaded', function() {
    easyMDE = new EasyMDE({
        element: document.getElementById('markdown-editor'),
        autofocus: true,
        spellChecker: false,
        placeholder: '在此输入 Markdown 格式的需求文档...\n\n支持标题、列表、表格、图片等格式',
        toolbar: [
            'bold', 'italic', 'heading', '|',
            'quote', 'unordered-list', 'ordered-list', '|',
            'link', 'image', 'table', '|',
            'preview', 'side-by-side', 'fullscreen', '|',
            'guide'
        ],
        uploadImage: true,
        imageUploadFunction: uploadImage,
        previewRender: function(plainText) {
            return this.parent.markdown(plainText);
        },
        renderingConfig: {
            singleLineBreaks: false,
            codeSyntaxHighlighting: true,
        },
        status: ['lines', 'words', 'cursor'],
        sideBySideFullscreen: false,
    });

    // 加载现有内容
    loadDocument();

    // 自动保存（3秒延迟）
    easyMDE.codemirror.on('change', function() {
        clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
            saveDocument(true);
        }, 3000);
    });
});

// 加载文档
function loadDocument() {
    showStatus('加载中...', 'saving');

    fetch(`/api/customer_requirements.php?action=get&customer_id=${customerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                easyMDE.value(data.data.content || '');
                showStatus('', '');
            } else {
                showStatus('加载失败', 'error');
                alert('加载失败: ' + data.error);
            }
        })
        .catch(err => {
            showStatus('加载失败', 'error');
            console.error('加载失败:', err);
            alert('加载失败，请刷新页面重试');
        });
}

// 保存文档
function saveDocument(isAutoSave = false) {
    const content = easyMDE.value();

    if (!isAutoSave) {
        showStatus('保存中...', 'saving');
    }

    fetch('/api/customer_requirements.php?action=save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            customer_id: customerId,
            content: content
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (isAutoSave) {
                showStatus('已自动保存', 'saved');
                setTimeout(() => showStatus('', ''), 2000);
            } else {
                showStatus('保存成功', 'saved');
                setTimeout(() => showStatus('', ''), 3000);
            }
        } else {
            showStatus('保存失败', 'error');
            if (!isAutoSave) {
                alert('保存失败: ' + data.error);
            }
        }
    })
    .catch(err => {
        showStatus('保存失败', 'error');
        console.error('保存失败:', err);
        if (!isAutoSave) {
            alert('保存失败，请稍后重试');
        }
    });
}

// 一键读取客户信息
function loadCustomerInfo() {
    if (!confirm('这将在编辑器中插入客户信息模板，是否继续？')) {
        return;
    }

    showStatus('读取中...', 'saving');

    fetch(`/api/customer_requirements.php?action=get_customer_info&customer_id=${customerId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const currentContent = easyMDE.value();
                if (currentContent.trim()) {
                    // 如果已有内容，询问是追加还是替换
                    if (confirm('当前已有内容，是否追加到末尾？\n\n点击"确定"追加，点击"取消"替换全部内容')) {
                        easyMDE.value(currentContent + '\n\n' + data.data.markdown);
                    } else {
                        easyMDE.value(data.data.markdown);
                    }
                } else {
                    easyMDE.value(data.data.markdown);
                }
                showStatus('读取成功', 'saved');
                setTimeout(() => showStatus('', ''), 2000);
            } else {
                showStatus('读取失败', 'error');
                alert('读取失败: ' + data.error);
            }
        })
        .catch(err => {
            showStatus('读取失败', 'error');
            console.error('读取失败:', err);
            alert('读取失败，请稍后重试');
        });
}

// 上传图片
function uploadImage(file, onSuccess, onError) {
    const formData = new FormData();
    formData.append('image', file);

    fetch('/api/customer_requirements.php?action=upload_image', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            onSuccess(data.data.url);
        } else {
            onError(data.error || '上传失败');
        }
    })
    .catch(err => {
        console.error('上传失败:', err);
        onError('上传失败，请稍后重试');
    });
}

// 显示状态
function showStatus(text, type) {
    const statusEl = document.getElementById('saveStatus');
    statusEl.textContent = text;
    statusEl.className = 'save-status ' + type;
}

// 页面关闭前提示
window.addEventListener('beforeunload', function(e) {
    // 如果有未保存的更改，提示用户
    // 注意：现代浏览器会忽略自定义消息
    if (easyMDE.value().trim()) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>

<?php layout_footer(); ?>
