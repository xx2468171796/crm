<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/layout.php';

if (current_user()) {
    header('Location: index.php?page=dashboard');
    exit;
}

layout_header('登录', false);

$error = isset($_GET['error']) ? true : false;
?>
<style>
/* 重置布局容器 */
body {
    background: #f5f7fa !important;
    min-height: 100vh;
    margin: 0;
    padding: 0;
}
.container-fluid {
    padding: 0 !important;
    margin: 0 !important;
}
.login-container {
    min-height: 100vh;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px;
    width: 100%;
}
.login-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    padding: 50px 40px;
    max-width: 450px;
    width: 100%;
    position: relative;
    border: 1px solid #e8e8e8;
    margin: 0 auto;
}
/* 确保登录卡片在所有屏幕上居中 */
.login-container > div {
    width: 100%;
    display: flex;
    justify-content: center;
}
.login-logo {
    text-align: center;
    margin-bottom: 30px;
}
.login-logo-icon {
    width: 80px;
    height: 80px;
    background: #2c3e50;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    color: white;
    margin-bottom: 15px;
    box-shadow: 0 4px 12px rgba(44, 62, 80, 0.15);
}
.login-title {
    font-size: 28px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 10px;
    text-align: center;
    letter-spacing: -0.5px;
}
.login-subtitle {
    text-align: center;
    color: #7f8c8d;
    font-size: 14px;
    margin-bottom: 30px;
}
.form-control {
    height: 48px;
    border-radius: 8px;
    border: 1.5px solid #e0e0e0;
    padding: 12px 16px;
    font-size: 15px;
    transition: all 0.2s;
    background: #fafafa;
}
.form-control:focus {
    border-color: #2c3e50;
    box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.08);
    background: #fff;
}
.form-label {
    font-weight: 600;
    color: #34495e;
    margin-bottom: 8px;
    font-size: 14px;
}
.btn-primary {
    height: 48px;
    border-radius: 8px;
    background: #2c3e50;
    border: none;
    font-size: 16px;
    font-weight: 600;
    letter-spacing: 0.3px;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(44, 62, 80, 0.2);
}
.btn-primary:hover {
    background: #34495e;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(44, 62, 80, 0.25);
}
.btn-primary:active {
    transform: translateY(0);
    background: #2c3e50;
}
.author-signature {
    margin-top: 30px;
    padding-top: 25px;
    border-top: 2px solid #f0f0f0;
    text-align: center;
    font-size: 14px;
    color: #888;
    font-style: italic;
    position: relative;
    z-index: 1;
}
.author-signature .heart {
    color: #ff6b6b;
    animation: heartbeat 1.5s ease-in-out infinite;
    display: inline-block;
}
@keyframes heartbeat {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.3); }
}
.alert {
    border-radius: 10px;
    border: none;
}
/* 移动端适配 */
@media (max-width: 576px) {
    .login-card {
        padding: 40px 30px;
    }
    .login-title {
        font-size: 26px;
    }
    .login-logo-icon {
        width: 60px;
        height: 60px;
        font-size: 30px;
    }
}
</style>

<div class="login-container">
    <div class="col-md-6 col-lg-5 col-xl-4">
        <div class="login-card">
            <div class="login-logo">
                <div class="login-logo-icon">🏢</div>
            </div>
            <h3 class="login-title">ANKOTTI 客户跟进系统</h3>
            <p class="login-subtitle">欢迎回来，请登录您的账户</p>
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>⚠️ 登录失败</strong><br>
                用户名或密码错误，请重试！
            </div>
            <?php endif; ?>
            <form method="post" action="/login_do.php">
                <div class="mb-4">
                    <label class="form-label">👤 用户名</label>
                    <input type="text" name="username" class="form-control" placeholder="请输入用户名" required autofocus>
                </div>
                <div class="mb-4">
                    <label class="form-label">🔒 密码</label>
                    <input type="password" name="password" class="form-control" placeholder="请输入密码" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <span>立即登录</span>
                </button>
            </form>
            <div class="author-signature">
                <span class="heart">❤</span> Alone 呕血制作 <span class="heart">❤</span>
            </div>
        </div>
    </div>
</div>

<!-- 引入点击特效 -->
<script src="js/click-effect.js"></script>

<?php
layout_footer();
