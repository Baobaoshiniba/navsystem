<?php
/**
 * 公司导航页 - 前台展示
 *
 * 功能：科技感导航首页，支持深/浅色主题、分类分组、搜索即时过滤、点击计数。
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/daily_quote.php';

$db      = db();
$siteName = setting('site_name');
$dailyQuote = daily_quote_initial();

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
    SELECT l.*, c.name AS category_name, c.name_en AS category_name_en, c.sort_order AS category_sort
    FROM links l
    JOIN categories c ON c.id = l.category_id
    WHERE l.is_active = 1 AND c.is_active = 1
    ORDER BY c.sort_order ASC, c.id ASC, l.sort_order ASC, l.id ASC
')->fetchAll();

$grouped = [];
foreach ($links as $link) {
    $grouped[$link['category_id']][] = $link;
}

// 统计
$totalCategories = count($cats);
$totalLinks      = count($links);
$totalClicks     = (int)$db->query('SELECT COALESCE(SUM(click_count), 0) FROM links')->fetchColumn();

// Open the homepage on team collaboration by default; explicit category URLs still take precedence.
$defaultCategoryId = '';
foreach ($cats as $category) {
    if ((string)($category['name'] ?? '') === '小组协作'
        || strtolower(trim((string)($category['name_en'] ?? ''))) === 'team collaboration') {
        $defaultCategoryId = (string)(int)$category['id'];
        break;
    }
}
if ($defaultCategoryId === '' && $cats) $defaultCategoryId = (string)(int)$cats[0]['id'];
$requestedCategory = isset($_GET['cat']) ? (string)$_GET['cat'] : '';
$categoryIds = array_map(static fn($category) => (string)(int)$category['id'], $cats);
$initialCategory = $requestedCategory === 'all' ? 'all'
    : (in_array($requestedCategory, $categoryIds, true) ? $requestedCategory : $defaultCategoryId);
$initialCategoryRow = null;
foreach ($cats as $category) {
    if ((string)(int)$category['id'] === $initialCategory) { $initialCategoryRow = $category; break; }
}
$initialHeading = $initialCategory === 'all' || !$initialCategoryRow
    ? t('全部资源') : localized_field($initialCategoryRow, 'name');

// 聚合搜索：引擎清单（settings 可配，缺省回退内置默认）与默认选中引擎
$engines       = search_engines();
$defaultEngine = default_search_engine_name();

page_header(t('导航首页'));

$footerText = setting('footer_text');
$settingsCache = &raw_setting_cache();
$contactEmail = trim(array_key_exists('contact_email', $settingsCache)
    ? (string)$settingsCache['contact_email']
    : (string)($config['contact_email'] ?? ''));
if ($contactEmail === '') $contactEmail = (string)($config['contact_email'] ?? 'liu5xin@139.com');
$contactEmailValid = filter_var($contactEmail, FILTER_VALIDATE_EMAIL) !== false;
echo '<body class="app-body">';

/* ============ 顶栏 ============ */
echo '<header class="navbar workspace-navbar">
    <div class="container navbar-inner">
        <a class="brand" href="index.php">' . logo_badge_html() . '<span class="brand-name">' . e(setting('logo_text')) . '</span></a>
        <div class="navbar-actions">' . theme_toggle_html() . '<a class="admin-link" href="admin/login.php">' . ui_icon('settings') . '<span>' . e(t('后台管理')) . '</span></a></div>
    </div>
</header>';

/* 引擎胶囊标签条：先拼接 HTML，避免在 echo 字符串中嵌入 PHP 标签 */
$enginePillsHtml = '';
foreach ($engines as $eng) {
    $isActive = $eng['name'] === $defaultEngine;
    $enginePillsHtml .= '<button type="button" class="engine-pill' . ($isActive ? ' is-active' : '') . '"'
        . ' role="tab" aria-selected="' . ($isActive ? 'true' : 'false') . '"'
        . ' data-engine="' . e($eng['name']) . '"'
        . ' data-label="' . e(t($eng['name'])) . '"'
        . ' data-template="' . e($eng['url']) . '"'
        . ' data-placeholder="' . e(search_input_placeholder($eng['name'])) . '">'
        . e(t($eng['name']))
        . '</button>';
}

