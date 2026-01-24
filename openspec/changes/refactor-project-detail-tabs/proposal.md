# Change: 重构项目详情页为顶部Tab导航布局

## Why
当前桌面端项目详情页布局拥挤，左侧Tab导航占用空间，信息重复显示（项目名称、编号在顶部和卡片中都有），状态进度条占用大量垂直空间。需要优化布局提升信息密度和用户体验。

## What Changes
- 将左侧垂直Tab导航改为顶部水平Tab导航
- 精简顶部信息区，移除重复内容
- 状态进度条支持折叠/展开
- 项目信息、客户信息、设计负责人三列并排显示
- 项目周期信息整合到项目信息卡片中
- 内容区域获得更大的水平空间

## Impact
- Affected specs: desktop-ui (新建)
- Affected code: 
  - `desktop/src/pages/ProjectDetailPage.tsx` - 主要修改文件
  - 无后端API变更
  - 无数据库变更
