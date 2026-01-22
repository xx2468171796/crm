## 1. 分析与规划
- [ ] 1.1 审查数据库表结构，列出所有字段命名不一致的情况
- [ ] 1.2 识别分散的更新逻辑代码位置
- [ ] 1.3 制定字段命名统一规范文档

## 2. Service Layer 设计
- [ ] 2.1 设计Service类结构（ContractService、CustomerService、InstallmentService等）
- [ ] 2.2 定义标准化的CRUD方法签名
- [ ] 2.3 实现事务管理和错误处理模式

## 3. 核心Service实现
- [ ] 3.1 创建 `index/core/services/ContractService.php`
- [ ] 3.2 创建 `index/core/services/CustomerService.php`
- [ ] 3.3 创建 `index/core/services/InstallmentService.php`
- [ ] 3.4 创建 `index/core/services/ReceiptService.php`

## 4. 数据迁移
- [ ] 4.1 编写字段统一迁移脚本
- [ ] 4.2 执行迁移并验证数据完整性
- [ ] 4.3 更新相关SQL查询

## 5. 代码重构
- [ ] 5.1 重构API层调用Service
- [ ] 5.2 重构前端PHP调用Service
- [ ] 5.3 移除重复的数据库操作代码

## 6. 测试与验证
- [ ] 6.1 单元测试Service方法
- [ ] 6.2 集成测试API接口
- [ ] 6.3 回归测试现有功能
