-- 将"技术"角色名称改为"设计师"
UPDATE roles SET name = '设计师', description = '设计师，只能访问技术资源模块' WHERE code = 'tech';

-- 验证更新结果
SELECT code, name, description FROM roles WHERE code = 'tech';
