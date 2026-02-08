# Change: 修复审批工作流 Bug 与代码优化

## Why

桌面端文件审批工作流存在多个严重 Bug，包括排序功能失效、权限检查缺失导致非管理员可见审批按钮、SQL 查询产生重复行、前后端角色定义不一致等。这些问题直接影响用户操作正确性和系统安全性，需要系统性修复。

## What Changes

### 严重 Bug 修复（P0）
- 修复 `ApprovalTable` 排序功能无效：排序 state 存在但从未应用到渲染数据
- 修复 `ProjectDetailPage` 列表视图审批按钮缺少 `isManager` 权限检查：任何角色都能看到通过/驳回按钮
- 修复 `ProjectDetailPage` 审批状态和操作按钮仅在"作品文件"分类显示，"客户文件"和"模型文件"不显示
- 修复 `desktop_approval.php` SQL 查询因 `LEFT JOIN project_tech_assignments` 产生重复行（缺少 `GROUP BY d.id`）
- **BREAKING**: 统一前后端 `design_manager` 角色定义——后端 `desktop_approval.php` 的 `$isManager` 添加 `design_manager` 角色

### 安全与逻辑修复（P1）
- 修复 `http.ts` GET 请求将 Token 暴露在 URL 查询参数中，统一使用 `Authorization: Bearer` Header
- 修复 `desktop_approval.php` 审批操作不检查文件当前状态（可重复通过/驳回），添加 `AND approval_status = 'pending'` 条件
- 修复 `desktop_file_manage.php` 批量删除缺少事务包裹
- 修复 `desktop_file_manage.php` 重命名操作非原子性（copy → delete → update 无事务保护）

### 其他修复（P2）
- 修复 `loadEvaluation` 缺少 `Authorization` Header
- 修复 `ProjectDetailPage` 批量驳回使用原生 `prompt()` 在 Tauri 环境下可能失效，改用自定义弹窗
- 修复 `ApprovalPage` "我的文件" Tab 切换不重置 status filter

### 代码质量优化（P3）
- 统一 `formatFileSize` 函数（5 处重复定义 → 统一使用 `utils.ts` 导出）
- 提取 `desktop_approval.php` 统计查询条件构建为公共函数（消除 110 行重复代码）
- 清理 `ProjectDetailPage.tsx` 中 50+ 处生产环境调试 `console.log`
- 修复 `desktop_approval.php` `handleStats` 中未使用预处理语句的 SQL

## Capabilities

### New Capabilities
- `desktop-approval`: 桌面端文件审批工作流的完整要求定义，覆盖权限检查、状态转换、批量操作、排序展示等

### Modified Capabilities
（当前无已存在的 spec，所有能力定义为新建）

## Impact

- **前端代码**:
  - `desktop/src/components/ApprovalTable.tsx` — 排序逻辑修复
  - `desktop/src/pages/ApprovalPage.tsx` — Tab 切换 filter 重置
  - `desktop/src/pages/ProjectDetailPage.tsx` — 权限检查、审批状态显示、prompt 替换、console.log 清理
  - `desktop/src/lib/http.ts` — GET 请求 Token 传递方式
  - `desktop/src/lib/utils.ts` — formatFileSize 统一导出
  - `desktop/src/components/FileTree.tsx` — 移除重复 formatFileSize
- **后端代码**:
  - `index/api/desktop_approval.php` — SQL 修复、角色统一、状态检查、统计代码重构
  - `index/api/desktop_file_manage.php` — 事务包裹、重命名原子性
- **API 契约**: 审批操作添加文件状态前置检查（`approval_status = 'pending'`），对已通过/已驳回文件的重复操作将返回错误
