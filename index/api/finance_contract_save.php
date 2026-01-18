<?php
require_once __DIR__ . '/../core/api_init.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

header('Content-Type: application/json; charset=utf-8');

auth_require();
$user = current_user();

if (!canOrAdmin(PermissionCode::CONTRACT_EDIT) && !canOrAdmin(PermissionCode::FINANCE_EDIT)) {
    echo json_encode(['success' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customerId = (int)($_POST['customer_id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$contractNo = trim((string)($_POST['contract_no'] ?? ''));
$salesUserId = (int)($_POST['sales_user_id'] ?? 0);
$signDate = trim((string)($_POST['sign_date'] ?? ''));

$grossAmount = (float)($_POST['gross_amount'] ?? 0);
$discountInCalc = (int)($_POST['discount_in_calc'] ?? 0);
$discountType = trim((string)($_POST['discount_type'] ?? ''));
$discountValue = $_POST['discount_value'] ?? null;
$discountNote = trim((string)($_POST['discount_note'] ?? ''));

$signerUserId = (int)($_POST['signer_user_id'] ?? 0);
$currency = trim((string)($_POST['currency'] ?? 'TWD'));
$unitPrice = isset($_POST['unit_price']) && $_POST['unit_price'] !== '' ? (float)$_POST['unit_price'] : null;

$installmentsJson = (string)($_POST['installments_json'] ?? '');
$ownerUserId = (int)($_POST['owner_user_id'] ?? 0);

if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误：customer_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($grossAmount <= 0) {
    echo json_encode(['success' => false, 'message' => '合同总价必须大于 0'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($salesUserId <= 0) {
    if (($user['role'] ?? '') === 'sales') {
        $salesUserId = (int)($user['id'] ?? 0);
    }
}

if ($salesUserId <= 0) {
    echo json_encode(['success' => false, 'message' => '请选择销售负责人'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 判断是否是该客户的首单合同
$existingContract = Db::queryOne(
    'SELECT id FROM finance_contracts WHERE customer_id = ? LIMIT 1',
    [$customerId]
);
$isFirstContract = $existingContract ? 0 : 1;

$instRows = json_decode($installmentsJson, true);
if (!is_array($instRows) || empty($instRows)) {
    echo json_encode(['success' => false, 'message' => '请至少填写 1 条分期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanInstallments = [];
foreach ($instRows as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }
    $dueDate = trim((string)($row['due_date'] ?? ''));
    $amountDue = (float)($row['amount_due'] ?? 0);

    if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        echo json_encode(['success' => false, 'message' => '分期到期日格式错误：第' . ((int)$idx + 1) . '行'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($amountDue <= 0) {
        echo json_encode(['success' => false, 'message' => '分期金额必须大于 0：第' . ((int)$idx + 1) . '行'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $collectorUserId = isset($row['collector_user_id']) ? (int)$row['collector_user_id'] : null;
    $paymentMethod = isset($row['payment_method']) ? trim((string)$row['payment_method']) : null;
    $instCurrency = isset($row['currency']) ? trim((string)$row['currency']) : 'TWD';
    
    $cleanInstallments[] = [
        'due_date' => $dueDate,
        'amount_due' => round($amountDue, 2),
        'collector_user_id' => $collectorUserId > 0 ? $collectorUserId : null,
        'payment_method' => $paymentMethod !== '' ? $paymentMethod : null,
        'currency' => $instCurrency,
    ];
}

if (empty($cleanInstallments)) {
    echo json_encode(['success' => false, 'message' => '请至少填写 1 条有效分期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$customer = Db::queryOne('SELECT id, name, owner_user_id FROM customers WHERE id = :id AND status = 1 AND deleted_at IS NULL LIMIT 1', ['id' => $customerId]);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => '客户不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 如果没有填写标题，自动生成：客户名称+序号
if ($title === '') {
    $customerName = $customer['name'] ?? '';
    $existingCount = (int)Db::queryOne('SELECT COUNT(*) as cnt FROM finance_contracts WHERE customer_id = ?', [$customerId])['cnt'];
    $title = $customerName . ($existingCount + 1);
}

if (($user['role'] ?? '') === 'sales') {
    if ((int)($customer['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) {
        echo json_encode(['success' => false, 'message' => '无权限：只能给自己名下客户创建订单'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($contractNo !== '') {
    $existingContract = Db::queryOne('SELECT id FROM finance_contracts WHERE contract_no = :no LIMIT 1', ['no' => $contractNo]);
    if ($existingContract) {
        echo json_encode(['success' => false, 'message' => '合同号已存在，请使用其他合同号'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($discountInCalc !== 0) {
    $discountInCalc = 1;
}

$discountType = $discountType !== '' ? $discountType : null;
if (!in_array($discountType, [null, 'amount', 'rate'], true)) {
    echo json_encode(['success' => false, 'message' => '折扣类型错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$discountValueFloat = null;
if ($discountType !== null) {
    $discountValueFloat = (float)$discountValue;
    if ($discountValueFloat <= 0) {
        echo json_encode(['success' => false, 'message' => '折扣值必须大于 0'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$netAmount = $grossAmount;
if ($discountInCalc === 1 && $discountType !== null && $discountValueFloat !== null) {
    if ($discountType === 'amount') {
        $netAmount = max(0.0, $grossAmount - $discountValueFloat);
    } elseif ($discountType === 'rate') {
        $rate = $discountValueFloat;
        if ($rate > 1 && $rate <= 100) {
            $rate = $rate / 100.0;
        }
        if ($rate <= 0 || $rate > 1) {
            echo json_encode(['success' => false, 'message' => '折扣比例必须在 (0,1] 或 (0,100]'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $netAmount = max(0.0, $grossAmount * $rate);
    }
}

$netAmount = round($netAmount, 2);

// 计算并锁定当月档位比例（仅首单需要）
$lockedCommissionRate = null;
if ($isFirstContract === 1) {
    try {
        $rule = Db::queryOne("SELECT * FROM commission_rules WHERE status = 'active' LIMIT 1");
    } catch (Exception $e) {
        $rule = null;
    }
    if ($rule) {
        $ruleType = $rule['rule_type'] ?? 'tiered';
        if ($ruleType === 'fixed') {
            $lockedCommissionRate = (float)($rule['fixed_rate'] ?? 0);
        } else {
            $signMonth = $signDate ? date('Y-m', strtotime($signDate)) : date('Y-m');
            $monthStart = strtotime($signMonth . '-01');
            $monthEnd = strtotime(date('Y-m-t', $monthStart));
            $ruleCurrency = $rule['currency'] ?? 'CNY';
            
            $currencyRates = [];
            $ratesRows = Db::query("SELECT from_currency, to_currency, rate FROM currency_rates WHERE status = 'active'");
            foreach ($ratesRows as $r) {
                $currencyRates[$r['from_currency'] . '_' . $r['to_currency']] = (float)$r['rate'];
            }
            
            $monthContracts = Db::query(
                "SELECT net_amount, currency FROM finance_contracts WHERE sales_user_id = ? AND sign_date >= ? AND sign_date <= ?",
                [$salesUserId, date('Y-m-d', $monthStart), date('Y-m-d', $monthEnd)]
            );
            $tierBase = 0;
            foreach ($monthContracts as $mc) {
                $mcAmount = (float)($mc['net_amount'] ?? 0);
                $mcCurrency = $mc['currency'] ?? 'TWD';
                if ($mcCurrency === $ruleCurrency) {
                    $tierBase += $mcAmount;
                } else {
                    $key = $mcCurrency . '_' . $ruleCurrency;
                    $rateVal = $currencyRates[$key] ?? 1.0;
                    $tierBase += $mcAmount * $rateVal;
                }
            }
            // 加上当前合同
            if ($currency === $ruleCurrency) {
                $tierBase += $netAmount;
            } else {
                $key = $currency . '_' . $ruleCurrency;
                $rateVal = $currencyRates[$key] ?? 1.0;
                $tierBase += $netAmount * $rateVal;
            }
            
            $tiers = Db::query("SELECT * FROM commission_tiers WHERE rule_id = ? ORDER BY tier_from ASC", [$rule['id']]);
            foreach ($tiers as $t) {
                $from = (float)($t['tier_from'] ?? 0);
                $to = $t['tier_to'] !== null ? (float)$t['tier_to'] : PHP_FLOAT_MAX;
                if ($tierBase >= $from && $tierBase < $to) {
                    $lockedCommissionRate = (float)($t['rate'] ?? 0);
                    break;
                }
            }
        }
    }
}

$sumInstallments = 0.0;
foreach ($cleanInstallments as $it) {
    $sumInstallments += (float)$it['amount_due'];
}
$sumInstallments = round($sumInstallments, 2);

if (abs($sumInstallments - $netAmount) > 0.01) {
    echo json_encode(['success' => false, 'message' => '分期合计(' . number_format($sumInstallments, 2) . ') 必须等于 折后金额(' . number_format($netAmount, 2) . ')'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
$uid = (int)($user['id'] ?? 0);

try {
    Db::beginTransaction();

    Db::execute('INSERT INTO finance_contracts (
            customer_id, contract_no, title, sales_user_id, signer_user_id, sign_date,
            gross_amount, discount_in_calc, discount_type, discount_value, discount_note, net_amount, unit_price, currency,
            is_first_contract, locked_commission_rate, status, create_time, update_time, create_user_id, update_user_id
        ) VALUES (
            :customer_id, :contract_no, :title, :sales_user_id, :signer_user_id, :sign_date,
            :gross_amount, :discount_in_calc, :discount_type, :discount_value, :discount_note, :net_amount, :unit_price, :currency,
            :is_first_contract, :locked_commission_rate, "active", :create_time, :update_time, :create_user_id, :update_user_id
        )', [
        'customer_id' => $customerId,
        'contract_no' => $contractNo !== '' ? $contractNo : null,
        'title' => $title !== '' ? $title : null,
        'sales_user_id' => $salesUserId,
        'signer_user_id' => $signerUserId > 0 ? $signerUserId : null,
        'sign_date' => $signDate !== '' ? $signDate : null,
        'gross_amount' => $grossAmount,
        'discount_in_calc' => $discountInCalc,
        'discount_type' => $discountType,
        'discount_value' => $discountValueFloat,
        'discount_note' => $discountNote !== '' ? $discountNote : null,
        'net_amount' => $netAmount,
        'unit_price' => $unitPrice,
        'currency' => $currency,
        'is_first_contract' => $isFirstContract,
        'locked_commission_rate' => $lockedCommissionRate,
        'create_time' => $now,
        'update_time' => $now,
        'create_user_id' => $uid,
        'update_user_id' => $uid,
    ]);

    $contractId = (int)Db::lastInsertId();
    if ($contractId <= 0) {
        throw new Exception('创建合同失败');
    }

    if ($contractNo === '') {
        $contractNo = 'CON-' . date('Y') . '-' . str_pad((string)$contractId, 6, '0', STR_PAD_LEFT);
        Db::execute('UPDATE finance_contracts SET contract_no = :no WHERE id = :id', ['no' => $contractNo, 'id' => $contractId]);
    }

    // 管理员可修改客户归属人
    if ($ownerUserId > 0 && in_array(($user['role'] ?? ''), ['admin', 'system_admin', 'super_admin'], true)) {
        Db::execute('UPDATE customers SET owner_user_id = :owner_id, update_time = :now WHERE id = :id', [
            'owner_id' => $ownerUserId,
            'now' => $now,
            'id' => $customerId,
        ]);
    }

    $instNo = 1;
    foreach ($cleanInstallments as $it) {
        Db::execute('INSERT INTO finance_installments (
                contract_id, customer_id, installment_no,
                due_date, amount_due, amount_paid,
                collector_user_id, payment_method, currency,
                status, create_time, update_time, create_user_id, update_user_id
            ) VALUES (
                :contract_id, :customer_id, :installment_no,
                :due_date, :amount_due, 0.00,
                :collector_user_id, :payment_method, :currency,
                "pending", :create_time, :update_time, :create_user_id, :update_user_id
            )', [
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'installment_no' => $instNo,
            'due_date' => $it['due_date'],
            'amount_due' => $it['amount_due'],
            'collector_user_id' => $it['collector_user_id'] ?? null,
            'payment_method' => $it['payment_method'] ?? null,
            'currency' => $it['currency'] ?? 'TWD',
            'create_time' => $now,
            'update_time' => $now,
            'create_user_id' => $uid,
            'update_user_id' => $uid,
        ]);
        $instNo++;
    }

    Db::commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'contract_id' => $contractId,
            'contract_no' => $contractNo,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    Db::rollback();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
