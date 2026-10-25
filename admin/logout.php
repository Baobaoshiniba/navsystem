<?php
/**
 * 后台 - 退出登录
 *
 * 只 session_destroy() 是不够的：服务端数据没了，浏览器仍攥着那枚会话 Cookie，
 * 下次请求会拿它去新建一个空会话。这里连同 Cookie 一起清掉。
 *
 * 取舍：本页是 GET 链接、不校验 CSRF。强制登出只构成骚扰，不构成提权或数据泄露；
 * 改成 POST 要连带改导航与事件委托，收益不划算。
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

secure_session_start();

$_SESSION = [];

// 用与建立时相同的参数把 Cookie 置为已过期，samesite 也要一并带上
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();
redirect('login.php');
