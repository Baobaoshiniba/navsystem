<?php
/**
 * 后台 - 点击统计
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();

$db = db();

// 汇总
$summary = $db->query('
    SELECT COUNT(*)                       AS total_links,
           COALESCE(SUM(click_count), 0)  AS total_clicks,
           COALESCE(AVG(click_count), 0)  AS avg_clicks,
           MAX(click_count)               AS max_clicks
    FROM links
')->fetch();

// 分类维度
$byCategory = $db->query('
    SELECT c.name, c.name_en,
           COUNT(l.id)                    AS link_count,
           COALESCE(SUM(l.click_count),0) AS clicks
    FROM categories c
    LEFT JOIN links l ON l.category_id = c.id
    GROUP BY c.id
    ORDER BY clicks DESC, c.sort_order ASC
')->fetchAll();

// 链接 TOP 排行
$topLinks = $db->query('
    SELECT l.title, l.title_en, l.url, l.icon, l.click_count, c.name AS category_name, c.name_en AS category_name_en
    FROM links l
    LEFT JOIN categories c ON c.id = l.category_id
    ORDER BY l.click_count DESC, l.id ASC
    LIMIT 100
')->fetchAll();

admin_header(t('点击统计'), 'stats');

$fmt = function ($v): string {
    return number_format((float)$v);
};
?>
<div class="page-title-row">
    <h1 class="page-title"><?= e(t('点击统计')) ?></h1>
    <p class="page-sub"><?= e(t('汇总展示所有导航链接的点击数据，便于了解高频资源。')) ?></p>
</div>

<div class="stat-cards">
    <div class="stat-card glass"><span class="sc-ico">🔗</span><div class="sc-body"><div class="stat-num grad-text"><?= $fmt($summary['total_links']) ?></div><div class="stat-label"><?= e(t('导航链接总数')) ?></div></div></div>
    <div class="stat-card glass"><span class="sc-ico">👆</span><div class="sc-body"><div class="stat-num grad-text"><?= $fmt($summary['total_clicks']) ?></div><div class="stat-label"><?= e(t('累计点击')) ?></div></div></div>
    <div class="stat-card glass"><span class="sc-ico">📊</span><div class="sc-body"><div class="stat-num grad-text"><?= $fmt($summary['avg_clicks']) ?></div><div class="stat-label"><?= e(t('平均点击数')) ?></div></div></div>
    <div class="stat-card glass"><span class="sc-ico">🏆</span><div class="sc-body"><div class="stat-num grad-text"><?= $fmt($summary['max_clicks']) ?></div><div class="stat-label"><?= e(t('最高单链接点击')) ?></div></div></div>
</div>

<div class="two-col stats-split">
    <section class="panel glass">
        <h2 class="panel-title"><?= e(t('分类点击分布')) ?></h2>
        <?php if (!$byCategory): ?>
            <div class="empty-tip"><span class="et-ico">📊</span><p><?= e(t('暂无分类点击数据')) ?></p></div>
        <?php else: ?>
        <?php $maxCat = max(array_map(fn($r) => (int)$r['clicks'], $byCategory) ?: [1]); ?>
        <ul class="bar-list">
            <?php foreach ($byCategory as $row): ?>
                <?php $pct = $maxCat > 0 ? round(((int)$row['clicks'] / $maxCat) * 100) : 0; ?>
                <li class="bar-item">
                    <div class="bar-head">
                        <span><?= e(localized_field($row, 'name')) ?>（<?= (int)$row['link_count'] ?> <?= e(t('链接）')) ?></span>
                        <span class="bar-val"><?= $fmt($row['clicks']) ?> <?= e(t('次')) ?></span>
                    </div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="panel glass">
        <h2 class="panel-title"><?= e(t('链接点击 TOP')) ?></h2>
        <?php if (!$topLinks): ?>
            <div class="empty-tip"><span class="et-ico">🏆</span><p><?= e(t('暂无链接点击数据')) ?></p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="data-table stats-table">
            <thead><tr><th>#</th><th><?= e(t('链接')) ?></th><th><?= e(t('分类')) ?></th><th><?= e(t('点击')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($topLinks as $rank => $link): ?>
                <tr>
                    <td><?= $rank + 1 ?></td>
                    <td class="cell-name"><?= e($link['icon'] ?: '🔖') ?> <?= e(localized_field($link, 'title')) ?>
                        <div class="sub-text"><a href="<?= e($link['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($link['url']) ?>"><?= e($link['url']) ?></a></div>
                    </td>
                    <td><?= e(localized_field($link, 'category_name') ?: '—') ?></td>
                    <td class="stat-click"><?= $fmt($link['click_count']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</div>
<?php admin_footer(); ?>
