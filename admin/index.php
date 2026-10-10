<?php
/**
 * 后台 - 导航链接管理（新增 / 编辑 / 删除 / 排序 / 启停）
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();

/** 链接列表每页条数（服务端分页，调整后列表自动生效） */
const PAGE_SIZE = 8;

/**
 * 构造链接列表页 URL（保留 cat / page 上下文）
 * @param int $cat  分类筛选，0 表示全部
 * @param int $page 页码，<=1 时不带 page 参数
 */
function list_url(int $cat = 0, int $page = 1): string
{
    $qs = [];
    if ($cat > 0) $qs['cat'] = $cat;
    if ($page > 1) $qs['page'] = $page;
    return 'index.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * 构造带编辑态的 URL（?edit=id 与 cat / page 叠加，取消编辑可回到原筛选页）
 */
function edit_url(int $editId, int $cat = 0, int $page = 1): string
{
    $qs = ['edit' => $editId];
    if ($cat > 0) $qs['cat'] = $cat;
    if ($page > 1) $qs['page'] = $page;
    return 'index.php?' . http_build_query($qs);
}

/**
 * 计算某链接在指定分类筛选（0 表示全部）下的页码
 * 用于排序（上移/下移/置顶）后回跳该记录所在页，规避越过页边界后用户看不到结果
 */
function calc_record_page(PDO $db, int $id, int $cat): int
{
    $stmt = $db->prepare('
        SELECT COUNT(*) FROM links l
        JOIN links cur ON cur.id = ?
        WHERE (? = 0 OR l.category_id = ?)
          AND (l.sort_order < cur.sort_order OR (l.sort_order = cur.sort_order AND l.id <= cur.id))
    ');
    $stmt->bindValue(1, $id, PDO::PARAM_INT);
    $stmt->bindValue(2, $cat, PDO::PARAM_INT);
    $stmt->bindValue(3, $cat, PDO::PARAM_INT);
    $stmt->execute();
    $rank = (int)$stmt->fetchColumn() - 1; // 0-based 排名
    return intdiv(max(0, $rank), PAGE_SIZE) + 1;
}

/**
 * 按筛选后总条数钳制页码（删除导致当前页为空时自动回退到上一页 / 末页）
 */
function clamp_page_for_total(int $total, int $page): int
{
    $max = max(1, (int)ceil($total / PAGE_SIZE));
    return min(max(1, $page), $max);
}

$db = db();
$cats = fetch_categories();

/* ============ 动作分发（POST） ============ */
if (is_post()) {
    guard_csrf();
    $action = post('action');

    // 回跳上下文：各操作表单携带当前筛选与页码，redirect 尽量保留用户所在列表位置
    $retCat  = (int)post('ret_cat', '0');
    $retPage = max(1, (int)post('ret_page', '1'));

    if ($action === 'save') {
        $id          = (int)post('id', '0');
        $categoryId  = (int)post('category_id', '0');
        $title       = post('title');
        $url         = post('url');
        $description = post('description');
        $icon        = post('icon');
        $sortOrder   = (int)post('sort_order', '0');
        $active      = isset($_POST['is_active']) ? 1 : 0;

        // 校验
        $errors = [];
        if ($title === '') $errors[] = '链接标题不能为空';
        if ($url === '')   $errors[] = '链接 URL 不能为空';
        if ($categoryId <= 0) $errors[] = '请选择所属分类';
        $exists = false;
        if ($categoryId > 0) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM categories WHERE id=?');
            $stmt->execute([$categoryId]);
            $exists = (int)$stmt->fetchColumn() > 0;
            if (!$exists) $errors[] = '所选分类不存在';
        }
        if ($errors) {
            flash(implode('；', $errors), 'error');
            // 编辑校验失败回编辑态；新增校验失败回原筛选列表
            redirect($id > 0 ? edit_url($id, $retCat, $retPage) : list_url($retCat, $retPage));
        }

        if ($id > 0) {
            $stmt = $db->prepare('UPDATE links SET category_id=?, title=?, url=?, description=?, icon=?, sort_order=?, is_active=? WHERE id=?');
            $stmt->execute([$categoryId, $title, $url, $description, $icon, $sortOrder, $active, $id]);
            flash('链接已更新');
        } else {
            $stmt = $db->prepare('INSERT INTO links (category_id, title, url, description, icon, sort_order, is_active, click_count) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
            $stmt->execute([$categoryId, $title, $url, $description, $icon, $sortOrder, $active]);
            flash('链接已新增');
        }
        redirect(list_url($retCat, $retPage));
    }

    if ($action === 'delete') {
        $id = (int)post('id', '0');
        if ($id > 0) {
            $db->prepare('DELETE FROM links WHERE id=?')->execute([$id]);
            flash('链接已删除');
        }
        // 删除后按当前筛选重算总数：本页删空则自动回上一页（无记录回第 1 页空态）
        $cntSql = 'SELECT COUNT(*) FROM links';
        $cntParams = [];
        if ($retCat > 0) {
            $cntSql .= ' WHERE category_id = ?';
            $cntParams[] = $retCat;
        }
        $stmt = $db->prepare($cntSql);
        $stmt->execute($cntParams);
        $afterTotal = (int)$stmt->fetchColumn();
        redirect(list_url($retCat, clamp_page_for_total($afterTotal, $retPage)));
    }

    if ($action === 'sort') {
        $id   = (int)post('id', '0');
        $mode = post('mode');
        // 排序范围：在某分类 tab 下只在同分类链接内交换（避免跨分类换位）；「全部」tab 保持全表逻辑
        $scopeSql    = 'SELECT id, sort_order FROM links';
        $scopeParams = [];
        if ($retCat > 0) {
            $scopeSql .= ' WHERE category_id = ?';
            $scopeParams[] = $retCat;
        }
        $scopeSql .= ' ORDER BY sort_order ASC, id ASC';
        $stmt = $db->prepare($scopeSql);
        $stmt->execute($scopeParams);
        $all = $stmt->fetchAll();
        $keys = array_map('intval', array_column($all, 'id'));
        $pos = array_search($id, $keys, true);
        if ($pos !== false) {
            if ($mode === 'top') {
                // 置顶：取全表 MIN(sort_order) - 1。sort_order 全表唯一，取更小值可保证置顶后必定排在首位且不与任何记录撞值
                $min = (int)$db->query('SELECT MIN(sort_order) FROM links')->fetchColumn();
                $db->prepare('UPDATE links SET sort_order=? WHERE id=?')->execute([$min - 1, $id]);
            } else {
                $swapId = null;
                if ($mode === 'up' && $pos > 0) $swapId = $keys[$pos - 1];
                if ($mode === 'down' && $pos < count($keys) - 1) $swapId = $keys[$pos + 1];
                if ($swapId !== null) {
                    $stmt = $db->prepare('SELECT sort_order FROM links WHERE id=?');
                    $stmt->execute([$id]);
                    $a = (int)$stmt->fetchColumn();
                    $stmt2 = $db->prepare('SELECT sort_order FROM links WHERE id=?');
                    $stmt2->execute([$swapId]);
                    $b = (int)$stmt2->fetchColumn();
                    $db->prepare('UPDATE links SET sort_order=? WHERE id=?')->execute([$b, $id]);
                    $db->prepare('UPDATE links SET sort_order=? WHERE id=?')->execute([$a, $swapId]);
                }
            }
        }
        flash('排序已更新');
        // 上移/下移/置顶后回跳该记录所在页（仍在当前筛选内），避免越过页边界后看不到操作结果
        redirect(list_url($retCat, calc_record_page($db, $id, $retCat)));
    }

    if ($action === 'toggle') {
        $id = (int)post('id', '0');
        $db->prepare('UPDATE links SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
        flash('状态已切换');
        redirect(list_url($retCat, $retPage));
    }

    // 单条重抓图标（无 JS 时的兜底路径；有 JS 时由 icon_refresh.php 走 AJAX）
    if ($action === 'icon_refresh') {
        $id = (int)post('id', '0');
        $stmt = $db->prepare('SELECT * FROM links WHERE id=?');
        $stmt->execute([$id]);
        $link = $stmt->fetch();
        if (!$link) {
            flash('链接不存在', 'error');
        } else {
            // 抓取为耗时操作：先释放 session 写锁，抓完再恢复（flash 需写 $_SESSION）
            release_session_write();
            $res = icon_fetch_for_link($db, $link, ['force' => true]);
            resume_session_write();
            flash(($res['ok'] ? '图标已更新：' : '图标抓取失败：') . $link['title'] . '（' . $res['message'] . '）', $res['ok'] ? 'success' : 'error');
        }
        redirect(list_url($retCat, $retPage));
    }

    // 刷新全部图标（无 JS 时的兜底路径：限时循环，未跑完提示再次点击；有 JS 时由前端分批 AJAX 推进）
    if ($action === 'icon_refresh_all') {
        @set_time_limit(180);
        // 兜底路径同样会连续抓取：先释放 session 写锁，避免阻塞其它后台请求
        release_session_write();
        $deadline = time() + 20;
        $all = $db->query('SELECT * FROM links ORDER BY id ASC')->fetchAll();
        $ok = 0;
        $fail = 0;
        $done = 0;
        foreach ($all as $row) {
            if (time() > $deadline) break;
            $res = icon_fetch_for_link($db, $row, ['force' => true]);
            $res['ok'] ? $ok++ : $fail++;
            $done++;
        }
        $rest = count($all) - $done;
        resume_session_write();
        flash(
            '图标刷新完成：成功 ' . $ok . ' 条，失败 ' . $fail . ' 条' . ($rest > 0 ? '，剩余 ' . $rest . ' 条请再次点击继续' : ''),
            $rest > 0 ? 'error' : 'success'
        );
        redirect(list_url($retCat, $retPage));
    }

    flash('未知操作', 'error');
    redirect(list_url($retCat, $retPage));
}

/* ============ GET：列表页 ============ */
$catMap = [];
foreach ($cats as $c) $catMap[(int)$c['id']] = $c;

// 可按分类筛选
$filterCat = (int)($_GET['cat'] ?? 0);

// 分类 tab 条数据：全表总数 + 各分类链接条数（含停用链接，用于 tab 徽标）
$allLinksCount = (int)$db->query('SELECT COUNT(*) FROM links')->fetchColumn();
$catCounts = [];
foreach ($db->query('SELECT category_id, COUNT(*) AS n FROM links GROUP BY category_id') as $r) {
    $catCounts[(int)$r['category_id']] = (int)$r['n'];
}

$where = ' WHERE 1=1';
$params = [];
if ($filterCat > 0) {
    $where .= ' AND l.category_id = ?';
    $params[] = $filterCat;
}
$fromSql = ' FROM links l LEFT JOIN categories c ON c.id = l.category_id';

// 筛选条件下总条数与启停统计（标题“共 N 条”与顶部统计卡均基于筛选总范围，而非当前页行数）
$stmt = $db->prepare('
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN l.is_active = 1 THEN 1 ELSE 0 END), 0) AS on_count,
           COALESCE(SUM(CASE WHEN l.is_active = 0 THEN 1 ELSE 0 END), 0) AS off_count
    ' . $fromSql . $where
);
$stmt->execute($params);
$statRow = $stmt->fetch();
$totalLinks = (int)$statRow['total'];
$statOn     = (int)$statRow['on_count'];
$statOff    = (int)$statRow['off_count'];

// 服务端分页：页码越界自动钳制（<1 取 1，>总页数取末页；无记录保持第 1 页空态）
$totalPages = max(1, (int)ceil($totalLinks / PAGE_SIZE));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) {
    $page = 1;
} elseif ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * PAGE_SIZE;

