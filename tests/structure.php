<?php
/**
 * Run: php tests/structure.php
 *
 * 守住「config.php 拆分」与「data/ 访问拦截」这两件事，防止以后改回去：
 *   - 拆到 lib/ 的模块里的函数必须都还在（改名 / 漏 require 会立刻暴露）
 *   - 这些模块依赖 NAV_ROOT 定位 data/ 与 assets/，路径必须指向项目根
 *   - lib 模块单独引入也能用（它们内部 require_once 了 config.php）
 *   - data/demo.json 可读（seed_demo_data 的硬依赖）
 *   - data/.htaccess 必须存在且带拒绝规则（P0 修复的兜底，删了公网就裸奔）
 *   - 验证码生成 / 校验一轮回（一次性消费）
 *   - 页面骨架函数产出预期标记
 *
 * 不触碰 data/company_nav.sqlite。
 */
declare(strict_types=1);
require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/* ---------- 0. 运行环境自检 ---------- */
// 不要用 extension_loaded('pdo_sqlite') 判定：有的 PHP 构建把它静态编译进去，
// 此时 extension_loaded 返回 false 但驱动其实可用（本机就是这种情况）。
// 以 PDO::getAvailableDrivers() 为准，否则会误报。
$drivers = PDO::getAvailableDrivers();
check(in_array('sqlite', $drivers, true),
    'PDO 缺少 sqlite 驱动（现有：' . implode(',', $drivers) . '），本项目无法运行');
check(extension_loaded('openssl'), '缺少 openssl 扩展，密码保险箱无法加解密');

/* ---------- 1. 拆分后的函数必须都在 ---------- */
$moved = [
    'lib/migrate.php' => ['init_database', 'seed_demo_data'],
    'lib/captcha.php' => ['captcha_generate', 'captcha_verify', 'captcha_font_path', 'captcha_png'],
    'lib/layout.php'  => ['theme_preference', 'page_header', 'theme_toggle_html', 'favicon_html',
                          'logo_badge_html', 'auth_brand_html', 'page_footer'],
];
foreach ($moved as $file => $functions) {
    check(is_file(NAV_ROOT . '/' . $file), "拆分模块缺失：{$file}");
    foreach ($functions as $fn) {
        check(function_exists($fn), "函数 {$fn} 未定义（应来自 {$file}）");
    }
}
// 留在 config.php 的核心函数
foreach (['db', 'setting', 'save_setting', 'admin_authenticate', 'admin_is_owner',
          'throttle_fail', 'csrf_token', 'csp_nonce', 'click_is_allowed', 'security_headers'] as $fn) {
    check(function_exists($fn), "核心函数 {$fn} 未定义（应留在 config.php）");
}

/* ---------- 2. NAV_ROOT 必须指向项目根 ---------- */
check(defined('NAV_ROOT'), 'NAV_ROOT 未定义');
check(realpath(NAV_ROOT) === realpath(__DIR__ . '/..'), 'NAV_ROOT 未指向项目根目录');
check(is_readable(NAV_ROOT . '/data/demo.json'), 'data/demo.json 不可读，seed_demo_data 会失败');
check(is_dir(NAV_ROOT . '/assets'), 'assets/ 不在 NAV_ROOT 下，captcha_font_path 会失效');

/* ---------- 3. lib 模块单独引入也能用 ---------- */
// 另起进程验证，避免当前进程已定义同名函数导致 require 后「看起来通过」
$probe = <<<'PHP'
<?php
require NAV_ROOT_PLACEHOLDER . '/lib/migrate.php';
echo function_exists('init_database') && function_exists('seed_demo_data')
    && defined('CAPTCHA_TTL') ? 'OK' : 'FAIL';
PHP;
if (function_exists('shell_exec')) {
    $probeFile = tempnam(sys_get_temp_dir(), 'nav-probe-');
    file_put_contents($probeFile, str_replace('NAV_ROOT_PLACEHOLDER', var_export(NAV_ROOT, true), $probe));
    $out = trim((string)shell_exec(PHP_BINARY . ' ' . escapeshellarg($probeFile) . ' 2>&1'));
    unlink($probeFile);
    check($out === 'OK', "单独引入 lib/migrate.php 失败：{$out}");
}
// shell_exec 被禁用时（部分加固过的运行环境会禁）跳过本项，不把它当成失败：
// 本项验证的是「lib 模块自洽」，禁用 shell_exec 只是让这项没法自动验证而已。

