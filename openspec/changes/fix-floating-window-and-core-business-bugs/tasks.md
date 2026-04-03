## 1. 悬浮窗 P0 — Mini 模式修复

- [ ] 1.1 修改 `src-tauri/tauri.conf.json`：移除 floating 窗口的 `minWidth`/`minHeight` 配置（或设为 1）
- [ ] 1.2 修改 `FloatingWindowV2.tsx` 进入 Mini 模式：先调 `setMinSize(48, 48)` 再调 `setSize(48, 48)`
- [ ] 1.3 修改 `FloatingWindowV2.tsx` 退出 Mini 模式：先恢复 `setMinSize(350, 600)` 再恢复之前的窗口尺寸

## 2. 悬浮窗 P0 — 轮询 Stale Closure 修复

- [ ] 2.1 创建 `loadTasksRef`/`loadProjectsRef`/`checkDeadlineRemindersRef` 等 useRef，在每次渲染后同步最新函数引用
- [ ] 2.2 修改 polling `useEffect` 中的 `setInterval` 回调，改为通过 ref.current 调用

## 3. 悬浮窗 P0 — 通知检测修复

- [ ] 3.1 将 `lastFormCount`/`lastEvalCount` 从 useState 改为 useRef
- [ ] 3.2 在 `checkNotifications` 中使用 ref.current 比较并更新

## 4. 悬浮窗 P0 — 消息过滤去重

- [ ] 4.1 移除 `messageTypeFilter` 按钮中的 `setTimeout(() => loadMessages(), 0)`
- [ ] 4.2 确认 `useEffect([messageFilter, messageTypeFilter])` 可正确触发加载

## 5. 悬浮窗 P0 — 关闭事件拦截

- [ ] 5.1 修改 `src-tauri/src/lib.rs`：在 `on_window_event` 中为 `floating` 窗口也添加 `CloseRequested` 拦截

## 6. 悬浮窗 P1 — 功能修复

- [ ] 6.1 在 `handleCreateTask` 的 POST body 中添加 `need_help: newTaskNeedHelp ? 1 : 0`
- [ ] 6.2 使用 `getVersion()` API 替换两处硬编码版本号（`:1909` 和 `:1966`）
- [ ] 6.3 将 `newTaskDate` 默认值从 `new Date().toISOString().split('T')[0]` 改为 `new Date().toLocaleDateString('sv-SE')`
- [ ] 6.4 为 task/project 类型消息添加点击导航（调用 `openMainWindowAndNavigate`）
- [ ] 6.5 修复表单消息点击：先调用 `openMainWindowAndNavigate` 再 `requestOpenFormDetail`
- [ ] 6.6 统一 `_handleMarkRead` 和 `handleMarkMessageRead` 的字段名为 `id`
- [ ] 6.7 添加窗口 visibility 检测：隐藏时暂停轮询，显示时恢复
- [ ] 6.8 修复 settings sync effect：将 `serverUrl`/`rootDir` 比较改用 `useSettingsStore.getState()` 获取最新值

## 7. 核心业务 P0 — API 端点修复

- [ ] 7.1 修改 `ProjectKanbanPage.tsx` 项目删除：`projects.php` → `desktop_projects.php`

## 8. 核心业务 P0 — Token 修复

- [ ] 8.1 修改 `src/lib/uploader.ts`：将 `localStorage.getItem('desktop_token')` 替换为 `useAuthStore.getState().token`

## 9. 核心业务 P0 — FileSyncPage 路径修复

- [ ] 9.1 修改 `FileSyncPage.tsx`：将 `localStorage.getItem('floating_settings')` 和硬编码 `D:\\客户资源` 替换为 `useSettingsStore.getState().rootDir`

## 10. 核心业务 P0 — 上传恢复修复

- [ ] 10.1 修改 `PersonalDrivePage.tsx`：为每个上传任务维护独立的 `AbortController`（Map<taskId, AbortController>）
- [ ] 10.2 在上传任务状态中跟踪 `completedParts: number[]`，恢复时跳过已完成分片

## 11. 核心业务 P1 — 看板修复

- [ ] 11.1 修复 `ProjectKanbanPage.tsx` 初次双重加载：在 search debounce effect 中排除首次渲染
- [ ] 11.2 修复 `loadCustomers` stale closure：`customers.length` 改用 `useSyncStore.getState()` 或 ref

## 12. 核心业务 P1 — 其他 Bug 修复

- [ ] 12.1 修复 `FinancePage.tsx` stats fallback：`data.data.stats || stats` → `data.data.stats || { lastMonth: 0, thisMonth: 0, pending: 0, total: 0 }`
- [ ] 12.2 修复 `TechCommissionPage.tsx` Fragment 缺 key：`<>` → `<React.Fragment key={user.tech_user_id}>`
- [ ] 12.3 修复 `FormListPage.tsx` 排序解析：`split('_')` 改为 `lastIndexOf('_')` 拆分
- [ ] 12.4 修复 `ProjectDetailPage.tsx` tab 切换竞态：添加 requestId 计数器

## 13. isManager 统一

- [ ] 13.1 修改 `TasksPage.tsx`：移除内联角色列表，导入 `isManager` from utils
- [ ] 13.2 修改 `TeamProgressPage.tsx`：移除内联角色列表，导入 `isManager` from utils
- [ ] 13.3 修改 `Layout.tsx`：确认使用 `isManager` from utils
- [ ] 13.4 修改 `permissions.ts`：移除重复的 `MANAGER_ROLES` 定义，导入 utils 中的定义

## 14. 错误 toast 补全

- [ ] 14.1 `ProjectKanbanPage.tsx` loadKanban 添加错误 toast
- [ ] 14.2 `TasksPage.tsx` loadTasks/updateTaskStatus/createTask 添加错误 toast
- [ ] 14.3 `FormListPage.tsx` handleStatusChange 添加错误 toast
- [ ] 14.4 `TeamProgressPage.tsx` loadTeamTasks/handleAssignTask 添加错误 toast
- [ ] 14.5 `TeamProjectsPage.tsx` loadTeamProjects 添加错误 toast

## 15. 权限检查补全

- [ ] 15.1 `ProjectKanbanPage.tsx`：状态变更前检查权限
- [ ] 15.2 `FormListPage.tsx`：表单状态变更前检查权限
- [ ] 15.3 `FileSyncPage.tsx`：审批操作前检查 `canApproveFiles()`

## 16. 客户详情页按钮修复

- [ ] 16.1 为 "保存" 按钮添加 `handleSave` handler
- [ ] 16.2 为 "分配技术" 按钮添加分配对话框或跳转
