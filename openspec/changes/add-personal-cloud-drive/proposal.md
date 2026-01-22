# Change: 添加个人网盘系统

## Why
员工需要个人存储空间来管理工作文件，同时支持通过分享链接接收外部文件。管理员需要对全员网盘进行统一管理和容量控制。

## What Changes
- **个人网盘**：每个用户拥有独立网盘空间，文件存储路径为 `部门/用户/网盘文件/`
- **分享上传**：复用文件分享逻辑，支持生成分享链接让他人上传文件到个人网盘
- **文件重命名**：通过分享链接上传的文件自动重命名为 `分享+原文件名+时间戳`
- **容量管理**：管理员可设置每个网盘的存储上限，默认50GB
- **后台管理**：管理员可在后台对每个用户的网盘文件进行增删改查（用户不可见）
- **桌面端集成**：在桌面端添加"我的网盘"功能模块

## Impact
- Affected specs: personal-drive (新增)
- Affected code:
  - `desktop/src/pages/PersonalDrivePage.tsx` - 新建网盘页面
  - `index/api/personal_drive_*.php` - 新建网盘相关API
  - `index/public/admin_personal_drives.php` - 后台管理页面
  - `index/public/drive_upload.php` - 网盘分享上传页面