/* ============ 首屏：搜索 + 每日一言 ============ */
echo '<section class="hero"><h1 class="home-title-sr-only">' . e(setting('site_name')) . '</h1><div class="container hero-inner">';
echo '<div class="agg-search" aria-label="' . e(t('聚合搜索')) . '">'
    . '<div class="engine-strip" id="engineStrip" role="tablist" aria-label="' . e(t('选择搜索引擎')) . '">' . $enginePillsHtml . '</div>'
    . '<form class="agg-form" id="aggForm" role="search">'
    . '<span class="agg-icon" aria-hidden="true">' . ui_icon('search') . '</span>'
    . '<input type="search" id="aggInput" class="agg-input" name="q" placeholder="' . e(t('搜索资源名称、网址或分类')) . '"'
    . ' aria-label="' . e(t('搜索资源名称、网址或分类')) . '" autocomplete="off" enterkeyhint="search">'
    . '<button type="submit" class="agg-btn" id="aggBtn">' . e(t('网页搜索')) . '</button></form>'
    . '<div class="agg-tip" id="aggTip" role="status" hidden></div></div>';
echo '<aside class="daily-quote" id="dailyQuote" aria-label="' . e(t('每日一言')) . '">'
    . '<span class="daily-quote-mark" aria-hidden="true">“</span>'
    . '<div class="daily-quote-body"><div class="daily-quote-label">' . e(t('每日一言')) . '</div>'
    . '<p class="daily-quote-text" id="dailyQuoteText" lang="' . (locale() === 'en' ? 'en' : 'zh-Hans') . '">' . e($dailyQuote['text']) . '</p>'
    . '<div class="daily-quote-meta" id="dailyQuoteMeta">'
    . '<span id="dailyQuoteAttribution">' . e($dailyQuote['provider'] === 'team' ? t('小组寄语') : trim((($dailyQuote['from_who'] ?? '') !== '' ? '— ' . $dailyQuote['from_who'] : '') . (($dailyQuote['from_who'] ?? '') !== '' && ($dailyQuote['from'] ?? '') !== '' ? ' · ' : '') . ($dailyQuote['from'] ?? ''))) . '</span>'
    . '<a id="dailyQuoteSource"' . ($dailyQuote['provider'] === 'team' ? ' hidden' : '') . ' href="' . e($dailyQuote['source_url'] ?? '#') . '" target="_blank" rel="noopener noreferrer">' . e($dailyQuote['provider'] === 'zenquotes' ? t('由 ZenQuotes 提供') : t('由一言提供')) . '</a>'
    . '</div></div></aside></div></section>';

/* ============ Resource overview ============ */
echo '<div class="container resources-heading"><h2 id="resourcesHeading">' . e($initialHeading) . '</h2><p>' . e(t('{categories} 个分类 · {links} 个资源', ['categories' => $totalCategories, 'links' => $totalLinks])) . '</p></div>';

