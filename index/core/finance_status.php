<?php
/**
 * 财务状态服务
 * 集中管理合同和分期的状态计算逻辑
 */

class FinanceStatus
{
    /**
     * 获取分期状态标签
     * @param float|null $amountDue 应收金额
     * @param float|null $amountPaid 已收金额
     * @param string|null $dueDate 到期日
     * @param string|null $manualStatus 手动状态
     * @return string 状态标签
     */
    public static function getInstallmentLabel($amountDue, $amountPaid, $dueDate, $manualStatus): string
    {
        $due = (float)($amountDue ?? 0);
        $paid = (float)($amountPaid ?? 0);
        $unpaid = $due - $paid;
        $ms = trim((string)($manualStatus ?? ''));

        // 手动状态优先（支持已收分期改回待收/催款）
        if ($ms !== '') {
            return $ms;
        }

        if ($due > 0 && $unpaid <= 0.00001) {
            return '已收';
        }

        if ($paid > 0.00001 && $unpaid > 0.00001) {
            return '部分已收';
        }

        $dt = (string)($dueDate ?? '');
        if ($dt !== '' && strtotime($dt) !== false && strtotime($dt) < strtotime(date('Y-m-d'))) {
            return '逾期';
        }

        return '待收';
    }

    /**
     * 获取合同状态标签
     * @param string|null $status 合同状态
     * @param string|null $manualStatus 手动状态
     * @return string 状态标签
     */
    public static function getContractLabel($status, $manualStatus): string
    {
        $ms = trim((string)($manualStatus ?? ''));
        if ($ms !== '') {
            return $ms;
        }

        $s = (string)($status ?? '');
        if ($s === 'active') {
            return '未结清';
        }
        if ($s === 'void') {
            return '作废';
        }
        if ($s === 'closed') {
            return '已结清';
        }
        return '未结清';
    }

    /**
     * 获取分期状态徽章样式类
     * @param string $label 状态标签
     * @return string Bootstrap 徽章类名
     */
    public static function getInstallmentBadgeClass(string $label): string
    {
        switch ($label) {
            case '已收':
                return 'success';
            case '部分已收':
            case '催款':
                return 'warning';
            case '逾期':
                return 'danger';
            case '待收':
                return 'primary';
            default:
                return 'secondary';
        }
    }

    /**
     * 获取合同状态徽章样式类
     * @param string $label 状态标签
     * @return string Bootstrap 徽章类名
     */
    public static function getContractBadgeClass(string $label): string
    {
        switch ($label) {
            case '已结清':
                return 'success';
            case '作废':
                return 'danger';
            case '未结清':
            default:
                return 'primary';
        }
    }

    /**
     * 获取分期状态完整信息（标签+徽章类）
     * @param float|null $amountDue
     * @param float|null $amountPaid
     * @param string|null $dueDate
     * @param string|null $manualStatus
     * @return array ['label' => string, 'badge' => string]
     */
    public static function getInstallmentStatus($amountDue, $amountPaid, $dueDate, $manualStatus): array
    {
        $label = self::getInstallmentLabel($amountDue, $amountPaid, $dueDate, $manualStatus);
        return [
            'label' => $label,
            'badge' => self::getInstallmentBadgeClass($label),
        ];
    }

    /**
     * 获取合同状态完整信息（标签+徽章类）
     * @param string|null $status
     * @param string|null $manualStatus
     * @return array ['label' => string, 'badge' => string]
     */
    public static function getContractStatus($status, $manualStatus): array
    {
        $label = self::getContractLabel($status, $manualStatus);
        return [
            'label' => $label,
            'badge' => self::getContractBadgeClass($label),
        ];
    }
}
