<?php
/** Same-origin endpoint for the homepage's cached daily quote. */

declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/daily_quote.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $quote = daily_quote_for_today();
} catch (Throwable $error) {
    $quote = daily_quote_fallback(daily_quote_date(), locale() === 'en' ? 'en' : 'zh-CN');
}

echo json_encode(['ok' => true, 'quote' => $quote], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
