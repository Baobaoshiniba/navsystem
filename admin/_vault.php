<?php
/**
 * 公司导航页 - 后台「密码保险箱」公共库
 *
 * 设计口径（方案 1：主密码解锁）：
 *  - 主密码本身只以 Argon2id 校验哈希（password_hash / password_verify）存入 vault_meta，不存明文；
 *  - 加密密钥由主密码经「高迭代 PBKDF2-HMAC-SHA256 + 独立随机盐」派生 32 字节（表中绝不留痕）；
 *  - 密钥只存在于 PHP 会话（$_SESSION['vault_key']，base64），不写入数据库 / 配置文件 / 日志 / 任何应用文件；
 *  - 15 分钟无操作自动锁定（服务端以「最后一次成功请求时间」判定，前端另有空闲倒计时兜底）；
 *  - 明文密码以 AES-256-GCM 加密，每条记录独立随机 12 字节 IV，密文按 base64(IV | TAG | CIPHERTEXT) 存储；
 *  - 忘记主密码无法解密（哈希不可逆、密钥不可复原），只能「重置保险箱」清空全部条目。
 *
 * 数据表：
 *  - vault_items：title / url / username / password_enc / notes / category / created_at / updated_at
 *  - vault_meta ：主密码校验哈希 master_hash、派生盐 kdf_salt、迭代次数 kdf_iterations、创建时间 created_at
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/** 无操作自动锁定阈值（秒）：15 分钟 */
const VAULT_IDLE_TIMEOUT = 900;

/** 主密码最短长度 */
const VAULT_MIN_MASTER_LEN = 8;

/** PBKDF2-HMAC-SHA256 默认迭代次数（可被 vault_meta.kdf_iterations 覆盖） */
const VAULT_KDF_ITERATIONS = 210000;

/** 派生密钥长度（AES-256 需 32 字节） */
const VAULT_KDF_KEY_BYTES = 32;

/** 派生盐长度（字节） */
const VAULT_KDF_SALT_BYTES = 16;

/** AES-256-GCM 参数：12 字节 IV + 16 字节认证标签 */
const VAULT_GCM_CIPHER = 'aes-256-gcm';
const VAULT_GCM_IV_BYTES = 12;
const VAULT_GCM_TAG_BYTES = 16;

/** 重置保险箱需输入的确认字样 */
const VAULT_RESET_PHRASE = '清空保险箱';

/* =====================================================================
 * 一、vault_meta 读写（主密码与派生参数）
 * ===================================================================== */

/**
 * 读取保险箱元数据（不存在返回默认值）
 */
function vault_meta_get(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT value FROM vault_meta WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return is_string($value) ? $value : $default;
}

/**
 * 写入保险箱元数据（key 不存在则插入，存在则更新）
 */
function vault_meta_set(string $key, string $value): void
{
    $stmt = db()->prepare(
        "INSERT INTO vault_meta (key, value, updated_at) VALUES (?, ?, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
    );
    $stmt->execute([$key, $value]);
}

/**
 * 是否已设置主密码（保险箱是否已初始化）
 */
function vault_master_is_set(): bool
{
    return vault_meta_get('master_hash') !== '';
}

/**
 * 当前生效的 PBKDF2 迭代次数
 */
function vault_kdf_iterations(): int
{
    $n = (int)vault_meta_get('kdf_iterations', (string)VAULT_KDF_ITERATIONS);
    return $n >= 10000 ? $n : VAULT_KDF_ITERATIONS;
}

/* =====================================================================
 * 二、密钥派生与加解密
 * ===================================================================== */

/**
 * 由主密码派生 32 字节加密密钥（PBKDF2-HMAC-SHA256 + 独立盐）
 *
 * @return string 原始二进制密钥（32 字节）
 */
function vault_derive_key(string $master): string
{
    $saltHex = vault_meta_get('kdf_salt');
    $saltRaw = $saltHex !== '' ? (hex2bin($saltHex) ?: '') : '';
    if ($saltRaw === '' || strlen($saltRaw) !== VAULT_KDF_SALT_BYTES) {
        throw new RuntimeException('保险箱密钥派生参数缺失，请重置保险箱后重新设置主密码。');
    }

    return hash_pbkdf2('sha256', $master, $saltRaw, vault_kdf_iterations(), VAULT_KDF_KEY_BYTES, true);
}

/**
 * AES-256-GCM 加密：每条记录独立随机 IV，输出 base64(IV | TAG | CIPHERTEXT)
 */
