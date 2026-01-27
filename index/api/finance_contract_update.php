<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

// 销售可以修改自己客户的合同，其他角色需要CONTRACT_EDIT或FINANCE_EDIT权限
$isSales = ($user['role'] ?? '') === 'sales';
if (!$isSales && !canOrAdmin(PermissionCode::CONTRACT_EDIT) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contractId = (int)($_POST['contract_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$contractNo = trim((string)($_POST['contract_no'] ?? ''));
$salesUserId = (int)($_POST['sales_user_id'] ?? 0);
$ownerUserId = (int)($_POST['owner_user_id'] ?? 0);
$signDate = trim((string)($_POST['sign_date'] ?? ''));

$grossAmount = (float)($_POST['gross_amount'] ?? 0);
$discountInCalc = (int)($_POST['discount_in_calc'] ?? 0);
$discountType = trim((string)($_POST['discount_type'] ?? ''));
$discountValue = $_POST['discount_value'] ?? null;
$discountNote = trim((string)($_POST['discount_note'] ?? ''));

$currency = trim((string)($_POST['currency'] ?? 'TWD'));
$unitPrice = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? (float)$_POST['unit_price'] : null;

if ($contractId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：contract_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contract = Db::queryOne('SELECT fc.*, c.owner_user_id FROM finance_contracts fc LEFT JOIN customers c ON fc.customer_id = c.id WHERE fc.id = ? LIMIT 1', [$contractId]);
if (!$contract) {
    echo json_encode(['success' => false, 'message' => '合同不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 销售只能修改自己客户的合同
if ($isSales && (int)($contract['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
    echo json_encode(['success' => false, 'message' => '无权限：只能修改自己名下客户的合同'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($grossAmount <= 0) {
    echo json_encode(['success' => false, 'message' => '合同总价必须大于 0'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($discountInCalc !== 0) {
    $discountInCalc = 1;
}

$discountValueFloat = null;
if ($discountInCalc === 1) {
    if ($discountType === '') {
        $discountType = 'amount';
    }
    if ($discountValue !== null && $discountValue !== '') {
        $discountValueFloat = (float)$discountValue;
    }
}

$netAmount = $grossAmount;
if ($discountInCalc === 1 && $discountType !== null && $discountValueFloat !== null) {
    if ($discountType === 'amount') {
        $netAmount = max(0.0, $grossAmount - $discountValueFloat);
    } elseif ($discountType === 'rate') {
        $rate = $discountValueFloat;
        if ($rate > 1 && $rate <= 100) {
            $rate = $rate / 100;
        }
        if ($rate <= 0 || $rate > 1) {
            echo json_encode(['success' => false, 'message' => '折扣比例必须在 (0,1] 或 (0,100]'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $netAmount = max(0.0, $grossAmount * $rate);
    }
}

$netAmount = round($netAmount, 2);

$now = time();
$uid = (int)($user['id'] ?? 0);

try {
    Db::beginTransaction();

    Db::execute('UPDATE finance_contracts SET
            contract_no = :contract_no,
            title = :title,
            sales_user_id = :sales_user_id,
            sign_date = :sign_date,
            gross_amount = :gross_amount,
            discount_in_calc = :discount_in_calc,
            discount_type = :discount_type,
            discount_value = :discount_value,
            discount_note = :discount_note,
            net_amount = :net_amount,
            unit_price = :unit_price,
            currency = :currency,
            update_time = :update_time,
            update_user_id = :update_user_id
        WHERE id = :id', [
        'contract_no' => $contractNo !== '' ? $contractNo : null,
        'title' => $title !== '' ? $title : null,
        'sales_user_id' => $salesUserId > 0 ? $salesUserId : $contract['sales_user_id'],
        'sign_date' => $signDate !== '' ? $signDate : null,
        'gross_amount' => $grossAmount,
        'discount_in_calc' => $discountInCalc,
        'discount_type' => $discountType !== '' ? $discountType : null,
        'discount_value' => $discountValueFloat,
        'discount_note' => $discountNote !== '' ? $discountNote : null,
        'net_amount' => $netAmount,
        'unit_price' => $unitPrice,
        'currency' => $currency,
        'update_time' => $now,
        'update_user_id' => $uid,
        'id' => $contractId,
    ]);

    // 更新客户归属人
    if ($ownerUserId > 0) {
        Db::execute('UPDATE customers SET owner_user_id = :owner_user_id, update_time = :update_time WHERE id = :customer_id', [
            'owner_user_id' => $ownerUserId,
            'update_time' => $now,
            'customer_id' => $contract['customer_id'],
        ]);
    }

    Db::commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'contract_id' => $contractId,
            'contract_no' => $contractNo !== '' ? $contractNo : $contract['contract_no'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    Db::rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
