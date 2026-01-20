# Tasks: 客户看板视图

## 1. 后端API
- [x] 1.1 在 `desktop_projects.php` 增加 `action=customers` 获取客户列表（支持搜索、分页）
- [x] 1.2 在 `desktop_projects.php` 增加 `action=create_project` 创建项目API
- [x] 1.3 在 `desktop_projects.php` 增加 `action=tech_users` 获取可分配的技术人员列表

## 2. 前端实现
- [x] 2.1 在 `ProjectKanbanPage.tsx` 增加 `customer` 视图模式
- [x] 2.2 实现客户列表组件（搜索、展开/收起项目）
- [x] 2.3 实现"新建项目"弹窗（项目名称、技术人员选择）
- [x] 2.4 调用API创建项目并刷新列表

## 3. 测试验证
- [ ] 3.1 验证客户列表加载正常
- [ ] 3.2 验证搜索客户功能
- [ ] 3.3 验证创建项目并分配技术人员
- [ ] 3.4 验证新项目在看板中显示
