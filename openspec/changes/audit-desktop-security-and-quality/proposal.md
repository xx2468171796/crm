## Why

桌面端应用（Tauri + React）经过深度安全审计和代码质量检查，发现 **60+ 个问题**，涵盖严重安全漏洞（CSP 绕过、文件系统全权限暴露、命令注入风险）、状态管理缺陷、错误处理缺失、性能瓶颈等。这些问题在生产环境中可能导致数据泄露、应用崩溃和用户体验严重下降。需要立即修复高危安全漏洞，并系统性改善代码质量。

## What Changes

### 安全加固（P0 - 紧急）
- **BREAKING** 重构 CSP 策略，移除 `'unsafe-inline'`，删除 `dangerousDisableAssetCspModification: true`
- **BREAKING** 收紧 Tauri 权限：为 `main` 和 `floating` 窗口分别配置最小权限，移除 `fs:read-all`、`fs:write-all`、`shell:default`
- 修复 `downloader.rs` 中 Windows `open_file` 的命令注入风险（替换 `cmd /c start` 为安全 API）
- 修复 `uploader.ts` 中 token 使用 `localStorage.getItem('desktop_token')` 与 auth store 不一致的问题
- 为 Rust 端所有接受路径参数的 commands 添加路径遍历防护
- 添加 `read_file_chunk` 中 `length` 参数的大小限制验证
- 将 token 存储从 `localStorage` 迁移到 Tauri 加密存储（`plugin-store`）
- 修复 CORS 配置：`api_init.php` 中 origin 校验改为白名单机制

### 稳定性修复（P1 - 高优先级）
- 添加 React Error Boundary，防止渲染错误导致白屏
- 修复 token 过期未校验问题：`auth.ts` 中 `expireAt` 字段未被使用
- 统一 HTTP 客户端：消除直接 `fetch` 调用（`DashboardPage`、`ApprovalPage`、`SettingsPage`、`use-auto-sync`），统一使用 `http` 工具
- 修复 `SettingsPage` 和 `websocket.ts` 中缺少 Authorization header 的请求
- 修复 `use-downloader.ts` 中将本地路径当 URL fetch 的 bug
- 修复浮动窗口缺少 `QueryClientProvider` 的问题
- 统一三处版本号（`package.json`、`tauri.conf.json`、`Cargo.toml`）

### 性能优化（P2 - 中优先级）
- 为大型组件添加 `React.memo`、`useMemo`、`useCallback`（`ApprovalPage` 565行、`ProjectDetailPage` 3829行、`FloatingWindowV2` 2149行）
- 修复 `useSettingsStore` 全量订阅导致整个 App 重渲染
- 修复 `use-auto-sync.ts` 中 `performSync` 依赖数组膨胀导致定时器频繁重建
- Rust 端 `scanner.rs` 正则编译改用 `lazy_static!`
- 修复 `download_file` 非流式下载（整体加载到内存）
- 为 `ApprovalPage` 添加分页或虚拟滚动

### 代码质量改善（P3 - 常规）
- 消除重复代码：项目名规范化（3处）、下载跳过逻辑（2处）、MIME 映射（Rust/TS 各1处）、`sanitizeFolderName`（2处）
- 统一 Toast 系统（目前存在 Radix-based 和 Zustand-based 两套）
- 消除重复 `MANAGER_ROLES` 定义（3处）
- 修复 Stale Closure 问题：`use-downloader.ts` 中 `downloadTasks` 闭包过期
- 修复 `sync store` 未使用 `getStorageKey()` 导致多账户共享数据
- 修复类型安全：消除 `any` 类型使用，统一 `UploadTask` 类型定义
- 删除源码中的备份文件（`ApprovalPage.tsx.backup`）
- 修复登录页版本号硬编码 `v0.1.0`（实际版本 `1.6.95`）
- 关闭生产环境 DevTools（`tauri.conf.json` 中 `devtools: true`）

### 可访问性与 UX 改善（P3）
- 为图标按钮添加 `aria-label`
- 表单 `label` 添加 `htmlFor`/`id` 关联
- 模态框添加焦点陷阱和 Escape 键关闭
- 将 `confirm()` 替换为自定义确认对话框组件

## Capabilities

### New Capabilities
- `desktop-security-hardening`: CSP 加固、Tauri 权限最小化、路径遍历防护、token 安全存储、命令注入修复
- `desktop-error-resilience`: Error Boundary、token 过期校验、统一 HTTP 客户端、API 错误处理标准化
- `desktop-performance-optimization`: 组件 memoization、store 选择性订阅、定时器稳定性、流式下载、分页加载

### Modified Capabilities
<!-- 无现有 spec 需要修改 -->

## Impact

### 受影响的代码
- **Tauri 配置**: `src-tauri/tauri.conf.json`、`src-tauri/capabilities/default.json`
- **Rust 后端**: `src-tauri/src/downloader.rs`、`commands.rs`、`scanner.rs`、`file_sync.rs`、`mouse_listener.rs`、`keyboard.rs`、`lib.rs`
- **React 前端**: `src/App.tsx`、`src/main.tsx`、`src/floating-main.tsx`、全部 pages/、components/、stores/、hooks/、services/、lib/
- **PHP 后端**: `index/core/api_init.php`（CORS 修复）
- **构建配置**: `package.json`、`Cargo.toml`、`tailwind.config.js`、`vite.config.ts`

### 受影响的 API
- 所有 `desktop_*.php` API 端点（token 验证增强）
- WebSocket 配置端点（添加认证）

### 依赖变更
- 新增: `@tauri-apps/plugin-store`（加密存储）
- 可选新增: `@tauri-apps/plugin-updater`（自动更新，后续迭代）

### 风险
- CSP 策略收紧可能影响现有内联脚本/样式，需全面回归测试
- Tauri 权限缩减需确认浮动窗口的最小权限集
- token 存储迁移需要平滑过渡方案，避免用户被强制登出
