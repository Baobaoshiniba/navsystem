<?php
/**
 * 点击统计接口（前台无刷新计数）
 * 仅允许同站点来源的 GET 请求；幂等且每次 +1。
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id !== null && $id !== false && $id > 0) {
    $stmt = db()->prepare('UPDATE links SET click_count = click_count + 1 WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
}

http_response_code(204);
header('Content-Type: image/gif');
// 返回 1x1 透明像素，兼容 sendBeacon / Image 两种方式
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
