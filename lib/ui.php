<?php
/** Crisp 24px outline symbols with a shared 1.9px stroke. Decorative icons are hidden from assistive technology. */
declare(strict_types=1);
function ui_icon(string $name, string $class = ''): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v9H3z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="var(--card-solid)"/><circle cx="15" cy="17" r="3" fill="var(--card-solid)"/>',
        'chart' => '<path d="M4 4v16h16M8 15v-4m4 4V7m4 8v-5"/>',
        'click' => '<path d="m7 4 1 14 4-3 3 5 2.5-1.5-3-5.2 5-.8L7 4z"/><path d="M18 4V2m3 5h2m-3 2 1.5 1.5"/>',
        'average' => '<path d="M4 5v15h16M7 16v-3m4 3V8m4 8v-5"/><path d="M6 11h14" stroke-dasharray="2 2"/>',
        'trend' => '<path d="M4 20V5m0 15h16m-13-4 4-4 3 2 5-6m-4 0h4v4"/>',
        'reset' => '<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'external' => '<path d="M14 3h7v7M21 3l-11 11M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'home' => '<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3z"/>',
        'logout' => '<path d="M9 3H4v18h5M9 12h12m-5-5 5 5-5 5"/>',
        'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M17 4a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 4v3"/>',
        'code' => '<path d="m8 5-7 7 7 7m8-14 7 7-7 7M14 3l-4 18"/>',
        'flask' => '<path d="M9 3h6M10 3v7L4 20a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1l-6-10V3M8 15h8"/>',
        'book' => '<path d="M12 5v16M12 5C8 2 4 3 2 4v15c4-2 7-1 10 2 3-3 6-4 10-2V4c-2-1-6-2-10 1z"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'pause' => '<path d="M8 5v14M16 5v14"/>',
        'edit' => '<path d="m15 4 5 5M4 20l5-1L21 7a3.5 3.5 0 0 0-5-5L4 14z"/>',
        'trash' => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
        'refresh' => '<path d="M3 10a9 9 0 0 1 15-6l3 3M21 3v4h-4M21 14A9 9 0 0 1 6 20l-3-3M3 21v-4h4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-7 5 8"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'warning' => '<path d="m12 3 10 18H2zM12 9v5M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'key' => '<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9m-3-3 3-3m-6 0 3-3"/>',
    ];
    $path = $paths[$name] ?? $paths['folder'];
    return '<svg class="ui-icon ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

function category_symbol(array $category): string
{
    $map = ['🤝' => 'users', '💻' => 'code', '🧪' => 'flask', '📚' => 'book', 'folder' => 'folder', 'users' => 'users', 'code' => 'code', 'flask' => 'flask', 'book' => 'book'];
    return ui_icon($map[$category['icon'] ?? ''] ?? 'folder');
}

/** Known brand assets are local; cached custom website icons remain supported. */
function resource_icon_url(array $link): string
{
    $host = icon_display_host((string)($link['url'] ?? ''));
    $brands = ['github.com' => 'github', 'php.net' => 'php', 'developer.mozilla.org' => 'mdnwebdocs',
        'drive.google.com' => 'googledrive', 'trello.com' => 'trello', 'postman.com' => 'postman',
        'zotero.org' => 'zotero', 'app.diagrams.net' => 'diagramsdotnet', 'scholar.google.com' => 'googlescholar',
        'validator.w3.org' => 'html5', 'pagespeed.web.dev' => 'pagespeedinsights', 'docs.qq.com' => 'tencentqq'];
    if (isset($brands[$host]) && is_file(__DIR__ . '/../assets/brands/' . $brands[$host] . '.svg')) {
        return 'assets/brands/' . $brands[$host] . '.svg';
    }
    return link_icon_url($link);
}

function resource_initial(array $link): string
{
    $title = trim(localized_field($link, 'title'));
    if ($title === '') return link_icon_letter($link);
    return function_exists('mb_substr') ? mb_strtoupper(mb_substr($title, 0, 1)) : strtoupper(substr($title, 0, 1));
}
