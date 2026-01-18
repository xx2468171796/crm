<?php

require_once __DIR__ . '/../core/storage/storage_provider.php';

class StorageDiagnostics
{
    public static function run(): array
    {
        $config = storage_config();
        $driver = $config['type'] ?? 'local';
        $result = [
            'driver' => $driver,
            'timestamp' => date('c'),
            'status' => 'ok',
            'tests' => [],
        ];

        try {
            if ($driver === 's3') {
                $tests = self::runS3Diagnostics($config['s3'] ?? [], $config['limits'] ?? []);
            } else {
                $tests = self::runLocalDiagnostics($config['local'] ?? []);
            }
            $result['tests'] = $tests;
            foreach ($tests as $test) {
                if ($test['status'] !== 'pass') {
                    $result['status'] = 'error';
                    break;
                }
            }
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $result['trace'] = $e->getTraceAsString();
        }

        return $result;
    }

    private static function runLocalDiagnostics(array $config): array
    {
        $root = $config['root'] ?? dirname(__DIR__) . '/uploads/storage';
        $tests = [];

        $exists = is_dir($root);
        $tests[] = self::makeTest('存储路径存在', $exists, $exists ? $root : '目录不存在: ' . $root);

        if (!$exists) {
            return $tests;
        }

        $writable = is_writable($root);
        $tests[] = self::makeTest('路径可写', $writable, $writable ? '目录可写' : '目录不可写: ' . $root);

        if ($writable) {
            $tmpFile = $root . '/storage_health_' . uniqid('', true) . '.txt';
            $start = microtime(true);
            $bytes = @file_put_contents($tmpFile, 'storage-health:' . time());
            $duration = microtime(true) - $start;
            $success = $bytes !== false;
            $tests[] = self::makeTest(
                '写入测试',
                $success,
                $success ? '写入 ' . $bytes . ' 字节成功' : '写入失败，请检查磁盘权限',
                $duration
            );
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        return $tests;
    }

    private static function runS3Diagnostics(array $config, array $limits): array
    {
        $tests = [];
        $required = ['bucket', 'region', 'access_key', 'secret_key'];
        $missing = [];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }
        $tests[] = self::makeTest(
            '配置完整性',
            empty($missing),
            empty($missing) ? 'bucket/region/access_key/secret_key 均已配置' : '缺少: ' . implode(', ', $missing)
        );
        if (!empty($missing)) {
            return $tests;
        }

        [$host, $port, $scheme] = self::resolveS3Host($config);
        $resolvedIp = $host ? gethostbyname($host) : '';
        $dnsOk = $resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP);
        $tests[] = self::makeTest(
            'DNS 解析',
            $dnsOk,
            $dnsOk ? $host . ' -> ' . $resolvedIp : '无法解析主机: ' . $host
        );

        if ($dnsOk) {
            $targetHost = ($scheme === 'https' ? 'ssl://' : '') . $host;
            $start = microtime(true);
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($targetHost, $port, $errno, $errstr, 5);
            $duration = microtime(true) - $start;
            if ($socket) {
                $tests[] = self::makeTest('TCP 连接', true, '连接成功，端口 ' . $port, $duration);
                fclose($socket);
            } else {
                $tests[] = self::makeTest('TCP 连接', false, '连接失败: ' . ($errstr ?: $errno), $duration);
            }
        } else {
            $tests[] = self::makeTest('TCP 连接', false, 'DNS 解析失败，跳过连接测试');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'storage_health_');
        $testKey = 'health-check/' . date('Ymd') . '/' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($tmpFile, 's3-health:' . time());
        try {
            $provider = new S3StorageProvider($config, $limits ?? []);
            $start = microtime(true);
            $provider->putObject($testKey, $tmpFile, ['mime_type' => 'text/plain']);
            $tests[] = self::makeTest('对象写入', true, '上传成功', microtime(true) - $start);

            $start = microtime(true);
            $stream = $provider->readStream($testKey);
            $data = stream_get_contents($stream);
            fclose($stream);
            $tests[] = self::makeTest(
                '对象读取',
                true,
                '读取 ' . strlen((string)$data) . ' 字节',
                microtime(true) - $start
            );

            $provider->deleteObject($testKey);
            $tests[] = self::makeTest('对象删除', true, '删除成功');
        } catch (Throwable $e) {
            $message = $e->getMessage();
            // 针对 HTTP 403 提供更详细的诊断信息
            if (strpos($message, 'HTTP 403') !== false || strpos($message, '403') !== false) {
                $message .= ' | 可能原因：1) Access Key/Secret Key 权限不足 2) Bucket 策略限制 3) MinIO 用户权限配置问题';
                if (empty($config['use_path_style']) && !empty($config['endpoint'])) {
                    $message .= ' 4) 建议尝试设置 use_path_style=1（MinIO 通常需要）';
                }
            }
            $tests[] = self::makeTest('对象写入', false, $message);
        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        return $tests;
    }

    private static function resolveS3Host(array $config): array
    {
        $useHttps = ($config['use_https'] ?? true) !== false;
        $scheme = $useHttps ? 'https' : 'http';
        if (!empty($config['endpoint'])) {
            $parsed = parse_url($config['endpoint']);
            $scheme = $parsed['scheme'] ?? $scheme;
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);
            return [$host, $port, $scheme];
        }
        $host = ($config['bucket'] ?? 'bucket') . '.s3.' . ($config['region'] ?? 'ap-east-1') . '.amazonaws.com';
        $port = $scheme === 'https' ? 443 : 80;
        return [$host, $port, $scheme];
    }

    private static function makeTest(string $name, bool $success, string $details, ?float $duration = null, array $extra = []): array
    {
        $result = [
            'name' => $name,
            'status' => $success ? 'pass' : 'fail',
            'details' => $details,
        ];
        if ($duration !== null) {
            $result['duration_ms'] = round($duration * 1000, 2);
        }
        if (!empty($extra)) {
            $result['extra'] = $extra;
        }
        return $result;
    }
}


