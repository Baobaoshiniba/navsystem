<?php
/**
 * 后台 - 密码保险箱
 *
 * 主密码解锁模式：
 *  - 首次进入设置主密码（≥8 位 + 二次确认），主密码只存 Argon2id 校验哈希；
 *  - 加密密钥由主密码经 PBKDF2-HMAC-SHA256（高迭代）+ 独立盐派生 32 字节，仅存会话，绝不落盘；
 *  - 解锁后 15 分钟无操作自动锁定（服务端判定 + 前端倒计时），锁定后需重新输入主密码；
 *  - 条目密码 AES-256-GCM 加密入库（每条独立随机 IV），页面默认打码，明文仅「显示 / 复制」时按需返回；
 *  - 忘记主密码只能「重置保险箱」清空全部条目（不可恢复）。
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require __DIR__ . '/_vault.php';

require_owner();
secure_session_start();

$db = db();

/** 组装列表页地址（保留搜索 / 分类 / 页码上下文） */
function vault_list_url(string $q = '', string $cat = '', int $page = 1, array $override = []): string
{
    $params = ['q' => $q, 'cat' => $cat, 'page' => $page > 1 ? (string)$page : ''];
    foreach ($override as $k => $v) {
        $params[$k] = (string)$v;
    }
    $params = array_filter($params, static fn($v): bool => $v !== '');
    return 'vault.php' . ($params ? '?' . http_build_query($params) : '');
}

$key = '';   // 当前会话密钥（仅解锁后非空）

