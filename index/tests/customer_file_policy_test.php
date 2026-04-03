<?php

require_once __DIR__ . '/../services/CustomerFilePolicy.php';

$customer = [
    'id' => 1,
    'department_id' => 2,
    'owner_user_id' => 5,
    'create_user_id' => 6,
];

$cases = [
    ['title' => 'admin view', 'actor' => ['id' => 99, 'role' => 'admin', 'department_id' => 1], 'action' => 'view', 'expected' => true],
    ['title' => 'dept admin same dept edit', 'actor' => ['id' => 2, 'role' => 'dept_admin', 'department_id' => 2], 'action' => 'edit', 'expected' => true],
    ['title' => 'dept admin other dept view', 'actor' => ['id' => 3, 'role' => 'dept_admin', 'department_id' => 8], 'action' => 'view', 'expected' => false],
    ['title' => 'owner edit', 'actor' => ['id' => 5, 'role' => 'staff', 'department_id' => 4], 'action' => 'edit', 'expected' => true],
    ['title' => 'creator view', 'actor' => ['id' => 6, 'role' => 'staff', 'department_id' => 4], 'action' => 'view', 'expected' => true],
    ['title' => 'random staff view', 'actor' => ['id' => 7, 'role' => 'staff', 'department_id' => 4], 'action' => 'view', 'expected' => false],
];

$failures = 0;
foreach ($cases as $case) {
    $allowed = $case['expected'];
    $actual = true;
    try {
        CustomerFilePolicy::authorize($case['actor'], $customer, $case['action']);
    } catch (RuntimeException $e) {
        $actual = false;
    }
    if ($actual !== $allowed) {
        $failures++;
        echo "[FAIL] {$case['title']} expected " . ($allowed ? 'allow' : 'deny') . PHP_EOL;
    } else {
        echo "[OK] {$case['title']}" . PHP_EOL;
    }
}

if ($failures > 0) {
    echo "Policy tests failed: {$failures}" . PHP_EOL;
    exit(1);
}

echo "Policy tests passed." . PHP_EOL;

