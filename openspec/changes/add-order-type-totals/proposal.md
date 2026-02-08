## Why

财务工作台目前只显示总体的应收/已收/未收合计，无法区分新客户（首单）和老客户（复购）的业绩贡献。
增加新单/复购合计统计，便于分析客户结构和复购率，为销售策略提供数据支持。

## What Changes

- 在财务工作台汇总统计卡片区域，增加"新单合计"和"复购合计"两个统计卡片
- 新单定义：客户在系统中的第一个合同（按合同创建时间判断）
- 复购定义：客户在系统中的第二个及以后的合同
- 统计金额为已收金额，支持按货币汇率转换（与现有统计一致）
- 筛选条件应与现有统计保持一致

## Capabilities

### New Capabilities
- `order-type-totals`: 按订单类型（新单/复购）统计合同已收金额

### Modified Capabilities
（无）

## Impact

- `index/public/finance_dashboard.php`: 增加新单/复购统计卡片显示，增加后端统计查询
- `index/public/js/finance/dashboard.js`: 前端金额显示与汇率转换逻辑
