<?php

/**
 * 群码生成与管理服务
 * 
 * 群码格式：QYYYYMMDDNN
 * - Q：固定前缀
 * - YYYYMMDD：日期
 * - NN：当日序号（01-99，超过99则继续递增）
 * 
 * 特性：
 * - 并发安全：使用数据库原子操作确保序号不重复
 * - 不可变：群码生成后不可修改
 */

require_once __DIR__ . '/../core/db.php';

class GroupCodeService
{
    /**
     * 生成新的群码（并发安全）
     * 
     * @param string|null $date 指定日期（YYYY-MM-DD），默认当天
     * @return string 生成的群码
     * @throws Exception 生成失败时抛出异常
     */
    public static function generate(?string $date = null): string
    {
        $dateKey = $date ?? date('Y-m-d');
        
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) {
            throw new InvalidArgumentException('日期格式无效，应为 YYYY-MM-DD');
        }
        
        $pdo = Db::pdo();
        
        // 使用事务确保原子性
        $pdo->beginTransaction();
        
        try {
            // 尝试插入新日期记录，如果已存在则忽略
            $pdo->prepare("
                INSERT INTO group_code_sequence (date_key, last_seq)
                VALUES (?, 0)
                ON DUPLICATE KEY UPDATE id = id
            ")->execute([$dateKey]);
            
            // 原子递增序号并锁定行
            $pdo->prepare("
                UPDATE group_code_sequence 
                SET last_seq = last_seq + 1 
                WHERE date_key = ?
            ")->execute([$dateKey]);
            
            // 获取新序号
            $stmt = $pdo->prepare("SELECT last_seq FROM group_code_sequence WHERE date_key = ?");
            $stmt->execute([$dateKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                throw new RuntimeException('无法获取群码序号');
            }
            
            $seqNum = (int)$row['last_seq'];
            
            $pdo->commit();
            
            // 生成群码：QYYYYMMDDNN
            $datePart = str_replace('-', '', $dateKey);
            $seqPart = str_pad($seqNum, 2, '0', STR_PAD_LEFT);
            
            return 'Q' . $datePart . $seqPart;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new RuntimeException('群码生成失败: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * 验证群码格式是否合法
     * 
     * @param string $groupCode 群码
     * @return bool 是否合法
     */
    public static function isValid(string $groupCode): bool
    {
        // 格式：Q + 8位日期 + 2位及以上序号
        return preg_match('/^Q\d{8}\d{2,}$/', $groupCode) === 1;
    }
    
    /**
     * 解析群码
     * 
     * @param string $groupCode 群码
     * @return array|null ['date' => 'YYYY-MM-DD', 'seq' => int] 或 null（无效时）
     */
    public static function parse(string $groupCode): ?array
    {
        if (!self::isValid($groupCode)) {
            return null;
        }
        
        $datePart = substr($groupCode, 1, 8);
        $seqPart = substr($groupCode, 9);
        
        $year = substr($datePart, 0, 4);
        $month = substr($datePart, 4, 2);
        $day = substr($datePart, 6, 2);
        
        return [
            'date' => "{$year}-{$month}-{$day}",
            'seq' => (int)$seqPart,
        ];
    }
    
    /**
     * 检查群码是否已存在
     * 
     * @param string $groupCode 群码
     * @return bool 是否存在
     */
    public static function exists(string $groupCode): bool
    {
        $row = Db::queryOne(
            "SELECT id FROM customers WHERE group_code = ? LIMIT 1",
            [$groupCode]
        );
        return $row !== null;
    }
    
    /**
     * 为客户分配群码（如果尚未分配）
     * 
     * @param int $customerId 客户 ID
     * @return string 群码（已有则返回现有，否则生成新的）
     * @throws Exception 客户不存在或分配失败
     */
    public static function ensureForCustomer(int $customerId): string
    {
        $customer = Db::queryOne(
            "SELECT id, group_code, create_time FROM customers WHERE id = ? AND deleted_at IS NULL",
            [$customerId]
        );
        
        if (!$customer) {
            throw new InvalidArgumentException('客户不存在');
        }
        
        // 如果已有群码，直接返回
        if (!empty($customer['group_code'])) {
            return $customer['group_code'];
        }
        
        // 生成新群码（使用客户创建日期或当前日期）
        $date = $customer['create_time'] ? date('Y-m-d', $customer['create_time']) : null;
        $groupCode = self::generate($date);
        
        // 更新客户记录
        Db::execute(
            "UPDATE customers SET group_code = ? WHERE id = ? AND group_code IS NULL",
            [$groupCode, $customerId]
        );
        
        // 再次查询确认（防止并发情况下被其他进程先更新）
        $updated = Db::queryOne(
            "SELECT group_code FROM customers WHERE id = ?",
            [$customerId]
        );
        
        return $updated['group_code'] ?? $groupCode;
    }
    
    /**
     * 根据群码获取客户 ID
     * 
     * @param string $groupCode 群码
     * @return int|null 客户 ID 或 null
     */
    public static function getCustomerId(string $groupCode): ?int
    {
        $row = Db::queryOne(
            "SELECT id FROM customers WHERE group_code = ? AND deleted_at IS NULL LIMIT 1",
            [$groupCode]
        );
        return $row ? (int)$row['id'] : null;
    }
}
