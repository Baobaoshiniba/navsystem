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
require_login();

$db       = db();
$siteKeys = array_keys(site_settings_defaults());

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

    $userLen = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);
    $pwdLen  = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);

    $errors = [];
    if (!in_array($action, ['reset', 'blank'], true)) {
        $errors[] = '未知操作，请重新选择要执行的动作。';
    }
    if ($username === '') {
        $errors[] = '管理员用户名不能为空。';
    } elseif ($userLen > 32) {
        $errors[] = '管理员用户名最长 32 个字符。';
    }
    if ($password !== '' || $password2 !== '') {
        if ($password !== $password2) {
            $errors[] = '两次输入的新密码不一致，请重新输入。';
        } elseif ($pwdLen < 6) {
            $errors[] = '新密码长度至少 6 位。';
        }
    }
    if (!$ack) {
        $errors[] = '请先勾选「我已了解该操作不可恢复」。';
    }
    if ($confirmText !== '清空') {
        $errors[] = '请在确认框中输入「清空」字样后再提交。';
    }

    if ($errors) {
        $rememberForm(is_string($action) ? $action : '', $username, $siteValues);
        flash(implode(' ', $errors), 'error');
        redirect('reset.php');
    }

    try {
        $db->beginTransaction();

        // 1) 必要数据：管理员账号入库（password_hash 存储；密码留空表示保持原密码不变）
        admin_save_account($username, $password);

        // 2) 必要数据：站点 5 项文案（留空则回退系统默认文案）
        $upsertSetting = $db->prepare(
            "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
        );
        foreach ($siteValues as $k => $v) {
            $upsertSetting->execute([$k, $v]);
        }

        // 3) 清空分类 / 链接及自增序列（ID 从 1 重新开始）；管理员账号与站点设置保留
        $db->exec('DELETE FROM links;');
        $db->exec('DELETE FROM categories;');
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('links', 'categories');");

        // 4) 「重置演示数据」额外写入演示分类与链接；「清空为空白」保持空库
        if ($action === 'reset') {
            seed_demo_data($db);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $rememberForm(is_string($action) ? $action : '', $username, $siteValues);
        flash('操作失败，数据未发生任何变更：' . $e->getMessage(), 'error');
        redirect('reset.php');
    }

    // 账号可能已改名，同步会话中的用户名
    $_SESSION['admin_username'] = $username;

    if ($action === 'reset') {
        flash('演示数据已重置：分类与链接已恢复为演示初始状态，管理员账号与站点文案已保存。');
    } else {
        flash('已清空为空白：分类与链接已清空（ID 从 1 重新开始），管理员账号与站点文案已保存。');
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
        : setting($k);
}

$pendingAction = (is_array($form) && in_array((string)($form['action'] ?? ''), ['reset', 'blank'], true))
    ? (string)$form['action']
    : '';
$autoOpen = is_array($form);

$siteDefaults = site_settings_defaults();
$siteFieldMeta = [
    'site_name'          => ['公司名称', '显示在页面标题与后台品牌区'],
    'logo_text'          => ['Logo 文字', '顶栏 Logo 显示文字，可与公司名称不同'],
    'site_slogan'        => ['副标题 / 欢迎语', '品牌区下方与主视觉区的一句话简介'],
    'search_placeholder' => ['搜索框占位文字', '前台搜索框未输入时的提示文字'],
    'footer_text'        => ['页脚版权 / 公司信息', '页面底部展示的版权与公司信息'],
];

$linkCount   = (int)$db->query('SELECT COUNT(*) FROM links')->fetchColumn();
$catCount    = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$totalClicks = (int)$db->query('SELECT COALESCE(SUM(click_count),0) FROM links')->fetchColumn();

admin_header('重置演示', 'reset');
?>
<div class="page-title-row">
    <h1 class="page-title">重置演示 / 清空数据</h1>
    <p class="page-sub">执行前先确认必要数据（管理员账号与站点文案），再选择要执行的动作。</p>
</div>

<section class="panel glass warn-panel">
    <h2 class="panel-title">⚠️ 危险操作确认</h2>
    <p>当前数据库包含：<strong><?= $catCount ?></strong> 个分类、<strong><?= $linkCount ?></strong> 条链接，累计点击 <strong><?= number_format($totalClicks) ?></strong> 次。</p>
    <p class="danger-text">两个动作均<strong>不可恢复</strong>，点击后需在弹窗中填写必要数据并完成二次确认（勾选知晓 + 输入「清空」）才会真正执行。</p>

    <div class="reset-actions">
        <button type="button" class="btn-danger" data-open-reset="reset">重置演示数据…</button>
        <button type="button" class="btn-ghost" data-open-reset="blank">清空为空白…</button>
    </div>
</section>

<section class="panel glass">
    <h2 class="panel-title">两个动作的区别</h2>
    <ul class="reset-list">
        <li><strong>重置演示数据</strong>：清空分类 / 链接与点击统计后，重新写入 5 个默认分类与 19 条示例链接，恢复为首次安装的演示状态。</li>
        <li><strong>清空为空白</strong>：只清空分类、链接与自增序列（ID 从 1 重新开始），<strong>不写入</strong>任何演示数据，适合从零开始搭建自己的导航。</li>
        <li>两个动作都会先保存弹窗中的<strong>管理员账号</strong>（用户名 / 新密码，入库加密存储）与<strong>站点 5 项文案</strong>，再执行清空，全流程同一事务，任一步失败自动回滚。</li>
        <li>管理员账号与站点设置不在清空范围内；弹窗中留空的文案字段会回退为系统默认文案。</li>
    </ul>
</section>

<!-- 「必要数据设置」模态框 -->
<div class="modal-backdrop" id="resetModal" hidden>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="resetModalTitle" aria-describedby="resetModalDesc">
        <form method="post" action="reset.php" class="modal-form" id="resetForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="resetAction" value="<?= e($pendingAction !== '' ? $pendingAction : 'reset') ?>">

            <header class="modal-head">
                <h2 class="modal-title" id="resetModalTitle">必要数据设置</h2>
                <button type="button" class="modal-close" id="resetModalClose" aria-label="关闭弹窗">✕</button>
            </header>

            <div class="modal-body">
                <p class="modal-tip" id="resetModalDesc">执行前请先确认必要数据：</p>

                <section class="modal-section">
                    <h3 class="modal-section-title">管理员账号</h3>
                    <div class="modal-grid">
                        <div class="form-field span-2">
                            <label class="field-label" for="m-username">用户名<span class="req">*</span></label>
                            <input class="form-input" type="text" id="m-username" name="username"
                                   value="<?= e($prefillUser) ?>" maxlength="32" autocomplete="username">
                            <div class="field-hint">当前登录账号，可在此改名；修改后请使用新用户名登录。</div>
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="m-pwd">新密码</label>
                            <input class="form-input" type="password" id="m-pwd" name="new_password"
                                   autocomplete="new-password" placeholder="留空表示不修改">
                        </div>
                        <div class="form-field">
                            <label class="field-label" for="m-pwd2">确认新密码</label>
                            <input class="form-input" type="password" id="m-pwd2" name="confirm_password"
                                   autocomplete="new-password" placeholder="再次输入新密码">
                        </div>
                        <div class="field-hint span-2">账号以 <code>password_hash</code> 加密存储于 <code>admin_users</code> 表，可随时在此自助改密；留空则保持当前密码不变。</div>
                    </div>
                </section>

                <section class="modal-section">
                    <h3 class="modal-section-title">站点文案</h3>
                    <div class="modal-grid">
                        <?php foreach ($siteFieldMeta as $key => [$label, $hint]): ?>
                        <div class="form-field">
                            <label class="field-label" for="m-<?= e($key) ?>"><?= e($label) ?></label>
                            <input class="form-input" type="text" id="m-<?= e($key) ?>" name="<?= e($key) ?>"
                                   value="<?= e($prefillSite[$key]) ?>" maxlength="200"
                                   placeholder="<?= e($siteDefaults[$key] ?? '') ?>">
                            <div class="field-hint"><?= e($hint) ?>；留空回退默认</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="modal-section modal-danger">
                    <h3 class="modal-section-title">危险确认</h3>
                    <label class="check-label" for="m-ack">
                        <input type="checkbox" name="ack" value="1" id="m-ack">
                        <span>我已了解：该操作不可恢复，分类 / 链接与点击统计将被清空。</span>
                    </label>
                    <div class="form-field">
                        <label class="field-label" for="m-confirm">请输入「清空」以确认<span class="req">*</span></label>
                        <input class="form-input" type="text" id="m-confirm" name="confirm_text"
                               autocomplete="off" placeholder="清空">
                    </div>
                    <p class="field-error" id="resetClientError" hidden></p>
                </section>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn-ghost" id="resetCancel">取消</button>
                <button type="submit" class="btn-danger" id="resetSubmit">确认重置演示数据</button>
            </footer>
        </form>
    </div>
</div>

<script>
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
    var lastFocus = null;

    var ACTION_TEXT = {
        reset: '确认重置演示数据',
        blank: '确认清空为空白'
    };
    var ACTION_HINT = {
        reset: '将清空分类 / 链接与点击统计，并重新写入演示数据（不可恢复）；管理员账号与站点文案会先保存。',
        blank: '将清空全部分类与链接（ID 从 1 重新开始），不写入演示数据（不可恢复）；管理员账号与站点文案会先保存。'
    };

    function setAction(action) {
        action = ACTION_TEXT[action] ? action : 'reset';
        actionEl.value = action;
        submitEl.textContent = ACTION_TEXT[action];
        if (hintEl) { hintEl.textContent = ACTION_HINT[action]; }
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
            e.preventDefault(); showError('管理员用户名不能为空。', userEl); return;
        }
        if (pwdEl.value !== '' || pwd2El.value !== '') {
            if (pwdEl.value !== pwd2El.value) {
                e.preventDefault(); showError('两次输入的新密码不一致，请重新输入。', pwd2El); return;
            }
            if (pwdEl.value.length < 6) {
                e.preventDefault(); showError('新密码长度至少 6 位。', pwdEl); return;
            }
        }
        if (!ackEl.checked) {
            e.preventDefault(); showError('请先勾选「我已了解该操作不可恢复」。', ackEl); return;
        }
        if (confirmEl.value.trim() !== '清空') {
            e.preventDefault(); showError('请在确认框中输入「清空」字样后再提交。', confirmEl); return;
        }
    });

<?php if ($autoOpen): ?>
    openModal(<?= json_encode($pendingAction !== '' ? $pendingAction : 'reset') ?>);
<?php endif; ?>
})();
</script>
<?php admin_footer(); ?>
