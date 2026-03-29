<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 桌面端 - 客户财务收款概览 API
 *
 * GET ?customer_id=123 - 获取客户所有合同及各期收款状态
 *
 * 返回每个合同下各期的百分比和收款状态，不返回具体金额
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/desktop_auth.php';
require_once __DIR__ . '/../core/finance_status.php';

$user = desktop_auth_require();

$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '客户ID无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取客户所有合同
    $contracts = Db::query("
        SELECT
            fc.id, fc.contract_no, fc.title, fc.net_amount,
            fc.status, fc.manual_status, fc.sign_date,
            u.realname as sales_name
        FROM finance_contracts fc
        LEFT JOIN users u ON fc.sales_user_id = u.id
        WHERE fc.customer_id = ?
        ORDER BY fc.sign_date DESC, fc.id DESC
    ", [$customerId]);

    if (empty($contracts)) {
        echo json_encode([
            'success' => true,
            'data' => ['contracts' => [], 'summary' => ['total_contracts' => 0, 'total_installments' => 0, 'collected_installments' => 0]]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contractIds = array_column($contracts, 'id');
    $placeholders = implode(',', array_fill(0, count($contractIds), '?'));

    // 一次性查询所有分期
    $installments = Db::query("
        SELECT
            fi.id, fi.contract_id, fi.installment_no, fi.due_date,
            fi.amount_due, fi.amount_paid, fi.status, fi.manual_status
        FROM finance_installments fi
        WHERE fi.contract_id IN ({$placeholders}) AND fi.deleted_at IS NULL
        ORDER BY fi.installment_no ASC
    ", $contractIds);

    // 按合同ID分组
    $installmentsByContract = [];
    foreach ($installments as $inst) {
        $installmentsByContract[$inst['contract_id']][] = $inst;
    }

    $totalInstallments = 0;
    $collectedInstallments = 0;
    $result = [];

    foreach ($contracts as $contract) {
        $cId = $contract['id'];
        $netAmount = (float)$contract['net_amount'];
        $contractStatus = FinanceStatus::getContractStatus($contract['status'], $contract['manual_status']);
        $insts = $installmentsByContract[$cId] ?? [];
        $totalInContract = count($insts);

        $installmentList = [];
        $collectedInContract = 0;

        foreach ($insts as $inst) {
            $amountDue = (float)$inst['amount_due'];
            $amountPaid = (float)$inst['amount_paid'];
            $statusInfo = FinanceStatus::getInstallmentStatus($amountDue, $amountPaid, $inst['due_date'], $inst['manual_status']);
            $isCollected = $statusInfo['label'] === '已收';

            // 计算该期占合同总额的百分比
            $percentage = $netAmount > 0 ? round($amountDue / $netAmount * 100) : 0;

            if ($isCollected) {
                $collectedInContract++;
            }

            $installmentList[] = [
                'no' => (int)$inst['installment_no'],
                'percentage' => $percentage,
                'status_label' => $statusInfo['label'],
                'status_badge' => $statusInfo['badge'],
                'due_date' => $inst['due_date'],
            ];
        }

        $totalInstallments += $totalInContract;
        $collectedInstallments += $collectedInContract;

        $result[] = [
            'contract_id' => (int)$cId,
            'contract_no' => $contract['contract_no'],
            'title' => $contract['title'],
            'sign_date' => $contract['sign_date'],
            'sales_name' => $contract['sales_name'],
            'status_label' => $contractStatus['label'],
            'status_badge' => $contractStatus['badge'],
            'total_installments' => $totalInContract,
            'collected_installments' => $collectedInContract,
            'installments' => $installmentList,
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'contracts' => $result,
            'summary' => [
                'total_contracts' => count($result),
                'total_installments' => $totalInstallments,
                'collected_installments' => $collectedInstallments,
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('[API] desktop_customer_finance 错误: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误'], JSON_UNESCAPED_UNICODE);
}
