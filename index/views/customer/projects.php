<?php
/**
 * 客户详情页 - 项目模块
 * 显示该客户的所有项目列表，支持创建、编辑、分配技术
 */

$customerId = $customer['id'] ?? 0;
if ($customerId <= 0) {
    echo '<div class="alert alert-danger">客户ID无效</div>';
    return;
}
?>

<div class="projects-container" style="padding: 20px; flex: 1; overflow-y: auto;">
    <!-- 门户设置卡片 -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>🏠 客户门户</strong>
                    <span id="portalStatus" class="ms-2 badge bg-secondary">加载中...</span>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openPortalSettingsModal()">
                        ⚙️ 门户设置
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm ms-1" onclick="copyPortalLink()">
                        📋 复制链接
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">项目列表</h5>
        <?php if (canOrAdmin(PermissionCode::PROJECT_CREATE)): ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="openCreateProjectModal()">
            <i class="bi bi-plus-circle"></i> 新建项目
        </button>
        <?php endif; ?>
    </div>

    <div id="projectsList">
        <div class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm" role="status">
                <span class="visually-hidden">加载中...</span>
            </div>
            <p class="mt-2">加载项目列表...</p>
            <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadProjects()">手动加载</button>
        </div>
    </div>
</div>

<!-- 新建项目模态框 -->
<div class="modal fade" id="createProjectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新建项目</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createProjectForm">
                    <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                    <div class="mb-3">
                        <label class="form-label">项目名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="project_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">群码</label>
                        <input type="text" class="form-control" name="group_code" value="<?= htmlspecialchars($customer['group_code'] ?? '') ?>">
                        <small class="text-muted">默认使用客户群码</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">开始日期</label>
                        <input type="date" class="form-control" name="start_date">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">截止日期</label>
                        <input type="date" class="form-control" name="deadline">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitCreateProject()">创建</button>
            </div>
        </div>
    </div>
</div>

<!-- 门户设置模态框 -->
<div class="modal fade" id="portalSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">🏠 门户设置</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">访问密码</label>
                    <input type="password" class="form-control" id="portalPassword" placeholder="留空表示无需密码">
                    <small class="text-muted">设置后客户访问门户需要输入此密码</small>
                </div>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="portalEnabled" checked>
                        <label class="form-check-label" for="portalEnabled">启用门户访问</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">过期时间（可选）</label>
                    <input type="date" class="form-control" id="portalExpiresAt">
                    <small class="text-muted">留空表示永不过期</small>
                </div>
                <div id="portalInfo" class="alert alert-info" style="display:none;">
                    <small>
                        <strong>访问次数：</strong><span id="portalAccessCount">0</span><br>
                        <strong>最后访问：</strong><span id="portalLastAccess">-</span>
                    </small>
                </div>
                <!-- 访问地址管理 -->
                <div id="portalRegionLinksSection" style="display:none;">
                    <hr>
                    <div class="card border-primary">
                        <div class="card-header bg-primary bg-opacity-10 py-2">
                            <strong>🌐 访问地址管理</strong>
                            <small class="text-muted ms-2">选择合适的区域链接发送给客户</small>
                        </div>
                        <div class="card-body py-2">
                            <div id="portalRegionLinksContainer">
                                <small class="text-muted">加载中...</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="savePortalSettings()">保存设置</button>
            </div>
        </div>
    </div>
</div>

<!-- 分配技术模态框 -->
<div class="modal fade" id="assignTechModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">分配技术</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">选择要分配给此项目的技术人员：</p>
                <div id="techListContainer">
                    <div class="text-center">
                        <div class="spinner-border spinner-border-sm" role="status"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitAssignTech()">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
var PROJ_API_URL = window.location.origin + '/api';
var PROJ_CUSTOMER_ID = <?= $customerId ?>;
var currentProjectId = null;
var portalToken = null;

console.log('[PROJ_DEBUG] Script loaded, customerId:', PROJ_CUSTOMER_ID);

