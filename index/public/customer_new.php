<?php
// 新增客户 - 首通（完全动态字段版本）
// 版本: 20251120_v5

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';
require_once __DIR__ . '/../core/field_renderer.php';

// 检查登录
if (!is_logged_in()) {
    redirect('/login.php');
}

layout_header('新增客户 - 首通');

$user = current_user();
$error = '';
$success = '';
$customerCode = '';
$intentSummary = '';

// 加载所有菜单（用于左侧导航）
$menus = Db::query('SELECT * FROM menus WHERE status = 1 ORDER BY sort_order, id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? '');
    $gender   = trim($_POST['gender'] ?? '');
    $age      = intval($_POST['age'] ?? 0) ?: null;
    $customId = trim($_POST['custom_id'] ?? '');

    $identity         = trim($_POST['identity'] ?? '');
    $identityCustom   = trim($_POST['identity_custom'] ?? '');
    $demandTimeType   = trim($_POST['demand_time_type'] ?? '');
    $demandCustom     = trim($_POST['demand_custom'] ?? '');
    
    // 使用动态字段处理函数
    $dynamicFieldValues = processFieldValues('first_contact', $_POST);
    $keyQuestions     = $dynamicFieldValues['key_questions'] ?? '';
    $keyMessages      = $dynamicFieldValues['key_messages'] ?? '';
    $materialsToSend  = $dynamicFieldValues['materials_to_send'] ?? '';
    $helpers          = $dynamicFieldValues['helpers'] ?? '';
    
    $nextFollowTime   = trim($_POST['next_follow_time'] ?? '');
    $remark           = trim($_POST['remark'] ?? '');

    if ($identity === '自定义' && $identityCustom !== '') {
        $identity = $identityCustom;
    }
    if ($demandTimeType === '自定义' && $demandCustom !== '') {
        $demandTimeType = $demandCustom;
    }

    if ($name === '') {
        $error = '请填写客户姓名';
    }

    if (!$error) {
        $now   = time();
        $uid   = $user['id'];
        $dept  = $user['department_id'] ?? null;

        $intentLevel   = null;
        $intentScore   = null;
        $intentSummary = '';

        if (in_array($demandTimeType, ['当天有案子', '1-3天有案子'])) {
            $intentLevel   = 'high';
            $intentScore   = 90;
            $intentSummary = '需求时间紧迫，意向偏高';
        } elseif (in_array($demandTimeType, ['3-7天有案子', '7-14天有案子'])) {
            $intentLevel   = 'medium';
            $intentScore   = 70;
            $intentSummary = '中短期需求，意向中等';
        } elseif ($demandTimeType !== '') {
            $intentLevel   = 'low';
            $intentScore   = 50;
            $intentSummary = '需求时间较远，意向偏低';
        }

        try {
            Db::execute('INSERT INTO customers
                (customer_code, custom_id, name, mobile, gender, age, identity, demand_time_type,
                 intent_level, intent_score, intent_summary,
                 owner_user_id, department_id, status,
                 create_time, update_time, create_user_id, update_user_id)
                 VALUES
                (:code, :custom_id, :name, :mobile, :gender, :age, :identity, :demand_time_type,
                 :intent_level, :intent_score, :intent_summary,
                 :owner_user_id, :department_id, 1,
                 :create_time, :update_time, :create_user_id, :update_user_id)', [
                'code'            => '',
                'custom_id'       => $customId,
                'name'            => $name,
                'mobile'          => $mobile,
                'gender'          => $gender,
                'age'             => $age,
                'identity'        => $identity,
                'demand_time_type'=> $demandTimeType,
                'intent_level'    => $intentLevel,
                'intent_score'    => $intentScore,
                'intent_summary'  => $intentSummary,
                'owner_user_id'   => $uid,
                'department_id'   => $dept,
                'create_time'     => $now,
                'update_time'     => $now,
                'create_user_id'  => $uid,
                'update_user_id'  => $uid,
            ]);

            $row = Db::queryOne('SELECT LAST_INSERT_ID() AS id');
            $customerId = $row ? intval($row['id']) : 0;

            if ($customerId <= 0) {
                throw new Exception('获取客户ID失败');
            }

            $customerCode = 'CUST-' . date('Y') . '-' . str_pad((string)$customerId, 6, '0', STR_PAD_LEFT);
            Db::execute('UPDATE customers SET customer_code = :code WHERE id = :id', [
                'code' => $customerCode,
                'id'   => $customerId,
            ]);

            $nextFollowTimestamp = $nextFollowTime !== '' ? strtotime($nextFollowTime) : null;

            Db::execute('INSERT INTO first_contact
                (customer_id, identity, demand_time_type, key_questions, key_messages,
                 materials_to_send, helpers, next_follow_time, remark,
                 create_time, update_time, create_user_id, update_user_id)
                 VALUES
                (:customer_id, :identity, :demand_time_type, :key_questions, :key_messages,
                 :materials_to_send, :helpers, :next_follow_time, :remark,
                 :create_time, :update_time, :create_user_id, :update_user_id)', [
                'customer_id'       => $customerId,
                'identity'          => $identity,
                'demand_time_type'  => $demandTimeType,
                'key_questions'     => $keyQuestions,
                'key_messages'      => $keyMessages,
                'materials_to_send' => $materialsToSend,
                'helpers'           => $helpers,
                'next_follow_time'  => $nextFollowTimestamp,
                'remark'            => $remark,
                'create_time'       => $now,
                'update_time'       => $now,
                'create_user_id'    => $uid,
                'update_user_id'    => $uid,
            ]);

            $success = '客户创建成功！客户ID: ' . $customerCode;
        } catch (Exception $e) {
            $error = '保存失败: ' . $e->getMessage();
        }
    }
}
?>

