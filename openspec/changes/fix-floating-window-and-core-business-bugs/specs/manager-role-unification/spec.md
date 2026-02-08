## ADDED Requirements

### Requirement: 统一管理员角色判断来源
系统中所有 `isManager` 判断 SHALL 引用 `src/lib/utils.ts` 中的 `MANAGER_ROLES` 常量和 `isManager()` 函数作为唯一来源。

#### Scenario: Layout 判断管理员菜单可见性
- **WHEN** Layout 组件判断是否显示团队管理菜单
- **THEN** 使用 `import { isManager } from '@/lib/utils'` 而非内联角色列表

#### Scenario: TasksPage 判断管理员功能
- **WHEN** TasksPage 判断是否显示任务分配功能
- **THEN** 使用 `isManager(user?.role)` 而非硬编码角色数组

#### Scenario: TeamProgressPage 判断管理员
- **WHEN** TeamProgressPage 判断是否显示团队视图
- **THEN** 使用 `isManager(user?.role)` 而非 `['admin', 'super_admin', ...].includes(role)`

#### Scenario: 新增管理员角色
- **WHEN** 后续需要添加新的管理员角色（如 `project_manager`）
- **THEN** 仅需在 `MANAGER_ROLES` 常量中添加一项，所有引用处自动生效

### Requirement: 管理员角色列表完整
`MANAGER_ROLES` SHALL 包含所有需要管理功能的角色，包括 `design_manager`。

#### Scenario: design_manager 访问团队任务
- **WHEN** design_manager 角色用户访问团队任务页
- **THEN** 能看到并使用管理员功能（与其他管理角色一致）
