<?php
/**
 * 后台 - 分类管理（新增 / 编辑 / 删除 / 排序 / 启用停用）
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();

$db = db();

/* ============ 动作分发（POST） ============ */
if (is_post()) {
    guard_csrf();
    $action = post('action');

    // 新增 / 编辑
    if ($action === 'save') {
        $id    = (int)post('id', '0');
        $name  = post('name');
        $icon  = post('icon');
        $nameEn = post('name_en');
        $order = (int)post('sort_order', '0');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash(t('分类名称不能为空'), 'error');
            redirect('categories.php');
        }
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE categories SET name=?, icon=?, sort_order=?, is_active=?, name_en=? WHERE id=?');
            $stmt->execute([$name, $icon, $order, $active, $nameEn, $id]);
            flash(t('分类已更新'));
        } else {
            $stmt = $db->prepare('INSERT INTO categories (name, icon, sort_order, is_active, name_en) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $icon, $order, $active, $nameEn]);
            flash(t('分类已新增'));
        }
        redirect('categories.php');
    }

    // 删除：先统计其下链接数，显式删除链接 + 分类（外键 CASCADE 已开启，双保险保证无孤儿数据）
    if ($action === 'delete') {
        $id  = (int)post('id', '0');
        $stmt = $db->prepare('SELECT COUNT(*) FROM links WHERE category_id=?');
        $stmt->execute([$id]);
        $cnt = (int)$stmt->fetchColumn();
        $db->prepare('DELETE FROM links WHERE category_id=?')->execute([$id]);
        $db->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
        flash((t('分类已删除，其下') . ' ') . $cnt . (' ' . t('条链接已一并删除')));
        redirect('categories.php');
    }

    // 排序（上移/下移/置顶）
    if ($action === 'sort') {
        $id   = (int)post('id', '0');
        $mode = post('mode');
        $all = $db->query('SELECT id, sort_order FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();
        $keys = array_column($all, 'id');
        $pos = array_search($id, array_map('intval', $keys), true);

        if ($pos !== false) {
            if ($mode === 'up' && $pos > 0) {
                $swapId = (int)$keys[$pos - 1];
            } elseif ($mode === 'down' && $pos < count($keys) - 1) {
                $swapId = (int)$keys[$pos + 1];
            } elseif ($mode === 'top') {
                $swapId = 0;
            } else {
                $swapId = null;
            }
            if ($mode === 'top') {
                $min = (int)$db->query('SELECT MIN(sort_order) FROM categories')->fetchColumn();
                $db->prepare('UPDATE categories SET sort_order=? WHERE id=?')->execute([$min - 1, $id]);
            } elseif ($swapId !== null) {
                $stmt = $db->prepare('SELECT sort_order FROM categories WHERE id=?');
                $stmt->execute([$id]);
                $a = (int)$stmt->fetchColumn();
                $stmt2 = $db->prepare('SELECT sort_order FROM categories WHERE id=?');
                $stmt2->execute([$swapId]);
                $b = (int)$stmt2->fetchColumn();
                $db->prepare('UPDATE categories SET sort_order=? WHERE id=?')->execute([$b, $id]);
                $db->prepare('UPDATE categories SET sort_order=? WHERE id=?')->execute([$a, $swapId]);
            }
        }
        flash(t('排序已更新'));
        redirect('categories.php');
    }

    // 启用 / 停用
    if ($action === 'toggle') {
        $id = (int)post('id', '0');
        $db->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        flash(t('状态已切换'));
        redirect('categories.php');
    }

    flash(t('未知操作'), 'error');
    redirect('categories.php');
}

