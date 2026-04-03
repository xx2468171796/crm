# CRM 项目部署指南

## 快速部署步骤

### 1. 服务器要求
- PHP 8.1+
- MySQL 8.0+ / MariaDB 10.5+
- Nginx 1.20+
- Composer 2.0+

### 2. Nginx 配置

1. 复制配置模板：
```bash
cp deploy/nginx.conf /etc/nginx/sites-available/crm.conf
```

2. 修改配置（替换以下占位符）：
| 占位符 | 替换为 | 示例 |
|--------|--------|------|
| `YOUR_DOMAIN` | 你的域名 | `crm.example.com` |
| `YOUR_IP` | 服务器 IP | `192.168.1.100` |
| `/path/to/project` | 项目路径 | `/var/www/crm` |

3. 启用配置：
```bash
ln -s /etc/nginx/sites-available/crm.conf /etc/nginx/sites-enabled/
nginx -t
nginx -s reload
```

### 3. CORS 跨域说明

Nginx 配置已内置 CORS 支持，允许以下来源访问：
- `http://tauri.localhost` (Tauri 桌面端)
- `http://localhost:*` (本地开发)
- `http://127.0.0.1:*` (本地开发)
- `http://192.168.*.*:*` (局域网)
- 任意 `Origin` 头指定的来源

### 4. 桌面客户端配置

在桌面客户端登录界面填写：
- **HTTP**: `http://YOUR_IP` 或 `http://YOUR_DOMAIN`
- **HTTPS**: `https://YOUR_DOMAIN` (需要 SSL 证书)

### 5. 环境变量配置

复制 `.env.example` 到 `.env` 并配置：

```env
# 数据库
DB_HOST=localhost
DB_NAME=crm
DB_USER=root
DB_PASS=your_password

# S3/MinIO 存储
S3_ENDPOINT=your-s3-endpoint
S3_BUCKET=your-bucket
S3_ACCESS_KEY=your-access-key
S3_SECRET_KEY=your-secret-key
S3_USE_HTTPS=1  # 公网使用 1，内网使用 0

# JWT 密钥
JWT_SECRET=your-jwt-secret
```

### 6. 文件权限

```bash
# 设置权限
chown -R www-data:www-data /var/www/crm
chmod -R 755 /var/www/crm
chmod -R 777 /var/www/crm/storage
```

### 7. 常见问题

#### CORS 错误
确保 Nginx 配置正确处理 OPTIONS 预检请求，并返回正确的 CORS 头。

#### 连接失败
1. 检查防火墙是否开放 80/443 端口
2. 检查 Nginx 是否监听正确的 IP
3. 使用 `curl -I http://YOUR_IP/api/auth/me.php` 测试

#### 桌面端无法登录
1. 确认服务器地址填写正确（包含 `http://` 或 `https://`）
2. 检查网络连通性：`ping YOUR_IP`
3. 查看浏览器控制台错误信息

---

## 文件结构

```
deploy/
├── nginx.conf      # Nginx 配置模板
└── README.md       # 本文档

core/
└── cors.php        # CORS 处理模块（已集成到 API）
```
