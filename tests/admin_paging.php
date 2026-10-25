<?php
/**
 * Run: php tests/admin_paging.php
 *
 * 覆盖后台「链接管理」的分页回跳：
 *   - calc_record_page()：排序（上移/下移/置顶）之后要跳回该记录所在的那一页
 *   - clamp_page_for_total()：删除导致当前页被删空时要回退到上一页
 *   - list_url() / edit_url()：筛选与页码上下文不丢失
 *
 * 这几个函数是 admin/index.php 的「页面内私有函数」。页面一被 require 就会先跑
 * require_login()（未登录直接 redirect + exit），CLI 下没法直接加载。
 *
 * 所以这里不是把函数抄一份过来 —— 抄过来的副本在源码改动后照样是绿的，等于没测试。
 * 改成按锚点从 admin/index.php 里切出「纯计算区」（const PAGE_SIZE 起到 $db = db(); 之前）
 * 再 eval：源码改了，被测代码跟着改；锚点要是被人挪没了，下面的断言会直接红。
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/* ---------- 加载被测代码（源码切片，非副本） ---------- */
$src   = (string)file_get_contents(NAV_ROOT . '/admin/index.php');
$start = strpos($src, 'const PAGE_SIZE');
$stop  = strpos($src, '$db = db();');
check($start !== false, 'admin/index.php must still define the PAGE_SIZE constant');
check($stop !== false && $stop > $start,
    'admin/index.php must still keep the pure helpers above the "$db = db();" line');

$slice = substr($src, $start, $stop - $start);
foreach (['list_url', 'edit_url', 'calc_record_page', 'clamp_page_for_total'] as $fn) {
    check(strpos($slice, 'function ' . $fn) !== false,
        'The pure-helper slice must contain ' . $fn . '() — the anchors moved, update this test');
}
// 排序口径：这里只断言「两个排序键都还在用」，不去断言比较方向。
// 早先连 <= / < 一起断言，结果纯逻辑写错时它先红，下面的运行时断言根本走不到 ——
// 看起来被测代码有覆盖，其实是被一条文本匹配挡在了门外。
// 分工改成：文本断言只守锚点没被挪走，算得对不对一律由运行时断言判定。
check(strpos($slice, 'cur.sort_order') !== false && strpos($slice, 'cur.id') !== false,
    'calc_record_page() must still rank by (sort_order, id)');
check(strpos($src, 'ORDER BY l.sort_order ASC, l.id ASC') !== false,
    'The list query must order by sort_order ASC, id ASC to match calc_record_page()');

eval($slice);

check(PAGE_SIZE === 8, 'PAGE_SIZE is expected to stay at 8 in this test');

