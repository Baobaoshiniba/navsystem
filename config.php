<?php
/**
 * 公司导航页 - 全局配置与核心基础设施
 *
 * 职责边界：
 *  - 本文件：站点配置、数据库连接、站点设置读写、账号与角色、登录限流、会话与 CSRF、
 *            点击统计防护、CSP 与安全响应头；
 *  - lib/migrate.php：建表 / 加列 / 演示数据播种；
 *  - lib/captcha.php：登录图形验证码（生成 / 校验 / 绘图）；
 *  - lib/layout.php ：页面头尾、主题切换、品牌区渲染；
 *  - lib/favicon.php：站点图标抓取与本地缓存；
 *  - lib/i18n.php / lib/ui.php：语言选择与图标集。
 *
 * 说明：
 *  - 首次运行 index.php / admin/login.php 时会自动初始化 SQLite 数据库并写入演示数据；
 *  - 管理员账号由 $admin_config 写入 admin_users 表（password_hash 加密存储），之后可在后台
 *    「重置演示」弹窗中自助修改；仅当库内没有任何账号时才回退 $admin_config 校验；
 *  - 修改站点标题等配置后刷新页面即可生效。
 */

declare(strict_types=1);

/**
 * 项目根目录：lib/ 下的模块用它定位 data/、assets/ 等不在自身目录下的资源
 * （那些文件里的 __DIR__ 指向 lib/，不能直接用）。
 */
define('NAV_ROOT', __DIR__);

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/ui.php';

// 以下三个模块已从本文件拆出，各自职责见文件头注释：
//   lib/migrate.php 建表 / 加列 / 演示数据播种
//   lib/captcha.php 登录图形验证码（生成 / 校验 / 绘图）
//   lib/layout.php  页面头尾、主题切换、品牌区渲染
// 它们内部都 require_once 了本文件。由本文件加载时，PHP 会把本文件视为「已包含」而跳过，
// 不会造成循环执行；单独引入它们（如测试脚本）时也能自动拉起本文件。
require_once __DIR__ . '/lib/migrate.php';
require_once __DIR__ . '/lib/captcha.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/backup.php';

// 站点基本配置（亦是站点设置默认文案；前台实际展示文案可在后台「站点设置」中覆盖）
$config = [
    // 公司名称（页面标题 / 主视觉标题等）
    'site_name'          => '小组项目导航',
    // Logo 显示文字（顶栏品牌区，可与公司名称不同）
    'logo_text'          => '小组工作台',
    // 副标题 / 欢迎语
    'site_slogan'        => '协作、开发与学习资源，一处直达',
    // 聚合搜索栏输入框提示语（{engine} 会替换为当前选中的引擎名）
    'search_placeholder' => '在 {engine} 中搜索…',
    // 页脚版权 / 公司信息
    'site_footer'        => '小组项目导航 © 2026 · 一起协作，高效完成',

    // ---- 点击统计防护 ----
    // 同一客户端对同一链接的计数最小间隔（秒）。窗口内重复上报只计一次。
    // 设为 0 表示关闭去重（回到「每次请求 +1」的行为）。
    'click_dedup_window'    => 86400,
    // 单个客户端每小时最多可计数的「不同链接」数量，用于阻止全表遍历刷数。
    // 设为 0 表示不限制。
    'click_max_links_per_hour' => 200,

    // ---- 内容安全策略（CSP）----
    // 取值三选一：
    //   'off'     —— 完全不发送 CSP
    //   'report'  —— 只发 Content-Security-Policy-Report-Only，违规仅上报不拦截
    //   'enforce' —— 正式生效，违规脚本会被浏览器直接拦下（当前值）
    //
    // 已于 2026-10-25 从 'report' 切到 'enforce'。切换前的前置条件由
    // tests/structure.php 第 8 节钉死（内联脚本必须带 nonce、禁止内联事件属性、
    // 禁止 eval / new Function），改动代码后请先跑 ./tests/run.sh。
    //
    // 若打开页面后发现主题切换 / 语言切换 / 二次确认弹窗 / 验证码换图异常，
    // 把这里改回 'report' 即可立刻恢复，然后再排查具体是哪条指令被拦。
    'csp_mode' => 'enforce',
];

