<?php
/**
 * 公司导航页 - 全局配置文件
 *
 * 说明：
 *  - 首次运行 index.php / admin/login.php 时会自动初始化 SQLite 数据库并写入演示数据；
 *  - 管理员账号由 $admin_config 写入 admin_users 表（password_hash 加密存储），之后可在后台
 *    「重置演示」弹窗中自助修改；仅当库内没有任何账号时才回退 $admin_config 校验；
 *  - 修改站点标题等配置后刷新页面即可生效。
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/lib/ui.php';

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
    // 首页每类链接点击多少次后进入“热门”推荐区排序展示
    'hot_threshold'      => 0,
];

// 管理后台登录配置
$admin_config = [
    'username' => 'admin',
    'password' => 'demo12345',   // Coursework demo account; change before public deployment
    'session_name' => 'company_nav_admin_session',
];

// 数据库文件路径（默认位于项目根目录 data/ 下）
$db_config = [
    'file' => __DIR__ . '/data/company_nav.sqlite',
];

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
 * 建表
 */
function init_database(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            icon       TEXT    DEFAULT \'\',
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active  INTEGER NOT NULL DEFAULT 1,
            created_at TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            title       TEXT    NOT NULL,
            url         TEXT    NOT NULL,
            description TEXT    DEFAULT \'\',
            icon        TEXT    DEFAULT \'\',
            sort_order  INTEGER NOT NULL DEFAULT 0,
            is_active   INTEGER NOT NULL DEFAULT 1,
            click_count INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\')),
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        );
    ');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_category ON links(category_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_active   ON links(is_active);');

    // Additive bilingual fields; keep original content and encrypted data untouched.
    foreach (['categories' => ['name_en'], 'links' => ['title_en', 'description_en']] as $table => $columns) {
        $existing = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . " TEXT NOT NULL DEFAULT ''");
            }
        }
    }

    // 链接图标本地缓存字段：幂等 ALTER，兼容已有旧库（老库缺列时自动补齐，已有数据不受影响）
    //  icon_file       本地缓存文件名（assets/icons/ 下，URL 哈希命名，避免撞名）
    //  icon_source     图标来源：site / google / duckduckgo / favicon.im
    //  icon_status     缓存状态：ok=有可用本地图标，fail=抓取失败（前台回退首字母徽章）
    //  icon_note       最近一次抓取的结果说明（便于后台排查）
    //  icon_fetched_at 最近一次抓取时间（判断是否需要重抓）
    $existingCols = [];
    foreach ($pdo->query('PRAGMA table_info(links)') as $col) {
        $existingCols[(string)$col['name']] = true;
    }
    $iconCols = [
        'icon_file'       => "TEXT NOT NULL DEFAULT ''",
        'icon_source'     => "TEXT NOT NULL DEFAULT ''",
        'icon_status'     => "TEXT NOT NULL DEFAULT ''",
        'icon_note'       => "TEXT NOT NULL DEFAULT ''",
        'icon_fetched_at' => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($iconCols as $colName => $colDdl) {
        if (!isset($existingCols[$colName])) {
            $pdo->exec('ALTER TABLE links ADD COLUMN ' . $colName . ' ' . $colDdl . ';');
        }
    }

    // 站点设置表（key/value，供后台「站点设置」读写前台展示文案）
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');

    // 幂等写入默认站点设置（仅当 key 不存在时插入，不覆盖后台已保存的值）
    $seedSettings = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))'
    );
    foreach (site_settings_defaults() as $key => $value) {
        $seedSettings->execute([$key, $value]);
    }

    // 管理员账号表：后台账号入库，密码以 password_hash 存储，支持后台自助改密
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS admin_users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\')),
            updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');

    // 首次初始化（表内无任何账号）时，把 config.php 中的默认账号写入库内，保证开箱即可登录；
    // 之后一律以库内账号为准（可在后台弹窗中自助改密），$admin_config 仅作旧库兼容兜底。
    if ((int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
        global $admin_config;
        $seedAdmin = $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, created_at, updated_at)
             VALUES (?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $seedAdmin->execute([
            $admin_config['username'],
            password_hash((string)$admin_config['password'], PASSWORD_DEFAULT),
        ]);
    }

    // 密码保险箱：条目表（password_enc 存 AES-256-GCM 密文 base64，绝不留明文）+ 元数据表（主密码校验哈希 / 密钥派生盐）
    // 说明：新增表为「只增不改」的幂等迁移，老库升级后 links / categories / settings / admin_users 数据不受影响。
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS vault_items (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            title        TEXT    NOT NULL,
            url          TEXT    NOT NULL DEFAULT \'\',
            username     TEXT    NOT NULL DEFAULT \'\',
            password_enc TEXT    NOT NULL DEFAULT \'\',
            notes        TEXT    NOT NULL DEFAULT \'\',
            category     TEXT    NOT NULL DEFAULT \'\',
            created_at   TEXT    NOT NULL DEFAULT (datetime(\'now\')),
            updated_at   TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vault_items_category ON vault_items(category);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_vault_items_updated  ON vault_items(updated_at);');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS vault_meta (
            key        TEXT PRIMARY KEY,
            value      TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');

    // 后台登录失败限流表（按客户端 IP 哈希记录；登录成功即清零）
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS login_attempts (
            ip_hash      TEXT PRIMARY KEY,
            fail_count   INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0,
            updated_at   TEXT    NOT NULL DEFAULT (datetime(\'now\'))
        );
    ');
}

/**
 * 写入演示数据
 */
function seed_demo_data(PDO $pdo): void
{
    $groups = json_decode(file_get_contents(__DIR__ . '/data/demo.json'), true, 512, JSON_THROW_ON_ERROR);
    $insertCat = $pdo->prepare('INSERT INTO categories (name, name_en, icon, sort_order) VALUES (?, ?, ?, ?)');
    $insertLink = $pdo->prepare(
        'INSERT INTO links (category_id, title, title_en, url, description, description_en, icon, sort_order, is_active, click_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)'
    );
    foreach ($groups as $position => $group) {
        $insertCat->execute([$group['name'], $group['name_en'], $group['icon'], $position + 1]);
        $categoryId = (int)$pdo->lastInsertId();
        foreach ($group['links'] as $rank => $link) {
            $insertLink->execute([$categoryId, $link['title'], $link['title_en'], $link['url'],
                $link['description'], $link['description_en'], $link['icon'], $rank + 1]);
        }
    }
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
 * 读取站点设置（优先 settings 表；空值/缺省回退默认文案）
 */
function raw_setting(string $key, bool $useDefault = true): string
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
function search_engines(): array
{
    static $cache = null;
    if ($cache === null) {
        $parsed = parse_search_engines(setting('search_engines'));
        $cache  = $parsed ?: default_search_engines();
    }
    return $cache;
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

/**
 * 读取配置项（顶部使用 $config / $admin_config / $db_config 全局数组）
 */
function cfg(string $key, $default = '')
{
    global $config;
    return $config[$key] ?? $default;
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
 * 基础设施函数：后台登录失败限流
 * ---------------------------------------------------------------------
 * 以客户端 IP 为维度统计连续失败次数，达到上限后锁定一段时间；
 * 登录成功立即清零。IP 不落明文，仅存 sha1 摘要。
 * ===================================================================== */

// 连续失败达到该次数即触发锁定
define('LOGIN_FAIL_LIMIT', 3);
// 单次锁定时长（秒）
define('LOGIN_LOCK_SECONDS', 30);

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

/**
 * 当前客户端 IP 的存储摘要（避免库内存明文 IP）
 */
function login_ip_hash(): string
{
    return sha1('company-nav|' . login_client_ip());
}

/**
 * 读取当前 IP 的限流记录
 */
function login_attempt_row(): ?array
{
    $stmt = db()->prepare('SELECT fail_count, locked_until FROM login_attempts WHERE ip_hash = ?');
    $stmt->execute([login_ip_hash()]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * 剩余锁定秒数；0 表示当前未被锁定
 */
function login_lock_remaining(): int
{
    $row   = login_attempt_row();
    $until = $row ? (int)$row['locked_until'] : 0;
    $left  = $until - time();
    return $left > 0 ? $left : 0;
}

/**
 * 记录一次登录失败
 *
 * @return int 剩余可尝试次数；返回 0 表示本次已触发锁定
 */
function login_attempt_fail(): int
{
    $pdo  = db();
    $hash = login_ip_hash();

    // 顺带清理一天前的陈旧记录，避免表无限增长
    $pdo->exec("DELETE FROM login_attempts WHERE updated_at < datetime('now', '-1 day')");

    $row   = login_attempt_row();
    $count = $row ? (int)$row['fail_count'] + 1 : 1;
    $until = 0;

    if ($count >= LOGIN_FAIL_LIMIT) {
        $until = time() + LOGIN_LOCK_SECONDS;
        $count = 0;                 // 触发锁定后计数归零，锁定期满重新累计
    }

    if ($row) {
        $stmt = $pdo->prepare(
            'UPDATE login_attempts SET fail_count = ?, locked_until = ?, updated_at = datetime(\'now\')
             WHERE ip_hash = ?'
        );
        $stmt->execute([$count, $until, $hash]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (ip_hash, fail_count, locked_until, updated_at)
             VALUES (?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([$hash, $count, $until]);
    }

    return $until > 0 ? 0 : (LOGIN_FAIL_LIMIT - $count);
}

/**
 * 登录成功：清空当前 IP 的失败记录
 */
function login_attempt_clear(): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ip_hash = ?');
    $stmt->execute([login_ip_hash()]);
}

/* =====================================================================
 * 基础设施函数：后台登录图形验证码
 * ===================================================================== */

// 验证码有效期（秒）
define('CAPTCHA_TTL', 300);
// 验证码字符集（剔除 0/O/1/I/L 等易混字符）
define('CAPTCHA_CHARSET', '23456789ABCDEFGHJKMNPQRSTUVWXYZ');

/**
 * 生成验证码并写入 Session
 *
 * @return string 明文验证码（仅用于绘图，不会回传给前端文本）
 */
function captcha_generate(int $length = 4): string
{
    $chars = CAPTCHA_CHARSET;
    $max   = strlen($chars) - 1;
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    $_SESSION['login_captcha']     = $code;
    $_SESSION['login_captcha_exp'] = time() + CAPTCHA_TTL;
    return $code;
}

/**
 * 校验验证码（一次性：无论成败都会销毁当前答案）
 */
function captcha_verify(?string $input): bool
{
    $answer = (string)($_SESSION['login_captcha'] ?? '');
    $exp    = (int)($_SESSION['login_captcha_exp'] ?? 0);
    unset($_SESSION['login_captcha'], $_SESSION['login_captcha_exp']);

    if ($answer === '' || $exp < time()) {
        return false;
    }
    $input = strtoupper(trim((string)$input));
    return $input !== '' && strlen($input) === strlen($answer) && hash_equals($answer, $input);
}

/**
 * 查找可用字体；找不到返回 null（回退 GD 内置字体）
 */
function captcha_font_path(): ?string
{
    static $done = false;
    static $path = null;
    if ($done) {
        return $path;
    }
    $done = true;

    $candidates = [
        __DIR__ . '/assets/fonts/captcha.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            $path = $file;
            return $path;
        }
    }
    return null;
}

/**
 * 直接输出验证码 PNG（生成与绘制一体，调用方无需再处理）
 */
function captcha_png(): void
{
    // 丢弃此前任何杂散输出（warning / notice），确保 PNG 数据流纯净
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('display_errors', '0');

    if (!headers_sent()) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    if (!function_exists('imagecreatetruecolor')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo ('GD ' . t('扩展未启用，无法生成验证码。'));
        return;
    }

    $code = captcha_generate(4);
    $w    = 132;
    $h    = 44;

    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 245, 247, 250));

    // 干扰点
    for ($i = 0; $i < 90; $i++) {
        $c = imagecolorallocate($im, random_int(180, 226), random_int(180, 226), random_int(180, 226));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }
    // 干扰线
    for ($i = 0; $i < 4; $i++) {
        $c = imagecolorallocate($im, random_int(150, 210), random_int(150, 210), random_int(150, 210));
        imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
    }

    $font  = captcha_font_path();
    $len   = strlen($code);
    $step  = (int)(($w - 24) / $len);
    for ($i = 0; $i < $len; $i++) {
        $ch    = $code[$i];
        $x     = 14 + $i * $step + random_int(-2, 2);
        $y     = random_int(26, 34);
        $color = imagecolorallocate($im, random_int(30, 90), random_int(60, 120), random_int(120, 190));

        if ($font !== null) {
            imagettftext($im, 20, random_int(-14, 14), $x, $y, $color, $font, $ch);
        } else {
            imagestring($im, 5, $x, (int)(($h - 15) / 2), $ch, $color);
        }
    }

    imagerectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 224, 228, 235));
    imagepng($im);
    imagedestroy($im);
}

/**
 * 校验管理员账号密码
 *
 * 优先校验 admin_users 表（password_verify）；仅当库内没有任何账号记录时，
 * 回退 config.php 的 $admin_config 明文配置（兼容首次安装的 admin / admin123）。
 */
function admin_authenticate(string $username, string $password): bool
{
    global $admin_config;

    if ($username === '' || $password === '') {
        return false;
    }

    if (admin_user_count() > 0) {
        $user = admin_find_user($username);
        return $user !== null && password_verify($password, (string)$user['password_hash']);
    }

    // 库内无账号记录：回退 config.php
    return hash_equals((string)$admin_config['username'], $username)
        && hash_equals((string)$admin_config['password'], $password);
}

/**
 * 写入 / 更新管理员账号（入库，password_hash 存储）
 *
 * @param string $username 用户名（不可为空）
 * @param string $password 新密码；留空表示保持原密码不变（库内无记录时用 config.php 密码兜底）
 */
function admin_save_account(string $username, string $password): void
{
    global $admin_config;

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
        $hash = $existing
            ? (string)$existing['password_hash']
            : password_hash((string)$admin_config['password'], PASSWORD_DEFAULT);
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE admin_users SET username = ?, password_hash = ?, updated_at = datetime(\'now\') WHERE id = ?'
        );
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
 *
 * @throws RuntimeException 用户名为空 / 用户名已存在 / 数据库约束失败
 */
function admin_create_user(string $username, string $password): void
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException(t('用户名不能为空。'));
    }
    if (admin_find_user($username) !== null) {
        throw new RuntimeException(t('该用户名已被注册，请换一个试试。'));
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO admin_users (username, password_hash, created_at, updated_at)
             VALUES (?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
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
 * 精致 Logo 徽章（青蓝渐变圆角方块 + 白色罗盘指针 SVG）
 */
function logo_badge_html(): string
{
    return '<span class="brand-logo" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><rect width="32" height="32" rx="8" fill="#2563eb"/><rect x="7" y="7" width="7" height="7" rx="1.5" fill="white"/><rect x="18" y="7" width="7" height="7" rx="1.5" fill="white"/><rect x="7" y="18" width="7" height="7" rx="1.5" fill="white"/><path d="M18 21.5h7m-3.5-3.5v7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg></span>';
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
    echo '<script src="' . $base . 'assets/js/i18n.js"></script><script src="' . $base . 'assets/js/theme.js"></script><script src="' . $base . 'assets/js/workspace.js"></script>
</body>
</html>';
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
