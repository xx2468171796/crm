<?php
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();
$userName = $user['name'] ?? $user['username'] ?? '访客';
$userRole = $user['role'] ?? 'employee';
$departmentId = $user['department_id'] ?? null;

$userOptions = Db::query('SELECT id, realname as name FROM users WHERE status = 1 ORDER BY realname ASC');
$departmentOptions = Db::query('SELECT id, name FROM departments ORDER BY name ASC');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>OKR 管理 - Mobile</title>
    <style>
        :root {
            --primary-color: #007AFF;
            --primary-gradient: linear-gradient(135deg, #007AFF, #0056b3);
            --success-color: #34C759;
            --warning-color: #FF9500;
            --danger-color: #FF3B30;
            --bg-color: #F2F2F7;
            --card-bg: #FFFFFF;
            --text-primary: #000;
            --text-secondary: #48484A;
            --text-tertiary: #8E8E93;
            --border-color: #E5E5EA;
            --shadow-sm: 0 4px 12px rgba(0,0,0,0.08);
            --safe-top: env(safe-area-inset-top);
            --safe-bottom: env(safe-area-inset-bottom);
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Helvetica Neue", Arial, sans-serif;
        }

        body.mobile-okr {
            margin: 0;
            background: var(--bg-color);
            color: var(--text-primary);
            padding-bottom: calc(78px + var(--safe-bottom));
        }

        .toast {
            position: fixed;
            left: 50%;
            bottom: calc(90px + var(--safe-bottom));
            transform: translateX(-50%) translateY(20px);
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 14px;
            opacity: 0;
            transition: all 0.2s ease;
            z-index: 3000;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .header {
            height: calc(54px + var(--safe-top));
            padding: var(--safe-top) 16px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 0.5px solid rgba(0,0,0,0.08);
        }

        .header-title {
            font-weight: 700;
            font-size: 18px;
            text-align: center;
            flex: 1;
        }

        .header-btn {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: var(--primary-color);
            font-size: 20px;
        }

        .cycle-selector {
            background: var(--card-bg);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
        }

        .cycle-dropdown {
            background: var(--bg-color);
            padding: 8px 14px;
            border-radius: 18px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .cycle-actions {
            display: flex;
            gap: 10px;
        }

        .cycle-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--bg-color);
            font-size: 16px;
        }

        .section-title {
            padding: 14px 16px 6px;
            font-size: 15px;
            font-weight: 700;
        }

        .okr-card {
            margin: 0 16px 14px;
            background: var(--card-bg);
            border-radius: 18px;
            padding: 16px;
            box-shadow: var(--shadow-sm);
        }

        .okr-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .okr-card-title {
            font-weight: 700;
            font-size: 16px;
        }

        .okr-progress {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .okr-progress-bar {
            flex: 1;
            height: 4px;
            border-radius: 999px;
            background: var(--border-color);
            overflow: hidden;
        }

        .okr-progress-fill {
            height: 100%;
            background: var(--success-color);
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        .kr-pill {
            padding: 10px;
            border-radius: 14px;
            background: var(--bg-color);
            margin-bottom: 10px;
        }

        .kr-pill-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .kr-pill-meta {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .mobile-view {
            display: none;
        }

        .mobile-view.active {
            display: block;
        }

        .task-card {
            margin: 12px 16px;
            padding: 14px;
            border-radius: 16px;
            background: var(--card-bg);
            box-shadow: var(--shadow-sm);
        }

        .task-title {
            font-weight: 600;
            margin-bottom: 8px;
        }

        .task-meta {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .mobile-relations {
            margin: 12px 16px;
            padding: 16px;
            border-radius: 18px;
            background: var(--card-bg);
        }

        .relation-column {
            margin-bottom: 16px;
        }

        .relation-column h4 {
            font-size: 13px;
            color: var(--text-tertiary);
            text-transform: uppercase;
            margin: 0 0 8px;
        }

        .relation-chip {
            padding: 10px 12px;
            border-radius: 14px;
            background: var(--bg-color);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            height: calc(64px + var(--safe-bottom));
            padding-bottom: var(--safe-bottom);
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1200;
        }

        .bottom-nav button {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 12px;
            color: var(--text-tertiary);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }

        .bottom-nav button.active {
            color: var(--primary-color);
            font-weight: 600;
        }

        .fab {
            position: fixed;
            right: 20px;
            bottom: calc(80px + var(--safe-bottom));
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            background: var(--primary-gradient);
            color: #fff;
            font-size: 28px;
            box-shadow: var(--shadow-sm);
            z-index: 1100;
        }

        /* 共享模态框样式（与桌面一致） */
        .okr-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            z-index: 2000;
        }

        .okr-modal.active {
            display: flex;
        }

        .okr-modal__dialog {
            width: min(720px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            background: #fff;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
        }

        .okr-modal__header,
        .okr-modal__footer {
            padding: 16px 20px;
        }

        .okr-modal__header {
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .okr-modal__body {
            padding: 16px 20px;
        }

        .okr-btn {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .okr-btn.primary {
            background: var(--primary-color);
            color: #fff;
        }

        .okr-btn.secondary {
            background: var(--bg-color);
            color: var(--text-primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        input[type="text"],
        input[type="date"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
        }
    </style>
</head>
<body class="mobile-okr" data-page="mobile">
    <div id="okrToast" class="toast"></div>
    <header class="header">
        <button class="header-btn" onclick="history.back()">‹</button>
        <div class="header-title">我的 OKR</div>
        <button class="header-btn" data-action="open-cycle-modal">⟳</button>
    </header>

    <section class="cycle-selector">
        <div class="cycle-dropdown" data-action="open-cycle-modal">
            <span id="mobileCycleName">加载中…</span>
            <span>⌄</span>
        </div>
        <div class="cycle-actions">
            <button class="cycle-action-btn" data-action="cycle-prev">‹</button>
            <button class="cycle-action-btn" data-action="cycle-next">›</button>
        </div>
    </section>

    <section class="section-title">进度概览</section>
    <div id="mobileSummary" style="margin:0 16px 16px; background:var(--card-bg); border-radius:18px; padding:16px; display:flex; gap:12px; flex-wrap:wrap;">
        <div style="flex:1;">
            <div style="font-size:12px; color:var(--text-tertiary);">总体进度</div>
            <div style="font-size:22px; font-weight:700;" id="mobileProgressTotal">–%</div>
        </div>
        <div style="flex:1;">
            <div style="font-size:12px; color:var(--text-tertiary);">任务完成</div>
            <div style="font-size:22px; font-weight:700;" id="mobileTaskMetric">0/0</div>
        </div>
        <div style="flex:1;">
            <div style="font-size:12px; color:var(--text-tertiary);">剩余天数</div>
            <div style="font-size:22px; font-weight:700;" id="mobileCycleRemain">-</div>
        </div>
    </div>

    <div id="mobileOkrRoot">
        <section class="mobile-view active" data-mobile-view="okrs">
            <div id="mobileOkrList"></div>
            <div class="section-title">任务列表</div>
            <div id="mobileTaskList"></div>
        </section>
        <section class="mobile-view" data-mobile-view="tasks">
            <div class="section-title">任务</div>
            <div id="mobileTaskListStandalone"></div>
        </section>
        <section class="mobile-view" data-mobile-view="relations">
            <div class="section-title">关系视图</div>
            <div class="mobile-relations" id="mobileRelationCanvas"></div>
        </section>
    </div>

    <button class="fab" data-action="open-create-task">+</button>

    <nav class="bottom-nav">
        <button class="active" data-mobile-nav="okrs">
            <span>📋</span>
            <span>OKRs</span>
        </button>
        <button data-mobile-nav="tasks">
            <span>✅</span>
            <span>任务</span>
        </button>
        <button data-mobile-nav="relations">
            <span>🕸️</span>
            <span>关系</span>
        </button>
        <button data-mobile-nav="summary">
            <span>📝</span>
            <span>总结</span>
        </button>
        <button data-action="open-create-okr">
            <span>＋</span>
            <span>新建</span>
        </button>
    </nav>

    <?php include __DIR__ . '/okr_modals.php'; ?>

    <script>
        window.OKR_BOOTSTRAP = {
            page: 'mobile',
            apiBase: '<?= Url::api() ?>',
            assetsBase: '<?= Url::base() ?>',
            user: {
                id: <?= (int)$user['id'] ?>,
                name: <?= json_encode($userName, JSON_UNESCAPED_UNICODE) ?>,
                role: <?= json_encode($userRole, JSON_UNESCAPED_UNICODE) ?>,
                department_id: <?= $departmentId ? (int)$departmentId : 'null' ?>
            },
            users: <?= json_encode($userOptions, JSON_UNESCAPED_UNICODE) ?>,
            departments: <?= json_encode($departmentOptions, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="<?= asset_url(Url::js('okr.js')) ?>"></script>
</body>
</html>