$file = tempnam(sys_get_temp_dir(), 'nav-page-');
try {
    $db_config['file'] = $file;
    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys = ON');

    // 临时库是「首次运行」，会自动播种演示数据。清干净再铺自己的，
    // 否则 id 与 sort_order 都被演示数据占着，页码断言全乱。
    $pdo->exec('DELETE FROM links');
    $pdo->exec('DELETE FROM categories');

    /* ---------- 铺数据：2 个分类 × 各 10 条，交错插入 ---------- */
    $pdo->prepare('INSERT INTO categories (name, name_en, icon, sort_order) VALUES (?,?,?,?)')
        ->execute(['甲', 'A', 'folder', 1]);
    $catA = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO categories (name, name_en, icon, sort_order) VALUES (?,?,?,?)')
        ->execute(['乙', 'B', 'folder', 2]);
    $catB = (int)$pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO links (category_id, title, url, sort_order, is_active) VALUES (?,?,?,?,1)'
    );
    // sort_order 与 id 保持一致（1..20），这样「排名 = id - 1」便于直接心算预期值
    for ($i = 1; $i <= 20; $i++) {
        $insert->execute([$i % 2 === 1 ? $catA : $catB, '链接' . $i, 'https://e.example/' . $i, $i]);
    }

    /**
     * 复刻页面列表查询：给定筛选与页码，返回该页实际渲染出来的 id 列表。
     * 「算出来的页码」对不对，最终得由「这一页里到底有没有这条记录」来判定。
     */
    $pageIds = static function (PDO $pdo, int $cat, int $page): array {
        $sql = 'SELECT l.id FROM links l LEFT JOIN categories c ON c.id = l.category_id WHERE 1=1';
        $params = [];
        if ($cat > 0) {
            $sql .= ' AND l.category_id = ?';
            $params[] = $cat;
        }
        $sql .= ' ORDER BY l.sort_order ASC, l.id ASC LIMIT ' . PAGE_SIZE
              . ' OFFSET ' . (($page - 1) * PAGE_SIZE);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    };

    /* ================= 1. 全部分类（cat=0）下的页边界 ================= */
    // 20 条 / 每页 8 → 页1: 1-8，页2: 9-16，页3: 17-20
    check(calc_record_page($pdo, 1, 0) === 1, 'Rank 0 must land on page 1');
    check(calc_record_page($pdo, 8, 0) === 1, 'Rank 7 (last of page 1) must land on page 1');
    check(calc_record_page($pdo, 9, 0) === 2, 'Rank 8 (first of page 2) must land on page 2');
    check(calc_record_page($pdo, 16, 0) === 2, 'Rank 15 (last of page 2) must land on page 2');
    check(calc_record_page($pdo, 17, 0) === 3, 'Rank 16 (first of page 3) must land on page 3');
    check(calc_record_page($pdo, 20, 0) === 3, 'The last record must land on the last page');

    // 每一条记录：算出的页码上必须真能看见它（这一条能兜住所有 off-by-one）
    for ($id = 1; $id <= 20; $id++) {
        $p = calc_record_page($pdo, $id, 0);
        check(in_array($id, $pageIds($pdo, 0, $p), true),
            'Record ' . $id . ' must be visible on its computed page ' . $p . ' (all)');
    }

    /* ================= 2. 分类筛选下的排名 ================= */
    // catA 里是 id 1,3,5,...,19（10 条），按 sort_order(=id) 升序
    check(calc_record_page($pdo, 1, $catA) === 1, 'In-cat rank 0 must be page 1');
    check(calc_record_page($pdo, 15, $catA) === 1, 'In-cat rank 7 must be page 1');
    check(calc_record_page($pdo, 17, $catA) === 2, 'In-cat rank 8 must roll to page 2');
    check(calc_record_page($pdo, 19, $catA) === 2, 'The last in-cat record must be on page 2');

    $catAIds = [];
    for ($id = 1; $id <= 20; $id += 2) {
        $catAIds[] = $id;
        $p = calc_record_page($pdo, $id, $catA);
        check(in_array($id, $pageIds($pdo, $catA, $p), true),
            'Record ' . $id . ' must be visible on its computed page ' . $p . ' (category A)');
    }
    // 筛选作用域必须排除另一个分类：全部视图第 2 页的头几条（9/10 属 catB）
    // 在 catA 视图里不应被算进来
    check(calc_record_page($pdo, 9, $catA) === 1,
        'A record from another category must not shift the in-category ranking');

    /* ================= 3. 排序动作之后的回跳 ================= */
    // 3a. 下移跨页：第 8 条（页1 末位）与第 9 条（页2 首位）交换 sort_order，
    //     它自己移到 rank 8 → 必须回跳到第 2 页，否则用户「点下移却看不见变化」。
    $pdo->exec('UPDATE links SET sort_order = 9 WHERE id = 8');
    $pdo->exec('UPDATE links SET sort_order = 8 WHERE id = 9');
    check(calc_record_page($pdo, 8, 0) === 2,
        'Moving down across a page boundary must jump to page 2');
    check(in_array(8, $pageIds($pdo, 0, calc_record_page($pdo, 8, 0)), true),
        'The moved record must actually be rendered on the page we jump to');

    // 3b. 置顶：把它挪到 sort_order 最小 → 必须回到第 1 页
    $pdo->exec('UPDATE links SET sort_order = 0 WHERE id = 8');
    check(calc_record_page($pdo, 8, 0) === 1, 'Pinning to top must jump back to page 1');
    check(in_array(8, $pageIds($pdo, 0, 1), true),
        'The pinned record must be the first thing on page 1');

    // 3c. sort_order 撞值时的稳定兜底：全部置成同一个 sort_order，
    //     排名必须回退到按 id 排（否则多条记录算出同一个 rank，页码会乱跳）
    $pdo->exec('UPDATE links SET sort_order = 5');
    check(calc_record_page($pdo, 1, 0) === 1, 'Tied sort_order must fall back to id ordering (rank 0)');
    check(calc_record_page($pdo, 9, 0) === 2, 'Tied sort_order must fall back to id ordering (rank 8)');
    check(calc_record_page($pdo, 20, 0) === 3, 'Tied sort_order must fall back to id ordering (rank 19)');
    for ($id = 1; $id <= 20; $id++) {
        $p = calc_record_page($pdo, $id, 0);
        check(in_array($id, $pageIds($pdo, 0, $p), true),
            'Under tied sort_order, record ' . $id . ' must still land on page ' . $p);
    }

    /* ================= 4. 异常输入不得炸、不得返回非法页码 ================= */
    // 记录已不存在（比如并发下被另一个人删了）：COUNT 为 0，rank 变 -1
    check(calc_record_page($pdo, 9999, 0) === 1,
        'A missing record must fall back to page 1 instead of returning 0 or a negative page');
    // 结果集为空
    $pdo->exec('DELETE FROM links');
    check(calc_record_page($pdo, 1, 0) === 1, 'An empty table must not break the page calculation');

    /* ================= 5. clamp_page_for_total ================= */
    check(clamp_page_for_total(20, 3) === 3, 'A valid page must be left alone');
    check(clamp_page_for_total(20, 99) === 3, 'A page past the end must clamp to the last page');
    check(clamp_page_for_total(20, 0) === 1, 'Page 0 must clamp to 1');
    check(clamp_page_for_total(20, -5) === 1, 'A negative page must clamp to 1');
    check(clamp_page_for_total(0, 1) === 1, 'An empty result set must stay on page 1');
    check(clamp_page_for_total(0, 7) === 1, 'Deleting everything must fall back to page 1');
    check(clamp_page_for_total(8, 1) === 1, 'Exactly one full page must not create a second page');
    check(clamp_page_for_total(9, 1) === 1, 'The 9th record starts page 2, page 1 stays valid');
    // 真实场景：第 3 页只有 4 条（total=20），删掉 1 条后 total=19 → 仍是 3 页；
    // 再删到 16 条 → 只剩 2 页，原本停在第 3 页就必须回退到第 2 页
    check(clamp_page_for_total(19, 3) === 3, 'Losing one of four trailing records keeps page 3');
    check(clamp_page_for_total(16, 3) === 2, 'When the last page disappears we must step back');

    /* ================= 6. URL 上下文 ================= */
    check(list_url() === 'index.php', 'Default list URL must be bare');
    check(list_url(0, 1) === 'index.php', 'cat=0 and page=1 must not add query params');
    check(list_url(3, 1) === 'index.php?cat=3', 'The category filter must survive');
    check(list_url(0, 2) === 'index.php?page=2', 'The page number must survive');
    check(list_url(3, 2) === 'index.php?cat=3&page=2', 'Both filters must survive together');
    check(edit_url(7) === 'index.php?edit=7', 'Edit URL must carry the id');
    check(edit_url(7, 3, 2) === 'index.php?edit=7&cat=3&page=2', 'Edit URL must carry the context');

    echo 'PASS: paging jump-back, category-scoped ranking, tie-break ordering, delete clamp and URL context' . PHP_EOL;
} finally {
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($file . $suffix)) {
            unlink($file . $suffix);
        }
    }
}
