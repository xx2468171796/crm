-- 更新角色权限配置脚本
-- 为各角色配置 RBAC 权限代码
-- 执行前请备份 roles 表

-- 1. 销售角色 (sales) - 客户管理、财务查看、项目创建
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "customer_edit", "customer_edit_basic", "file_upload", "deal_manage", 
      "finance_view", "finance_view_own", "contract_view", "contract_create",
      "project_view", "project_create", "project_assign"]'
) WHERE code = 'sales';

-- 2. 财务角色 (finance) - 财务完整权限
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "finance_view", "finance_edit", "finance_status_edit", 
      "finance_dashboard", "finance_payment_summary", "finance_prepay",
      "contract_view", "contract_edit", "contract_create"]'
) WHERE code = 'finance';

-- 3. 技术角色 (tech) - 技术资源、项目查看
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "tech_resource_view", "tech_resource_edit", "tech_resource_delete",
      "project_view", "project_edit", "project_status_edit"]'
) WHERE code = 'tech';

-- 4. 部门主管 (dept_leader) - 部门数据、项目管理
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "customer_edit", "customer_transfer", "file_upload", "file_delete",
      "finance_view", "contract_view", "contract_create",
      "project_view", "project_create", "project_edit", "project_assign",
      "tech_resource_view", "tech_resource_edit",
      "dept_data_view", "dept_member_manage"]'
) WHERE code = 'dept_leader';

-- 5. 部门管理员 (dept_admin) - 部门数据管理
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "customer_edit", "file_upload",
      "finance_view", "contract_view",
      "project_view", "project_create",
      "dept_data_view"]'
) WHERE code = 'dept_admin';

-- 6. 客服角色 (service) - 客户查看、基础编辑
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "customer_edit_basic", "file_upload"]'
) WHERE code = 'service';

-- 7. 查看者角色 (viewer) - 只读权限
UPDATE roles SET permissions = JSON_MERGE_PRESERVE(
    COALESCE(permissions, '[]'),
    '["customer_view", "finance_view_own", "project_view"]'
) WHERE code = 'viewer';

-- 验证更新结果
SELECT code, name, permissions FROM roles WHERE code IN ('sales', 'finance', 'tech', 'dept_leader', 'dept_admin', 'service', 'viewer');
