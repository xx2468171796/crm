#!/bin/bash
# WebSocket 服务部署脚本 (Linux)
# 使用方法: sudo bash deploy_websocket.sh

# 配置
PROJECT_PATH="/var/www/crmchonggou/index"
SERVICE_NAME="websocket"
PHP_PATH="/usr/bin/php"

echo "=== WebSocket 服务部署 ==="

# 1. 复制服务文件
echo "1. 安装 systemd 服务..."
sudo cp ${PROJECT_PATH}/scripts/websocket.service /etc/systemd/system/${SERVICE_NAME}.service

# 2. 修改路径（如果需要）
# sudo sed -i "s|/var/www/crmchonggou/index|${PROJECT_PATH}|g" /etc/systemd/system/${SERVICE_NAME}.service

# 3. 重新加载 systemd
echo "2. 重新加载 systemd..."
sudo systemctl daemon-reload

# 4. 启用开机自启
echo "3. 启用开机自启..."
sudo systemctl enable ${SERVICE_NAME}

# 5. 启动服务
echo "4. 启动服务..."
sudo systemctl start ${SERVICE_NAME}

# 6. 检查状态
echo "5. 检查服务状态..."
sudo systemctl status ${SERVICE_NAME}

echo ""
echo "=== 常用命令 ==="
echo "启动: sudo systemctl start ${SERVICE_NAME}"
echo "停止: sudo systemctl stop ${SERVICE_NAME}"
echo "重启: sudo systemctl restart ${SERVICE_NAME}"
echo "状态: sudo systemctl status ${SERVICE_NAME}"
echo "日志: sudo tail -f /var/log/websocket.log"
