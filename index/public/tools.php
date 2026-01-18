<?php
// 小工具页面 - 直接嵌入HTML版本(浅色系)

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

layout_header('小工具');

// 直接包含独立的HTML文件内容
$htmlFile = __DIR__ . '/tools_standalone.html';
$htmlContent = file_get_contents($htmlFile);

// 提取body内容(去掉html/head/body标签)
preg_match('/<body>(.*?)<\/body>/s', $htmlContent, $matches);
if (isset($matches[1])) {
    echo $matches[1];
}

// 提取style内容
preg_match('/<style>(.*?)<\/style>/s', $htmlContent, $styleMatches);
if (isset($styleMatches[1])) {
    echo '<style>' . $styleMatches[1] . '</style>';
}

// 提取script内容
preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $htmlContent, $scriptMatches);
if (!empty($scriptMatches[1])) {
    foreach ($scriptMatches[1] as $script) {
        echo '<script>' . $script . '</script>';
    }
}

// 提取script src
preg_match_all('/<script[^>]+src="([^"]+)"[^>]*><\/script>/s', $htmlContent, $srcMatches);
if (!empty($srcMatches[1])) {
    foreach ($srcMatches[1] as $src) {
        echo '<script src="' . $src . '"></script>';
    }
}

layout_footer();
?>