// 当前页数据（LIMIT/OFFSET 由常量与钳制后整数运算得出，直接内联避免 PDO 绑定类型问题）
$stmt = $db->prepare('
    SELECT l.*, c.name AS category_name
    ' . $fromSql . $where . '
    ORDER BY l.sort_order ASC, l.id ASC
    LIMIT ' . PAGE_SIZE . ' OFFSET ' . $offset
);
$stmt->execute($params);
$links = $stmt->fetchAll();

// 当前筛选范围内全部链接 id（按当前排序），用于跨页判断「上移/下移/置顶」按钮是否可用
$rankSql = 'SELECT id FROM links' . ($filterCat > 0 ? ' WHERE category_id = ?' : '') . ' ORDER BY sort_order ASC, id ASC';
$stmtRank = $db->prepare($rankSql);
$stmtRank->execute($filterCat > 0 ? [$filterCat] : []);
$scopeIds   = array_map('intval', array_column($stmtRank->fetchAll(), 'id'));
$scopeTotal = count($scopeIds);

// 编辑模式
$editing = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $db->prepare('SELECT * FROM links WHERE id=?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

// 新增时默认选中筛选项
$formDefaultCat = $editing ? (int)$editing['category_id'] : ($filterCat > 0 ? $filterCat : 0);

// 新增时排序值默认取全表 MAX(sort_order) + 1，保证新增链接排在列表最后（编辑态仍显示该链接原有排序值）
$defaultSortOrder = $editing
    ? (int)$editing['sort_order']
    : ((int)$db->query('SELECT MAX(sort_order) FROM links')->fetchColumn() + 1);

admin_header('链接管理', 'links');
$iconStats = icon_cache_stats($db);
?>
<div class="page-title-row">
    <h1 class="page-title">链接管理</h1>
    <p class="page-sub keep-left">维护前台导航链接：标题 / URL / 描述 / 图标 / 所属分类，支持排序与启停。</p>
    <form method="post" action="index.php" class="inline-form" id="iconRefreshAllForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="icon_refresh_all">
        <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
        <input type="hidden" name="ret_page" value="<?= $page ?>">
        <button type="submit" class="btn-ghost btn-sm" id="iconRefreshAll">🔄 刷新全部图标</button>
    </form>
</div>

<!-- 批量刷新进度（JS 分批调用 icon_refresh.php，不阻塞页面） -->
<div class="icon-refresh-bar" id="iconRefreshBar" hidden>
    <span class="irb-track"><span class="irb-fill" id="iconRefreshFill" style="width:0%"></span></span>
    <span class="irb-text" id="iconRefreshText">准备中…</span>
</div>

<?php
// 轻量统计卡：已在上方按筛选总范围算出 $totalLinks / $statOn / $statOff
?>
<div class="mini-stats is-four">
    <div class="mini-stat"><span class="ms-ico">🔗</span><div><div class="ms-num"><?= $totalLinks ?></div><div class="ms-label">当前列表链接</div></div></div>
    <div class="mini-stat"><span class="ms-ico">✅</span><div><div class="ms-num"><?= $statOn ?></div><div class="ms-label">启用中</div></div></div>
    <div class="mini-stat"><span class="ms-ico">⏸️</span><div><div class="ms-num"><?= $statOff ?></div><div class="ms-label">已停用</div></div></div>
    <div class="mini-stat"><span class="ms-ico">🖼️</span><div><div class="ms-num"><?= $iconStats['cached'] ?>/<?= $iconStats['total'] ?></div><div class="ms-label">已缓存图标</div></div></div>
</div>

<div class="two-col two-col-form">
    <!-- 左侧：表单 -->
    <section class="panel glass">
        <h2 class="panel-title"><?= $editing ? '编辑链接' : '新增链接' ?></h2>
        <form method="post" action="index.php" class="form-stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
            <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
            <input type="hidden" name="ret_page" value="<?= $page ?>">

            <label class="field-label">所属分类 *</label>
            <select class="form-input" name="category_id" required>
                <option value="">— 请选择分类 —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $formDefaultCat ? 'selected' : '' ?>>
                        <?= e($c['name']) ?><?= (int)$c['is_active'] === 1 ? '' : '（停用）' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="field-label">标题 *</label>
            <input class="form-input" name="title" required maxlength="60"
                   value="<?= e($editing['title'] ?? '') ?>" placeholder="如：企业微信">

            <label class="field-label">URL *</label>
            <input class="form-input" name="url" required maxlength="500" placeholder="https://…"
                   value="<?= e($editing['url'] ?? '') ?>">

            <label class="field-label">描述</label>
            <input class="form-input" name="description" maxlength="200"
                   value="<?= e($editing['description'] ?? '') ?>" placeholder="一句话描述该链接">

            <label class="field-label">图标（emoji / unicode，可选）</label>
            <input class="form-input" name="icon" maxlength="16"
                   value="<?= e($editing['icon'] ?? '') ?>" placeholder="如：🐙">

            <label class="field-label">排序值（数字越小越靠前）</label>
            <input class="form-input" name="sort_order" type="number" value="<?= $defaultSortOrder ?>">

            <label class="check-label">
                <input type="checkbox" name="is_active" value="1" <?= (!isset($editing['is_active']) || (int)$editing['is_active'] === 1) ? 'checked' : '' ?>>
                启用该链接（前台展示）
            </label>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $editing ? '保存修改' : '新增链接' ?></button>
                <?php if ($editing): ?>
                    <a class="btn-ghost" href="<?= e(list_url($filterCat, $page)) ?>">取消编辑</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- 右侧：链接列表 -->
    <section class="panel glass">
        <h2 class="panel-title">链接列表（共 <?= $totalLinks ?> 条）</h2>

        <!-- 分类 tab 条：服务端 GET 切换（?cat=N），切换后重置到第 1 页 -->
        <nav class="cat-tabs" aria-label="链接分类筛选">
            <a class="cat-tab<?= $filterCat === 0 ? ' is-active' : '' ?>" href="<?= e(list_url(0, 1)) ?>"<?= $filterCat === 0 ? ' aria-current="page"' : '' ?>>全部<span class="tab-badge"><?= $allLinksCount ?></span></a>
            <?php foreach ($cats as $c): $cid = (int)$c['id']; ?>
                <a class="cat-tab<?= $filterCat === $cid ? ' is-active' : '' ?>" href="<?= e(list_url($cid, 1)) ?>"<?= $filterCat === $cid ? ' aria-current="page"' : '' ?>><?= e($c['name']) ?><?php if ((int)$c['is_active'] !== 1): ?><em class="tab-off">停用</em><?php endif; ?><span class="tab-badge"><?= $catCounts[$cid] ?? 0 ?></span></a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$links): ?>
            <div class="empty-tip"><span class="et-ico">🔗</span><p>当前筛选下暂无链接，请先在左侧新增。</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table links-table">
            <thead>
                <tr><th>排序</th><th>图标</th><th>标题</th><th>URL</th><th>分类</th><th>点击</th><th class="td-center">状态</th><th class="th-actions">操作</th></tr>
            </thead>
            <tbody>
                <?php foreach ($links as $i => $link): $lid = (int)$link['id'];
                    // 排名基于当前筛选范围整体计算（而非当前页序号），翻页后首/末条判断同样正确
                    $rank    = array_search($lid, $scopeIds, true);
                    $isFirst = $rank === 0;
                    $isLast  = $rank === $scopeTotal - 1;
                    // 图标预览：本地缓存命中走 <img>，否则用首字母渐变徽章兜底（与前台一致）
                    $iconRel  = link_icon_url($link);
                    $iconTip  = ($link['icon_status'] ?? '') === 'ok'
                        ? '来源：' . icon_source_label((string)($link['icon_source'] ?? '')) . '　抓取：' . ((string)($link['icon_fetched_at'] ?? '') ?: '—')
                        : '未缓存本地图标：' . ((string)($link['icon_note'] ?? '') ?: '尚未抓取');
                ?>
                <tr data-link-id="<?= $lid ?>">
                    <td>
                        <form method="post" action="index.php" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="sort">
                            <input type="hidden" name="id" value="<?= $lid ?>">
                            <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
                            <input type="hidden" name="ret_page" value="<?= $page ?>">
                            <div class="sort-btns">
                                <button type="submit" name="mode" value="up" class="sort-btn sort-up" title="上移" <?= $isFirst ? 'disabled' : '' ?>><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 12.5V4"/><path d="M4.5 7 8 3.5 11.5 7"/></svg></button>
                                <button type="submit" name="mode" value="down" class="sort-btn sort-down" title="下移" <?= $isLast ? 'disabled' : '' ?>><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3.5v8.5"/><path d="M4.5 9 8 12.5 11.5 9"/></svg></button>
                                <button type="submit" name="mode" value="top" class="sort-btn sort-top" title="置顶" <?= $isFirst ? 'disabled' : '' ?>><svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.6 3.2h6.8"/><path d="M8 13.2V6"/><path d="M5.2 8.8 8 6l2.8 2.8"/></svg></button>
                            </div>
                        </form>
                    </td>
                    <td class="cell-icon">
                        <span class="icon-cell" title="<?= e($iconTip) ?>" data-icon-preview>
                            <?php if ($iconRel !== ''): ?>
                                <img src="../<?= e($iconRel) ?>" alt="" width="40" height="40" loading="lazy">
                            <?php else: ?>
                                <span class="icon-letter" style="--badge-hue:<?= link_icon_hue($link) ?>"><?= e(link_icon_letter($link)) ?></span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td class="cell-name"><?= e($link['title']) ?><small><?= e(icon_display_host((string)$link['url'])) ?></small></td>
                    <td class="cell-url"><a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($link['url']) ?>"><?= e($link['url']) ?></a></td>
                    <td><span class="cat-chip gray" title="<?= e($link['category_name'] ?? '—') ?>"><?= e($link['category_name'] ?? '—') ?></span></td>
                    <td><?= (int)$link['click_count'] ?></td>
                    <td class="td-center"><span class="badge <?= (int)$link['is_active'] === 1 ? 'badge-on' : 'badge-off' ?>"><?= (int)$link['is_active'] === 1 ? '启用' : '停用' ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a class="mini-btn" href="<?= e(edit_url($lid, $filterCat, $page)) ?>">✎ 编辑</a>
                            <form method="post" action="index.php" class="inline-form icon-single-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="icon_refresh">
                                <input type="hidden" name="id" value="<?= $lid ?>">
                                <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
                                <input type="hidden" name="ret_page" value="<?= $page ?>">
                                <button type="submit" class="mini-btn" title="重新抓取该链接的 favicon 并写入本地缓存">🔄 重抓</button>
                            </form>
                            <form method="post" action="index.php" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $lid ?>">
                                <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
                                <input type="hidden" name="ret_page" value="<?= $page ?>">
                                <button type="submit" class="mini-<?= (int)$link['is_active'] === 1 ? 'ghost' : 'ok' ?>"><?= (int)$link['is_active'] === 1 ? '⏸ 停用' : '▶ 启用' ?></button>
                            </form>
                            <form method="post" action="index.php" class="inline-form"
                                  onsubmit="return confirm('确定删除链接「<?= e($link['title']) ?>」吗？此操作不可恢复！');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $lid ?>">
                                <input type="hidden" name="ret_cat" value="<?= $filterCat ?>">
                                <input type="hidden" name="ret_page" value="<?= $page ?>">
                                <button type="submit" class="mini-btn mini-danger">🗑 删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($totalPages <= 1): ?>
            <!-- 仅一页：简洁单页样式（不发散为按钮组） -->
            <div class="pagination is-single">
                <span class="page-meta">共 <?= $totalLinks ?> 条 · 第 <?= $page ?> / <?= $totalPages ?> 页</span>
            </div>
        <?php else: ?>
            <?php
            // 页码窗口：总页数 ≤7 全量展示，否则保留首尾 + 当前页附近 ±1，缺省以省略号占位
            $pages = [];
            if ($totalPages <= 7) {
                for ($p = 1; $p <= $totalPages; $p++) $pages[] = $p;
            } else {
                $pages[] = 1;
                if ($page > 3) $pages[] = 'gap';
                for ($p = max(2, $page - 1); $p <= min($totalPages - 1, $page + 1); $p++) $pages[] = $p;
                if ($page < $totalPages - 2) $pages[] = 'gap';
                $pages[] = $totalPages;
            }
            ?>
            <nav class="pagination" aria-label="链接列表分页">
                <span class="page-meta">共 <?= $totalLinks ?> 条 · 第 <?= $page ?> / <?= $totalPages ?> 页</span>
                <div class="page-btns">
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?= e(list_url($filterCat, $page - 1)) ?>" rel="prev">‹ 上一页</a>
                    <?php else: ?>
                        <span class="page-btn is-disabled" aria-disabled="true">‹ 上一页</span>
                    <?php endif; ?>

                    <?php foreach ($pages as $pg): ?>
                        <?php if ($pg === 'gap'): ?>
                            <span class="page-btn is-gap">…</span>
                        <?php elseif ($pg === $page): ?>
                            <span class="page-btn is-current" aria-current="page"><?= $pg ?></span>
                        <?php else: ?>
                            <a class="page-btn" href="<?= e(list_url($filterCat, (int)$pg)) ?>"><?= $pg ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= e(list_url($filterCat, $page + 1)) ?>" rel="next">下一页 ›</a>
                    <?php else: ?>
                        <span class="page-btn is-disabled" aria-disabled="true">下一页 ›</span>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<script>
