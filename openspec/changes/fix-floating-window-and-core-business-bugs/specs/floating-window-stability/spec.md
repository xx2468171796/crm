## ADDED Requirements

### Requirement: Mini 模式窗口缩放
悬浮窗 SHALL 支持 Mini 模式，将窗口缩小到 48x48 像素的悬浮球形态。

#### Scenario: 进入 Mini 模式
- **WHEN** 用户触发 Mini 模式切换
- **THEN** 系统调用 `setMinSize(48, 48)` 后再调用 `setSize(48, 48)`，窗口缩小为悬浮球

#### Scenario: 退出 Mini 模式
- **WHEN** 用户点击悬浮球退出 Mini 模式
- **THEN** 系统恢复 `setMinSize(350, 600)` 并恢复之前保存的窗口尺寸

### Requirement: 轮询使用最新状态
悬浮窗的轮询定时器 SHALL 始终使用最新的过滤条件和函数引用，不受 React 闭包限制。

#### Scenario: 任务过滤条件变更后轮询
- **WHEN** 用户修改 taskFilter，且 10 秒轮询到期
- **THEN** 轮询使用最新的 taskFilter 值调用 loadTasks，返回符合新过滤条件的结果

#### Scenario: 窗口隐藏时停止轮询
- **WHEN** 悬浮窗被隐藏（handleClose）
- **THEN** 轮询定时器暂停，不发送 API 请求

#### Scenario: 窗口显示时恢复轮询
- **WHEN** 悬浮窗重新显示
- **THEN** 轮询定时器恢复，立即执行一次数据刷新

### Requirement: 通知检测正确触发
通知检测 SHALL 正确比较前后数量变化，触发通知提示。

#### Scenario: 新表单提交通知
- **WHEN** 表单数量从 N 增加到 N+1（N > 0）
- **THEN** 系统显示通知提示"有新的表单提交"

#### Scenario: 首次加载不触发通知
- **WHEN** 应用首次加载获取表单数量
- **THEN** 仅记录数量作为基准，不触发通知

### Requirement: 消息过滤无重复请求
消息过滤 SHALL 在切换过滤器时只发送一次 API 请求。

#### Scenario: 切换消息类型过滤
- **WHEN** 用户点击消息类型过滤按钮
- **THEN** 状态更新后由 useEffect 触发一次 loadMessages，按钮不额外触发

### Requirement: 悬浮窗关闭事件拦截
悬浮窗的系统关闭事件 SHALL 被拦截，改为隐藏窗口而非销毁。

#### Scenario: OS 触发悬浮窗关闭
- **WHEN** 操作系统发送 CloseRequested 事件给悬浮窗
- **THEN** 事件被拦截，窗口隐藏而非销毁

### Requirement: 新建任务发送完整字段
新建任务 SHALL 将所有用户填写的字段发送到 API。

#### Scenario: 创建带 need_help 的任务
- **WHEN** 用户勾选"需要帮助"并提交新任务
- **THEN** API 请求包含 `need_help: 1` 字段

### Requirement: 版本号动态显示
悬浮窗 SHALL 动态读取应用版本号，不使用硬编码值。

#### Scenario: 查看版本号
- **WHEN** 用户在悬浮窗设置页或状态栏查看版本号
- **THEN** 显示从 Tauri `getVersion()` API 获取的实际版本号

### Requirement: 任务日期使用本地时区
新建任务的默认日期 SHALL 使用用户本地时区的当天日期。

#### Scenario: UTC+8 用户在 23:30 创建任务
- **WHEN** UTC+8 用户在当地 23:30 打开新建任务
- **THEN** 默认日期为当地日期（今天），而非 UTC 的明天

### Requirement: 消息点击导航
所有类型的消息 SHALL 支持点击导航到对应详情页。

#### Scenario: 点击任务消息
- **WHEN** 用户点击类型为 task 的消息
- **THEN** 主窗口显示并导航到对应任务详情

#### Scenario: 点击表单消息
- **WHEN** 用户点击类型为 form 的消息
- **THEN** 主窗口先显示并获得焦点，然后导航到表单详情

### Requirement: 已读标记字段一致
标记消息已读 SHALL 使用统一的 API 字段名。

#### Scenario: 标记消息已读
- **WHEN** 用户标记一条消息为已读
- **THEN** API 请求使用统一字段名 `id`（而非混用 `notification_id`）

### Requirement: Settings 同步使用最新值
悬浮窗的 settings 同步事件处理 SHALL 使用最新的 serverUrl 和 rootDir 进行比较。

#### Scenario: 主窗口修改 serverUrl 后同步
- **WHEN** 主窗口发送 settings sync 事件，且 serverUrl 已变更
- **THEN** 悬浮窗使用最新的 serverUrl 进行比较，正确检测变化并更新
