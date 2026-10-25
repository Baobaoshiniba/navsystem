<?php
/**
 * 公司导航页 - favicon 抓取与本地缓存
 *
 * 设计要点：
 *  - 三级优先级：① 本地缓存命中 → ② 源站抓取（/favicon.ico + HTML 中 <link rel="icon">）→
 *    ③ 第三方 favicon 服务兜底（Google s2 / DuckDuckGo / favicon.im）→ ④ 全部失败回退首字母徽章；
 *  - 一律落盘本地：不管来自源站还是第三方，取回的图片都写入 assets/icons/，前台只读本地文件；
 *  - 安全：仅 http/https；禁止内网 / 回环 / 保留地址（SSRF 防护，含 DNS 解析校验与重定向逐跳校验）；
 *    连接 / 读取超时、响应体积上限、图片魔数校验，非法内容一律丢弃；
 *  - 失败静默降级，绝不抛异常打断页面。
 */

declare(strict_types=1);

/** 缓存目录（相对项目根） */
const ICON_CACHE_DIRNAME      = 'assets/icons';
/** 单个图标文件体积上限（字节） */
const ICON_MAX_BYTES          = 307200;   // 300KB
/** 解析 <link rel="icon"> 时首页 HTML 的读取上限（字节） */
const ICON_MAX_HTML_BYTES     = 524288;   // 512KB
/** 第三方兜底图标的最小边长（像素），用于剔除「无站点图标」的占位图 */
const ICON_MIN_SIDE           = 24;
/** 连接超时 / 整体超时（秒） */
const ICON_CONNECT_TIMEOUT    = 2;
const ICON_READ_TIMEOUT       = 5;
/** 缓存有效期（天），超过则视为可重抓 */
const ICON_REFRESH_TTL_DAYS   = 30;

/* =====================================================================
 * 1. 路径与命名
 * ===================================================================== */

/** 图标缓存目录绝对路径 */
function icon_cache_dir(): string
{
    return dirname(__DIR__) . '/' . ICON_CACHE_DIRNAME;
}

/** 前台可用的相对前缀（index.php 与 admin/ 下均可拼接使用） */
function icon_cache_public_prefix(): string
{
    return ICON_CACHE_DIRNAME . '/';
}

/** 确保缓存目录存在（自动创建，失败不抛异常） */
function icon_ensure_cache_dir(): string
{
    $dir = icon_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** URL 归一化（去首尾空白、小写 scheme/host，用于稳定哈希） */
function icon_normalize_url(string $url): string
{
    $url = trim($url);
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $url;
    }
    $scheme = strtolower($parts['scheme'] ?? 'http');
    $host   = strtolower($parts['host']);
    $port   = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path   = $parts['path'] ?? '/';
    $query  = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $scheme . '://' . $host . $port . $path . $query;
}

/** 由 URL 哈希生成缓存文件名（不含扩展名，避免不同链接撞名） */
function icon_cache_basename(string $url): string
{
    return 'ic_' . substr(hash('sha256', icon_normalize_url($url)), 0, 24);
}

/** 提取用于展示的域名（去掉 www. 前缀与端口） */
function icon_display_host(string $url): string
{
    $host = (string)@parse_url(trim($url), PHP_URL_HOST);
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    return $host;
}

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
    if (substr($host, -6) === '.local' || substr($host, -6) === '.internal') {
        return true;
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

/* =====================================================================
 * 4. HTTP 抓取（手动跟随重定向，逐跳校验）
 * ===================================================================== */

/** 相对地址补全为绝对地址 */
function icon_absolute_url(string $base, string $ref): string
{
    $ref = trim($ref);
    if ($ref === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $ref)) {
        return $ref;
    }
    $b = @parse_url($base);
    if (!is_array($b) || empty($b['host'])) {
        return '';
    }
    $scheme = strtolower($b['scheme'] ?? 'http');
    $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . (int)$b['port'] : '');
    if (strpos($ref, '//') === 0) {
        return $scheme . ':' . $ref;
    }
    if ($ref[0] === '/') {
        return $origin . $ref;
    }
    $dir = isset($b['path']) ? rtrim(dirname($b['path']), '/') : '';
    if ($dir === '.' || $dir === '\\') {
        $dir = '';
    }
    return $origin . $dir . '/' . $ref;
}

/**
 * 请求 URL 并返回响应体
 *
 * @param string[] $allowHosts 测试专用主机白名单
 * @return array{ok:bool,bytes:string,status:int,type:string,url:string,error:string}
 */
