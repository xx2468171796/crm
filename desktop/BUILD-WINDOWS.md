# Windows 环境桌面端打包规范

> 本文档适用于在 **Windows 环境** 下打包桌面端应用
> 
> 如果在 **Linux 环境** 下交叉编译，请参考 `BUILD.md`

## 快速打包命令

```powershell
# 1. 进入当前项目的桌面端目录
cd <项目根目录>\desktop

# 2. 拉取最新代码
git pull origin master

# 3. 安装依赖（首次或依赖更新后）
npm install

# 4. 执行打包
npm run tauri build

# 5. 打包产物位置
# 免安装exe: <项目根目录>\desktop\src-tauri\target\release\tech-resource-sync.exe
# 安装包:    <项目根目录>\desktop\src-tauri\target\release\bundle\nsis\项目管理工具_x.x.x_x64-setup.exe
```

## 详细打包流程

### 第一步：拉取最新代码

```powershell
cd <项目根目录>
git pull origin master
```

### 第二步：进入桌面端目录

```powershell
cd desktop
```

### 第三步：安装依赖

```powershell
npm install
```

### 第四步：执行打包

```powershell
npm run tauri build
```

此命令会：
1. 自动递增版本号（通过 prebuild 脚本）
2. 构建前端资源（vite build）
3. 编译 Rust 后端
4. 生成 NSIS 安装包

### 第五步：复制到输出目录

```powershell
# 获取版本号
$version = (Get-Content package.json | ConvertFrom-Json).version

# 创建输出目录
if (!(Test-Path "..\output")) { New-Item -ItemType Directory -Path "..\output" }

# 复制免安装exe
Copy-Item "src-tauri\target\release\tech-resource-sync.exe" "..\output\tech-resource-sync-v$version.exe"

# 复制安装包（如果存在）
$nsisPath = Get-ChildItem "src-tauri\target\release\bundle\nsis\*.exe" -ErrorAction SilentlyContinue
if ($nsisPath) {
    Copy-Item $nsisPath.FullName "..\output\"
}
```

## 输出文件

| 类型 | 文件路径 | 说明 |
|------|----------|------|
| 免安装exe | `<项目根目录>\output\tech-resource-sync-v{版本号}.exe` | 直接运行，无需安装 |
| 安装包 | `<项目根目录>\output\项目管理工具_{版本号}_x64-setup.exe` | NSIS安装程序 |

## 版本号管理

版本号在以下文件中定义：
- `desktop/package.json` → `version` 字段
- `desktop/src-tauri/tauri.conf.json` → `version` 字段

**注意**：`npm run tauri build` 会自动通过 `prebuild` 脚本递增 `package.json` 中的版本号。

## 环境要求

| 依赖 | 版本要求 | 安装方式 |
|------|----------|----------|
| Node.js | 18+ | https://nodejs.org |
| Rust | 1.70+ | https://rustup.rs |
| Visual Studio Build Tools | 2019+ | https://visualstudio.microsoft.com/downloads/ |

### 安装 Rust

```powershell
# 下载并运行 rustup-init.exe
# https://win.rustup.rs/x86_64

# 验证安装
rustc --version
cargo --version
```

### 安装 Visual Studio Build Tools

下载并安装 Visual Studio Build Tools，选择 "C++ 桌面开发" 工作负载。

## 配置文件说明

### tauri.conf.json 打包配置

```json
{
  "bundle": {
    "active": true,
    "targets": ["nsis"],  // 只生成NSIS安装包，避免MSI错误
    "icon": [
      "icons/32x32.png",
      "icons/128x128.png",
      "icons/128x128@2x.png",
      "icons/icon.ico"
    ],
    "windows": {
      "certificateThumbprint": null,
      "digestAlgorithm": "sha256",
      "timestampUrl": ""
    }
  }
}
```

## 常见问题

### 1. MSI 打包失败

**错误**: `failed to run light.exe`

**解决**: 修改 `tauri.conf.json`，将 `targets` 改为只使用 `nsis`：
```json
"targets": ["nsis"]
```

### 2. Rust 编译错误

**解决**: 确保已安装 Visual Studio Build Tools 和 C++ 工具链

### 3. npm 依赖问题

```powershell
# 清理并重新安装
Remove-Item -Recurse -Force node_modules
Remove-Item package-lock.json
npm install
```

### 4. 版本号不一致

确保 `package.json` 和 `tauri.conf.json` 中的版本号一致。

## 一键打包脚本

创建 `build-windows.ps1`（放在项目根目录）：

```powershell
# Windows 一键打包脚本
$ErrorActionPreference = "Stop"

Write-Host "=== 开始打包 ===" -ForegroundColor Green

# 获取脚本所在目录作为项目根目录
$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

# 进入桌面端目录
Set-Location "$ProjectRoot\desktop"

# 拉取代码
Write-Host "拉取最新代码..." -ForegroundColor Yellow
git pull origin master

# 安装依赖
Write-Host "安装依赖..." -ForegroundColor Yellow
npm install

# 打包
Write-Host "开始打包..." -ForegroundColor Yellow
npm run tauri build

# 获取版本号
$version = (Get-Content package.json | ConvertFrom-Json).version

# 复制到输出目录
Write-Host "复制到输出目录..." -ForegroundColor Yellow
if (!(Test-Path "$ProjectRoot\output")) { New-Item -ItemType Directory -Path "$ProjectRoot\output" }
Copy-Item "src-tauri\target\release\tech-resource-sync.exe" "$ProjectRoot\output\tech-resource-sync-v$version.exe"

$nsisPath = Get-ChildItem "src-tauri\target\release\bundle\nsis\*.exe" -ErrorAction SilentlyContinue
if ($nsisPath) {
    Copy-Item $nsisPath.FullName "$ProjectRoot\output\"
}

Write-Host "=== 打包完成 ===" -ForegroundColor Green
Write-Host "版本: $version" -ForegroundColor Cyan
Write-Host "输出目录: $ProjectRoot\output\" -ForegroundColor Cyan
```

运行脚本：
```powershell
.\build-windows.ps1
```

## 发布流程

1. 修改功能代码并测试
2. 提交代码到 Git
3. 执行打包命令 `npm run tauri build`
4. 复制产物到 output 目录
5. 测试 exe 运行正常
6. 发布给用户
