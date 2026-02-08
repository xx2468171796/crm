## Context

桌面端 CRM 应用的悬浮窗（2149 行单组件）和核心业务页面（15+ 个页面组件）在深度审计中暴露了大量稳定性问题。修复范围涵盖 Tauri 窗口配置、React 闭包管理、API 调用一致性、状态竞态、权限校验等多个层面。

主要约束：
- 保持最小改动原则，不重构架构（大规模拆分 FloatingWindowV2 留到后续迭代）
- 所有 API 调用走统一 `http` 客户端
- 权限检查使用 `core/rbac.php` 中的函数
- `isManager` 统一引用 `src/lib/utils.ts` 中的定义

## Goals / Non-Goals

**Goals:**
- 修复悬浮窗 Mini 模式、轮询、通知、窗口生命周期等 P0/P1 问题
- 修复核心业务页面的 API 端点、token、上传、竞态等 P0/P1 问题
- 统一 `isManager` 角色判断
- 补全错误提示和权限检查

**Non-Goals:**
- 不拆分 FloatingWindowV2 为多个子组件（后续迭代）
- 不添加 `useMemo`/`useCallback` 性能优化（后续迭代）
- 不修改安全相关配置（CSP、文件权限等）
- 不添加新功能（离线缓存、自动更新等）

## Decisions

### 1. 悬浮窗 Mini 模式修复策略
**选择**: 进入 Mini 模式时调用 `win.setMinSize(new LogicalSize(48, 48))`，退出时恢复 `setMinSize(new LogicalSize(350, 600))`。同时将 `tauri.conf.json` 中 floating 窗口的 `minWidth`/`minHeight` 移除（由代码动态控制）。
**理由**: 保留配置灵活性，Mini 模式需要极小窗口，而展开模式需要保证最小可用尺寸。

### 2. Stale Closure 修复模式
**选择**: 统一使用 `useRef` + `useEffect` 同步最新值的模式。将 `loadTasks`、`loadProjects`、`taskFilter` 等存入 ref，interval 回调通过 ref.current 调用。
**理由**: 这是 React 官方推荐的模式，比在 interval 依赖数组中加入所有值更稳定，避免频繁重建定时器。

### 3. 消息过滤去重策略
**选择**: 移除按钮的 `setTimeout(() => loadMessages(), 0)`，完全依赖 `useEffect([messageFilter, messageTypeFilter])` 触发加载。
**理由**: React 状态更新后 `useEffect` 会自动触发，`setTimeout` 是不必要的且导致用旧值请求。

### 4. isManager 统一方案
**选择**: 所有页面统一导入 `import { isManager } from '@/lib/utils'`，不在页面中内联角色列表。`MANAGER_ROLES` 在 `utils.ts` 中作为唯一定义源。
**理由**: 单一来源可以确保角色判断一致，后续添加新角色只需改一处。

### 5. 上传断点续传方案
**选择**: 在 `PersonalDrivePage` 的上传任务状态中跟踪 `completedParts: Set<number>`。暂停后恢复时跳过已完成的分片。每个任务独立 `AbortController`。
**理由**: 最小改动实现断点续传，不需要修改后端 API。

### 6. Tab 切换竞态修复
**选择**: 使用递增的 `requestId` 计数器。每次 `loadProject(tab)` 时记录当前 requestId，响应返回后检查是否与最新 requestId 匹配，不匹配则丢弃。
**理由**: 简单有效的竞态处理模式，不需要 AbortController（后端请求不支持取消）。

### 7. 错误 toast 补全策略
**选择**: 统一在 `catch` 块中调用 `toast({ title: '操作失败', description: error.message, variant: 'destructive' })`。
**理由**: 与现有代码风格一致。

## Risks / Trade-offs

- [Mini 模式] 动态 `setMinSize` 在某些 Windows 版本上可能有延迟 → 添加 50ms setTimeout 确保 setSize 在 setMinSize 之后执行
- [Stale Closure] useRef 模式增加了代码间接性 → 添加注释说明设计意图
- [isManager 统一] `design_manager` 加入后可能看到更多管理功能 → 需确认业务需求
- [上传续传] 已完成分片列表存在 Zustand store 中，应用重启后丢失 → 可接受，重启后重新上传
- [竞态修复] requestId 方案丢弃延迟响应 → 可能浪费一次请求，但确保数据正确
