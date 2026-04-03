<?php

require_once __DIR__ . '/../core/storage/storage_provider.php';

function assertTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

// Local storage smoke test
$tempRoot = sys_get_temp_dir() . '/local_storage_test_' . uniqid();
@mkdir($tempRoot, 0755, true);
$local = new LocalStorageProvider(['root' => $tempRoot], [
    'preview_mimes' => ['image/png'],
]);
$tmpFile = tempnam(sys_get_temp_dir(), 'upload_');
file_put_contents($tmpFile, 'hello-world');
$meta = $local->putObject('customer/1/demo.txt', $tmpFile);
assertTrue(file_exists($tempRoot . '/customer/1/demo.txt'), 'local file should exist');
$stream = $local->readStream('customer/1/demo.txt');
assertTrue(stream_get_contents($stream) === 'hello-world', 'local file content mismatch');
fclose($stream);

$s3 = new S3StorageProvider([
    'endpoint' => 'https://s3.example.com',
    'region' => 'ap-east-1',
    'bucket' => 'demo-bucket',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETKEYEXAMPLE',
], ['preview_mimes' => []]);
$s3SignedUrl = $s3->getTemporaryUrl('customer/5/demo.pdf', 300);
assertTrue(
    strpos($s3SignedUrl, 'X-Amz-Signature=') !== false,
    's3 signed url missing signature'
);

echo "Storage provider tests passed." . PHP_EOL;

