<?php
/**
 * 公司导航页 - 数据库迁移与演示数据播种
 *
 * 由 config.php 加载。这里只放「建表 / 加列 / 播种」这类一次性结构逻辑，
 * 业务查询函数仍在 config.php 与各功能页内。
 *
 * 迁移口径：全部幂等。每次连接都会执行一遍，靠
 *   - CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS
 *   - PRAGMA table_info 探测后再 ALTER TABLE ADD COLUMN
 * 保证老库能平滑升级出新表新列，且已有数据不受影响。
 */

declare(strict_types=1);

// 允许本文件被单独引入（例如测试脚本）；已由 config.php 加载时不会重复执行。
require_once dirname(__DIR__) . '/config.php';

/**
 * 建表（幂等）
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

    // 管理员角色分级字段。必须早于下面的账号播种执行 ——
    // 否则全新库在播种 INSERT 时还没有 role 列，会直接抛错（此坑已实际发生过一次）。
    $adminCols = [];
    foreach ($pdo->query('PRAGMA table_info(admin_users)') as $col) {
        $adminCols[(string)$col['name']] = true;
    }
    if (!isset($adminCols['role'])) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN role TEXT NOT NULL DEFAULT '" . ROLE_OWNER . "'");
    }

    // 「需要改密」标记。注意这里不再播种任何账号 —— 全新库的 admin_users 是空表，
    // 首位管理员由 admin/install.php 引导创建（自己设密码），从而根除「默认密码人尽皆知」。
    //
    // 走到这个分支说明是老库升级：存量账号一律标记 must_change_password = 1。
    // 原因是我们无法从 password_hash 反推它是否仍是当年 config.php 里那个明文默认密码，
    // 保守置 1 —— 宁可多提醒一次，也不放过一个仍在用初始密码的站点。登录改一次即消除。
    if (!isset($adminCols['must_change_password'])) {
        $pdo->exec('ALTER TABLE admin_users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE admin_users SET must_change_password = 1');
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

    // 后台登录失败限流表：双维度「限定键」设计
    //   scope       维度：ip（客户端 IP）/ user（登录名）/ captcha（验证码错误，独立计数）
    //   subject_hash 该维度主体值的 sha256 摘要，不落明文（IP、用户名都不存原文）
    //   strikes     历史触发锁定的次数，用于指数退避（30s → 60s → 120s → …）
    // 取代此前的单点 login_attempts（仅按 IP），使「换 IP 撞同一个账号」也能被锁住。
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS login_throttle (
            scope        TEXT    NOT NULL,
            subject_hash TEXT    NOT NULL,
            fail_count   INTEGER NOT NULL DEFAULT 0,
            strikes      INTEGER NOT NULL DEFAULT 0,
            locked_until INTEGER NOT NULL DEFAULT 0,
            updated_at   TEXT    NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (scope, subject_hash)
        );
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_throttle_updated ON login_throttle(updated_at);');

    // 点击统计去重表（按「客户端 + 链接」记录最近一次计数的 Unix 时间戳）
    //   ip_hash   REMOTE_ADDR 的 sha256 摘要，不落明文 IP
    //   clicked_at 时间戳（秒）；超过 click_dedup_window 即视为可重新计数
    // 主键为 (ip_hash, link_id)，配合 INSERT ... ON CONFLICT 实现 upsert；
    // 「单 IP 每小时不同链接数」由 COUNT(*) 统计本表行数得出。
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS click_throttle (
            ip_hash    TEXT    NOT NULL,
            link_id    INTEGER NOT NULL,
            clicked_at INTEGER NOT NULL,
            PRIMARY KEY (ip_hash, link_id)
        );
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_click_throttle_time ON click_throttle(clicked_at);');
}

/**
 * 写入演示数据（仅全新数据库首次运行时调用一次）
 */
function seed_demo_data(PDO $pdo): void
{
    // 注意：本文件位于 lib/ 下，data/ 在项目根，必须走 NAV_ROOT 而不是 __DIR__。
    $groups = json_decode(file_get_contents(NAV_ROOT . '/data/demo.json'), true, 512, JSON_THROW_ON_ERROR);
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
 * 安装密钥文件路径
 *
 * 落在 data/ 下，随该目录一起受三层封堵保护（见 README「运行时数据保护」一节）。
 */
function install_key_path(): string
{
    return NAV_ROOT . '/data/.install_key';
}

/**
 * 读取安装密钥；文件不存在或格式不对时随机生成一份再落盘（600 权限）。
 *
 * 用途：admin/install.php 创建首位管理员时，若请求不是来自本机就要求输入它。
 * 没有这层校验的话，公网部署场景下任何人抢先打开安装页就能把自己设成站长。
 * 来自 127.0.0.1 / ::1 的请求免验，本地开发与课堂演示不受影响。
 *
 * @return string 40 位十六进制串
 */
function install_key(): string
{
    $path = install_key_path();
    $key  = is_readable($path) ? trim((string)file_get_contents($path)) : '';

    if (preg_match('/^[0-9a-f]{40}$/', $key) !== 1) {
        $key = bin2hex(random_bytes(20));
        // 写入失败不致命：密钥会退化成「本次请求内的随机值」，下次请求再换一个，
        // 远程安装将无法完成，但站点其它功能不受影响。
        @file_put_contents($path, $key . "\n");
        @chmod($path, 0600);
    }

    return $key;
}
