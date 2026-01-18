# 1Panel + OpenResty 生产环境部署指南

## 目标环境

| 组件 | 版本 | 说明 |
|------|------|------|
| 操作系统 | Debian 12 LTS | 通用生产环境 Linux 发行版 |
| 面板 | 1Panel | Web 管理面板 |
| Web服务器 | OpenResty (Nginx) | 反向代理、负载均衡、HTTPS |
| PHP | 8.1+ | 推荐 8.2 或 8.3 |
| 数据库 | MySQL 8.0+ | 字符集 utf8mb4 |
| 对象存储 | MinIO / S3 兼容 | 文件存储 |

---

## 1. 项目结构

```
/www/wwwroot/your-domain/
├── api/                    # PHP API 文件
├── core/                   # 核心库
├── public/                 # 静态资源和入口
├── services/               # 业务服务
├── config/
│   ├── app.php             # 应用配置（需要修改）
│   └── storage.php         # 存储配置（需要修改）
├── scripts/                # 脚本
└── .htaccess               # Apache 配置（如果使用）
```

---

## 2. 环境配置

### 2.1 创建配置文件

```bash
# 复制配置模板
cp config/app.example.php config/app.php
cp config/storage.example.php config/storage.php
```

### 2.2 修改 config/app.php

```php
<?php
return [
    'db' => [
        'host' => '127.0.0.1',      // 数据库主机
        'port' => 3306,
        'dbname' => 'your_database',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret' => 'your-jwt-secret-key-change-this',
        'expire' => 86400 * 7,  // 7天
    ],
];
```

### 2.3 修改 config/storage.php

```php
<?php
return [
    'type' => 's3',
    's3' => [
        'endpoint' => 'https://your-minio-endpoint.com',
        'region' => 'cn-default',
        'bucket' => 'your-bucket',
        'access_key' => 'your-access-key',
        'secret_key' => 'your-secret-key',
        'use_path_style_endpoint' => true,
        'prefix' => '',
    ],
];
```

---

## 3. OpenResty (Nginx) 配置

在 1Panel 中创建网站，配置文件参考：

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name your-domain.com;
    
    root /www/wwwroot/your-domain/public;
    index index.php index.html;
    
    # SSL 配置（1Panel 自动生成）
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    # CORS 配置 - 允许桌面端访问
    location /api/ {
        # CORS 头
        add_header 'Access-Control-Allow-Origin' $http_origin always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, X-Requested-With' always;
        add_header 'Access-Control-Allow-Credentials' 'true' always;
        
        # 预检请求
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' $http_origin;
            add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, DELETE, OPTIONS';
            add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type, X-Requested-With';
            add_header 'Access-Control-Allow-Credentials' 'true';
            add_header 'Content-Length' 0;
            add_header 'Content-Type' 'text/plain';
            return 204;
        }
        
        # PHP 处理
        try_files $uri $uri/ /api/index.php?$query_string;
        
        location ~ \.php$ {
            fastcgi_pass unix:/tmp/php-cgi-81.sock;  # 根据 1Panel PHP 版本调整
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    
    # 静态资源
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-81.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 3600;  # 长时间运行的请求（如文件上传）
    }
    
    # 禁止访问敏感文件
    location ~ /\.(git|env|htaccess) {
        deny all;
    }
    
    location ~ /config/ {
        deny all;
    }
}
```

---

## 4. 数据库初始化

```bash
# 导入完整数据库结构
mysql -u your_username -p your_database < database_complete_schema_v3.sql
```

---

## 5. PHP 扩展要求

确保安装以下 PHP 扩展：

```bash
# 1Panel 中安装 PHP 扩展
php -m | grep -E "pdo|mysql|json|curl|mbstring|openssl"

# 必需扩展
- pdo
- pdo_mysql
- json
- curl
- mbstring
- openssl
- fileinfo
```

---

## 6. 桌面端配置

桌面端需要配置服务器地址：

```
服务器地址: https://your-domain.com
```

---

## 7. 部署检查清单

### 上线前检查

- [ ] 配置文件已创建并填写正确
- [ ] 数据库连接正常
- [ ] S3/MinIO 连接正常
- [ ] CORS 配置正确（桌面端可访问）
- [ ] HTTPS 证书已配置
- [ ] PHP 扩展已安装
- [ ] 目录权限正确（777 for uploads）

### 验证命令

```bash
# 检查 PHP 语法
find api/ -name "*.php" -exec php -l {} \;

# 测试 API 连通性
curl -I https://your-domain.com/api/desktop_login.php

# 测试 CORS
curl -H "Origin: http://tauri.localhost" \
     -H "Access-Control-Request-Method: POST" \
     -X OPTIONS \
     https://your-domain.com/api/desktop_login.php
```

---

## 8. 常见问题

### Q: 502 Bad Gateway
- 检查 PHP-FPM 是否运行：`systemctl status php81-fpm`
- 重启 PHP-FPM：`systemctl restart php81-fpm`
- 检查 fastcgi_pass 路径是否正确
- 检查 PHP-FPM 日志：`tail -f /var/log/php81-fpm.log`

### Q: 开发环境 Laragon PHP-CGI 崩溃
- 在 Laragon 托盘右键 → "Stop All" → "Start All"
- 或运行 `laragon.exe reload`
- PHP-CGI 不如 PHP-FPM 稳定，生产环境务必使用 PHP-FPM

### Q: CORS 错误
- 检查 Nginx CORS 配置
- 确保 `add_header` 使用 `always` 参数

### Q: 数据库连接失败
- 检查 config/app.php 配置
- 检查 MySQL 用户权限

### Q: 文件上传失败
- 检查 S3/MinIO 配置
- 检查网络连通性
- 检查 Bucket 权限
