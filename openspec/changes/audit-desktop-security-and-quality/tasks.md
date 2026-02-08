## 1. 添加 React Error Boundary 防白屏

- [x] 1.1 在 `src/App.tsx` 中创建 `ErrorBoundary` class component，包含 fallback UI（错误信息 + 重新加载按钮）
- [x] 1.2 在 `<BrowserRouter>` 外层包裹 `<ErrorBoundary>`

## 2. 修复 Token 过期未校验

- [x] 2.1 在 `ProtectedRoute` 组件中添加 `expireAt` 检查，过期则调用 `logout()` 并重定向
- [x] 2.2 在 `src/lib/http.ts` 的 `request` 方法开头添加 token 过期检查（含 5 分钟缓冲）

## 3. 统一 HTTP 客户端

- [x] 3.1 修改 `DashboardPage.tsx` 中的直接 `fetch` 调用，改用 `http.get()`
- [x] 3.2 修改 `SettingsPage.tsx` 中的 `loadAccelerationNodes` 直接 `fetch`，改用 `http.get()`
- [x] 3.3 修改 `ApprovalPage.tsx` 中的直接 `fetch` 调用，改用 `http.get/post()`
- [x] 3.4 修改 `use-auto-sync.ts` 中的直接 `fetch` 调用，改用 `http.get/post()`
- [x] 3.5 修改 `services/websocket.ts` 中 `fetchWsConfig` 的直接 `fetch`，改用 `http.get()`

## 4. 修复浮动窗口缺少 QueryClientProvider

- [x] 4.1 在 `src/floating-main.tsx` 中添加 `QueryClient` 实例和 `QueryClientProvider` 包裹

## 5. 修复 use-downloader.ts 下载功能

- [x] 5.1 修复 `startDownload` 函数，使用 presignedUrl 通过 fetch 流式下载代替将本地路径当 URL 的 `fetch` 调用

## 6. 统一三处版本号

- [x] 6.1 将 `src-tauri/tauri.conf.json` 的 version 更新为 `1.6.95`
- [x] 6.2 将 `src-tauri/Cargo.toml` 的 version 更新为 `1.6.95`

## 7. 修复登录页版本号硬编码

- [x] 7.1 在 `LoginPage.tsx` 中使用 Tauri `getVersion()` API 动态读取版本号，替换硬编码的 `v0.1.0`

## 8. 修复 Stale Closure 和 Sync Store Key

- [x] 8.1 修复 `use-downloader.ts` 中使用 `useSyncStore.getState()` 替代闭包中的旧值
- [x] 8.2 修复 `src/stores/sync.ts` 使用 `getStorageKey('sync-storage')` 替代硬编码 `'sync-storage'`

## 9. 修复 use-auto-sync performSync 依赖膨胀

- [x] 9.1 将 `performSync` 内部改为使用 `getState()` 获取 store 值，减少 useCallback 依赖数组