function vault_encrypt(string $plain, string $key): string
{
    if (strlen($key) !== VAULT_KDF_KEY_BYTES) {
        throw new RuntimeException('保险箱密钥无效，请重新解锁。');
    }

    $iv  = random_bytes(VAULT_GCM_IV_BYTES);
    $tag = '';
    $cipher = openssl_encrypt($plain, VAULT_GCM_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', VAULT_GCM_TAG_BYTES);
    if ($cipher === false || strlen($tag) !== VAULT_GCM_TAG_BYTES) {
        throw new RuntimeException('密码加密失败，请稍后重试。');
    }

    return base64_encode($iv . $tag . $cipher);
}

/**
 * AES-256-GCM 解密；密钥错误 / 密文被篡改时返回 null（认证失败）
 */
function vault_decrypt(string $enc, string $key): ?string
{
    if ($enc === '') {
        return '';
    }
    if (strlen($key) !== VAULT_KDF_KEY_BYTES) {
        return null;
    }

    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= VAULT_GCM_IV_BYTES + VAULT_GCM_TAG_BYTES) {
        return null;
    }

    $iv         = substr($raw, 0, VAULT_GCM_IV_BYTES);
    $tag        = substr($raw, VAULT_GCM_IV_BYTES, VAULT_GCM_TAG_BYTES);
    $ciphertext = substr($raw, VAULT_GCM_IV_BYTES + VAULT_GCM_TAG_BYTES);

    $plain = openssl_decrypt($ciphertext, VAULT_GCM_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/* =====================================================================
 * 三、会话解锁态（密钥仅存会话 + 15 分钟无操作自动锁定）
 * ===================================================================== */

/**
 * 首次设置主密码：写 Argon2id 校验哈希 + 独立派生盐，并立即解锁当前会话
 */
function vault_setup_master(string $master): void
{
    vault_meta_set('master_hash', password_hash($master, PASSWORD_ARGON2ID));
    vault_meta_set('kdf_salt', bin2hex(random_bytes(VAULT_KDF_SALT_BYTES)));
    vault_meta_set('kdf_iterations', (string)VAULT_KDF_ITERATIONS);
    vault_meta_set('created_at', date('Y-m-d H:i:s'));

    vault_unlock_with_key(vault_derive_key($master));
}

/**
 * 校验主密码（Argon2id 哈希比对）
 */
function vault_verify_master(string $master): bool
{
    $hash = vault_meta_get('master_hash');
    if ($hash === '' || $master === '') {
        return false;
    }
    return password_verify($master, $hash);
}

/**
 * 用派生密钥解锁当前会话（密钥只进 $_SESSION，不落盘）
 */
function vault_unlock_with_key(string $key): void
{
    secure_session_start();
    $_SESSION['vault_key']         = base64_encode($key);
    $_SESSION['vault_unlocked_at'] = time();
    $_SESSION['vault_last_active'] = time();
    vault_reset_failures();
}

/**
 * 校验主密码并解锁；失败时累计失败次数（触发轻微延迟，抬高暴力破解成本）
 */
function vault_unlock(string $master): bool
{
    if (!vault_verify_master($master)) {
        vault_note_failure();
        return false;
    }
    vault_unlock_with_key(vault_derive_key($master));
    return true;
}

/**
 * 锁定：从会话中清除密钥与时间戳
 */
function vault_lock(): void
{
    unset($_SESSION['vault_key'], $_SESSION['vault_unlocked_at'], $_SESSION['vault_last_active']);
}

/**
 * 距离自动锁定还剩多少秒（未解锁返回 0）
 */
function vault_remaining_seconds(): int
{
    $last = (int)($_SESSION['vault_last_active'] ?? 0);
    if ($last <= 0) {
        return 0;
    }
    return max(0, VAULT_IDLE_TIMEOUT - (time() - $last));
}

/**
 * 当前会话是否已解锁（超时则自动锁定并返回 false）
 */
function vault_is_unlocked(): bool
{
    if ((string)($_SESSION['vault_key'] ?? '') === '') {
        return false;
    }
    if (vault_remaining_seconds() <= 0) {
        vault_lock();
        return false;
    }
    return true;
}

/**
 * 任意一次成功请求都会刷新「最后活动时间」（仅在已解锁时有意义）
 */
function vault_touch(): void
{
    if (vault_is_unlocked()) {
        $_SESSION['vault_last_active'] = time();
    }
}

/**
 * 取当前会话的原始密钥（未解锁返回空串）
 */
function vault_session_key(): string
{
    if (!vault_is_unlocked()) {
        return '';
    }
    $raw = base64_decode((string)$_SESSION['vault_key'], true);
    return (is_string($raw) && strlen($raw) === VAULT_KDF_KEY_BYTES) ? $raw : '';
}

/**
 * 记录一次解锁失败，并按失败次数做轻微延迟（0.25s / 次，上限 1s）
 */
function vault_note_failure(): void
{
    secure_session_start();
    $fails = (int)($_SESSION['vault_fail_count'] ?? 0) + 1;
    $_SESSION['vault_fail_count'] = $fails;
    $delay = min(1000000, 250000 * $fails);
    usleep($delay);
}

/**
 * 清空失败计数（解锁成功后调用）
 */
function vault_reset_failures(): void
{
    unset($_SESSION['vault_fail_count']);
}

/* =====================================================================
 * 四、条目数据访问（title / url / username / password_enc / notes / category）
 * ===================================================================== */

/**
 * LIKE 通配符转义，避免用户输入的 % _ 被当作通配
 */
function vault_like_escape(string $keyword): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
}

/**
 * 组装列表检索条件
 *
 * @return array{0:string,1:array} [WHERE 子句, 绑定参数]
 */
function vault_where(string $keyword, string $category): array
{
    $sql    = ' WHERE 1=1';
    $params = [];
    if ($keyword !== '') {
        $like = '%' . vault_like_escape($keyword) . '%';
        $sql .= " AND (title LIKE ? ESCAPE '\\' OR url LIKE ? ESCAPE '\\' OR username LIKE ? ESCAPE '\\'"
              . " OR notes LIKE ? ESCAPE '\\' OR category LIKE ? ESCAPE '\\')";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }
    if ($category !== '') {
        $sql .= ' AND category = ?';
        $params[] = $category;
    }
    return [$sql, $params];
}

/**
 * 检索条目（服务端分页；LIMIT/OFFSET 由整数运算得出，直接内联规避 PDO 绑定类型问题）
 *
 * @return array{total:int, page:int, pages:int, rows:array}
 */
function vault_items(string $keyword = '', string $category = '', int $page = 1, int $pageSize = 10): array
{
    $db = db();
    [$where, $params] = vault_where($keyword, $category);

    $stmt = $db->prepare('SELECT COUNT(*) FROM vault_items' . $where);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $pages = max(1, (int)ceil($total / $pageSize));
    $page  = min(max(1, $page), $pages);
    $offset = ($page - 1) * $pageSize;

    $stmt = $db->prepare(
        'SELECT * FROM vault_items' . $where . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset
    );
    $stmt->execute($params);

    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'rows' => $stmt->fetchAll()];
}