/* ============ 分类筛选条 ============ */
if ($cats) {
    echo ('<div class="container filter-bar">
        <nav class="cat-filter" id="catFilter" data-default-cat="' . e($defaultCategoryId) . '" data-all-label="' . e(t('全部资源')) . '" aria-label="' . t('按分类筛选导航链接') . '">
            <button type="button" class="filter-pill' . ($initialCategory === 'all' ? ' is-active' : '') . '" data-cat="all" aria-pressed="' . ($initialCategory === 'all' ? 'true' : 'false') . '">' . t('全部') . '<span class="filter-badge">') . $totalLinks . '</span></button>';
    foreach ($cats as $cat) {
        $cid = (int)$cat['id'];
        $isInitialCategory = (string)$cid === $initialCategory;
        echo '<button type="button" class="filter-pill' . ($isInitialCategory ? ' is-active' : '') . '" data-cat="' . $cid . '" aria-pressed="' . ($isInitialCategory ? 'true' : 'false') . '">'
            . e(localized_field($cat, 'name')) . '<span class="filter-badge">' . (int)$cat['link_count'] . '</span></button>';
    }
    echo '</nav>
    </div>';
}

/* ============ 快速启动区：单一资源网格 ============ */
echo '<main class="container main-area">';
echo '<div class="filter-empty is-hidden" id="filterEmptyTip" role="status">' . e(t('没有找到匹配的导航资源')) . '</div>';
if ($links) {
    echo '<div class="link-grid" id="resourceGrid">';
    foreach ($links as $link) {
        $safeUrl    = e($link['url']);
        $ext        = preg_match('#^https?://#i', $link['url']) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $iconUrl    = resource_icon_url($link);
        $iconLetter = resource_initial($link);
        $domain     = icon_display_host((string)$link['url']);
        $catName    = localized_field($link, 'category_name');
        echo '<a href="' . $safeUrl . '"' . $ext . ' class="link-card"'
            . ' data-id="' . (int)$link['id'] . '"'
            . ' data-cat="' . (int)$link['category_id'] . '"'
            . ' data-name="' . e(localized_field($link, 'title')) . '"'
            . ' data-desc="' . e(localized_field($link, 'description')) . '"'
            . ' data-category="' . e($catName) . '"'
            . ' data-url="' . $safeUrl . '">';
        echo '<span class="link-card-head">';
        if ($iconUrl !== '') {
            echo '<span class="link-icon"><img src="' . e($iconUrl) . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"></span>';
        } else {
            echo '<span class="link-icon link-icon-fallback"><span class="link-letter">' . e($iconLetter) . '</span></span>';
        }
        echo '<span class="link-head-text"><span class="link-title">' . e(localized_field($link, 'title')) . '</span>';
        if ($domain !== '') echo '<span class="link-domain">' . e($domain) . '</span>';
        echo '</span></span>';
        echo '<span class="link-desc">' . e(localized_field($link, 'description') !== '' ? localized_field($link, 'description') : t('暂无描述')) . '</span>';
        echo '<span class="link-card-foot"><span class="link-badge link-badge-cat">' . e($catName) . '</span>'
            . '<span class="link-count" title="' . e(t('点击次数')) . '">' . ui_icon('eye') . '<span class="click-value">' . (int)$link['click_count'] . '</span></span>'
            . '<span class="link-arrow" aria-hidden="true">' . ui_icon('external') . '</span></span></a>';
    }
    echo '</div>';
}

if (!$cats) {
    echo '<div class="empty-block"><p>' . e(t('还没有可展示的分类，请先到后台添加分类与链接。')) . '</p>'
        . '<a class="btn-primary" href="admin/login.php">' . e(t('进入管理后台')) . '</a></div>';
}
echo '</main>';
?>

<footer class="app-footer">
    <div class="container">
        <span><?= e($footerText) ?></span>
        <?php if ($contactEmailValid): ?>
            <a class="footer-contact" href="mailto:<?= e($contactEmail) ?>"><?= ui_icon('mail') ?><span><?= e($contactEmail) ?></span></a>
        <?php endif; ?>
    </div>
</footer>

<!-- 点击统计 -->
<script<?= nonce_attr() ?>>
/* 点击上报：用事件委托替代内联 onclick，使页面能启用 CSP */
function recordClick(id, card) {
    var url = 'track_click.php?id=' + encodeURIComponent(id);
    try {
        if (navigator.sendBeacon) { navigator.sendBeacon(url); }
        else {
            var img = new Image();
            img.src = url;
        }
    } catch (e) {}
    // 不阻塞跳转，计数仅作展示参考
    if (card) {
        var n = card.querySelector('.link-count');
        if (n) {
            var count = n.querySelector('.click-value');
            var m = count && count.textContent.match(/([\d,]+)/);
            if (m) {
                var v = parseInt(m[1].replace(/,/g, ''), 10) + 1;
                count.textContent = v.toLocaleString(document.documentElement.lang);
            }
        }
    }
}
document.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) { return; }
    var card = target.closest('.link-card');
    if (!card) { return; }
    recordClick(parseInt(card.getAttribute('data-id'), 10) || 0, card);
}, false);
</script>

