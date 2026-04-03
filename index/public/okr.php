<?php
require_once __DIR__ . '/../core/layout.php';

auth_require();
$user = current_user();
$userName = $user['name'] ?? $user['username'] ?? '访客';
$userRole = $user['role'] ?? 'employee';
$departmentId = $user['department_id'] ?? null;
$departmentName = $user['department_name'] ?? '';

$userOptions = Db::query('SELECT id, realname as name FROM users WHERE status = 1 ORDER BY realname ASC');
$departmentOptions = Db::query('SELECT id, name FROM departments ORDER BY name ASC');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OKR 管理 - ANKOTTI</title>
    <style>
        :root {
            --primary-color: #007AFF;
            --primary-hover: #0056b3;
            --success-color: #34C759;
            --warning-color: #FF9500;
            --danger-color: #FF3B30;
            --bg-color: #F5F5F7;
            --card-bg: #FFFFFF;
            --sidebar-bg: #FAFAFA;
            --text-primary: #1D1D1F;
            --text-secondary: #6E6E73;
            --text-tertiary: #8E8E93;
            --border-color: #D2D2D7;
            --divider-color: #E5E5EA;
            --radius-lg: 12px;
            --radius-md: 8px;
            --radius-sm: 6px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        * {
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        body.okr-app {
            margin: 0;
            min-height: 100vh;
            background: var(--bg-color);
            color: var(--text-primary);
        }

        .okr-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0,0,0,0.85);
            color: #fff;
            padding: 12px 20px;
            border-radius: 999px;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            z-index: 3000;
            pointer-events: none;
        }

        .okr-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .top-nav {
            height: 56px;
            background: var(--card-bg);
            border-bottom: 1px solid var(--divider-color);
            display: flex;
            align-items: center;
            padding: 0 24px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .nav-logo {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin-right: 40px;
        }

        .nav-menu {
            display: flex;
            gap: 8px;
        }

        .nav-menu a {
            text-decoration: none;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .nav-menu a:hover {
            background: var(--bg-color);
            color: var(--text-primary);
        }

        .nav-menu a.active {
            background: var(--primary-color);
            color: white;
        }

        .nav-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-search {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--bg-color);
            border-radius: 20px;
            width: 240px;
        }

        .nav-search input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 14px;
            flex: 1;
            color: var(--text-primary);
        }

        .nav-search input::placeholder {
            color: var(--text-tertiary);
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .nav-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
        }

        .main-layout {
            display: flex;
            margin-top: 56px;
            min-height: calc(100vh - 56px);
        }

        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--divider-color);
            padding: 20px 16px;
            position: fixed;
            top: 56px;
            left: 0;
            bottom: 0;
            overflow-y: auto;
        }

        .sidebar-section + .sidebar-section {
            margin-top: 24px;
        }

        .sidebar-section {
            margin-bottom: 24px;
        }

        .sidebar-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            padding: 0 12px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--radius-md);
            font-size: 14px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .sidebar-item:hover {
            background: var(--divider-color);
            color: var(--text-primary);
        }

        .sidebar-item.active {
            background: rgba(0, 122, 255, 0.1);
            color: var(--primary-color);
            font-weight: 500;
        }

        .sidebar-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .cycle-selector {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 12px;
            margin-bottom: 12px;
        }

        .cycle-current {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cycle-nav {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .cycle-nav-btn {
            width: 24px;
            height: 24px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .cycle-nav-btn:hover {
            background: var(--bg-color);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .cycle-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .cycle-remain-badge {
            display: inline-block;
            background: var(--success-color);
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin: 8px 12px 0;
        }

        .cycle-remain-badge.warning {
            background: var(--warning-color);
        }

        .cycle-remain-badge.danger {
            background: var(--danger-color);
        }

        /* 周期选择模态框样式 */
        .segment-control {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .segment-item {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--card-bg);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .segment-item:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .segment-item.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .cycle-quick-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: var(--bg-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .cycle-quick-item:hover {
            background: var(--card-bg);
            border-color: var(--border-color);
        }

        .cycle-quick-item.active {
            border: 2px solid var(--primary-color);
            background: var(--card-bg);
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            background: var(--card-bg);
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 122, 255, 0.1);
        }

        /* 周期列表样式 */
        .cycle-list {
            margin-top: 20px;
            border-top: 1px solid var(--divider-color);
            padding-top: 16px;
        }

        .cycle-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 8px;
            transition: all 0.2s;
            gap: 16px;
        }

        .cycle-list-item:hover {
            border-color: var(--primary-color);
            background: var(--bg-color);
        }

        .cycle-list-item.active {
            border-color: var(--primary-color);
            background: rgba(var(--primary-rgb), 0.05);
        }

        .cycle-list-item-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .cycle-list-item-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .cycle-list-item-type {
            font-size: 12px;
            padding: 2px 8px;
            background: var(--bg-color);
            border-radius: 4px;
            color: var(--text-secondary);
        }

        .cycle-list-item-date {
            font-size: 13px;
            color: var(--text-secondary);
            flex-shrink: 0;
        }

        .cycle-list-item-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .okr-btn.small {
            padding: 4px 12px;
            font-size: 12px;
        }

        .okr-btn.danger {
            background: var(--danger-color);
            color: white;
        }

        .okr-btn.danger:hover {
            background: #c0392b;
        }

        .sidebar-item-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .cycle-card {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 12px;
            border: 1px solid var(--divider-color);
        }

        .cycle-card h4 {
            margin: 0 0 4px;
            font-size: 16px;
        }

        .cycle-meta {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 24px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .page-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .okr-btn {
            padding: 10px 20px;
            border-radius: var(--radius-md);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            min-height: 40px;
        }

        .okr-btn.primary {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .okr-btn.primary:hover {
            background: var(--primary-hover);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }

        .okr-btn.secondary {
            background: var(--card-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .okr-btn.secondary:hover {
            background: var(--bg-color);
        }

        .okr-btn.small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .okr-btn svg {
            width: 16px;
            height: 16px;
        }

        .okr-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .okr-card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .okr-card-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .task-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .view {
            display: none;
            gap: 16px;
        }

        .view.active {
            display: block;
        }

        .okr-container {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .okr-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .okr-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .okr-cycle-name {
            font-size: 16px;
            font-weight: 600;
        }

        .okr-progress-bar {
            width: 200px;
            height: 6px;
            background: var(--divider-color);
            border-radius: 3px;
            overflow: hidden;
        }

        .okr-progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .okr-progress-fill.success {
            background: var(--success-color);
        }

        .okr-progress-fill.warning {
            background: var(--warning-color);
        }

        .okr-progress-text {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .okr-menu-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--text-tertiary);
        }

        .okr-menu-btn:hover {
            background: var(--bg-color);
        }

        .okr-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .okr-progress {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .okr-progress-bar {
            flex: 1;
            height: 6px;
            background: var(--divider-color);
            border-radius: 999px;
            overflow: hidden;
        }

        .okr-progress-fill {
            height: 100%;
            background: var(--success-color);
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        .objective-section {
            padding: 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .objective-section:last-child {
            border-bottom: none;
        }

        .objective-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .objective-badge {
            background: var(--primary-color);
            color: white;
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .objective-content {
            flex: 1;
        }

        .objective-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .objective-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .objective-meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .objective-meta-item .progress-icon {
            color: var(--success-color);
        }

        .objective-actions {
            display: flex;
            gap: 8px;
        }

        .objective-action-btn {
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: transparent;
            font-size: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
        }

        .objective-action-btn:hover {
            background: var(--bg-color);
            color: var(--text-primary);
        }

        .add-align-btn {
            color: var(--primary-color);
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: background 0.2s;
        }

        .add-align-btn:hover {
            background: rgba(0, 122, 255, 0.1);
        }

        .kr-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .kr-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            background: var(--bg-color);
            border-radius: var(--radius-md);
            transition: all 0.2s;
            cursor: pointer;
        }

        .kr-item:hover {
            background: var(--divider-color);
        }

        .kr-badge {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 600;
            min-width: 32px;
        }

        .kr-content {
            flex: 1;
        }

        .kr-title {
            font-size: 14px;
            line-height: 1.4;
            margin-bottom: 8px;
        }

        .kr-stats {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .kr-stat {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kr-stat.progress {
            color: var(--success-color);
            font-weight: 500;
        }

        .kr-stat.confidence .heart {
            color: var(--danger-color);
        }

        .kr-meta {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .kr-actions {
            display: flex;
            gap: 8px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .kr-item:hover .kr-actions {
            opacity: 1;
        }

        .kr-action-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: var(--card-bg);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .kr-action-btn:hover {
            background: var(--primary-color);
            color: white;
        }

        .icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--card-bg);
            color: var(--text-primary);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--divider-color);
        }

        .okr-toolbar,
        .task-toolbar,
        .relation-toolbar {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .btn-chip {
            border: 1px solid var(--divider-color);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
        }

        .btn-chip.active {
            background: rgba(0,122,255,0.12);
            color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .okr-filter-input {
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 14px;
            width: 200px;
            background: var(--card-bg);
            color: var(--text-primary);
        }

        .okr-filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(74, 144, 226, 0.1);
        }

        .task-table-wrapper {
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            padding: 0;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        table.task-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.task-table thead {
            background: var(--sidebar-bg);
        }

        table.task-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-tertiary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--divider-color);
        }

        table.task-table td {
            padding: 14px 16px;
            font-size: 14px;
            border-bottom: 1px solid var(--divider-color);
        }

        table.task-table tr:hover {
            background: var(--bg-color);
        }

        .task-priority-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }

        .task-priority-dot.high { background: var(--danger-color); }
        .task-priority-dot.medium { background: var(--warning-color); }
        .task-priority-dot.low { background: var(--success-color); }

        .task-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .task-status.pending {
            background: var(--divider-color);
            color: var(--text-secondary);
        }

        .task-status.in-progress {
            background: rgba(0, 122, 255, 0.1);
            color: var(--primary-color);
        }

        .task-status.completed {
            background: rgba(52, 199, 89, 0.1);
            color: var(--success-color);
        }

        .task-status.failed {
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger-color);
        }

        .task-user {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .task-user-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }

        .task-due {
            color: var(--text-secondary);
        }

        .task-due.overdue {
            color: var(--warning-color);
        }

        /* ========== 关系视图 - 无限画布 ========== */
        .relation-container {
            position: relative;
            background: 
                linear-gradient(90deg, var(--divider-color) 1px, transparent 1px),
                linear-gradient(var(--divider-color) 1px, transparent 1px);
            background-size: 24px 24px;
            background-color: var(--bg-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            height: calc(100vh - 260px);
            overflow: hidden;
        }

        .relation-toolbar {
            position: absolute;
            top: 16px;
            left: 16px;
            display: flex;
            gap: 12px;
            z-index: 10;
        }

        .relation-filter {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--card-bg);
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }

        .relation-filter:hover {
            border-color: var(--primary-color);
        }

        .relation-filter.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* 缩放控制面板 */
        .zoom-panel {
            position: absolute;
            bottom: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 8px 12px;
            box-shadow: var(--shadow-md);
            z-index: 10;
        }

        .zoom-panel-btn {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            background: var(--bg-color);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-primary);
            transition: all 0.2s;
        }

        .zoom-panel-btn:hover {
            background: var(--divider-color);
        }

        .zoom-panel-btn:active {
            transform: scale(0.95);
        }

        .zoom-panel-info {
            font-size: 13px;
            font-weight: 500;
            min-width: 50px;
            text-align: center;
            color: var(--text-secondary);
        }

        .zoom-panel-divider {
            width: 1px;
            height: 24px;
            background: var(--divider-color);
            margin: 0 4px;
        }

        /* 画布容器 */
        .canvas-wrapper {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow: hidden;
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
            touch-action: none;
        }

        .canvas-wrapper:active {
            cursor: grabbing;
        }

        .canvas-wrapper.dragging {
            cursor: grabbing;
        }

        .canvas-inner {
            position: absolute;
            transform-origin: 0 0;
            padding: 80px 60px 60px 60px;
            min-width: 2000px;
            min-height: 1500px;
            will-change: transform;
        }

        .relation-tree {
            display: flex;
            gap: 60px;
            padding: 20px 0;
        }

        .relation-column {
            flex: 0 0 340px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .relation-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px;
            border: none;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            position: relative;
            transition: box-shadow 0.2s, transform 0.2s;
            cursor: pointer;
            min-width: 280px;
            max-width: 320px;
        }

        .relation-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .relation-card:active {
            transform: scale(0.98);
        }

        /* 关联数量标识 */
        .relation-card .relation-count {
            position: absolute;
            right: -12px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            color: var(--primary-color);
            font-size: 12px;
            font-weight: 600;
        }

        .relation-card .relation-count::before {
            content: '<';
            margin-right: 2px;
        }

        .relation-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .relation-card-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 12px;
            line-height: 1.5;
            color: #333;
        }

        /* 进度条容器 */
        .relation-card-progress-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .relation-card-progress-bar {
            flex: 1;
            height: 6px;
            background: #f0f0f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .relation-card-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4A90E2, #67B8F7);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .relation-card-progress-text {
            font-size: 13px;
            color: #666;
            min-width: 40px;
            text-align: right;
        }

        /* 部门和负责人信息 */
        .relation-card-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }

        .relation-card-level {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .relation-card-level.company {
            background: #FFF3E0;
            color: #E65100;
        }

        .relation-card-level.department {
            background: #FFF3E0;
            color: #E65100;
        }

        .relation-card-level.personal {
            background: #E3F2FD;
            color: #1565C0;
        }

        .relation-card-owner {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #666;
        }

        .relation-card-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #FFB74D, #FF9800);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: white;
            font-weight: 500;
        }

        /* 展开/收起按钮 */
        .relation-card-toggle {
            position: absolute;
            right: 12px;
            top: 12px;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #999;
            transition: transform 0.2s;
        }

        .relation-card-toggle.expanded {
            transform: rotate(180deg);
        }

        /* KR列表 */
        .relation-card-krs {
            border-top: 1px solid #f0f0f0;
            padding-top: 10px;
            margin-top: 4px;
        }

        .relation-card-kr {
            font-size: 13px;
            color: #666;
            padding: 8px 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
            transition: color 0.2s;
            position: relative;
        }

        .relation-card-kr:hover {
            color: var(--primary-color);
        }

        .relation-card-kr-label {
            color: #999;
            font-weight: 500;
            flex-shrink: 0;
        }

        .relation-card-kr-title {
            flex: 1;
            line-height: 1.4;
        }

        .relation-card-kr-progress {
            color: #999;
            flex-shrink: 0;
        }

        /* KR关联数量标识 */
        .relation-card-kr .kr-relation-count {
            position: absolute;
            right: -20px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            font-size: 11px;
            font-weight: 600;
        }

        .relation-card-kr .kr-relation-count::after {
            content: '>';
            margin-left: 1px;
        }

        /* 连接线样式 */
        .relation-connector {
            position: absolute;
            pointer-events: none;
        }

        .relation-connector-line {
            stroke: #FFB74D;
            stroke-width: 2;
            fill: none;
        }

        /* 旧连接线样式保留兼容 */
        .relation-card .connector {
            position: absolute;
            right: -60px;
            top: 50%;
            width: 60px;
            height: 2px;
            background: linear-gradient(90deg, #ddd, #FFB74D);
        }

        .relation-card .connector::after {
            content: '';
            position: absolute;
            right: 0;
            top: -5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #FFB74D;
            border: 3px solid #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        .relation-column:last-child .relation-card .connector {
            display: none;
        }

        /* 关系视图空状态 */
        .relation-empty-state {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: var(--text-tertiary);
        }

        .relation-empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .relation-empty-state-text {
            font-size: 15px;
        }

        .fab-btn {
            position: fixed;
            right: 32px;
            bottom: 32px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            background: var(--primary-color);
            color: #fff;
            box-shadow: var(--shadow-md);
            font-size: 24px;
            cursor: pointer;
            z-index: 1100;
        }

        .okr-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            z-index: 2000;
        }

        .okr-modal.active {
            display: flex;
        }

        .okr-modal__dialog {
            width: min(960px, 90vw);
            max-height: 80vh;
            overflow-y: auto;
            background: var(--card-bg);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            animation: modalIn 0.2s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .okr-modal__header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--divider-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .okr-modal__body {
            padding: 20px 24px;
            overflow-y: auto;
        }

        .okr-modal__footer {
            padding: 16px 24px;
            border-top: 1px solid var(--divider-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* ========== 新建OKR弹窗样式 ========== */
        .okr-create-dialog {
            width: min(520px, 95vw);
            max-height: 90vh;
        }

        .okr-create-body {
            padding: 0;
        }

        /* 对齐区域 */
        .okr-create-align-section {
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .okr-create-align-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
        }

        .okr-create-align-btn .align-icon {
            font-size: 16px;
            transform: rotate(180deg) scaleX(-1);
        }

        .okr-create-align-list {
            margin-top: 8px;
        }

        .okr-create-align-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-color);
            border-radius: var(--radius-sm);
            margin-top: 8px;
            font-size: 13px;
        }

        .okr-create-align-item .align-badge {
            background: var(--primary-color);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .okr-create-align-item .remove-align {
            margin-left: auto;
            color: var(--text-secondary);
            cursor: pointer;
        }

        /* 目标输入区域 */
        .okr-create-objective-section {
            padding: 16px 20px;
            background: #fff;
        }

        .okr-create-objective-input {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .okr-badge-o {
            flex-shrink: 0;
            background: var(--primary-color);
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        .okr-create-objective-input textarea {
            flex: 1;
            border: none;
            resize: none;
            min-height: 60px;
            font-size: 15px;
            line-height: 1.5;
            padding: 4px 0;
            color: var(--text-secondary);
        }

        .okr-create-objective-input textarea:focus {
            outline: none;
            color: var(--text-primary);
        }

        .okr-create-objective-input textarea::placeholder {
            color: var(--text-secondary);
        }

        /* 设置区域 */
        .okr-create-settings {
            border-top: 6px solid var(--bg-color);
        }

        .okr-create-setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .okr-create-setting-row .setting-label {
            font-size: 15px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .okr-create-setting-row .setting-value {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: var(--text-secondary);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .okr-create-setting-row .setting-value.clickable:hover {
            color: var(--primary-color);
        }

        .okr-create-setting-row .chevron {
            font-size: 16px;
            color: #ccc;
        }

        .okr-create-setting-row .user-avatar-mini {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 500;
        }

        /* 层级选择tabs */
        .okr-level-tabs {
            display: flex;
            background: var(--bg-color);
            border-radius: 8px;
            padding: 3px;
        }

        .okr-level-tabs .level-tab {
            padding: 6px 14px;
            border: none;
            background: transparent;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .okr-level-tabs .level-tab:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .okr-level-tabs .level-tab.active {
            background: var(--primary-color);
            color: white;
        }

        .okr-level-tabs .level-tab:not(.active):not(:disabled):hover {
            background: var(--divider-color);
        }

        /* KR编辑区域 */
        .okr-create-kr-section {
            padding: 0;
        }

        .kr-editor-item {
            padding: 16px 20px;
            border-top: 1px solid var(--divider-color);
        }

        .kr-editor-header {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .kr-badge {
            flex-shrink: 0;
            color: var(--primary-color);
            font-size: 13px;
            font-weight: 600;
            padding-top: 4px;
        }

        .kr-editor-header textarea {
            flex: 1;
            border: none;
            resize: none;
            min-height: 50px;
            font-size: 14px;
            line-height: 1.5;
            padding: 0;
            color: var(--text-secondary);
        }

        .kr-editor-header textarea:focus {
            outline: none;
            color: var(--text-primary);
        }

        .kr-editor-actions {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 12px;
            padding-left: 32px;
            flex-wrap: wrap;
        }

        .kr-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            padding: 4px 0;
            line-height: 1;
            vertical-align: middle;
        }

        .kr-action-btn svg {
            flex-shrink: 0;
            width: 16px;
            height: 16px;
            display: block;
        }

        .kr-action-btn span {
            line-height: 1;
            display: inline-block;
        }

        .kr-action-btn:hover {
            color: var(--primary-color);
        }

        .kr-action-btn.delete {
            margin-left: auto;
        }

        .kr-action-btn.delete:hover {
            color: var(--error-color);
        }

        .kr-confidence-display {
            background: var(--bg-color);
            padding: 2px 8px;
            border-radius: 4px;
            line-height: 1.2;
            display: inline-block;
        }

        /* 添加KR按钮 */
        .okr-create-add-kr-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: calc(100% - 40px);
            margin: 16px 20px;
            padding: 14px;
            border: 1px dashed var(--divider-color);
            border-radius: var(--radius-md);
            background: var(--bg-color);
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .okr-create-add-kr-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            background: rgba(24, 144, 255, 0.05);
        }

        /* 底部操作栏 */
        .okr-create-footer {
            justify-content: space-between;
            padding: 12px 20px;
            background: var(--bg-color);
        }

        .okr-create-footer .footer-left {
            display: flex;
            gap: 16px;
        }

        .okr-create-footer .footer-right {
            display: flex;
            gap: 12px;
        }

        .footer-icon-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            padding: 8px 0;
        }

        .footer-icon-btn:hover {
            color: var(--primary-color);
        }

        .footer-icon-btn svg {
            width: 18px;
            height: 18px;
        }

        /* ========== 对齐选择弹窗 ========== */
        .okr-align-dialog {
            width: min(520px, 95vw);
            max-height: 85vh;
        }

        .okr-align-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-back {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            cursor: pointer;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-back:hover {
            color: var(--text-primary);
        }

        .align-header-center {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 15px;
            font-weight: 500;
        }

        .align-header-center .dropdown-icon {
            font-size: 10px;
            color: var(--text-secondary);
        }

        .okr-align-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .align-filter-btn {
            padding: 6px 12px;
            border: none;
            background: var(--bg-color);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .align-filter-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .align-selected-count {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .okr-align-body {
            padding: 0;
            max-height: 50vh;
            overflow-y: auto;
        }

        .align-okr-card {
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .align-okr-header {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }

        .align-okr-badge {
            flex-shrink: 0;
            background: var(--primary-color);
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .align-okr-title {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.4;
        }

        .align-okr-checkbox {
            width: 22px;
            height: 22px;
            border: 2px solid var(--divider-color);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .align-okr-checkbox.checked {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .align-okr-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: 38px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .align-okr-meta .level-tag {
            padding: 2px 8px;
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            color: var(--primary-color);
            font-size: 11px;
        }

        .align-okr-meta .user-info {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .align-okr-meta .user-avatar-tiny {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }

        .align-kr-list {
            margin-left: 38px;
            margin-top: 12px;
        }

        .align-kr-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-top: 1px solid var(--divider-color);
        }

        .align-kr-item .kr-label {
            color: var(--text-secondary);
            font-size: 12px;
        }

        .align-kr-item .kr-title {
            flex: 1;
            font-size: 13px;
        }

        /* ========== 任务选择弹窗 ========== */
        .okr-task-select-dialog {
            width: min(600px, 95vw);
            max-height: 85vh;
        }

        .okr-task-select-body {
            padding: 0;
            max-height: 60vh;
            display: flex;
            flex-direction: column;
        }

        .task-select-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .task-select-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .task-select-btn.primary {
            background: var(--primary-color);
            color: white;
        }

        .task-select-btn.primary:hover {
            background: var(--primary-hover);
        }

        .task-select-search {
            flex: 1;
        }

        .task-select-search input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--divider-color);
            border-radius: var(--radius-sm);
            font-size: 14px;
        }

        .task-select-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px 20px;
        }

        .task-select-loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .task-select-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--divider-color);
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .task-select-item:hover {
            background: var(--bg-color);
            border-color: var(--primary-color);
        }

        .task-select-item.selected {
            background: rgba(24, 144, 255, 0.1);
            border-color: var(--primary-color);
        }

        .task-select-checkbox {
            width: 20px;
            height: 20px;
            border: 2px solid var(--divider-color);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .task-select-item.selected .task-select-checkbox {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .task-select-content {
            flex: 1;
            min-width: 0;
        }

        .task-select-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .task-select-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .task-select-status {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }

        .task-select-status.pending {
            background: #F5F5F7;
            color: var(--text-secondary);
        }

        .task-select-status.in_progress {
            background: #E8F5E9;
            color: #2E7D32;
        }

        .task-select-status.completed {
            background: #E3F2FD;
            color: #1976D2;
        }

        .kr-tasks-count {
            position: relative;
        }

        .kr-tasks-count.has-tasks::after {
            content: attr(data-count);
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--primary-color);
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 16px;
            text-align: center;
        }

        /* ========== KR设置弹窗 ========== */
        .okr-kr-settings-dialog {
            width: min(520px, 95vw);
        }

        .okr-kr-settings-header {
            display: flex;
            align-items: center;
        }

        .okr-kr-settings-header h3 {
            flex: 1;
            text-align: center;
            margin-right: 32px;
        }

        .okr-kr-settings-body {
            padding: 0;
        }

        .kr-settings-name {
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .kr-settings-name textarea {
            width: 100%;
            border: none;
            resize: none;
            min-height: 80px;
            font-size: 15px;
            line-height: 1.5;
            color: var(--text-secondary);
        }

        .kr-settings-name textarea:focus {
            outline: none;
            color: var(--text-primary);
        }

        .kr-settings-section {
            padding: 16px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .kr-settings-section label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        .kr-confidence-slider {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .kr-confidence-slider input[type="range"] {
            flex: 1;
            height: 4px;
            -webkit-appearance: none;
            appearance: none;
            background: linear-gradient(to right, var(--primary-color) 50%, var(--divider-color) 50%);
            border-radius: 2px;
        }

        .kr-confidence-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--primary-color);
            cursor: pointer;
        }

        .kr-confidence-slider .confidence-value {
            font-size: 14px;
            color: var(--text-secondary);
            min-width: 40px;
        }

        .kr-settings-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--divider-color);
        }

        .kr-settings-row .setting-label {
            font-size: 15px;
            font-weight: 500;
        }

        .kr-settings-row .setting-value {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
            color: var(--text-secondary);
            background: none;
            border: none;
            cursor: pointer;
        }

        .kr-settings-row .setting-input {
            width: 80px;
            text-align: right;
            border: none;
            font-size: 14px;
            color: var(--text-primary);
        }

        .kr-settings-row .setting-input:focus {
            outline: none;
        }

        .kr-settings-divider {
            height: 8px;
            background: var(--bg-color);
        }

        .okr-btn.full-width {
            width: 100%;
        }

        /* ========== 用户选择弹窗 ========== */
        .okr-select-dialog {
            width: min(400px, 90vw);
        }

        .user-select-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .user-select-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .user-select-item:hover {
            background: var(--bg-color);
        }

        .user-select-item .user-avatar-mini {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 500;
        }

        .user-select-item .user-name {
            flex: 1;
            font-size: 14px;
        }

        .user-select-item .check-icon {
            color: var(--primary-color);
            font-weight: bold;
        }

        .user-select-item.selected {
            background: rgba(24, 144, 255, 0.1);
        }

        .user-select-item.selected .check-icon {
            display: inline !important;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        input[type="text"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--divider-color);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        .kr-list-editor {
            margin-top: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .kr-editor-card {
            border: 1px dashed var(--divider-color);
            border-radius: var(--radius-md);
            padding: 12px;
            background: var(--bg-color);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--bg-color);
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            background: var(--divider-color);
        }

        .empty-state {
            padding: 48px 0;
            text-align: center;
            color: var(--text-secondary);
        }

        /* 视图切换按钮组 */
        .view-toggle-group {
            display: flex;
            background: var(--surface-bg);
            border-radius: var(--radius-md);
            padding: 4px;
            gap: 2px;
        }

        .view-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 32px;
            border: none;
            background: transparent;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .view-toggle-btn:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--text-primary);
        }

        .view-toggle-btn.active {
            background: var(--primary-color);
            color: #fff;
        }

        /* 任务视图面板 */
        .task-view-panel[hidden] {
            display: none;
        }

        /* 日历视图样式 */
        .task-calendar-header {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--card-bg);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
        }

        .calendar-nav-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid var(--divider-color);
            background: #fff;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .calendar-nav-btn:hover {
            background: var(--surface-bg);
            color: var(--text-primary);
        }

        .calendar-month-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            min-width: 120px;
            text-align: center;
        }

        .calendar-today-btn {
            margin-left: auto;
        }

        .task-calendar-grid {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: var(--surface-bg);
            border-bottom: 1px solid var(--divider-color);
        }

        .calendar-weekday {
            padding: 12px;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .calendar-weekday.weekend {
            color: var(--primary-color);
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }

        .calendar-day {
            min-height: 100px;
            border-right: 1px solid var(--divider-color);
            border-bottom: 1px solid var(--divider-color);
            padding: 8px;
            background: #fff;
            cursor: pointer;
            transition: background 0.15s;
        }

        .calendar-day:nth-child(7n) {
            border-right: none;
        }

        .calendar-day:hover {
            background: var(--surface-bg);
        }

        .calendar-day.other-month {
            background: var(--surface-bg);
        }

        .calendar-day.other-month .day-number {
            color: var(--text-muted);
        }

        .calendar-day.today {
            background: rgba(0, 102, 255, 0.05);
        }

        .calendar-day.today .day-number {
            background: var(--primary-color);
            color: #fff;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .day-number {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .calendar-day.weekend .day-number {
            color: var(--primary-color);
        }

        .calendar-tasks {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .calendar-task-item {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: opacity 0.15s;
        }

        .calendar-task-item:hover {
            opacity: 0.85;
        }

        .calendar-task-item.status-pending {
            background: #fff3e0;
            color: #e65100;
            border-left: 3px solid #ff9800;
        }

        .calendar-task-item.status-in_progress {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 3px solid #2196f3;
        }

        .calendar-task-item.status-completed {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 3px solid #4caf50;
        }

        .calendar-task-item.status-failed {
            background: #ffebee;
            color: #c62828;
            border-left: 3px solid #f44336;
        }

        .calendar-task-more {
            font-size: 11px;
            color: var(--text-secondary);
            padding: 2px 8px;
            cursor: pointer;
        }

        .calendar-task-more:hover {
            color: var(--primary-color);
        }

        /* 近期任务视图样式 */
        .recent-task-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 16px;
        }

        .recent-nav-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .recent-nav-btn:hover {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .recent-date-strip {
            display: flex;
            gap: 4px;
        }

        .recent-date-item {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-secondary);
            background: transparent;
            border: none;
        }

        .recent-date-item:hover {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .recent-date-item.active {
            background: var(--primary-color);
            color: white;
        }

        .recent-date-item.today:not(.active) {
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .recent-date-label {
            font-size: 14px;
            color: var(--text-primary);
            font-weight: 500;
            margin-left: 8px;
        }

        .recent-today-btn {
            margin-left: auto;
            padding: 6px 16px;
            font-size: 13px;
        }

        .recent-task-content {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .recent-quick-add {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--bg-secondary);
            border-radius: 8px;
            border: 1px dashed var(--border-color);
            transition: all 0.2s;
        }

        .recent-quick-add:hover,
        .recent-quick-add:focus-within {
            border-color: var(--primary-color);
            background: var(--card-bg);
        }

        .quick-add-icon {
            color: var(--primary-color);
            display: flex;
            align-items: center;
        }

        .quick-add-input {
            flex: 1;
            border: none;
            background: transparent;
            font-size: 14px;
            color: var(--text-primary);
            outline: none;
        }

        .quick-add-input::placeholder {
            color: var(--text-secondary);
        }

        .quick-add-more {
            width: 28px;
            height: 28px;
            border: none;
            background: transparent;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            opacity: 0;
            transition: all 0.2s;
        }

        .recent-quick-add:hover .quick-add-more {
            opacity: 1;
        }

        .quick-add-more:hover {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .recent-task-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .recent-task-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: var(--card-bg);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .recent-task-item:hover {
            background: var(--bg-secondary);
            border-color: var(--border-color);
        }

        .recent-task-checkbox {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .recent-task-checkbox:hover {
            border-color: var(--primary-color);
        }

        .recent-task-checkbox.checked {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }

        .recent-task-title {
            flex: 1;
            font-size: 14px;
            color: var(--text-primary);
        }

        .recent-task-item.completed .recent-task-title {
            text-decoration: line-through;
            color: var(--text-secondary);
        }

        .recent-task-empty {
            padding: 48px 24px;
            text-align: center;
            color: var(--text-secondary);
        }

        .recent-task-empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            color: var(--border-color);
        }

        .recent-task-empty-text {
            font-size: 14px;
        }

        @media (max-width: 1200px) {
            .main-layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
            }

            .main-content {
                margin-left: 0;
                max-width: 100%;
            }
        }
    </style>
</head>
<body class="okr-app" data-page="desktop">
    <div id="okrToast" class="okr-toast"></div>
    <header class="top-nav">
        <div class="nav-logo">ANKOTTI</div>
        <div class="nav-menu">
            <a href="admin.php">首页</a>
            <a href="customers.php">客户管理</a>
            <a class="active">OKR管理</a>
            <a href="file_manager.php">文件管理</a>
        </div>
        <div class="nav-actions">
            <div class="nav-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
                </svg>
                <input type="text" placeholder="搜索OKR、任务..." id="navSearchInput">
            </div>
            <div class="nav-user">
                <div class="nav-user-avatar"><?= htmlspecialchars(mb_substr($userName, 0, 1, 'UTF-8')) ?></div>
                <span><?= htmlspecialchars($userName) ?></span>
            </div>
        </div>
    </header>

    <div class="main-layout">
        <aside class="sidebar" id="okrSidebar">
            <div class="sidebar-section">
                <div class="cycle-selector">
                    <button class="cycle-nav-btn" data-action="cycle-prev">‹</button>
                    <div class="cycle-current" data-action="open-cycle-modal">
                        <span class="cycle-name" id="okrCycleName">加载中…</span>
                    </div>
                    <button class="cycle-nav-btn" data-action="cycle-next">›</button>
                </div>
                <div class="cycle-remain-badge" id="okrCycleRemain">剩余 -- 天</div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-title">OKR视图</div>
                <div class="sidebar-menu">
                    <div class="sidebar-item active" data-view-target="okrs">
                        <span>📋</span> 我的OKR
                    </div>
                    <div class="sidebar-item" data-view-target="all-okrs">
                        <span>📁</span> 全部OKR
                    </div>
                    <div class="sidebar-item" data-view-target="relations">
                        <span>🔗</span> 关系视图
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-title">任务管理</div>
                <div class="sidebar-menu">
                    <div class="sidebar-item" data-view-target="tasks">
                        <span>📝</span> 我的任务
                        <span class="sidebar-item-badge" id="myTaskCount" style="display:none;">0</span>
                    </div>
                    <div class="sidebar-item" data-view-target="all-tasks">
                        <span>📋</span> 全部任务
                    </div>
                </div>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-title">更多</div>
                <div class="sidebar-menu">
                    <div class="sidebar-item" data-action="open-summary">
                        <span>📊</span> 总结报告
                    </div>
                    <div class="sidebar-item" data-action="open-help">
                        <span>❓</span> 帮助中心
                    </div>
                </div>
            </div>
        </aside>
        <main class="main-content" id="okrDesktopRoot">
            <div class="page-header" id="okrPageHeader">
                <div>
                    <div class="page-title" id="okrPageTitle">我的 OKR</div>
                    <div class="cycle-meta" id="okrPageMeta">对齐策略、KR 进度与任务状态实时同步</div>
                </div>
                <div class="page-actions" id="okrPageActions">
                    <button class="okr-btn secondary" data-action="open-cycle-modal">切换周期</button>
                    <button class="okr-btn secondary" data-action="open-progress-modal" id="okrProgressBtn">进度更新</button>
                    <button class="okr-btn primary" data-action="open-create-okr">+ 新建 OKR</button>
                    <button class="okr-btn primary" data-action="open-create-task" style="display: none;">+ 新建任务</button>
                </div>
            </div>

            <section class="view active" data-view="okrs">
                <div id="okrContainerList"></div>
                <div class="empty-state" id="okrEmptyState" hidden>
                    暂无 OKR，请先创建。
                </div>
            </section>

            <section class="view" data-view="all-okrs">
                <div class="okr-toolbar">
                    <div>
                        <strong>类型：</strong>
                        <button class="btn-chip active" data-all-okr-filter="type" data-value="">全部</button>
                        <button class="btn-chip" data-all-okr-filter="type" data-value="company">公司级</button>
                        <button class="btn-chip" data-all-okr-filter="type" data-value="department">部门级</button>
                        <button class="btn-chip" data-all-okr-filter="type" data-value="personal">个人级</button>
                    </div>
                    <div>
                        <strong>状态：</strong>
                        <button class="btn-chip active" data-all-okr-filter="status" data-value="">全部</button>
                        <button class="btn-chip" data-all-okr-filter="status" data-value="pending">待开始</button>
                        <button class="btn-chip" data-all-okr-filter="status" data-value="in_progress">进行中</button>
                        <button class="btn-chip" data-all-okr-filter="status" data-value="completed">已完成</button>
                        <button class="btn-chip" data-all-okr-filter="status" data-value="failed">未达成</button>
                    </div>
                    <div>
                        <strong>负责人：</strong>
                        <input type="text" class="okr-filter-input" id="allOkrOwnerFilter" placeholder="搜索负责人..." />
                    </div>
                </div>
                <div id="allOkrList"></div>
                <div class="empty-state" id="allOkrEmptyState" hidden>暂无 OKR</div>
            </section>

            <section class="view" data-view="tasks">
                <div class="page-header">
                    <div>
                        <div class="page-title">我的任务</div>
                        <div class="cycle-meta">管理与追踪任务进度，确保目标按时达成</div>
                    </div>
                    <div class="page-actions">
                        <!-- 视图切换按钮 -->
                        <div class="view-toggle-group">
                            <button class="view-toggle-btn active" data-task-view-mode="list" title="任务视图">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
                                    <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                                </svg>
                            </button>
                            <button class="view-toggle-btn" data-task-view-mode="recent" title="近期任务">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </button>
                            <button class="view-toggle-btn" data-task-view-mode="calendar" title="日历视图">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                            </button>
                        </div>
                        <button class="okr-btn primary" data-action="open-create-task">+ 新建任务</button>
                    </div>
                </div>

                <!-- 任务列表视图 -->
                <div class="task-view-panel" data-task-panel="list">
                    <div class="task-toolbar">
                        <div>
                            <strong>范围：</strong>
                            <button class="btn-chip active" data-task-filter="filter" data-value="my">我的</button>
                            <button class="btn-chip" data-task-filter="filter" data-value="my_responsible">我负责</button>
                            <button class="btn-chip" data-task-filter="filter" data-value="my_assigned">我分配</button>
                            <button class="btn-chip" data-task-filter="filter" data-value="my_participate">我参与</button>
                            <button class="btn-chip" data-task-filter="filter" data-value="all">全部</button>
                        </div>
                        <div>
                            <strong>状态：</strong>
                            <button class="btn-chip active" data-task-filter="status" data-value="">全部</button>
                            <button class="btn-chip" data-task-filter="status" data-value="pending">待处理</button>
                            <button class="btn-chip" data-task-filter="status" data-value="in_progress">进行中</button>
                            <button class="btn-chip" data-task-filter="status" data-value="completed">已完成</button>
                            <button class="btn-chip" data-task-filter="status" data-value="failed">未达成</button>
                        </div>
                    </div>
                    <div class="task-table-wrapper">
                        <table class="task-table" id="okrTaskTable">
                            <thead>
                                <tr>
                                    <th>任务</th>
                                    <th>状态</th>
                                    <th>负责人</th>
                                    <th>截止</th>
                                    <th>关联</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        <div class="empty-state" id="taskEmptyState" hidden>暂无任务</div>
                    </div>
                </div>

                <!-- 日历视图 -->
                <div class="task-view-panel" data-task-panel="calendar" hidden>
                    <div class="task-calendar-header">
                        <button class="calendar-nav-btn" id="calendarPrevMonth">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                        </button>
                        <span class="calendar-month-title" id="calendarMonthTitle">2025年11月</span>
                        <button class="calendar-nav-btn" id="calendarNextMonth">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </button>
                        <button class="okr-btn secondary calendar-today-btn" id="calendarToday">今天</button>
                    </div>
                    <div class="task-calendar-grid">
                        <div class="calendar-weekdays">
                            <div class="calendar-weekday">周一</div>
                            <div class="calendar-weekday">周二</div>
                            <div class="calendar-weekday">周三</div>
                            <div class="calendar-weekday">周四</div>
                            <div class="calendar-weekday">周五</div>
                            <div class="calendar-weekday weekend">周六</div>
                            <div class="calendar-weekday weekend">周日</div>
                        </div>
                        <div class="calendar-days" id="calendarDays">
                            <!-- 日历格子由 JS 动态生成 -->
                        </div>
                    </div>
                </div>

                <!-- 近期任务视图 -->
                <div class="task-view-panel" data-task-panel="recent" hidden>
                    <div class="recent-task-header">
                        <button class="recent-nav-btn" id="recentPrevWeek">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                        </button>
                        <div class="recent-date-strip" id="recentDateStrip">
                            <!-- 日期条由 JS 动态生成 -->
                        </div>
                        <button class="recent-nav-btn" id="recentNextWeek">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </button>
                        <span class="recent-date-label" id="recentDateLabel">11月25日 今天</span>
                        <button class="okr-btn secondary recent-today-btn" id="recentToday">今天</button>
                    </div>
                    <div class="recent-task-content">
                        <div class="recent-quick-add">
                            <span class="quick-add-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                            </span>
                            <input type="text" class="quick-add-input" id="recentQuickAddInput" placeholder="在此添加内容，按回车创建事件">
                            <button class="quick-add-more" title="更多选项">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
                                </svg>
                            </button>
                        </div>
                        <div class="recent-task-list" id="recentTaskList">
                            <!-- 近期任务列表由 JS 动态生成 -->
                        </div>
                    </div>
                </div>
            </section>

            <section class="view" data-view="all-tasks">
                <div class="task-toolbar">
                    <div>
                        <strong>状态：</strong>
                        <button class="btn-chip active" data-all-task-filter="status" data-value="">全部</button>
                        <button class="btn-chip" data-all-task-filter="status" data-value="pending">待处理</button>
                        <button class="btn-chip" data-all-task-filter="status" data-value="in_progress">进行中</button>
                        <button class="btn-chip" data-all-task-filter="status" data-value="completed">已完成</button>
                        <button class="btn-chip" data-all-task-filter="status" data-value="failed">未达成</button>
                    </div>
                </div>
                <div class="task-table-wrapper">
                    <table class="task-table" id="allTaskTable">
                        <thead>
                            <tr>
                                <th>任务</th>
                                <th>状态</th>
                                <th>负责人</th>
                                <th>截止</th>
                                <th>关联</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div class="empty-state" id="allTaskEmptyState" hidden>暂无任务</div>
                </div>
            </section>

            <section class="view" data-view="relations">
                <div class="relation-container" id="relationContainer">
                    <!-- 顶部筛选工具栏 -->
                    <div class="relation-toolbar">
                        <div class="relation-filter active" data-relation-filter="all">全部的OKR</div>
                        <div class="relation-filter" data-relation-filter="show-kr">显示关键结果</div>
                        <div class="relation-filter" data-relation-filter="company">公司级</div>
                        <div class="relation-filter" data-relation-filter="department">部门级</div>
                        <div class="relation-filter" data-relation-filter="personal">个人级</div>
                    </div>

                    <!-- 缩放控制面板 -->
                    <div class="zoom-panel">
                        <button class="zoom-panel-btn" data-relation-zoom="out" title="缩小">−</button>
                        <span class="zoom-panel-info" id="zoomLevel">100%</span>
                        <button class="zoom-panel-btn" data-relation-zoom="in" title="放大">+</button>
                        <div class="zoom-panel-divider"></div>
                        <button class="zoom-panel-btn" data-relation-zoom="reset" title="重置">⟲</button>
                        <button class="zoom-panel-btn" data-relation-zoom="fit" title="适应视图">⊡</button>
                    </div>

                    <!-- 无限画布 -->
                    <div class="canvas-wrapper" id="relationCanvasWrapper">
                        <div class="canvas-inner" id="relationCanvasInner">
                            <div class="relation-tree" id="okrRelationCanvas">
                                <!-- 动态渲染 -->
                            </div>
                        </div>
                    </div>

                    <!-- 空状态 -->
                    <div class="relation-empty-state" id="relationEmptyState" hidden>
                        <div class="relation-empty-state-icon">🔗</div>
                        <div class="relation-empty-state-text">当前周期暂无OKR数据</div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <button class="fab-btn" data-action="open-create-task">+</button>

    <?php include __DIR__ . '/okr_modals.php'; ?>

    <script>
        window.OKR_BOOTSTRAP = {
            page: 'desktop',
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

        // 周期导航辅助函数
        function prevCycle() {
            if (window.OKRApp && typeof window.OKRApp.prevCycle === 'function') {
                window.OKRApp.prevCycle();
            }
        }

        function nextCycle() {
            if (window.OKRApp && typeof window.OKRApp.nextCycle === 'function') {
                window.OKRApp.nextCycle();
            }
        }

        // 搜索功能
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('navSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const query = e.target.value.trim();
                    if (window.OKRApp && typeof window.OKRApp.search === 'function') {
                        window.OKRApp.search(query);
                    }
                });
            }
        });
    </script>
    <script src="<?= asset_url(Url::js('okr.js')) ?>"></script>
</body>
</html>

