# Change: 财务工作台全面审计与Bug修复

## Why
财务工作台是CRM系统的核心模块，用户反馈存在多个功能异常和用户体验问题。需要全面审计代码，识别并修复所有bug，确保系统稳定可靠。

## What Changes
### 已发现的Bug（待修复）
1. **实收时间筛选问题** - ✅已修复
   - 字段名错误：`receipt_time` → `received_date`
   - JS `collectFilters` 缺少 `date_type/receipt_start/receipt_end` 参数
   - JS `applyDashboardFilters` 缺少相关参数

2. **筛选按钮刷新方式** - ✅已修复
   - 改为Ajax刷新而非整页跳转

3. **待审计功能模块**
   - [ ] 合同视图数据展示
   - [ ] 分期视图数据展示
   - [ ] 人员汇总视图
   - [ ] 汇总统计金额计算
   - [ ] 分组合计功能
   - [ ] 汇率转换功能
   - [ ] 自定义视图保存/加载
   - [ ] 筛选条件联动
   - [ ] 排序功能
   - [ ] 附件管理
   - [ ] 收款操作弹窗
   - [ ] 分期管理弹窗

## Impact
- Affected specs: finance-dashboard
- Affected code: 
  - `index/public/finance_dashboard.php` (1712行)
  - `index/public/js/finance-dashboard.js` (2658行)
  - `index/public/js/finance/*.js` (模块化JS)
  - `index/core/finance_dashboard_service.php`