// 管理后台登录配置
//
// 这里没有 password 一项，是故意的。此前密码以明文写在这里，会在建库时被自动播种成
// 首位管理员，而 README 里印着同一串字符 —— 等于把后台钥匙公开，且因为「改密码要先清空
// 站点数据」，实际几乎没人会去改它。
//
// 现在的流程：全新库不播种任何账号，首次访问后台会被引导到 admin/install.php
// 自行设置站长账号与密码。老库升级不受影响，认证一律以库内 admin_users 为准。
$admin_config = [
    // 仅用作安装向导里用户名输入框的预填值，不再参与任何认证
    'username' => 'admin',
    'session_name' => 'company_nav_admin_session',
];

// 数据库文件路径（默认位于项目根目录 data/ 下）
$db_config = [
    'file' => __DIR__ . '/data/company_nav.sqlite',
];

/* ---- 管理员角色 ----
 * owner  ：全部权限（站点设置、重置演示、密码保险箱、链接与分类）
 * editor ：仅维护链接与分类
 * 注册新账号一律为 editor；首位 owner 由 admin/install.php 安装向导创建。 */
define('ROLE_OWNER', 'owner');
define('ROLE_EDITOR', 'editor');

// 管理员密码最小长度。安装向导 / 独立改密页 / 重置演示页三处统一取这个值，
// 避免出现「A 页面要求 8 位、B 页面 6 位就放行」的口径不一。
define('ADMIN_PASSWORD_MIN_LENGTH', 8);

// ---- 登录防护阈值（见 lib 内 throttle_* 系列函数）----
// 账号或密码连续错误的次数上限，达到即锁定
define('LOGIN_FAIL_LIMIT', 3);
// 单次锁定的基准时长（秒）；反复触发按 2 的倍数递增
define('LOGIN_LOCK_BASE_SECONDS', 30);
// 验证码连续错误的次数上限（单独计数，不与密码错误混用）
define('LOGIN_CAPTCHA_FAIL_LIMIT', 10);
// 验证码触发锁定的基准时长（秒）
define('LOGIN_CAPTCHA_LOCK_SECONDS', 300);

// 注册限流：同一 IP 在锁定窗口内最多创建的账号数。
// 注意这里对「注册成功」也计数 —— 只统计失败的话，脚本每次都能成功，照样能批量开号。
define('REGISTER_MAX_PER_WINDOW', 5);
// 达到上限后的锁定时长（秒）
define('REGISTER_LOCK_SECONDS', 600);

// 链接图标（favicon）抓取与本地缓存模块：三级优先级 + SSRF 防护 + 落盘本地
require_once __DIR__ . '/lib/favicon.php';

/* =====================================================================
 * 以下为基础设施函数，一般无需修改
 * ===================================================================== */

/**
 * 获取 PDO 连接，首次调用时自动建表并写入演示数据
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $db_config;

    $dbFile = $db_config['file'];
    $dbDir  = dirname($dbFile);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }

    $firstRun = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // 多 worker（多进程）并发访问先设忙等超时，再切 WAL，避免瞬时锁冲突直接抛 SQLITE_BUSY
    $pdo->exec('PRAGMA busy_timeout = 60000;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    // SQLite 默认不启用外键约束，此处显式开启，保证删除分类时 ON DELETE CASCADE 生效
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // 建表与 settings 默认值：每次连接都幂等执行（CREATE IF NOT EXISTS / INSERT OR IGNORE），
    // 保证老库也能平滑升级出新表；演示数据仅首次运行写入。
    init_database($pdo);
    if ($firstRun) {
        seed_demo_data($pdo);
    }

    return $pdo;
}

/**
 * 站点设置默认值（key => value）
 *
 * 与顶部 $config 保持一致，同时作为「站点设置」的默认兜底文案；
 * 后台保存到 settings 表后，前台以 settings 表为准（空值回退此处默认）。
 */
function site_settings_defaults(): array
{
    global $config;
    return [
        'site_name'          => $config['site_name']          ?? '小组项目导航',
        'logo_text'          => $config['logo_text']          ?? '小组工作台',
        'site_slogan'        => $config['site_slogan']        ?? '协作、开发与学习资源，一处直达',
        'search_placeholder' => $config['search_placeholder'] ?? '在 {engine} 中搜索…',
        'footer_text'        => $config['site_footer']        ?? '小组项目导航 © 2026 · 一起协作，高效完成',
        'site_name_en'          => 'Team Resource Hub',
        'logo_text_en'          => 'Team Workspace',
        'site_slogan_en'        => 'Your shared space for collaboration, development and learning',
        'search_placeholder_en' => 'Search with {engine}…',
        'footer_text_en'        => 'Team Resource Hub © 2026 · Work better together',

    ];
}

