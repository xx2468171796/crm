<?php

/**
 * 简易指标记录器，将指标写入 runtime/metrics.log 供采集。
 */
if (!function_exists('record_metric')) {
    function record_metric(string $name, float $value, array $tags = []): void
    {
        $dir = dirname(__DIR__) . '/runtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/metrics.log';
        $entry = [
            'timestamp' => time(),
            'metric' => $name,
            'value' => $value,
            'tags' => $tags,
        ];
        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }
}

