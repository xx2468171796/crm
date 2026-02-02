## 1. 数据库变更
- [x] 1.1 为 `system_dict` 表添加手续费字段（fee_type, fee_value）
- [x] 1.2 为 `finance_receipts` 表添加手续费记录字段（fee_type, fee_value, fee_amount, original_amount）
- [x] 1.3 创建数据库迁移脚本

## 2. 后端API
- [x] 2.1 修改 `system_dict.php` API 支持手续费字段的保存和读取
- [x] 2.2 修改 `finance_receipt_save.php` 保存手续费信息
- [x] 2.3 创建 `finance_fee_report.php` 手续费报表API
- [x] 2.4 创建 `finance_payment_fee.php` 手续费配置查询API

## 3. 前端页面
- [x] 3.1 修改 `admin_payment_methods.php` 支付方式管理页面，添加手续费配置表单
- [x] 3.2 修改 `finance_receipts.php` 收款登记页面，显示手续费计算
- [x] 3.3 创建 `finance_fee_report.php` 手续费报表页面
- [x] 3.4 在财务侧边栏添加手续费报表菜单

## 4. 核心函数
- [x] 4.1 在 `dict.php` 中添加获取支付方式手续费配置的函数
- [x] 4.2 添加手续费计算函数（支持固定金额和百分比）

## 5. 测试验证
- [ ] 5.1 测试支付方式手续费配置保存
- [ ] 5.2 测试收款时手续费自动计算
- [ ] 5.3 测试手续费报表统计
