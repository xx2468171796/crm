# File Transfer Capability

## ADDED Requirements

### Requirement: Unified Upload API
系统 SHALL 提供统一的文件上传 API (`/api/file_upload.php`)，支持所有客户端（后台Web、桌面端、客户门户）使用相同的接口上传文件。

#### Scenario: 小文件直接上传成功
- **WHEN** 用户上传小于分片阈值的文件
- **THEN** 系统直接处理上传并返回成功结果
- **AND** 返回文件存储路径和传输ID

#### Scenario: 大文件分片上传成功
- **WHEN** 用户上传大于分片阈值的文件
- **THEN** 系统自动进行分片上传
- **AND** 每个分片上传后更新进度
- **AND** 所有分片完成后合并文件

### Requirement: Unified Download API
系统 SHALL 提供统一的文件下载 API (`/api/file_download.php`)，根据环境自动选择直连或代理模式。

#### Scenario: HTTPS 环境下载 HTTP S3 文件
- **WHEN** 网站使用 HTTPS 而 S3 使用 HTTP
- **THEN** 系统通过代理模式下载文件
- **AND** 返回正确的 Content-Type 和 Content-Disposition 头

#### Scenario: 协议一致环境直连下载
- **WHEN** 网站和 S3 使用相同协议
- **THEN** 系统返回预签名 URL 进行直连下载

### Requirement: Progress Tracking
系统 SHALL 提供进度跟踪 API (`/api/file_progress.php`)，支持实时查询上传/下载进度。

#### Scenario: 查询上传进度
- **WHEN** 客户端请求传输进度
- **THEN** 系统返回当前进度百分比、已传输字节数、总字节数
- **AND** 返回预估剩余时间和传输速度

#### Scenario: 传输完成状态
- **WHEN** 传输完成后查询进度
- **THEN** 系统返回 completed 状态
- **AND** 返回最终文件信息

### Requirement: Environment Auto-Detection
系统 SHALL 自动检测运行环境，智能选择直连或代理模式。

#### Scenario: 检测到协议不一致
- **WHEN** 网站协议与 S3 协议不一致（如 HTTPS 网站 + HTTP S3）
- **THEN** 系统自动使用代理模式

#### Scenario: 检测到协议一致
- **WHEN** 网站协议与 S3 协议一致
- **THEN** 系统自动使用直连模式

#### Scenario: 强制代理模式
- **WHEN** 配置 UPLOAD_MODE=proxy
- **THEN** 系统强制使用代理模式，忽略自动检测

### Requirement: Frontend SDK
系统 SHALL 提供前端 JavaScript SDK (`file-transfer.js`)，封装上传/下载逻辑并提供统一的进度回调。

#### Scenario: SDK 上传文件
- **WHEN** 调用 `transfer.upload(file, options)`
- **THEN** SDK 自动处理小文件直传或大文件分片
- **AND** 通过 onProgress 回调返回实时进度

#### Scenario: SDK 下载文件
- **WHEN** 调用 `transfer.download(url, filename)`
- **THEN** SDK 自动处理下载
- **AND** 通过 onProgress 回调返回下载进度

### Requirement: Configuration Driven
系统 SHALL 支持通过配置文件和环境变量控制文件传输行为。

#### Scenario: 配置分片阈值
- **WHEN** 设置 `upload.chunk_threshold` 配置
- **THEN** 系统使用配置的阈值判断是否分片上传

#### Scenario: 配置上传模式
- **WHEN** 设置 `UPLOAD_MODE` 环境变量为 auto/proxy/direct
- **THEN** 系统使用指定的上传模式