/**
 * 站点设置缓存（按引用共享）
 *
 * 之所以不用「函数内 static」而要走引用：那样 save_setting() 写完库后，
 * 同一个请求里后续的 raw_setting() 仍会读到旧值（缓存无失效途径）。
 * 现在 save_setting() 会同步更新这份缓存，读写始终一致。
 *
 * @return array<string,string>
 */
function &raw_setting_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT key, value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['key']] = (string)$row['value'];
            }
        } catch (Throwable $e) {
            // DB 不可用时静默降级，由默认值兜底
        }
    }
    return $cache;
}

/**
 * 读取站点设置（优先 settings 表；空值/缺省回退默认文案）
 */
function raw_setting(string $key, bool $useDefault = true): string
{
    $cache = &raw_setting_cache();
    if (isset($cache[$key]) && $cache[$key] !== '') {
        return $cache[$key];
    }
    $defaults = site_settings_defaults();
    return $useDefault ? ($defaults[$key] ?? '') : '';
}

/** Localize display settings only; editing always reads raw values. */
function setting(string $key): string
{
    if (locale() === 'en' && in_array($key, ['site_name', 'logo_text', 'site_slogan', 'search_placeholder', 'footer_text'], true)) {
        $english = raw_setting($key . '_en', false);
        if (trim($english) !== '') return $english;
    }
    return raw_setting($key);
}

/**
 * 写入单条站点设置（key 不存在则插入，存在则更新）
 *
 * 仅按需写入（如注册开关），不并入 site_settings_defaults() 的默认文案集合，
 * 因此「重置演示 / 清空为空白」仍沿用原有 5 项文案键，行为不变。
 */
function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
    );
    $stmt->execute([$key, $value]);

    // 同步刷新读取缓存与引擎清单缓存，保证同一请求内读到的一定是刚写入的值
    $cache = &raw_setting_cache();
    $cache[$key] = $value;
    search_engines_reset();
}

/**
 * 是否开放后台注册（settings 键 register_enabled，'1' 为开启）
 *
 * 默认关闭：键不存在或值为空时一律视为关闭，需在「站点设置」页手动开启。
 */
function register_enabled(): bool
{
    return setting('register_enabled') === '1';
}

/* =====================================================================
 * 聚合搜索（前台顶部搜索栏 → 跳转外部搜索引擎）
 *
 *  - 引擎清单存于 settings 键 search_engines，格式：每行「名称|URL模板」，
 *    模板必须为 http/https 且含 {q} 占位符（替换为 encodeURIComponent 后的关键词）；
 *  - 默认引擎存于 settings 键 search_engine_default（存名称，非法时回退首个）；
 *  - 两个键均为「按需写入」，不并入 site_settings_defaults()，键缺失/空值一律回退内置默认，
 *    因此不改动「重置演示 / 清空为空白」既有行为。
 * ===================================================================== */

/**
 * 内置默认引擎清单（名称 + 搜索地址模板，{q} 为关键词占位符）
 */
function default_search_engines(): array
{
    return [
        ['name' => 'Google', 'url' => 'https://www.google.com/search?q={q}'],
        ['name' => 'Bing', 'url' => 'https://www.bing.com/search?q={q}'],
        ['name' => 'Google Scholar', 'url' => 'https://scholar.google.com/scholar?q={q}'],
        ['name' => 'GitHub', 'url' => 'https://github.com/search?q={q}'],
    ];
}

/**
 * 内置默认引擎清单的文本形式（后台设置页 placeholder 提示用）
 */
function default_search_engines_text(): string
{
    $lines = [];
    foreach (default_search_engines() as $eng) {
        $lines[] = $eng['name'] . '|' . $eng['url'];
    }
    return implode("\n", $lines);
}

/**
 * 解析引擎清单文本
 *
 * 规则：逐行解析「名称|URL模板」；名称为空、缺分隔符、模板不含 {q}、
 *       模板非 http/https（含 javascript: / data: 等）一律忽略；同名去重（保留首个）。
 * 返回：[['name' => .., 'url' => ..], ...]；无任何有效行时返回空数组，由调用方回退默认清单。
 */
