# 客户需求文档管理功能

## 功能概述

这个功能允许销售人员在Web端编写客户需求文档（Markdown格式），技术人员可以在桌面客户端查看和编辑这些文档。支持双向同步。

## 主要特性

1. **Markdown 编辑器**
   - 支持实时预览
   - 工具栏快捷按钮（标题、粗体、斜体、列表、表格等）
   - 图片上传功能
   - 自动保存（3秒延迟）

2. **一键读取客户信息**
   - 自动生成包含客户基本信息的Markdown模板
   - 包括客户名称、编号、联系方式、阶段信息等
   - 可选择追加或替换现有内容

3. **版本控制**
   - 每次保存自动创建历史版本
   - 记录修改人和修改时间

4. **双向同步**
   - Web端（销售）和桌面端（技术）都可以编辑
   - 使用 `last_sync_time` 字段实现增量同步

## 使用方法

### Web端（销售人员）

1. **创建/编辑需求文档**
   - 进入客户详情页
   - 点击左侧导航的"📝 需求文档"标签
   - 点击"编辑需求文档"按钮
   - 在编辑器中输入Markdown格式的需求文档

2. **一键导入客户信息**
   - 在编辑器页面点击"一键读取客户信息"按钮
   - 系统会自动生成包含客户信息的Markdown模板
   - 可以选择追加到现有内容或替换全部内容
   - 删除不需要的信息后保存

3. **上传图片**
   - 点击工具栏的"📷"按钮
   - 选择图片文件（最大10MB）
   - 图片会自动上传并插入Markdown语法

4. **查看需求文档**
   - 在客户详情页的"需求文档"标签中查看
   - 支持Markdown渲染，包括表格、图片等

### 桌面端（技术人员）

1. **查看需求列表**
   - 打开桌面应用
   - 点击左侧导航的"需求管理"
   - 查看所有参与项目的客户需求文档列表

2. **查看需求详情**
   - 点击需求卡片
   - 在弹窗中查看完整的Markdown渲染内容
   - 支持全屏查看

3. **编辑需求文档**
   - 在详情弹窗中点击"编辑"按钮
   - 左侧编辑器输入Markdown，右侧实时预览
   - 点击"保存"按钮保存修改

4. **搜索需求**
   - 在顶部搜索框输入客户名称或编号
   - 点击"搜索"按钮筛选

## 数据库结构

### customer_requirements 表
- `id`: 主键
- `customer_id`: 客户ID（外键）
- `content`: Markdown内容（longtext）
- `version`: 版本号
- `create_time`: 创建时间
- `update_time`: 更新时间
- `create_user_id`: 创建人ID
- `update_user_id`: 更新人ID
- `last_sync_time`: 最后同步时间（用于桌面端同步）

### customer_requirements_history 表
- `id`: 主键
- `requirement_id`: 需求文档ID
- `customer_id`: 客户ID
- `content`: 历史内容
- `version`: 版本号
- `create_time`: 创建时间
- `create_user_id`: 创建人ID
- `change_note`: 修改说明

## API 接口

### Web端 API (`/api/customer_requirements.php`)

1. **获取需求文档**
   - `GET ?action=get&customer_id=X`
   - 返回指定客户的需求文档

2. **保存需求文档**
   - `POST action=save`
   - Body: `{ customer_id, content }`
   - 自动创建历史版本

3. **获取客户信息**
   - `GET ?action=get_customer_info&customer_id=X`
   - 返回格式化的Markdown模板

4. **上传图片**
   - `POST action=upload_image`
   - FormData: `image` 文件
   - 返回图片URL

### 桌面端 API (`/api/desktop_requirements.php`)

1. **获取需求列表**
   - `GET ?action=list&search=X`
   - 返回技术人员参与项目的客户需求列表

2. **获取需求详情**
   - `GET ?action=get&customer_id=X`
   - 返回完整的需求文档内容

3. **保存需求文档**
   - `POST action=save`
   - Body: `{ customer_id, content }`
   - 权限检查：只能编辑参与项目的客户需求

4. **同步列表**
   - `GET ?action=sync_list&last_sync_time=X`
   - 返回自上次同步后更新的需求列表

## 权限控制

- **Web端**: 所有登录用户都可以创建和编辑需求文档
- **桌面端**: 技术人员只能查看和编辑自己参与项目的客户需求文档

## 文件位置

### 后端
- `/index/migrations/create_customer_requirements_table.sql` - 数据库迁移
- `/index/api/customer_requirements.php` - Web端API
- `/index/api/desktop_requirements.php` - 桌面端API
- `/index/public/customer_requirement_editor.php` - 编辑器页面
- `/index/public/customer_detail.php` - 客户详情页（已添加需求文档标签）
- `/index/public/css/customer-detail.css` - 样式文件（已添加Markdown样式）

### 前端（桌面端）
- `/desktop/src/pages/RequirementsPage.tsx` - 需求管理页面
- `/desktop/src/App.tsx` - 路由配置
- `/desktop/src/components/Layout.tsx` - 导航菜单
- `/desktop/src/index.css` - 全局样式（已添加prose样式）
- `/desktop/package.json` - 依赖配置（已添加react-markdown和remark-gfm）

## 安装步骤

1. **执行数据库迁移**
   ```bash
   # 方式1: 通过Web访问
   访问: http://your-domain/scripts/run_requirements_migration.php

   # 方式2: 直接执行SQL
   mysql -u用户名 -p数据库名 < /path/to/create_customer_requirements_table.sql
   ```

2. **安装桌面端依赖**
   ```bash
   cd desktop
   npm install
   # 或
   pnpm install
   ```

3. **重新构建桌面应用**
   ```bash
   cd desktop
   npm run build
   npm run tauri build
   ```

## 注意事项

1. 图片上传目录需要有写权限：`/uploads/requirements/`
2. 图片大小限制：10MB
3. 支持的图片格式：jpg, jpeg, png, gif, webp
4. Markdown编辑器使用 marked.js 库进行渲染
5. 桌面端使用 react-markdown 和 remark-gfm 进行渲染

## 未来改进

- [ ] 支持需求文档模板
- [ ] 支持需求文档导出（PDF、Word）
- [ ] 支持需求文档版本对比
- [ ] 支持需求文档评论功能
- [ ] 支持需求文档审批流程
- [ ] 支持需求文档关联项目任务

---

*创建日期: 2026-03-02*
*版本: 1.0*
