<?php
/**
 * Run: php tests/http_serve.php
 *
 * 真起一个 PHP 内置服务器，用真实 HTTP 请求验收运行时行为。
 *
 * 为什么必须有这一层：P0「数据库可被直接下载」的修复是靠 router.php 拦的，
 * 但此前只有一条静态断言（检查 start.sh 里写了 router.php 这个字符串）。
 * 静态断言有个致命盲区 —— 只要有人手工 `php -S -t .` 起服务（或部署时忘了配），
 * 那条断言照样是绿的，而 data/ 已经敞开了。字符串匹配管不住运行时的实际行为。
 *
 * 所以这里同时起两个服务器做对照：
 *   A：php -S ... router.php     （start.sh 的正式启动方式）
 *   B：php -S ... -t .           （漏配 router 的错误启动方式）
 * 同一个探针文件在 A 下必须 403、在 B 下必须 200。
 * B 是刻意保留的「反向对照」：它证明 A 的 403 来自 router 的拦截，
 * 而不是「文件不存在所以碰巧 404」—— 少了对照片，这组断言随时会变成空跑。
 *
 * 工作目录是项目的临时副本，不碰真实的 data/company_nav.sqlite。
 */
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = __DIR__ . '/..';

/* ---------- 目录工具 ---------- */
function copy_tree(string $src, string $dst): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $path => $info) {
        $rel = substr((string)$path, strlen($src) + 1);
        if ($rel === '.git' || strpos($rel, '.git/') === 0) {
            continue;
        }
        if ($rel === '.workbuddy' || strpos($rel, '.workbuddy/') === 0) {
            continue;
        }
        $target = $dst . '/' . $rel;
        if ($info->isDir()) {
            @mkdir($target, 0777, true);
        } else {
            @mkdir(dirname($target), 0777, true);
            copy((string)$path, $target);
        }
    }
}

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path => $info) {
        $info->isDir() ? @rmdir((string)$path) : @unlink((string)$path);
    }
    @rmdir($dir);
}

/** 取一个空闲端口（有极小的 TOCTOU 窗口，真撞上了由启动重试兜住） */
function free_port(): int
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($srv === false) {
        throw new RuntimeException('Cannot allocate a port: ' . $errstr);
    }
    $name = (string)stream_socket_get_name($srv, false);
    fclose($srv);
    return (int)substr($name, strrpos($name, ':') + 1);
}

require __DIR__ . '/_http.php';

/**
 * @return array{status:int, headers:array<string,string>, setCookies:array<int,string>, body:string}
 */
function http_get(string $port, string $path): array
{
    static $clients = [];
    if (!isset($clients[$port])) {
        $clients[$port] = new TestClient($port);
    }
    return $clients[$port]->get($path);
}

/* ---------- 起服务器 ---------- */
function start_server(string $cwd, string $args): array
{
    $cmd = 'exec php -S 127.0.0.1:' . $args;
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start the built-in server');
    }
    return [$proc, (int)substr($args, 0, strpos($args, ' '))];
}

function wait_ready(string $port, string $probe): void
{
    for ($i = 0; $i < 100; $i++) {
        try {
            $r = http_get($port, $probe);
            if ($r['status'] > 0) {
                return;
            }
        } catch (Throwable $e) {
            // 还没起来，继续等
        }
        usleep(100000);
    }
    throw new RuntimeException('Server on port ' . $port . ' never became ready');
}

