/**
 * theme.js - 深浅色主题控制（v3）
 *
 * 策略（与后端 config.php theme_preference() 保持一致）：
 *  - 用户从未手动选择过 => 固定浅色（light），不再跟随系统深色自动变黑；
 *  - 点击切换按钮后 => 写 Cookie(theme=light|dark) 记忆 365 天，服务端后续直接按 Cookie 输出；
 *  - 页面加载后 => 以服务端直出的 data-theme 为准（默认 light），JS 仅负责切换与回写。
 *
 * 附带职责：根据当前主题切换 favicon 深浅变体（light 用 favicon.svg，dark 用 favicon-dark.svg）。
 */
(function () {
    'use strict';
    var KEY = 'theme';
    var DAYS = 365;

    function readCookie() {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + KEY + '=([^;]+)'));
        return m ? decodeURIComponent(m[1]) : '';
    }

    function writeCookie(value) {
        var expires = new Date(Date.now() + DAYS * 864e5).toUTCString();
        document.cookie = KEY + '=' + encodeURIComponent(value) +
            '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function updateFavicon(theme) {
        var link = document.getElementById('appFavicon');
        if (!link) return;
        var base = link.getAttribute('data-fav-base') || '';
        link.setAttribute('href', base + (theme === 'dark' ? 'favicon-dark.svg' : 'favicon.svg'));
    }

    function applyTheme(theme, persist) {
        var root = document.documentElement;
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-loaded', '1');
        updateFavicon(theme);
        if (persist) {
            writeCookie(theme);
        }
    }

    // 手动切换按钮
    var toggle = document.getElementById('themeToggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark', true);
        });
    }

    // 初始化：无 Cookie（或 Cookie 非法）时保持服务端默认（light）；有 Cookie 则按用户选择
    var saved = readCookie();
    var serverTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    var theme = (saved === 'dark' || saved === 'light') ? saved : serverTheme;
    applyTheme(theme, false);
})();
