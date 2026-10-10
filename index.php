<?php
/**
 * 公司导航页 - 前台展示
 *
 * 功能：科技感导航首页，支持深/浅色主题、分类分组、搜索即时过滤、点击计数。
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$db      = db();
$siteName = setting('site_name');

// 分类（含各自链接数量）
$cats = $db->query('
    SELECT c.*,
           (SELECT COUNT(*) FROM links l WHERE l.category_id = c.id AND l.is_active = 1) AS link_count
    FROM categories c
    WHERE c.is_active = 1
    ORDER BY c.sort_order ASC, c.id ASC
')->fetchAll();

// 启用中的链接
$links = $db->query('
    SELECT l.*, c.name AS category_name, c.sort_order AS category_sort
    FROM links l
    JOIN categories c ON c.id = l.category_id
    WHERE l.is_active = 1 AND c.is_active = 1
    ORDER BY c.sort_order ASC, c.id ASC, l.sort_order ASC, l.id ASC
')->fetchAll();

$categoryColors = ['#5b8cff', '#a97bff', '#00c6b4', '#ff9f45', '#ff6b8b', '#36d1dc', '#ffc53d', '#6c5ce7'];
$grouped = [];
foreach ($links as $link) {
    $grouped[$link['category_id']][] = $link;
}

// 统计
$totalCategories = count($cats);
$totalLinks      = count($links);
$totalClicks     = (int)$db->query('SELECT COALESCE(SUM(click_count), 0) FROM links')->fetchColumn();

// 聚合搜索：引擎清单（settings 可配，缺省回退内置默认）与默认选中引擎
$engines       = search_engines();
$defaultEngine = default_search_engine_name();

page_header('导航首页');

$footerText = setting('footer_text');
echo '<body class="app-body">';

/* ============ 顶栏 ============ */
echo '<header class="navbar glass">
    <div class="container navbar-inner">
        <div class="brand">
            ' . logo_badge_html() . '
            <div>
                <div class="brand-name">' . e(setting('logo_text')) . '</div>
                <div class="brand-tag">' . e(setting('site_slogan')) . '</div>
            </div>
        </div>
        <div class="navbar-actions">
            <div class="stats-chip" title="分类 / 链接 / 累计点击">
                <span>📂 ' . $totalCategories . '</span>
                <span>🔗 ' . $totalLinks . '</span>
                <span>👆 ' . number_format($totalClicks) . '</span>
            </div>
            ' . theme_toggle_html() . '
            <a class="admin-link" href="admin/login.php" title="管理后台">后台管理</a>
        </div>
    </div>
</header>';

/* 引擎胶囊标签条：先拼接 HTML，避免在 echo 字符串中嵌入 PHP 标签 */
$enginePillsHtml = '';
foreach ($engines as $eng) {
    $isActive = $eng['name'] === $defaultEngine;
    $enginePillsHtml .= '<button type="button" class="engine-pill' . ($isActive ? ' is-active' : '') . '"'
        . ' role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '"'
        . ' data-engine="' . e($eng['name']) . '"'
        . ' data-template="' . e($eng['url']) . '"'
        . ' data-placeholder="' . e(search_input_placeholder($eng['name'])) . '">'
        . e($eng['name'])
        . '</button>';
}

/* ============ 主视觉区 ============ */
echo '<section class="hero">
    <div class="hero-glow hero-glow-a"></div>
    <div class="hero-glow hero-glow-b"></div>
    <div class="container hero-inner">
        <h1 class="hero-title"><span class="grad-text">' . e(setting('site_name')) . '</span></h1>
        <p class="hero-sub">' . e(setting('site_slogan')) . '</p>
        <div class="agg-search" role="search" aria-label="聚合搜索">
            <div class="engine-strip" id="engineStrip" role="tablist" aria-label="选择搜索引擎">' . $enginePillsHtml . '</div>
            <form class="agg-form" id="aggForm" role="search">
                <span class="agg-icon" aria-hidden="true">🔍</span>
                <input type="search" id="aggInput" class="agg-input" name="q"
                       placeholder="' . e(search_input_placeholder($defaultEngine)) . '"
                       aria-label="' . e($defaultEngine) . ' 搜索关键词" autocomplete="off" enterkeyhint="search">
                <button type="submit" class="agg-btn" id="aggBtn">搜索</button>
            </form>
            <div class="agg-tip" id="aggTip" role="status" hidden></div>
        </div>
    </div>
</section>';

