<?php
/**
 * 公司导航页 - 后台「重置演示数据 / 清空为空白」
 *
 * 行为契约：
 *  - 两个动作：reset（清空后重写演示数据）与 blank（只清空分类 / 链接，不写演示数据，ID 从 1 开始）；
 *  - 任一动作触发前，先弹出「必要数据设置」模态框：管理员账号（用户名预填 / 新密码 / 确认密码）、
 *    站点 5 项文案（预填当前值，留空回退系统默认）、危险确认（勾选已知晓 + 输入「清空」）；
 *  - 提交后先写入必要数据（admin_users 账号 + settings 站点设置），再执行清空或重置，
 *    全流程置于同一事务，任一步失败整体回滚并给出明确错误提示；
 *  - 管理员账号与站点设置均不在清空范围内（只清空 categories / links 及其自增序列）。
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_once __DIR__ . '/_vault.php';
// 清空 / 重置不可恢复，且弹窗里能直接改掉管理员账号 —— 仅站长可进入
require_owner();

$db       = db();
$siteKeys = ['site_name', 'logo_text', 'site_slogan', 'search_placeholder', 'footer_text'];

/* ------------------------------------------------------------------
 * POST：保存必要数据 + 执行动作（同一事务）
 * ---------------------------------------------------------------- */
