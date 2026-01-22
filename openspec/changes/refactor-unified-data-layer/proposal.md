# Change: 统一数据库字段命名与封装Service Layer

## Why
当前数据库中存在"同一业务含义对应多个不同字段"（如合同货币字段在不同地方可能命名不同）以及"更新逻辑分散"（同一实体的更新代码散落在多个文件中）的问题，导致数据不一致和维护困难。

## What Changes
- **统一字段命名规范**：审查并统一数据库字段命名，确保同一业务概念使用一致的字段名
- **创建Service Layer**：封装原子化更新方法，所有数据更新操作通过统一的Service类进行
- **数据迁移脚本**：修复由字段不一致导致的历史数据问题
- **代码重构**：将分散的更新逻辑迁移到Service Layer

## Impact
- Affected specs: data-layer (新增)
- Affected code: 
  - `index/core/` - 新增Service类
  - `index/api/` - 调用Service而非直接操作数据库
  - `index/public/` - 调用Service而非直接操作数据库
  - 数据库迁移脚本
