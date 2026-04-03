# 桌面端打包规范 - Linux 环境（交叉编译）

> ⚠️ **本文档适用于 Linux 环境**，用于交叉编译生成 Windows 可执行文件
> 
> 如果在 **Windows 环境** 下打包，请参考 `BUILD-WINDOWS.md`

## 快速打包（推荐）

```bash
# 进入当前项目的 desktop 目录
cd <项目根目录>/desktop

# ⚠️ 重要：每次打包前必须更新版本号！
# 修改 package.json 和 src-tauri/tauri.conf.json 中的 version 字段
# 版本号格式：主版本.次版本.修订号 (如 1.6.81)

# 安装依赖（首次或依赖更新后）
npm install

# Windows 交叉编译（生成免安装exe）
npm run tauri build -- --target x86_64-pc-windows-gnu

# 复制到输出目录（带版本号命名）
VERSION=$(node -p "require('./package.json').version")
cp src-tauri/target/x86_64-pc-windows-gnu/release/tech-resource-sync.exe ../output/tech-resource-sync-v${VERSION}.exe

# 输出文件位置
# 免安装exe: <项目根目录>/output/tech-resource-sync-v{版本号}.exe
# 安装包:    <项目根目录>/desktop/src-tauri/target/x86_64-pc-windows-gnu/release/bundle/nsis/项目管理工具_{版本号}_x64-setup.exe
```

## 输出位置

所有打包文件复制到项目根目录下的 output 文件夹：
```
<项目根目录>/output/
```

文件命名规则：
- 免安装版：`tech-resource-sync.exe` 或 `tech-resource-sync-v{版本号}.exe`
- 安装包：`项目管理工具_{版本号}_x64-setup.exe`

## 完整打包流程

### 1. 准备环境（首次）

```bash
# 安装 mingw-w64 交叉编译工具链
apt-get install -y mingw-w64

# 安装 Rust Windows 目标
rustup target add x86_64-pc-windows-gnu

# 安装 libxdo（Linux原生构建需要）
apt-get install -y libxdo-dev
```

### 2. 配置交叉编译

确保 `desktop/src-tauri/.cargo/config.toml` 包含：

```toml
[target.x86_64-pc-windows-gnu]
linker = "x86_64-w64-mingw32-gcc"
ar = "x86_64-w64-mingw32-ar"
```

### 3. 构建步骤

```bash
# 进入当前项目的桌面端目录
cd <项目根目录>/desktop

# 安装 npm 依赖
npm install

# 构建 Windows exe（交叉编译）
npm run tauri build -- --target x86_64-pc-windows-gnu

# 复制到输出目录
cp src-tauri/target/x86_64-pc-windows-gnu/release/tech-resource-sync.exe ../output/
```

### 4. 更新版本号（可选）

修改以下文件中的版本号：
- `desktop/package.json` → `version` 字段
- `desktop/src-tauri/tauri.conf.json` → `version` 字段

## 环境要求

| 依赖 | 版本 | 用途 |
|------|------|------|
| Node.js | 18+ | 前端构建 |
| Rust | 1.70+ | Tauri 后端 |
| mingw-w64 | 10+ | Windows 交叉编译 |
| libxdo-dev | - | Linux 原生构建 |

## 注意事项

1. **交叉编译限制**：Linux 交叉编译可生成 exe 和 NSIS 安装包，但无法签名
2. **签名要求**：如需签名，需在 Windows 环境运行构建
3. **代码提交**：打包前确保代码已提交到 Git，避免丢失更改
4. **前端资源**：构建命令会自动打包前端资源

## 版本发布流程

1. 修改功能代码并测试
2. 更新版本号（package.json + tauri.conf.json）
3. 提交代码到 Git
4. 执行打包命令
5. 复制 exe 到 output 目录
6. 测试 exe 运行正常
7. 发布给用户

## 故障排除

### 缺少 libxdo
```bash
apt-get install -y libxdo-dev
```

### 交叉编译链接错误
确保 `.cargo/config.toml` 配置正确，并已安装 mingw-w64

### npm 依赖问题
```bash
rm -rf node_modules package-lock.json
npm install
```