/**
 * 读取单条条目（不存在返回 null）
 */
function vault_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM vault_items WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 新增条目（密码为空则存空串，不加密空值）
 *
 * @param array{title:string,url:string,username:string,password:string,notes:string,category:string} $data
 */
function vault_create(array $data, string $key): int
{
    $enc = $data['password'] === '' ? '' : vault_encrypt($data['password'], $key);
    $stmt = db()->prepare(
        "INSERT INTO vault_items (title, url, username, password_enc, notes, category, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))"
    );
    $stmt->execute([$data['title'], $data['url'], $data['username'], $enc, $data['notes'], $data['category']]);
    return (int)db()->lastInsertId();
}

/**
 * 更新条目（密码留空表示保持原密文不变，避免误清空）
 *
 * @param array{title:string,url:string,username:string,password:string,notes:string,category:string} $data
 */
function vault_update(int $id, array $data, string $key): bool
{
    $row = vault_get($id);
    if ($row === null) {
        return false;
    }
    $enc = $data['password'] === '' ? (string)$row['password_enc'] : vault_encrypt($data['password'], $key);

    $stmt = db()->prepare(
        "UPDATE vault_items
            SET title = ?, url = ?, username = ?, password_enc = ?, notes = ?, category = ?, updated_at = datetime('now')
          WHERE id = ?"
    );
    $stmt->execute([$data['title'], $data['url'], $data['username'], $enc, $data['notes'], $data['category'], $id]);
    return true;
}

/**
 * 删除条目
 */
function vault_delete(int $id): bool
{
    $stmt = db()->prepare('DELETE FROM vault_items WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * 统计：条目总数 / 有账号的条目 / 分类数
 *
 * @return array{total:int, with_login:int, categories:int}
 */
function vault_stats(): array
{
    $db = db();
    $total = (int)$db->query('SELECT COUNT(*) FROM vault_items')->fetchColumn();
    $withLogin = (int)$db->query("SELECT COUNT(*) FROM vault_items WHERE username <> ''")->fetchColumn();
    $cats = (int)$db->query("SELECT COUNT(DISTINCT category) FROM vault_items WHERE category <> ''")->fetchColumn();
    return ['total' => $total, 'with_login' => $withLogin, 'categories' => $cats];
}

/**
 * 已使用过的分类（去重，供表单 datalist 与筛选下拉）
 */
function vault_category_list(): array
{
    try {
        $rows = db()->query("SELECT DISTINCT category FROM vault_items WHERE category <> '' ORDER BY category ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return array_map(static fn(array $r): string => (string)$r['category'], $rows);
}

/**
 * 重置保险箱：清空全部条目 + 抹掉主密码与派生参数，并锁定当前会话
 *
 * 不可恢复：条目密文与派生盐一并删除，旧密钥再也无法解密任何历史数据。
 */
function vault_reset_all(): void
{
    $db = db();
    $db->exec('DELETE FROM vault_items;');
    $db->exec('DELETE FROM sqlite_sequence WHERE name = \'vault_items\';');
    $db->exec('DELETE FROM vault_meta;');
    vault_lock();
}

/**
 * 网址安全化：仅放行 http / https，缺协议自动补 https://，非法一律返回空串
 */
function vault_safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url)) {
        $url = 'https://' . $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}
