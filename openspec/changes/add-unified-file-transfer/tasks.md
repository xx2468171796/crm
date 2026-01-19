# Tasks: 统一文件传输服务

## 1. 核心服务实现
- [x] 1.1 更新 `storage.php` 配置，添加 upload 配置项
- [x] 1.2 创建 `FileTransferService.php` 核心服务类
  - [x] 1.2.1 实现 `detectMode()` 环境检测方法
  - [x] 1.2.2 实现 `initUpload()` 上传初始化方法
  - [x] 1.2.3 实现 `uploadChunk()` 分片上传方法
  - [x] 1.2.4 实现 `completeUpload()` 完成上传方法
  - [x] 1.2.5 实现 `proxyDownload()` 代理下载方法
  - [x] 1.2.6 实现进度跟踪方法 (`getProgress()`, `updateProgress()`)

## 2. API 层实现
- [x] 2.1 创建 `file_transfer.php` 统一 API（合并上传/下载/进度）
- [x] 2.2 支持 action 参数：init/chunk/complete/direct/progress/download/mode

## 3. 前端 SDK 实现
- [x] 3.1 创建 `file-transfer.js` 前端 SDK
  - [x] 3.1.1 实现 `FileTransfer` 类
  - [x] 3.1.2 实现 `upload()` 方法（支持小文件直传和大文件分片）
  - [x] 3.1.3 实现 `download()` 方法
  - [x] 3.1.4 实现进度回调机制
  - [x] 3.1.5 实现错误处理和重试逻辑

## 4. 各端集成
- [x] 4.1 修改 `resource-center.js` 使用新 SDK（已添加 FileTransfer 集成和进度弹窗）
- [x] 4.2 修改 `project_detail.php` 引入 SDK（已添加 file-transfer.js 引用）
- [x] 4.3 `portal.php` 无需修改（客户门户只有下载功能，无上传）
- [x] 4.4 桌面端目录为空，无需修改

## 5. 测试与验证
- [ ] 5.1 测试小文件上传
- [ ] 5.2 测试大文件分片上传
- [ ] 5.3 测试进度显示
- [ ] 5.4 测试下载功能

## 6. 清理
- [x] 6.1 保留旧代码作为回退（uploadFileLegacy, uploadLargeFile）
- [x] 6.2 更新文档（docs/file-transfer.md）
