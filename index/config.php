<?php
// ============================================
// ANKOTTI CRM 系统配置文件
// ============================================
// 部署时请修改此文件中的数据库配置
// ============================================

return [
    // ============================================
    // 数据库配置（支持环境变量，方便部署）
    // ============================================
    // 环境变量说明：
    // DB_HOST     - 数据库主机地址
    // DB_PORT     - 数据库端口（默认3306）
    // DB_DATABASE - 数据库名称
    // DB_USERNAME - 数据库用户名
    // DB_PASSWORD - 数据库密码
    // ============================================
    'db' => [
        'host'     => getenv('DB_HOST') ?: '188.209.141.219',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'dbname'   => getenv('DB_DATABASE') ?: 'crm20260116',
        'username' => getenv('DB_USERNAME') ?: 'crm20260116',
        'password' => getenv('DB_PASSWORD') ?: 'JKHAiinysrhR2YWM',
        'charset'  => 'utf8mb4',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ],
    ],

    // 应用配置
    'app' => [
        'debug'        => true,
        'timezone'     => 'Asia/Shanghai',
        'session_name' => 'ankotti_crm',
    ],
    
    // 默认管理员配置
    'admin' => [
        'username' => 'admin',
        'password' => '123456',  // 首次安装后请立即修改
        'realname' => '系统管理员',
        'email'    => 'admin@example.com',
    ],
    
    // URL配置
    'url' => [
        // 基础URL - 留空表示自动检测（推荐）
        // 自动检测会根据当前访问的域名/IP和端口自动适配
        // 例如：http://192.168.110.252:886 或 https://yourdomain.com
        'base_url'     => '',
        
        // 路径配置 - 使用绝对路径，不依赖域名
        'api_path'     => '/api',
        'js_path'      => '/js',
        'css_path'     => '/css',
        'upload_path'  => '/uploads',
    ],

    // 安全配置
    'security' => [
        // 允许跨域访问的 Origin 白名单（逗号分隔）
        // 为空表示仅允许同源（推荐生产环境保持为空，避免跨站携带 Cookie 调用 API）
        'cors_allow_origins' => array_values(array_filter(array_map('trim', explode(',', getenv('CORS_ALLOW_ORIGINS') ?: '')))),
    ],
    
    // ============================================
    // WebSocket 实时通知配置
    // ============================================
    'websocket' => [
        // WebSocket 服务端口
        'port' => 8300,
        
        // HTTP 推送接口端口（内部使用）
        'push_port' => 8301,
        
        // WebSocket 服务主机（服务端监听地址）
        // 0.0.0.0 表示监听所有网卡
        'host' => '0.0.0.0',
        
        // 客户端连接地址（留空表示自动检测）
        // 自动检测会根据当前访问的域名/IP自动适配
        // 如需指定，填写完整地址如：ws://192.168.110.246:8300
        'client_url' => '',
        
        // 是否启用 WebSocket 服务
        'enabled' => true,
    ],
];
