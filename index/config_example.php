<?php
// 配置文件示例
// 请根据实际情况修改 config.php

return [
    // 数据库配置
    'db' => [
        // 如果数据库在本地服务器，使用：
        'dsn'      => 'mysql:host=localhost;port=3306;dbname=t4;charset=utf8mb4',
        // 或者使用127.0.0.1：
        // 'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=t4;charset=utf8mb4',
        
        // 如果数据库在远程服务器（当前配置）：
        // 'dsn'      => 'mysql:host=192.168.110.252;port=3306;dbname=t4;charset=utf8mb4',
        
        'username' => 't4',
        'password' => 'xx123654',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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
        'password' => '123456',
        'realname' => '系统管理员',
        'email'    => 'admin@example.com',
    ],
    
    // URL配置
    'url' => [
        'base_url'     => '',
        'api_path'     => '/api',
        'js_path'      => '/js',
        'css_path'     => '/css',
        'upload_path'  => '/uploads',
    ],
];
