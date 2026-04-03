<?php
/**
 * 评价模板配置页面
 */

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/rbac.php';
require_once __DIR__ . '/../../core/layout.php';

auth_require();
$user = current_user();

if (!isAdmin($user)) {
    echo '<div class="alert alert-danger m-4">无权限访问此页面</div>';
    exit;
}

$pageTitle = '评价模板配置';
layout_header($pageTitle);
?>

<style>
.eval-config-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
}
.eval-config-header {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #fff;
    padding: 24px 32px;
}
.eval-config-header h4 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
}
.eval-config-header .subtitle {
    opacity: 0.9;
    margin-top: 8px;
    font-size: 14px;
}
.eval-config-body {
    padding: 32px;
}
.config-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
}
.config-section-title {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.config-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 15px;
    transition: all 0.2s;
    background: #fff;
}
.config-select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.config-help {
    color: #64748b;
    font-size: 13px;
    margin-top: 10px;
}
.btn-save-config {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #fff;
    border: none;
    padding: 12px 32px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-save-config:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
}
.info-card {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-radius: 12px;
    padding: 24px;
    border-left: 4px solid #3b82f6;
}
.info-card h6 {
    color: #1e40af;
    font-weight: 600;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.info-card ul {
    margin: 0;
    padding-left: 20px;
    color: #1e40af;
}
.info-card li {
    margin-bottom: 8px;
    line-height: 1.6;
}
.info-card li:last-child {
    margin-bottom: 0;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}
.status-badge.success {
    background: #dcfce7;
    color: #166534;
}
.status-badge.warning {
    background: #fef3c7;
    color: #92400e;
}
</style>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="eval-config-card">
                <div class="eval-config-header">
                    <h4><i class="bi bi-star-fill"></i> 评价模板配置</h4>
                    <div class="subtitle">配置客户评价时使用的表单模板</div>
                </div>
                <div class="eval-config-body">
                    <div class="row">
                        <div class="col-lg-7">
                            <div class="config-section">
                                <div class="config-section-title">
                                    <i class="bi bi-gear" style="color: #6366f1;"></i>
                                    默认评价表单模板
                                </div>
                                <select id="defaultEvaluationTemplate" class="config-select">
                                    <option value="0">使用简单评分（5星+文字）</option>
                                </select>
                                <div class="config-help">
                                    选择客户评价时使用的表单模板。如果选择"简单评分"，将使用默认的5星评分系统。
                                </div>
                            </div>
                            
                            <div class="d-flex align-items-center gap-3">
                                <button type="button" class="btn-save-config" onclick="saveConfig()">
                                    <i class="bi bi-check-lg"></i> 保存配置
                                </button>
                                <span id="saveStatus"></span>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="info-card">
                                <h6><i class="bi bi-lightbulb-fill"></i> 使用说明</h6>
                                <ul>
                                    <li>评价表单模板需要先在<a href="index.php?page=admin_form_templates">表单模板</a>中创建，类型选择"评价表单"</li>
                                    <li>项目进入"设计评价"阶段时，会自动创建评价表单实例</li>
                                    <li>客户在门户中填写评价表单后，项目自动完工</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 避免重复声明
if (typeof window.EVAL_API_URL === 'undefined') {
    window.EVAL_API_URL = '/api';
}
const EVAL_API = window.EVAL_API_URL;

// 加载评价模板列表
async function loadEvaluationTemplates() {
    try {
        const response = await fetch(`${EVAL_API}/form_templates.php?form_type=evaluation&status=published`);
        const data = await response.json();
        
        const select = document.getElementById('defaultEvaluationTemplate');
        
        if (data.success && data.data) {
            data.data.forEach(template => {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = template.name;
                select.appendChild(option);
            });
        }
        
        // 加载当前配置
        loadCurrentConfig();
    } catch (error) {
        console.error('加载模板失败:', error);
    }
}

// 加载当前配置
async function loadCurrentConfig() {
    try {
        const response = await fetch(`${EVAL_API}/system_config.php?key=default_evaluation_template_id`);
        const data = await response.json();
        
        if (data.success && data.data) {
            document.getElementById('defaultEvaluationTemplate').value = data.data.config_value || '0';
        }
    } catch (error) {
        console.error('加载配置失败:', error);
    }
}

// 保存配置
async function saveConfig() {
    const templateId = document.getElementById('defaultEvaluationTemplate').value;
    
    try {
        const response = await fetch(`${EVAL_API}/system_config.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                key: 'default_evaluation_template_id',
                value: templateId
            })
        });
        const data = await response.json();
        
        if (data.success) {
            alert('配置保存成功');
        } else {
            alert('保存失败: ' + (data.message || '未知错误'));
        }
    } catch (error) {
        alert('保存失败: ' + error.message);
    }
}

// 页面加载
document.addEventListener('DOMContentLoaded', loadEvaluationTemplates);
</script>

<?php layout_footer(); ?>
