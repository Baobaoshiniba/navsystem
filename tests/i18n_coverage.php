<?php
/**
 * Run: php tests/i18n_coverage.php [--strict]
 *
 * 双语言词条覆盖检查：扫描代码里所有 t() / jt() 的**字面量**中文源串，
 * 找出 lang/en.php 里没有对应英文词条的那些。
 *
 * 新增 UI 文案时最容易漏的就是这一步 —— 漏了不会报错，只是英文界面上突然冒出中文。
 *
 * 判定口径：
 *   - 只检查含中日韩字符的源串（纯英文 / 数字的如 t('{count}') 本来就不需要翻译）
 *   - 只检查字面量调用；t('前缀' . $x) 这类拼接无法静态提取，跳过
 *   - admin/、lib/ 与根目录 *.php 都在扫描范围内；tests/ 自身排除（那是测试数据）
 *   - 默认只报告统计（退出码 0）；--strict 下把「有遗漏」当作失败
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$english = require $root . '/lang/en.php';
$strict = in_array('--strict', $argv ?? [], true);

/** 扫描范围内所有 PHP 文件（排除 tests/ 与 vendor 类目录） */
$files = [];
foreach (['.', 'admin', 'lib'] as $dir) {
    foreach (glob($root . ($dir === '.' ? '' : '/' . $dir) . '/*.php') ?: [] as $file) {
        $files[] = $file;
    }
}

/** 匹配 t('...') 与 jt('...') 的单引号字面量首参 */
$pattern = '/\b(j?t)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/u';

$seen = [];   // 源串 => 首次出现位置
foreach ($files as $file) {
    $code = (string)file_get_contents($file);
    if (preg_match_all($pattern, $code, $m, PREG_SET_ORDER) === false) {
        continue;
    }
    foreach ($m as $match) {
        $key = $match[2];
        // 只关心含 CJK 的源串
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $key) !== 1) {
            continue;
        }
        $rel = ltrim(str_replace($root, '', $file), '/');
        $seen[$key] ??= $rel;
    }
}

$missing = [];
foreach ($seen as $key => $where) {
    if (!array_key_exists($key, $english)) {
        $missing[$key] = $where;
    }
}

ksort($missing, SORT_NATURAL);

if ($missing === []) {
    printf("PASS: %d 个中文源串全部有英文词条\n", count($seen));
    exit(0);
}

printf("发现 %d / %d 个中文源串缺少英文词条：\n", count($missing), count($seen));
foreach ($missing as $key => $where) {
    printf("  [%s] %s\n", $where, $key);
}

if ($strict) {
    fwrite(STDERR, "\n--strict：词条遗漏视为失败。请补进 lang/en.php 后重跑。\n");
    exit(1);
}

printf("\n（默认模式仅报告，不判失败。加上 --strict 可让遗漏变为失败。）\n");
exit(0);
