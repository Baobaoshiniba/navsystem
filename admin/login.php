<?php
/**
 * 后台 - 登录
 *
 * 视觉：左侧品牌区（Logo / 公司名 / 价值主张 / 渐变光晕 / 网格底纹）+ 右侧玻璃表单卡，
 *       浅色默认（不跟随系统深色），≤1000px 自动堆叠；样式统一并入 assets/css/style.css 第 11 节。
 * 账号校验：一律校验 admin_users 表（password_hash 存储）；密码可在「修改密码」页自助更换。
 *       库内没有任何账号时跳 install.php 引导创建首位管理员 —— 已不存在任何明文默认密码。
 * 注册入口：仅当「站点设置」开启「开放注册」时显示（register_enabled()）。
 * 安全：图形验证码（4 位、一次性）+ 同 IP 连续失败 3 次锁定 30 秒；验证码错误不计入锁定计数。
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

secure_session_start();

// 尚未创建任何管理员账号：先走安装向导，别让人对着一个空库猜密码
if (admin_needs_install()) {
    redirect('install.php');
}

// 已登录则直接进入后台
if (!empty($_SESSION['admin_logged_in'])) {
    redirect('index.php');
}

$error    = '';
$username = '';
$notice   = isset($_GET['registered']) ? t('注册成功，请使用新账号登录。') : '';

// 进入本页面前先确认 IP 维度是否被锁（验证码独立计数，不影响这里的判断）
$lockedIp = throttle_ip_remaining();

if (is_post()) {
    if (!csrf_verify()) {
        $error = t('安全校验失败，请刷新页面重试。');
    } else {
        $username = post('username');
        $lockedUser = throttle_user_remaining($username);
        $locked = max($lockedIp, $lockedUser);

        if ($locked > 0) {
            // 锁定期内直接拒绝，即便账号密码正确也不放行
            $error = (t('登录失败次数过多，请') . ' ') . $locked . (' ' . t('秒后再试。'));
        } elseif (!captcha_verify(post('captcha'))) {
            // 验证码错误走独立计数：与密码错误分开统计，避免互相干扰
            $left = throttle_fail('captcha', login_client_ip(), LOGIN_CAPTCHA_FAIL_LIMIT, LOGIN_CAPTCHA_LOCK_SECONDS);
            $error = $left > 0
                ? (t('验证码错误或已失效，请重新输入。还可尝试') . ' ') . $left . (' ' . t('次。'))
                : (t('验证码连续错误过多，请稍后再试。'));
        } else {
            $password = (string)($_POST['password'] ?? '');

            if (admin_authenticate($username, $password)) {
                // 登录成功：一次性清掉该 IP 与该账号两个维度的失败记录
                throttle_clear('ip', login_client_ip());
                throttle_clear('user', strtolower($username));
                throttle_clear('captcha', login_client_ip());
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username']  = $username;
                // 角色随登录写入会话；库内无该账号时用 owner 兜底（兼容 config.php 明文账号的旧库）
                $_SESSION['admin_role']      = admin_role_for($username);
                redirect('index.php');
            }

            // 密码错误：同时累加 IP 与账号两个维度。
            // 只按 IP 计数的话，攻击者轮换账号就能绕过；只按账号计数则换 IP 能绕过 —— 两个都要计。
            $leftIp   = throttle_fail('ip', login_client_ip(), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
            $leftUser = throttle_fail('user', strtolower($username), LOGIN_FAIL_LIMIT, LOGIN_LOCK_BASE_SECONDS);
            $left     = min($leftIp, $leftUser);
            $error = $left > 0
                ? (t('账号或密码错误，还可尝试') . ' ') . $left . (' ' . t('次。'))
                : (t('账号或密码错误，失败次数过多，请稍后再试。'));
        }
    }
}

$siteName     = e(setting('site_name'));
$theme        = theme_preference();
$openRegister = register_enabled();
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(t('管理后台登录 ·')) ?> <?= $siteName ?></title>
<?= favicon_html('../assets/') ?>
<style>
html:not([data-loaded]) { background:#f8fafc; }
html[data-theme="dark"]:not([data-loaded]) { background:#111827; }
</style>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/workspace.css">
</head>
<body class="auth-body">
<div class="auth-shell">

    <?= auth_brand_html(
        t('让每一次出发，都有清晰的入口'),
        t('把小组常用的协作平台、开发文档与学习资料整理在一起，让每位成员都能快速找到需要的资源。'),
        [
            t('按小组需要整理分类，随时更新常用资源'),
            t('账号密码加密入库，登录态安全可控'),
            t('点击数据实时统计，热门入口一目了然'),
        ],
        t('管理后台 · 小组成员维护入口')
    ) ?>

    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authTitle">
            <div class="auth-language"><?= language_switch_html() ?></div>
            <div class="auth-card-head">
                <span class="auth-badge"><?= e(t('管理后台')) ?></span>
                <h1 class="auth-title" id="authTitle"><?= e(t('欢迎回来')) ?></h1>
                <p class="auth-sub"><?= e(t('请使用管理员账号登录，继续维护你的导航页。')) ?></p>
            </div>

            <?php if ($notice): ?>
                <div class="flash flash-success" role="status"><?= e($notice) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="flash flash-error" id="flashMsg" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" class="auth-form">
                <?= csrf_field() ?>

                <div class="form-field">
                    <label class="field-label" for="username"><?= e(t('账号')) ?></label>
                    <input class="form-input" type="text" id="username" name="username"
                           value="<?= e($username) ?>" required autocomplete="username"
                           autofocus placeholder="<?= e(t('请输入账号')) ?>">
                </div>

                <div class="form-field">
                    <label class="field-label" for="password"><?= e(t('密码')) ?></label>
                    <input class="form-input" type="password" id="password" name="password"
                           required autocomplete="current-password" placeholder="<?= e(t('请输入密码')) ?>">
                </div>

                <div class="form-field">
                    <label class="field-label" for="captcha"><?= e(t('验证码')) ?></label>
                    <div class="captcha-row">
                        <input class="form-input" type="text" id="captcha" name="captcha"
                               required autocomplete="off" maxlength="4"
                               placeholder="<?= e(t('请输入右侧 4 位字符')) ?>">
                        <img class="captcha-img" id="captchaImg" src="captcha.php?v=<?= time() ?>"
                             alt="<?= e(t('验证码')) ?>" title="<?= e(t('点击换一张')) ?>"
                             data-captcha-src="captcha.php">
                    </div>
                </div>

                <button type="submit" class="btn-primary btn-block"><?= e(t('登 录')) ?></button>
            </form>

            <?php if ($openRegister): ?>
                <div class="auth-alt">
                    <span><?= e(t('还没有账号？')) ?></span>
                    <a href="register.php"><?= e(t('立即注册')) ?></a>
                </div>
            <?php endif; ?>
        </section>

        <div class="auth-back">
            <a href="../index.php">← <?= e(t('返回前台导航页')) ?></a>
        </div>
    </main>

</div>
<?php page_footer('../'); ?>
