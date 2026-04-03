# Change: 在桌面端项目看板增加客户看板视图

## Why
设计主管需要在桌面端快速找到客户并为其创建项目、分配技术人员。当前项目看板只能查看已有项目，无法直接从客户维度管理和创建新项目。

## What Changes
- 在项目看板页面增加"客户"视图Tab（与现有看板/表格/人员视图并列）
- 客户视图显示所有客户列表，支持搜索和筛选
- 每个客户可展开查看其项目列表
- 每个客户行提供"新建项目"按钮
- 新建项目弹窗支持选择技术人员分配

## Impact
- Affected specs: desktop-kanban
- Affected code:
  - `desktop/src/pages/ProjectKanbanPage.tsx` - 增加客户视图
  - `index/api/desktop_projects.php` - 增加客户列表和创建项目API
