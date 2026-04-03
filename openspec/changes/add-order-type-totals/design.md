# Design: 新单/复购合计统计

## Context

财务工作台已有汇总统计区域，显示合同数、分期数、应收/已收/未收合计。
现需增加新单（首单）和复购（非首单）的分类统计。

## Goals / Non-Goals

**Goals:**
- 在现有汇总卡片区域增加新单/复购统计
- 与现有统计保持一致的筛选逻辑和汇率转换

**Non-Goals:**
- 不改变现有统计卡片的布局或样式
- 不增加新的 API 接口（在现有查询中扩展）

## Decisions

### Decision 1: 新单/复购判断逻辑

使用子查询确定每个客户的首个合同ID：
```sql
SELECT customer_id, MIN(id) AS first_contract_id 
FROM finance_contracts 
GROUP BY customer_id
```

然后在主查询中：
- 新单：`c.id = first_contract_id`
- 复购：`c.id != first_contract_id`

### Decision 2: 统计金额

使用已收金额（amount_paid）作为统计口径，与现有"已收合计"保持一致。

### Decision 3: 前端实现

- 在现有汇总卡片行中增加两个卡片
- 复用现有的 `sumByCurrency` 数据结构，增加 `new_order` 和 `repurchase` 两个维度
- 复用现有的汇率转换逻辑
