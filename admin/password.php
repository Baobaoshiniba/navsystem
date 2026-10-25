<?php
/**
 * 后台 - 修改密码
 *
 * 为什么要单独开这一页：此前改密码的唯一入口是「重置演示」弹窗，而那个弹窗会连带
 * 清空全部分类、链接与点击统计 —— 想改密码就得先毁掉站点数据，结果就是没人改。
 * 这一页只改密码，不动任何内容。
 *
 * 所有角色（owner / editor）都能进：改自己的密码不需要站长权限。
 *
 * 安全：
 *   - 必须先用当前密码验证身份，防止会话被劫持后直接改密夺号
 *   - 当前密码校验失败计入登录限流（IP 与账号双维度）—— 否则这里会变成一个
 *     不限次数的撞密码入口，把登录页的限流绕过去
 *   - 改密成功即 session_regenerate_id，并清除「需要改密」标记
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();
secure_session_start();

$username = (string)($_SESSION['admin_username'] ?? '');
$errors   = [];

if (is_post()) {
    guard_csrf();

    // 密码一律直取 $_POST，不经过 post() 的 trim —— 首尾空格也是密码的一部分
    $current   = (string)($_POST['current_password'] ?? '');
    $password  = (string)($_POST['new_password'] ?? '');
    $password2 = (string)($_POST['confirm_password'] ?? '');

    $pwdLen = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    // 先判锁：锁定期内即便密码正确也不放行
    $locked = max(throttle_ip_remaining(), throttle_user_remaining($username));

    if ($locked > 0) {
        $errors[] = (t('登录失败次数过多，请') . ' ') . $locked . (' ' . t('秒后再试。'));
    } elseif (!admin_authenticate($username, $current)) {
        // 与登录页同一套双维度计数，避免这里成为限流的旁路
        $leftIp   = throttle_fail('ip', login_client_ip(), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
        $leftUser = throttle_fail('user', strtolower($username), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
        $left     = min($leftIp, $leftUser);
        $errors[] = $left > 0
            ? (t('当前密码不正确，还可尝试') . ' ') . $left . (' ' . t('次。'))
            : t('当前密码不正确，失败次数过多，请稍后再试。');
    } elseif ($password !== $password2) {
        $errors[] = t('两次输入的新密码不一致，请重新输入。');
    } elseif ($pwdLen < ADMIN_PASSWORD_MIN_LENGTH) {
        $errors[] = t('新密码长度至少 8 位。');
    } elseif (hash_equals($current, $password)) {
        $errors[] = t('新密码不能与当前密码相同。');
    }

    if (!$errors) {
        // 用户名保持不变，只换密码；admin_save_account 内部会清 must_change_password
        admin_save_account($username, $password);

        throttle_clear('user', strtolower($username));
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username']  = $username;
        $_SESSION['admin_role']      = admin_role_for($username);

        flash(t('密码已更新，下次登录请使用新密码。'));
        redirect('password.php');
    }
}

admin_header(t('修改密码'), 'password');

echo ('<div class="page-title-row">
    <h1 class="page-title">' . e(t('修改密码')) . '</h1>
    <p class="page-sub">' . e(t('修改当前账号的登录密码。只影响密码本身，不会改动任何分类、链接与统计数据。')) . '</p>
</div>');

if ($errors) {
    echo '<div class="flash flash-error" role="alert">' . e(implode(' ', $errors)) . '</div>';
}

echo '<form method="post" action="password.php" class="panel glass" autocomplete="off">';
echo csrf_field();
echo '<div class="form-stack">';

echo ('<div class="form-field">
    <label class="field-label" for="f-current">' . e(t('当前密码')) . '<span class="req">*</span></label>
    <input class="form-input" type="password" id="f-current" name="current_password"
           required autocomplete="current-password" placeholder="' . e(t('请输入当前密码')) . '">
    <div class="field-hint">' . e(t('连续输错会触发登录限流，与登录页共用同一套计数。')) . '</div>
</div>');

echo ('<div class="form-field">
    <label class="field-label" for="f-new">' . e(t('新密码')) . '<span class="req">*</span></label>
    <input class="form-input" type="password" id="f-new" name="new_password"
           required autocomplete="new-password" placeholder="' . e(t('至少 8 位，建议使用字母与数字组合')) . '">
</div>');

echo ('<div class="form-field">
    <label class="field-label" for="f-confirm">' . e(t('确认新密码')) . '<span class="req">*</span></label>
    <input class="form-input" type="password" id="f-confirm" name="confirm_password"
           required autocomplete="new-password" placeholder="' . e(t('再次输入新密码')) . '">
</div>');

echo '</div>';

echo ('<div class="form-actions">
    <button type="submit" class="btn-primary">' . e(t('修改密码')) . '</button>
    <div class="field-hint">' . e(t('密码以哈希形式入库，系统不保存明文，也无法找回——请牢记新密码。')) . '</div>
</div>');

echo '</form>';

admin_footer();
