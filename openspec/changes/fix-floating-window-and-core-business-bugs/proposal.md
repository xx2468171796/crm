## Why

悬浮窗（FloatingWindowV2）和核心业务页面经深度审计发现 **85+ 个问题**，其中包括严重功能失效（Mini 模式无法缩小、轮询 stale closure、通知永远不触发）、核心业务 Bug（项目删除 API 端点错误、上传 token 读错 key、暂停恢复不保留进度）以及多处竞态条件和缺失权限检查。这些问题直接影响用户日常操作，需要系统性修复。

## What Changes

### 悬浮窗 P0 修复 — 阻塞用户操作

- 修复 Mini 模式失效：调整 `tauri.conf.json` 悬浮窗 `minWidth`/`minHeight` 允许缩小到图标尺寸，并在进入/退出 Mini 模式时动态设置 `setMinSize`
- 修复轮询 stale closure：polling `useEffect` 使用 `useRef` 存储最新的 `taskFilter`、`loadTasks` 等函数引用，或在 interval 回调中使用 `getState()` 获取最新值
- 修复表单通知永不触发：将 `lastFormCount`/`lastEvalCount` 改用 `useRef` 存储，避免闭包捕获初始值
- 修复消息过滤双重触发：移除按钮 `setTimeout(() => loadMessages(), 0)`，仅依赖 `useEffect` 响应 filter 状态变化
- 修复悬浮窗关闭事件未拦截：在 `lib.rs` 中为 floating 窗口也添加 `CloseRequested` 拦截，改为 hide 而非 destroy

### 悬浮窗 P1 修复 — 功能缺陷

- 修复 `newTaskNeedHelp` 字段未发送到 API
- 统一悬浮窗三处版本号显示（动态读取 Tauri `getVersion()`）
- 修复 `newTaskDate` 使用 UTC 导致时区偏差
- 修复非表单类型消息点击无反应，添加导航到对应详情页
- 修复表单消息点击不先显示主窗口
- 修复 `_handleMarkRead` 和 `handleMarkMessageRead` 字段名不一致
- 修复窗口隐藏后轮询定时器仍在运行，添加 visibility 检测
- 修复 settings sync effect stale closure（添加依赖或改用 ref）

### 核心业务 P0 修复

- 修复项目删除 API 端点：`projects.php` → `desktop_projects.php`
- 修复 `uploader.ts` token 读取：从 `localStorage('desktop_token')` 改为使用 `useAuthStore.getState().token`
- 修复 `FileSyncPage` 硬编码路径：改用 `useSettingsStore().rootDir`
- 修复 `PersonalDrivePage` 上传暂停恢复不保留进度：跟踪已完成分片，从断点续传
- 修复 `PersonalDrivePage` 共享 AbortController：每个上传任务独立 AbortController

### 核心业务 P1 修复

- 修复 Kanban 页 stale closure + 初次双重加载
- 修复 Finance 页 stats fallback 引用旧状态
- 修复提成页 Fragment 缺 key
- 修复排序值解析 `update_time_desc` 被错误拆分
- 统一 `isManager` 定义（5处引用同一来源）
- 添加 7 处缺失的错误 toast 提示
- 添加 5 处缺失的权限检查
- 修复项目详情页快速切换 tab 竞态条件
- 修复客户详情页 "保存" 和 "分配技术" 按钮无 onClick

### 悬浮窗组件拆分（架构改善）

- 将 FloatingProjectSelector 的 `fetch` 改用 `http` 客户端
- 提取 tab 间独立的 `searchText`/`sortBy`/`groupEnabled` 状态

## Capabilities

### New Capabilities
- `floating-window-stability`: 悬浮窗 Mini 模式、轮询机制、通知触发、窗口生命周期、消息交互的修复
- `core-business-bugfix`: 核心业务页面的 API 端点修正、token 修复、上传恢复、竞态条件消除、权限检查补全、错误反馈完善
- `manager-role-unification`: 统一 `isManager` 定义，消除 5 处不一致的角色判断逻辑

### Modified Capabilities
<!-- 无现有 spec 需要修改 -->

## Impact

### 受影响的代码

**悬浮窗（14 个文件）:**
- `src/pages/FloatingWindowV2.tsx` — 主要修复目标（Mini 模式、轮询、通知、消息、状态管理）
- `src/floating-main.tsx` — 已修复（上一轮）
- `src-tauri/src/lib.rs` — 添加 floating 窗口 close 拦截
- `src-tauri/tauri.conf.json` — 调整 floating 窗口 minWidth/minHeight
- `src/components/FloatingProjectSelector.tsx` — fetch → http
- `src/lib/windowEvents.ts` — 窗口通信

**核心业务（15+ 个文件）:**
- `src/pages/ProjectKanbanPage.tsx` — 删除 API、stale closure、双重加载
- `src/pages/ProjectDetailPage.tsx` — tab 切换竞态
- `src/pages/CustomerDetailPage.tsx` — 按钮 handler
- `src/pages/FinancePage.tsx` — stats fallback
- `src/pages/TechCommissionPage.tsx` — Fragment key、toast variant
- `src/pages/FormListPage.tsx` — 排序解析、权限检查
- `src/pages/TasksPage.tsx` — 错误 toast、权限
- `src/pages/TeamProgressPage.tsx` — 错误 toast、权限
- `src/pages/TeamProjectsPage.tsx` — 错误 toast
- `src/pages/FileSyncPage.tsx` — 硬编码路径、权限检查
- `src/pages/PersonalDrivePage.tsx` — 上传恢复、AbortController
- `src/pages/FileLogsPage.tsx` — 分页
- `src/lib/uploader.ts` — token 修复
- `src/lib/utils.ts` — isManager 统一来源
- `src/components/Layout.tsx` — isManager 引用

### 风险
- 悬浮窗 Mini 模式修改需要同时改 Tauri config 和 React 逻辑，需在 Windows 上实测
- `isManager` 统一后可能影响现有页面的角色可见性，需回归测试所有角色
- 上传断点续传改动涉及状态跟踪逻辑，需确保与后端分片 API 兼容