if (is_post()) {
    guard_csrf();
    $action = post('action');

    // 每次成功请求刷新活动时间（超时者不会被刷新，见 vault_touch / vault_is_unlocked）
    vault_touch();

    $retUrl = vault_list_url(post('ret_q'), post('ret_cat'), max(1, (int)post('ret_page', '1')));

    switch ($action) {
        /* ---------------- 首次设置主密码 ---------------- */
        case 'setup_master':
            if (vault_master_is_set()) {
                flash(t('主密码已设置，请直接输入主密码解锁。'), 'error');
                redirect('vault.php');
            }
            $p1 = (string)($_POST['master_password'] ?? '');
            $p2 = (string)($_POST['master_password2'] ?? '');
            $errs = [];
            if (mb_strlen($p1) < VAULT_MIN_MASTER_LEN) {
                $errs[] = (t('主密码至少') . ' ') . VAULT_MIN_MASTER_LEN . (' ' . t('位。'));
            }
            if (mb_strlen($p1) > 200) {
                $errs[] = t('主密码过长（上限 200 位）。');
            }
            if (!hash_equals($p1, $p2)) {
                $errs[] = t('两次输入的主密码不一致。');
            }
            if ($errs) {
                flash(implode(' ', $errs), 'error');
                redirect('vault.php');
            }
            vault_setup_master($p1);
            flash(t('主密码设置成功，保险箱已解锁。请务必牢记主密码：忘记后只能重置保险箱，全部条目将无法恢复。'));
            redirect('vault.php');
            break;

        /* ---------------- 解锁 ---------------- */
        case 'unlock':
            if (!vault_master_is_set()) {
                flash(t('尚未设置主密码，请先设置。'), 'error');
                redirect('vault.php');
            }
            $master = (string)($_POST['master_password'] ?? '');
            if ($master === '') {
                flash(t('请输入主密码。'), 'error');
                redirect('vault.php');
            }
            if (!vault_unlock($master)) {
                flash(t('主密码不正确，请重新输入。'), 'error');
                redirect('vault.php');
            }
            flash(t('保险箱已解锁，') . (VAULT_IDLE_TIMEOUT / 60) . (' ' . t('分钟无操作将自动锁定。')));
            redirect('vault.php');
            break;

        /* ---------------- 立即锁定 ---------------- */
        case 'lock':
            vault_lock();
            flash(t('保险箱已锁定，需重新输入主密码解锁。'));
            redirect('vault.php');
            break;

        /* ---------------- 重置保险箱（忘记主密码时唯一出口，无需解锁） ---------------- */
        case 'reset_vault':
            $ack  = (string)($_POST['ack'] ?? '');
            $text = trim((string)($_POST['confirm_text'] ?? ''));
            $errs = [];
            if ($ack !== '1') {
                $errs[] = t('请先勾选「我已了解该操作不可恢复」。');
            }
            if ($text !== VAULT_RESET_PHRASE) {
                $errs[] = t('请输入「') . VAULT_RESET_PHRASE . ('」' . t('以确认。'));
            }
            if ($errs) {
                flash(implode(' ', $errs), 'error');
                redirect('vault.php');
            }
            $cleared = vault_stats()['total'];
            vault_reset_all();
            flash((t('保险箱已重置：已清空') . ' ') . $cleared . (' ' . t('条记录，主密码与加密密钥一并清除，原数据不可恢复。请重新设置主密码。')));
            redirect('vault.php');
            break;

        /* ---------------- 新增 / 编辑条目（需解锁） ---------------- */
        case 'save':
            if (!vault_is_unlocked()) {
                flash(t('保险箱已锁定（') . (VAULT_IDLE_TIMEOUT / 60) . (' ' . t('分钟无操作），请重新输入主密码后重试。')), 'error');
                redirect($retUrl);
            }
            $key = vault_session_key();

            $id       = (int)post('id', '0');
            $title    = post('title');
            $urlRaw   = post('url');
            $username = post('username');
            $password = (string)($_POST['password'] ?? '');   // 密码不 trim：首尾空格可能是密码的一部分
            $notes    = post('notes');
            $category = post('category');

            $errs = [];
            if ($title === '') {
                $errs[] = t('标题不能为空。');
            } elseif (mb_strlen($title) > 80) {
                $errs[] = t('标题过长（上限 80 字）。');
            }
            $safeUrl = vault_safe_url($urlRaw);
            if ($urlRaw === '') {
                $errs[] = t('网址不能为空。');
            } elseif ($safeUrl === '') {
                $errs[] = t('网址格式不正确（仅支持 http / https）。');
            }
            if (mb_strlen($username) > 120) {
                $errs[] = t('账号过长（上限 120 字）。');
            }
            if (mb_strlen($password) > 200) {
                $errs[] = t('密码过长（上限 200 位）。');
            }
            if (mb_strlen($category) > 40) {
                $errs[] = t('分类过长（上限 40 字）。');
            }
            if (mb_strlen($notes) > 500) {
                $errs[] = t('备注过长（上限 500 字）。');
            }
            if ($errs) {
                flash(implode(' ', $errs), 'error');
                redirect($retUrl);
            }

            $data = [
                'title'    => $title,
                'url'      => $safeUrl,
                'username' => $username,
                'password' => $password,
                'notes'    => $notes,
                'category' => $category,
            ];

            if ($id > 0) {
                if (!vault_update($id, $data, $key)) {
                    flash(t('要编辑的条目不存在，可能已被删除。'), 'error');
                } else {
                    flash(t('条目「') . $title . ('」' . t('已更新。')));
                }
            } else {
                vault_create($data, $key);
                flash(t('条目「') . $title . ('」' . t('已加入保险箱。')));
            }
            redirect(vault_list_url(post('ret_q'), post('ret_cat'), max(1, (int)post('ret_page', '1'))));
            break;

        /* ---------------- 删除条目（需解锁） ---------------- */
        case 'delete':
            if (!vault_is_unlocked()) {
                flash(t('保险箱已锁定（') . (VAULT_IDLE_TIMEOUT / 60) . (' ' . t('分钟无操作），请重新输入主密码后重试。')), 'error');
                redirect($retUrl);
            }
            $id = (int)post('id', '0');
            if ($id > 0 && vault_delete($id)) {
                flash(t('条目已删除。'));
            } else {
                flash(t('要删除的条目不存在。'), 'error');
            }
            redirect($retUrl);
            break;
    }

    // 未匹配任何动作：回列表页
    redirect('vault.php');
}

// GET：刷新活动时间（已锁定或超时则不受影响）
vault_touch();

$masterSet = vault_master_is_set();
$unlocked  = $masterSet && vault_is_unlocked();
if ($unlocked) {
    $key = vault_session_key();
}

