## Context

桌面端（Tauri + React + TypeScript）与 PHP 后端共同构成文件审批工作流。当前代码在多处存在功能性 Bug 和安全隐患，涉及前端 6 个文件和后端 2 个文件。修复需兼顾向后兼容，避免引入回归。

当前架构：
- 前端通过 `fetch` / `http.ts` 调用 PHP API（`desktop_approval.php`、`desktop_file_manage.php`）
- 权限判断存在两套机制：前端 `utils.ts` 的 `isManager()` 和 `permissions store` 的 `canApproveFiles()`
- 文件审批状态在后端存储为字符串（`pending`/`approved`/`rejected`），API 返回时转换为数字（0/1/2）

## Goals / Non-Goals

**Goals:**
- 修复所有 P0-P2 级别的 Bug，确保审批工作流功能正确
- 统一前后端角色定义，消除权限不一致
- 加固后端审批操作的状态校验和事务安全
- 消除 Token 在 URL 中暴露的安全风险
- 提升代码可维护性（去重、清理调试日志）

**Non-Goals:**
- 不拆分 `ProjectDetailPage.tsx`（3800+ 行）为子组件——该重构范围过大，应作为独立 change
- 不引入新的审批功能（如审批流程、多级审批）
- 不修改数据库 schema
- 不重构前端状态管理方案（Zustand → 其他）

## Decisions

### 1. 排序逻辑放在前端还是后端

**决策**: 前端排序（客户端排序）

**理由**: 当前 `ApprovalTable` 已有排序 UI 和 state，只是缺少排序执行逻辑。审批列表数据量通常在 100 条以内（有分页），前端排序完全够用。服务端排序需改 API 接口，影响面更大。

**实现**: 在 `ApprovalTable` 渲染前对 `data` 使用 `Array.prototype.sort()` 按 `sortKey` 和 `sortOrder` 排序，然后渲染排序后的数组。

### 2. GET 请求 Token 传递方式

**决策**: 统一使用 `Authorization: Bearer` Header，不再区分 GET/POST

**理由**: 将 Token 放在 URL 查询参数中存在安全风险（服务器日志、浏览器历史、Referer 泄露）。所有请求类型统一使用 Header 传递更安全。

**替代方案**: 保留 URL 参数但使用一次性 Token——复杂度高，不适合当前项目规模。

**兼容性**: 后端 `desktop_auth.php` 已支持从 Header 和 URL 参数两种方式读取 Token，因此前端改为 Header 后后端无需修改。

### 3. 审批操作的幂等性处理

**决策**: 后端 UPDATE 添加 `AND approval_status = 'pending'` 条件，对非 pending 文件返回错误

**理由**: 防止重复操作导致审批记录混乱（如已通过的文件被再次通过会覆盖 `approved_at` 时间）。前端在收到错误后刷新列表，用户看到最新状态。

**实现**: SQL 改为 `UPDATE deliverables SET ... WHERE id = ? AND approval_status = 'pending'`，检查 affected rows，为 0 时返回错误。

### 4. 批量删除事务策略

**决策**: 使用 PDO 事务包裹整个批量删除操作

**理由**: 当前逐条删除，若中间失败，已删除的不会回滚。使用事务确保要么全部成功，要么全部回滚。

**实现**: `$pdo->beginTransaction()` → 循环删除 → `$pdo->commit()` / `$pdo->rollBack()`

### 5. 审批状态按钮的文件类型限制

**决策**: 移除 `categoryName === '作品文件'` 的硬编码限制，所有分类文件都展示审批状态

**理由**: 审批状态是文件维度的属性，不应受文件分类限制。后端对所有分类的文件都设置了 `approval_status`，前端应统一展示。

### 6. 批量驳回弹窗替代 prompt()

**决策**: 复用 `ProjectDetailPage` 已有的 `InputDialog` 组件或自建简单 modal

**理由**: Tauri 桌面端环境下原生 `prompt()` 可能不工作或体验差。项目中已有 `InputDialog` 和 `ConfirmDialog` 组件可复用。

### 7. 重命名操作原子性

**决策**: 将 S3 copy + delete + DB update 包裹在 PDO 事务中，S3 操作失败时回滚 DB 变更

**理由**: 当前 copy 失败仍会更新 DB 文件名（但 path 不变），造成名称与实际路径不一致。

**限制**: S3 操作本身不支持事务，但可以在 copy 失败时直接返回错误不更新 DB；copy 成功但 delete 失败只会留下冗余文件（可接受），DB 已更新为新路径。

## Risks / Trade-offs

- **GET Token 改动影响范围**: `http.ts` 是全局 HTTP 客户端，改 GET Token 逻辑影响所有 GET 请求 → **缓解**: 后端已支持 Header 方式，改动只在前端且向后兼容
- **审批幂等性可能影响并发操作**: 两个管理员同时通过同一文件，第二个会收到错误 → **缓解**: 错误提示清晰（"文件状态已变更，请刷新"），刷新后看到正确状态
- **SQL GROUP BY 可能影响分页计数**: 添加 `GROUP BY d.id` 后 `COUNT` 查询也需对应调整 → **缓解**: COUNT 查询已使用 `COUNT(DISTINCT d.id)`，无需额外修改
- **console.log 清理可能删除有用日志**: 部分 log 在生产调试时有用 → **缓解**: 保留 `console.error` 和关键错误日志，只移除 `console.log` 调试输出