/* ---------- 4. data/.htaccess 兜底必须存在 ---------- */
$htaccess = NAV_ROOT . '/data/.htaccess';
check(is_file($htaccess), 'data/.htaccess 缺失 —— 公网部署时数据库可能被直接下载');
// Apache 2.4 写法是 "Require all denied"，旧写法是 "Deny from all"，两种都算有效
$rules = (string)file_get_contents($htaccess);
check(preg_match('/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\s*$/mi', $rules) === 1,
    'data/.htaccess 里没有有效的拒绝规则');

/* ---------- 5. start.sh 必须用 router.php 启动 ---------- */
// 只看真正的启动命令行：注释里会提到 "-t ."，逐字搜全文会误报
$startLine = '';
foreach (explode("\n", (string)file_get_contents(NAV_ROOT . '/start.sh')) as $line) {
    if (strpos(trim($line), '#') === 0) {
        continue;
    }
    if (strpos($line, 'php -S') !== false) {
        $startLine = $line;
    }
}
check($startLine !== '', 'start.sh 里找不到 php -S 启动命令');
check(strpos($startLine, 'router.php') !== false, 'start.sh 未使用 router.php，data/ 会被当静态资源发出');
check(preg_match('/(^|\s)-t(\s|$)/', $startLine) === 0, 'start.sh 仍在用 -t 文档根模式');

/* ---------- 6. 验证码一轮回 ---------- */
$_SESSION = [];
$code = captcha_generate(4);
check(strlen($code) === 4, '验证码长度不为 4');
check(captcha_verify($code) === true, '正确验证码未通过校验');
// 一次性：同一个答案再用一次必须失败
$_SESSION = [];
$code2 = captcha_generate(4);
check(captcha_verify($code2) === true, '第二轮正确验证码未通过校验');
check(captcha_verify($code2) === false, '验证码未被一次性消费（可重放）');
$_SESSION = [];
captcha_generate(4);
check(captcha_verify('ZZZZ') === false, '错误验证码竟然通过了');

/* ---------- 7. 页面骨架产出 ---------- */
check(strpos(favicon_html('assets/'), 'appFavicon') !== false, 'favicon_html 产出缺少 appFavicon');
check(strpos(favicon_html('../assets/'), 'data-fav-base="../assets/"') !== false, 'favicon_html 前缀拼接错误');
check(strpos(logo_badge_html(), 'brand-logo') !== false, 'logo_badge_html 产出缺少 brand-logo');
check(strpos(theme_toggle_html(), 'themeToggle') !== false, 'theme_toggle_html 产出缺少 themeToggle');
check(strpos(auth_brand_html('标题', '副标题', ['卖点']), 'auth-points') !== false, 'auth_brand_html 未渲染卖点列表');
check(strpos(auth_brand_html('标题', '副标题', [], '注脚'), 'auth-brand-foot') !== false, 'auth_brand_html 未渲染注脚');

/* ---------- 8. CSP 前置条件 ---------- *
 * 这些是把 csp_mode 从 'report' 切到 'enforce' 的前提。任何一条被破坏，
 * 切换后页面功能就会真的坏掉，所以在这里钉死。
 */
$scanTargets = [];
foreach (['.', 'admin', 'lib'] as $dir) {
    foreach (glob(NAV_ROOT . ($dir === '.' ? '' : '/' . $dir) . '/*.php') ?: [] as $file) {
        $scanTargets[] = $file;
    }
}

