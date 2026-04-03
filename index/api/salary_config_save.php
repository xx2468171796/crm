<?php
/**
 * 保存工资组成配置
 */
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();

if (!canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_require();

$ruleId = (int)($_POST['rule_id'] ?? 0);
$componentsJson = $_POST['components'] ?? '';

if ($ruleId <= 0) {
    echo json_encode(['success' => false, 'message' => '规则ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$components = json_decode($componentsJson, true);
if (!is_array($components)) {
    echo json_encode(['success' => false, 'message' => '组成项数据格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证每个组成项
$maxComponents = 50;
if (count($components) > $maxComponents) {
    echo json_encode(['success' => false, 'message' => '组成项数量过多'], JSON_UNESCAPED_UNICODE);
    exit;
}

$seenCodes = [];
foreach ($components as $c) {
    $code = trim((string)($c['code'] ?? ''));
    $name = trim((string)($c['name'] ?? ''));

    if ($code === '' || $name === '') {
        echo json_encode(['success' => false, 'message' => '组成项必须有代码和名称'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) {
        echo json_encode(['success' => false, 'message' => '组成项代码格式不正确'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($seenCodes[$code])) {
        echo json_encode(['success' => false, 'message' => '组成项代码重复'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $seenCodes[$code] = true;

    if (!in_array($c['type'] ?? '', ['fixed', 'calculated', 'manual'])) {
        echo json_encode(['success' => false, 'message' => '组成项类型无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($c['op'] ?? '+', ['+', '-'])) {
        echo json_encode(['success' => false, 'message' => '操作符无效'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $config = json_encode(['components' => $components], JSON_UNESCAPED_UNICODE);
    
    Db::exec(
        "UPDATE commission_rule_sets SET salary_config = ? WHERE id = ?",
        [$config, $ruleId]
    );
    
    echo json_encode(['success' => true, 'message' => '保存成功'], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
