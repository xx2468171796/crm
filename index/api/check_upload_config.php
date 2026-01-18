<?php
require_once __DIR__ . '/../core/api_init.php';
/**
 * 上传配置诊断脚本
 * 用于检查 PHP 和服务器配置是否支持大文件上传
 */

header('Content-Type: application/json; charset=utf-8');

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function parseSize($size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int)$size;
    
    switch ($last) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }
    
    return $size;
}

$config = [
    'php' => [
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'memory_limit' => ini_get('memory_limit'),
        'file_uploads' => ini_get('file_uploads'),
        'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    ],
    'parsed' => [
        'upload_max_filesize_bytes' => parseSize(ini_get('upload_max_filesize')),
        'post_max_size_bytes' => parseSize(ini_get('post_max_size')),
    ],
    'issues' => [],
    'recommendations' => [],
];

// 检查配置问题
if ($config['parsed']['post_max_size_bytes'] < $config['parsed']['upload_max_filesize_bytes']) {
    $config['issues'][] = 'post_max_size 必须大于或等于 upload_max_filesize';
    $config['recommendations'][] = '在 php.ini 中设置: post_max_size = ' . ini_get('upload_max_filesize');
}

if ($config['parsed']['upload_max_filesize_bytes'] < 100 * 1024 * 1024) {
    $config['issues'][] = 'upload_max_filesize 小于 100MB，可能无法上传大文件';
    $config['recommendations'][] = '建议设置 upload_max_filesize = 2048M 或更大';
}

if ($config['parsed']['post_max_size_bytes'] < 100 * 1024 * 1024) {
    $config['issues'][] = 'post_max_size 小于 100MB，可能无法上传大文件';
    $config['recommendations'][] = '建议设置 post_max_size = 2048M 或更大';
}

if ($config['php']['max_execution_time'] < 300) {
    $config['issues'][] = 'max_execution_time 小于 300 秒，大文件上传可能超时';
    $config['recommendations'][] = '建议设置 max_execution_time = 300 或更大';
}

if ($config['php']['max_input_time'] < 300) {
    $config['issues'][] = 'max_input_time 小于 300 秒，大文件上传可能超时';
    $config['recommendations'][] = '建议设置 max_input_time = 300 或更大';
}

// 检查临时目录
$tmpDir = $config['php']['upload_tmp_dir'];
if (!is_dir($tmpDir)) {
    $config['issues'][] = "上传临时目录不存在: {$tmpDir}";
} elseif (!is_writable($tmpDir)) {
    $config['issues'][] = "上传临时目录不可写: {$tmpDir}";
} else {
    $freeSpace = disk_free_space($tmpDir);
    $config['tmp_dir_free_space'] = formatBytes($freeSpace);
    if ($freeSpace < 5 * 1024 * 1024 * 1024) {
        $config['issues'][] = "临时目录可用空间小于 5GB，可能影响大文件上传";
    }
}

// 检查应用配置
$storageConfigFile = __DIR__ . '/../config/storage.php';
if (file_exists($storageConfigFile)) {
    $storageConfig = require $storageConfigFile;
    $appMaxSize = $storageConfig['limits']['max_single_size'] ?? 0;
    $config['app_config'] = [
        'max_single_size' => formatBytes($appMaxSize),
        'max_single_size_bytes' => $appMaxSize,
    ];
    
    if ($appMaxSize > $config['parsed']['upload_max_filesize_bytes']) {
        $config['issues'][] = '应用配置的最大文件大小 (' . formatBytes($appMaxSize) . ') 超过了 PHP upload_max_filesize (' . $config['php']['upload_max_filesize'] . ')';
        $config['recommendations'][] = '建议将 PHP upload_max_filesize 设置为至少 ' . formatBytes($appMaxSize);
    }
}

$config['status'] = empty($config['issues']) ? 'ok' : 'warning';
$config['message'] = empty($config['issues']) 
    ? '配置检查通过，支持大文件上传' 
    : '发现 ' . count($config['issues']) . ' 个潜在问题';

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

