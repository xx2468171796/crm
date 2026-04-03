## Context

桌面端 CRM 应用（Tauri 2.x + React + TypeScript）在深度审计中发现多个稳定性和功能问题。本设计聚焦于不涉及安全重构的修复，确保现有功能正确运行、应用不会崩溃白屏。

当前问题：
- 渲染异常导致整个应用白屏（无 Error Boundary）
- Token 过期后不自动登出，API 调用静默失败
- 多处直接使用 `fetch` 绕过统一 HTTP 客户端（无重试、无 401 处理）
- 浮动窗口缺少 QueryClientProvider，React Query 调用会崩溃
- 下载器将本地路径当 URL fetch（功能完全失效）
- 三处版本号不一致（1.6.95 / 1.6.85 / 1.6.21）
- 自动同步定时器因依赖膨胀频繁重建

## Goals / Non-Goals

**Goals:**
- 添加全局错误边界，防止白屏崩溃
- 实现 token 过期自动检测与登出
- 统一所有 API 调用走 `http` 客户端
- 修复浮动窗口 React Query 支持
- 修复下载器功能
- 统一版本号管理
- 稳定自动同步定时器
- 修复登录页版本号显示
- 修复 sync store 多账户数据隔离

**Non-Goals:**
- 安全加固（CSP、权限、加密存储等 — 后续迭代）
- 性能优化（memoization、虚拟滚动 — 后续迭代）
- 可访问性改善（aria、焦点管理 — 后续迭代）
- 代码重复消除（后续迭代）

## Decisions

### 1. Error Boundary 实现方式
**选择**: 在 `src/App.tsx` 外层包裹一个 class component Error Boundary，提供友好的错误 UI 和"重新加载"按钮。
**理由**: React 18 仍然只支持 class component 形式的 Error Boundary。不引入额外依赖（如 react-error-boundary），保持最小改动。

### 2. Token 过期检测策略
**选择**: 在 `ProtectedRoute` 中检查 `expireAt`，过期则自动调用 `logout()` 并跳转登录页。同时在 `http.ts` 的请求拦截中检查。
**理由**: 双重保障 — 路由级别防止过期用户访问页面，请求级别防止过期 token 发送无效请求。

### 3. 统一 HTTP 客户端策略
**选择**: 将 `DashboardPage`、`ApprovalPage`、`SettingsPage`、`use-auto-sync` 中的直接 `fetch` 替换为 `http.get/post`。对于 `SettingsPage` 的加速节点和 `websocket.ts` 的配置请求，使用 `http` 客户端以自动附加 token。
**理由**: 统一走 `http` 可获得重试、并发控制、401 自动登出等能力。

### 4. 版本号统一方案
**选择**: 以 `package.json` 的 `1.6.95` 为准，同步更新 `tauri.conf.json` 和 `Cargo.toml`。
**理由**: `package.json` 版本最高，`version-bump.js` 脚本已存在用于同步。

### 5. 浮动窗口修复
**选择**: 在 `floating-main.tsx` 中添加 `QueryClientProvider`。
**理由**: 最小改动，与 `main.tsx` 保持一致。

## Risks / Trade-offs

- [Token 过期检测] 如果客户端时钟不准确，可能误判过期 → 使用 5 分钟缓冲窗口
- [HTTP 客户端统一] `SettingsPage` 加速节点请求改用 `http` 后 URL 拼接方式不同 → 需保持 endpoint 格式兼容
- [版本号同步] Cargo.toml 版本更新后需要重新编译 Rust → CI 构建时自动处理