// 加载门户信息
function loadPortalInfo() {
    fetch(PROJ_API_URL + '/portal_password.php?customer_id=' + PROJ_CUSTOMER_ID)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var statusEl = document.getElementById('portalStatus');
            if (data.success && data.data) {
                portalToken = data.data.token;
                var hasPassword = data.data.has_password == 1;
                var enabled = data.data.enabled == 1;
                
                if (!enabled) {
                    statusEl.className = 'ms-2 badge bg-danger';
                    statusEl.textContent = '已禁用';
                } else if (hasPassword) {
                    statusEl.className = 'ms-2 badge bg-success';
                    statusEl.textContent = '已启用（有密码）';
                } else {
                    statusEl.className = 'ms-2 badge bg-warning text-dark';
                    statusEl.textContent = '已启用（无密码）';
                }
                
                // 填充模态框数据
                document.getElementById('portalEnabled').checked = enabled;
                if (data.data.expires_at) {
                    var d = new Date(data.data.expires_at * 1000);
                    document.getElementById('portalExpiresAt').value = d.toISOString().split('T')[0];
                }
                if (data.data.access_count > 0) {
                    document.getElementById('portalInfo').style.display = 'block';
                    document.getElementById('portalAccessCount').textContent = data.data.access_count;
                    if (data.data.last_access_at) {
                        var lastAccess = new Date(data.data.last_access_at * 1000);
                        document.getElementById('portalLastAccess').textContent = lastAccess.toLocaleString('zh-CN');
                    }
                }
            } else {
                statusEl.className = 'ms-2 badge bg-secondary';
                statusEl.textContent = '未创建';
            }
        })
        .catch(function(err) {
            console.error('Load portal info error:', err);
            document.getElementById('portalStatus').textContent = '加载失败';
        });
}

// 打开门户设置模态框
function openPortalSettingsModal() {
    document.getElementById('portalPassword').value = '';
    new bootstrap.Modal(document.getElementById('portalSettingsModal')).show();
    
    // 加载多区域链接
    if (portalToken) {
        document.getElementById('portalRegionLinksSection').style.display = 'block';
        loadPortalRegionLinks();
    } else {
        document.getElementById('portalRegionLinksSection').style.display = 'none';
    }
}

// 加载门户多区域链接
function loadPortalRegionLinks() {
    var container = document.getElementById('portalRegionLinksContainer');
    container.innerHTML = '<small class="text-muted">加载中...</small>';
    
    fetch('/api/portal_link.php?action=get_region_urls&token=' + encodeURIComponent(portalToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.regions && data.regions.length > 0) {
                var html = '';
                data.regions.forEach(function(r, idx) {
                    html += '<div class="input-group input-group-sm mb-2">' +
                        '<span class="input-group-text" style="min-width:70px;font-size:11px;">' + (r.is_default ? '⭐' : '') + ' ' + r.region_name + '</span>' +
                        '<input type="text" class="form-control portal-region-input" id="portalRegionLink_' + idx + '" value="' + r.url + '" readonly style="font-size:11px;">' +
                        '<button class="btn btn-outline-primary" type="button" data-portal-link-idx="' + idx + '">复制</button>' +
                    '</div>';
                });
                container.innerHTML = html;
                
                // 使用 addEventListener 绑定复制按钮
                container.querySelectorAll('button[data-portal-link-idx]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var idx = this.getAttribute('data-portal-link-idx');
                        var input = document.getElementById('portalRegionLink_' + idx);
                        if (input) {
                            input.select();
                            document.execCommand('copy');
                            showAlertModal('链接已复制到剪贴板！', 'success');
                        }
                    });
                });
            } else {
                // 无区域配置，显示默认链接
                var defaultUrl = window.location.origin + '/portal.php?token=' + portalToken;
                container.innerHTML = '<div class="input-group input-group-sm">' +
                    '<input type="text" class="form-control" id="portalDefaultLink" value="' + defaultUrl + '" readonly style="font-size:11px;">' +
                    '<button class="btn btn-outline-primary" type="button" id="copyPortalDefaultBtn">复制</button>' +
                '</div><small class="text-muted">未配置区域节点，使用默认链接</small>';
                
                document.getElementById('copyPortalDefaultBtn').addEventListener('click', function() {
                    var input = document.getElementById('portalDefaultLink');
                    input.select();
                    document.execCommand('copy');
                    showAlertModal('链接已复制到剪贴板！', 'success');
                });
            }
        })
        .catch(function(err) {
            var defaultUrl = window.location.origin + '/portal.php?token=' + portalToken;
            container.innerHTML = '<div class="input-group input-group-sm">' +
                '<input type="text" class="form-control" id="portalDefaultLink" value="' + defaultUrl + '" readonly style="font-size:11px;">' +
                '<button class="btn btn-outline-primary" type="button" id="copyPortalDefaultBtn">复制</button>' +
            '</div>';
            
            document.getElementById('copyPortalDefaultBtn').addEventListener('click', function() {
                var input = document.getElementById('portalDefaultLink');
                input.select();
                document.execCommand('copy');
                showAlertModal('链接已复制到剪贴板！', 'success');
            });
        });
}

