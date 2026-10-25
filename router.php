<?php
/**
 * 公司导航页 - PHP 内置服务器路由脚本
 *
 * 存在的意义：默认的 `php -S host:port -t .` 会把文档根下的所有文件当作静态资源直接返回，
 * 其中就包含 data/company_nav.sqlite —— 那里面有管理员密码哈希。本路由脚本在交给内置服务器
 * 处理之前，先把运行时数据目录拦下来。
 *
 * 由 start.sh 接管调用（exec php -S ... router.php），无需手工指定。
 *
 * 注意：本脚本只对 PHP 内置服务器生效。正式部署（nginx / Apache）请参照 docs/deploy.md
 *      与 data/.htaccess 的拦截规则，两者缺一不可。
 */

declare(strict_types=1);

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

/*
 * 运行时数据目录（SQLite 主库 / 备份 / 未来可能出现的日志）与版本库目录一律拒绝。
 * 这里用 indexOf 式的前缀判断而非逐文件列举，避免以后新增文件时出现漏网之鱼。
 */
if (preg_match('#^/(data|\.git)/#i', $requestPath)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

$target = realpath(__DIR__ . $requestPath);

// 存在的文件（含 .php 脚本）与目录：返回 false 交回内置服务器，
// 由它按原逻辑执行 PHP 或自动寻找目录下的 index.php
if ($target !== false
    && str_starts_with($target, __DIR__ . DIRECTORY_SEPARATOR)
    && (is_file($target) || is_dir($target))) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
return true;
