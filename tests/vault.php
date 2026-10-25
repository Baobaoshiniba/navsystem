<?php
/**
 * Run: php tests/vault.php
 *
 * 覆盖密码保险箱：主密码设置与校验、PBKDF2 密钥派生、AES-256-GCM 加解密、
 * 每条记录独立 IV、密文篡改检测、会话解锁与空闲锁定、条目 CRUD、危险 URL 过滤。
 *
 * 这一块此前只有人工审查、没有自动化覆盖。加解密一旦出错是会丢数据的，
 * 而且「忘记主密码无法恢复」的设计让 bug 的代价格外高。
 */
declare(strict_types=1);
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/_vault.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$file = tempnam(sys_get_temp_dir(), 'nav-vault-');
try {
    $db_config['file'] = $file;
    $pdo = db();
    $_SESSION = [];

    /* ---------- 初始状态 ---------- */
    check(vault_master_is_set() === false, 'A fresh vault must not have a master password');
    check(vault_is_unlocked() === false, 'A fresh vault must be locked');
    check(vault_session_key() === '', 'A locked vault must not hand out a key');

    /* ---------- 设置主密码 ---------- */
    vault_setup_master('MasterPass2026');
    check(vault_master_is_set() === true, 'Setup must record the master password');
    check(vault_is_unlocked() === true, 'Setup must unlock the current session');
    check(vault_verify_master('MasterPass2026') === true, 'The correct master password must verify');
    check(vault_verify_master('WrongPass2026') === false, 'A wrong master password must be rejected');
    // 主密码只能以校验哈希落盘，绝不能出现明文
    check(strpos(vault_meta_get('master_hash'), 'MasterPass2026') === false,
        'The stored master hash must not contain the plaintext password');
    check(strlen(vault_meta_get('kdf_salt')) === VAULT_KDF_SALT_BYTES * 2,
        'Setup must write a hex KDF salt of the expected length');

    /* ---------- 密钥派生 ---------- */
    $key = vault_derive_key('MasterPass2026');
    check(strlen($key) === VAULT_KDF_KEY_BYTES, 'The derived key must be 32 bytes for AES-256');
    check(vault_derive_key('MasterPass2026') === $key,
        'Deriving from the same master password must be deterministic');
    check(vault_derive_key('AnotherPass2026') !== $key,
        'A different master password must derive a different key');
    // 密钥只应存在于会话里
    check($key === vault_session_key(), 'The session key must match the derived key');

    /* ---------- 加解密往返 ---------- */
    $samples = [
        'Hello-2026',
        '中文密码测试',
        '',
        str_repeat('x', 500),
        "特殊\t字符\n&<>\"'",
        '🔐 emoji 与多字节',
    ];
    foreach ($samples as $plain) {
        $enc = vault_encrypt($plain, $key);
        check(vault_decrypt($enc, $key) === $plain, 'Round-trip failed for plaintext: ' . var_export($plain, true));
    }

    // 相同明文两次加密必须不同 —— 每条记录独立 IV。
    // 若退化成 ECB，两条相同密码会产生相同密文，等于泄露「这两个账号密码一样」。
    $a = vault_encrypt('same-password', $key);
    $b = vault_encrypt('same-password', $key);
    check($a !== $b, 'Encrypting the same plaintext twice must yield different ciphertext (unique IV)');
    check(strpos(base64_decode($a, true), 'same-password') === false,
        'The ciphertext must not contain the plaintext');

    // 错误密钥必须解不开
    $wrongKey = str_repeat("\x00", VAULT_KDF_KEY_BYTES);
    check($wrongKey !== $key, 'The fake key must differ from the real one');
    check(vault_decrypt($a, $wrongKey) === null, 'Decrypting with the wrong key must fail, not return garbage');
    check(vault_decrypt($a, 'too-short') === null, 'A short key must be rejected');

    // 篡改密文：GCM 的认证标签必须让解密失败（静默改成另一条密码是最坏的情况）
    $raw    = base64_decode($a, true);
    $raw[20] = chr(ord($raw[20]) ^ 0x01);
    check(vault_decrypt(base64_encode($raw), $key) === null,
        'A tampered ciphertext must fail authentication (GCM tag)');
    // 截断同样要失败
    check(vault_decrypt(base64_encode(substr(base64_decode($a, true), 0, 10)), $key) === null,
        'A truncated ciphertext must be rejected');
    // 非 base64 输入不能抛异常
    check(vault_decrypt('not-valid-base64-!!!', $key) === null, 'Invalid base64 must return null, not throw');

    /* ---------- 会话锁定与空闲超时 ---------- */
    vault_lock();
    check(vault_is_unlocked() === false, 'vault_lock() must lock the vault');
    check(vault_session_key() === '', 'A locked vault must not hand out a key');

    check(vault_unlock('MasterPass2026') === true, 'Unlocking with the correct master must succeed');
    check(vault_is_unlocked() === true, 'The vault must be unlocked after a successful unlock');
    // 空闲超时：把「最后活动时间」推到阈值之前
    $_SESSION['vault_last_active'] = time() - VAULT_IDLE_TIMEOUT - 1;
    check(vault_is_unlocked() === false, 'An idle session past the timeout must auto-lock');
    check(vault_session_key() === '', 'Auto-lock must clear the key from the session');
    check(vault_unlock('NopeWrong2026') === false, 'Unlocking with a wrong master must fail');

    vault_unlock('MasterPass2026');
    check(vault_remaining_seconds() > 0 && vault_remaining_seconds() <= VAULT_IDLE_TIMEOUT,
        'Remaining time must be within the idle window');

    /* ---------- 条目 CRUD（真实加解密路径） ---------- */
    vault_unlock('MasterPass2026');
    $k = vault_session_key();
    check($k !== '', 'Need a session key to exercise CRUD');

    $id = vault_create([
        'title'    => 'GitHub',
        'url'      => 'https://github.com/',
        'username' => 'baobao',
        'password' => 'Secret#2026',
        'notes'    => '主账号',
        'category' => '开发',
    ], $k);
    check($id > 0, 'vault_create must return a new id');

    $row = vault_get($id);
    check($row !== null, 'The created item must be readable');
    check($row['title'] === 'GitHub', 'Title round-trip failed');
    check($row['password_enc'] !== 'Secret#2026', 'The stored password must be encrypted, never plaintext');
    check(strpos((string)$row['password_enc'], 'Secret') === false, 'The ciphertext must not leak the plaintext');
    check(vault_decrypt((string)$row['password_enc'], $k) === 'Secret#2026', 'Stored password must decrypt back');

    // 更新
    check(vault_update($id, [
        'title'    => 'GitHub',
        'url'      => 'https://github.com/',
        'username' => 'baobao2',
        'password' => 'Rotated#2026',
        'notes'    => '',
        'category' => '开发',
    ], $k) === true, 'vault_update must report success');
    $row = vault_get($id);
    check(vault_decrypt((string)$row['password_enc'], $k) === 'Rotated#2026', 'Updated password must decrypt back');

    // 用错误密钥更新后应无法用正确密钥解出有意义的原文
    check(vault_delete($id) === true, 'vault_delete must report success');
    check(vault_get($id) === null, 'A deleted item must be gone');

    /* ---------- 检索与统计 ---------- */
    vault_create(['title' => 'Alpha', 'url' => '', 'username' => 'u1', 'password' => 'p1', 'notes' => '', 'category' => 'A'], $k);
    vault_create(['title' => 'Beta',  'url' => '', 'username' => 'u2', 'password' => 'p2', 'notes' => '', 'category' => 'B'], $k);
    // vault_items 返回的是 [total, page, pages, rows]，不是裸行数组
    $all = vault_items('', '', 1, 10);
    check((int)$all['total'] === 2, 'vault_items must list both records');
    check(count($all['rows']) === 2, 'vault_items must return two rows on page 1');
    check((int)vault_items('Alpha', '', 1, 10)['total'] === 1, 'Keyword search must filter');
    // LIKE 通配符必须被转义：'%' 不能变成「匹配所有」
    check((int)vault_items('%', '', 1, 10)['total'] === 0, 'A bare % must not match everything (LIKE escaping)');
    check((int)vault_items('_', '', 1, 10)['total'] === 0, 'A bare _ must not match everything (LIKE escaping)');
    check(vault_like_escape('50%_off') === '50\\%\\_off', 'LIKE wildcards must be escaped');
    $stats = vault_stats();
    check((int)$stats['total'] === 2, 'vault_stats must count the records');

    /* ---------- 危险 URL 过滤 ---------- */
    check(vault_safe_url('javascript:alert(1)') === '', 'javascript: URLs must be stripped');
    check(vault_safe_url('JaVaScRiPt:alert(1)') === '', 'javascript: filtering must be case-insensitive');
    check(vault_safe_url('data:text/html,<script>') === '', 'data: URLs must be stripped');
    check(vault_safe_url('https://example.com/') === 'https://example.com/', 'Normal https URLs must be preserved');

    /* ---------- 重置 ---------- */
    vault_reset_all();
    check(vault_master_is_set() === false, 'vault_reset_all must clear the master password');
    check((int)vault_items('', '', 1, 10)['total'] === 0, 'vault_reset_all must clear every item');
    check(vault_is_unlocked() === false, 'Reset must leave the vault locked');

    echo "PASS: vault master, KDF, AES-GCM round-trip, tamper detection, idle lock, CRUD and URL safety\n";
} finally {
    $pdo = null;
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($file . $suffix)) {
            unlink($file . $suffix);
        }
    }
}