/* 图标抓取交互：批量刷新分批 AJAX 推进（不阻塞页面）+ 单条重抓即时回显 */
(function () {
    'use strict';
    var form   = document.getElementById('iconRefreshAllForm');
    var btn    = document.getElementById('iconRefreshAll');
    var bar    = document.getElementById('iconRefreshBar');
    var fill   = document.getElementById('iconRefreshFill');
    var text   = document.getElementById('iconRefreshText');
    var csrfEl = form ? form.querySelector('input[name="csrf_token"]') : null;
    var CSRF   = csrfEl ? csrfEl.value : '';
    var running = false;

    /* 防僵死三件套：单次请求超时 / 整批总时限 / 单条失败重试上限 —— 保证进度条必然结束 */
    var REQ_TIMEOUT_MS = 15000;   // 单次请求硬超时，到点 abort，绝不让页面无限等待
    var RUN_TIMEOUT_MS = 90000;   // 整批刷新总时限（90 秒），到点停止并给出统计
    var MAX_RETRY      = 1;       // 单条失败最多重试 1 次，禁止无限重试
    var BATCH_LIMIT    = 3;       // 每批条数（后端上限 ICON_BATCH_MAX=5，取 3 更平滑）

    if (!window.fetch || !form) return;

    function post(data, ctrl, timeoutMs) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        var timer = setTimeout(function () {
            if (ctrl) { try { ctrl.abort(); } catch (e) {} }
        }, timeoutMs || REQ_TIMEOUT_MS);
        return fetch('icon_refresh.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            signal: ctrl ? ctrl.signal : undefined,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (json) {
            clearTimeout(timer);
            return json;
        }, function (err) {
            clearTimeout(timer);
            throw err;
        });
    }

    /* 用抓取结果即时更新该行图标预览（缓存命中显示图片，否则首字母徽章） */
    function applyItem(item) {
        var row = document.querySelector('tr[data-link-id="' + item.id + '"]');
        var box = row ? row.querySelector('[data-icon-preview]') : null;
        if (!box) return;
        box.innerHTML = '';
        if (item.icon) {
            var img = document.createElement('img');
            img.src = item.icon + '?t=' + Date.now();
            img.alt = '';
            img.width = 40;
            img.height = 40;
            box.appendChild(img);
            box.setAttribute('title', '来源：' + item.source + '　抓取：刚刚');
        } else {
            var span = document.createElement('span');
            span.className = 'icon-letter';
            span.style.setProperty('--badge-hue', item.hue);
            span.textContent = item.letter;
            box.appendChild(span);
            box.setAttribute('title', '未缓存本地图标：' + item.message);
        }
    }

    /* 批量刷新：分批 AJAX 推进，超时/到点/异常都必须结束进度条并给出统计 */
    form.addEventListener('submit', function (e) {
        if (running) { e.preventDefault(); return; }
        e.preventDefault();
        running = true;
        btn.disabled = true;
        bar.hidden = false;
        fill.style.width = '0%';

        var startedAt  = Date.now();
        var cursor     = 0;
        var ok = 0, fail = 0, total = 0, processed = 0;
        var retryOk = 0, retryFail = 0;   // 失败条目的重试结果
        var reqTries = 0;                 // 请求级失败重试计数
        var retryQueue = [];
        var runningReq = null;
        var runTimer = null;
        var ended = false;                // finish 只允许执行一次

        function finalOk()   { return ok + retryOk; }
        function finalFail() { return Math.max(0, fail - retryOk); }
        function stats() {
            return '已完成 ' + processed + ' 条（成功 ' + finalOk() + '，失败 ' + finalFail() + '）';
        }
        function remainText() {
            return (total > processed) ? '，剩余 ' + (total - processed) + ' 条未处理' : '';
        }
        function overdue() {
            return (Date.now() - startedAt) >= RUN_TIMEOUT_MS;
        }
        function stopMsg(prefix) {
            return prefix + stats() + remainText();
        }

        /* 唯一出口：成功 / 超时 / 中断 / 异常都走这里收尾，进度条不允许停留在"正在刷新…" */
        function finish(kind, msg) {
            if (ended) return;
            ended = true;
            running = false;
            btn.disabled = false;
            if (runTimer) { clearTimeout(runTimer); runTimer = null; }
            if (runningReq) { try { runningReq.abort(); } catch (e2) {} runningReq = null; }
            if (kind === 'done') { fill.style.width = '100%'; }
            text.textContent = msg;
            if (kind === 'done') {
                setTimeout(function () { window.location.reload(); }, 1200);
            }
        }

        /* 整批总时限兜底：即使某次请求彻底卡住，到点也必须结束进度条并给结果 */
        runTimer = setTimeout(function () {
            finish('timeout', stopMsg('已到 ' + Math.round(RUN_TIMEOUT_MS / 1000) + ' 秒总时限，刷新已停止：'));
        }, RUN_TIMEOUT_MS);

        /* 按游标逐批推进 */
        function pump() {
            if (ended) return;
            if (overdue()) {
                finish('timeout', stopMsg('已到 ' + Math.round(RUN_TIMEOUT_MS / 1000) + ' 秒总时限，刷新已停止：'));
                return;
            }
            text.textContent = '正在刷新… ' + stats() + (total ? ' / 共 ' + total + ' 条' : '');
            runningReq = new AbortController();
            post({ action: 'batch', cursor: cursor, limit: BATCH_LIMIT, scope: 'all' }, runningReq)
                .then(function (res) {
                    runningReq = null;
                    if (ended) return;
                    if (!res || !res.ok) { throw new Error((res && res.error) || '接口返回异常'); }
                    total      = res.total || total;
                    cursor     = res.cursor;
                    ok        += res.success;
                    fail      += res.failed;
                    processed += res.processed;
                    (res.items || []).forEach(applyItem);
                    (res.items || []).forEach(function (item) {
                        if (item && !item.ok) retryQueue.push({ id: item.id, tries: 0 });
                    });
                    fill.style.width = (total ? Math.min(100, Math.round(processed / total * 100)) : 100) + '%';
                    if (res.done || res.processed === 0) {
                        drainRetry();
                    } else {
                        pump();
                    }
                })
                .catch(function (err) {
                    runningReq = null;
                    if (ended) return;
                    if (reqTries < MAX_RETRY) {          // 请求级失败同样最多重试 1 次
                        reqTries++;
                        text.textContent = '请求失败，正在重试… ' + stats();
                        setTimeout(pump, 500);
                        return;
                    }
                    finish('error', stopMsg('刷新中断：') + '，原因：'
                        + (err && err.name === 'AbortError' ? '请求超时' : ((err && err.message) || '网络异常')));
                });
        }

        /* 失败条目重试：每条最多 MAX_RETRY 次，跑完即收尾 */
        function drainRetry() {
            if (ended) return;
            if (!retryQueue.length) {
                var extra = (retryOk + retryFail) > 0
                    ? '，其中重试恢复 ' + retryOk + ' 条、仍失败 ' + retryFail + ' 条' : '';
                finish('done', '刷新完成：共 ' + processed + ' 条，成功 ' + finalOk() + '，失败 ' + finalFail() + extra);
                return;
            }
            if (overdue()) {
                finish('timeout', stopMsg('已到 ' + Math.round(RUN_TIMEOUT_MS / 1000) + ' 秒总时限，刷新已停止：'));
                return;
            }
            var job = retryQueue.shift();
            text.textContent = '正在重试失败条目… ' + stats();
            runningReq = new AbortController();
            post({ action: 'single', id: job.id }, runningReq)
                .then(function (res) {
                    runningReq = null;
                    if (ended) return;
                    if (res && res.ok && res.item) {
                        applyItem(res.item);
                        if (res.item.ok) {
                            retryOk++;
                        } else {
                            retryFail++;
                            if (job.tries + 1 < MAX_RETRY) {   // 未达上限才允许再入队
                                retryQueue.push({ id: job.id, tries: job.tries + 1 });
                            }
                        }
                    } else {
                        retryFail++;
                    }
                    drainRetry();
                })
                .catch(function (err) {
                    runningReq = null;
                    if (ended) return;
                    retryFail++;
                    drainRetry();
                });
        }

        pump();
    });

    /* 单条重抓：AJAX 提交，成功后原地更新预览，无需整页刷新（带请求超时 + 失败重试上限 1 次） */
    Array.prototype.forEach.call(document.querySelectorAll('.icon-single-form'), function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var b = f.querySelector('button');
            var label = b.textContent;
            var id = f.querySelector('input[name="id"]').value;
            var tries = 0;
            b.disabled = true;
            b.textContent = '…';
            function restore() { b.disabled = false; b.textContent = label; }
            function attempt() {
                var ctrl = new AbortController();
                post({ action: 'single', id: id }, ctrl).then(function (res) {
                    restore();
                    if (!res || !res.ok) { window.alert('重抓失败：' + ((res && res.error) || '未知错误')); return; }
                    applyItem(res.item);
                }).catch(function (err) {
                    if (tries < MAX_RETRY) {
                        tries++;
                        b.textContent = '…重试';
                        attempt();
                        return;
                    }
                    restore();
                    window.alert('重抓失败：' + (err && err.name === 'AbortError' ? '请求超时' : ((err && err.message) || '网络异常')));
                });
            }
            attempt();
        });
    });
})();
</script>
<?php admin_footer(); ?>
