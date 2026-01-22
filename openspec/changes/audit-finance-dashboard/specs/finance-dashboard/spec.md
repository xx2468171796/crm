## MODIFIED Requirements

### Requirement: 日期筛选功能
系统必须提供按签约时间或实收时间筛选合同的功能。

#### Scenario: 按实收时间筛选本月有收款的合同
- **WHEN** 用户选择"实收时间"并选择"本月"
- **THEN** 系统显示本月有任何收款记录的合同（不论签约日期）
- **AND** 数据库查询使用 `received_date` 字段而非 `receipt_time`
- **AND** Ajax请求包含 `date_type=receipt` 和 `receipt_start/receipt_end` 参数

#### Scenario: 按签约时间筛选本月签约的合同
- **WHEN** 用户选择"签约时间"并选择"本月"
- **THEN** 系统显示本月签约的合同
- **AND** 数据库查询使用 `sign_date` 字段

#### Scenario: 筛选按钮触发Ajax刷新
- **WHEN** 用户点击"筛选"按钮
- **THEN** 系统通过Ajax刷新数据而非整页跳转
- **AND** URL地址栏同步更新筛选参数

### Requirement: 汇总统计功能
系统必须在财务工作台顶部显示合同汇总统计信息。

#### Scenario: 显示汇总金额
- **WHEN** 用户访问财务工作台
- **THEN** 系统显示合同总金额、已收金额、未收金额
- **AND** 金额可按选定的汇率模式（原始/固定/浮动）转换显示

### Requirement: 分组合计功能
系统必须提供按签约人或归属人分组统计的功能。

#### Scenario: 按签约人分组合计
- **WHEN** 用户选择"分组合计"并选择按签约人分组
- **THEN** 系统显示每个签约人的合同金额、已收金额、未收金额
- **AND** 金额支持多货币转换
