<?php
/**
 * URL辅助函数
 * 统一管理所有URL路径
 */

class Url
{
    private static $config = null;
    
    /**
     * 初始化配置
     */
    private static function init()
    {
        if (self::$config === null) {
            $config = require __DIR__ . '/../config.php';
            self::$config = $config['url'];
        }
    }
    
    /**
     * 获取基础URL
     */
    public static function base()
    {
        self::init();
        
        if (!empty(self::$config['base_url'])) {
            return self::$config['base_url'];
        }
        
        // 自动检测（支持反向代理）
        $protocol = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $protocol = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            $protocol = 'https';
        } elseif (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            $protocol = 'https';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        return $protocol . '://' . $host;
    }
    
    /**
     * 获取API路径
     */
    public static function api($path = '')
    {
        self::init();
        $apiPath = self::$config['api_path'];
        return $apiPath . ($path ? '/' . ltrim($path, '/') : '');
    }
    
    /**
     * 获取JS路径
     */
    public static function js($file = '')
    {
        self::init();
        $jsPath = self::$config['js_path'];
        return $jsPath . ($file ? '/' . ltrim($file, '/') : '');
    }
    
    /**
     * 获取CSS路径
     */
    public static function css($file = '')
    {
        self::init();
        $cssPath = self::$config['css_path'];
        return $cssPath . ($file ? '/' . ltrim($file, '/') : '');
    }
    
    /**
     * 获取上传文件路径
     */
    public static function upload($file = '')
    {
        self::init();
        $uploadPath = self::$config['upload_path'];
        return $uploadPath . ($file ? '/' . ltrim($file, '/') : '');
    }
    
    /**
     * 获取完整URL
     */
    public static function full($path = '')
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}

// 定义全局常量
define('BASE_URL', Url::base());
define('API_URL', Url::api());
define('JS_URL', Url::js());
define('CSS_URL', Url::css());
define('UPLOAD_URL', Url::upload());