$tmp = sys_get_temp_dir() . '/nav-http-' . bin2hex(random_bytes(4));
$servers = [];
try {
    mkdir($tmp, 0700, true);
    copy_tree($root, $tmp);

    // 用一份全新库：冷启动会自己建表播种演示数据，id 从 1 开始，断言才好写。
    // 同时避免任何一项测试碰到真实的 data/company_nav.sqlite。
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($tmp . '/data/company_nav.sqlite' . $suffix)) {
            unlink($tmp . '/data/company_nav.sqlite' . $suffix);
        }
    }
    if (is_file($tmp . '/data/.install_key')) {
        unlink($tmp . '/data/.install_key');
    }
    rm_tree($tmp . '/data/backups');

    // 探针：一个放在 data/ 下的普通文本文件。它存在的意义是让
    // 「403 是拦下来的」与「404 是文件不存在」这两种情况能被区分开。
    $probeMarker = 'NAV-PROBE-' . bin2hex(random_bytes(3));
    file_put_contents($tmp . '/data/probe.txt', $probeMarker);

    $portA = (string)free_port();
    $portB = (string)free_port();
    [$procA] = start_server($tmp, $portA . ' router.php');   // 正式启动方式
    [$procB] = start_server($tmp, $portB . ' -t .');         // 反向对照：漏配 router
    $servers = [$procA, $procB];

    wait_ready($portA, '/index.php');
    wait_ready($portB, '/index.php');

    /* ===== 1. 运行时数据目录必须挡在门外 ===== */
    $a = http_get($portA, '/data/probe.txt');
    $b = http_get($portB, '/data/probe.txt');
    check($a['status'] === 403, 'router.php must return 403 for /data/, got ' . $a['status']);
    check(strpos($a['body'], $probeMarker) === false, 'The 403 body must not leak the file content');
    // 反向对照：不带 router 时同一文件确实能下载到 —— 证明上面的 403 是拦出来的
    check($b['status'] === 200, 'Control server without router must serve /data/, got ' . $b['status']);
    check(strpos($b['body'], $probeMarker) !== false,
        'Control server must actually return the probe content, otherwise the 403 assertion proves nothing');

    $db = http_get($portA, '/data/company_nav.sqlite');
    check($db['status'] === 403, 'The SQLite database must never be downloadable, got ' . $db['status']);
    $key = http_get($portA, '/data/.install_key');
    check($key['status'] === 403, 'The install key must never be downloadable, got ' . $key['status']);
    $git = http_get($portA, '/.git/config');
    check($git['status'] === 403, '/.git/ must be blocked, got ' . $git['status']);

    /*
     * 绕过写法清单。判定口径统一为「既不能 200，也不能把内容吐出来」——
     * 有些写法（如 /data;/）在内置服务器一侧天然是 404，同样是安全的，
     * 写死 403 会把安全的实现判成失败。
     *
     * 这一组是本次补测试时抓出来的真实漏洞的回归：router 原本只按原始
     * REQUEST_URI 的前缀判断，而内置服务器会自己把 /./ 与 a/../ 压平后再找文件，
     * 两边口径不一致 → /./data/xxx 与 /assets/../data/xxx 都能整库下载。
     */
    foreach ([
        '/DATA/probe.txt',                 // 大小写
        '/data/../data/probe.txt',         // 原地绕圈
        '/./data/probe.txt',               // 点段前缀
        '/././data/probe.txt',
        '/.//data/probe.txt',
        '//./data/probe.txt',
        '/x/../data/probe.txt',            // 借不存在的目录当跳板
        '/assets/../data/probe.txt',       // 借真实存在的目录当跳板
        '/%2e/data/probe.txt',             // 编码的点段
        '/%2E/data/probe.txt',
        '/%252e/data/probe.txt',           // 双重编码
        '/data%2fprobe.txt',               // 编码的斜杠
        '/data;/probe.txt',
    ] as $tricky) {
        $r = http_get($portA, $tricky);
        check($r['status'] !== 200, 'Path "' . $tricky . '" must not be served, got ' . $r['status']);
        check(strpos($r['body'], $probeMarker) === false, 'Path "' . $tricky . '" leaked the probe content');
    }

    /* ===== 2. 安全响应头 ===== */
    $home = http_get($portA, '/index.php');
    check($home['status'] === 200, 'The front page must render, got ' . $home['status']);
    foreach ([
        'x-content-type-options' => 'nosniff',
        'x-frame-options'        => null,
        'referrer-policy'        => null,
        'permissions-policy'     => null,
    ] as $header => $expected) {
        check(isset($home['headers'][$header]), 'Missing security header: ' . $header);
        if ($expected !== null) {
            check(stripos($home['headers'][$header], $expected) !== false,
                'Header ' . $header . ' must contain ' . $expected);
        }
    }
    // CSP 必须是 enforce 而不是 report-only：后者只是上报，拦不住任何东西
    check(isset($home['headers']['content-security-policy']), 'CSP must be sent as an enforcing header');
    check(!isset($home['headers']['content-security-policy-report-only'])
        || $home['headers']['content-security-policy-report-only'] === '',
        'CSP must not be left in report-only mode');

    /* ===== 3. CSP 之下页面还得能用 ===== */
    // 内联脚本要么带 nonce，要么是空标签：CSP 一 enforce，没有 nonce 的内联脚本会被直接丢弃。
    preg_match_all('#<script\b[^>]*>#i', $home['body'], $scripts);
    $inline = 0;
    foreach ($scripts[0] as $tag) {
        if (stripos($tag, 'src=') !== false) {
            continue;   // 外链走 script-src，不需要 nonce
        }
        $inline++;
        check(stripos($tag, 'nonce=') !== false,
            'Inline <script> must carry a nonce under CSP, found: ' . $tag);
    }
    check($inline > 0, 'The front page should contain at least one inline script to exercise the nonce path');
    // 内联事件属性在 CSP 下必然失效，代码约定里是明令禁止的
    check(preg_match('#\son(click|submit|change|input|error|load|mouseover|focus|keydown)\s*=#i', $home['body']) !== 1,
        'Inline event handlers (onclick= etc.) are forbidden — use data-* attributes with delegation');

    /* ===== 4. 后台未登录不得越入 ===== */
    $admin = http_get($portA, '/admin/index.php');
    check($admin['status'] === 302, 'The admin area must redirect when not signed in, got ' . $admin['status']);
    check(($admin['headers']['location'] ?? '') === 'install.php',
        'A brand new site must be sent to the setup wizard, got ' . ($admin['headers']['location'] ?? '(none)'));
    check(http_get($portA, '/admin/install.php')['status'] === 200, 'The setup wizard must be reachable');
    // 连登录页本身也要让位给安装向导，否则「还没建号就先撞登录页」
    $login = http_get($portA, '/admin/login.php');
    check($login['status'] === 302 && ($login['headers']['location'] ?? '') === 'install.php',
        'The login page must hand over to the setup wizard while no account exists');

    /* ===== 5. 点击统计：无鉴权但必须去重 ===== */
    $before = null;
    $readClicks = static function () use ($tmp): int {
        // 服务器进程还开着，直接读主库文件即可（WAL 由 SQLite 自动恢复）
        $pdo = new PDO('sqlite:' . $tmp . '/data/company_nav.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return (int)$pdo->query('SELECT click_count FROM links WHERE id = 1')->fetchColumn();
    };

    $c0 = $readClicks();
    $t1 = http_get($portA, '/track_click.php?id=1');
    check($t1['status'] === 204, 'track_click must answer 204, got ' . $t1['status']);
    check($readClicks() === $c0 + 1, 'The first click must be counted');

    // 同一个客户端立刻重复点同一条：去重窗口内不得再计
    http_get($portA, '/track_click.php?id=1');
    http_get($portA, '/track_click.php?id=1');
    check($readClicks() === $c0 + 1, 'Repeat clicks from the same client must be de-duplicated');

    // 换一条链接应当照常计数（去重粒度是「客户端 + 链接」，不是「客户端」）
    http_get($portA, '/track_click.php?id=2');
    check((function () use ($tmp): int {
        $pdo = new PDO('sqlite:' . $tmp . '/data/company_nav.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return (int)$pdo->query('SELECT click_count FROM links WHERE id = 2')->fetchColumn();
    })() === 1, 'A different link must still be counted');

    // 不存在的 id 不得 500，也不得污染计数
    check(http_get($portA, '/track_click.php?id=99999')['status'] === 204, 'An unknown link id must not error');
    check(http_get($portA, '/track_click.php?id=0')['status'] === 204, 'A zero id must not error');
    check(http_get($portA, '/track_click.php')['status'] === 204, 'A missing id must not error');

    /* ===== 6. 静态资源与前台渲染 ===== */
    check(http_get($portA, '/assets/css/style.css')['status'] === 200, 'Stylesheets must be served');
    check(http_get($portA, '/no-such-page.php')['status'] === 404, 'Unknown paths must 404');
    check(strpos($home['body'], '<!DOCTYPE html>') === 0, 'The front page must start with a doctype');

    /* ===== 7. 安装向导 → 进后台 → 安装页必须失效 ===== */
    // 用一个新的客户端实例：前面几节不关心会话，这里要一套干净的 Cookie。
    $c = new TestClient($portA);
    $adminCount = static function () use ($tmp): int {
        $pdo = new PDO('sqlite:' . $tmp . '/data/company_nav.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    };

    $page = $c->get('/admin/install.php');
    check($page['status'] === 200, 'The setup wizard must be reachable on a fresh site');
    // 会话 Cookie 在明文 http 下必须可用（不能带 Secure）：带的话浏览器根本不存，
    // 本地开发会永远登不进后台，而这类问题只有在真实链路里才看得见。
    check($page['setCookies'] !== [], 'The setup wizard must start a session');
    foreach ($page['setCookies'] as $cookie) {
        check(preg_match('#;\s*secure\s*(;|$)#i', $cookie) !== 1,
            'The session cookie must not be Secure over plain http: ' . $cookie);
        check(preg_match('#;\s*httponly\s*(;|$)#i', $cookie) === 1,
            'The session cookie must be HttpOnly: ' . $cookie);
    }

    check(preg_match('#name="csrf_token"\s+value="([^"]+)"#', $page['body'], $m) === 1,
        'The setup form must carry a CSRF token');
    $csrf = $m[1];

    // 缺 CSRF：页面重渲染并提示，且绝不能建号
    $noCsrf = $c->post('/admin/install.php', [
        'username' => 'owner1', 'password' => 'Str0ng-Pass', 'password2' => 'Str0ng-Pass',
    ]);
    check($adminCount() === 0, 'A POST without the CSRF token must not create an account');
    check(strpos($noCsrf['body'], '安全校验失败') !== false, 'A missing CSRF token must be reported');

    // 弱密码与两次不一致：同样只提示不建号
    foreach ([
        ['password' => '1234',    'password2' => '1234',    'expect' => '至少 8 位'],
        ['password' => 'Str0ng-Pass', 'password2' => 'Other-Pass', 'expect' => '不一致'],
    ] as $bad) {
        $r = $c->post('/admin/install.php', [
            'csrf_token' => $csrf, 'username' => 'owner1',
            'password' => $bad['password'], 'password2' => $bad['password2'],
        ]);
        check($adminCount() === 0, 'An invalid setup attempt must not create an account');
        check(strpos($r['body'], $bad['expect']) !== false,
            'The setup wizard must report: ' . $bad['expect']);
    }

    // 正式建号：本机免安装密钥
    $done = $c->post('/admin/install.php', [
        'csrf_token' => $csrf, 'username' => 'owner1',
        'password' => 'Str0ng-Pass', 'password2' => 'Str0ng-Pass',
    ]);
    check($adminCount() === 1, 'A valid setup must create exactly one account');
    check($done['status'] === 302 && ($done['headers']['location'] ?? '') === 'index.php',
        'A successful setup must land on the admin dashboard');

    // 建号即登录：后台首页应当直接打得开
    $dash = $c->get('/admin/index.php');
    check($dash['status'] === 200, 'The dashboard must open right after setup, got ' . $dash['status']);

    // 已登录时再访问安装页：让位给后台，不能停在表单上
    $again = $c->get('/admin/install.php');
    check($again['status'] === 302, 'The setup wizard must not render again while signed in');

    /* ===== 8. 登录态下的链接与分类操作 ===== */
    // 这一段复用上面建立的会话。字段名是照着页面源码写死的：
    // 表单一旦改了名字而处理端没跟着改，这里会直接红（数据层测试看不到这一层）。
    $open = static function () use ($tmp): PDO {
        return new PDO('sqlite:' . $tmp . '/data/company_nav.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    };
    $count = static function (string $table) use ($open): int {
        return (int)$open()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    };

    $dash2 = $c->get('/admin/index.php');
    check(preg_match('#name="csrf_token"\s+value="([^"]+)"#', $dash2['body'], $m2) === 1,
        'The dashboard must expose a CSRF token for its forms');
    $token = $m2[1];

    $catId   = (int)$open()->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $linksBefore = $count('links');

    // 新增链接
    $saved = $c->post('/admin/index.php', [
        'csrf_token' => $token, 'action' => 'save', 'id' => '0',
        'category_id' => (string)$catId, 'title' => '端到端测试链接',
        'url' => 'https://example.com/e2e', 'description' => '', 'icon' => '',
        'sort_order' => '99', 'is_active' => '1', 'ret_cat' => '0', 'ret_page' => '1',
    ]);
    check($saved['status'] === 302, 'Saving a link must redirect, got ' . $saved['status']);
    check($count('links') === $linksBefore + 1, 'Saving must insert exactly one link');
    $newId = (int)$open()->query("SELECT id FROM links WHERE title = '端到端测试链接'")->fetchColumn();
    check($newId > 0, 'The new link must be findable by its title');

    // 空标题：只提示，不落库
    $bad = $c->post('/admin/index.php', [
        'csrf_token' => $token, 'action' => 'save', 'id' => '0',
        'category_id' => (string)$catId, 'title' => '', 'url' => 'https://example.com/x',
        'ret_cat' => '0', 'ret_page' => '1',
    ]);
    check($count('links') === $linksBefore + 1, 'A link with an empty title must not be saved');
    $after = $c->get('/admin/index.php');
    check(strpos($after['body'], '链接标题不能为空') !== false,
        'The dashboard must surface the validation error');

    // 置顶：排序值应被压到最小，且页面仍能正常回跳
    $sorted = $c->post('/admin/index.php', [
        'csrf_token' => $token, 'action' => 'sort', 'id' => (string)$newId,
        'mode' => 'top', 'ret_cat' => '0', 'ret_page' => '1',
    ]);
    check($sorted['status'] === 302, 'Reordering must redirect, got ' . $sorted['status']);
    $first = (int)$open()->query('SELECT id FROM links ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
    check($first === $newId, 'Pinning to top must make the link the very first record');

    // 删除
    $deleted = $c->post('/admin/index.php', [
        'csrf_token' => $token, 'action' => 'delete', 'id' => (string)$newId,
        'ret_cat' => '0', 'ret_page' => '1',
    ]);
    check($deleted['status'] === 302, 'Deleting must redirect, got ' . $deleted['status']);
    check($count('links') === $linksBefore, 'Deleting must remove exactly the one link');

    // CSRF：后台任何写操作缺 token 都不许生效
    $forged = $c->post('/admin/index.php', [
        'action' => 'save', 'id' => '0', 'category_id' => (string)$catId,
        'title' => '未授权写入', 'url' => 'https://example.com/evil',
        'ret_cat' => '0', 'ret_page' => '1',
    ]);
    check($count('links') === $linksBefore, 'A POST without the CSRF token must not write anything');

    /* ---- 分类管理 ---- */
    $catPage = $c->get('/admin/categories.php');
    check($catPage['status'] === 200, 'The categories page must open, got ' . $catPage['status']);
    check(preg_match('#name="csrf_token"\s+value="([^"]+)"#', $catPage['body'], $m3) === 1,
        'The categories page must expose a CSRF token');
    $catsBefore = $count('categories');

    $c->post('/admin/categories.php', [
        'csrf_token' => $m3[1], 'action' => 'save', 'id' => '0',
        'name' => '端到端测试分类', 'icon' => '', 'sort_order' => '99', 'is_active' => '1',
    ]);
    check($count('categories') === $catsBefore + 1, 'Saving a category must insert it');
    $newCat = (int)$open()->query("SELECT id FROM categories WHERE name = '端到端测试分类'")->fetchColumn();

    // 空名称不落库
    $c->post('/admin/categories.php', [
        'csrf_token' => $m3[1], 'action' => 'save', 'id' => '0', 'name' => '',
    ]);
    check($count('categories') === $catsBefore + 1, 'A category with an empty name must not be saved');

    // 给它挂一条链接，再删分类 —— 验证级联清理不留孤儿
    $open()->prepare('INSERT INTO links (category_id, title, url, is_active) VALUES (?,?,?,1)')
        ->execute([$newCat, '待级联删除', 'https://example.com/cascade']);
    $c->post('/admin/categories.php', [
        'csrf_token' => $m3[1], 'action' => 'delete', 'id' => (string)$newCat,
    ]);
    check($count('categories') === $catsBefore, 'Deleting a category must remove it');
    check((int)$open()->query('SELECT COUNT(*) FROM links WHERE category_id = ' . $newCat)->fetchColumn() === 0,
        'Deleting a category must cascade to its links');

    /* ===== 9. 登出，且安装页必须永久失效 ===== */
    $out = $c->get('/admin/logout.php');
    check($out['status'] === 302 && ($out['headers']['location'] ?? '') === 'login.php',
        'Signing out must hand over to the login page, got ' . ($out['headers']['location'] ?? '(none)'));
    check($c->get('/admin/index.php')['status'] === 302, 'The dashboard must be closed again after sign-out');

    // 核心安全断言：站点已经装好，安装页必须彻底失效。
    // 否则任何人在任意时刻打开它就能再建一个 owner 账号。
    $reinstall = $c->get('/admin/install.php');
    check($reinstall['status'] === 302
        && ($reinstall['headers']['location'] ?? '') === 'login.php',
        'Once installed, the setup wizard must stay shut, got ' . $reinstall['status']
        . ' -> ' . ($reinstall['headers']['location'] ?? '(none)'));
    check($adminCount() === 1, 'No extra account may appear after the site is installed');

    echo 'PASS: data/ and .git/ blocked under the router (with a no-router control), '
       . 'security headers, CSP-safe markup, admin gating and click de-duplication' . PHP_EOL;
} finally {
    foreach ($servers as $proc) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            // 等它真的退出，否则端口与临时目录还在被占用
            for ($i = 0; $i < 50; $i++) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    break;
                }
                usleep(50000);
            }
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
        }
    }
    rm_tree($tmp);
}
