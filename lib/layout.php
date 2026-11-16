<?php
/**
 * 公司导航页 - 页面骨架与品牌区渲染
 *
 * 由 config.php 加载。这里只放「把 HTML 输出出去」的展示层函数：
 *   page_header()      前台页头（DOCTYPE / favicon / 样式表）
 *   page_footer()      前台页尾（载入 ui-wiring / i18n / theme / workspace 四个脚本）
 *   theme_preference() 当前主题（读 cookie）
 *   theme_toggle_html() 主题 + 语言切换按钮组
 *   favicon_html()     favicon 声明（深浅两个变体）
 *   logo_badge_html()  品牌徽章
 *   auth_brand_html()  登录 / 注册页左侧品牌分栏
 *
 * 后台页头尾在 admin/_init.php 的 admin_header() / admin_footer() 中，
 * 与这里不是同一套（后台有自己的侧边导航与容器），但共用 favicon_html / logo_badge_html / page_footer。
 */

declare(strict_types=1);

// 允许本文件被单独引入；已由 config.php 加载时不会重复执行。
require_once dirname(__DIR__) . '/config.php';

/**
 * 获取当前主题偏好（cookie）
 */
function theme_preference(): string
{
    // 默认固定浅色（不跟随系统深色）；仅当 Cookie 明确为 dark / light 时跟随用户手动选择
    return ($_COOKIE['theme'] ?? '') === 'dark' ? 'dark' : 'light';
}

/**
 * 统一页面头部（含主题初始化脚本与 favicon）
 *
 * FOUC 防护：无 data-loaded 时，light 默认 #f8fafc（淡奶酪黄暖调）、dark 用户（服务端已按 cookie 输出
 * data-theme="dark"）#111827，避免 CSS 加载前的白闪/暗闪。
 */
