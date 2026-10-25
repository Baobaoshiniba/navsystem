<?php
/**
 * 点击统计接口（前台无刷新计数）
 *
 * 现已加上防护，详见 config.php 的 click_is_allowed() / click_mark()：
 *   - 同一客户端对同一链接，在 click_dedup_window 窗口内只计一次；
 *   - 同一客户端每小时最多对 click_max_links_per_hour 个不同链接计数。
 * 这样避免了「任何人都能把任意链接刷到任意数值」的失真问题。
 *
 * 注意：本接口是 GET 且有意保持无需登录（前台卡片点击要对匿名访客生效），
 *       安全性依赖上述去重与限流，不依赖鉴权。
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id !== null && $id !== false && $id > 0) {
    if (click_is_allowed($id)) {
        $stmt = db()->prepare('UPDATE links SET click_count = click_count + 1 WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        // 只有确实命中了某条启用中的链接才登记去重戳；
        // 否则探测不存在的 ID 会往去重表里塞无用行。
        if ($stmt->rowCount() > 0) {
            click_mark($id);
        }
    }
}

http_response_code(204);
header('Content-Type: image/gif');
// 返回 1x1 透明像素，兼容 sendBeacon / Image 两种方式
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
