# 统一文件传输服务

## 概述

统一文件传输服务提供了一个统一的上传/下载接口，自动检测环境选择直连或代理模式，支持小文件直传、大文件分片上传、实时进度跟踪。

## 架构

```
前端 SDK (file-transfer.js)
         │
         ▼
统一 API (file_transfer.php)
         │
         ▼
FileTransferService.php
         │
    ┌────┴────┐
    ▼         ▼
直连模式   代理模式
(预签名URL) (后端转发)
    │         │
    └────┬────┘
         ▼
       S3 存储
```

## 环境自适应

系统自动检测运行环境，智能选择传输模式：

| 网站协议 | S3 协议 | 选择模式 |
|----------|---------|----------|
| HTTPS    | HTTPS   | 直连     |
| HTTP     | HTTP    | 直连     |
| HTTPS    | HTTP    | **代理** |
| HTTP     | HTTPS   | 直连     |

可通过环境变量 `UPLOAD_MODE` 强制指定模式：
- `auto` - 自动检测（默认）
- `proxy` - 强制代理
- `direct` - 强制直连

## 配置

在 `config/storage.php` 中配置：

```php
'upload' => [
    'mode' => getenv('UPLOAD_MODE') ?: 'auto',
    'chunk_threshold' => 10 * 1024 * 1024, // 10MB，超过此大小分片上传
    'chunk_size' => 10 * 1024 * 1024,      // 10MB 分片大小
    'progress_dir' => '/tmp/file_transfer_progress',
    'progress_ttl' => 86400,               // 进度文件24小时过期
],
```

## 前端 SDK 使用

### 引入

```html
<script src="/js/file-transfer.js"></script>
```

### 初始化

```javascript
const transfer = new FileTransfer({
    apiBase: '/api/',           // API 基础路径
    chunkThreshold: 10485760,   // 分片阈值（可选）
    maxRetries: 3,              // 重试次数（可选）
    progressInterval: 500       // 进度轮询间隔（可选）
});
```

### 上传文件

```javascript
// 基本上传
transfer.upload(file, { storageKey: 'path/to/file.ext' })
    .then(result => console.log('完成', result))
    .catch(err => console.error('失败', err));

// 带进度回调
transfer.upload(file, { storageKey: 'path/to/file.ext' })
    .onProgress((info) => {
        console.log(`${info.progress}%`);
        console.log(`${info.transferredFormatted} / ${info.totalFormatted}`);
        console.log(`速度: ${info.speedFormatted}`);
    })
    .onComplete((result) => {
        console.log('上传完成', result);
    })
    .onError((err) => {
        console.error('上传失败', err);
    });
```

### 下载文件

```javascript
transfer.download(url, 'filename.ext')
    .onProgress((info) => {
        console.log(`下载进度: ${info.progress}%`);
    })
    .then(() => console.log('下载完成'));
```

### 获取传输模式

```javascript
const modeInfo = await transfer.getMode();
console.log('当前模式:', modeInfo.mode); // 'direct' 或 'proxy'
```

## API 接口

### 获取模式
```
GET /api/file_transfer.php?action=mode

响应: { "success": true, "data": { "mode": "proxy", "site_https": true } }
```

### 初始化上传
```
POST /api/file_transfer.php?action=init
Content-Type: application/json

{ "filename": "test.psd", "filesize": 189430648, "storage_key": "groups/xxx/test.psd", "mime_type": "application/octet-stream" }

响应: { "success": true, "data": { "transfer_id": "xxx", "mode": "proxy", "chunked": true, "upload_id": "xxx", "total_parts": 19, "chunk_size": 10485760 } }
```

### 上传分片（代理模式）
```
POST /api/file_transfer.php?action=chunk&transfer_id=xxx&part_number=1
Content-Type: application/octet-stream

[分片二进制数据]

响应: { "success": true, "data": { "part_number": 1, "etag": "xxx", "progress": 5 } }
```

### 完成上传
```
POST /api/file_transfer.php?action=complete
Content-Type: application/json

{ "transfer_id": "xxx" }

响应: { "success": true, "data": { "success": true, "storage_key": "groups/xxx/test.psd" } }
```

### 查询进度
```
GET /api/file_transfer.php?action=progress&transfer_id=xxx

响应: { "success": true, "data": { "status": "uploading", "progress": 50, "transferred": 94715324, "total_size": 189430648, "speed": 5242880, "eta": 18 } }
```

### 代理下载
```
GET /api/file_transfer.php?action=download&url=xxx&filename=test.psd

响应: 文件流
```

## 进度信息结构

```javascript
{
    transferId: "xxx",
    filename: "test.psd",
    status: "uploading",        // pending | uploading | completed | failed
    progress: 50,               // 0-100
    transferred: 94715324,      // 已传输字节
    total: 189430648,           // 总字节
    speed: 5242880,             // 字节/秒
    eta: 18,                    // 预计剩余秒数
    speedFormatted: "5 MB/s",
    transferredFormatted: "90.3 MB",
    totalFormatted: "180.6 MB"
}
```

## 错误处理

SDK 内置重试机制，默认重试3次。可通过配置调整：

```javascript
const transfer = new FileTransfer({
    maxRetries: 5,      // 最大重试次数
    retryDelay: 1000    // 重试延迟（毫秒）
});
```

## 取消上传

```javascript
const task = transfer.upload(file, options);

// 稍后取消
task.abort();
```

## 兼容性

- 现代浏览器（Chrome, Firefox, Safari, Edge）
- 需要 Fetch API 和 Promise 支持
- 回退：如果 FileTransfer SDK 未加载，resource-center.js 会使用旧的上传逻辑
