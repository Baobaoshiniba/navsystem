<?php
/**
 * 首页每日寄语：中文一言哲学类 API、英文 ZenQuotes 每日励志 API，按语言和日期缓存。
 */

declare(strict_types=1);

function daily_quote_date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
}

function daily_quote_fallback(string $date, string $language = 'zh-CN'): array
{
    $quotes = [
        ['zh' => '先完成今天的一小步，远方就会更近一些。', 'en' => 'Take one small step today, and the distance ahead will feel shorter.'],
        ['zh' => '不必一次做好所有事，持续前进就很了不起。', 'en' => 'You do not have to do everything at once; steady progress is already meaningful.'],
        ['zh' => '认真走过的每一步，都会成为下一步的底气。', 'en' => 'Every thoughtful step becomes confidence for the next one.'],
        ['zh' => '把注意力放回眼前，答案常从行动里出现。', 'en' => 'Bring your attention to what is in front of you; answers often emerge through action.'],
        ['zh' => '允许自己慢一点，但不要忘记继续出发。', 'en' => 'Give yourself time, and remember you can still begin again.'],
        ['zh' => '今天的专注，会让明天多一点从容。', 'en' => 'A little focus today can make tomorrow feel more manageable.'],
        ['zh' => '与其等待完美时机，不如先迈出可行的一步。', 'en' => 'Instead of waiting for the perfect moment, take the next possible step.'],
    ];
    $quote = $quotes[(int)substr($date, -2) % count($quotes)];
    return [
        'text' => $language === 'en' ? $quote['en'] : $quote['zh'],
        'from' => '',
        'from_who' => '',
        'source_url' => '',
        'provider' => 'team',
    ];
}

function daily_quote_cache_read(string $date, string $language): ?array
{
    $cacheKey = $language === 'en' ? 'daily_quote_cache_en' : 'daily_quote_cache_zh';
    $raw = raw_setting($cacheKey, false);
    if ($raw === '') return null;
    $cache = json_decode($raw, true);
    if (!is_array($cache) || ($cache['date'] ?? '') !== $date || !is_array($cache['quote'] ?? null)) return null;
    $quote = $cache['quote'];
    if (!is_string($quote['text'] ?? null) || trim($quote['text']) === '') return null;

    $provider = (string)($quote['provider'] ?? '');
    $allowedHost = $provider === 'hitokoto' ? 'hitokoto\\.cn' : ($provider === 'zenquotes' ? 'zenquotes\\.io' : '');
    if ($allowedHost !== '') {
        if (!preg_match('#^https://' . $allowedHost . '(?:/|\\?)#i', (string)($quote['source_url'] ?? ''))) {
            $quote['source_url'] = $provider === 'hitokoto' ? 'https://hitokoto.cn/' : 'https://zenquotes.io/';
        }
    } else {
        $quote['source_url'] = '';
    }
    return $quote;
}

function daily_quote_initial(): array
{
    $date = daily_quote_date();
    $language = locale() === 'en' ? 'en' : 'zh-CN';
    return daily_quote_cache_read($date, $language) ?? daily_quote_fallback($date, $language);
}

function daily_quote_http_json(string $url): ?array
{
    $body = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) return null;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => 'TeamResourceHub/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 16384) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($error !== 0) return null;
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 4, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: TeamResourceHub/1.0\r\n",
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || strlen($response) > 16384) return null;
        $body = $response;
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\\s(\\d{3})\\s/', $statusLine, $match)) $status = (int)$match[1];
    }
    if ($status < 200 || $status >= 300 || $body === '') return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function daily_quote_fetch_api(string $language): ?array
{
    if ($language === 'en') {
        $data = daily_quote_http_json('https://zenquotes.io/api/today');
        $item = is_array($data[0] ?? null) ? $data[0] : null;
        if (!$item || !is_string($item['q'] ?? null)) return null;
        $text = trim(strip_tags($item['q']));
        if ($text === '' || strlen($text) > 1200) return null;
        return [
            'text' => $text,
            'from' => '',
            'from_who' => trim(strip_tags((string)($item['a'] ?? ''))),
            'source_url' => 'https://zenquotes.io/',
            'provider' => 'zenquotes',
        ];
    }

    $data = daily_quote_http_json('https://international.v1.hitokoto.cn/?c=k&encode=json&max_length=80');
    if (!is_string($data['hitokoto'] ?? null)) return null;
    $text = trim(strip_tags($data['hitokoto']));
    if ($text === '' || strlen($text) > 1200) return null;
    $uuid = (string)($data['uuid'] ?? '');
    return [
        'text' => $text,
        'from' => trim(strip_tags((string)($data['from'] ?? ''))),
        'from_who' => trim(strip_tags((string)($data['from_who'] ?? ''))),
        'source_url' => preg_match('/^[a-f0-9-]{36}$/i', $uuid)
            ? 'https://hitokoto.cn?uuid=' . rawurlencode($uuid)
            : 'https://hitokoto.cn/',
        'provider' => 'hitokoto',
    ];
}

function daily_quote_for_today(): array
{
    $date = daily_quote_date();
    $language = locale() === 'en' ? 'en' : 'zh-CN';
    $cached = daily_quote_cache_read($date, $language);
    if ($cached !== null) return $cached;

    $quote = daily_quote_fetch_api($language) ?? daily_quote_fallback($date, $language);
    $cacheKey = $language === 'en' ? 'daily_quote_cache_en' : 'daily_quote_cache_zh';
    try {
        save_setting($cacheKey, json_encode(
            ['date' => $date, 'quote' => $quote],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    } catch (Throwable $ignored) {
        // A read-only settings table must not prevent the homepage from displaying a quote.
    }
    return $quote;
}