// 保存门户设置
function savePortalSettings() {
    var password = document.getElementById('portalPassword').value;
    var enabled = document.getElementById('portalEnabled').checked ? 1 : 0;
    var expiresAt = document.getElementById('portalExpiresAt').value;
    
    fetch(PROJ_API_URL + '/portal_password.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            customer_id: PROJ_CUSTOMER_ID,
            password: password,
            enabled: enabled,
            expires_at: expiresAt
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            if (data.data && data.data.token) {
                portalToken = data.data.token;
            }
            showAlertModal('门户设置已保存', 'success');
            bootstrap.Modal.getInstance(document.getElementById('portalSettingsModal')).hide();
            loadPortalInfo();
        } else {
            showAlertModal('保存失败: ' + data.message, 'error');
        }
    })
    .catch(function(err) {
        showAlertModal('保存失败: ' + err.message, 'error');
    });
}

// 复制门户链接（默认链接，完整链接请在门户设置中查看）
function copyPortalLink() {
    if (!portalToken) {
        showAlertModal('请先在门户设置中创建门户', 'warning');
        return;
    }
    
    var url = window.location.origin + '/portal.php?token=' + portalToken;
    copyTextToClipboard(url);
    showAlertModal('默认链接已复制！<br><small class="text-muted">如需其他区域链接，请点击"门户设置"查看</small>', 'success');
}

function copyPortalRegionLinkInput(inputId) {
    var input = document.getElementById(inputId);
    if (input) {
        var text = input.value;
        console.log('[PORTAL_COPY_DEBUG] Copying:', text);
        
        // 优先使用 Clipboard API
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                console.log('[PORTAL_COPY_DEBUG] Clipboard API success');
                showAlertModal('链接已复制到剪贴板！', 'success');
            }).catch(function(err) {
                console.log('[PORTAL_COPY_DEBUG] Clipboard API failed:', err);
                fallbackCopyPortal(text);
            });
        } else {
            fallbackCopyPortal(text);
        }
    }
}

function fallbackCopyPortal(text) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    
    try {
        var success = document.execCommand('copy');
        console.log('[PORTAL_COPY_DEBUG] execCommand result:', success);
        if (success) {
            showAlertModal('链接已复制到剪贴板！', 'success');
        } else {
            showAlertModal('复制失败，请手动复制', 'warning');
        }
    } catch (err) {
        console.error('[PORTAL_COPY_DEBUG] Copy failed:', err);
        showAlertModal('复制失败，请手动复制', 'warning');
    }
    
    document.body.removeChild(textarea);
}

function copyTextToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            console.log('[PORTAL_COPY_DEBUG] Clipboard API success');
        }).catch(function(err) {
            fallbackCopyPortal(text);
        });
    } else {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
        } catch (err) {
            console.error('Copy failed:', err);
        }
        document.body.removeChild(textarea);
    }
}