foreach ($scanTargets as $file) {
    $rel = ltrim(str_replace(NAV_ROOT, '', $file), '/');
    // 用 token 去掉注释后再扫：否则 config.php 文档注释里那些说明性的 <script> 会被误判。
    // 这样扫到的才是真正会输出给浏览器的标签。
    $clean = '';
    foreach (token_get_all((string)file_get_contents($file)) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;
            }
            $clean .= $tok[1];
        } else {
            $clean .= $tok;
        }
    }

    // 8a. 内联脚本必须带 nonce；外链脚本（有 src）走 'self'，不需要 nonce
    if (preg_match_all('/<script\b[^>]*>/i', $clean, $m) > 0) {
        foreach ($m[0] as $tag) {
            $isExternal = preg_match('/\bsrc\s*=/i', $tag) === 1;
            $hasNonce   = stripos($tag, 'nonce') !== false;
            check($isExternal || $hasNonce,
                "{$rel} 有内联 <script> 未带 nonce：{$tag}（应为 <script<?= nonce_attr() ?>>）");
        }
    }

    // 8b. 禁止内联事件属性：CSP 下它们会被直接拦掉
    check(preg_match('/\son(click|submit|change|load|error|mouseover|mouseout|focus|blur|input|keyup|keydown|dblclick)\s*=/i', $clean) === 0,
        "{$rel} 出现内联事件属性（onclick / onsubmit 等），CSP 生效后会被拦下，请改用 data-* + 事件委托");

    // 8c. 禁止 eval / new Function：需要 'unsafe-eval' 才能跑，本项目策略里没有
    check(preg_match('/\b(eval\s*\(|new\s+Function\s*\()/i', $clean) === 0,
        "{$rel} 出现 eval / new Function，CSP 策略未开放 unsafe-eval");
}

// 8d. CSP 策略本身必须含 nonce，且不能出现放得过宽的通配
$policy = csp_policy();
check(strpos($policy, "'nonce-") !== false, 'CSP 策略缺少 nonce 来源');
check(strpos($policy, "script-src *") === false, 'CSP script-src 过于宽松');
check(strpos($policy, "default-src *") === false, 'CSP default-src 过于宽松');
check(strpos($policy, "object-src 'none'") !== false, 'CSP 未禁用 object-src');

/* ---------- 9. 默认密码必须彻底消失 ---------- *
 * 此前 config.php 里有一份明文管理员密码，建库时会自动播种成首位管理员，
 * 而 README 又印着同一串字符 —— 默认密码等于公开密码。整改后：
 *   - config.php 不再持有任何密码
 *   - 全新库不播种账号，改由 admin/install.php 引导创建
 *   - 文档里不得再出现可用的默认凭据
 * 这几条一旦被破坏，风险会静默回归，所以在这里钉死。
 */
$adminCfg = (string)file_get_contents(NAV_ROOT . '/config.php');
check(strpos($adminCfg, "admin_config['password']") === false,
    'config.php 又出现了 $admin_config[\'password\'] —— 明文默认密码会随之回归');
check(strpos($adminCfg, "'demo12345'") === false, 'config.php 里仍残留演示密码明文');

foreach (['admin/install.php', 'admin/password.php'] as $page) {
    check(is_file(NAV_ROOT . '/' . $page), "缺失 {$page}");
}

// 全新库不得自带账号：这条断言直接对应 admin_needs_install() 的语义
$freshFile = tempnam(sys_get_temp_dir(), 'nav-fresh-');
try {
    $fresh = new PDO('sqlite:' . $freshFile);
    init_database($fresh);
    check((int)$fresh->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0,
        '全新库竟然自带管理员账号 —— 默认密码风险回归');
} finally {
    $fresh = null;
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($freshFile . $suffix)) {
            unlink($freshFile . $suffix);
        }
    }
}

// 文档里不能再出现「账号 + 明文密码」的组合，否则等于把钥匙印在说明书上
foreach (['README.md', 'docs/deploy.md'] as $doc) {
    $path = NAV_ROOT . '/' . $doc;
    if (!is_file($path)) {
        continue;
    }
    check(preg_match('/["\'`]demo12345["\'`]/', (string)file_get_contents($path)) === 0,
        "{$doc} 里仍写着演示密码明文");
}

echo 'PASS: config split, NAV_ROOT paths, data protection, captcha round-trip, layout markup, CSP prerequisites and no-default-password' . PHP_EOL;