function icon_http_get(string $url, array $allowHosts = [], int $maxBytes = ICON_MAX_BYTES, int $maxHops = 3): array
{
    $fail = static function (string $error, string $url, int $status = 0): array {
        return ['ok' => false, 'bytes' => '', 'status' => $status, 'type' => '', 'url' => $url, 'error' => $error];
    };

    if (!function_exists('curl_init')) {
        return $fail('curl-unavailable', $url);
    }

    $current = $url;
    for ($hop = 0; $hop <= $maxHops; $hop++) {
        // 每一跳都重新做安全校验，防止公开站点 302 到内网
        if (!icon_url_is_safe($current, $allowHosts)) {
            return $fail('unsafe-url', $current);
        }

        $tooBig  = false;
        $headers = [];
        $ch = curl_init($current);
        if ($ch === false) {
            return $fail('curl-init-failed', $current);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_FOLLOWLOCATION   => false,          // 重定向由本函数逐跳处理
            CURLOPT_CONNECTTIMEOUT   => ICON_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT          => ICON_READ_TIMEOUT,
            CURLOPT_USERAGENT        => 'CompanyNavIconBot/1.0',
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_ENCODING         => '',
            CURLOPT_HTTPHEADER       => ['Accept: image/*,text/html;q=0.8,*/*;q=0.5'],
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow) use ($maxBytes, &$tooBig) {
                if ($dlNow > $maxBytes) {   // 超限直接中断，避免大文件打爆内存
                    $tooBig = true;
                    return 1;
                }
                return 0;
            },
            CURLOPT_HEADERFUNCTION   => static function ($res, $line) use (&$headers) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        $bytes  = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            return $fail('response-too-large', $current, $status);
        }
        if ($bytes === false) {
            return $fail($error !== '' ? $error : 'request-failed', $current, $status);
        }
        if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
            $next = icon_absolute_url($current, (string)$headers['location']);
            if ($next === '') {
                return $fail('bad-redirect', $current, $status);
            }
            $current = $next;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            return $fail('http-status-' . $status, $current, $status);
        }
        $ctype = strtolower(trim(explode(';', $ctype)[0]));
        return ['ok' => true, 'bytes' => (string)$bytes, 'status' => $status, 'type' => $ctype, 'url' => $current, 'error' => ''];
    }

    return $fail('too-many-redirects', $current);
}

/* =====================================================================
 * 5. 候选地址
 * ===================================================================== */

/** 第三方 favicon 服务兜底地址（按顺序尝试） */
function icon_thirdparty_candidates(string $host): array
{
    if ($host === '') {
        return [];
    }
    $h = rawurlencode($host);
    return [
        'google'     => 'https://www.google.com/s2/favicons?sz=64&domain=' . $h,
        'duckduckgo' => 'https://icons.duckduckgo.com/ip3/' . $h . '.ico',
        'favicon.im' => 'https://favicon.im/' . $h,
    ];
}

/** 从 HTML 中解析 <link rel="...icon..."> 声明，返回绝对地址列表 */
function icon_html_icon_links(string $html, string $pageUrl): array
{
    $out = [];
    if ($html === '' || stripos($html, '<link') === false) {
        return $out;
    }
    if (!preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
        return $out;
    }
    foreach ($matches[0] as $tag) {
        if (!preg_match('/\brel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $rel)) {
            continue;
        }
        $relVal = strtolower(trim($rel[1] !== '' ? $rel[1] : ($rel[2] !== '' ? $rel[2] : $rel[3])));
        if (strpos($relVal, 'icon') === false) {
            continue;
        }
        if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $href)) {
            continue;
        }
        $hrefVal = html_entity_decode(trim($href[1] !== '' ? $href[1] : ($href[2] !== '' ? $href[2] : $href[3])), ENT_QUOTES);
        if ($hrefVal === '' || stripos($hrefVal, 'data:') === 0) {
            continue;
        }
        $abs = icon_absolute_url($pageUrl, $hrefVal);
        if ($abs !== '') {
            $out[] = $abs;
        }
    }
    return array_values(array_unique($out));
}

/**
 * 源站候选①：站点根 /favicon.ico
 */
function icon_ico_candidate(string $pageUrl): string
{
    return icon_absolute_url($pageUrl, '/favicon.ico');
}

/**
 * 源站候选②：首页 HTML 中声明的 <link rel="...icon...">
 *
 * @return string[]
 */
