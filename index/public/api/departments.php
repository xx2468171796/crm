<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';
header('Content-Type: application/json; charset=utf-8');
auth_require();
$pdo = Db::pdo();
$stmt = $pdo->query("SELECT id, name FROM departments ORDER BY id ASC");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success' => true, 'data' => $departments], JSON_UNESCAPED_UNICODE);
