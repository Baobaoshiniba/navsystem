<?php
/**
 * 后台 - 首次安装向导
 *
 * 触发条件：admin_users 表内一个账号都没有（全新库）。后台任意页面检测到该状态时
 * 都会重定向过来 —— 见 admin/_init.php 的 require_login() 与 login.php 开头。
 *
 * 为什么要有这一页：此前全新库会用 config.php 里的明文密码自动播种一个管理员，
 * 而 README 又把同一串字符印在文档里，等于把后台钥匙公开；又因为「改密码要先清空
 * 站点数据」，实际上几乎没人会去改。现在改由安装者当场自己设。
 *
 * 防抢先安装：非本机（非 127.0.0.1 / ::1）来源必须额外输入安装密钥，密钥由 install_key()
 * 生成并写入 data/.install_key（该目录已被三层封堵，见 README「运行时数据保护」）。
 * 少了这层校验，公网部署时任何人抢先打开本页就能把自己设成站长。
 *
 * 视觉沿用登录页：左侧品牌区 + 右侧玻璃表单卡。
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

secure_session_start();

// 站点已装好 → 本页必须失效，否则任何人都能再来建一个 owner
if (!admin_needs_install()) {
    redirect('login.php');
}
// 极端情况：会话里还留着登录态（例如装到一半），直接放行进后台
if (!empty($_SESSION['admin_logged_in'])) {
    redirect('index.php');
}

$local = in_array(login_client_ip(), ['127.0.0.1', '::1'], true);
// 本机免密钥（本地开发与课堂演示不受影响），远程必须验
$needsKey = !$local;

$errors   = [];
$username = (string)$admin_config['username'];

if (is_post()) {
    if (!csrf_verify()) {
        $errors[] = t('安全校验失败，请刷新页面重试。');
    } else {
        $username  = trim(post('username'));
        // 密码直取 $_POST，不经过 post() 的 trim —— 首尾空格也是密码的一部分
        $password  = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $keyInput  = trim(post('install_key'));

        $userLen = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);
        $pwdLen  = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

        if ($username === '') {
            $errors[] = t('管理员用户名不能为空。');
        } elseif ($userLen > 32) {
            $errors[] = t('管理员用户名最长 32 个字符。');
        }
        if ($password !== $password2) {
            $errors[] = t('两次输入的新密码不一致，请重新输入。');
        } elseif ($pwdLen < ADMIN_PASSWORD_MIN_LENGTH) {
            $errors[] = t('新密码长度至少 8 位。');
        }
        // hash_equals 而非 ===：避免用响应耗时逐字节猜出密钥
        if ($needsKey && !hash_equals(install_key(), $keyInput)) {
            $errors[] = t('安装密钥不正确，请查看 data/.install_key 文件。');
        }

        if (!$errors) {
            try {
                admin_install_owner($username, $password);

                // 建号即登录，省去再敲一次密码；首位管理员恒为 owner
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username']  = $username;
                $_SESSION['admin_role']      = ROLE_OWNER;

                flash(t('站长账号已创建，欢迎使用。'));
                redirect('index.php');
            } catch (Throwable $e) {
                // 业务校验的失败（用户名已存在、密码太短）本来就该给用户看；
                // 但代码缺陷同样会落到这里，而那时 $e->getMessage() 是
                // 「Call to undefined function ...」这类内部信息 —— 安装页对匿名访客开放，
                // 照原样上屏等于把实现细节送出去。这里只留一句人话，细节写进本机错误日志。
                if ($e instanceof Error) {
                    error_log('[navsystem] setup failed: ' . $e->getMessage());
                    $errors[] = t('创建账号失败，请稍后重试。');
                } else {
                    $errors[] = $e->getMessage();
                }
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
<title><?= e(t('创建站长账号 ·')) ?> <?= $siteName ?></title>
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
        t('首次安装 · 创建站长账号')
    ) ?>

    <main class="auth-panel">
        <section class="auth-card" aria-labelledby="authTitle">
            <div class="auth-language"><?= language_switch_html() ?></div>
            <div class="auth-card-head">
                <span class="auth-badge"><?= e(t('首次安装')) ?></span>
                <h1 class="auth-title" id="authTitle"><?= e(t('创建站长账号')) ?></h1>
                <p class="auth-sub"><?= e(t('系统尚未创建任何管理员账号。请设置第一位站长的用户名与密码，该账号拥有全部权限。')) ?></p>
            </div>

            <?php if ($errors): ?>
                <div class="flash flash-error" role="alert"><?= e(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <form method="post" action="install.php" class="auth-form">
                <?= csrf_field() ?>

                <div class="form-field">
                    <label class="field-label" for="username"><?= e(t('用户名')) ?></label>
                    <input class="form-input" type="text" id="username" name="username"
                           value="<?= e($username) ?>" required maxlength="32"
                           autocomplete="username" autofocus placeholder="<?= e(t('请输入账号')) ?>">
                </div>

                <div class="form-field">
                    <label class="field-label" for="password"><?= e(t('密码')) ?></label>
                    <input class="form-input" type="password" id="password" name="password"
                           required autocomplete="new-password"
                           placeholder="<?= e(t('至少 8 位，建议使用字母与数字组合')) ?>">
                </div>

                <div class="form-field">
                    <label class="field-label" for="password2"><?= e(t('确认密码')) ?></label>
                    <input class="form-input" type="password" id="password2" name="password2"
                           required autocomplete="new-password" placeholder="<?= e(t('再次输入新密码')) ?>">
                </div>

                <?php if ($needsKey): ?>
                <div class="form-field">
                    <label class="field-label" for="install_key"><?= e(t('安装密钥')) ?></label>
                    <input class="form-input" type="text" id="install_key" name="install_key"
                           required autocomplete="off" placeholder="<?= e(t('请输入安装密钥')) ?>">
                    <div class="field-hint"><?= e(t('检测到你不是从本机访问，需要输入安装密钥以防止他人抢先创建站长账号。密钥位于 data/.install_key 文件中。')) ?></div>
                </div>
                <?php else: ?>
                <input type="hidden" name="install_key" value="">
                <?php endif; ?>

                <button type="submit" class="btn-primary btn-block"><?= e(t('创建账号并进入后台')) ?></button>
            </form>

            <div class="auth-alt">
                <span><?= e(t('密码只以哈希形式入库，不会以明文保存，之后可在「修改密码」页随时更换。')) ?></span>
            </div>
        </section>

        <div class="auth-back">
            <a href="../index.php">← <?= e(t('返回前台导航页')) ?></a>
        </div>
    </main>

</div>
<?php page_footer('../'); ?>
