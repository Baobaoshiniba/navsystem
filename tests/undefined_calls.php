<?php
/**
 * Run: php tests/undefined_calls.php
 *
 * 检查每个入口页面「调用了但拿不到」的函数。
 *
 * 光看「整个项目里有没有定义」是不够的：flash() 明明定义在 admin/_init.php 里，
 * 但 admin/install.php 只 require 了 config.php（它不能引 _init.php —— 那里一进来
 * 就 require_login()，会把还没建号的站长弹到登录页，形成死循环）。
 * 结果就是每次全新安装建号成功后，flash() 直接抛 Error，
 * 被 catch 拦下渲染成错误页 —— 账号其实已经建好了，站长却以为失败了。
 *
 * 这类问题的共同特征是：函数存在，但不在当前入口的引入链上。
 * 所以这里按「入口 → 递归解析 require → 可达文件集合 → 这些文件里定义的函数」
 * 来判定，而不是全局搜一遍了事。
 */
declare(strict_types=1);

$root = dirname(__DIR__);

/** 入口页面：会被 HTTP 直接请求的那些，不含被包含的库文件 */
$entries = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/admin/*.php') ?: []
);
$entries = array_values(array_filter($entries, static function (string $f) use ($root): bool {
    // router.php 由内置服务器在启动时加载，也算入口
    return substr($f, -4) === '.php';
}));

/** 递归收集一个文件在执行时能保证被加载的所有文件 */
function reachable_files(string $file, array &$seen = []): array
{
    $real = realpath($file);
    if ($real === false || isset($seen[$real])) {
        return $seen;
    }
    $seen[$real] = true;

    // 按 token 找 require：不能用正则。
    // config.php 的注释里就有「require_once 本文件」这类说明文字，
    // 正则会跨行贪婪匹配，把注释和真正的语句粘成一坨（lib/migrate.php 就是这么被漏掉的）。
    $tokens = token_get_all((string)file_get_contents($real));
    $exprs  = [];
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if (!is_array($token)
            || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $parts = [];
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === ';') {
                break;
            }
            $parts[] = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $exprs[] = trim(implode('', $parts));
    }

    foreach ($exprs as $expr) {
        $expr = trim($expr);
        // 把 __DIR__ 换成实际目录再求值；只处理常量字符串拼接这一种写法
        $eval = str_replace('__DIR__', var_export(dirname($real), true), $expr);
        if (strpos($eval, 'glob(') !== false) {
            // 形如 foreach (glob(__DIR__.'/lib/*.php') as $f) require $f;
            // 静态求不出值，但目录是确定的，整目录都算可达
            if (preg_match("#'([^']*/lib/\*\.php)'#", $eval, $g) === 1) {
                $dir = dirname(substr($g[1], 0, strpos($g[1], '*') + 1));
                foreach (glob($dir . '*.php') ?: [] as $lib) {
                    reachable_files($lib, $seen);
                }
            }
            continue;
        }
        if (!preg_match('#^[A-Za-z0-9_\'\"\.\/\$\(\)\s\-]+$#', $eval)) {
            continue;   // 太动态，跳过（不判失败，避免误报）
        }
        try {
            $target = @eval('return ' . $eval . ';');
        } catch (Throwable $e) {
            continue;
        }
        if (is_string($target) && $target !== '') {
            reachable_files($target, $seen);
        }
    }
    return $seen;
}

/** 收集一组文件里定义的函数名 */
function defined_functions(array $files): array
{
    $names = [];
    foreach (array_keys($files) as $file) {
        $tokens = token_get_all((string)file_get_contents($file));
        $afterFunction = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $afterFunction = true;
                continue;
            }
            if (!$afterFunction) {
                continue;
            }
            // 函数名前面可能有空白（function db()）与引用符号（function &foo()），
            // 这两个都得放过 —— 直接清状态的话就一个函数也认不出来。
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            if ($token === '&') {
                continue;
            }
            if (is_array($token) && $token[0] === T_STRING) {
                $names[strtolower($token[1])] = true;
                $afterFunction = false;
                continue;
            }
            $afterFunction = false;
        }
    }
    return $names;
}

/** 收集一个文件里「以函数形式调用」的名字 */
function called_functions(string $file): array
{
    $tokens = token_get_all((string)file_get_contents($file));
    $names  = [];
    $skipPrev = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        // 后面（跳过空白与注释）必须紧跟左括号，才算函数调用
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j])
            && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= count($tokens) || $tokens[$j] !== '(') {
            continue;
        }
        // 前面是 -> :: new function 的都不是普通函数调用
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k--;
        }
        if ($k >= 0 && is_array($tokens[$k]) && in_array($tokens[$k][0], $skipPrev, true)) {
            continue;
        }
        $names[strtolower($token[1])] = $token[1];
    }
    return $names;
}

$internal = [];
foreach (get_defined_functions()['internal'] as $fn) {
    $internal[strtolower($fn)] = true;
}
// 语言结构：token 化后也表现为「名字 + 左括号」，但不是函数
foreach (['array', 'list', 'isset', 'empty', 'unset', 'exit', 'die', 'echo', 'print', 'declare', 'define', 'eval'] as $kw) {
    $internal[$kw] = true;
}

$failures = [];
foreach ($entries as $entry) {
    $reach = reachable_files($entry);
    $available = defined_functions($reach);
    // 同一个入口自己也定义函数（例如 admin/index.php 里的纯计算函数）
    $missing = [];
    foreach (called_functions($entry) as $lower => $display) {
        if (isset($available[$lower]) || isset($internal[$lower])) {
            continue;
        }
        $missing[] = $display;
    }
    if ($missing !== []) {
        $failures[] = str_replace($root . '/', '', $entry) . ' -> ' . implode(', ', $missing);
    }
}

if ($failures !== []) {
    throw new RuntimeException(
        'These entry points call functions that are never loaded on their include chain:' . PHP_EOL
        . '  ' . implode(PHP_EOL . '  ', $failures) . PHP_EOL
        . '要么是缺了 require，要么是函数放错了文件（挪到 config.php / lib/ 下对所有入口都可见）。'
    );
}

echo 'PASS: every entry point only calls functions reachable through its own include chain' . PHP_EOL;
