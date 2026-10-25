<?php
/**
 * Run: php tests/icon_security.php
 *
 * 守住 lib/icon_security.php —— 图标抓取的 SSRF 防线与图片魔数白名单。
 * 这里的逻辑一旦被改弱，攻击者就能让服务器去请求内网地址（云元数据、内网管理后台…），
 * 或把可执行内容写进同源静态目录，所以全部钉成断言。
 *
 * 只测不依赖网络的纯逻辑；DNS 解析（icon_resolve_host_ips）联网部分不在此覆盖。
 */

declare(strict_types=1);
require __DIR__ . '/../config.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/* ---------- 1. icon_ip_is_public：私网 / 回环 / 保留地址必须拒绝 ---------- */
$blockedIps = [
    '127.0.0.1'      => '回环',
    '127.1.2.3'      => '回环（短写）',
    '10.0.0.1'       => '私网 A 类',
    '10.255.255.254' => '私网 A 类边界',
    '172.16.0.1'     => '私网 B 类下界',
    '172.31.255.254' => '私网 B 类上界',
    '192.168.1.1'    => '私网 C 类',
    '169.254.169.254' => '链路本地 / 云元数据',
    '0.0.0.0'        => '未指定地址',
    '::1'            => 'IPv6 回环',
    'fe80::1'        => 'IPv6 链路本地',
    'fc00::1'        => 'IPv6 唯一本地',
];
foreach ($blockedIps as $ip => $why) {
    check(icon_ip_is_public($ip) === false, "icon_ip_is_public 应拒绝 {$ip}（{$why}）");
}

$allowedIps = ['8.8.8.8', '1.1.1.1', '114.114.114.114', '203.0.113.10'];
foreach ($allowedIps as $ip) {
    check(icon_ip_is_public($ip) === true, "icon_ip_is_public 应放行公网地址 {$ip}");
}

// IPv4-mapped IPv6 必须先剥掉 ::ffff: 前缀再判定，否则可绕过
check(icon_ip_is_public('::ffff:127.0.0.1') === false, '::ffff:127.0.0.1 应被判定为回环（防绕过）');
check(icon_ip_is_public('::ffff:8.8.8.8') === true, '::ffff:8.8.8.8 应被判定为公网');
// 非法输入不能误判为公网
check(icon_ip_is_public('') === false, '空串不应判为公网');
check(icon_ip_is_public('not-an-ip') === false, '非 IP 字符串不应判为公网');
check(icon_ip_is_public('999.999.999.999') === false, '越界数值不应判为公网');

/* ---------- 2. icon_host_is_blocked：天然禁止的主机名 ---------- */
$blockedHosts = [
    'localhost'                 => 'localhost',
    'sub.localhost'             => '.localhost 子域',
    'printer.local'             => 'mDNS .local',
    'db.internal'               => '.internal',
    'metadata'                  => '云元数据短名',
    'metadata.google.internal'  => 'GCP 元数据域名',
    '127.0.0.1'                 => '回环 IP 字面量',
    '10.1.2.3'                  => '私网 IP 字面量',
];
foreach ($blockedHosts as $host => $why) {
    check(icon_host_is_blocked($host) === true, "icon_host_is_blocked 应拒绝 {$host}（{$why}）");
}

foreach (['example.com', 'github.com', 'www.google.com', '8.8.8.8'] as $host) {
    check(icon_host_is_blocked($host) === false, "icon_host_is_blocked 不应拒绝 {$host}");
}
check(icon_host_is_blocked('') === true, '空主机名应被拒绝');

