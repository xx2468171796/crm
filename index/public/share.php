<?php
// 外部分享链接访问页面
// 验证后重定向到客户详情页（只读模式）

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/url.php';
require_once __DIR__ . '/../core/device.php';

$customerCode = trim($_GET['code'] ?? '');

if ($customerCode === '') {
    die('无效的访问链接');
}

// 通过客户代码查询客户信息
$customer = Db::queryOne('SELECT * FROM customers WHERE customer_code = :code', ['code' => $customerCode]);

if (!$customer) {
    die('客户不存在');
}

// 查询分享链接配置
$link = Db::queryOne('SELECT * FROM customer_links WHERE customer_id = :id', ['id' => $customer['id']]);

// 如果没有生成链接，拒绝访问
if (!$link) {
    die('此客户未开启分享功能');
}

// 如果链接已停用，拒绝访问
if (!$link['enabled']) {
    die('此分享链接已停用');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 检查访问权限
$user = current_user();
$inputPassword = null;

// 处理密码验证
if (!$user && $link['password']) {
    // 检查是否已验证过密码
    if (!isset($_SESSION['share_verified_' . $customer['id']])) {
        $inputPassword = trim($_POST['password'] ?? '');
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (verifyLinkPassword($inputPassword, $link['password'])) {
                $_SESSION['share_verified_' . $customer['id']] = true;
                $_SESSION['share_password_' . $customer['id']] = $inputPassword;
                
                // 记录访问
                Db::execute('UPDATE customer_links SET last_access_at = :time, last_access_ip = :ip, access_count = access_count + 1 WHERE id = :id', [
                    'time' => time(),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'id' => $link['id']
                ]);
            } else {
                $error = '密码错误';
            }
        }
    } else {
        $inputPassword = $_SESSION['share_password_' . $customer['id']] ?? null;
    }
}

// 使用权限检查函数判断权限
$permission = checkLinkPermission($link, $user, $inputPassword);

// 调试信息
error_log("DEBUG share.php: user=" . json_encode($user) . ", permission=$permission");
error_log("DEBUG share.php: link_id={$link['id']}, org_permission={$link['org_permission']}, allowed_edit_users={$link['allowed_edit_users']}");

// 如果权限为none，拒绝访问
if ($permission === 'none') {
    // 显示详细错误信息（仅用于调试）
    $debugInfo = [
        'user_id' => $user['id'] ?? 'guest',
        'user_role' => $user['role'] ?? 'guest',
        'org_permission' => $link['org_permission'],
        'allowed_edit_users' => $link['allowed_edit_users'],
        'allowed_view_users' => $link['allowed_view_users']
    ];
    error_log("DEBUG share.php: Access denied - " . json_encode($debugInfo));
    die('您没有权限访问此客户信息<br><small>调试信息: user_id=' . ($user['id'] ?? 'guest') . ', org_permission=' . $link['org_permission'] . '</small>');
}

// 如果需要密码但未验证，显示密码输入页面
if (!$user && $link['password'] && !isset($_SESSION['share_verified_' . $customer['id']])) {
    // 继续显示密码输入页面（下面的HTML代码）
} else {
    // 设置权限标记
    if ($permission === 'edit') {
        $_SESSION['share_editable_' . $customer['id']] = true;
    } elseif ($permission === 'view') {
        $_SESSION['share_readonly_' . $customer['id']] = true;
    }
    // permission === 'none' 的情况已在上面被拒绝，这里不会到达
    
    // 记录访问（如果还没记录过）
    if (!isset($_SESSION['share_verified_' . $customer['id']])) {
        Db::execute('UPDATE customer_links SET last_access_at = :time, last_access_ip = :ip, access_count = access_count + 1 WHERE id = :id', [
            'time' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'id' => $link['id']
        ]);
        $_SESSION['share_verified_' . $customer['id']] = true;
    }
    
    // 根据设备类型跳转到相应版本的客户详情页
    if (isMobileDevice()) {
        header('Location: mobile_customer_detail.php?id=' . $customer['id']);
    } else {
        header('Location: customer_detail.php?id=' . $customer['id']);
    }
    exit;
}

// 以下是密码输入页面
if ($link['password']) {
    
    // 显示密码输入页面
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>访问验证 - ANKOTTI</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            /* 手机端背景色改为纯白色 */
            @media (max-width: 768px) {
                body.bg-light {
                    background-color: #ffffff !important;
                }
            }
        </style>
    </head>
    <body class="bg-light">
        <div class="container">
            <div class="row justify-content-center mt-5">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title text-center mb-4">访问验证</h5>
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger"><?= $error ?></div>
                            <?php endif; ?>
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">请输入访问密码</label>
                                    <input type="password" name="password" class="form-control" required autofocus>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">访问</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
