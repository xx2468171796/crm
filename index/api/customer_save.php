<?php
require_once __DIR__ . '/../core/api_init.php';
// 保存客户信息（新增或更新）

// 开启错误报告
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../core/field_renderer.php';
require_once __DIR__ . '/../core/migrations.php';
require_once __DIR__ . '/../services/GroupCodeService.php';

// 确保数据库字段存在
ensureCustomerGroupField();

// 检查是否是外部访问（通过密码验证的）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$customerId = intval($_POST['customer_id'] ?? 0);
$isNew = $customerId === 0;
$isExternalEditable = false;

if ($customerId > 0 && isset($_SESSION['share_verified_' . $customerId]) && isset($_SESSION['share_editable_' . $customerId])) {
    // 外部访问但有编辑权限（输入了密码）
    $isExternalEditable = true;
    // 创建一个虚拟用户对象
    $user = [
        'id' => 0,
        'username' => 'external',
        'role' => 'external',
        'department_id' => null
    ];
} else {
    // 内部用户需要登录
    $user = current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => '请先登录',
            'redirect' => '/login.php'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 基本信息
$name     = trim($_POST['name'] ?? '');
$mobile   = trim($_POST['mobile'] ?? '');
$customerGroup = trim($_POST['customer_group'] ?? '');
$gender   = trim($_POST['gender'] ?? '');
$age      = intval($_POST['age'] ?? 0) ?: null;
$customId = trim($_POST['custom_id'] ?? '');
$activityTag = trim($_POST['activity_tag'] ?? '');
$groupCode = trim($_POST['group_code'] ?? '') ?: null;
$groupName = trim($_POST['group_name'] ?? '') ?: null;
$alias = trim($_POST['alias'] ?? '') ?: null;

// 首通信息（兼容新三层结构字段）
$fieldValues      = processFieldValues('first_contact', $_POST);

$identity         = trim($fieldValues['identity'] ?? ($_POST['identity'] ?? ''));
$identityCustom   = trim($_POST['identity_custom'] ?? '');
$demandTimeType   = trim($fieldValues['customer_demand'] ?? $fieldValues['demand_time_type'] ?? ($_POST['demand_time_type'] ?? ''));
$demandCustom     = trim($_POST['demand_custom'] ?? '');

$keyQuestions     = $fieldValues['key_questions'] ?? '';
if ($keyQuestions === '' && isset($_POST['key_questions'])) {
    $keyQuestions = implode('、', (array)$_POST['key_questions']);
}

$keyMessages      = $fieldValues['key_messages'] ?? '';
$keyMessagesCustom = trim($_POST['key_messages_custom'] ?? '');
if ($keyMessages === '' && isset($_POST['key_messages'])) {
    $keyMessages = implode('、', (array)$_POST['key_messages']);
}

$materialsToSend  = $fieldValues['materials_to_send'] ?? '';
$materialsCustom  = trim($_POST['materials_custom'] ?? '');
if ($materialsToSend === '' && isset($_POST['materials_to_send'])) {
    $materialsToSend = implode('、', (array)$_POST['materials_to_send']);
}

$helpers          = $fieldValues['helpers'] ?? '';
$helpersCustom    = trim($_POST['helpers_custom'] ?? '');
if ($helpers === '' && isset($_POST['helpers'])) {
    $helpers = implode('、', (array)$_POST['helpers']);
}

$nextFollowTime   = trim($_POST['next_follow_time'] ?? '');
$remark           = trim($_POST['remark'] ?? '');

// [TRACE] 调试首通备注
error_log('[customer_save] remark=' . substr($remark, 0, 100));

// 处理自定义字段
if ($identity === '自定义' && $identityCustom !== '') {
    $identity = $identityCustom;
}
if ($demandTimeType === '自定义' && $demandCustom !== '') {
    $demandTimeType = $demandCustom;
}
if ($keyMessagesCustom !== '') {
    $keyMessages .= ($keyMessages ? '、' : '') . $keyMessagesCustom;
}
if ($materialsCustom !== '') {
    $materialsToSend .= ($materialsToSend ? '、' : '') . $materialsCustom;
}
if ($helpersCustom !== '') {
    $helpers .= ($helpers ? '、' : '') . $helpersCustom;
}

if ($name === '') {
    echo json_encode([
        'success' => false,
        'message' => '请填写客户姓名'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$now   = time();
$uid   = $user['id'] ?? 0;
$dept  = $user['department_id'] ?? null;

// 简单意向判断
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
    if ($isNew) {
        // 新增客户
        Db::execute('INSERT INTO customers
            (customer_code, custom_id, name, alias, mobile, customer_group, group_name, gender, age, identity, demand_time_type, activity_tag,
             intent_level, intent_score, intent_summary,
             owner_user_id, department_id, status,
             create_time, update_time, create_user_id, update_user_id)
             VALUES
            (:code, :custom_id, :name, :alias, :mobile, :customer_group, :group_name, :gender, :age, :identity, :demand_time_type, :activity_tag,
             :intent_level, :intent_score, :intent_summary,
             :owner_user_id, :department_id, 1,
             :create_time, :update_time, :create_user_id, :update_user_id)', [
            'code'            => '',
            'custom_id'       => $customId,
            'name'            => $name,
            'alias'           => $alias,
            'mobile'          => $mobile,
            'customer_group'  => $customerGroup !== '' ? $customerGroup : null,
            'group_name'      => $groupName,
            'gender'          => $gender,
            'age'             => $age,
            'identity'        => $identity,
            'demand_time_type'=> $demandTimeType,
            'activity_tag'    => $activityTag !== '' ? $activityTag : null,
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

        // 生成客户系统ID（从100000开始）
        $sequenceNumber = 100000 + $customerId;
        $customerCode = 'CUST-' . date('Y') . '-' . str_pad((string)$sequenceNumber, 6, '0', STR_PAD_LEFT);
        Db::execute('UPDATE customers SET customer_code = :code WHERE id = :id', [
            'code' => $customerCode,
            'id'   => $customerId,
        ]);
        
        // 生成群码（QYYYYMMDDNN，不可变唯一标识）
        try {
            $groupCode = GroupCodeService::ensureForCustomer($customerId);
        } catch (Exception $e) {
            error_log('[SYNC_DEBUG] 群码生成失败: ' . $e->getMessage());
        }

        // 插入首通记录
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
        
        // 获取首通记录ID
        $firstContactId = Db::lastInsertId();
        
        // 保存新三层结构的动态字段值
        saveDimensionFieldValues('first_contact', $firstContactId, $fieldValues, $now);

        // 自动生成分享链接
        $token = bin2hex(random_bytes(32)); // 64位随机token
        $shareUrl = '';
        
        try {
            // 先检查是否已存在该客户的分享链接
            $existingLink = Db::queryOne('SELECT id FROM customer_links WHERE customer_id = :cid', ['cid' => $customerId]);
            
            if ($existingLink) {
                // 如果已存在，则更新token
                $result = Db::execute('UPDATE customer_links SET 
                    token = :token, 
                    enabled = 1, 
                    updated_at = :now 
                    WHERE customer_id = :cid', [
                    'token' => $token,
                    'now' => $now,
                    'cid' => $customerId
                ]);
                error_log("更新分享链接: customer_id={$customerId}, token={$token}, result={$result}");
            } else {
                // 如果不存在，则插入新记录
                $result = Db::execute('INSERT INTO customer_links 
                    (customer_id, token, enabled, created_at, updated_at) 
                    VALUES 
                    (:cid, :token, 1, :created_at, :updated_at)', [
                    'cid' => $customerId,
                    'token' => $token,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                error_log("插入分享链接: customer_id={$customerId}, token={$token}, result={$result}");
            }
            
            // 生成分享链接 - 使用客户编号（customer_code）
            $shareUrl = BASE_URL . '/share.php?code=' . $customerCode;
            error_log("生成分享链接成功: {$shareUrl}");
            
        } catch (Exception $e) {
            // 记录错误日志
            error_log('生成分享链接失败: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        }
        
        echo json_encode([
            'success' => true,
            'message' => '客户创建成功！<br>📋 客户链接已复制到剪贴板',
            'redirect' => '/index.php?page=customer_detail&id=' . $customerId . '#tab-first_contact',
            'shareUrl' => $shareUrl,
            'copyLink' => true
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        // 更新客户 (group_code不可修改，group_name可修改)
        Db::execute('UPDATE customers SET
            custom_id = :custom_id,
            name = :name,
            alias = :alias,
            mobile = :mobile,
            customer_group = :customer_group,
            group_name = :group_name,
            gender = :gender,
            age = :age,
            identity = :identity,
            demand_time_type = :demand_time_type,
            activity_tag = :activity_tag,
            intent_level = :intent_level,
            intent_score = :intent_score,
            intent_summary = :intent_summary,
            update_time = :update_time,
            update_user_id = :update_user_id
            WHERE id = :id', [
            'custom_id'       => $customId,
            'name'            => $name,
            'alias'           => $alias,
            'mobile'          => $mobile,
            'customer_group'  => $customerGroup !== '' ? $customerGroup : null,
            'group_name'      => $groupName,
            'gender'          => $gender,
            'age'             => $age,
            'identity'        => $identity,
            'demand_time_type'=> $demandTimeType,
            'activity_tag'    => $activityTag !== '' ? $activityTag : null,
            'intent_level'    => $intentLevel,
            'intent_score'    => $intentScore,
            'intent_summary'  => $intentSummary,
            'update_time'     => $now,
            'update_user_id'  => $uid,
            'id'              => $customerId,
        ]);

        // 更新首通记录
        $nextFollowTimestamp = $nextFollowTime !== '' ? strtotime($nextFollowTime) : null;
        
        $existing = Db::queryOne('SELECT id FROM first_contact WHERE customer_id = :id', ['id' => $customerId]);
        $firstContactId = 0;
        
        if ($existing) {
            $firstContactId = $existing['id'];
            Db::execute('UPDATE first_contact SET
                identity = :identity,
                demand_time_type = :demand_time_type,
                key_questions = :key_questions,
                key_messages = :key_messages,
                materials_to_send = :materials_to_send,
                helpers = :helpers,
                next_follow_time = :next_follow_time,
                remark = :remark,
                update_time = :update_time,
                update_user_id = :update_user_id
                WHERE customer_id = :customer_id', [
                'identity'          => $identity,
                'demand_time_type'  => $demandTimeType,
                'key_questions'     => $keyQuestions,
                'key_messages'      => $keyMessages,
                'materials_to_send' => $materialsToSend,
                'helpers'           => $helpers,
                'next_follow_time'  => $nextFollowTimestamp,
                'remark'            => $remark,
                'update_time'       => $now,
                'update_user_id'    => $uid,
                'customer_id'       => $customerId,
            ]);
        } else {
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
            $firstContactId = Db::lastInsertId();
        }
        
        // 保存新三层结构的动态字段值
        saveDimensionFieldValues('first_contact', $firstContactId, $fieldValues, $now);

        echo json_encode([
            'success' => true,
            'message' => '客户更新成功！',
            'redirect' => '/index.php?page=customer_detail&id=' . $customerId . '#tab-first_contact'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Exception $e) {
    error_log('Customer save error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => '保存失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
