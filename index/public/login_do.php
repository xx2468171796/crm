<?php
// 处理登录提交

// 开启错误显示（调试用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/../core/db.php';
    require_once __DIR__ . '/../core/auth.php';

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        header('Location: /login.php');
        exit;
    }

    // 账号密码校验（使用密码哈希）
    $user = Db::queryOne('SELECT * FROM users WHERE username = :u AND status = 1 LIMIT 1', ['u' => $username]);

    if (!$user || !password_verify($password, $user['password'])) {
        // 登录失败
        header('Location: /login.php?error=1');
        exit;
    }

    auth_login($user);

    header('Location: /index.php?page=dashboard');
    exit;
    
} catch (Exception $e) {
    // 记录错误到日志
    error_log('Login error: ' . $e->getMessage());
    
    // 显示友好的错误信息
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>登录错误</title></head><body>';
    echo '<h1>登录出错</h1>';
    echo '<p>错误信息：' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>文件：' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p>行号：' . $e->getLine() . '</p>';
    echo '<p><a href="/login.php">返回登录</a></p>';
    echo '</body></html>';
    exit;
}
