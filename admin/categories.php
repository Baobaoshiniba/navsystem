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
        $order = (int)post('sort_order', '0');
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('分类名称不能为空', 'error');
            redirect('categories.php');
        }
        if ($id > 0) {
            $stmt = $db->prepare('UPDATE categories SET name=?, icon=?, sort_order=?, is_active=? WHERE id=?');
            $stmt->execute([$name, $icon, $order, $active, $id]);
            flash('分类已更新');
        } else {
            $stmt = $db->prepare('INSERT INTO categories (name, icon, sort_order, is_active) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $icon, $order, $active]);
            flash('分类已新增');
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
        flash('分类已删除，其下 ' . $cnt . ' 条链接已一并删除');
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
                $a = (int)$db->prepare('SELECT sort_order FROM categories WHERE id=?')->execute([$id]) ?? 0;
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
        flash('排序已更新');
        redirect('categories.php');
    }

    // 启用 / 停用
    if ($action === 'toggle') {
        $id = (int)post('id', '0');
        $db->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        flash('状态已切换');
        redirect('categories.php');
    }

    flash('未知操作', 'error');
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

admin_header('分类管理', 'categories');
?>
<div class="page-title-row">
    <h1 class="page-title">分类管理</h1>
    <p class="page-sub">管理导航分类：新增 / 编辑 / 排序 / 启停；删除分类会级联删除其下链接。</p>
</div>

<?php
// 轻量统计卡
$cOn = 0; $cOff = 0;
foreach ($cats as $c) {
    ((int)$c['is_active'] === 1) ? $cOn++ : $cOff++;
}
?>
<div class="mini-stats">
    <div class="mini-stat"><span class="ms-ico">📂</span><div><div class="ms-num"><?= count($cats) ?></div><div class="ms-label">全部分类</div></div></div>
    <div class="mini-stat"><span class="ms-ico">✅</span><div><div class="ms-num"><?= $cOn ?></div><div class="ms-label">启用中</div></div></div>
    <div class="mini-stat"><span class="ms-ico">⏸️</span><div><div class="ms-num"><?= $cOff ?></div><div class="ms-label">已停用</div></div></div>
</div>

<div class="two-col two-col-form">
    <!-- 左侧：表单 -->
    <section class="panel glass">
        <h2 class="panel-title"><?= $editing ? '编辑分类' : '新增分类' ?></h2>
        <form method="post" action="categories.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

            <label class="field-label">分类名称 *</label>
            <input class="form-input" name="name" required maxlength="50"
                   value="<?= e($editing['name'] ?? '') ?>" placeholder="如：技术研发">

            <label class="field-label">图标（emoji / unicode，可选）</label>
            <input class="form-input" name="icon" maxlength="16"
                   value="<?= e($editing['icon'] ?? '') ?>" placeholder="如：🧪">

            <label class="field-label">排序值（数字越小越靠前）</label>
            <input class="form-input" name="sort_order" type="number" value="<?= (int)($editing['sort_order'] ?? 0) ?>">

            <label class="check-label">
                <input type="checkbox" name="is_active" value="1" <?= (!isset($editing['is_active']) || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                启用该分类（前台展示）
            </label>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $editing ? '保存修改' : '新增分类' ?></button>
                <?php if ($editing): ?>
                    <a class="btn-ghost" href="categories.php">取消编辑</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- 右侧：分类列表 -->
    <section class="panel glass">
        <h2 class="panel-title">分类列表（共 <?= count($cats) ?> 个）</h2>
        <?php if (!$cats): ?>
            <div class="empty-tip"><span class="et-ico">📂</span><p>暂无分类，请先在左侧新增。</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table cats-table">
            <thead>
                <tr><th>排序</th><th>图标</th><th>名称</th><th class="td-center">链接数</th><th class="td-center">状态</th><th class="th-actions">操作</th></tr>
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
                                <button type="submit" name="mode" value="up" class="sort-btn" title="上移" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                                <button type="submit" name="mode" value="down" class="sort-btn" title="下移" <?= $i === count($cats) - 1 ? 'disabled' : '' ?>>↓</button>
                                <button type="submit" name="mode" value="top" class="sort-btn" title="置顶" <?= $i === 0 ? 'disabled' : '' ?>>⤒</button>
                            </div>
                        </form>
                    </td>
                    <td class="cell-icon"><?= e($cat['icon'] ?: '📁') ?></td>
                    <td class="cell-name"><?= e($cat['name']) ?></td>
                    <td class="td-center"><span class="cat-chip <?= (int)$cat['link_count'] > 0 ? '' : 'gray' ?>"><?= (int)$cat['link_count'] ?> 条</span></td>
                    <td class="td-center"><span class="badge <?= (int)$cat['is_active'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int)$cat['is_active'] === 1 ? '启用' : '停用' ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a class="mini-btn" href="categories.php?edit=<?= $cid ?>">✎ 编辑</a>
                            <form method="post" action="categories.php" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <button type="submit" class="mini-<?= (int)$cat['is_active'] === 1 ? 'ghost' : 'ok' ?>"><?= (int)$cat['is_active'] === 1 ? '⏸ 停用' : '▶ 启用' ?></button>
                            </form>
                            <form method="post" action="categories.php" class="inline-form"
                                  onsubmit="return confirm('确定删除分类「<?= e($cat['name']) ?>」吗？\\n其下 <?= (int)$cat['link_count'] ?> 条链接将一并删除，此操作不可恢复！');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $cid ?>">
                                <button type="submit" class="mini-btn mini-danger">🗑 删除</button>
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
