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

/**
 * 把请求路径整理成「唯一规范形式」
 *
 * 为什么必须多这一步：我们判定的依据是 $_SERVER['REQUEST_URI']（原始、未整理），
 * 而真正去磁盘上找文件的是内置服务器 —— 它会先自己把 `/./x`、`a/../x`、`//x`
 * 这类写法合并掉。两边口径不一致，就出现「我们看前缀不像 data/，它找出来偏偏是
 * data/ 里的文件」的绕过：
 *     /./data/company_nav.sqlite       → 200，整库可下载
 *     /assets/../data/company_nav.sqlite → 200，借一个真实目录当跳板
 * 所以判定之前先把路径按同样的规则压平，再来看首段是谁。
 */
function nav_normalize_path(string $path): string
{
    // 逐段剔除空段（来自 //）与 . 段；.. 段回退一级。
    // 不使用 realpath()：它要求文件真实存在，而拦截要在「文件是否存在」之前完成。
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
}

/**
 * 运行时数据目录（SQLite 主库 / 备份 / 安装密钥 / 未来可能出现的日志）与版本库目录一律拒绝。
 * 这里用前缀判断而非逐文件列举，避免以后新增文件时出现漏网之鱼。
 */
function nav_is_forbidden(string $path): bool
{
    return preg_match('#^/(data|\.git)(/|$)#i', $path) === 1;
}

function nav_forbid(): bool
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

/*
 * 解码：REQUEST_URI 是未解码的原始串。内置服务器自己会解码，于是
 * `/data%2fprobe` 这类写法同样能绕前缀判断。解码两次是刻意的保守选择 ——
 * 能挡住双重编码（%252e），误伤面仅限于「文件名里真含百分号」这种极端情况，
 * 而代价只是多几个 403，不会泄露任何东西。
 */
$decoded = rawurldecode(str_replace('\\', '/', $requestPath));
$decoded = rawurldecode($decoded);

if (nav_is_forbidden(nav_normalize_path($decoded))) {
    return nav_forbid();
}

$target = realpath(__DIR__ . $requestPath);

// 兜底：即便前缀判断被别的写法绕过去了，只要最终落点真的在受保护目录里就照样拒绝。
// realpath() 已经把 .. 全部解析掉了，这里看到的就是磁盘上的真实位置。
if ($target !== false) {
    foreach (['data', '.git'] as $guarded) {
        $guardedDir = realpath(__DIR__ . '/' . $guarded);
        if ($guardedDir !== false
            && str_starts_with($target, $guardedDir . DIRECTORY_SEPARATOR)) {
            return nav_forbid();
        }
    }
}

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