<style>
body { font-size: 14px; }
.main-container { border: 1px solid #dee2e6; background: #fff; }
.top-bar { background: #f8f9fa; padding: 10px 15px; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; gap: 10px; }
.top-bar input, .top-bar select { font-size: 13px; height: 32px; }
.top-bar label { margin: 0; font-size: 12px; color: #666; }
.sidebar { width: 150px; border-right: 1px solid #dee2e6; background: #fafafa; }
.sidebar .nav-link { padding: 10px 15px; font-size: 13px; border-bottom: 1px solid #e9ecef; color: #495057; }
.sidebar .nav-link.active { background: #0d6efd; color: #fff; font-weight: 600; }
.content-area { flex: 1; padding: 15px; }
.field-row { margin-bottom: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
.field-row:last-child { border-bottom: none; }
.field-label { font-weight: 600; margin-bottom: 5px; font-size: 13px; color: #333; }
.field-options { display: flex; flex-wrap: wrap; gap: 15px; }
.field-options label { font-size: 13px; margin: 0; }
.remark-box { width: 100%; min-height: 100px; font-size: 13px; }
.bottom-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 2px solid #dee2e6; }
.intent-box { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin-top: 10px; border-radius: 4px; }
.intent-box h6 { color: #0056b3; margin: 0 0 8px 0; }
</style>

<!-- 提示弹窗 -->
<?php if ($error): ?>
<div class="modal fade" id="alertModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">❌ 错误</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?= htmlspecialchars($error) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>
<script>
var alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
alertModal.show();
</script>
<?php endif; ?>

<?php if ($success): ?>
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">✅ 成功</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?= htmlspecialchars($success) ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" data-bs-dismiss="modal">确定</button>
            </div>
        </div>
    </div>
</div>
<script>
var successModal = new bootstrap.Modal(document.getElementById('successModal'));
successModal.show();
</script>
<?php endif; ?>

<form method="post">
    <div class="main-container">
        <!-- 顶部信息栏 -->
        <div class="top-bar">
            <div>
                <label>客户姓名</label>
                <input type="text" name="name" class="form-control form-control-sm" style="width:120px;" required>
            </div>
            <div>
                <label>联系方式</label>
                <input type="text" name="mobile" class="form-control form-control-sm" style="width:140px;">
            </div>
            <div>
                <label>客户群</label>
                <input type="text" name="customer_group" class="form-control form-control-sm" style="width:140px;" placeholder="可选">
            </div>
            <div>
                <label>性别</label>
                <select name="gender" class="form-select form-select-sm" style="width:70px;">
                    <option value="">-</option>
                    <option value="男">男</option>
                    <option value="女">女</option>
                </select>
            </div>
            <div>
                <label>年龄</label>
                <input type="number" name="age" class="form-control form-control-sm" style="width:70px;" min="0" max="120">
            </div>
            <div>
                <label>ID</label>
                <input type="text" name="custom_id" class="form-control form-control-sm" style="width:100px;" placeholder="手动填写">
            </div>
            <div>
                <label>自动生成ID</label>
                <input type="text" class="form-control form-control-sm" style="width:180px;" value="<?= $customerCode ? $customerCode : '保存后生成' ?>" readonly>
            </div>
            <div style="margin-left: auto;">
                <button type="button" class="btn btn-outline-primary btn-sm" disabled>链接分享</button>
            </div>
        </div>

        <div style="display: flex;">
            <!-- 左侧Tab（动态加载） -->
            <div class="sidebar">
                <ul class="nav nav-pills flex-column">
                    <?php foreach ($menus as $index => $menu): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $index === 0 ? 'active' : '' ?>" 
                               href="#" 
                               data-menu-code="<?= htmlspecialchars($menu['menu_code']) ?>">
                                <?= htmlspecialchars($menu['menu_name']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- 右侧内容 -->
            <div class="content-area">
                <!-- 动态字段：从数据库加载（新三层结构：menus → dimensions → fields） -->
                <?php
                echo renderModuleFields('first_contact');
                ?>

                <!-- 下次跟进时间 -->
                <div class="field-row">
                    <div class="field-label">下次跟进时间</div>
                    <div>
                        <input type="datetime-local" name="next_follow_time" class="form-control form-control-sm" style="width:220px;">
                        <small class="text-muted">默认为明天</small>
                    </div>
                </div>

                <!-- 首通备注 -->
                <div class="field-row">
                    <div class="field-label">首通备注</div>
                    <textarea name="remark" class="form-control remark-box" placeholder="记录沟通要点..."></textarea>
                </div>

                <!-- 意向总结 -->
                <?php if ($intentSummary): ?>
                <div class="intent-box">
                    <h6>📊 意向总结</h6>
                    <p class="mb-0"><?= htmlspecialchars($intentSummary) ?></p>
                </div>
                <?php endif; ?>

                <!-- 底部按钮 -->
                <div class="bottom-actions">
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled>意向总结</button>
                    <button type="button" class="btn btn-outline-info btn-sm" disabled>复制为图片</button>
                    <button type="submit" class="btn btn-success btn-sm">保存记录</button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
layout_footer();
