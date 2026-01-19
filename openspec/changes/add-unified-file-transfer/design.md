# Design: 统一文件传输服务

## Context
当前系统存在以下问题：
1. 文件上传/下载代码分散在多处，维护困难
2. HTTP S3 与 HTTPS 网站之间的 Mixed Content 问题导致上传/下载失败
3. 缺乏统一的进度跟踪机制
4. 内网/外网部署切换需要修改代码

**利益相关者**：
- 开发人员（需要统一的 API）
- 运维人员（需要灵活的部署配置）
- 最终用户（需要可靠的上传/下载体验和进度反馈）

## Goals / Non-Goals

**Goals:**
- 提供统一的文件上传/下载 API，所有端使用相同接口
- 自动检测环境，智能选择直连或代理模式
- 提供实时进度跟踪能力
- 支持大文件分片上传和断点续传
- 配置驱动，通过环境变量控制行为

**Non-Goals:**
- 不实现多 S3 节点负载均衡（后续可扩展）
- 不实现 P2P 传输加速
- 不改变现有的 S3 存储结构

## Decisions

### 1. 环境检测策略
**决定**: 通过比较网站协议和 S3 协议自动选择模式
- 协议一致 → 直连模式（返回预签名 URL）
- 协议不一致 → 代理模式（通过后端转发）

**替代方案**:
- 强制所有请求走代理 - 性能损失大
- 要求 S3 必须 HTTPS - 增加部署复杂度

### 2. 进度跟踪方案
**决定**: 使用文件系统存储进度状态（简单可靠）
- 进度文件存储在 `/tmp/file_transfer_progress/`
- 每次上传分片后更新进度
- 前端定时轮询进度 API

**替代方案**:
- Redis 存储 - 需要额外依赖
- SSE 推送 - 复杂度高
- WebSocket - 复杂度高

### 3. 分片大小
**决定**: 默认 10MB 分片，可配置
- 小于分片阈值的文件直接上传
- 大文件自动分片

### 4. 前端 SDK 设计
**决定**: 提供统一的 `FileTransfer` 类
```javascript
const transfer = new FileTransfer({ apiBase: '/api/' });
transfer.upload(file, options).onProgress(fn).then(result);
transfer.download(url, filename).onProgress(fn).then(result);
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        前端 SDK                                  │
│                   file-transfer.js                               │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                      统一 API 层                                 │
│  file_upload.php  │  file_download.php  │  file_progress.php    │
└─────────────────────────┬───────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────────┐
│                  FileTransferService.php                         │
│  - detectMode()      检测直连/代理模式                           │
│  - upload()          统一上传入口                                │
│  - uploadChunk()     分片上传                                    │
│  - download()        统一下载入口                                │
│  - getProgress()     获取进度                                    │
│  - updateProgress()  更新进度                                    │
└─────────────────────────┬───────────────────────────────────────┘
                          │
         ┌────────────────┼────────────────┐
         ▼                ▼                ▼
   ┌──────────┐    ┌──────────┐    ┌──────────┐
   │ 直连模式 │    │ 代理模式 │    │ 本地存储 │
   │返回预签名│    │后端转发  │    │ (备选)   │
   └──────────┘    └──────────┘    └──────────┘
```

## API Design

### 上传 API
```
POST /api/file_upload.php
Content-Type: multipart/form-data

参数:
- file: 文件数据（小文件直传）
- chunk: 分片数据（大文件分片）
- project_id: 项目ID
- category: 文件分类
- transfer_id: 传输ID（分片上传时必需）
- part_number: 分片编号（分片上传时必需）
- total_parts: 总分片数（首个分片时必需）

响应:
{
  "success": true,
  "data": {
    "transfer_id": "uuid",
    "mode": "direct|proxy",
    "presigned_url": "...",  // 直连模式
    "progress": 50           // 当前进度
  }
}
```

### 下载 API
```
GET /api/file_download.php?url=xxx&filename=xxx&transfer_id=xxx

响应:
- 直连模式: 302 重定向到预签名 URL
- 代理模式: 直接返回文件流
```

### 进度 API
```
GET /api/file_progress.php?transfer_id=xxx

响应:
{
  "success": true,
  "data": {
    "transfer_id": "uuid",
    "status": "uploading|completed|failed",
    "progress": 50,
    "transferred": 5242880,
    "total": 10485760,
    "speed": 1048576,
    "eta": 5
  }
}
```

## Configuration

```php
// storage.php 新增配置
'upload' => [
    // 上传模式: auto=自动检测, proxy=强制代理, direct=强制直连
    'mode' => getenv('UPLOAD_MODE') ?: 'auto',
    
    // 分片阈值（字节），超过此大小使用分片上传
    'chunk_threshold' => 10 * 1024 * 1024, // 10MB
    
    // 分片大小（字节）
    'chunk_size' => 10 * 1024 * 1024, // 10MB
    
    // 进度文件存储路径
    'progress_dir' => '/tmp/file_transfer_progress',
    
    // 进度文件过期时间（秒）
    'progress_ttl' => 86400, // 24小时
],
```

## Risks / Trade-offs

| 风险 | 缓解措施 |
|------|----------|
| 代理模式性能损失 | 内网部署时自动使用直连模式 |
| 进度文件占用磁盘 | 定期清理过期进度文件 |
| 分片上传中断 | 支持断点续传，保留分片状态 |
| 并发上传冲突 | 每个上传分配唯一 transfer_id |

## Migration Plan

1. **Phase 1**: 创建新的统一服务和 API（不影响现有功能）
2. **Phase 2**: 创建前端 SDK
3. **Phase 3**: 逐步迁移各端代码使用新接口
   - 后台 Web
   - 客户门户
   - 桌面端
4. **Phase 4**: 移除旧的上传/下载代码

**回滚方案**: 各端代码保留旧接口调用，通过配置开关切换

## Open Questions

- [ ] 是否需要支持上传限速？
- [ ] 是否需要支持多文件批量上传进度聚合？
- [ ] 桌面端是否需要本地缓存优化？
