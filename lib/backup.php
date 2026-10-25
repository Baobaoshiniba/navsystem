<?php
/**
 * 公司导航页 - 数据库备份
 *
 * 存在的原因：「重置演示 / 清空为空白」是不可逆操作，原先 README 承诺「重置前自动备份」，
 * 但代码里根本没有这段逻辑 —— 文档承诺了一个不存在的安全网。这里把它补上。
 *
 * 快照方式用 SQLite 的 VACUUM INTO（3.27+），而不是直接 copy 数据库文件：
 * 本项目数据库开的是 WAL 模式，直接复制主库文件可能漏掉仍在 -wal 里的已提交事务，
 * 拿到的快照未必完整。VACUUM INTO 由数据库自己产出一致快照，不受 WAL 与并发影响。
 *
 * 备份落在 data/backups/，随该目录一起受三层封堵保护（见 README「运行时数据保护」），
 * 且 data/* 已被 .gitignore 排除，不会进仓库。
 */

declare(strict_types=1);

// 允许本文件被单独引入（例如测试脚本）；已由 config.php 加载时不会重复执行。
require_once dirname(__DIR__) . '/config.php';

/** 保留的备份份数上限，超出后删最旧的 */
const BACKUP_KEEP_MAX = 10;

/**
 * 备份目录。测试可以在 require config.php 之前 define 这个常量指到临时目录，
 * 避免把真实的 data/backups/ 弄脏 —— 清理逻辑是删文件的，隔离不了就会误伤。
 */
if (!defined('NAV_BACKUP_DIR')) {
    define('NAV_BACKUP_DIR', NAV_ROOT . '/data/backups');
}

/**
 * 备份目录路径
 */
function backup_dir(): string
{
    return NAV_BACKUP_DIR;
}

/** 本模块生成的备份文件名前缀；清理只认这个前缀 */
const BACKUP_FILE_PREFIX = 'before-';

/** 清理时匹配的通配模式 */
function backup_glob_pattern(): string
{
    return backup_dir() . '/' . BACKUP_FILE_PREFIX . '*.sqlite';
}

/**
 * 生成一份数据库快照
 *
 * @param string $label 便于辨识的前缀，例如 'reset'
 * @return string 成功返回备份文件绝对路径；失败返回空串（备份失败不应阻断业务）
 */
function backup_create(string $label = ''): string
{
    $dir = backup_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return '';
    }

    $prefix = $label !== '' ? preg_replace('/[^a-z0-9_-]+/i', '-', $label) . '-' : '';
    $dest   = $dir . '/' . BACKUP_FILE_PREFIX . $prefix . date('Ymd-His') . '.sqlite';

    try {
        $pdo = db();
        // VACUUM INTO 要求目标文件不存在；时间戳命名下冲突概率极低，仍兜一层
        if (is_file($dest)) {
            $dest = $dir . '/' . BACKUP_FILE_PREFIX . $prefix . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        }
        $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
    } catch (Throwable $e) {
        // 备份失败不阻断主流程：重置本身是用户明确确认过的动作，
        // 因为备份没做成而拒绝执行反而更糟。
        return '';
    }

    if (!is_file($dest)) {
        return '';
    }
    @chmod($dest, 0600);
    backup_prune();

    return $dest;
}

/**
 * 清理超出保留上限的旧备份（按修改时间，删最旧的）
 *
 * 只清理本模块自己生成的 before-*.sqlite。目录里若有手工放进去的别的备份，
 * 不该被自动清理悄悄删掉。
 */
function backup_prune(int $keep = BACKUP_KEEP_MAX): void
{
    if ($keep < 1) {
        $keep = 1;
    }
    $files = glob(backup_glob_pattern()) ?: [];
    if (count($files) <= $keep) {
        return;
    }
    usort($files, static function (string $a, string $b): int {
        return filemtime($a) <=> filemtime($b);
    });
    foreach (array_slice($files, 0, count($files) - $keep) as $old) {
        @unlink($old);
    }
}
