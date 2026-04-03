## ADDED Requirements

### Requirement: 项目删除使用正确 API
项目删除操作 SHALL 使用桌面端专用 API 端点 `desktop_projects.php`。

#### Scenario: 删除项目
- **WHEN** 用户在看板页删除项目
- **THEN** 请求发送到 `desktop_projects.php`，而非 `projects.php`

### Requirement: 上传使用统一 Token
文件上传 SHALL 从 auth store 获取 token，不直接读取 localStorage。

#### Scenario: 上传文件
- **WHEN** uploader 发起分片上传请求
- **THEN** Authorization header 使用 `useAuthStore.getState().token` 获取的 token

### Requirement: FileSyncPage 使用配置目录
FileSyncPage SHALL 使用 settings store 中配置的根目录，不使用硬编码路径。

#### Scenario: 同步页面读取根目录
- **WHEN** FileSyncPage 需要确定工作目录
- **THEN** 从 `useSettingsStore().rootDir` 获取，不使用 `D:\\客户资源`

### Requirement: 上传暂停恢复保留进度
文件上传暂停后恢复 SHALL 从上次成功的分片继续，不重新上传已完成分片。

#### Scenario: 暂停后恢复上传
- **WHEN** 用户暂停 50% 进度的上传任务后恢复
- **THEN** 仅上传剩余 50% 的分片，已完成分片跳过

### Requirement: 独立 AbortController
每个上传任务 SHALL 拥有独立的 AbortController，暂停操作只影响对应任务。

#### Scenario: 暂停单个上传任务
- **WHEN** 用户暂停第 2 个上传任务
- **THEN** 仅第 2 个任务暂停，其他任务继续上传

### Requirement: 看板数据加载无重复
看板页初次渲染 SHALL 只加载一次数据，不因 debounce effect 导致双重加载。

#### Scenario: 页面初次加载
- **WHEN** 用户进入看板页
- **THEN** 数据只请求一次，不出现两个并行的相同请求

### Requirement: 金融统计 fallback 使用默认值
金融页统计数据 SHALL 在 API 返回空值时使用默认值，不引用旧状态。

#### Scenario: API 返回空 stats
- **WHEN** 金融 API 返回 `stats: null`
- **THEN** 使用 `{ lastMonth: 0, thisMonth: 0, pending: 0, total: 0 }` 而非旧的 stats 状态

### Requirement: Fragment 正确使用 key
列表渲染中的 Fragment SHALL 使用唯一 key prop。

#### Scenario: 提成表格渲染
- **WHEN** 渲染用户提成行
- **THEN** Fragment 使用 `key={user.tech_user_id}` 避免 React 警告

### Requirement: 排序值正确解析
排序选择器 SHALL 正确解析含下划线的字段名。

#### Scenario: 选择 update_time 降序
- **WHEN** 用户选择"更新时间降序"排序
- **THEN** 解析 `update_time_desc` 得到 `sortBy='update_time'`、`order='desc'`

### Requirement: 错误操作显示 toast 提示
所有 API 操作失败 SHALL 向用户显示 toast 错误提示。

#### Scenario: 看板加载失败
- **WHEN** 看板数据请求返回错误
- **THEN** 显示 toast "加载失败" + 错误描述

#### Scenario: 任务创建失败
- **WHEN** 创建任务 API 返回错误
- **THEN** 显示 toast "创建失败" + 错误描述

### Requirement: 页面操作权限检查
敏感操作 SHALL 在客户端先进行权限检查，不满足权限的操作隐藏或禁用。

#### Scenario: 非管理员修改项目状态
- **WHEN** 非管理员用户尝试修改项目状态
- **THEN** 状态修改菜单不显示或操作被阻止

#### Scenario: 非管理员审批文件
- **WHEN** 非管理员用户访问 FileSyncPage
- **THEN** 审批按钮隐藏或禁用

### Requirement: Tab 切换数据一致性
项目详情页快速切换 tab SHALL 确保显示的数据与当前选中 tab 一致。

#### Scenario: 快速切换文件→消息 tab
- **WHEN** 用户快速从"文件"tab 切换到"消息"tab
- **THEN** 仅显示消息数据，不会因文件请求延迟返回而显示文件数据

### Requirement: 客户详情页按钮可用
客户详情页的"保存"和"分配技术"按钮 SHALL 有可用的 onClick handler。

#### Scenario: 点击保存按钮
- **WHEN** 用户在客户详情页点击"保存"
- **THEN** 保存表单数据到后端 API

#### Scenario: 点击分配技术按钮
- **WHEN** 用户点击"分配技术"按钮
- **THEN** 打开技术人员选择器或分配对话框
