<?php
/**
 * StorageProvider 配置
 *
 * - type: 当前使用的存储驱动(local|s3)
 * - limits: 上传约束(单文件大小/允许类型)
 * - local: 本地磁盘配置
 * - s3: AWS S3 或兼容对象存储配置
 */
return [
    'type' => getenv('STORAGE_DRIVER') ?: 's3',

    'limits' => [
        'max_single_size' => 2 * 1024 * 1024 * 1024, // 2GB
        'max_customer_total' => 10 * 1024 * 1024 * 1024, // 10GB, 单客户累计
        'allowed_extensions' => [], // 空数组表示不限制扩展名
        'preview_mimes' => [
            'image/jpeg','image/png','image/gif','image/webp','image/bmp',
            'application/pdf',
            'video/mp4','video/quicktime','video/x-msvideo','video/webm',
            'audio/mpeg','audio/mp3','audio/wav','audio/ogg','audio/webm','audio/x-m4a','audio/mp4','audio/aac','audio/flac'
        ],
        'folder_upload' => [
            'max_files' => 500,
            'max_total_bytes' => 2 * 1024 * 1024 * 1024, // 2GB
            'max_depth' => 5,
            'max_segment_length' => 40,
        ],
        'zip_download' => [
            'max_files' => 800,
            'max_total_bytes' => 5 * 1024 * 1024 * 1024, // 5GB
        ],
    ],

    'local' => [
        'root' => dirname(__DIR__) . '/uploads/storage',
        'base_url' => '/uploads/storage',
    ],

    's3' => [
        // ============================================
        // 中国云存储服务配置示例（请根据实际情况选择）
        // ============================================
        // 
        // 方式1: MinIO（自建对象存储，当前配置）
        // 'endpoint'       => 'http://192.168.110.246:9000',
        // 'region'         => 'cn-default',
        // 'use_path_style' => true,
        // 'use_https'      => false,  // 内网通常用 HTTP
        //
        // 方式2: 阿里云 OSS（推荐国内使用）
        // 'endpoint'       => 'oss-cn-hangzhou.aliyuncs.com',  // 根据你的区域修改: cn-hangzhou, cn-beijing, cn-shanghai
        // 'region'         => 'cn-hangzhou',
        // 'use_path_style' => false,  // 阿里云 OSS 使用虚拟主机样式
        // 'use_https'      => true,
        //
        // 方式3: 腾讯云 COS
        // 'endpoint'       => 'cos.ap-guangzhou.myqcloud.com',  // 根据你的区域修改: ap-guangzhou, ap-beijing, ap-shanghai
        // 'region'         => 'ap-guangzhou',
        // 'use_path_style' => false,  // 腾讯云 COS 使用虚拟主机样式
        // 'use_https'      => true,
        //
        // 方式4: 七牛云 Kodo
        // 'endpoint'       => 's3-cn-east-1.qiniucs.com',  // 根据你的区域修改: cn-east-1, cn-north-1, cn-south-1
        // 'region'         => 'cn-east-1',
        // 'use_path_style' => false,
        // 'use_https'      => true,
        // ============================================
        
        // ============================================
        // 环境变量配置（推荐方式，支持公网/内网部署切换）
        // ============================================
        // S3_ENDPOINT      - 服务端点，包含协议 (http:// 或 https://)
        // S3_USE_HTTPS     - 1=强制HTTPS, 0=强制HTTP, 不设置=从endpoint自动检测
        // S3_BUCKET        - 存储桶名称
        // S3_ACCESS_KEY    - 访问密钥
        // S3_SECRET_KEY    - 密钥
        // S3_USE_PATH_STYLE - 1=路径样式(MinIO), 0=虚拟主机样式(AWS/阿里云)
        // ============================================
        
        'endpoint'       => ($__s3Endpoint = getenv('S3_ENDPOINT') ?: 'http://frp.xmwl.top:48771'),
        'region'         => getenv('S3_REGION') ?: 'cn-default',
        'bucket'         => getenv('S3_BUCKET') ?: 'crm20260116',
        'access_key'     => getenv('S3_ACCESS_KEY') ?: 'L9G8IjSqWbftIWkkDkyu',
        'secret_key'     => getenv('S3_SECRET_KEY') ?: 'OhvzHwG44sYVESyqhBfVNDoUATwB991AxjagwSfL',
        'session_token'  => getenv('S3_SESSION_TOKEN') ?: null,
        'prefix'         => trim((getenv('S3_PREFIX') !== false ? getenv('S3_PREFIX') : ''), '/'),
        // use_https: 优先使用环境变量，否则从 endpoint 自动检测协议
        'use_https'      => (($__useHttps = getenv('S3_USE_HTTPS')) !== false && $__useHttps !== '')
            ? ($__useHttps === '1')
            : (strpos($__s3Endpoint, 'https://') === 0),
        'use_path_style' => getenv('S3_USE_PATH_STYLE') !== '0',  // MinIO=true, AWS/阿里云=false
        'timeout'        => ($__s3Timeout = getenv('S3_TIMEOUT')) !== false && $__s3Timeout !== ''
            ? (int)$__s3Timeout
            : 60,
        // 公开访问 URL（用于客户门户等公开链接）
        // 格式: http(s)://域名:端口/bucket
        // 如不设置，将自动拼接 endpoint + bucket
        'public_url'     => getenv('S3_PUBLIC_URL') ?: null,
    ],
];