function icon_html_candidates(string $pageUrl, array $allowHosts = []): array
{
    // HTML 体积上限远大于图标本身；不强制 content-type 为 html（不少站点标错）
    $page = icon_http_get($pageUrl, $allowHosts, ICON_MAX_HTML_BYTES, 2);
    if (!$page['ok']) {
        if (icon_is_connect_error($page['error'])) {
            icon_mark_host_unreachable(icon_display_host($pageUrl));
        }
        return [];
    }
    $head = substr($page['bytes'], 0, 4096);
    $isHtml = stripos($page['type'], 'html') !== false || stripos($head, '<html') !== false || stripos($head, '<link') !== false;
    if (!$isHtml) {
        return [];
    }
    return icon_html_icon_links($page['bytes'], $page['url']);
}

/** 连接类失败（超时 / 无法连接 / DNS 解析失败）判定 */
function icon_is_connect_error(string $error): bool
{
    $error = strtolower($error);
    if ($error === '') {
        return false;
    }
    foreach (['connect', 'timed out', 'resolve', 'name lookup', 'could not resolve'] as $needle) {
        if (strpos($error, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/* --- 主机 / 第三方服务可达性：同一 PHP 请求内记忆，避免批量刷新时反复等待同一个超时 --- */

/** 不可达标记（按引用共享） */
function &icon_down_state(): array
{
    static $down = ['host' => [], 'provider' => []];
    return $down;
}

function icon_host_unreachable(string $host): bool
{
    $down = &icon_down_state();
    return $host !== '' && !empty($down['host'][$host]);
}

function icon_mark_host_unreachable(string $host): void
{
    if ($host === '') {
        return;
    }
    $down = &icon_down_state();
    $down['host'][$host] = true;
}

function icon_thirdparty_unreachable(string $provider): bool
{
    $down = &icon_down_state();
    return !empty($down['provider'][$provider]);
}

function icon_mark_thirdparty_down(string $provider, string $error): void
{
    if (!icon_is_connect_error($error)) {
        return;                             // 4xx/5xx 属服务正常，只是没有该图标
    }
    $down = &icon_down_state();
    $down['provider'][$provider] = true;
}

/* =====================================================================
 * 6. 抓取 / 落盘 / 入库
 * ===================================================================== */

/** 校验候选图片字节，返回可落盘的扩展名（非法返回空串） */
function icon_validate_bytes(string $bytes): string
{
    if ($bytes === '' || strlen($bytes) > ICON_MAX_BYTES) {
        return '';
    }
    return icon_image_type($bytes);
}

/** 把图片字节写入缓存目录，返回文件名 */
function icon_store_bytes(string $bytes, string $ext, string $url): string
{
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext));
    if ($ext === '' || icon_validate_bytes($bytes) !== $ext) {
        return '';
    }
    $dir = icon_ensure_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return '';
    }
    $file = icon_cache_basename($url) . '.' . $ext;
    $path = $dir . '/' . $file;
    if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
        return '';
    }
    @chmod($path, 0644);
    return $file;
}

