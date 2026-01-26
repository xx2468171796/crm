# Tasks: 统一所有文件上传的并发和异步处理

## 1. 后端API修改
- [x] 1.1 创建桌面端本地缓存分片上传API (desktop_chunk_upload.php)
- [x] 1.2 修改personal_drive_chunk_upload.php使用SSD缓存目录
- [x] 1.3 修改rc_upload_complete.php为异步S3合并
- [x] 1.4 修改admin_recycle_bin.php删除操作为异步
- [x] 1.5 增加S3请求超时时间到300秒
- [x] 1.6 统一分片大小为90MB

## 2. 桌面端前端修改
- [x] 2.1 修改uploader.ts使用新的desktop_chunk_upload.php API
- [x] 2.2 修改use-uploader.ts使用新API
- [x] 2.3 修改ProjectDetailPage.tsx支持3并发上传
- [x] 2.4 修改use-auto-sync.ts支持3并发上传
- [x] 2.5 修改PersonalDrivePage.tsx支持3并发上传

## 3. Web端前端修改
- [x] 3.1 修改resource-center.js支持3并发上传
- [x] 3.2 修改folder-upload.js支持3并发上传
- [x] 3.3 修改file-transfer.js支持3并发上传
- [x] 3.4 修改portal.php客户门户支持3并发上传

## 4. 测试验证
- [ ] 4.1 测试桌面端项目文件上传
- [ ] 4.2 测试桌面端个人网盘上传
- [ ] 4.3 测试Web端资源中心上传
- [ ] 4.4 测试回收站删除功能
