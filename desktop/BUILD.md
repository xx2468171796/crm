# 桌面端打包规范

## 打包命令

```bash
# 在 desktop 目录下执行
./scripts/build-windows.sh [版本号]

# 示例
./scripts/build-windows.sh 1.6.72
./scripts/build-windows.sh          # 使用 package.json 中的版本
```

## 输出位置

所有打包文件输出到：
```
当前项目文件夹/output
```

文件命名规则：`tech-resource-sync-v{版本号}.exe`

## 打包流程

1. **更新版本号** - 自动更新 package.json 和 tauri.conf.json
2. **安装依赖** - 检查并安装 npm 依赖
3. **构建前端** - `npm run build` 生成 dist/
4. **构建 exe** - 交叉编译 Windows 可执行文件
5. **复制输出** - 复制到统一输出目录

## 环境要求

- Node.js 18+
- Rust 1.70+
- mingw-w64 (交叉编译工具链)
- rustup target: x86_64-pc-windows-gnu

## 注意事项

1. Linux 交叉编译只能生成裸 exe，无法生成 NSIS/MSI 安装包
2. 如需安装包，需在 Windows 环境运行 `npm run tauri build`
3. 打包前确保代码已提交，避免丢失更改
4. 注意要打包前端资源页面。

## 版本发布流程

1. 修改功能代码
2. 测试功能正常
3. 更新版本号并打包: `./scripts/build-windows.sh 1.x.x`
4. 测试 exe 运行正常
5. 发布给用户
