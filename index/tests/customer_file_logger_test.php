<?php

require_once __DIR__ . '/../services/CustomerFileLogger.php';

$entries = [];
$logger = new CustomerFileLogger(function ($customerId, $fileId, $action, $actor, $extra) use (&$entries) {
    $entries[] = [
        'customer_id' => $customerId,
        'file_id' => $fileId,
        'action' => $action,
        'actor_id' => $actor['id'],
        'extra' => $extra,
    ];
});

$logger->log(10, 99, 'file_uploaded', ['id' => 7], ['storage_key' => 'customer/10/demo.pdf']);
$logger->log(10, 99, 'file_downloaded', ['id' => 7], []);

if (count($entries) !== 2) {
    throw new RuntimeException('Logger should record two entries');
}

if ($entries[0]['extra']['storage_key'] !== 'customer/10/demo.pdf') {
    throw new RuntimeException('Logger extra payload mismatch');
}

echo "Customer file logger tests passed." . PHP_EOL;

