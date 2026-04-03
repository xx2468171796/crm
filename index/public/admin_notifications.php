<?php
/**
 * 管理员通知发送页面
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/rbac.php';

auth_require();
$currentUser = current_user();

if (!RoleCode::isAdminRole($currentUser['role'])) {
    layout_header('无权限');
    echo '<div class="alert alert-danger">您没有权限访问此页面</div>';
    layout_footer();
    exit;
}

// 获取用户列表
$users = Db::query("SELECT id, realname, role FROM users WHERE status = 1 AND deleted_at IS NULL ORDER BY realname");

layout_header('通知管理');
?>

<style>
.notification-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.log-table th {
    background: #f8f9fa;
    font-weight: 600;
}
.log-table td {
    vertical-align: middle;
}
</style>

<div class="container-fluid py-4">
    <div class="row">
        <!-- 发送通知表单 -->
        <div class="col-md-5">
            <div class="notification-card p-4 mb-4">
                <h5 class="mb-4">
                    <i class="bi bi-send text-primary"></i> 发送系统通知
                </h5>
                
                <form id="sendNotificationForm">
                    <div class="mb-3">
                        <label class="form-label">通知标题 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="notifyTitle" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">通知内容 <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="notifyContent" rows="4" required maxlength="500"></textarea>
                        <small class="text-muted">最多500字</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">通知类型</label>
                        <select class="form-select" id="notifyType">
                            <option value="system">系统通知</option>
                            <option value="task">任务通知</option>
                            <option value="project">项目通知</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">接收人</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="recipientType" id="recipientAll" value="all" checked>
                            <label class="form-check-label" for="recipientAll">
                                所有用户
                            </label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="recipientType" id="recipientSelect" value="select">
                            <label class="form-check-label" for="recipientSelect">
                                指定用户
                            </label>
                        </div>
                        
                        <div id="userSelectContainer" style="display: none;">
                            <select class="form-select" id="recipientUsers" multiple size="6">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['realname']) ?> (<?= $u['role'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">按住 Ctrl 多选</small>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send"></i> 发送通知
                    </button>
                </form>
            </div>
        </div>
        
        <!-- 发送记录 -->
        <div class="col-md-7">
            <div class="notification-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history text-secondary"></i> 发送记录
                    </h5>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadLogs()">
                        <i class="bi bi-arrow-clockwise"></i> 刷新
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover log-table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>标题</th>
                                <th>类型</th>
                                <th>接收人数</th>
                                <th>发送人</th>
                            </tr>
                        </thead>
                        <tbody id="logTableBody">
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">加载中...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <nav id="logPagination" class="mt-3"></nav>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 接收人类型切换
    document.querySelectorAll('input[name="recipientType"]').forEach(radio => {
        radio.addEventListener('change', function() {
            document.getElementById('userSelectContainer').style.display = 
                this.value === 'select' ? 'block' : 'none';
        });
    });
    
    // 表单提交
    document.getElementById('sendNotificationForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const title = document.getElementById('notifyTitle').value.trim();
        const content = document.getElementById('notifyContent').value.trim();
        const type = document.getElementById('notifyType').value;
        const recipientType = document.querySelector('input[name="recipientType"]:checked').value;
        
        let recipients = 'all';
        if (recipientType === 'select') {
            const selected = Array.from(document.getElementById('recipientUsers').selectedOptions);
            recipients = selected.map(opt => parseInt(opt.value));
            if (recipients.length === 0) {
                showAlertModal('请选择至少一个接收人', 'warning');
                return;
            }
        }
        
        try {
            const response = await fetch(`${API_URL}/admin_send_notification.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title, content, type, recipients })
            });
            
            const data = await response.json();
            if (data.success) {
                showAlertModal(data.message, 'success');
                document.getElementById('sendNotificationForm').reset();
                loadLogs();
            } else {
                showAlertModal(data.error || '发送失败', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showAlertModal('发送失败，请稍后重试', 'error');
        }
    });
    
    // 加载发送记录
    loadLogs();
});

async function loadLogs(page = 1) {
    try {
        const response = await fetch(`${API_URL}/admin_send_notification.php?page=${page}`);
        const data = await response.json();
        
        if (data.success) {
            renderLogs(data.data.logs);
            renderPagination(data.data.pagination);
        }
    } catch (error) {
        console.error('Error loading logs:', error);
    }
}

function renderLogs(logs) {
    const tbody = document.getElementById('logTableBody');
    
    if (logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">暂无发送记录</td></tr>';
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        const typeLabels = { system: '系统', task: '任务', project: '项目' };
        const typeBadges = { system: 'bg-secondary', task: 'bg-success', project: 'bg-primary' };
        
        return `
            <tr>
                <td><small>${log.send_time}</small></td>
                <td>
                    <strong>${escapeHtml(log.title)}</strong>
                    <div class="text-muted small text-truncate" style="max-width: 200px;" title="${escapeHtml(log.content)}">
                        ${escapeHtml(log.content)}
                    </div>
                </td>
                <td><span class="badge ${typeBadges[log.type] || 'bg-secondary'}">${typeLabels[log.type] || log.type}</span></td>
                <td>${log.recipient_count} 人</td>
                <td>${escapeHtml(log.sender_name || '-')}</td>
            </tr>
        `;
    }).join('');
}

function renderPagination(pagination) {
    const nav = document.getElementById('logPagination');
    if (pagination.total <= pagination.page_size) {
        nav.innerHTML = '';
        return;
    }
    
    const totalPages = Math.ceil(pagination.total / pagination.page_size);
    let html = '<ul class="pagination pagination-sm justify-content-center mb-0">';
    
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === pagination.page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="loadLogs(${i}); return false;">${i}</a>
        </li>`;
    }
    
    html += '</ul>';
    nav.innerHTML = html;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php
layout_footer();
?>