/** 删除某链接遗留的旧缓存文件（换扩展名时用） */
function icon_delete_cached_file(string $file): void
{
    $file = basename($file);
    if ($file === '' || $file === '.' || $file === '..') {
        return;
    }
    $path = icon_cache_dir() . '/' . $file;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * 三级抓取主流程（只负责取回合法图片字节，不写库）
 *
 * @param string[] $allowHosts 测试专用主机白名单，生产调用留空
 * @param bool     $allowThirdparty 是否允许第三方服务兜底（仅测试用于隔离源站层级，生产恒为 true）
 * @return array{status:string,bytes:string,ext:string,source:string,message:string}
 */
function favicon_fetch_bytes(string $url, array $allowHosts = [], bool $allowThirdparty = true): array
{
    $miss = static function (string $message): array {
        return ['status' => 'fail', 'bytes' => '', 'ext' => '', 'source' => '', 'message' => $message];
    };

    if (!icon_url_is_safe($url, $allowHosts)) {
        return $miss(t('目标地址不被允许（非 http/https 或指向内网 / 回环 / 保留地址）'));
    }

    $host = icon_display_host($url);

    // ② 源站抓取：站点根 /favicon.ico → 首页 HTML 中声明的 <link rel="icon">
    if (!icon_host_unreachable($host)) {
        $ico = icon_ico_candidate($url);
        if ($ico !== '') {
            $resp = icon_http_get($ico, $allowHosts);
            if ($resp['ok']) {
                $ext = icon_validate_bytes($resp['bytes']);
                if ($ext !== '') {
                    return ['status' => 'ok', 'bytes' => $resp['bytes'], 'ext' => $ext, 'source' => 'site', 'message' => (t('源站 /favicon') . '.ico ' . t('抓取成功'))];
                }
            } elseif (icon_is_connect_error($resp['error'])) {
                icon_mark_host_unreachable($host);  // 主机不可达，后续源站候选不必再试
            }
        }
        if (!icon_host_unreachable($host)) {
            foreach (icon_html_candidates($url, $allowHosts) as $candidate) {
                $resp = icon_http_get($candidate, $allowHosts);
                if (!$resp['ok']) {
                    continue;
                }
                $ext = icon_validate_bytes($resp['bytes']);
                if ($ext === '') {
                    continue;
                }
                return ['status' => 'ok', 'bytes' => $resp['bytes'], 'ext' => $ext, 'source' => 'site', 'message' => (t('源站') . ' <link rel="icon"> ' . t('抓取成功'))];
            }
        }
    }

    // ③ 第三方 favicon 服务兜底
    if ($allowThirdparty) {
        foreach (icon_thirdparty_candidates($host) as $provider => $candidate) {
            if (icon_thirdparty_unreachable($provider)) {
                continue;                       // 同一请求内已确认不可达，跳过，避免逐个链接重复等待超时
            }
            $resp = icon_http_get($candidate, $allowHosts, ICON_MAX_BYTES, 2);
            if (!$resp['ok']) {
                icon_mark_thirdparty_down($provider, $resp['error']);
                continue;
            }
            $ext = icon_validate_bytes($resp['bytes']);
            if ($ext === '') {
                continue;
            }
            if (icon_is_placeholder_image($resp['bytes'])) {
                continue;                       // 第三方「无站点图标」占位图，不作为有效图标
            }
            return ['status' => 'ok', 'bytes' => $resp['bytes'], 'ext' => $ext, 'source' => $provider, 'message' => t('第三方服务兜底成功')];
        }
    }

    return $miss(t('源站与第三方服务均未取到有效图标'));
}

/** 第三方「域名无图标」占位图判定（尺寸过小视为占位） */
function icon_is_placeholder_image(string $bytes): bool
{
    if (!function_exists('getimagesizefromstring')) {
        return false;
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return false;                       // 读不出尺寸（如 ICO）则不做该判定
    }
    return $info[0] < ICON_MIN_SIDE || $info[1] < ICON_MIN_SIDE;
}

/**
 * 抓取并落盘，返回抓取结果（不含入库）
 *
 * @return array{status:string,file:string,source:string,message:string}
 */
function favicon_fetch(string $url, array $options = []): array
{
    $allowHosts = $options['allow_hosts'] ?? [];
    $allowThirdparty = (bool)($options['allow_thirdparty'] ?? true);
    $result = favicon_fetch_bytes($url, $allowHosts, $allowThirdparty);
    if ($result['status'] !== 'ok') {
        return ['status' => 'fail', 'file' => '', 'source' => '', 'message' => $result['message']];
    }
    $file = icon_store_bytes($result['bytes'], $result['ext'], $url);
    if ($file === '') {
        return ['status' => 'fail', 'file' => '', 'source' => '', 'message' => t('图标写入本地缓存失败')];
    }
    return ['status' => 'ok', 'file' => $file, 'source' => $result['source'], 'message' => $result['message']];
}

/**
 * 为一条链接抓取图标并写库
 *
 * @param array $options allow_hosts：测试专用主机白名单；force：忽略本地缓存强制重抓
 * @return array{ok:bool,file:string,source:string,status:string,message:string}
 */
function icon_fetch_for_link(PDO $pdo, array $link, array $options = []): array
{
    $id  = (int)($link['id'] ?? 0);
    $url = trim((string)($link['url'] ?? ''));
    $now = date('Y-m-d H:i:s');

    $update = static function (PDO $pdo, int $id, string $file, string $source, string $status, string $note, string $now): void {
        $stmt = $pdo->prepare(
            'UPDATE links SET icon_file = ?, icon_source = ?, icon_status = ?, icon_note = ?, icon_fetched_at = ? WHERE id = ?'
        );
        $stmt->execute([$file, $source, $status, $note, $now, $id]);
    };

    if ($id <= 0 || $url === '') {
        return ['ok' => false, 'file' => '', 'source' => '', 'status' => 'fail', 'message' => t('链接不存在或 URL 为空')];
    }

    // ① 本地缓存命中（未强制重抓且状态正常）
    if (empty($options['force'])) {
        $cached = icon_cached_file($link);
        if ($cached !== '') {
            return ['ok' => true, 'file' => $cached, 'source' => (string)($link['icon_source'] ?? ''), 'status' => 'cached', 'message' => t('命中本地缓存，无需重抓')];
        }
    }

    $result = favicon_fetch($url, $options);

    if ($result['status'] !== 'ok') {
        // 抓取失败：保留已有的有效缓存（避免卡片回退），只在从未缓存成功时标记 fail
        $existing = (string)($link['icon_file'] ?? '');
        if ($existing !== '' && ($link['icon_status'] ?? '') === 'ok' && is_file(icon_cache_dir() . '/' . $existing)) {
            $update($pdo, $id, $existing, (string)($link['icon_source'] ?? ''), 'ok', $result['message'], $now);
            return ['ok' => false, 'file' => $existing, 'source' => (string)($link['icon_source'] ?? ''), 'status' => 'stale', 'message' => $result['message']];
        }
        $update($pdo, $id, '', '', 'fail', $result['message'], $now);
        return ['ok' => false, 'file' => '', 'source' => '', 'status' => 'fail', 'message' => $result['message']];
    }

    // 扩展名变化时清掉旧文件，避免缓存目录堆积
    $old = (string)($link['icon_file'] ?? '');
    if ($old !== '' && $old !== $result['file']) {
        icon_delete_cached_file($old);
    }
    $update($pdo, $id, $result['file'], $result['source'], 'ok', $result['message'], $now);
    return ['ok' => true, 'file' => $result['file'], 'source' => $result['source'], 'status' => 'ok', 'message' => $result['message']];
}

/* =====================================================================
 * 7. 前台展示辅助
 * ===================================================================== */

/** 该链接本地缓存文件是否有效（返回文件名，无效返回空串） */
function icon_cached_file(array $link): string
{
    if ((string)($link['icon_status'] ?? '') !== 'ok') {
        return '';
    }
    $file = (string)($link['icon_file'] ?? '');
    if ($file === '' || basename($file) !== $file) {
        return '';
    }
    return is_file(icon_cache_dir() . '/' . $file) ? $file : '';
}

/** 前台图标地址（相对路径，仅指向本地缓存；无有效缓存返回空串） */
function link_icon_url(array $link): string
{
    $file = icon_cached_file($link);
    return $file === '' ? '' : icon_cache_public_prefix() . $file;
}

/** 首字母徽章文字（优先域名首字母，其次标题首字） */
function link_icon_letter(array $link): string
{
    $host = icon_display_host((string)($link['url'] ?? ''));
    $seed = $host !== '' ? $host : (string)($link['title'] ?? '');
    $seed = trim($seed);
    if ($seed === '') {
        return '#';
    }
    return mb_strtoupper(mb_substr($seed, 0, 1));
}

/** 首字母徽章色相（由域名哈希稳定生成，落在青蓝—紫区间更贴合既有体系） */
function link_icon_hue(array $link): int
{
    $host = icon_display_host((string)($link['url'] ?? ''));
    $seed = $host !== '' ? $host : (string)($link['title'] ?? '');
    $hash = (int)hexdec(substr(md5($seed), 0, 4));
    return 186 + ($hash % 76);              // 186°(青) ~ 261°(蓝紫)
}

/** 图标来源的中文说明（后台展示用） */
function icon_source_label(string $source): string
{
    $map = [
        'site'       => t('源站'),
        'google'     => 'Google',
        'duckduckgo' => 'DuckDuckGo',
        'favicon.im' => 'favicon.im',
    ];
    return $map[$source] ?? '';
}

/** 是否需要重抓（本地无有效缓存，或缓存已超过有效期） */
function icon_needs_refresh(array $link): bool
{
    if (icon_cached_file($link) === '') {
        return true;
    }
    $fetchedAt = (string)($link['icon_fetched_at'] ?? '');
    $ts = $fetchedAt !== '' ? strtotime($fetchedAt) : false;
    if ($ts === false) {
        return true;
    }
    return (time() - $ts) > ICON_REFRESH_TTL_DAYS * 86400;
}

/** 缓存统计（用于后台展示） */
function icon_cache_stats(PDO $pdo): array
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
    $cached = (int)$pdo->query("SELECT COUNT(*) FROM links WHERE icon_status = 'ok'")->fetchColumn();
    $failed = (int)$pdo->query("SELECT COUNT(*) FROM links WHERE icon_status = 'fail'")->fetchColumn();
    return ['total' => $total, 'cached' => $cached, 'failed' => $failed, 'none' => max(0, $total - $cached - $failed)];
}
