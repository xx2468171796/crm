<?php
/**
 * 帮助中心 - 整合小工具和常用QA
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

auth_require();
layout_header('帮助中心');
?>

<style>
.help-card {
    border: 1px solid #e8e8e8;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 20px;
    transition: all 0.3s;
    background: #fff;
}
.help-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.help-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    margin-bottom: 16px;
}
.help-card-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 8px;
}
.help-card-desc {
    color: #666;
    font-size: 14px;
}
.section-title {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #1890ff;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h4><i class="bi bi-question-circle me-2"></i>帮助中心</h4>
            <p class="text-muted mb-0">小工具和常用问答</p>
        </div>
    </div>

    <!-- 小工具区域 -->
    <h5 class="section-title"><i class="bi bi-tools me-2"></i>小工具</h5>
    <div class="row mb-4">
        <div class="col-md-4 col-sm-6">
            <a href="index.php?page=tools" class="text-decoration-none">
                <div class="help-card">
                    <div class="help-card-icon bg-primary text-white">
                        <i class="bi bi-calculator"></i>
                    </div>
                    <div class="help-card-title">计算工具</div>
                    <div class="help-card-desc">金额计算、汇率换算、提成计算等实用工具</div>
                </div>
            </a>
        </div>
        <div class="col-md-4 col-sm-6">
            <a href="index.php?page=exchange_rate" class="text-decoration-none">
                <div class="help-card">
                    <div class="help-card-icon bg-success text-white">
                        <i class="bi bi-currency-exchange"></i>
                    </div>
                    <div class="help-card-title">汇率查询</div>
                    <div class="help-card-desc">查看当前汇率，支持多币种转换</div>
                </div>
            </a>
        </div>
        <div class="col-md-4 col-sm-6">
            <a href="index.php?page=commission_calculator" class="text-decoration-none">
                <div class="help-card">
                    <div class="help-card-icon bg-warning text-white">
                        <i class="bi bi-percent"></i>
                    </div>
                    <div class="help-card-title">提成计算器</div>
                    <div class="help-card-desc">快速计算销售提成和技术提成</div>
                </div>
            </a>
        </div>
    </div>

    <!-- 常用QA区域 -->
    <h5 class="section-title"><i class="bi bi-chat-dots me-2"></i>常用QA</h5>
    <div class="row">
        <div class="col-12">
            <div class="accordion" id="qaAccordion">
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#qa1">
                            如何新建客户？
                        </button>
                    </h2>
                    <div id="qa1" class="accordion-collapse collapse show" data-bs-parent="#qaAccordion">
                        <div class="accordion-body">
                            点击顶部导航栏"新增客户"，填写客户基本信息后保存即可。必填字段包括：客户名称、联系方式。
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#qa2">
                            如何创建合同？
                        </button>
                    </h2>
                    <div id="qa2" class="accordion-collapse collapse" data-bs-parent="#qaAccordion">
                        <div class="accordion-body">
                            在客户详情页面，点击"财务信息"标签，选择"新建合同"。填写合同金额、分期信息后保存。
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#qa3">
                            如何登记收款？
                        </button>
                    </h2>
                    <div id="qa3" class="accordion-collapse collapse" data-bs-parent="#qaAccordion">
                        <div class="accordion-body">
                            在合同详情页面，找到对应的分期，点击"登记收款"按钮。选择收款日期、方式后确认。
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#qa4">
                            如何查看我的应收？
                        </button>
                    </h2>
                    <div id="qa4" class="accordion-collapse collapse" data-bs-parent="#qaAccordion">
                        <div class="accordion-body">
                            点击顶部导航栏"我的应收"，可查看所有待收款项。支持按状态筛选：待收、催款、逾期等。
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#qa5">
                            如何查看提成？
                        </button>
                    </h2>
                    <div id="qa5" class="accordion-collapse collapse" data-bs-parent="#qaAccordion">
                        <div class="accordion-body">
                            点击"我的工资条"可查看个人提成明细。部门主管可在"提成管理"查看团队提成。
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php layout_footer(); ?>
