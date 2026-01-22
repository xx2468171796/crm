# Tasks: 个人网盘系统

## 1. 数据库设计
- [ ] 1.1 创建 `personal_drives` 表存储用户网盘信息（user_id, storage_limit, used_storage）
- [ ] 1.2 创建 `drive_files` 表存储网盘文件（drive_id, filename, storage_key, file_size, folder_path）
- [ ] 1.3 创建 `drive_share_links` 表存储网盘分享链接

## 2. 后端API开发
- [ ] 2.1 创建 `personal_drive_list.php` - 获取网盘文件列表
- [ ] 2.2 创建 `personal_drive_upload.php` - 上传文件到网盘
- [ ] 2.3 创建 `personal_drive_download.php` - 下载网盘文件
- [ ] 2.4 创建 `personal_drive_delete.php` - 删除网盘文件
- [ ] 2.5 创建 `personal_drive_share.php` - 生成分享链接
- [ ] 2.6 创建 `drive_share_upload.php` - 处理分享上传（无需认证）

## 3. 分享上传页面
- [ ] 3.1 创建 `drive_upload.php` 公开上传页面（复用门户风格UI）
- [ ] 3.2 实现拖拽上传和批量上传
- [ ] 3.3 文件自动重命名为 `分享+原文件名+时间戳`

## 4. 桌面端集成
- [ ] 4.1 创建 `PersonalDrivePage.tsx` 网盘页面
- [ ] 4.2 实现文件列表展示（支持文件夹结构）
- [ ] 4.3 实现文件上传/下载/删除
- [ ] 4.4 实现生成分享链接功能
- [ ] 4.5 显示存储空间使用情况

## 5. 后台管理
- [ ] 5.1 创建 `admin_personal_drives.php` 管理页面
- [ ] 5.2 实现查看所有用户网盘
- [ ] 5.3 实现设置网盘容量上限
- [ ] 5.4 实现管理员增删改查用户文件

## 6. 测试验证
- [ ] 6.1 测试文件上传下载
- [ ] 6.2 测试分享链接上传
- [ ] 6.3 测试容量限制
- [ ] 6.4 测试后台管理功能