$q       = trim((string)($_GET['q'] ?? ''));
$filterCat = trim((string)($_GET['cat'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$editId  = max(0, (int)($_GET['edit'] ?? 0));

$list     = ['total' => 0, 'page' => 1, 'pages' => 1, 'rows' => []];
$stats    = ['total' => 0, 'with_login' => 0, 'categories' => 0];
$cats     = [];
$editing  = null;

if ($unlocked) {
    $list  = vault_items($q, $filterCat, $page, 10);
    $stats = vault_stats();
    $cats  = vault_category_list();
    if ($editId > 0) {
        $editing = vault_get($editId);
    }
}

admin_header(t('密码保险箱'), 'vault');
?>
<div class="page-title-row">
    <h1 class="page-title"><?= e(t('密码保险箱')) ?></h1>
    <?php if ($unlocked): ?>
        <p class="page-sub"><?= e(t('共')) ?> <?= $stats['total'] ?> <?= e(t('条记录 ·')) ?> <?= $stats['categories'] ?> <?= e(t('个分类；密码以 AES-256-GCM 加密存储，仅在本机解锁后可读。')) ?></p>
    <?php else: ?>
        <p class="page-sub"><?= e(t('存放网页链接与对应账号密码：主密码解锁 + AES-256-GCM 加密入库，密钥仅存于当前会话。')) ?></p>
    <?php endif; ?>

    <?php if ($unlocked): ?>
        <div class="vault-session">
            <span class="vault-timer" id="vaultTimer" data-remaining="<?= vault_remaining_seconds() ?>" data-timeout="<?= VAULT_IDLE_TIMEOUT ?>" role="status"><?= ui_icon('lock') ?> <?= e(t('已解锁')) ?></span>
            <form method="post" action="vault.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="lock">
                <button type="submit" class="btn-ghost btn-sm"><?= e(t('立即锁定')) ?></button>
            </form>
            <button type="button" class="btn-ghost btn-sm" id="vaultResetOpen"><?= e(t('重置保险箱…')) ?></button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$masterSet): /* ============ 一、首次设置主密码 ============ */ ?>

    <section class="panel glass vault-gate">
        <div class="vault-gate-ico" aria-hidden="true"><?= ui_icon('lock') ?></div>
        <h2 class="vault-gate-title"><?= e(t('首次使用：设置主密码')) ?></h2>
        <p class="vault-gate-desc"><?= e(t('主密码是保险箱唯一的钥匙，用于派生加密密钥。它本身只以 Argon2id 校验哈希保存，不存明文，也不写入任何文件。')) ?></p>
        <ul class="vault-gate-points">
            <li><?= e(t('至少')) ?> <?= VAULT_MIN_MASTER_LEN ?> <?= e(t('位，建议 12 位以上并混合大小写、数字与符号；')) ?></li>
            <li><?= e(t('加密密钥由主密码派生后仅保存在当前会话，')) ?><?= (int)(VAULT_IDLE_TIMEOUT / 60) ?> <?= e(t('分钟无操作自动锁定；')) ?></li>
            <li><span class="danger-text"><?= e(t('忘记主密码无法找回')) ?></span>：<?= e(t('数据严格加密，只能「重置保险箱」清空全部条目。')) ?></li>
        </ul>

        <form method="post" action="vault.php" class="form-stack vault-gate-form" id="vaultSetupForm" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="setup_master">

            <div class="form-field">
                <label class="field-label" for="vaultMaster1"><?= e(t('主密码')) ?><span class="req">*</span></label>
                <div class="vault-input-group">
                    <input class="form-input" type="password" id="vaultMaster1" name="master_password"
                           minlength="<?= VAULT_MIN_MASTER_LEN ?>" maxlength="200" required
                           autocomplete="new-password" placeholder="<?= e(t('至少')) ?> <?= VAULT_MIN_MASTER_LEN ?> <?= e(t('位')) ?>">
                    <button type="button" class="mini-btn" data-vault-toggle="vaultMaster1" aria-pressed="false"><?= ui_icon('eye') ?><span data-toggle-label><?= e(t('显示')) ?></span></button>
                </div>
                <div class="vault-strength" data-vault-strength="vaultMaster1" data-level="0">
                    <div class="vs-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <span class="vs-text"><?= e(t('强度：—')) ?></span>
                </div>
            </div>

            <div class="form-field">
                <label class="field-label" for="vaultMaster2"><?= e(t('确认主密码')) ?><span class="req">*</span></label>
                <div class="vault-input-group">
                    <input class="form-input" type="password" id="vaultMaster2" name="master_password2"
                           minlength="<?= VAULT_MIN_MASTER_LEN ?>" maxlength="200" required
                           autocomplete="new-password" placeholder="<?= e(t('再次输入主密码')) ?>">
                    <button type="button" class="mini-btn" data-vault-toggle="vaultMaster2" aria-pressed="false"><?= ui_icon('eye') ?><span data-toggle-label><?= e(t('显示')) ?></span></button>
                </div>
                <p class="field-hint"><?= e(t('两次输入需完全一致；主密码不会以任何形式落盘。')) ?></p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= e(t('设置主密码并进入保险箱')) ?></button>
            </div>
        </form>
    </section>

<?php elseif (!$unlocked): /* ============ 二、已设置但未解锁 ============ */ ?>

    <section class="panel glass vault-gate">
        <div class="vault-gate-ico" aria-hidden="true"><?= ui_icon('lock') ?></div>
        <h2 class="vault-gate-title"><?= e(t('保险箱已锁定')) ?></h2>
        <p class="vault-gate-desc"><?= e(t('请输入主密码解锁。解锁后')) ?> <?= (int)(VAULT_IDLE_TIMEOUT / 60) ?> <?= e(t('分钟无操作会自动锁定，也可随时点击「立即锁定」。')) ?></p>

        <form method="post" action="vault.php" class="form-stack vault-gate-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unlock">

            <div class="form-field">
                <label class="field-label" for="vaultMasterUnlock"><?= e(t('主密码')) ?><span class="req">*</span></label>
                <div class="vault-input-group">
                    <input class="form-input" type="password" id="vaultMasterUnlock" name="master_password"
                           maxlength="200" required autocomplete="current-password" placeholder="<?= e(t('输入主密码')) ?>">
                    <button type="button" class="mini-btn" data-vault-toggle="vaultMasterUnlock" aria-pressed="false"><?= ui_icon('eye') ?><span data-toggle-label><?= e(t('显示')) ?></span></button>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= e(t('解锁保险箱')) ?></button>
            </div>
        </form>

        <div class="vault-gate-foot">
            <p class="field-hint"><?= e(t('忘记主密码？数据无法解密找回，只能重置保险箱（清空全部条目，不可恢复）。')) ?></p>
            <button type="button" class="btn-ghost btn-sm" id="vaultResetOpen"><?= e(t('重置保险箱…')) ?></button>
        </div>
    </section>

<?php else: /* ============ 三、已解锁主界面 ============ */ ?>

    <div class="mini-stats">
        <div class="mini-stat"><span class="ms-ico"><?= ui_icon('folder') ?></span><div><div class="ms-num"><?= $stats['total'] ?></div><div class="ms-label"><?= e(t('保险箱条目')) ?></div></div></div>
        <div class="mini-stat"><span class="ms-ico"><?= ui_icon('users') ?></span><div><div class="ms-num"><?= $stats['with_login'] ?></div><div class="ms-label"><?= e(t('含账号条目')) ?></div></div></div>
        <div class="mini-stat"><span class="ms-ico"><?= ui_icon('folder') ?></span><div><div class="ms-num"><?= $stats['categories'] ?></div><div class="ms-label"><?= e(t('已用分类')) ?></div></div></div>
    </div>

    <div class="two-col two-col-form vault-layout">
        <!-- 左栏：新增 / 编辑表单 -->
        <section class="panel glass">
            <h2 class="panel-title"><?= $editing ? ('' . ui_icon('edit') . ' ' . t('编辑条目')) : ('＋ ' . t('新增条目')) ?></h2>

            <form method="post" action="vault.php" class="form-stack" id="vaultItemForm" autocomplete="off"
                  data-unlocked="<?= $unlocked ? '1' : '0' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
                <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                <input type="hidden" name="ret_cat" value="<?= e($filterCat) ?>">
                <input type="hidden" name="ret_page" value="<?= (int)$list['page'] ?>">

                <div class="form-field">
                    <label class="field-label" for="v-title"><?= e(t('标题')) ?><span class="req">*</span></label>
                    <input class="form-input" type="text" id="v-title" name="title" maxlength="80" required
                           placeholder="<?= e(t('如：公司邮箱 / 阿里云控制台')) ?>" value="<?= e($editing['title'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label class="field-label" for="v-url"><?= e(t('网址')) ?><span class="req">*</span></label>
                    <input class="form-input" type="text" id="v-url" name="url" maxlength="500" required
                           placeholder="https://example.com/login" value="<?= e($editing['url'] ?? '') ?>">
                    <p class="field-hint"><?= e(t('缺省协议将自动补 https')) ?>://，仅允许 http / https。</p>
                </div>

                <div class="form-field">
                    <label class="field-label" for="v-username"><?= e(t('账号')) ?></label>
                    <div class="vault-input-group">
                        <input class="form-input" type="text" id="v-username" name="username" maxlength="120"
                               autocomplete="off" placeholder="<?= e(t('登录账号 / 邮箱')) ?>" value="<?= e($editing['username'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <label class="field-label" for="v-password"><?= e(t('密码')) ?></label>
                    <div class="vault-input-group">
                        <input class="form-input" type="password" id="v-password" name="password" maxlength="200"
                               autocomplete="new-password" placeholder="<?= $editing ? t('留空表示不修改已存密码') : t('留空表示暂不保存密码') ?>">
                        <button type="button" class="mini-btn" data-vault-toggle="v-password" aria-pressed="false"><?= ui_icon('eye') ?><span data-toggle-label><?= e(t('显示')) ?></span></button>
                        <button type="button" class="mini-btn" id="vaultGenPwd" data-vault-target="v-password">🎲 <?= e(t('生成')) ?></button>
                    </div>
                    <div class="vault-strength" data-vault-strength="v-password" data-level="0">
                        <div class="vs-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                        <span class="vs-text"><?= e(t('强度：—')) ?></span>
                    </div>
                </div>

                <div class="form-field">
                    <label class="field-label" for="v-category"><?= e(t('分类')) ?></label>
                    <input class="form-input" type="text" id="v-category" name="category" maxlength="40" list="vaultCats"
                           placeholder="<?= e(t('如：运维 / 第三方服务')) ?>" value="<?= e($editing['category'] ?? '') ?>">
                    <datalist id="vaultCats">
                        <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-field">
                    <label class="field-label" for="v-notes"><?= e(t('备注')) ?></label>
                    <textarea class="form-input" id="v-notes" name="notes" rows="3" maxlength="500"
                              placeholder="<?= e(t('密保问题 / 使用范围 / 到期提醒等')) ?>"><?= e($editing['notes'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary"><?= $editing ? t('保存修改') : t('加入保险箱') ?></button>
                    <?php if ($editing): ?>
                        <a class="btn-ghost" href="<?= e(vault_list_url($q, $filterCat, (int)$list['page'])) ?>"><?= e(t('取消编辑')) ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <!-- 右栏：列表 -->
        <section class="panel glass">
            <div class="vault-list-head">
                <h2 class="panel-title"><?= ui_icon('key') ?> <?= e(t('条目列表')) ?><?= $list['total'] > 0 ? ('（' . t('共') . ' ') . $list['total'] . (' ' . t('条）')) : '' ?></h2>
                <form method="get" action="vault.php" class="vault-search" role="search">
                    <input class="form-input" type="search" name="q" value="<?= e($q) ?>"
                           placeholder="<?= e(t('搜索标题 / 网址 / 账号 / 备注 / 分类')) ?>" aria-label="<?= e(t('搜索保险箱条目')) ?>">
                    <select class="form-input" name="cat" aria-label="<?= e(t('按分类筛选')) ?>">
                        <option value=""><?= e(t('全部分类')) ?></option>
                        <?php foreach ($cats as $c): ?>
                            <option value="<?= e($c) ?>" <?= $c === $filterCat ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary btn-sm"><?= e(t('搜索')) ?></button>
                    <?php if ($q !== '' || $filterCat !== ''): ?>
                        <a class="btn-ghost btn-sm" href="vault.php">清空</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!$list['rows']): ?>
                <div class="empty-tip">
                    <span class="et-ico"><?= ui_icon('folder') ?></span>
                    <p><strong><?= ($q !== '' || $filterCat !== '') ? t('没有匹配的条目') : t('保险箱还是空的') ?></strong></p>
                    <p><?= ($q !== '' || $filterCat !== '') ? t('换个关键字或清空筛选再试试。') : t('在左侧填写标题与网址，即可把账号密码加密存入。') ?></p>
                </div>
            <?php else: ?>
                <div class="table-wrap vault-table-wrap">
                    <table class="data-table vault-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= e(t('标题')) ?></th>
                                <th scope="col"><?= e(t('网址')) ?></th>
                                <th scope="col"><?= e(t('账号')) ?></th>
                                <th scope="col"><?= e(t('密码')) ?></th>
                                <th scope="col"><?= e(t('分类')) ?></th>
                                <th scope="col"><?= e(t('更新时间')) ?></th>
                                <th scope="col" class="th-actions"><?= e(t('操作')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($list['rows'] as $row): $rid = (int)$row['id']; ?>
                                <tr data-vault-row="<?= $rid ?>">
                                    <td class="cell-name" data-label="<?= e(t('标题')) ?>" title="<?= e($row['title']) ?>">
                                        <?= e($row['title']) ?>
                                        <?php if (trim((string)$row['notes']) !== ''): ?>
                                            <span class="vault-note-dot" title="<?= e(t('含备注')) ?>">•</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-url" data-label="<?= e(t('网址')) ?>">
                                        <?php $safeUrl = vault_safe_url((string)$row['url']); ?>
                                        <?php if ($safeUrl !== ''): ?>
                                            <a href="<?= e($safeUrl) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($row['url']) ?>"><?= e($row['url']) ?></a>
                                        <?php else: ?>
                                            <span class="muted" title="<?= e($row['url']) ?>"><?= e($row['url']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="vault-cell-secret" data-label="<?= e(t('账号')) ?>">
                                        <?php if ((string)$row['username'] === ''): ?>
                                            <span class="muted">—</span>
                                        <?php else: ?>
                                            <span class="secret-line">
                                                <span class="vault-mask" data-vault-mask="<?= $rid ?>-username">••••••••</span>
                                                <span class="vault-cell-actions">
                                                    <button type="button" class="mini-btn" data-vault-act="show" data-vault-id="<?= $rid ?>" data-vault-field="username"><?= e(t('显示')) ?></button>
                                                    <button type="button" class="mini-btn" data-vault-act="copy" data-vault-id="<?= $rid ?>" data-vault-field="username"><?= e(t('复制')) ?></button>
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="vault-cell-secret" data-label="<?= e(t('密码')) ?>">
                                        <?php if ((string)$row['password_enc'] === ''): ?>
                                            <span class="muted">—</span>
                                        <?php else: ?>
                                            <span class="secret-line">
                                                <span class="vault-mask" data-vault-mask="<?= $rid ?>-password">••••••••</span>
                                                <span class="vault-cell-actions">
                                                    <button type="button" class="mini-btn" data-vault-act="show" data-vault-id="<?= $rid ?>" data-vault-field="password"><?= e(t('显示')) ?></button>
                                                    <button type="button" class="mini-btn" data-vault-act="copy" data-vault-id="<?= $rid ?>" data-vault-field="password"><?= e(t('复制')) ?></button>
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(t('分类')) ?>">
                                        <?php if ((string)$row['category'] !== ''): ?>
                                            <span class="cat-chip gray" title="<?= e($row['category']) ?>"><?= e($row['category']) ?></span>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-time" data-label="<?= e(t('更新时间')) ?>" title="<?= e((string)$row['updated_at']) ?>"><?= e((string)$row['updated_at']) ?></td>
                                    <td class="cell-actions" data-label="<?= e(t('操作')) ?>">
                                        <div class="row-actions">
                                            <a class="mini-btn" href="<?= e(vault_list_url($q, $filterCat, (int)$list['page'], ['edit' => $rid])) ?>" title="<?= e(t('编辑条目')) ?>" aria-label="<?= e(t('编辑条目：')) ?><?= e($row['title']) ?>"><?php /* 图标按钮：无障碍标签见 aria-label */ ?><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></a>
                                            <form method="post" action="vault.php" class="inline-form"
                                                  data-confirm="<?= e(t('确定删除条目「{name}」吗？此操作不可恢复！', ['name' => $row['title']])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $rid ?>">
                                                <input type="hidden" name="ret_q" value="<?= e($q) ?>">
                                                <input type="hidden" name="ret_cat" value="<?= e($filterCat) ?>">
                                                <input type="hidden" name="ret_page" value="<?= (int)$list['page'] ?>">
                                                <button type="submit" class="mini-btn mini-danger" title="<?= e(t('删除条目')) ?>" aria-label="<?= e(t('删除条目：')) ?><?= e($row['title']) ?>"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($list['pages'] <= 1): ?>
                    <div class="pagination is-single">
                        <span class="page-meta"><?= e(t('共')) ?> <?= $list['total'] ?> <?= e(t('条 · 第')) ?> <?= $list['page'] ?> / <?= $list['pages'] ?> <?= e(t('页')) ?></span>
                    </div>
                <?php else: ?>
                    <?php
                    $pages = [];
                    if ($list['pages'] <= 7) {
                        for ($p = 1; $p <= $list['pages']; $p++) $pages[] = $p;
                    } else {
                        $pages[] = 1;
                        if ($list['page'] > 3) $pages[] = 'gap';
                        for ($p = max(2, $list['page'] - 1); $p <= min($list['pages'] - 1, $list['page'] + 1); $p++) $pages[] = $p;
                        if ($list['page'] < $list['pages'] - 2) $pages[] = 'gap';
                        $pages[] = $list['pages'];
                    }
                    ?>
                    <nav class="pagination" aria-label="<?= e(t('保险箱条目分页')) ?>">
                        <span class="page-meta"><?= e(t('共')) ?> <?= $list['total'] ?> <?= e(t('条 · 第')) ?> <?= $list['page'] ?> / <?= $list['pages'] ?> <?= e(t('页')) ?></span>
                        <div class="page-btns">
                            <?php if ($list['page'] > 1): ?>
                                <a class="page-btn" href="<?= e(vault_list_url($q, $filterCat, $list['page'] - 1)) ?>" rel="prev">‹ <?= e(t('上一页')) ?></a>
                            <?php else: ?>
                                <span class="page-btn is-disabled" aria-disabled="true">‹ <?= e(t('上一页')) ?></span>
                            <?php endif; ?>

                            <?php foreach ($pages as $pg): ?>
                                <?php if ($pg === 'gap'): ?>
                                    <span class="page-btn is-gap">…</span>
                                <?php elseif ($pg === $list['page']): ?>
                                    <span class="page-btn is-current" aria-current="page"><?= $pg ?></span>
                                <?php else: ?>
                                    <a class="page-btn" href="<?= e(vault_list_url($q, $filterCat, (int)$pg)) ?>"><?= $pg ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <?php if ($list['page'] < $list['pages']): ?>
                                <a class="page-btn" href="<?= e(vault_list_url($q, $filterCat, $list['page'] + 1)) ?>" rel="next"><?= e(t('下一页')) ?> ›</a>
                            <?php else: ?>
                                <span class="page-btn is-disabled" aria-disabled="true"><?= e(t('下一页')) ?> ›</span>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>

            <p class="vault-tip"><?= e(t('账号与密码默认打码，点「显示 / 复制」时才向服务端按需取明文；页面源码中不含任何明文密码。')) ?></p>
        </section>
    </div>

<?php endif; ?>

<?php if ($masterSet): /* ============ 重置保险箱模态框（已设置主密码即可用，含忘记主密码场景） ============ */ ?>
<div class="modal-backdrop" id="vaultResetModal" hidden>
    <div class="modal-dialog modal-narrow" role="dialog" aria-modal="true" aria-labelledby="vaultResetTitle" aria-describedby="vaultResetDesc">
        <form method="post" action="vault.php" class="modal-form" id="vaultResetForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_vault">

            <header class="modal-head">
                <h2 class="modal-title" id="vaultResetTitle"><?= e(t('重置保险箱')) ?></h2>
                <button type="button" class="modal-close" id="vaultResetClose" aria-label="<?= e(t('关闭弹窗')) ?>">✕</button>
            </header>

            <div class="modal-body">
                <p class="modal-tip" id="vaultResetDesc"><?= e(t('重置会删除保险箱中的')) ?><strong><?= e(t('全部条目')) ?></strong>，<?= e(t('并清除主密码与加密密钥。数据经 AES-256-GCM 加密，密钥不可复原，因此原条目')) ?><strong class="danger-text"><?= e(t('无法恢复')) ?></strong>。</p>

                <section class="modal-section modal-danger">
                    <h3 class="modal-section-title"><?= e(t('危险确认')) ?></h3>
                    <label class="check-label" for="vault-ack">
                        <input type="checkbox" name="ack" value="1" id="vault-ack">
                        <span><?= e(t('我已了解：重置后全部条目永久丢失，且无法通过任何方式找回。')) ?></span>
                    </label>
                    <div class="form-field">
                        <label class="field-label" for="vault-confirm"><?= e(t('请输入「')) ?><?= e(VAULT_RESET_PHRASE) ?>」<?= e(t('以确认')) ?><span class="req">*</span></label>
                        <input class="form-input" type="text" id="vault-confirm" name="confirm_text"
                               autocomplete="off" placeholder="<?= e(VAULT_RESET_PHRASE) ?>">
                    </div>
                    <p class="field-error" id="vaultResetError" hidden></p>
                </section>
            </div>

            <footer class="modal-foot">
                <button type="button" class="btn-ghost" id="vaultResetCancel"><?= e(t('取消')) ?></button>
                <button type="submit" class="btn-danger" id="vaultResetSubmit"><?= e(t('确认重置保险箱')) ?></button>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 保险箱页面的运行时配置：CSRF / 超时 / 确认词组 / 全部界面文案。
     交互逻辑本身已抽到 assets/js/vault.js（内联脚本无法被 node --check 覆盖）。
     JSON_HEX_TAG 等标志确保 </script> 与引号不会破坏页面结构。 -->
<script<?= nonce_attr() ?> type="application/json" id="vaultConfig">
<?= json_encode([
    'endpoint'    => 'vault_reveal.php',
    'csrf'        => csrf_token(),
    'timeout'     => VAULT_IDLE_TIMEOUT,
    'resetPhrase' => VAULT_RESET_PHRASE,
    'i18n'        => [
        'badResponse'      => t('返回数据异常。'),
        'readFailed'       => t('读取失败。'),
        'copyFailedManual' => t('复制失败，请手动选择文本。'),
        'show'             => t('显示'),
        'hide'             => t('隐藏'),
        'emptySuffix'      => t('空）'),
        'readFailedShort'  => t('读取失败'),
        'emptyNoCopy'      => t('该字段为空，无可复制内容。'),
        'copied'           => t('已复制'),
        'copyFailed'       => t('复制失败'),
        'strength0'        => t('强度：—'),
        'strength1'        => t('强度：弱（易被猜解）'),
        'strength2'        => t('强度：中'),
        'strength3'        => t('强度：较强'),
        'strength4'        => t('强度：强（推荐）'),
        'atLeast8'         => t('至少 8 位'),
        'unlockedPrefix'   => t('已解锁 ·'),
        'autoLockSuffix'   => t('后自动锁定'),
        'needAck'          => t('请先勾选「我已了解该操作不可恢复」。'),
        'confirmPrefix'    => t('请输入「'),
        'confirmSuffix'    => t('以确认。'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
<script src="../assets/js/vault.js"></script>

<?php admin_footer(); ?>
