# Change: 收款方式手续费加成功能

## Why
不同收款方式（如微信、支付宝、银行转账等）会产生不同的手续费，需要在系统中配置每种收款方式的手续费加成，以便在收款时自动计算并显示原金额和加成后的金额，同时提供报表统计手续费情况。

## What Changes
- 在支付方式管理中增加手续费加成配置（支持固定金额和百分比两种方式）
- 收款登记时根据选择的支付方式自动计算并显示加成金额
- 收款记录中保存原金额和加成金额
- 新增手续费报表页面，按收款方式统计原金额和加成金额

## Impact
- Affected specs: payment-method-fee (新增)
- Affected code:
  - `index/public/admin_payment_methods.php` - 支付方式管理页面
  - `index/api/system_dict.php` - 字典API（扩展字段）
  - `index/public/finance_receipts.php` - 收款登记页面
  - `index/api/finance_receipt_save.php` - 收款保存API
  - `index/public/finance_fee_report.php` - 新增手续费报表页面
  - 数据库: `system_dict` 表增加手续费字段，`finance_receipts` 表增加加成金额字段