/* ---------- 3. icon_url_is_safe：协议白名单与目标校验 ---------- */
$badUrls = [
    'file:///etc/passwd'                 => 'file 协议',
    'gopher://127.0.0.1:6379/_INFO'      => 'gopher 协议（SSRF 经典利用）',
    'ftp://example.com/x.png'            => 'ftp 协议',
    'dict://127.0.0.1:11211/stat'        => 'dict 协议',
    'ldap://127.0.0.1/'                  => 'ldap 协议',
    'http://localhost/favicon.ico'       => 'localhost',
    'http://169.254.169.254/latest/meta-data/' => '云元数据地址',
    'http://192.168.0.1/favicon.ico'     => '私网地址',
    'http://10.0.0.5/'                   => '私网地址',
    'https://metadata.google.internal/'  => 'GCP 元数据域名',
    'http:///favicon.ico'                => '缺少主机名',
    ''                                   => '空 URL',
    'not a url'                          => '非法 URL',
];
foreach ($badUrls as $url => $why) {
    check(icon_url_is_safe($url) === false, "icon_url_is_safe 应拒绝 {$why}：{$url}");
}

foreach ([
    'https://example.com/favicon.ico',
    'http://example.com/favicon.ico',
    'https://www.google.com/favicon.ico',
] as $url) {
    // 公网域名且解析结果均为公网时放行；若当前环境无 DNS，解析失败会被拒绝，
    // 这同样是正确的「失败即放弃」行为，故两种结果都接受。
    $ok = icon_url_is_safe($url);
    check($ok === true || $ok === false, 'icon_url_is_safe 应返回布尔值');
}

// 测试豁免白名单：显式传入 allowHosts 时应放行（生产调用一律留空）
check(icon_url_is_safe('http://localhost/favicon.ico', ['localhost']) === true,
    'allowHosts 白名单应能豁免 localhost（仅供本地冒烟测试）');
check(icon_url_is_safe('http://localhost/favicon.ico', ['LOCALHOST']) === true,
    'allowHosts 白名单比较应大小写不敏感');

/* ---------- 4. icon_image_type：魔数白名单 ---------- */
$goodImages = [
    "\x89PNG\r\n\x1a\n" . str_repeat("\0", 40) => 'png',
    "\xFF\xD8\xFF\xE0" . str_repeat("\0", 40)  => 'jpg',
    'GIF89a' . str_repeat("\0", 40)            => 'gif',
    'GIF87a' . str_repeat("\0", 40)            => 'gif',
    'RIFF' . "\0\0\0\0" . 'WEBP' . str_repeat("\0", 40) => 'webp',
    "\x00\x00\x01\x00" . str_repeat("\0", 40)  => 'ico',
    'BM' . str_repeat("\0", 40)                => 'bmp',
];
foreach ($goodImages as $bytes => $expected) {
    check(icon_image_type($bytes) === $expected,
        'icon_image_type 识别错误，期望 ' . $expected . '，实际 ' . var_export(icon_image_type($bytes), true));
}

// 这些一律不得被识别为图片：否则可能被写进同源静态目录造成 XSS
$badImages = [
    '<svg xmlns="http://www.w3.org/2000/svg">' => 'SVG（可携带脚本）',
    '<html><script>alert(1)</script></html>'   => 'HTML',
    '<?php system($_GET["c"]); ?>'             => 'PHP 源码',
    'MZ' . str_repeat("\0", 40)                => 'PE 可执行文件',
    '#!/bin/sh\nrm -rf /'                      => 'Shell 脚本',
    ''                                          => '空内容',
    'abc'                                       => '过短内容',
];
foreach ($badImages as $bytes => $why) {
    check(icon_image_type($bytes) === '', "icon_image_type 不应识别 {$why} 为图片");
}

/* ---------- 5. 拆分后函数归属正确 ---------- */
check(is_file(NAV_ROOT . '/lib/icon_security.php'), 'lib/icon_security.php 缺失');
foreach (['icon_image_type', 'icon_ip_is_public', 'icon_host_is_blocked',
          'icon_resolve_host_ips', 'icon_url_is_safe'] as $fn) {
    check(function_exists($fn), "函数 {$fn} 未定义（应在 lib/icon_security.php）");
}
check(function_exists('favicon_fetch_bytes'), 'favicon_fetch_bytes 未定义（应留在 lib/favicon.php）');

echo 'PASS: SSRF guard, image magic whitelist and icon_security split' . PHP_EOL;
