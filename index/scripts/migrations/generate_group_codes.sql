-- 为现有客户生成群码
-- 使用当前日期 + 递增序号

SET @today = CURDATE();
SET @date_str = DATE_FORMAT(@today, '%Y%m%d');

-- 初始化序列
INSERT INTO group_code_sequence (date_key, last_seq) VALUES (@today, 0)
ON DUPLICATE KEY UPDATE id = id;

-- 更新每个没有群码的客户
UPDATE customers c
JOIN (
    SELECT id, @seq := @seq + 1 as seq_num
    FROM customers, (SELECT @seq := (SELECT COALESCE(last_seq, 0) FROM group_code_sequence WHERE date_key = CURDATE())) r
    WHERE group_code IS NULL AND deleted_at IS NULL
    ORDER BY id ASC
) t ON c.id = t.id
SET c.group_code = CONCAT('Q', DATE_FORMAT(CURDATE(), '%Y%m%d'), LPAD(t.seq_num, 2, '0'));

-- 更新序列计数
UPDATE group_code_sequence 
SET last_seq = (SELECT COUNT(*) FROM customers WHERE group_code LIKE CONCAT('Q', DATE_FORMAT(CURDATE(), '%Y%m%d'), '%'))
WHERE date_key = CURDATE();