function parse_search_engines(string $raw): array
{
    $out   = [];
    $seen  = [];
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^([^|]*)\|(.+)$/u', $line, $m)) {
            continue;
        }
        $name = trim($m[1]);
        $url  = trim($m[2]);
        // 名称：剔除控制字符并限长，防止注入样式/换行
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        if ($name === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($name) > 24) {
            $name = mb_substr($name, 0, 24);
        }
        // 模板：仅允许 http/https + 必须含 {q}
        if (strpos($url, '{q}') === false || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $out[] = ['name' => $name, 'url' => $url];
    }
    return $out;
}

/**
 * 当前生效的引擎清单（settings 表 search_engines 优先；无有效配置回退内置默认）
 */
function &search_engines_cache(): ?array
{
    static $cache = null;
    return $cache;
}

function search_engines(): array
{
    $cache = &search_engines_cache();
    if ($cache === null) {
        $parsed = parse_search_engines(setting('search_engines'));
        $cache  = $parsed ?: default_search_engines();
    }
    return $cache;
}

/** 配置变更后丢弃引擎清单缓存，下一次读取时重新解析 */
function search_engines_reset(): void
{
    $cache = &search_engines_cache();
    $cache = null;
}

/**
 * 当前默认选中的引擎名（settings 键 search_engine_default；非法/缺失回退首个）
 */
function default_search_engine_name(): string
{
    $engines = search_engines();
    $want    = setting('search_engine_default');
    foreach ($engines as $eng) {
        if ($eng['name'] === $want) {
            return $want;
        }
    }
    return $engines[0]['name'] ?? '';
}

/**
 * 聚合搜索输入框提示语（{engine} 替换为指定引擎名）
 */
function search_input_placeholder(string $engineName): string
{
    $tpl = trim((string)setting('search_placeholder'));
    // 兼容旧版历史值：旧配置里的该文案是站内搜索提示、不含 {engine} 占位，
    // 这类值直接回退内置模板，确保提示语始终随当前引擎变化（后台提示已说明 {engine} 用法）。
    if ($tpl === '' || strpos($tpl, '{engine}') === false) {
        $tpl = locale() === 'en' ? 'Search with {engine}…' : '在 {engine} 中搜索…';
    }
    return str_replace('{engine}', t($engineName), $tpl);
}

/* =====================================================================
 * 管理员账号（admin_users 表）
 *  - 密码统一以 password_hash() 存储，校验用 password_verify()；
 *  - 登录优先校验库内账号，仅当库内无任何账号时才回退 config.php 的 $admin_config。
 * ===================================================================== */

/**
 * 读取库内管理员账号（不存在返回 null）
 */