// 页面加载时获取门户信息
setTimeout(loadPortalInfo, 500);

function loadProjects() {
    console.log('[PROJ_DEBUG] loadProjects called');
    var url = PROJ_API_URL + '/projects.php?customer_id=' + PROJ_CUSTOMER_ID;
    fetch(url)
        .then(function(r) {
            console.log('[PROJ_DEBUG] Response status:', r.status);
            return r.json();
        })
        .then(function(data) {
            console.log('[PROJ_DEBUG] Response data:', data);
            if (data.success) {
                renderProjects(data.data);
            } else {
                document.getElementById('projectsList').innerHTML = 
                    '<div class="alert alert-warning">' + data.message + '</div>';
            }
        })
        .catch(function(err) {
            console.error('[PROJ_DEBUG] Fetch error:', err);
            document.getElementById('projectsList').innerHTML = 
                '<div class="alert alert-danger">加载失败: ' + err.message + '</div>';
        });
}

function renderProjects(projects) {
    var container = document.getElementById('projectsList');
    
    if (!projects || projects.length === 0) {
        container.innerHTML = '<div class="alert alert-info">暂无项目，点击"新建项目"开始</div>';
        return;
    }
    
    var html = '<div class="list-group">';
    for (var i = 0; i < projects.length; i++) {
        var project = projects[i];
        var statusBadge = getStatusBadge(project.current_status);
        var updateTime = new Date(project.update_time * 1000).toLocaleString('zh-CN');
        
        var formContainerId = 'projectForms_' + project.id;
        html += '<div class="list-group-item">' +
            '<div class="d-flex justify-content-between align-items-start">' +
                '<div class="flex-grow-1">' +
                    '<h6 class="mb-1">' + project.project_name + 
                        ' <small class="text-muted">' + project.project_code + '</small></h6>' +
                    '<p class="mb-1">' + statusBadge + 
                        (project.group_code ? ' <span class="badge bg-secondary">' + project.group_code + '</span>' : '') +
                    '</p>' +
                    '<small class="text-muted">更新时间：' + updateTime + '</small>' +
                    '<div id="' + formContainerId + '" class="mt-2 pt-2 border-top"><small class="text-muted">加载表单中...</small></div>' +
                '</div>' +
                '<div class="btn-group">' +
                    '<button class="btn btn-sm btn-outline-primary" onclick="openAssignTechModal(' + project.id + ')">👨‍💻 分配技术</button>' +
                    '<button class="btn btn-sm btn-outline-info" onclick="viewProjectDetail(' + project.id + ')"><i class="bi bi-eye"></i> 查看详情</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
    
    // 加载每个项目的表单
    for (var j = 0; j < projects.length; j++) {
        loadProjectForms(projects[j].id, 'projectForms_' + projects[j].id);
    }
}

function getStatusBadge(status) {
    var badges = {
        '待沟通': 'secondary',
        '需求确认': 'info',
        '设计中': 'primary',
        '设计核对': 'warning',
        '设计完工': 'success',
        '设计评价': 'dark'
    };
    var color = badges[status] || 'secondary';
    return '<span class="badge bg-' + color + '">' + status + '</span>';
}

function openCreateProjectModal() {
    document.getElementById('createProjectForm').reset();
    new bootstrap.Modal(document.getElementById('createProjectModal')).show();
}

function submitCreateProject() {
    var form = document.getElementById('createProjectForm');
    var formData = new FormData(form);
    var data = {};
    formData.forEach(function(value, key) { data[key] = value; });
    
    fetch(PROJ_API_URL + '/projects.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            showAlertModal('项目创建成功', 'success');
            bootstrap.Modal.getInstance(document.getElementById('createProjectModal')).hide();
            loadProjects();
        } else {
            showAlertModal('创建失败: ' + result.message, 'error');
        }
    })
    .catch(function(err) {
        showAlertModal('创建失败: ' + err.message, 'error');
    });
}