<!-- 每日一言：同源接口每日缓存，API 失败时保留首屏兜底寄语 -->
<script<?= nonce_attr() ?>>
(function () {
    var text = document.getElementById('dailyQuoteText');
    var attribution = document.getElementById('dailyQuoteAttribution');
    var source = document.getElementById('dailyQuoteSource');
    if (!text || !attribution || !source || !window.fetch) return;
    fetch('daily_quote.php?lang=' + encodeURIComponent(document.documentElement.lang || 'zh-CN'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (response) { if (!response.ok) throw new Error('quote-unavailable'); return response.json(); })
        .then(function (payload) {
            if (!payload || !payload.ok || !payload.quote || !payload.quote.text) return;
            var quote = payload.quote;
            text.textContent = quote.text;
            var parts = [quote.from_who, quote.from].filter(function (part) { return !!part; });
            attribution.textContent = parts.length ? '— ' + parts.join(' · ') : (<?= jt('小组寄语') ?>);
            if (quote.provider === 'hitokoto' || quote.provider === 'zenquotes') {
                source.href = quote.source_url || (quote.provider === 'zenquotes' ? 'https://zenquotes.io/' : 'https://hitokoto.cn/');
                source.textContent = quote.provider === 'zenquotes' ? (<?= jt('由 ZenQuotes 提供') ?>) : (<?= jt('由一言提供') ?>);
                source.hidden = false;
            }
        })
        .catch(function () { /* 使用服务器渲染的每日兜底寄语 */ });
})();
</script>

<!-- 聚合搜索：切换引擎 + 回车/点击跳转外部搜索引擎（关键词 encodeURIComponent 后替换 {q}） -->
<script<?= nonce_attr() ?>>
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
        input.setAttribute('aria-label', <?= jt('搜索资源名称、网址或分类') ?>);
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
            tip.textContent = (<?= jt('请输入要搜索的关键词') ?>);
            input.focus();
            return;
        }
        var tpl = current.getAttribute('data-template') || '';
        // 双保险：仅允许 http/https 模板，非法配置直接拦截
        if (!/^https?:\/\//i.test(tpl) || tpl.indexOf('{q}') === -1) {
            tip.hidden = false;
            tip.textContent = (<?= jt('当前搜索引擎配置无效，请到后台「站点设置」检查') ?>);
            return;
        }
        tip.hidden = true;
        var url = tpl.replace(/\{q\}/g, encodeURIComponent(q));
        var win = window.open(url, '_blank', 'noopener,noreferrer');
        if (win) { win.opener = null; }
    });
})();
</script>

<!-- 分类标签筛选：默认展示小组协作；选择状态写入 URL 参数 ?cat=，刷新/分享后保留 -->
<script<?= nonce_attr() ?>>
(function () {
    var filter = document.getElementById('catFilter');
    if (!filter) { return; }
    var pills = Array.prototype.slice.call(filter.querySelectorAll('.filter-pill'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('.link-card'));
    var emptyTip = document.getElementById('filterEmptyTip');
    var input = document.getElementById('aggInput');
    if (!pills.length) { return; }

    var ids = pills.map(function (p) { return p.getAttribute('data-cat'); });

    function currentCat() {
        var raw = '';
        try {
            raw = new URLSearchParams(window.location.search).get('cat') || '';
        } catch (e) { raw = ''; }
        if (ids.indexOf(raw) !== -1) return raw;
        var defaultCat = filter.getAttribute('data-default-cat') || 'all';
        return ids.indexOf(defaultCat) !== -1 ? defaultCat : 'all';
    }

    function apply(cat, syncUrl) {
        pills.forEach(function (p) {
            var on = p.getAttribute('data-cat') === cat;
            p.classList.toggle('is-active', on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        var query = input ? input.value.trim().toLocaleLowerCase() : '';
        var visible = 0;
        cards.forEach(function (card) {
            var categoryMatch = cat === 'all' || card.getAttribute('data-cat') === cat;
            var searchable = [card.getAttribute('data-name'), card.getAttribute('data-url'), card.getAttribute('data-desc'), card.getAttribute('data-category')].join(' ').toLocaleLowerCase();
            var queryMatch = query === '' || searchable.indexOf(query) !== -1;
            var hit = categoryMatch && queryMatch;
            card.classList.toggle('is-hidden', !hit);
            if (hit) visible++;
        });
        if (emptyTip) emptyTip.classList.toggle('is-hidden', visible > 0);
        var heading = document.getElementById('resourcesHeading');
        if (heading) {
            var activePill = pills.filter(function (p) { return p.getAttribute('data-cat') === cat; })[0];
            heading.textContent = cat === 'all' ? (filter.getAttribute('data-all-label') || '')
                : (activePill && activePill.firstChild ? activePill.firstChild.nodeValue.trim() : '');
        }
        if (syncUrl) {
            var url = new URL(window.location.href);
            url.searchParams.set('cat', cat);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', url);
            }
        }
    }

    pills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            var cat = pill.getAttribute('data-cat');
            apply(cat, true);
        });
    });

    if (input) input.addEventListener('input', function () { apply(currentCat(), false); });
    apply(currentCat(), false);
})();
</script>

<?php
page_footer();