function admin_find_user(string $username): ?array
{
    if ($username === '') {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * 库内管理员账号数量（0 表示尚未入库，走 config.php 兜底）
 */
function admin_user_count(): int
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 当前管理员用户名（弹窗预填用）：库内有账号取库内首个，否则回退 config.php
 */
function admin_current_username(): string
{
    global $admin_config;
    try {
        $name = db()->query('SELECT username FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if (is_string($name) && $name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
        // 库不可用时回退配置
    }
    return (string)$admin_config['username'];
}

/* =====================================================================
 * 管理员角色分级
 * ---------------------------------------------------------------------
 * owner  ：全部权限（站点设置 / 重置演示 / 密码保险箱 / 链接与分类）
 * editor ：只能维护链接与分类
 *
 * 「开放注册」一旦开启，任何人都能自助建号。若注册出来的账号也是 owner，
 * 就等于把整个后台连同保险箱一起对外开放 —— 因此注册一律产出 editor，
 * 只有首次安装由 config.php 播种的那个账号是 owner。
 * ===================================================================== */

/** 合法角色清单 */
function admin_role_list(): array
{
    return [ROLE_OWNER, ROLE_EDITOR];
}

/** 清洗并校验角色值，非法值一律按 owner 处理（不因脏数据把账号降级到 editor 之外） */
function admin_role_normalize(?string $role): string
{
    $role = strtolower(trim((string)$role));
    return in_array($role, admin_role_list(), true) ? $role : ROLE_OWNER;
}

/** 读取指定用户名在库内的角色；库内无该账号时按 owner 兜底（兼容 config.php 明文账号的旧库） */
function admin_role_for(string $username): string
{
    if ($username === '') {
        return ROLE_OWNER;
    }
    try {
        $user = admin_find_user($username);
    } catch (Throwable $e) {
        return ROLE_OWNER;
    }
    if ($user === null) {
        return ROLE_OWNER;
    }
    return admin_role_normalize((string)($user['role'] ?? ROLE_OWNER));
}

/** 当前登录会话的角色；会话里没有记录时按 owner 兜底，保证升级前已登录的会话不受影响 */
function admin_current_role(): string
{
    return admin_role_normalize((string)($_SESSION['admin_role'] ?? ROLE_OWNER));
}

/** 当前会话是否为站长（owner） */
function admin_is_owner(): bool
{
    return admin_current_role() === ROLE_OWNER;
}

/* =====================================================================
 * 基础设施函数：后台登录失败限流（双维度 + 指数退避）
 * ---------------------------------------------------------------------
 * 以「维度 + 主体摘要」为键统计连续失败次数，达到上限后锁定；登录成功即清零。
 * 三个维度各自独立计数：
 *   ip      —— 挡住来自同一来源的批量尝试
 *   user    —— 挡住分布在不同 IP、针对同一个账号的撞库
 *   captcha —— 验证码自身的错误，单独计数，不与密码错误混在同一个桶里
 * IP 与用户名都不落明文，只存 sha256 摘要。
 * ===================================================================== */

/**
 * 获取客户端 IP
 *
 * 站点由 nginx + php-fpm 直连，REMOTE_ADDR 即真实客户端地址且不可伪造，
 * 故不采信 X-Forwarded-For，避免被绕过限流。
 */
function login_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

/** 某个限流维度下主体值的摘要 */
function throttle_hash(string $scope, string $subject): string
{
    return hash('sha256', 'company-nav|throttle|' . $scope . '|' . $subject);
}

/** 读取限流记录 */
function throttle_row(string $scope, string $subject): ?array
{
    $stmt = db()->prepare(
        'SELECT fail_count, strikes, locked_until FROM login_throttle WHERE scope = ? AND subject_hash = ?'
    );
    $stmt->execute([$scope, throttle_hash($scope, $subject)]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 剩余锁定秒数；0 表示当前未被锁定
 */
function throttle_remaining(string $scope, string $subject): int
{
    $row   = throttle_row($scope, $subject);
    $until = $row ? (int)$row['locked_until'] : 0;
    $left  = $until - time();
    return $left > 0 ? $left : 0;
}

/**
 * 记录一次失败
 *
 * @return int 剩余可尝试次数；返回 0 表示本次已触发锁定
 */
function throttle_fail(string $scope, string $subject, int $limit, int $baseSeconds): int
{
    $pdo   = db();
    $key   = throttle_hash($scope, $subject);
    $limit = max(1, $limit);

    // 顺带清理一天前的陈旧记录，避免表无限增长
    $pdo->exec("DELETE FROM login_throttle WHERE updated_at < datetime('now', '-1 day')");

    $row     = throttle_row($scope, $subject);
    $count   = $row ? (int)$row['fail_count'] + 1 : 1;
    $strikes = $row ? (int)$row['strikes'] : 0;
    $until   = 0;

    if ($count >= $limit) {
        // 指数退避：第 n 次触发锁定时长 = base * 2^(n-1)，封顶 32 倍，避免被无限放大
        $strikes++;
        $until = time() + ($baseSeconds * (1 << min($strikes - 1, 5)));
        $count = 0;                 // 触发锁定后计数归零，锁定期满重新累计
    }

    $stmt = $pdo->prepare(
        'INSERT INTO login_throttle (scope, subject_hash, fail_count, strikes, locked_until, updated_at)
         VALUES (?, ?, ?, ?, ?, datetime(\'now\'))
         ON CONFLICT(scope, subject_hash) DO UPDATE SET
           fail_count = excluded.fail_count, strikes = excluded.strikes,
           locked_until = excluded.locked_until, updated_at = excluded.updated_at'
    );
    $stmt->execute([$scope, $key, $count, $strikes, $until]);

    return $until > 0 ? 0 : ($limit - $count);
}

/**
 * 清空某个维度的失败记录（登录成功、或验证码答对时调用）
 */
function throttle_clear(string $scope, string $subject): void
{
    $stmt = db()->prepare('DELETE FROM login_throttle WHERE scope = ? AND subject_hash = ?');
    $stmt->execute([$scope, throttle_hash($scope, $subject)]);
}

/**
 * 某个用户名当前的锁定剩余秒数（0 表示未被锁定）
 */
function throttle_user_remaining(string $username): int
{
    if ($username === '') {
        return 0;
    }
    return throttle_remaining('user', strtolower($username));
}

/**
 * 当前 IP 的锁定剩余秒数（0 表示未被锁定）
 */
function throttle_ip_remaining(): int
{
    return throttle_remaining('ip', login_client_ip());
}

/**
 * 校验管理员账号密码
 *
 * 一律以 admin_users 表为准（password_verify）。库内还没有账号（即尚未完成安装）时
 * 直接返回 false —— 此前这里会回退 $admin_config 里的明文密码，而 README 又印着同一串
 * 字符，等于把后台钥匙公开。现在首位管理员必须由 admin/install.php 自行设置。
 */
function admin_authenticate(string $username, string $password): bool
{
    if ($username === '' || $password === '') {
        return false;
    }

    $user = admin_find_user($username);

    return $user !== null && password_verify($password, (string)$user['password_hash']);
}

/**
 * 站点是否尚未完成安装（admin_users 表内一个账号都没有）
 *
 * 为真时后台所有页面都应跳到 admin/install.php，由访问者设置首位管理员。
 */
function admin_needs_install(): bool
{
    return admin_user_count() === 0;
}

/**
 * 当前登录账号是否仍在使用初始密码、应当尽快改掉
 *
 * 只有老库升级的存量账号会被置上这个标记（见 lib/migrate.php 的加列逻辑）——
 * 我们无法从哈希反推它是不是当年那个明文默认密码，所以一律提醒一次，改完即消除。
 */
function admin_must_change_password(): bool
{
    $username = (string)($_SESSION['admin_username'] ?? '');
    if ($username === '') {
        return false;
    }
    $user = admin_find_user($username);

    return $user !== null && (int)($user['must_change_password'] ?? 0) === 1;
}

/**
 * 清除「需要改密」标记：改密成功或安装向导建号成功后调用
 */
function admin_mark_password_changed(string $username): void
{
    db()->prepare(
        "UPDATE admin_users SET must_change_password = 0, updated_at = datetime('now') WHERE username = ?"
    )->execute([$username]);
}

/**
 * 安装向导专用：创建首位管理员
 *
 * 与 admin_create_user()（注册流程，恒为 editor）不同，这里建的是 owner，
 * 且必须显式给出密码 —— 不允许空密码建号。
 *
 * @throws RuntimeException 用户名非法 / 密码过短 / 站点已完成安装
 */
function admin_install_owner(string $username, string $password): void
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException(t('管理员用户名不能为空。'));
    }
    if (strlen($username) > 32) {
        throw new RuntimeException(t('管理员用户名最长 32 个字符。'));
    }
    if (strlen($password) < ADMIN_PASSWORD_MIN_LENGTH) {
        throw new RuntimeException(t('新密码长度至少 8 位。'));
    }
    if (!admin_needs_install()) {
        // 站点已装好：绝不允许再建第二个 owner，否则任何人都能把自己提权成站长。
        throw new RuntimeException(t('站点已完成安装，请直接登录。'));
    }

    db()->prepare(
        'INSERT INTO admin_users (username, password_hash, role, must_change_password, created_at, updated_at)
         VALUES (?, ?, ?, 0, datetime(\'now\'), datetime(\'now\'))'
    )->execute([$username, password_hash($password, PASSWORD_DEFAULT), ROLE_OWNER]);
}

/**
 * 写入 / 更新管理员账号（入库，password_hash 存储）
 *
 * @param string $username 用户名（不可为空）
 * @param string $password 新密码；留空表示保持原密码不变（库内无记录时用 config.php 密码兜底）
 */
function admin_save_account(string $username, string $password): void
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException(t('管理员用户名不能为空'));
    }

    $pdo      = db();
    $rows     = $pdo->query('SELECT * FROM admin_users ORDER BY id ASC')->fetchAll();
    $existing = null;
    foreach ($rows as $row) {
        if ((string)$row['username'] === $username) {
            $existing = $row;
            break;
        }
    }
    // 改名场景：库内已有账号但用户名不匹配时，沿用第一条记录改名（后台始终只有一名管理员）
    if ($existing === null && $rows) {
        $existing = $rows[0];
    }

    if ($password === '') {
        if ($existing === null) {
            // 库内还没有账号：不允许用空密码建号，必须显式给一个密码
            throw new RuntimeException(t('尚未创建管理员账号，请先完成安装向导。'));
        }
        $hash         = (string)$existing['password_hash'];
        $passwordGone = false;
    } else {
        $hash         = password_hash($password, PASSWORD_DEFAULT);
        $passwordGone = true;
    }

    if ($existing) {
        // 只有密码真的换了才清「需要改密」标记 —— 仅改用户名不算数，
        // 否则老库用户在重置演示弹窗里改个用户名就把安全提醒糊弄过去了。
        $sql = $passwordGone
            ? 'UPDATE admin_users SET username = ?, password_hash = ?, must_change_password = 0,
                updated_at = datetime(\'now\') WHERE id = ?'
            : 'UPDATE admin_users SET username = ?, password_hash = ?, updated_at = datetime(\'now\') WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$username, $hash, (int)$existing['id']]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (username, password_hash, created_at, updated_at)
         VALUES (?, ?, datetime(\'now\'), datetime(\'now\'))'
    );
    $stmt->execute([$username, $hash]);
}

/**
 * 新增管理员账号（注册流程专用，password_hash 存储）
 *
 * 与 admin_save_account() 的「单账号改名 / 改密」语义不同：此处始终新增一条记录，
 * 用户名重复时抛错，由调用方转成表单提示。
 * 角色恒为 editor —— 见「管理员角色分级」的取舍说明。
 *
 * 密码长度在这里也校验一遍：注册页的校验属于页面层，换一个调用点就能绕开，
 * 数据层再兜一次才挡得住。
 *
 * @throws RuntimeException 用户名为空 / 用户名已存在 / 密码过短 / 数据库约束失败
 */
function admin_create_user(string $username, string $password): void
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException(t('用户名不能为空。'));
    }
    $pwdLen = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($pwdLen < ADMIN_PASSWORD_MIN_LENGTH) {
        throw new RuntimeException(t('新密码长度至少 8 位。'));
    }
    if (admin_find_user($username) !== null) {
        throw new RuntimeException(t('该用户名已被注册，请换一个试试。'));
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO admin_users (username, password_hash, role, created_at, updated_at)
             VALUES (?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), ROLE_EDITOR]);
    } catch (PDOException $e) {
        // UNIQUE 约束兜底（并发注册同一用户名）
        throw new RuntimeException(t('该用户名已被注册，请换一个试试。'));
    }
}

/**
 * 开启 Session（安全属性）
 */
function secure_session_start(): void
{
    global $admin_config;
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name($admin_config['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // HTTPS 站点必须带 Secure，否则会话 Cookie 会被浏览器经明文 HTTP 请求携带
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        // 后台位于 /admin/ 子目录，显式声明根路径避免会话被限定在子目录内
        'path'     => '/',
    ]);
    session_start();
}

/**
 * 当前请求是否为 POST
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * 生成 CSRF Token（存在 Session 中）
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 输出隐藏的 CSRF 字段
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * 校验 CSRF Token
 */
function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * HTML 转义
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 从表单取值，去除首尾空白；可给默认值
 */
function post(?string $key, string $default = ''): string
{
    if ($key === null) {
        return $default;
    }
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * 简单友好的重定向
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/* =====================================================================
 * 基础设施函数：点击统计去重与限流
 * ---------------------------------------------------------------------
 * track_click.php 原本对任意 GET 请求无条件 +1，任何人都能把任意链接的点击数
 * 刷到任意值。此处补上两层约束：
 *   ① 同客户端 + 同链接，在 click_dedup_window 窗口内只计一次；
 *   ② 同客户端每小时最多对 click_max_links_per_hour 个不同链接计数，防止遍历 ID 全表刷数。
 *
 * 说明：为何不用 Referer 校验？因为 navigator.sendBeacon() 的 Referer 会被部分
 *       隐私插件与浏览器策略剥离，硬性校验会漏计真实用户的点击，反而让统计更不准。
 *       IP 去重不依赖客户端配合，对本场景性价比更高。
 * ===================================================================== */

/**
 * 当前客户端 IP 的存储摘要（不落明文）
 */
function click_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', 'company-nav|click|' . $ip);
}

/**
 * 读取点击统计防护配置
 *
 * @return array{window:int,hourly:int}
 */
function click_limits(): array
{
    global $config;
    return [
        'window' => max(0, (int)($config['click_dedup_window'] ?? 86400)),
        'hourly' => max(0, (int)($config['click_max_links_per_hour'] ?? 200)),
    ];
}

/**
 * 本次点击是否可以计数
 */
function click_is_allowed(int $linkId): bool
{
    if ($linkId <= 0) {
        return false;
    }

    $limits = click_limits();
    // 两层约束都显式关闭时，退化为原行为（每次请求都计数）
    if ($limits['window'] <= 0 && $limits['hourly'] <= 0) {
        return true;
    }

    $pdo  = db();
    $hash = click_ip_hash();
    $now  = time();

    // 顺带清理陈旧行，避免表无限增长（保留窗口至少要能覆盖「每小时预算」的统计区间，
    // 因此取 max(3600, window)，否则预算统计会因为过早清理而失真）
    $pdo->prepare('DELETE FROM click_throttle WHERE clicked_at < ?')
        ->execute([$now - max(3600, $limits['window'])]);

    // ① 同一客户端对同一链接：窗口内重复上报不再计数
    if ($limits['window'] > 0) {
        $stmt = $pdo->prepare('SELECT clicked_at FROM click_throttle WHERE ip_hash = ? AND link_id = ?');
        $stmt->execute([$hash, $linkId]);
        $last = $stmt->fetchColumn();
        if ($last !== false && ($now - (int)$last) < $limits['window']) {
            return false;
        }
    }

    // ② 同一客户端近一小时已计数的不同链接数达上限：拒绝（防 ID 遍历）
    if ($limits['hourly'] > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM click_throttle WHERE ip_hash = ? AND clicked_at > ?');
        $stmt->execute([$hash, $now - 3600]);
        if ((int)$stmt->fetchColumn() >= $limits['hourly']) {
            return false;
        }
    }

    return true;
}

/**
 * 记录一次已认可的点击（写入 / 更新去重戳）
 */
function click_mark(int $linkId): void
{
    if ($linkId <= 0) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO click_throttle (ip_hash, link_id, clicked_at) VALUES (?, ?, ?)
         ON CONFLICT(ip_hash, link_id) DO UPDATE SET clicked_at = excluded.clicked_at'
    );
    $stmt->execute([click_ip_hash(), $linkId, time()]);
}

/* =====================================================================
 * 基础设施函数：安全响应头 + CSP nonce
 * ---------------------------------------------------------------------
 * 全站统一输出。默认只放「不需要改造页面就能生效」的头；
 * CSP 需要内联脚本带 nonce、事件属性改为监听器，见下方 csp_header()。
 * ===================================================================== */

/**
 * 本次请求的 CSP 随机串（每次请求不同，杜绝预判注入）
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(24));
    }
    return $nonce;
}

/**
 * 内联 <script> 标签必须携带的属性
 *
 * 用法：<script<?= nonce_attr() ?>> ... </script>
 */
function nonce_attr(): string
{
    return ' nonce="' . e(csp_nonce()) . '"';
}

/**
 * 组装 CSP 策略串
 */
function csp_policy(): string
{
    $nonce = csp_nonce();
    return implode('; ', [
        "default-src 'self'",
        // 现代浏览器识别 nonce 后会忽略 'unsafe-inline'；保留它只是给不支持 nonce 的老浏览器兜底
        "script-src 'self' 'nonce-" . $nonce . "' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: https:",
        "font-src 'self'",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "object-src 'none'",
    ]);
}

/**
 * 发送 CSP 头（模式由 $config['csp_mode'] 决定）
 */
function csp_header(): void
{
    global $config;
    $mode = strtolower(trim((string)($config['csp_mode'] ?? 'report')));
    if ($mode === 'off') {
        return;
    }
    // 非 Known 值一律按 report 处理，避免拼错开关名就误截断页面
    $enforce = ($mode === 'enforce');
    $name    = $enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    header($name . ': ' . csp_policy());
}

function security_headers(): void
{
    // CLI（如 tests/i18n_smoke.php）没有 HTTP 响应，跳过
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    csp_header();
}

/*
 * 每个入口的第一行都是 require config.php，此处统一调用可保证
 * 连 track_click.php / admin/icon_refresh.php 这类 AJAX 接口也一并覆盖，
 * 避免以后新增入口时漏掉。
 */
security_headers();
