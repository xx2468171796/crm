#!/bin/bash
# Windows 桌面端打包脚本
# 使用方法: ./scripts/build-windows.sh [版本号]
# 示例: ./scripts/build-windows.sh 1.6.72

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
OUTPUT_DIR="/opt/1panel/www/sites/192.168.110.25101-11/output"

# 获取版本号
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep '"version"' "$PROJECT_DIR/package.json" | head -1 | sed 's/.*"version": "\([^"]*\)".*/\1/')
fi

echo "=========================================="
echo "  Windows 桌面端打包"
echo "  版本: $VERSION"
echo "=========================================="

cd "$PROJECT_DIR"

# 1. 更新 package.json 和 tauri.conf.json 版本号
echo "[1/5] 更新版本号..."
sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"$VERSION\"/" package.json
sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"$VERSION\"/" src-tauri/tauri.conf.json

# 2. 安装依赖
echo "[2/5] 检查依赖..."
if [ ! -d "node_modules" ]; then
    npm install
fi

# 3. 构建前端
echo "[3/5] 构建前端..."
npm run build

# 4. 构建 Windows exe (使用 tauri build 以正确嵌入前端资源)
echo "[4/5] 构建 Windows exe (交叉编译)..."
# 重要: 必须使用 tauri build 而不是 cargo build
# cargo build 不会嵌入前端资源到 exe 中
# tauri build 会正确将 dist 目录的前端资源嵌入到 exe
npx tauri build --target x86_64-pc-windows-gnu 2>&1 | grep -v "failed to bundle" || true
# 注意: NSIS 安装包创建会失败(Linux不支持),但 exe 已正确生成

# 5. 复制到输出目录
echo "[5/5] 复制到输出目录..."
mkdir -p "$OUTPUT_DIR"
EXE_NAME="tech-resource-sync-v${VERSION}.exe"
cp "src-tauri/target/x86_64-pc-windows-gnu/release/tech-resource-sync.exe" "$OUTPUT_DIR/$EXE_NAME"

# 复制 WebView2Loader.dll (Windows 运行必需)
DLL_FILE="src-tauri/target/x86_64-pc-windows-gnu/release/WebView2Loader.dll"
if [ -f "$DLL_FILE" ]; then
    cp "$DLL_FILE" "$OUTPUT_DIR/WebView2Loader.dll"
    echo "  已复制 WebView2Loader.dll"
fi

echo ""
echo "=========================================="
echo "  打包完成!"
echo "  输出文件: $OUTPUT_DIR/$EXE_NAME"
echo "  文件大小: $(ls -lh "$OUTPUT_DIR/$EXE_NAME" | awk '{print $5}')"
if [ -f "$OUTPUT_DIR/WebView2Loader.dll" ]; then
    echo "  DLL文件: $OUTPUT_DIR/WebView2Loader.dll ($(ls -lh "$OUTPUT_DIR/WebView2Loader.dll" | awk '{print $5}'))"
    echo ""
    echo "  注意: 需要将 exe 和 dll 放在同一目录运行"
fi
echo "=========================================="
