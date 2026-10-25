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
    // 全新库还没有任何账号，此时登录页也进不去 —— 一律回安装向导
    if (admin_needs_install()) {
        redirect('install.php');
    }
    if (empty($_SESSION['admin_logged_in'])) {
        redirect('login.php');
    }
    // 账号可能已被删除或改名。会话里只存了登录时的用户名，不回查的话
    // 就成了「幽灵会话」—— 账号都没了，持有人照样能改后台数据。
    if (!admin_session_user_exists()) {
        $_SESSION = [];
        session_destroy();
        redirect('login.php');
    }
}

/**
 * 会话里记的那个账号在库中是否仍然存在
 *
 * 单独抽出来是为了能直接断言：判定逻辑埋在 require_login() 里的话，
 * 测试只能靠匹配源码文本，那种断言一改写法就失效。
 */
function admin_session_user_exists(): bool
{
    $username = (string)($_SESSION['admin_username'] ?? '');

    return $username !== '' && admin_find_user($username) !== null;
}

/**
 * 校验站长身份：未登录跳登录页，已登录但不是 owner 则拒绝访问。
 *
 * 用于「站点设置 / 重置演示 / 密码保险箱」这三个一旦失守就不可逆或会泄密的页面。
 * editor 角色只能维护链接与分类。
 */
function require_owner(): void
{
    require_login();
    if (!admin_is_owner()) {
        http_response_code(403);
        flash(t('该操作需要站长权限，当前账号只能维护链接与分类。'), 'error');
        redirect('index.php');
    }
}

/**
 * 渲染后台主导航（按角色过滤）：editor 看不到站务类入口
 *
 * @param string $activeNav 当前高亮的导航键
 */
function admin_nav_items(): array
{
    $items = [
        'links'      => ['index.php',       'link',     t('链接管理'), false],
        'categories' => ['categories.php',  'folder',   t('分类管理'), false],
        'vault'      => ['vault.php',       'lock',     t('密码保险箱'), true],
        'settings'   => ['settings.php',    'settings', t('站点设置'), true],
        'stats'      => ['stats.php',       'chart',    t('点击统计'), false],
        // 改密对 owner 与 editor 都开放：改自己的密码不需要站长权限
        'password'   => ['password.php',    'key',      t('修改密码'), false],
        'reset'      => ['reset.php',       'reset',    t('重置演示'), true],
    ];
    if (!admin_is_owner()) {
        $items = array_filter($items, static function (array $item): bool {
            return $item[3] === false;
        });
    }
    return $items;
}

/**
 * 校验 CSRF，失败统一提示并返回
 */
function guard_csrf(): void
{
    if (!csrf_verify()) {
        http_response_code(400);
        exit(('CSRF ' . t('校验失败，请返回重试。')));
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
<html lang="' . e(locale()) . '" data-theme="' . $theme . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . e($title) . ' · ' . $siteName . '</title>
' . favicon_html('../assets/') . '
<style>
html:not([data-loaded]) { background:#f8fafc; }
html[data-theme="dark"]:not([data-loaded]) { background:#111827; }
</style>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/workspace.css">
</head>
<body class="app-body admin-body">';

    echo '<header class="navbar workspace-navbar"><div class="container admin-topbar">
        <a class="brand" href="index.php">' . logo_badge_html() . '<span class="brand-name">' . e(setting('logo_text')) . '</span></a>
        <div class="navbar-actions"><a class="admin-link home-link" href="../index.php">' . ui_icon('home') . '<span>' . e(t('返回前台')) . '</span></a>'
        . theme_toggle_html() . '<a class="admin-link signout-link" href="logout.php">' . ui_icon('logout') . '<span>' . e(t('退出')) . '</span></a></div>
        </div></header>';

    echo '<aside class="workspace-sidebar"><div class="sidebar-heading">' . e(t('管理工作台')) . '</div><nav class="workspace-nav" aria-label="' . e(t('后台主导航')) . '">';
    foreach (admin_nav_items() as $key => [$url, $icon, $label]) {
        $active = $key === $activeNav;
        echo '<a href="' . $url . '"' . ($active ? ' class="active" aria-current="page"' : '') . '>' . ui_icon($icon) . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav><div class="sidebar-note">'
        . e(admin_is_owner() ? t('内容维护与数据统计') : t('编辑器权限：可维护链接与分类'))
        . '</div></aside>';
    echo '<main class="container admin-main">';

    $flash = $_SESSION['flash'] ?? null;
    if ($flash) {
        $level = ($flash['type'] ?? 'success') === 'error' ? 'flash-error' : 'flash-success';
        echo '<div class="flash ' . $level . '" id="flashMsg">' . e($flash['msg'] ?? '') . '</div>';
        unset($_SESSION['flash']);
    }

    // 老库升级的存量账号会被标上「需要改密」（见 lib/migrate.php 的加列逻辑）——
    // 我们无法从哈希反推它是否仍是当年那个明文默认密码，所以一律提醒一次，改完即消失。
    if (admin_must_change_password()) {
        echo '<div class="flash flash-warning" role="alert">'
            . e(t('当前账号可能仍在使用初始密码，建议尽快更换。'))
            . ' <a href="password.php">' . e(t('去修改密码')) . '</a></div>';
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

// flash() 已移至 lib/layout.php（由 config.php 加载）。
// 原位置在 admin/_init.php 时，install.php / login.php 这类「必须先放行」的页面
// 引入不了它（_init.php 一进来就 require_login()），建号成功后调用会直接抛 Error。

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

