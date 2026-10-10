<?php
/**
 * 公司导航页 - 后台公共逻辑（鉴权 + 布局）
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * 校验登录态，未登录跳转登录页
 */
function require_login(): void
{
    secure_session_start();
    if (empty($_SESSION['admin_logged_in'])) {
        redirect('login.php');
    }
}

/**
 * 校验 CSRF，失败统一提示并返回
 */
function guard_csrf(): void
{
    if (!csrf_verify()) {
        http_response_code(400);
        exit('CSRF 校验失败，请返回重试。');
    }
}

/**
 * 释放 session 写锁：在鉴权/CSRF 完成后、进入耗时操作（如远程抓取图标）之前调用。
 * session 文件锁默认持有到请求结束，会让同一浏览器（同一 session）的其它后台请求排队等待；
 * 提前释放可避免「抓取中后台整页卡死」的互锁现象。释放后不再写 $_SESSION 即为安全。
 */
function release_session_write(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * 重新获取 session 写权限：仅用于 release_session_write() 之后仍需写 $_SESSION（如 flash）的场景。
 * 未调用本函数而直接改 $_SESSION，改动不会落盘。
 */
function resume_session_write(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        secure_session_start();
    }
}

/**
 * 后台通用页面头部
 */
function admin_header(string $title, string $activeNav = ''): void
{
    $siteName = e(setting('site_name'));
    $theme    = theme_preference();
    echo '<!DOCTYPE html>
<html lang="zh-CN" data-theme="' . $theme . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . e($title) . ' · ' . $siteName . '</title>
' . favicon_html('../assets/') . '
<style>
html:not([data-loaded]) { background:#faf1dd; }
html[data-theme="dark"]:not([data-loaded]) { background:#0b1220; }
</style>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="app-body admin-body">';

    $items = [
        'links'       => ['index.php',        '🔗 链接管理'],
        'categories'  => ['categories.php',   '📂 分类管理'],
        'vault'       => ['vault.php',        '🔐 密码保险箱'],
        'settings'    => ['settings.php',     '⚙️ 站点设置'],
        'stats'       => ['stats.php',        '📊 点击统计'],
        'reset'       => ['reset.php',        '♻️ 重置演示'],
    ];

    echo '<header class="navbar glass">
    <div class="container admin-topbar">
        <div class="brand">
            ' . logo_badge_html() . '
            <div>
                <div class="brand-name">' . $siteName . ' · 管理后台</div>
                <div class="brand-tag">内容维护与数据统计</div>
            </div>
        </div>
        <div class="admin-nav-center">
            <nav class="admin-nav-inner" aria-label="后台主导航">';
    foreach ($items as $key => $item) {
        $active = $key === $activeNav ? ' class="active"' : '';
        echo '<a href="' . $item[0] . '"' . $active . '>' . $item[1] . '</a>';
    }
    echo '</nav>
        </div>
        <div class="navbar-actions">
            <a class="admin-link" href="../index.php">← 返回前台</a>
            ' . theme_toggle_html() . '
            <a class="admin-link danger-link" href="logout.php">退出</a>
        </div>
    </div>
</header>';

    echo '<main class="container admin-main">';

    $flash = $_SESSION['flash'] ?? null;
    if ($flash) {
        $level = ($flash['type'] ?? 'success') === 'error' ? 'flash-error' : 'flash-success';
        echo '<div class="flash ' . $level . '" id="flashMsg">' . e($flash['msg'] ?? '') . '</div>';
        unset($_SESSION['flash']);
    }
}

/**
 * 后台通用页面尾部
 */
function admin_footer(): void
{
    echo '</main>';
    page_footer('../');
}

/**
 * 设置一次性提示信息
 */
function flash(string $msg, string $type = 'success'): void
{
    secure_session_start();
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/**
 * 获取所有分类（后台下拉使用）
 */
function fetch_categories(): array
{
    $db = db();
    return $db->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();
}

/**
 * 排序字段方向清洗
 */
function clean_order(string $dir): string
{
    return $dir === 'desc' ? 'DESC' : 'ASC';
}

/**
 * 统一删除确认提示（JS confirm 在 onclick 中触发）
 */
function confirm_onclick(string $msg): string
{
    return 'onclick="return confirm(\'' . addslashes($msg) . '\');"';
}