/* ============ GET：列表页 ============ */
$cats = $db->query('
    SELECT c.*,
           (SELECT COUNT(*) FROM links l WHERE l.category_id = c.id) AS link_count
    FROM categories c
    ORDER BY c.sort_order ASC, c.id ASC
')->fetchAll();

// 编辑模式（可选参数 edit=id）
$editing = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id=?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

admin_header(t('分类管理'), 'categories');
?>
<div class="page-title-row">
    <h1 class="page-title"><?= e(t('分类管理')) ?></h1>
    <p class="page-sub"><?= e(t('管理导航分类：新增 / 编辑 / 排序 / 启停；删除分类会级联删除其下链接。')) ?></p>
</div>

<?php
// 轻量统计卡
$cOn = 0; $cOff = 0;
foreach ($cats as $c) {
    ((int)$c['is_active'] === 1) ? $cOn++ : $cOff++;
}
?>
<div class="mini-stats">
    <div class="mini-stat"><span class="ms-ico"><?= ui_icon('folder') ?></span><div><div class="ms-num"><?= count($cats) ?></div><div class="ms-label"><?= e(t('全部分类')) ?></div></div></div>
    <div class="mini-stat"><span class="ms-ico"><?= ui_icon('check') ?></span><div><div class="ms-num"><?= $cOn ?></div><div class="ms-label"><?= e(t('启用中')) ?></div></div></div>
    <div class="mini-stat"><span class="ms-ico"><?= ui_icon('pause') ?></span><div><div class="ms-num"><?= $cOff ?></div><div class="ms-label"><?= e(t('已停用')) ?></div></div></div>
</div>

<div class="two-col two-col-form">
    <!-- 左侧：表单 -->
    <details class="panel glass editor-panel" open data-editing="<?= $editing ? '1' : '0' ?>">
        <summary class="panel-title"><?= $editing ? t('编辑分类') : t('新增分类') ?></summary>
        <form method="post" action="categories.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

            <label class="field-label"><?= e(t('分类名称 *')) ?></label>
            <input class="form-input" name="name" required maxlength="50"
                   value="<?= e($editing['name'] ?? '') ?>" placeholder="<?= e(t('如：技术研发')) ?>">

            <label class="field-label" for="name_en"><?= e(t('分类名称（英文）')) ?></label>
            <input class="form-input" id="name_en" name="name_en" maxlength="50" value="<?= e($editing['name_en'] ?? '') ?>" placeholder="<?= e(t('可选，留空时显示中文内容')) ?>">

            <label class="field-label" for="category-icon"><?= e(t('分类图标')) ?></label>
            <?php $legacyIcons = ['🤝' => 'users', '💻' => 'code', '🧪' => 'flask', '📚' => 'book'];
                $currentIcon = $editing['icon'] ?? 'folder';
                $currentIcon = $legacyIcons[$currentIcon] ?? $currentIcon;
            ?>
            <select class="form-input" id="category-icon" name="icon">
                <?php foreach (['folder' => t('文件夹'), 'users' => t('小组协作'), 'code' => t('开发工具'), 'flask' => t('测试与质量'), 'book' => t('学习与报告')] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $currentIcon === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="field-label"><?= e(t('排序值（数字越小越靠前）')) ?></label>
            <input class="form-input" name="sort_order" type="number" value="<?= (int)($editing['sort_order'] ?? 0) ?>">

            <label class="check-label">
                <input type="checkbox" name="is_active" value="1" <?= (!isset($editing['is_active']) || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                <?= e(t('启用该分类（前台展示）')) ?>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $editing ? t('保存修改') : t('新增分类') ?></button>
                <?php if ($editing): ?>
                    <a class="btn-ghost" href="categories.php"><?= e(t('取消编辑')) ?></a>
                <?php endif; ?>
            </div>
        </form>
    </details>

    <!-- 右侧：分类列表 -->
    <section class="panel glass">
        <h2 class="panel-title"><?= e(t('分类列表（共')) ?> <?= count($cats) ?> <?= e(t('个）')) ?></h2>
        <?php if (!$cats): ?>
            <div class="empty-tip"><span class="et-ico"><?= ui_icon('folder') ?></span><p><?= e(t('暂无分类，请先在左侧新增。')) ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table cats-table">
            <thead>
                <tr><th><?= e(t('排序')) ?></th><th><?= e(t('图标')) ?></th><th><?= e(t('名称')) ?></th><th class="td-center"><?= e(t('链接数')) ?></th><th class="td-center"><?= e(t('状态')) ?></th><th class="th-actions"><?= e(t('操作')) ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ($cats as $i => $cat): $cid = (int)$cat['id']; ?>
                <tr>
                    <td>
                        <form method="post" action="categories.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sort">
                            <input type="hidden" name="id" value="<?= $cid ?>">
                            <div class="sort-btns">
                                <button type="submit" name="mode" value="up" class="sort-btn" title="<?= e(t('上移')) ?>" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                                <button type="submit" name="mode" value="down" class="sort-btn" title="<?= e(t('下移')) ?>" <?= $i === count($cats) - 1 ? 'disabled' : '' ?>>↓</button>
                                <button type="submit" name="mode" value="top" class="sort-btn" title="<?= e(t('置顶')) ?>" <?= $i === 0 ? 'disabled' : '' ?>>⤒</button>
                            </div>
                        </form>
                    </td>
                    <td class="cell-icon"><?= category_symbol($cat) ?></td>
                    <td class="cell-name"><?= e(localized_field($cat, 'name')) ?></td>
                    <td class="td-center"><span class="cat-chip <?= (int)$cat['link_count'] > 0 ? '' : 'gray' ?>"><?= (int)$cat['link_count'] ?> <?= e(t('条')) ?></span></td>
                    <td class="td-center"><span class="badge <?= (int)$cat['is_active'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int)$cat['is_active'] === 1 ? t('启用') : t('停用') ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a class="mini-btn" href="categories.php?edit=<?= $cid ?>"><?= ui_icon('edit') ?> <?= e(t('编辑')) ?></a>
                            <form method="post" action="categories.php" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <button type="submit" class="mini-<?= (int)$cat['is_active'] === 1 ? 'ghost' : 'ok' ?>"><?= (int)$cat['is_active'] === 1 ? ('' . ui_icon('pause') . ' ' . t('停用')) : ('' . ui_icon('arrow') . ' ' . t('启用')) ?></button>
                            </form>
                            <form method="post" action="categories.php" class="inline-form"
                                  data-confirm="<?= e(t('确定删除分类「{name}」及其下 {count} 条链接吗？此操作不可恢复！', ['name' => localized_field($cat, 'name'), 'count' => (int)$cat['link_count']])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <button type="submit" class="mini-btn mini-danger"><?= ui_icon('trash') ?> <?= e(t('删除')) ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</div>
<?php admin_footer(); ?>
