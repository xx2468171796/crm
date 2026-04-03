-- 添加门户相关权限到 permissions 表

INSERT INTO permissions (code, name, module, description, sort_order) VALUES
('portal_view', '查看客户门户', 'portal', '可以打开客户门户链接', 1),
('portal_copy_link', '复制门户链接', 'portal', '可以复制门户访问链接', 2),
('portal_view_password', '查看门户密码', 'portal', '可以查看门户访问密码', 3),
('portal_edit_password', '修改门户密码', 'portal', '可以修改门户访问密码', 4)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
