# Project Context

## Purpose
CRM（客户关系管理）系统，用于管理客户信息、销售流程、合同财务、跟进记录等业务。支持多角色权限管理，包括管理员、销售、财务等角色。

## Tech Stack
- **后端**: PHP 7.4+（原生 PHP，无框架）
- **数据库**: MySQL 8.0（通过 PDO 连接）
- **前端**: HTML5, Bootstrap 5, JavaScript（原生）
- **图标**: Bootstrap Icons, Lucide Icons
- **部署**: 1Panel 面板管理

## Project Conventions

### Code Style
- PHP 文件使用 `<?php` 开头，不使用短标签
- 使用 PDO 预处理语句防止 SQL 注入
- 文件命名：小写下划线分隔（如 `finance_contract_create.php`）
- API 返回 JSON 格式：`{ "success": bool, "message": string, "data": mixed }`
- 前端使用 Bootstrap 5 响应式布局

### Architecture Patterns
- **MVC-like 结构**:
  - `index/public/` - 前端页面（视图+控制器）
  - `index/api/` - API 接口
  - `index/core/` - 核心功能（db.php, auth.php, rbac.php, layout.php）
- **RBAC 权限系统**: 
  - `RoleCode` 定义角色常量
  - `PermissionCode` 定义权限常量
  - `isAdmin()`, `can()`, `canOrAdmin()` 权限检查函数
- **统一布局**: `layout_header()` 和 `layout_footer()` 函数

### Testing Strategy
- 手动测试为主
- 通过浏览器访问页面验证功能
- API 可通过 curl 或 Postman 测试

### Git Workflow
- 直接在生产环境修改（谨慎操作）
- 重要变更前备份相关文件

## Domain Context
- **客户管理**: 客户信息、联系人、跟进记录
- **合同财务**: 合同创建、分期付款、收款记录
- **权限角色**:
  - `super_admin` / `admin` - 系统管理员
  - `sales` - 销售人员
  - `finance` - 财务人员
  - `dept_leader` / `dept_admin` - 部门管理
- **字段管理**: 动态字段（维度）、选项管理、客户筛选字段

## Important Constraints
- 数据库连接信息在 `index/core/db.php` 中配置
- 权限检查必须使用 `core/rbac.php` 中的函数
- 所有页面需要登录验证（`auth_require()` 或 `is_logged_in()`）
- 敏感操作需要管理员权限验证

## External Dependencies
- **数据库服务器**: MySQL 8.0
- **Web 服务器**: Nginx（通过 1Panel 管理）
- **CDN 资源**:
  - Bootstrap 5 CSS/JS
  - Bootstrap Icons
  - Lucide Icons
