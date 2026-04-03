# Change: 统一所有文件上传的并发和异步处理

## Why
当前系统中存在多个文件上传入口（桌面端、Web端、个人网盘等），但它们的实现方式不统一，导致：
1. 部分上传使用串行方式，速度较慢
2. 部分上传在完成时会阻塞等待S3操作，导致前端卡住
3. 代码重复，维护困难

## What Changes
- **桌面端上传**：使用本地缓存分片上传API，支持3并发，异步S3上传
- **Web端上传**：支持3并发分片上传，异步S3合并
- **个人网盘上传**：使用SSD缓存目录，支持异步S3上传
- **删除操作**：异步删除S3文件，立即返回响应
- **统一分片大小**：90MB

## Impact
- 受影响的API：
  - `desktop_chunk_upload.php` - 桌面端分片上传
  - `personal_drive_chunk_upload.php` - 个人网盘分片上传
  - `rc_upload_complete.php` - 资源中心上传完成
  - `admin_recycle_bin.php` - 回收站删除
- 受影响的前端：
  - `ProjectDetailPage.tsx` - 桌面端项目详情页
  - `use-auto-sync.ts` - 桌面端自动同步
  - `use-uploader.ts` - 桌面端上传器
  - `resource-center.js` - Web端资源中心
  - `folder-upload.js` - Web端文件夹上传
  - `file-transfer.js` - Web端文件传输SDK
