<?php
/**
 * Run: php tests/admin_logic.php
 *
 * 覆盖后台数据层与鉴权：
 *   - 分类 / 链接的增删改，删除分类级联清理其下链接
 *   - 账号密码校验、后台改密
 *   - 角色分级：注册产出 editor、播种与存量账号为 owner
 *   - 登录限流的双维度计数与指数退避
 * 这些都是以前只能手点的部分，这里用一次性临时数据库自动化。
 */
declare(strict_types=1);
require __DIR__ . '/../config.php';
// admin_session_user_exists() 定义在后台公共库里
require __DIR__ . '/../admin/_init.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$file = tempnam(sys_get_temp_dir(), 'nav-admin-');
try {
    $db_config['file'] = $file;
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = ON');

    /* ---------- 分类与链接 ---------- */
    $pdo->prepare('INSERT INTO categories (name, name_en, icon, sort_order) VALUES (?,?,?,?)')
        ->execute(['协作', 'Collab', 'users', 1]);
    $catId = (int)$pdo->lastInsertId();
    check($catId > 0, 'Category insert failed');

    foreach (['文档' => 'https://a.example', '云盘' => 'https://b.example'] as $title => $url) {
        $pdo->prepare('INSERT INTO links (category_id, title, url, is_active, click_count) VALUES (?,?,?,1,0)')
            ->execute([$catId, $title, $url]);
    }
    check((int)$pdo->query('SELECT COUNT(*) FROM links WHERE category_id=' . $catId)->fetchColumn() === 2,
        'Expected two links in the new category');

    // 编辑：标题更新不应影响点击数
    $linkId = (int)$pdo->query('SELECT id FROM links ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE links SET click_count = 9 WHERE id = ?')->execute([$linkId]);
    $pdo->prepare('UPDATE links SET title = ?, url = ? WHERE id = ?')->execute(['新文档', 'https://a2.example', $linkId]);
    $row = $pdo->query('SELECT title, click_count FROM links WHERE id=' . $linkId)->fetch();
    check($row['title'] === '新文档', 'Link title update failed');
    check((int)$row['click_count'] === 9, 'Editing must not reset click counts');

    // 删除分类应级联清掉其下链接（外键 ON DELETE CASCADE）
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
    check((int)$pdo->query('SELECT COUNT(*) FROM links WHERE category_id=' . $catId)->fetchColumn() === 0,
        'Deleting a category must cascade to its links');

    /* ---------- 安装向导：全新库不得自带任何默认账号 ---------- */
    check(admin_user_count() === 0, 'A fresh database must not seed any admin account');
    check(admin_needs_install() === true, 'A fresh database must report that setup is pending');
    // 这是本次整改的核心断言：config.php 里那个明文默认密码必须彻底失效
    check(admin_authenticate('admin', 'demo12345') === false,
        'There must be no working default password on a fresh install');
    check(admin_authenticate('admin', '') === false, 'Empty password must be rejected');

    // 弱密码在建号之前就被挡下，且失败的尝试不该留下账号
    try {
        admin_install_owner('admin', '1234');
        throw new RuntimeException('A short password must be rejected by the setup wizard');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('新密码长度至少 8 位。'), 'Unexpected weak-password error');
    }
    check(admin_user_count() === 0, 'A rejected setup attempt must not create any account');

    // 空密码建号同样不允许（此前会拿 config.php 的明文兜底）
    try {
        admin_save_account('admin', '');
        throw new RuntimeException('Creating an account with a blank password must be rejected');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('尚未创建管理员账号，请先完成安装向导。'), 'Unexpected blank-password error');
    }

    // 安装密钥：40 位十六进制，且多次调用必须稳定（否则远程安装永远验不过）
    $key = install_key();
    check(preg_match('/^[0-9a-f]{40}$/', $key) === 1, 'The setup key must be 40 hex characters');
    check(install_key() === $key, 'The setup key must stay stable across calls');

    // 正常建号：首个账号为 owner，且不带「需要改密」标记
    admin_install_owner('admin', 'OwnerPass2026');
    check(admin_user_count() === 1, 'The setup wizard must create exactly one admin');
    check(admin_needs_install() === false, 'Setup must be complete once the wizard finishes');
    check(admin_authenticate('admin', 'OwnerPass2026') === true, 'The wizard-created account must be able to sign in');
    check(admin_authenticate('admin', 'wrong') === false, 'Wrong password must be rejected');
    check(admin_authenticate('', 'OwnerPass2026') === false, 'Empty username must be rejected');
    check(admin_authenticate('admin', '') === false, 'Empty password must be rejected');
    check(admin_authenticate('nobody', 'OwnerPass2026') === false, 'Unknown user must be rejected');
    check((int)admin_find_user('admin')['must_change_password'] === 0,
        'An account created by the wizard must not be flagged as needing a password change');

    // 装好之后绝不允许再建第二个 owner —— 否则任何人都能把自己提权成站长
    try {
        admin_install_owner('intruder', 'IntruderPass2026');
        throw new RuntimeException('A second owner must not be creatable after setup');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('站点已完成安装，请直接登录。'), 'Unexpected post-setup install error');
    }
    check(admin_user_count() === 1, 'The blocked second setup must not create an account');

    /* ---------- 后台改密 ---------- */
    // 留空表示保持原密码
    admin_save_account('admin', '');
    check(admin_authenticate('admin', 'OwnerPass2026') === true, 'Blank password must keep the current one');
    admin_save_account('admin', 'BrandNewPass2026');
    check(admin_authenticate('admin', 'OwnerPass2026') === false, 'Old password must stop working');
    check(admin_authenticate('admin', 'BrandNewPass2026') === true, 'New password must take effect');
    check(password_verify('BrandNewPass2026', (string)admin_find_user('admin')['password_hash']),
        'Passwords must be stored as hashes, never plaintext');

    // 「需要改密」标记：老库升级的存量账号会带上，改一次密码即消除
    $_SESSION['admin_username'] = 'admin';
    $pdo->exec("UPDATE admin_users SET must_change_password = 1 WHERE username = 'admin'");
    check(admin_must_change_password() === true, 'A flagged account must report that a password change is due');
    admin_save_account('admin', 'FreshPass2026');
    check(admin_must_change_password() === false, 'Changing the password must clear the flag');
    // 只改用户名、不换密码，不能把提醒糊弄过去
    $pdo->exec("UPDATE admin_users SET must_change_password = 1 WHERE username = 'admin'");
    admin_save_account('admin', '');
    check(admin_must_change_password() === true, 'Saving without a new password must keep the flag');
    $_SESSION['admin_username'] = null;
    check(admin_must_change_password() === false, 'No signed-in user means no flag to report');

    /* ---------- 角色分级 ---------- */
    check(admin_role_for('admin') === ROLE_OWNER, 'Seeded admin must be owner');
    admin_create_user('member1', 'MemberPass123');
    check(admin_role_for('member1') === ROLE_EDITOR, 'Self-registered accounts must be editor');
    try {
        admin_create_user('member1', 'AnotherPass123');
        throw new RuntimeException('Duplicate registration must be rejected');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('该用户名已被注册，请换一个试试。'), 'Unexpected duplicate-registration error');
    }
    check(admin_role_normalize('bogus') === ROLE_OWNER, 'Unknown roles must fall back to owner, not silently downgrade');
    check(admin_role_normalize('') === ROLE_OWNER, 'Blank role must fall back to owner');
    try {
        admin_create_user('', 'Whatever123');
        throw new RuntimeException('Empty username must be rejected');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('用户名不能为空。'), 'Unexpected empty-username error');
    }

    // 「自助改密」不应把 owner 降级，也不应把 editor 提权
    admin_save_account('admin', 'OwnerPass2026');
    check(admin_role_for('admin') === ROLE_OWNER, 'Changing a password must not change the role');

    /* ---------- 登录限流：双维度 + 指数退避 ---------- */
    $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
    $pdo->exec('DELETE FROM login_throttle');

    // 同一账号从不同 IP 尝试：IP 会变，但账号维度应当持续累加
    throttle_fail('user', 'admin', LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    check(throttle_remaining('user', 'admin') === 0, 'Two failures must not lock yet');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.41';          // 换 IP
    throttle_fail('user', 'admin', LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.42';          // 再换 IP
    throttle_fail('user', 'admin', LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    check(throttle_user_remaining('admin') > 0, 'Same account must lock even when the IP keeps changing');
    check(throttle_ip_remaining() === 0, 'Each new IP alone should not be locked after one failure');

    // 指数退避：30 → 60 → 120
    $pdo->exec('DELETE FROM login_throttle');
    $target = 'guessing-victim';
    $durations = [];
    for ($round = 1; $round <= 3; $round++) {
        for ($i = 0; $i < LOGIN_FAIL_LIMIT; $i++) {
            throttle_fail('user', $target, LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
        }
        $remaining = throttle_remaining('user', $target);
        $durations[] = $remaining;
        $pdo->prepare('UPDATE login_throttle SET locked_until = 0 WHERE scope = ? AND subject_hash = ?')
            ->execute(['user', throttle_hash('user', $target)]);
    }
    check($durations[1] > $durations[0], 'Second lockout must last longer (backoff)');
    check($durations[2] > $durations[1], 'Third lockout must last longer still (backoff)');

    // 验证码维度独立，不应消耗密码尝试次数
    $pdo->exec('DELETE FROM login_throttle');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    throttle_fail('captcha', login_client_ip(), LOGIN_CAPTCHA_FAIL_LIMIT, LOGIN_CAPTCHA_LOCK_SECONDS);
    check(throttle_ip_remaining() === 0, 'Captcha failures must not lock password attempts');
    throttle_fail('ip', login_client_ip(), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    check(throttle_ip_remaining() === 0, 'A single password failure must not lock yet');

    // 登录成功后清零
    $pdo->exec('DELETE FROM login_throttle');
    throttle_fail('ip', login_client_ip(), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    throttle_fail('user', 'someone', LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
    throttle_clear('ip', login_client_ip());
    throttle_clear('user', 'someone');
    check(throttle_ip_remaining() === 0, 'Successful login must clear IP counters');
    check(throttle_user_remaining('someone') === 0, 'Successful login must clear account counters');

    /* ---------- 老库升级：存量账号必须被标记为「需要改密」 ---------- */
    // 模拟一个还没加 must_change_password 列的旧库：删掉该列后重跑迁移。
    // 迁移应当补回这一列，并把已有账号一律置 1 —— 我们无法从哈希反推它是不是
    // 当年那个明文默认密码，宁可多提醒一次。
    $pdo->exec('ALTER TABLE admin_users DROP COLUMN must_change_password');
    init_database($pdo);
    $cols = array_column($pdo->query('PRAGMA table_info(admin_users)')->fetchAll(), 'name');
    check(in_array('must_change_password', $cols, true), 'Migration must restore the dropped column');
    check((int)admin_find_user('admin')['must_change_password'] === 1,
        'Legacy accounts must be flagged as needing a password change');
    // 清掉标记，避免影响上面已断言过的其它检查
    admin_mark_password_changed('admin');
    check(admin_must_change_password() === false, 'Clearing the flag must work');

    /* ---------- 幽灵会话：账号没了，会话不该继续有效 ---------- */
    $_SESSION['admin_username'] = 'admin';
    check(admin_session_user_exists() === true, 'An existing account must keep the session valid');
    $pdo->exec("DELETE FROM admin_users WHERE username = 'member1'");
    $_SESSION['admin_username'] = 'member1';
    check(admin_session_user_exists() === false,
        'A deleted account must invalidate the session (no ghost sessions)');
    $_SESSION['admin_username'] = '';
    check(admin_session_user_exists() === false, 'A blank session username must not count as valid');
    $_SESSION['admin_username'] = 'admin';

    /* ---------- 注册：弱密码在数据层也要被挡住 ---------- */
    // 页面层的校验换一个调用点就能绕开，所以 admin_create_user 自己也得校验
    try {
        admin_create_user('weakling', '1234');
        throw new RuntimeException('A short password must be rejected when creating a user');
    } catch (RuntimeException $e) {
        check($e->getMessage() === t('新密码长度至少 8 位。'), 'Unexpected weak-password error on create');
    }
    check(admin_find_user('weakling') === null, 'The rejected weak account must not exist');

    /* ---------- 注册限流：成功也计数 ---------- */
    // 只统计失败挡不住脚本 —— 它每次都能成功。这里按 IP 维度累计注册成功次数。
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
    $pdo->exec("DELETE FROM login_throttle WHERE scope = 'register'");
    for ($i = 0; $i < REGISTER_MAX_PER_WINDOW; $i++) {
        throttle_fail('register', login_client_ip(), REGISTER_MAX_PER_WINDOW, REGISTER_LOCK_SECONDS);
    }
    check(throttle_remaining('register', login_client_ip()) > 0,
        'Reaching the registration ceiling must lock further registrations');
    // 换一个 IP 不受影响
    check(throttle_remaining('register', '198.51.100.78') === 0, 'The ceiling must be per-IP');
    $pdo->exec("DELETE FROM login_throttle WHERE scope = 'register'");

    /* ---------- 站点设置 ---------- */
    save_setting('site_name', '测试站点');
    check(raw_setting('site_name') === '测试站点', 'Settings round-trip failed');
    save_setting('search_engines', "坏行没有分隔符\n必应|https://www.bing.com/search?q={q}\n脚本|javascript:alert(1)?q={q}");
    $engines = parse_search_engines(setting('search_engines'));
    check(count($engines) === 1, 'Invalid engine lines must be ignored');
    check($engines[0]['name'] === '必应', 'Valid engine line was dropped');

    echo "PASS: admin CRUD, cascade delete, credentials, role tiers, throttling and settings\n";
} finally {
    $pdo = null;
    foreach (['', '-wal', '-shm'] as $suffix) if (is_file($file . $suffix)) unlink($file . $suffix);
}
