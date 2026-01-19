# Change: 添加文件去重（Deduplication）功能

## Why
当用户上传相同内容的文件时，系统会重复存储多份相同数据，浪费存储空间。通过文件哈希去重，可以实现"秒传"功能，提升用户体验并节省存储成本。

## What Changes
- 添加 `file_hash` 字段到 `deliverables` 表，存储文件的 SHA256 哈希值
- 前端在上传前计算文件哈希
- 后端检查哈希是否已存在，存在则复用已有存储（秒传）
- 不存在则正常上传，上传后保存哈希

## Impact
- Affected specs: file-transfer
- Affected code:
  - `index/api/rc_upload_init.php` - 添加哈希检查逻辑
  - `index/api/rc_upload_complete.php` - 保存文件哈希
  - `index/api/deliverables.php` - 保存文件哈希
  - `index/public/js/components/resource-center.js` - 前端计算哈希
  - 数据库迁移脚本 - 添加 file_hash 字段
