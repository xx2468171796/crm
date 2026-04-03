# Change: 统一文件传输服务

## Why
当前系统中文件上传/下载功能分散在多个位置（后台Web、桌面端、客户门户），且存在 HTTP S3 与 HTTPS 网站之间的 Mixed Content 问题。需要一个统一的、自适应的文件传输服务，支持内网/外网、HTTP/HTTPS 等多种部署场景，并提供统一的进度跟踪能力。

## What Changes
- **BREAKING**: 统一所有文件上传/下载入口到新的 API
- 新增 `FileTransferService` 核心服务类，自动检测环境选择直连或代理模式
- 新增统一上传 API (`file_upload.php`)，支持小文件直传和大文件分片
- 新增统一下载 API (`file_download.php`)，支持代理下载和进度跟踪
- 新增进度查询 API (`file_progress.php`)，支持实时进度轮询
- 新增前端统一 SDK (`file-transfer.js`)，提供一致的上传/下载体验
- 修改各端代码（后台、桌面端、客户门户）使用新的统一接口
- 支持断点续传（大文件分片上传）
- 配置驱动，通过环境变量控制上传模式（auto/proxy/direct）

## Impact
- Affected specs: file-transfer (新增)
- Affected code:
  - `index/services/FileTransferService.php` (新增)
  - `index/api/file_upload.php` (新增)
  - `index/api/file_download.php` (新增)
  - `index/api/file_progress.php` (新增)
  - `index/public/js/file-transfer.js` (新增)
  - `index/config/storage.php` (修改，新增 upload 配置)
  - `index/public/js/components/resource-center.js` (修改，使用新 SDK)
  - `index/public/project_detail.php` (修改，使用新 SDK)
  - `index/public/portal.php` (修改，使用新 SDK)
  - `index/desktop/` (修改，使用新 SDK)
