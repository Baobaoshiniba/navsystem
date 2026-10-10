<?php
/**
 * 后台 - 图标抓取接口（JSON）
 *
 * 两个动作，均为 POST + CSRF 保护，供链接管理页调用：
 *   - action=single：单条重抓（id）
 *   - action=batch ：批量刷新，按 id 游标分批推进（cursor / limit / scope），前端循环调用直到 done，
 *                    单次请求只处理少量记录，避免阻塞页面与请求超时
 *
 * 抓取逻辑全部收敛在 lib/favicon.php，本文件只负责编排与统计。
 */

declare(strict_types=1);

require __DIR__ . '/_init.php';
require_login();

/** 单次批量请求最多处理的链接数（防止请求过大） */
const ICON_BATCH_MAX = 5;

if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

/** 输出 JSON 并结束 */
function icon_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 组装单条链接的前端可读结果（admin/ 相对路径） */
function icon_item_payload(array $link, array $result): array
{
    $link['icon_file']    = (string)($result['file'] ?? '');
    $link['icon_status']  = ($result['ok'] ?? false) ? 'ok' : 'fail';
    $cacheRel = link_icon_url($link);
    return [
        'id'       => (int)($link['id'] ?? 0),
        'title'    => (string)($link['title'] ?? ''),
        'ok'       => (bool)($result['ok'] ?? false),
        'status'   => (string)($result['status'] ?? ''),
        'source'   => (string)($result['source'] ?? ''),
        'message'  => (string)($result['message'] ?? ''),
        'icon'     => $cacheRel === '' ? '' : '../' . $cacheRel,
        'letter'   => link_icon_letter($link),
        'hue'      => link_icon_hue($link),
    ];
}

$db = db();

if (!is_post()) {
    icon_json(['ok' => false, 'error' => '仅支持 POST 请求']);
}
guard_csrf();

// 鉴权与 CSRF 校验已完成，下面进入耗时抓取：先释放 session 写锁，
// 避免同一浏览器其它后台请求被这把锁阻塞（本文件后续不再写 $_SESSION）
release_session_write();

$action = post('action');

/* ---------- 单条重抓 ---------- */
if ($action === 'single') {
    $id = (int)post('id', '0');
    $stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
    $stmt->execute([$id]);
    $link = $stmt->fetch();
    if (!$link) {
        icon_json(['ok' => false, 'error' => '链接不存在']);
    }
    try {
        $result = icon_fetch_for_link($db, $link, ['force' => true]);
    } catch (Throwable $e) {
        $result = ['ok' => false, 'file' => '', 'source' => '', 'status' => 'fail', 'message' => '抓取异常：' . $e->getMessage()];
    }
    icon_json(['ok' => true, 'item' => icon_item_payload($link, $result)]);
}

/* ---------- 批量刷新（游标分批） ---------- */
if ($action === 'batch') {
    $cursor = max(0, (int)post('cursor', '0'));
    $limit  = (int)post('limit', '3');
    $limit  = max(1, min(ICON_BATCH_MAX, $limit));
    $scope  = post('scope') === 'missing' ? 'missing' : 'all';

    $total = (int)$db->query('SELECT COUNT(*) FROM links')->fetchColumn();

    // scope=missing 只处理「无有效缓存 / 上次抓取失败」的链接
    $where = ' WHERE id > ?';
    if ($scope === 'missing') {
        $where .= " AND (icon_status IS NULL OR icon_status <> 'ok' OR icon_file IS NULL OR icon_file = '')";
    }
    $stmt = $db->prepare('SELECT * FROM links' . $where . ' ORDER BY id ASC LIMIT ' . $limit);
    $stmt->execute([$cursor]);
    $rows = $stmt->fetchAll();

    $items = [];
    $okCount = 0;
    $failCount = 0;
    foreach ($rows as $link) {
        try {
            $result = icon_fetch_for_link($db, $link, ['force' => true]);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'file' => '', 'source' => '', 'status' => 'fail', 'message' => '抓取异常：' . $e->getMessage()];
        }
        if ($result['ok']) {
            $okCount++;
        } else {
            $failCount++;
        }
        $items[] = icon_item_payload($link, $result);
        $cursor  = (int)$link['id'];
    }

    // 还有没有剩余：按剩余条数判断（含 scope 过滤）
    $restSql = 'SELECT COUNT(*) FROM links' . ($scope === 'missing'
        ? " WHERE id > ? AND (icon_status IS NULL OR icon_status <> 'ok' OR icon_file IS NULL OR icon_file = '')"
        : ' WHERE id > ?');
    $stmtRest = $db->prepare($restSql);
    $stmtRest->execute([$cursor]);
    $remaining = (int)$stmtRest->fetchColumn();

    icon_json([
        'ok'        => true,
        'processed' => count($items),
        'success'   => $okCount,
        'failed'    => $failCount,
        'cursor'    => $cursor,
        'remaining' => $remaining,
        'total'     => $total,
        'done'      => $remaining <= 0,
        'items'     => $items,
    ]);
}

icon_json(['ok' => false, 'error' => '未知操作']);
