## 1. P0 严重 Bug 修复

- [x] 1.1 修复 `ApprovalTable.tsx` 排序功能：在 `data.map()` 前对数据按 `sortKey` / `sortOrder` 排序，支持嵌套字段（`project.name`、`uploader.name`）
- [x] 1.2 修复 `ProjectDetailPage.tsx` 列表视图审批按钮权限：将 `categoryName === '作品文件'` 硬编码条件移除，添加 `isManager` 权限检查包裹审批按钮
- [x] 1.3 修复 `ProjectDetailPage.tsx` 审批状态显示：所有文件分类（客户文件、作品文件、模型文件）统一展示审批状态徽章和操作按钮
- [x] 1.4 修复 `desktop_approval.php` `handleList` SQL 查询：添加 `GROUP BY d.id` 消除 `project_tech_assignments` JOIN 导致的重复行
- [x] 1.5 修复 `desktop_approval.php` `handleMyFiles` SQL 查询：同样添加 `GROUP BY d.id`
- [x] 1.6 统一前后端角色定义：在 `desktop_approval.php` 的 `$isManager` 中添加 `design_manager` 角色

## 2. P1 安全与逻辑修复

- [x] 2.1 修复 `http.ts` GET 请求 Token 传递：移除 URL 查询参数逻辑，统一对所有 HTTP 方法使用 `Authorization: Bearer` Header
- [x] 2.2 修复 `desktop_approval.php` `handleApprove`：UPDATE 添加 `AND approval_status = 'pending'` 条件，检查 affected rows 并在为 0 时返回错误
- [x] 2.3 修复 `desktop_approval.php` `handleReject`：同样添加 `AND approval_status = 'pending'` 条件和 affected rows 检查
- [x] 2.4 修复 `desktop_approval.php` `handleBatchApprove`：添加 `AND approval_status = 'pending'` 条件
- [x] 2.5 修复 `desktop_approval.php` `handleBatchReject`：添加 `AND approval_status = 'pending'` 条件
- [x] 2.6 修复 `desktop_file_manage.php` 批量删除：使用 `$pdo->beginTransaction()` / `commit()` / `rollBack()` 包裹整个操作
- [x] 2.7 修复 `desktop_file_manage.php` 重命名操作：S3 copy 失败时直接返回错误不更新 DB，成功时在事务中更新 DB

## 3. P2 其他修复

- [x] 3.1 修复 `ProjectDetailPage.tsx` `loadEvaluation`：添加 `Authorization: Bearer ${token}` Header
- [x] 3.2 修复 `ProjectDetailPage.tsx` `handleBatchReject`：将 `prompt()` 替换为自定义弹窗组件（复用 `InputDialog` 或创建类似 `ApprovalPage` 的驳回弹窗）
- [x] 3.3 修复 `ApprovalPage.tsx` Tab 切换：在 `setActiveTab('my_files')` 时同步将 `filters.status` 重置为 `'all'`

## 4. P3 代码质量优化

- [x] 4.1 统一 `formatFileSize`：移除 `ApprovalPage.tsx`、`ApprovalTable.tsx`、`FileTree.tsx`、`ProjectDetailPage.tsx` 中的本地定义，统一 `import { formatFileSize } from '@/lib/utils'`
- [x] 4.2 提取 `desktop_approval.php` 统计条件构建：将 `handleList` 中重复的条件构建逻辑提取为 `buildFilterConditions()` 公共函数
- [x] 4.3 清理 `ProjectDetailPage.tsx` 调试日志：移除所有 `console.log` 调试语句，保留 `console.error` 错误日志
- [x] 4.4 修复 `desktop_approval.php` `handleStats`：将 `"d.submitted_by = " . (int)$user['id']` 改为预处理语句参数化
