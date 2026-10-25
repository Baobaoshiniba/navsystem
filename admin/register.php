<?php
/**
 * 后台 - 注册（内部小团队自助开通管理账号）
 *
 * 行为契约：
 *  - 是否可注册由「站点设置 → 开放注册」开关决定（settings 键 register_enabled，默认关闭）；
 *    关闭时页面只展示「注册已关闭」提示与返回登录入口，POST 一律拦截。
 *  - 开启时：用户名（3–20 位，中英文 / 数字 / 下划线 / 连字符 / 点）+ 密码（≥6 位）+ 确认密码；
 *    校验通过后以 password_hash() 写入 admin_users，成功后跳登录页并提示。
 *  - 重复用户名 / 密码过短 / 两次不一致 / 用户名为空或格式不合法，均给出明确错误并回填用户名。
 *  - 视觉与登录页同源：左侧品牌区 + 右侧玻璃表单卡，样式统一并入 assets/css/style.css 第 11 节。
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

secure_session_start();

$openRegister = register_enabled();

$errors   = [];
$username = '';
$flags    = ['username' => false, 'password' => false, 'confirm_password' => false];

/** 字符串长度（优先按字符计数，兼容多字节用户名） */
$len = static function (string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
};

if (is_post()) {
    if (!$openRegister) {
        $errors[] = t('注册已关闭，暂不支持创建新账号。');
    } elseif (!csrf_verify()) {
        $errors[] = t('安全校验失败，请刷新页面重试。');
    } else {
        $username = post('username');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if ($username === '') {
            $errors[]        = t('请输入用户名。');
            $flags['username'] = true;
        } elseif ($len($username) < 3 || $len($username) > 20) {
            $errors[]        = t('用户名长度需为 3–20 个字符。');
            $flags['username'] = true;
        } elseif (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_.-]+$/u', $username)) {
            $errors[]        = t('用户名仅支持中英文、数字、下划线、连字符与点。');
            $flags['username'] = true;
        }

        if ($password === '') {
            $errors[]         = t('请输入密码。');
            $flags['password'] = true;
        } elseif ($len($password) < 6) {
            $errors[]         = t('密码至少 6 位。');
            $flags['password'] = true;
        } elseif ($len($password) > 64) {
            $errors[]         = t('密码最长 64 位。');
            $flags['password'] = true;
        }

        if (!$flags['password'] && $password !== $confirm) {
            $errors[]                = t('两次输入的密码不一致，请重新确认。');
            $flags['confirm_password'] = true;
        }

        if (!$errors) {
            try {
                admin_create_user($username, $password);
                redirect('login.php?registered=1');
            } catch (Throwable $ex) {
                $errors[]          = $ex->getMessage();
                $flags['username'] = true;
            }
        }
    }
}

$siteName = e(setting('site_name'));
$theme    = theme_preference();
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(t('注册管理账号 ·')) ?> <?= $siteName ?></title>
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
        t('三步开通你的管理账号'),
        t('注册成功后即可登录后台，维护站点分类、链接与展示文案，所有改动前台即时生效。'),
        [
            t('用户名与密码自主设置，密码哈希存储'),
            t('注册入口由「站点设置」开关统一控制'),
            t('账号开通后即刻可登录，无需人工审批'),
        ],
        t('管理后台 · 注册开放状态可随时调整')
    ) ?>

    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authTitle">
            <div class="auth-language"><?= language_switch_html() ?></div>
            <div class="auth-card-head">
                <span class="auth-badge"><?= e(t('创建账号')) ?></span>
                <h1 class="auth-title" id="authTitle"><?= $openRegister ? t('注册管理账号') : t('注册已关闭') ?></h1>
                <p class="auth-sub">
                    <?= $openRegister
                        ? t('用户名 3–20 位，密码不少于 6 位。')
                        : t('管理员已在「站点设置」中关闭了注册入口。') ?>
                </p>
            </div>

            <?php if ($errors): ?>
                <div class="auth-errors" id="flashMsg" role="alert">
                    <ul>
                        <?php foreach ($errors as $msg): ?>
                            <li><?= e($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($openRegister): ?>
                <form method="post" action="register.php" class="auth-form">
                    <?= csrf_field() ?>

                    <div class="form-field">
                        <label class="field-label" for="username"><?= e(t('用户名')) ?></label>
                        <input class="form-input<?= $flags['username'] ? ' is-error' : '' ?>" type="text"
                               id="username" name="username" value="<?= e($username) ?>"
                               required autocomplete="username" autofocus
                               placeholder="3–20 <?= e(t('位，中英文 / 数字 / _ -')) ?> .">
                    </div>

                    <div class="form-field">
                        <label class="field-label" for="password"><?= e(t('密码')) ?></label>
                        <input class="form-input<?= $flags['password'] ? ' is-error' : '' ?>" type="password"
                               id="password" name="password" required autocomplete="new-password"
                               placeholder="<?= e(t('至少 6 位')) ?>">
                    </div>

                    <div class="form-field">
                        <label class="field-label" for="confirm_password"><?= e(t('确认密码')) ?></label>
                        <input class="form-input<?= $flags['confirm_password'] ? ' is-error' : '' ?>" type="password"
                               id="confirm_password" name="confirm_password" required
                               autocomplete="new-password" placeholder="<?= e(t('请再次输入密码')) ?>">
                    </div>

                    <button type="submit" class="btn-primary btn-block"><?= e(t('注 册')) ?></button>
                </form>
            <?php endif; ?>

            <div class="auth-alt">
                <span><?= e(t('已有账号？')) ?></span>
                <a href="login.php"><?= e(t('返回登录')) ?></a>
                <?php if (!$openRegister): ?>
                    <span class="auth-alt-note"><?= e(t('如需开放注册，请登录后台在「站点设置」中开启。')) ?></span>
                <?php endif; ?>
            </div>
        </section>

        <div class="auth-back">
            <a href="../index.php">← <?= e(t('返回前台导航页')) ?></a>
        </div>
    </main>

</div>
<?php page_footer('../'); ?>