/* ============ 分类筛选条 ============ */
if ($cats) {
    echo '<div class="container filter-bar">
        <nav class="cat-filter" id="catFilter" aria-label="按分类筛选导航链接">
            <button type="button" class="filter-pill is-active" data-cat="all" aria-pressed="true">全部<span class="filter-badge">' . $totalLinks . '</span></button>';
    foreach ($cats as $cat) {
        $cid = (int)$cat['id'];
        echo '<button type="button" class="filter-pill" data-cat="' . $cid . '" aria-pressed="false">'
            . e($cat['name']) . '<span class="filter-badge">' . (int)$cat['link_count'] . '</span></button>';
    }
    echo '</nav>
    </div>';
}

/* ============ 导航卡片区 ============ */
echo '<main class="container main-area">';
echo '<div class="filter-empty is-hidden" id="filterEmptyTip" role="status">该分类下暂无启用的导航链接</div>';
$catIndex = 0;
foreach ($cats as $cat):
    $cid = (int)$cat['id'];
    $hasLinks = isset($grouped[$cid]) && count($grouped[$cid]) > 0;
    $color = $categoryColors[$catIndex % count($categoryColors)];
    $catIndex++;
?>
<section class="category-block" id="cat-<?= $cid ?>" data-cat="<?= $cid ?>" data-name="<?= e($cat['name']) ?>"
         data-count="<?= $hasLinks ? count($grouped[$cid]) : 0 ?>">
    <div class="category-head">
        <span class="cat-icon" style="background:<?= $color ?>1f;border-color:<?= $color ?>55"><?= e($cat['icon'] ?: '📁') ?></span>
        <h2 class="cat-title" style="color:<?= $color ?>"><?= e($cat['name']) ?></h2>
        <span class="cat-count"><?= $hasLinks ? count($grouped[$cid]) : 0 ?> 项</span>
    </div>

    <?php if (!$hasLinks): ?>
        <div class="empty-tip">该分类下暂无启用的导航链接</div>
    <?php else: ?>
        <div class="link-grid">
            <?php foreach ($grouped[$cid] as $link): ?>
                <?php
                $safeUrl    = e($link['url']);
                $ext        = preg_match('#^https?://#i', $link['url']) ? ' target="_blank" rel="noopener noreferrer"' : '';
                // 图标：本地缓存 favicon 优先，其次回退首字母渐变徽章（三级抓取的最终兜底）
                $iconUrl    = link_icon_url($link);
                $iconLetter = link_icon_letter($link);
                $iconHue    = link_icon_hue($link);
                $domain     = icon_display_host((string)$link['url']);
                ?>
                <a href="<?= $safeUrl ?>"<?= $ext ?> class="link-card"
                   data-name="<?= e($link['title']) ?>"
                   data-desc="<?= e($link['description']) ?>"
                   data-url="<?= $safeUrl ?>"
                   onclick="recordClick(<?= (int)$link['id'] ?>, event)">
                    <span class="link-card-head">
                        <?php if ($iconUrl !== ''): ?>
                            <span class="link-icon">
                                <img src="<?= e($iconUrl) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                            </span>
                        <?php else: ?>
                            <span class="link-icon link-icon-fallback" style="--badge-hue:<?= (int)$iconHue ?>">
                                <span class="link-letter"><?= e($iconLetter) ?></span>
                            </span>
                        <?php endif; ?>
                        <span class="link-head-text">
                            <span class="link-title"><?= e($link['title']) ?></span>
                            <?php if ($domain !== ''): ?>
                                <span class="link-domain"><?= e($domain) ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                    <span class="link-desc"><?= e($link['description'] !== '' ? $link['description'] : '暂无描述') ?></span>
                    <span class="link-card-foot">
                        <span class="link-badge link-badge-cat" style="--badge-tone:<?= e($color) ?>"><?= e($cat['name']) ?></span>
                        <span class="link-count" title="点击次数">👆 <?= (int)$link['click_count'] ?></span>
                        <span class="link-arrow" aria-hidden="true">↗</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if (!$cats): ?>
    <div class="empty-block">
        <p>还没有可展示的分类，请先到后台添加分类与链接。</p>
        <a class="btn-primary" href="admin/login.php">进入管理后台</a>
    </div>
<?php endif; ?>
</main>

<footer class="app-footer">
    <div class="container"><?= e($footerText) ?></div>
</footer>

<!-- 点击统计 -->
<script>
function recordClick(id, ev) {
    var url = 'track_click.php?id=' + encodeURIComponent(id);
    try {
        if (navigator.sendBeacon) { navigator.sendBeacon(url); }
        else {
            var img = new Image();
            img.src = url;
        }
    } catch (e) {}
    // 不阻塞跳转，计数仅作展示参考
    var card = ev && ev.currentTarget;
    if (card) {
        var n = card.querySelector('.link-count');
        if (n) {
            var m = n.textContent.match(/👆\s*([\d,]+)/);
            if (m) {
                var v = parseInt(m[1].replace(/,/g, ''), 10) + 1;
                n.textContent = '👆 ' + v.toLocaleString();
            }
        }
    }
}
</script>

