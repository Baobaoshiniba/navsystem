<?php
/**
 * 公司导航页 - 图标抓取的「安全校验」部分
 *
 * 从 lib/favicon.php 拆出。这里只放两类与抓取内容安全性直接相关的纯函数：
 *   1. 图片魔数识别（icon_image_type）—— 只放行位图，SVG / HTML / 脚本一律丢弃，
 *      避免把可执行内容落到同源静态目录造成存储型 XSS；
 *   2. SSRF 防护（icon_ip_is_public / icon_host_is_blocked / icon_resolve_host_ips /
 *      icon_url_is_safe）—— 只放行公网 http(s)，禁止内网 / 回环 / 保留地址与云元数据域名，
 *      并对 DNS 解析结果逐条校验，防止 DNS rebinding 绕过。
 *
 * 这些函数不依赖项目内任何东西（纯 PHP 内置函数），可独立测试。
 * 由 lib/favicon.php 加载；此处仍 require 一份 config.php 以便被单独引入时也能工作。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/* =====================================================================
 * 2. 图片识别（魔数校验）
 * ===================================================================== */

/**
 * 依据文件头识别图片类型
 *
 * 只放行位图格式；SVG / HTML / 脚本等内容一律返回空串被丢弃
 * （避免把可执行内容落到同源静态目录造成 XSS）。
 */
function icon_image_type(string $bytes): string
{
    $len = strlen($bytes);
    if ($len < 4) {
        return '';
    }
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'png';
    }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0) {
        return 'gif';
    }
    if ($len >= 12 && strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (strncmp($bytes, "\x00\x00\x01\x00", 4) === 0) {
        return 'ico';
    }
    if (strncmp($bytes, 'BM', 2) === 0) {
        return 'bmp';
    }
    return '';
}

/* =====================================================================
 * 3. SSRF 防护
 * ===================================================================== */

/** 单个 IP 是否属于公网可访问地址（私网 / 回环 / 保留地址一律拒绝） */
function icon_ip_is_public(string $ip): bool
{
    $ip = trim($ip, "[] \t\n\r\0\x0B");
    // IPv4-mapped IPv6（::ffff:127.0.0.1）先拆出内层 IPv4 再判定，避免绕过
    if (stripos($ip, '::ffff:') === 0) {
        $inner = substr($ip, 7);
        if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = $inner;
        }
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** 主机名是否属于天然禁止的目标（localhost / .local / 云元数据域名等） */
function icon_host_is_blocked(string $host): bool
{
    $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
    if ($host === '') {
        return true;
    }
    if ($host === 'localhost' || substr($host, -10) === '.localhost') {
        return true;
    }
    // 逐个后缀按「实际长度」截取比较。此前这里对 '.internal' 也用了 -6，
    // 而它实际有 9 个字符，导致该后缀的拦截从未生效（由 tests/icon_security.php 抓出）。
    // 写成循环 + strlen，以后加后缀不会再犯同样的错。
    foreach (['.local', '.internal', '.corp', '.lan', '.home'] as $suffix) {
        if (substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    if ($host === 'metadata.google.internal' || $host === 'metadata') {
        return true;
    }
    // 纯 IP 字面量：直接判定公网属性
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !icon_ip_is_public($host);
    }
    return false;
}

/** 解析域名对应的全部 IP（IPv4 + IPv6） */
function icon_resolve_host_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }
    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $r) {
            if (!empty($r['ip'])) {
                $ips[] = (string)$r['ip'];
            }
            if (!empty($r['ipv6'])) {
                $ips[] = (string)$r['ipv6'];
            }
        }
    }
    if (!$ips) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
    }
    return array_values(array_unique($ips));
}

/**
 * URL 是否可安全请求
 *
 * @param string[] $allowHosts 仅本地冒烟测试使用的主机白名单，生产调用一律留空
 */
function icon_url_is_safe(string $url, array $allowHosts = []): bool
{
    $parts = @parse_url(trim($url));
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;                       // 只允许 http/https（挡掉 file:// gopher:// 等）
    }
    $host = (string)($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }
    $allow = array_map('strtolower', $allowHosts);
    if ($allow && in_array(strtolower($host), $allow, true)) {
        return true;                        // 测试专用豁免
    }
    if (icon_host_is_blocked($host)) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return true;                        // 公网 IP 字面量，前面已判定
    }
    $ips = icon_resolve_host_ips($host);
    if (!$ips) {
        return false;                       // 解析不出地址 → 直接放弃，不盲目请求
    }
    foreach ($ips as $ip) {
        if (!icon_ip_is_public($ip)) {
            return false;                   // 任一解析结果落在内网即拒绝
        }
    }
    return true;
}
