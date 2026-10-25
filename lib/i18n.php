<?php
/** Shared, allowlisted locale selection and source-keyed interface messages. */
declare(strict_types=1);

function locale(): string
{
    static $selected;
    if ($selected !== null) return $selected;
    $query = $_GET['lang'] ?? null;
    $cookie = $_COOKIE['nav_lang'] ?? null;
    $selected = in_array($query, ['zh-CN', 'en'], true) ? $query
        : (in_array($cookie, ['zh-CN', 'en'], true) ? $cookie : 'zh-CN');
    if (in_array($query, ['zh-CN', 'en'], true) && !headers_sent()) {
        setcookie('nav_lang', $selected, [
            'expires' => time() + 365 * 86400, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    return $selected;
}

function t(string $message, array $params = []): string
{
    static $english;
    if ($english === null) $english = require __DIR__ . '/../lang/en.php';
    $text = locale() === 'en' ? ($english[$message] ?? $message) : $message;
    foreach ($params as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
    return $text;
}

/** For PHP-generated JavaScript expressions, including inline scripts. */
function jt(string $message): string
{
    return json_encode(t($message), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function localized_field(array $row, string $field): string
{
    $english = (string)($row[$field . '_en'] ?? '');
    return locale() === 'en' && trim($english) !== '' ? $english : (string)($row[$field] ?? '');
}

function language_switch_html(): string
{
    $query = $_GET;
    $html = '<nav class="language-switch" aria-label="Language / 语言">';
    foreach (['zh-CN' => '中文', 'en' => 'English'] as $code => $label) {
        $query['lang'] = $code;
        $active = locale() === $code;
        $html .= '<a href="?' . htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') . '" lang="' . $code . '" class="language-option' . ($active ? ' is-active' : '') . '"'
            . ($active ? ' aria-current="true"' : '') . ' data-language="' . $code . '" data-unsaved="'
            . htmlspecialchars(t('有未保存的修改，切换语言将放弃这些修改。继续吗？'), ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
    }
    return $html . '</nav>';
}

// Resolve before output, so explicit preferences can be remembered with a cookie.
locale();

function item_count(int $count): string
{
    if (locale() === 'en') return number_format($count) . ($count === 1 ? ' item' : ' items');
    return number_format($count) . ' 项';
}