function openAssignTechModal(projectId) {
    currentProjectId = projectId;
    new bootstrap.Modal(document.getElementById('assignTechModal')).show();
    
    fetch(PROJ_API_URL + '/project_tech_assign.php?action=get&project_id=' + projectId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderTechList(data.data);
            } else {
                document.getElementById('techListContainer').innerHTML = 
                    '<div class="alert alert-warning">' + data.message + '</div>';
            }
        })
        .catch(function(err) {
            document.getElementById('techListContainer').innerHTML = 
                '<div class="alert alert-danger">加载失败: ' + err.message + '</div>';
        });
}

function renderTechList(data) {
    var container = document.getElementById('techListContainer');
    var assignedIds = data.assignments.map(function(a) { return a.tech_user_id; });
    
    if (!data.available_techs || data.available_techs.length === 0) {
        container.innerHTML = '<p class="text-muted">暂无技术人员</p>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < data.available_techs.length; i++) {
        var tech = data.available_techs[i];
        var isAssigned = assignedIds.indexOf(tech.id) !== -1;
        html += '<div class="form-check mb-2">' +
            '<input class="form-check-input tech-checkbox" type="checkbox" value="' + tech.id + 
            '" id="tech_' + tech.id + '"' + (isAssigned ? ' checked' : '') + '>' +
            '<label class="form-check-label" for="tech_' + tech.id + '">' +
                (tech.realname || tech.username) +
                (isAssigned ? ' <span class="badge bg-success ms-2">已分配</span>' : '') +
            '</label></div>';
    }
    container.innerHTML = html;
}

function submitAssignTech() {
    var checkboxes = document.querySelectorAll('#assignTechModal .tech-checkbox:checked');
    var techUserIds = [];
    for (var i = 0; i < checkboxes.length; i++) {
        techUserIds.push(checkboxes[i].value);
    }
    
    fetch(PROJ_API_URL + '/project_tech_assign.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'sync',
            project_id: currentProjectId,
            tech_user_ids: techUserIds
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showAlertModal('分配成功', 'success');
            bootstrap.Modal.getInstance(document.getElementById('assignTechModal')).hide();
            loadProjects();
        } else {
            showAlertModal('分配失败: ' + data.message, 'error');
        }
    })
    .catch(function(err) {
        showAlertModal('保存失败: ' + err.message, 'error');
    });
}

function viewProjectDetail(projectId) {
    window.location.href = '/index.php?page=project_detail&id=' + projectId;
}

// 加载项目的表单实例
function loadProjectForms(projectId, containerId) {
    fetch(PROJ_API_URL + '/form_instances.php?project_id=' + projectId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var container = document.getElementById(containerId);
            if (!container) return;
            
            if (data.success && data.data && data.data.length > 0) {
                var html = '<div class="mt-2">';
                for (var i = 0; i < data.data.length; i++) {
                    var form = data.data[i];
                    var statusClass = form.status === 'submitted' ? 'bg-success' : 'bg-warning';
                    var statusText = form.status === 'submitted' ? '已提交' : '待填写';
                    var fillBtn = form.status !== 'submitted' ? 
                        '<a href="/form_fill.php?token=' + form.fill_token + '" target="_blank" class="btn btn-sm btn-primary ms-2">填写</a>' : '';
                    
                    html += '<div class="d-flex align-items-center justify-content-between py-1 border-bottom">' +
                        '<span><i class="bi bi-file-text me-1"></i>' + form.instance_name + '</span>' +
                        '<span><span class="badge ' + statusClass + '">' + statusText + '</span>' + fillBtn + '</span>' +
                    '</div>';
                }
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<small class="text-muted">暂无需求表单</small>';
            }
        })
        .catch(function(err) {
            console.error('Load forms error:', err);
        });
}

console.log('[PROJ_DEBUG] All functions defined');
</script>

<style>
.projects-container {
    background: #fff;
}
.list-group-item {
    border-left: 3px solid #dee2e6;
    transition: all 0.2s;
}
.list-group-item:hover {
    border-left-color: #0d6efd;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>