<!-- 聚合搜索：切换引擎 + 回车/点击跳转外部搜索引擎（关键词 encodeURIComponent 后替换 {q}） -->
<script>
(function () {
    var form = document.getElementById('aggForm');
    var input = document.getElementById('aggInput');
    var tip = document.getElementById('aggTip');
    var pills = Array.prototype.slice.call(document.querySelectorAll('.engine-pill'));
    if (!form || !input || !pills.length) { return; }
    var current = pills.filter(function (p) { return p.classList.contains('is-active'); })[0] || pills[0];

    function selectEngine(pill, focusInput) {
        current = pill;
        pills.forEach(function (p) {
            var on = p === pill;
            p.classList.toggle('is-active', on);
            p.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        var ph = pill.getAttribute('data-placeholder');
        if (ph) { input.setAttribute('placeholder', ph); }
        input.setAttribute('aria-label', (pill.getAttribute('data-engine') || '') + ' 搜索关键词');
        tip.hidden = true;
        if (focusInput) { input.focus(); }
    }

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () { selectEngine(pill, true); });
        pill.addEventListener('keydown', function (ev) {
            var idx = pills.indexOf(pill);
            if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
                ev.preventDefault();
                var next = ev.key === 'ArrowRight' ? (idx + 1) % pills.length : (idx - 1 + pills.length) % pills.length;
                pills[next].focus();
                selectEngine(pills[next], false);
            }
        });
        if (!pill.hasAttribute('tabindex')) { pill.setAttribute('tabindex', '0'); }
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var q = (input.value || '').trim();
        if (q === '') {
            tip.hidden = false;
            tip.textContent = '请输入要搜索的关键词';
            input.focus();
            return;
        }
        var tpl = current.getAttribute('data-template') || '';
        // 双保险：仅允许 http/https 模板，非法配置直接拦截
        if (!/^https?:\/\//i.test(tpl) || tpl.indexOf('{q}') === -1) {
            tip.hidden = false;
            tip.textContent = '当前搜索引擎配置无效，请到后台「站点设置」检查';
            return;
        }
        tip.hidden = true;
        var url = tpl.replace(/\{q\}/g, encodeURIComponent(q));
        var win = window.open(url, '_blank', 'noopener,noreferrer');
        if (win) { win.opener = null; }
    });
})();
</script>

<!-- 分类标签筛选：默认「全部」，状态写入 URL 参数 ?cat=，刷新/分享后保留 -->
<script>
(function () {
    var filter = document.getElementById('catFilter');
    if (!filter) { return; }
    var pills = Array.prototype.slice.call(filter.querySelectorAll('.filter-pill'));
    var blocks = Array.prototype.slice.call(document.querySelectorAll('.category-block'));
    var emptyTip = document.getElementById('filterEmptyTip');
    if (!pills.length) { return; }

    var ids = pills.map(function (p) { return p.getAttribute('data-cat'); });

    function currentCat() {
        var raw = '';
        try {
            raw = new URLSearchParams(window.location.search).get('cat') || '';
        } catch (e) { raw = ''; }
        return ids.indexOf(raw) !== -1 ? raw : 'all';
    }

    function apply(cat, syncUrl) {
        pills.forEach(function (p) {
            var on = p.getAttribute('data-cat') === cat;
            p.classList.toggle('is-active', on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        blocks.forEach(function (block) {
            var hit = cat === 'all' || block.getAttribute('data-cat') === cat;
            block.classList.toggle('is-hidden', !hit);
        });
        if (emptyTip) {
            var target = document.getElementById('cat-' + cat);
            var isEmptyCat = !!target && target.getAttribute('data-count') === '0';
            emptyTip.classList.toggle('is-hidden', !isEmptyCat);
        }
        if (syncUrl) {
            var url = window.location.pathname + (cat === 'all' ? '' : '?cat=' + encodeURIComponent(cat)) + window.location.hash;
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', url);
            }
        }
    }

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            var cat = pill.getAttribute('data-cat');
            apply(cat, true);
            var target = document.getElementById('cat-' + cat);
            if (target && cat !== 'all') { target.scrollIntoView({ block: 'start' }); }
        });
    });

    apply(currentCat(), false);
})();
</script>

<!-- 卡片光效跟随鼠标 -->
<script>
document.querySelectorAll('.link-card').forEach(function (card) {
    card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
        card.style.setProperty('--my', ((e.clientY - r.top) / r.height * 100) + '%');
    });
});
</script>

<?php
page_footer();
