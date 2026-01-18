# 技术资源同步客户端

桌面端客户端，用于技术人员同步本地资源文件到云端。

## 技术栈

- **框架**: Tauri v2 + React 18 + TypeScript
- **构建**: Vite
- **样式**: TailwindCSS
- **状态管理**: Zustand + TanStack Query
- **UI 组件**: shadcn/ui (Radix UI)

## 开发环境准备

### 1. 安装依赖

```bash
# 安装 Node.js 依赖
npm install

# 安装 Rust（如果未安装）
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
```

### 2. 初始化 Tauri

```bash
# 初始化 Tauri 项目（首次）
npm run tauri init
```

### 3. 开发模式

```bash
# 启动开发服务器（仅前端）
npm run dev

# 启动 Tauri 开发模式（包含 Rust 后端）
npm run tauri dev
```

### 4. 构建发布

```bash
npm run tauri build
```

## 目录结构

```
desktop/
├── src/
│   ├── components/     # React 组件
│   │   ├── ui/         # 基础 UI 组件
│   │   └── Layout.tsx  # 布局组件
│   ├── pages/          # 页面组件
│   ├── stores/         # Zustand 状态管理
│   ├── hooks/          # 自定义 Hooks
│   ├── lib/            # 工具函数
│   ├── types/          # TypeScript 类型定义
│   ├── App.tsx         # 根组件
│   ├── main.tsx        # 入口文件
│   └── index.css       # 全局样式
├── src-tauri/          # Tauri/Rust 代码（初始化后生成）
├── package.json
├── vite.config.ts
├── tailwind.config.js
└── tsconfig.json
```

## 功能概览

- **登录**: 连接后端服务器并认证
- **群资源**: 按群码浏览和管理资源
- **作品/模型上传**: 分片断点续传上传大文件
- **客户文件下载**: 增量下载客户资料
- **任务队列**: 查看上传/下载进度
- **设置**: 配置同步目录和参数

## 后端 API

客户端调用以下后端 API：

| API | 说明 |
|-----|------|
| `POST /api/desktop_login.php` | 登录 |
| `GET /api/desktop_groups.php` | 群列表 |
| `GET /api/desktop_group_resources.php` | 群资源列表 |
| `POST /api/desktop_upload_init.php` | 初始化分片上传 |
| `POST /api/desktop_upload_part_url.php` | 获取分片预签名 URL |
| `POST /api/desktop_upload_complete.php` | 完成上传 |

## 本地目录规范

根目录下按以下格式命名客户群文件夹：

```
{根目录}/
├── Q2025122001_张三/
│   ├── 作品文件/
│   ├── 模型文件/
│   └── 客户文件/
├── Q2025122002_李四/
│   ├── 作品文件/
│   ├── 模型文件/
│   └── 客户文件/
└── ...
```

- **群码**: 不可变唯一标识（格式 `QYYYYMMDDNN`）
- **群名**: 可变显示名称
- **三固定目录**: `作品文件`、`模型文件`、`客户文件`
