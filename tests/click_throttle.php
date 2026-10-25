<?php
/**
 * Run: php tests/click_throttle.php
 *
 * 覆盖点击统计的去重与限流。track_click.php 对匿名访客开放，
 * 这条链路一旦失效，后台「点击统计」的数字就不再可信。
 * 使用一次性临时数据库，不影响本地站点数据。
 */
declare(strict_types=1);
require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$file = tempnam(sys_get_temp_dir(), 'nav-click-');
try {
    $db_config['file'] = $file;
    $pdo = db();

    // 测试用窗口：1 小时去重、单 IP 每小时只允许 5 个不同链接
    $config['click_dedup_window']      = 3600;
    $config['click_max_links_per_hour'] = 5;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

    check($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='click_throttle'")
        ->fetchColumn() == 1, 'click_throttle table missing');

    // ① 首次点击允许；同一 IP 在窗口内重复点同一链接应被拒绝
    check(click_is_allowed(1) === true, 'First click on a link must be allowed');
    click_mark(1);
    check(click_is_allowed(1) === false, 'Duplicate click within window must be rejected');
    check(click_is_allowed(1) === false, 'Repeat duplicates must stay rejected');

    // ② 同一 IP 点另一个链接不受影响
    check(click_is_allowed(2) === true, 'A different link must still be allowed');
    click_mark(2);

    // ③ 换一个客户端 IP，同一链接应重新允许
    $_SERVER['REMOTE_ADDR'] = '203.0.113.6';
    check(click_is_allowed(1) === true, 'A different client must be counted separately');
    click_mark(1);

    // ④ 每小时不同链接数上限：先把预算用到位
    $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
    for ($i = 1; $i <= 5; $i++) {
        check(click_is_allowed($i) === true, "Link {$i} should be within the hourly budget");
        click_mark($i);
    }
    check(click_is_allowed(6) === false, 'Hourly per-client budget must be enforced');

    // ⑤ 非法 id 一律拒绝
    check(click_is_allowed(0) === false, 'Zero id must be rejected');
    check(click_is_allowed(-3) === false, 'Negative id must be rejected');

    // ⑥ 两层限制都显式关闭时，回退到「每次都计数」的旧行为
    $config['click_dedup_window']       = 0;
    $config['click_max_links_per_hour'] = 0;
    for ($i = 1; $i <= 5; $i++) {
        check(click_is_allowed(7) === true, 'Disabled limits must allow every request');
    }

    // ⑦ 计数后的清理不应误删新鲜记录
    $config['click_dedup_window'] = 3600;
    $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
    check(click_is_allowed(1) === false, 'Fresh records must survive cleanup');

    echo "PASS: click deduplication, hourly budget, per-client isolation and opt-out\n";
} finally {
    $pdo = null;
    foreach (['', '-wal', '-shm'] as $suffix) if (is_file($file . $suffix)) unlink($file . $suffix);
}
