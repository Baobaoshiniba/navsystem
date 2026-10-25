<?php
/**
 * Run: php tests/backup.php
 *
 * 覆盖数据库备份：快照能生成且可被独立打开、内容是完整的、
 * 保留份数上限会删最旧的。
 *
 * 背景：「重置演示 / 清空为空白」不可逆转，README 一直写着「重置前自动备份」，
 * 但代码里并没有这段逻辑。补上实现后由本测试守住。
 *
 * 注意：备份目录在 require config.php 之前就被指到临时目录了。
 * 清理逻辑是要删文件的，不隔离就会把真实的 data/backups/ 弄脏（这一点踩过）。
 */
declare(strict_types=1);

$tmpBackup = sys_get_temp_dir() . '/nav-backup-test-' . bin2hex(random_bytes(4));
mkdir($tmpBackup, 0700, true);
define('NAV_BACKUP_DIR', $tmpBackup);

require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$file = tempnam(sys_get_temp_dir(), 'nav-backup-');
$snapshots = [];

try {
    $db_config['file'] = $file;
    $pdo = db();
    $pdo->exec('PRAGMA journal_mode=WAL');          // 与生产库一致，验证 WAL 下快照也完整
    $pdo->exec('CREATE TABLE t (a TEXT)');
    $pdo->exec("INSERT INTO t VALUES ('before-reset')");

    $dir = backup_dir();
    check($dir === $tmpBackup, 'The backup dir must honour the override');
    check(strpos($dir, '/data/backups') === false, 'Tests must never touch the real backup dir');

    $snap = backup_create('reset');
    check($snap !== '', 'backup_create must return a path');
    check(is_file($snap), 'The snapshot file must exist');
    $snapshots[] = $snap;

    // 快照必须能被独立打开，且包含备份那一刻的数据
    $copy = new PDO('sqlite:' . $snap);
    check((int)$copy->query('SELECT COUNT(*) FROM t')->fetchColumn() === 1,
        'The snapshot must contain the data from backup time');
    check($copy->query('SELECT a FROM t')->fetchColumn() === 'before-reset',
        'The snapshot content must match');
    $copy = null;

    // 快照是静态的：之后对源库的改动不应影响它
    $pdo->exec("INSERT INTO t VALUES ('after-reset')");
    $copy = new PDO('sqlite:' . $snap);
    check((int)$copy->query('SELECT COUNT(*) FROM t')->fetchColumn() === 1,
        'The snapshot must not change after the source keeps mutating');
    $copy = null;

    /* ---------- 保留份数上限 ---------- */
    // 前面那份快照已经验证完了，先清掉，让保留上限的断言不被它干扰
    foreach ($snapshots as $done) {
        if (is_file($done)) {
            unlink($done);
        }
    }
    $snapshots = [];

    // 直接造一批带不同 mtime 的假备份：用真实时间戳命名的话文件名按秒走，
    // 得 sleep 十几秒才能稳定区分新旧，不值得。
    $total = BACKUP_KEEP_MAX + 3;
    $stamp = time() - 3600;
    for ($i = 0; $i < $total; $i++) {
        $fake = $dir . '/before-prune-fake-' . $i . '.sqlite';
        file_put_contents($fake, 'x');
        touch($fake, $stamp + $i);      // $i 越大越新
        $snapshots[] = $fake;
    }
    backup_prune(BACKUP_KEEP_MAX);

    $left = glob($dir . '/before-prune-fake-*.sqlite') ?: [];
    check(count($left) === BACKUP_KEEP_MAX,
        'Pruning must keep exactly ' . BACKUP_KEEP_MAX . ', found ' . count($left));
    // 删的必须是最旧的：fake-0 / 1 / 2 消失，最新的留着
    check(!is_file($dir . '/before-prune-fake-0.sqlite'), 'The oldest backup must be deleted first');
    check(!is_file($dir . '/before-prune-fake-2.sqlite'), 'The third-oldest backup must be deleted');
    check(is_file($dir . '/before-prune-fake-' . ($total - 1) . '.sqlite'),
        'The newest backup must survive pruning');

    // 清理只认 before- 前缀：手工放进去的其它备份不该被悄悄删掉
    $manual = $dir . '/manual-keepme.sqlite';
    file_put_contents($manual, 'x');
    touch($manual, time() - 99999);      // 比所有 fake 都旧
    backup_prune(BACKUP_KEEP_MAX);
    check(is_file($manual), 'Pruning must not delete files that are not its own before-*.sqlite');
    unlink($manual);

    echo 'PASS: snapshot integrity, WAL safety, isolation from later writes and retention cap' . PHP_EOL;
} finally {
    $pdo = null;
    // 清掉本测试自己产生的备份，别把 data/backups/ 弄脏
    foreach ($snapshots as $snap) {
        if (is_file($snap)) {
            unlink($snap);
        }
    }
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($file . $suffix)) {
            unlink($file . $suffix);
        }
    }
    // 整个临时备份目录一并收掉
    foreach (glob($tmpBackup . '/*') ?: [] as $left) {
        @unlink($left);
    }
    @rmdir($tmpBackup);
}