if (is_post()) {
    guard_csrf();

    $action      = post('action');
    $username    = post('username');
    $password    = (string)($_POST['new_password'] ?? '');
    $password2   = (string)($_POST['confirm_password'] ?? '');
    $confirmText = post('confirm_text');
    $ack         = post('ack') === '1';

    $siteValues = [];
    foreach ($siteKeys as $k) {
        $siteValues[$k] = post($k, '');
    }

    /* 校验失败 / 执行失败时回填：写入 Session 后 PRG 回本页，页面会自动重开模态框并保留已填内容 */
    $rememberForm = static function (string $action, string $username, array $siteValues): void {
        $_SESSION['reset_form'] = [
            'action'   => $action,
            'username' => $username,
            'site'     => $siteValues,
        ];
    };

    $errors = [];
    if ($action === 'restore_demo') {
        // 完整 Demo 恢复：账号与保险箱凭据均为公开测试值。
        $username = 'admin';
        $password = 'admin123.';
        $password2 = 'admin123.';
    }
    $userLen = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);
    $pwdLen  = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if (!in_array($action, ['reset', 'blank', 'restore_demo'], true)) {
        $errors[] = t('未知操作，请重新选择要执行的动作。');
    }
    if ($username === '') {
        $errors[] = t('管理员用户名不能为空。');
    } elseif ($userLen > 32) {
        $errors[] = t('管理员用户名最长 32 个字符。');
    }
    if ($password !== '' || $password2 !== '') {
        if ($password !== $password2) {
            $errors[] = t('两次输入的新密码不一致，请重新输入。');
        } elseif ($pwdLen < ADMIN_PASSWORD_MIN_LENGTH) {
            $errors[] = t('新密码长度至少 8 位。');
        }
    }
    if (!$ack) {
        $errors[] = t('请先勾选「我已了解该操作不可恢复」。');
    }
    $requiredPhrase = $action === 'restore_demo' ? t('恢复演示') : t('清空');
    if ($confirmText !== $requiredPhrase) {
        $errors[] = t('请在确认框中输入对应的确认短语后再提交。');
    }

    if ($errors) {
        $rememberForm(is_string($action) ? $action : '', $username, $siteValues);
        flash(implode(' ', $errors), 'error');
        redirect('reset.php');
    }

    // 动手前留一份快照。完整演示恢复必须先备份成功，避免无法回退。
    $backupPath = backup_create($action === 'blank' ? 'blank' : ($action === 'restore_demo' ? 'demo-restore' : 'reset'));
    if ($action === 'restore_demo' && $backupPath === '') {
        flash(t('恢复前备份失败，未修改任何数据；请检查磁盘空间和 data/backups 写入权限。'), 'error');
        redirect('reset.php');
    }

    try {
        $db->beginTransaction();

        // 1) 完整恢复时重建公开 Demo 站长；普通动作仍按弹窗修改账号。
        if ($action === 'restore_demo') {
            $db->exec('DELETE FROM admin_users;');
            $db->exec("DELETE FROM sqlite_sequence WHERE name = 'admin_users';");
            $db->prepare(
                'INSERT INTO admin_users (username, password_hash, role, must_change_password, created_at, updated_at)
                 VALUES (?, ?, ?, 0, datetime(\'now\'), datetime(\'now\'))'
            )->execute(['admin', password_hash('admin123.', PASSWORD_DEFAULT), ROLE_OWNER]);
            $_SESSION['admin_username'] = 'admin';
        } else {
            admin_save_account($username, $password);
            $_SESSION['admin_username'] = $username;
        }

        // 普通重置可同时保存弹窗中的站点文案；完整恢复保留用户配置和联系邮箱。
        if ($action !== 'restore_demo') {
            $upsertSetting = $db->prepare(
                "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
            );
            foreach ($siteValues as $k => $v) {
                $upsertSetting->execute([$k, $v]);
            }
        }

        // 清空链接 / 分类、点击去重记录及自增序列。
        $db->exec('DELETE FROM links;');
        $db->exec('DELETE FROM categories;');
        $db->exec('DELETE FROM click_throttle;');
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('links', 'categories');");

        // 普通「重置演示」与「完整恢复」写入默认资源；「清空为空白」保持空库。
        if ($action === 'reset' || $action === 'restore_demo') {
            seed_demo_data($db);
        }

        if ($action === 'restore_demo') {
            // 已在同一事务和快照保护下清空原保险箱，再创建课堂公开测试密码。
            vault_reset_all();
            vault_setup_master('12345678');
            vault_lock();
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($action === 'restore_demo') vault_lock();
        $rememberForm(is_string($action) ? $action : '', $username, $siteValues);
        // 异常原文是 SQL、表结构、文件路径这类内部细节 —— 连站长都不必看到，
        // 落到服务器错误日志里去查就够了。与 install.php 的处理保持一致。
        error_log('[navsystem] reset failed: ' . $e->getMessage());
        flash(t('操作失败，数据未发生任何变更，请查看服务器错误日志。'), 'error');
        redirect('reset.php');
    }

    // 账号可能已改名，同步会话中的用户名
    $_SESSION['admin_username'] = $username;

    // 备份成功就把文件名告诉站长，方便需要时找回；失败也照常完成重置，只在提示里不提
    $backupNote = $backupPath !== ''
        ? (' ' . t('已自动备份当前数据库到') . ' data/backups/' . basename($backupPath) . '。')
        : '';

    if ($action === 'restore_demo') {
        flash(t('完整演示环境已恢复：所有后台账号已重建为 admin / admin123.，100 条资源，点击统计已清零；保险箱主密码为 12345678，原保险箱条目已清除。网站文案和联系邮箱保留。') . $backupNote);
    } elseif ($action === 'reset') {
        flash(t('演示数据已重置：分类与链接已恢复为演示初始状态，管理员账号与站点文案已保存。') . $backupNote);
    } else {
        flash(t('已清空为空白：分类与链接已清空（ID 从 1 重新开始），管理员账号与站点文案已保存。') . $backupNote);
    }
    redirect('reset.php');
}

/* ------------------------------------------------------------------
 * GET：渲染页面（统计数据 + 弹窗预填）
 * ---------------------------------------------------------------- */
$form = $_SESSION['reset_form'] ?? null;
unset($_SESSION['reset_form']);

$prefillUser = (is_array($form) && (string)($form['username'] ?? '') !== '')
    ? (string)$form['username']
    : admin_current_username();

$prefillSite = [];
foreach ($siteKeys as $k) {
    $prefillSite[$k] = (is_array($form) && array_key_exists($k, (array)($form['site'] ?? [])))
        ? (string)$form['site'][$k]
        : raw_setting($k);
}

$pendingAction = (is_array($form) && in_array((string)($form['action'] ?? ''), ['reset', 'blank', 'restore_demo'], true))
    ? (string)$form['action']
    : '';
$autoOpen = is_array($form);

$siteDefaults = site_settings_defaults();
$siteFieldMeta = [
    'site_name'          => [t('站点名称'), t('显示在页面标题与后台品牌区')],
    'logo_text'          => [('Logo ' . t('文字')), t('顶栏 Logo 显示文字，可与站点名称不同')],
    'site_slogan'        => [t('副标题 / 欢迎语'), t('品牌区下方与主视觉区的一句话简介')],
    'search_placeholder' => [t('搜索框占位文字'), t('前台搜索框未输入时的提示文字')],
    'footer_text'        => [t('页脚 / 项目信息'), t('页面底部展示的项目说明')],
];

$linkCount   = (int)$db->query('SELECT COUNT(*) FROM links')->fetchColumn();
$catCount    = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$totalClicks = (int)$db->query('SELECT COALESCE(SUM(click_count),0) FROM links')->fetchColumn();

admin_header(t('重置演示'), 'reset');
?>
<div class="page-title-row">
    <h1 class="page-title"><?= e(t('重置演示 / 清空数据')) ?></h1>
    <p class="page-sub"><?= e(t('选择重置数据、清空数据或恢复完整 Demo；危险操作均需二次确认。')) ?></p>
</div>

<section class="panel glass warn-panel">
    <h2 class="panel-title"><?= ui_icon('warning') ?> <?= e(t('危险操作确认')) ?></h2>
    <p><?= e(t('当前数据库包含：')) ?><strong><?= $catCount ?></strong> <?= e(t('个分类、')) ?><strong><?= $linkCount ?></strong> <?= e(t('条链接，累计点击')) ?> <strong><?= number_format($totalClicks) ?></strong> <?= e(t('次。')) ?></p>
    <p class="danger-text"><?= e(t('所有恢复和清空操作均不可撤销')) ?>；<?= e(t('恢复演示会先备份数据库，并要求勾选知晓和输入确认短语。')) ?></p>

    <div class="reset-actions">
        <button type="button" class="btn-danger" data-open-reset="reset"><?= e(t('重置演示数据…')) ?></button>
        <button type="button" class="btn-ghost" data-open-reset="blank"><?= e(t('清空为空白…')) ?></button>
        <button type="button" class="btn-danger" data-open-reset="restore_demo"><?= e(t('恢复完整演示环境…')) ?></button>
    </div>
</section>

<section class="panel glass">
    <h2 class="panel-title"><?= e(t('操作内容与影响')) ?></h2>
    <ul class="reset-list">
        <li><strong><?= e(t('重置演示数据')) ?></strong>：<?= e(t('清空分类 / 链接与点击统计后，重新写入 4 个小组作业分类与 100 条演示链接。')) ?></li>
        <li><strong><?= e(t('清空为空白')) ?></strong>：<?= e(t('只清空分类、链接与自增序列（ID 从 1 重新开始），')) ?><strong><?= e(t('不写入')) ?></strong><?= e(t('任何演示数据，适合从零开始搭建自己的导航。')) ?></li>
        <li><?= e(t('重置演示和清空为空白会保存弹窗中的管理员账号与站点文案，再执行操作；任一步失败都会回滚。')) ?></li>
        <li><?= e(t('这两个操作会保留当前管理员账号和站点设置；恢复完整演示则会重建管理员账号并保留站点文案与邮箱。')) ?></li>
        <li><strong><?= e(t('恢复完整演示环境')) ?></strong>：<?= e(t('删除所有后台账号后重建唯一站长 admin / admin123.，重置为 4 个分类和 100 条链接、清零点击统计，并清除保险箱条目后设置主密码 12345678。网站文案和联系邮箱保留。其他成员需重新使用演示账号登录。')) ?></li>
        <li><?= e(t('执行前自动备份当前数据库；恢复演示操作仍会永久删除保险箱条目。')) ?></li>
    </ul>
</section>

<!-- 「必要数据设置」模态框 -->
<div class="modal-backdrop" id="resetModal" hidden>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle" aria-describedby="resetModalDesc">
        <form method="post" action="reset.php" class="modal-form" id="resetForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="resetAction" value="<?= e($pendingAction !== '' ? $pendingAction : 'reset') ?>">

            <header class="modal-head">
                <h2 class="modal-title" id="resetModalTitle"><?= e(t('必要数据设置')) ?></h2>
                <button type="button" class="modal-close" id="resetModalClose" aria-label="<?= e(t('关闭弹窗')) ?>">✕</button>
            </header>

            <div class="modal-body">
                <p class="modal-tip" id="resetModalDesc"><?= e(t('执行前请先确认必要数据：')) ?></p>

                <details class="modal-section reset-optional-settings">
                    <summary><?= e(t('可选：调整管理员账号与站点文案')) ?></summary>
                    <p class="field-hint"><?= e(t('默认保留当前账号和站点文案；只有需要修改时才展开。')) ?></p>
                <section class="modal-section reset-account-section">
                    <h3 class="modal-section-title"><?= e(t('管理员账号')) ?></h3>
                    <div class="modal-grid">
                        <div class="form-field span-2">
                            <label class="field-label" for="m-username"><?= e(t('用户名')) ?><span class="req">*</span></label>
                            <input class="form-input" type="text" id="m-username" name="username"
                                   value="<?= e($prefillUser) ?>" maxlength="32" autocomplete="username">
                            <div class="field-hint"><?= e(t('当前登录账号，可在此改名；修改后请使用新用户名登录。')) ?></div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="m-pwd"><?= e(t('新密码')) ?></label>
                            <input class="form-input" type="password" id="m-pwd" name="new_password"
                                   autocomplete="new-password" placeholder="<?= e(t('留空表示不修改')) ?>">
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="m-pwd2"><?= e(t('确认新密码')) ?></label>
                            <input class="form-input" type="password" id="m-pwd2" name="confirm_password"
                                   autocomplete="new-password" placeholder="<?= e(t('再次输入新密码')) ?>">
                        </div>
                        <div class="field-hint span-2"><?= e(t('账号以')) ?> <code>password_hash</code> <?= e(t('加密存储于')) ?> <code>admin_users</code> <?= e(t('表，可随时在此自助改密；留空则保持当前密码不变。')) ?></div>
                    </div>
                </section>

                <section class="modal-section reset-site-section">
                    <h3 class="modal-section-title"><?= e(t('站点文案')) ?></h3>
                    <div class="modal-grid">
                        <?php foreach ($siteFieldMeta as $key => [$label, $hint]): ?>
                        <div class="form-field">
                            <label class="field-label" for="m-<?= e($key) ?>"><?= e($label) ?></label>
                            <input class="form-input" type="text" id="m-<?= e($key) ?>" name="<?= e($key) ?>"
                                   value="<?= e($prefillSite[$key]) ?>" maxlength="200"
                                   placeholder="<?= e($siteDefaults[$key] ?? '') ?>">
                            <div class="field-hint"><?= e($hint) ?>；<?= e(t('留空回退默认')) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                </details>

                <section class="modal-section modal-danger">
                    <h3 class="modal-section-title"><?= e(t('危险确认')) ?></h3>
                    <label class="check-label" for="m-ack">
                        <input type="checkbox" name="ack" value="1" id="m-ack">
                        <span id="resetAckText"><?= e(t('我已了解：该操作不可恢复，分类 / 链接与点击统计将被清空。')) ?></span>
                    </label>
                    <div class="form-field">
                        <label class="field-label" for="m-confirm"><span id="confirmLabelText"><?= e(t('请输入「清空」以确认')) ?></span><span class="req">*</span></label>
                        <input class="form-input" type="text" id="m-confirm" name="confirm_text"
                               autocomplete="off" placeholder="清空">
                    </div>
                    <p class="field-error" id="resetClientError" hidden></p>
                </section>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn-ghost" id="resetCancel"><?= e(t('取消')) ?></button>
                <button type="submit" class="btn-danger" id="resetSubmit" disabled><?= e(t('确认重置演示数据')) ?></button>
            </footer>
        </form>
    </div>
</div>

<script<?= nonce_attr() ?>>
(function () {
    var backdrop = document.getElementById('resetModal');
    if (!backdrop) { return; }

    var dialog    = backdrop.querySelector('.modal-dialog');
    var form      = document.getElementById('resetForm');
    var actionEl  = document.getElementById('resetAction');
    var submitEl  = document.getElementById('resetSubmit');
    var hintEl    = document.getElementById('resetModalDesc');
    var errEl     = document.getElementById('resetClientError');
    var ackEl     = document.getElementById('m-ack');
    var confirmEl = document.getElementById('m-confirm');
    var userEl    = document.getElementById('m-username');
    var pwdEl     = document.getElementById('m-pwd');
    var pwd2El    = document.getElementById('m-pwd2');
    var confirmLabelEl = document.getElementById('confirmLabelText');
    var ackTextEl = document.getElementById('resetAckText');
    var modalTitleEl = document.getElementById('resetModalTitle');
    var accountSection = document.querySelector('.reset-account-section');
    var siteSection = document.querySelector('.reset-site-section');
    var optionalSettings = document.querySelector('.reset-optional-settings');
    var lastFocus = null;

    var ACTION_TEXT = {
        reset: (<?= jt('确认重置演示数据') ?>),
        blank: (<?= jt('确认清空为空白') ?>),
        restore_demo: (<?= jt('恢复完整演示环境') ?>)
    };
    var ACTION_HINT = {
        reset: (<?= jt('将清空分类 / 链接与点击统计，并重新写入演示数据（不可恢复）；管理员账号与站点文案会先保存。') ?>),
        blank: (<?= jt('将清空全部分类与链接（ID 从 1 重新开始），不写入演示数据（不可恢复）；管理员账号与站点文案会先保存。') ?>),
        restore_demo: (<?= jt('删除所有后台账号并重建 admin / admin123.，重置为 100 条演示链接并清零点击统计；清空密码保险箱全部条目并将主密码设为 12345678。网站文案与联系邮箱保留。') ?>)
    };
    var CONFIRM_PHRASES = { reset: (<?= jt('清空') ?>), blank: (<?= jt('清空') ?>), restore_demo: (<?= jt('恢复演示') ?>) };

    function setAction(action) {
        action = ACTION_TEXT[action] ? action : 'reset';
        actionEl.value = action;
        submitEl.textContent = ACTION_TEXT[action];
        if (hintEl) { hintEl.textContent = ACTION_HINT[action]; }
        var isRestore = action === 'restore_demo';
        if (accountSection) accountSection.hidden = isRestore;
        if (siteSection) siteSection.hidden = isRestore;
        if (optionalSettings) optionalSettings.hidden = isRestore;
        if (modalTitleEl) modalTitleEl.textContent = isRestore ? (<?= jt('恢复课堂演示环境') ?>) : (<?= jt('必要数据设置') ?>);
        if (confirmLabelEl) confirmLabelEl.textContent = isRestore ? (<?= jt('请输入「恢复演示」以确认') ?>) : (<?= jt('请输入「清空」以确认') ?>);
        if (ackTextEl) ackTextEl.textContent = isRestore ? (<?= jt('所有后台账号会被删除并恢复为 admin / admin123.，保险箱条目会永久删除，演示链接与统计会恢复初始状态。') ?>) : (<?= jt('我已了解：该操作不可恢复，分类 / 链接与点击统计将被清空。') ?>);
        confirmEl.placeholder = CONFIRM_PHRASES[action];
        updateSubmitState();
    }

    function updateSubmitState() {
        if (!submitEl || !ackEl || !confirmEl) return;
        submitEl.disabled = !ackEl.checked || confirmEl.value.trim() !== CONFIRM_PHRASES[actionEl.value];
    }

    function hideError() {
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        [ackEl, confirmEl, userEl, pwd2El].forEach(function (el) {
            if (el) { el.classList.remove('is-error'); }
        });
    }

    function showError(msg, el) {
        if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
        if (el) { el.classList.add('is-error'); el.focus(); }
    }

    function focusables() {
        return dialog.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, a[href]');
    }

    function openModal(action) {
        lastFocus = document.activeElement;
        setAction(action || 'reset');
        hideError();
        backdrop.hidden = false;
        document.body.classList.add('modal-open');
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { if (userEl) { userEl.focus(); } });
        }
    }

    function closeModal() {
        backdrop.hidden = true;
        document.body.classList.remove('modal-open');
        if (lastFocus && typeof lastFocus.focus === 'function') { lastFocus.focus(); }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-open-reset]'), function (btn) {
        btn.addEventListener('click', function () { openModal(btn.getAttribute('data-open-reset')); });
    });
    ackEl.addEventListener('change', updateSubmitState);
    confirmEl.addEventListener('input', updateSubmitState);
    document.getElementById('resetModalClose').addEventListener('click', closeModal);
    document.getElementById('resetCancel').addEventListener('click', closeModal);
    backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop) { closeModal(); } });

    document.addEventListener('keydown', function (e) {
        if (backdrop.hidden) { return; }
        if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); closeModal(); return; }
        if (e.key !== 'Tab') { return; }
        var list = focusables();
        if (!list.length) { return; }
        var first = list[0], last = list[list.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    form.addEventListener('submit', function (e) {
        hideError();
        if (userEl && userEl.value.trim() === '') {
            e.preventDefault(); showError((<?= jt('管理员用户名不能为空。') ?>), userEl); return;
        }
        if (pwdEl.value !== '' || pwd2El.value !== '') {
            if (pwdEl.value !== pwd2El.value) {
                e.preventDefault(); showError((<?= jt('两次输入的新密码不一致，请重新输入。') ?>), pwd2El); return;
            }
            if (pwdEl.value.length < <?= ADMIN_PASSWORD_MIN_LENGTH ?>) {
                e.preventDefault(); showError((<?= jt('新密码长度至少 8 位。') ?>), pwdEl); return;
            }
        }
        if (!ackEl.checked) {
            e.preventDefault(); showError((<?= jt('请先勾选「我已了解该操作不可恢复」。') ?>), ackEl); return;
        }
        if (confirmEl.value.trim() !== CONFIRM_PHRASES[actionEl.value]) {
            e.preventDefault(); showError((<?= jt('确认短语不正确，请按提示重新输入。') ?>), confirmEl); return;
        }
    });

<?php if ($autoOpen): ?>
    openModal(<?= json_encode($pendingAction !== '' ? $pendingAction : 'reset') ?>);
<?php endif; ?>
})();
</script>
<?php admin_footer(); ?>
