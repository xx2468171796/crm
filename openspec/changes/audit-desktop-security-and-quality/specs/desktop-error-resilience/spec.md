## ADDED Requirements

### Requirement: Error Boundary 防崩溃白屏
应用 SHALL 在最外层包裹 React Error Boundary，捕获所有未处理的渲染错误，显示友好的错误提示页面而非白屏。

#### Scenario: 子组件抛出渲染错误
- **WHEN** 任意子组件在渲染过程中抛出未捕获的 JavaScript 错误
- **THEN** Error Boundary 捕获错误，显示包含错误描述和"重新加载"按钮的备用 UI

#### Scenario: 用户点击重新加载
- **WHEN** 用户在错误页面点击"重新加载"按钮
- **THEN** 应用重置错误状态并重新加载页面

### Requirement: Token 过期自动登出
系统 SHALL 在检测到 token 已过期时自动登出用户并跳转到登录页。

#### Scenario: 路由守卫检测过期 token
- **WHEN** 用户导航到受保护页面且 token 的 expireAt 时间已过期
- **THEN** 系统调用 logout()，用户被重定向到登录页

#### Scenario: HTTP 请求前检测过期 token
- **WHEN** 发起 API 请求时检测到 token 已过期（含 5 分钟缓冲）
- **THEN** 系统调用 logout() 并返回认证错误响应，不发送实际请求

### Requirement: 统一 HTTP 客户端
所有 API 请求 SHALL 通过统一的 HttpClient (`src/lib/http.ts`) 发起，禁止直接使用 `fetch`。

#### Scenario: Dashboard 加载统计数据
- **WHEN** DashboardPage 加载统计数据
- **THEN** 使用 `http.get()` 而非直接 `fetch`，自动携带 Authorization header

#### Scenario: SettingsPage 加载加速节点
- **WHEN** SettingsPage 请求加速节点列表
- **THEN** 使用 `http.get()` 自动携带 Authorization header

#### Scenario: WebSocket 配置请求
- **WHEN** WebSocket 服务获取配置
- **THEN** 使用 `http.get()` 自动携带 Authorization header

#### Scenario: API 请求收到 401 响应
- **WHEN** 任意 API 请求返回 HTTP 401
- **THEN** HttpClient 自动调用 logout() 登出用户

### Requirement: 浮动窗口 React Query 支持
浮动窗口 SHALL 包裹 QueryClientProvider，确保 React Query hooks 正常工作。

#### Scenario: 浮动窗口使用 React Query
- **WHEN** FloatingWindowV2 内部组件使用 useQuery 或 useMutation
- **THEN** 请求正常发起，不抛出 "No QueryClient set" 错误

### Requirement: 下载器功能修复
下载器 SHALL 正确通过 Tauri 命令下载文件到本地，而非将本地路径当 URL fetch。

#### Scenario: 用户下载文件
- **WHEN** 用户触发文件下载且有有效的 presignedUrl
- **THEN** 系统使用 Tauri 的 download_file_chunked 命令将文件保存到 localPath

### Requirement: 版本号统一
应用的版本号 SHALL 在 package.json、tauri.conf.json、Cargo.toml 三处保持一致。

#### Scenario: 查看版本号
- **WHEN** 用户在登录页或设置页查看版本号
- **THEN** 显示与 package.json 一致的版本号

### Requirement: Sync Store 数据隔离
Sync Store SHALL 使用基于实例的存储 key，确保多账户数据不会混淆。

#### Scenario: 不同账户登录
- **WHEN** 用户 A 登出后用户 B 登录
- **THEN** 用户 B 不会看到用户 A 的同步任务和本地分组数据

### Requirement: 自动同步定时器稳定
自动同步 SHALL 维持稳定的定时器，不因依赖变化而频繁重建。

#### Scenario: 同步间隔设为 30 分钟
- **WHEN** 用户设置自动同步间隔为 30 分钟
- **THEN** performSync 每 30 分钟执行一次，定时器不会因 React 重渲染而频繁重建

### Requirement: Stale Closure 修复
Hook 中 SHALL 使用 `getState()` 获取最新 store 状态，避免闭包捕获过期数据。

#### Scenario: 下载任务队列处理
- **WHEN** useDownloader 的 startDownload 执行时
- **THEN** 使用 `useSyncStore.getState().downloadTasks` 获取最新任务列表，而非闭包中的旧值
