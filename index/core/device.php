<?php
/**
 * 设备检测工具函数
 */

/**
 * 检测是否为移动设备
 * @return bool
 */
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobilePatterns = [
        '/Android/i',
        '/webOS/i',
        '/iPhone/i',
        '/iPad/i',
        '/iPod/i',
        '/BlackBerry/i',
        '/Windows Phone/i',
        '/IEMobile/i',
        '/Opera Mini/i'
    ];
    
    foreach ($mobilePatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            return true;
        }
    }
    
    return false;
}