function page_header(string $title): void
{
    $siteName = e(setting('site_name'));
    $theme    = theme_preference();
    echo '<!DOCTYPE html>
<html lang="' . e(locale()) . '" data-theme="' . $theme . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . e(t($title)) . ' · ' . $siteName . '</title>
' . favicon_html('assets/') . '
<style>
html:not([data-loaded]) { background:#f8fafc; }
html[data-theme="dark"]:not([data-loaded]) { background:#111827; }
</style>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/workspace.css">
</head>';
}

/**
 * 渲染主题切换按钮（配合 theme.js；浅色默认显示月亮、深色显示太阳）
 */
function theme_toggle_html(string $extraClass = ''): string
{
    return language_switch_html() . '<button type="button" class="theme-toggle ' . $extraClass . ('" id="themeToggle" aria-label="' . t('切换深浅色主题') . '">
<svg class="tt-icon tt-dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
<svg class="tt-icon tt-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
</button>');
}

/**
 * favicon 声明（两个变体：浅色 favicon.svg / 深色 favicon-dark.svg；theme.js 按主题切换 href）
 */
function favicon_html(string $prefix): string
{
    $theme = theme_preference();
    $file  = $theme === 'dark' ? 'favicon-dark.svg' : 'favicon.svg';
    return '<link rel="icon" type="image/svg+xml" id="appFavicon" data-fav-base="' . e($prefix) . '" href="' . e($prefix . $file) . '">';
}

/**
 * 导航罗盘徽章（蓝色圆角方块 + 白色方向针 SVG）
 */
function logo_badge_html(): string
{
    return '<span class="brand-logo" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" focusable="false"><rect width="32" height="32" rx="9" fill="#245bd8"/><circle cx="16" cy="16" r="11" stroke="white" stroke-opacity=".42" stroke-width="1.35"/><path d="m10.5 22 4-10.3L24 8.5l-3.5 9.4z" fill="white"/><path d="m14.5 11.7 6 6.2" stroke="#9ce8d7" stroke-width="1.7" stroke-linecap="round"/><circle cx="16" cy="16" r="1.5" fill="#245bd8" stroke="white" stroke-width="1"/></svg></span>';
}

/**
 * 登录 / 注册页左侧品牌区（品牌分栏布局共用）
 *
 * 结构：Logo 徽标 + 公司名 + 副标题 → 主标题 + 价值主张 → 卖点列表 → 底部注脚；
 * 背景由渐变光晕（.auth-brand-glow）与细网格底纹（.auth-brand-grid）叠加而成。
 *
 * @param string   $title    主标题（如「欢迎回来」）
 * @param string   $subtitle 副标题 / 价值主张
 * @param string[] $points   卖点列表（逐条渲染为带勾选图标的行）
 * @param string   $foot     底部注脚，留空则不渲染
 */
function auth_brand_html(string $title, string $subtitle, array $points = [], string $foot = ''): string
{
    $html = '<aside class="auth-brand">'
        . '<span class="auth-brand-grid" aria-hidden="true"></span>'
        . '<span class="auth-brand-glow auth-brand-glow-a" aria-hidden="true"></span>'
        . '<span class="auth-brand-glow auth-brand-glow-b" aria-hidden="true"></span>'
        . '<div class="auth-brand-inner">'
        . '<div class="auth-brand-top">' . logo_badge_html()
        . '<div class="auth-brand-id">'
        . '<div class="auth-brand-name">' . e(setting('site_name')) . '</div>'
        . '<div class="auth-brand-tag">' . e(setting('site_slogan')) . '</div>'
        . '</div></div>'
        . '<div class="auth-brand-body">'
        . '<h1 class="auth-brand-title">' . e($title) . '</h1>'
        . '<p class="auth-brand-sub">' . e($subtitle) . '</p>'
        . '</div>';

    if ($points) {
        $html .= '<ul class="auth-points">';
        foreach ($points as $point) {
            $html .= '<li><span class="ap-ico" aria-hidden="true">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" '
                . 'stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
                . '</span><span>' . e((string)$point) . '</span></li>';
        }
        $html .= '</ul>';
    }

    if ($foot !== '') {
        $html .= '<div class="auth-brand-foot">' . e($foot) . '</div>';
    }

    return $html . '</div></aside>';
}

/**
 * 统一页面尾部（主题切换逻辑）
 *
 * @param string $base 资源相对前缀：根目录页面传 ''，admin/ 子目录页面传 '../'
 */
function page_footer(string $base = ''): void
{
    echo '<script src="' . $base . 'assets/js/ui-wiring.js"></script><script src="' . $base . 'assets/js/i18n.js"></script><script src="' . $base . 'assets/js/theme.js"></script><script src="' . $base . 'assets/js/workspace.js"></script>
</body>
</html>';
}

/** Shared copyright and contact footer for sign-in, install and registration pages. */
function site_footer_html(string $class = 'auth-page-footer'): string
{
    $footerKey = locale() === 'en' ? 'footer_text_en' : 'footer_text';
    $footerText = trim(setting($footerKey));
    $email = trim(setting('contact_email')) ?: 'liu5xin@139.com';
    return '<footer class="' . e($class) . '">'
        . '<span>' . e($footerText) . '</span>'
        . '<a href="mailto:' . e($email) . '">' . ui_icon('mail') . '<span>' . e($email) . '</span></a>'
        . '</footer>';
}

/**
 * 设置一次性提示信息（下个页面渲染后即清除）
 *
 * 放在这里而不是 admin/_init.php：_init.php 一进来就跑 require_login()，
 * 那些「必须先放行才能访问」的页面（安装向导 install.php、登录页 login.php）
 * 根本没法引入它。此前 flash() 定义在那里，结果 install.php 建号成功后
 * 调用 flash() 直接抛 Error —— 账号其实已经建好了，页面却渲染成报错。
 * 挪到由 config.php 加载的模块里，对所有入口一视同仁。
 *
 * @param string $type success 或 error
 */
function flash(string $msg, string $type = 'success'): void
{
    secure_session_start();
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
