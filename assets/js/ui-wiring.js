/* 全局 UI 行为：把原先写在 HTML 属性里的内联事件迁移到监听器，
 * 这样页面才能在启用 CSP 后不再依赖 'unsafe-inline'。
 *
 * 约定：
 *   - 需要二次确认的表单加 data-confirm="提示语"
 *   - 点击换一张的验证码图片加 data-captcha-src="图片基准地址"
 * 均为事件委托，后续由 JS 动态插入的元素同样生效。
 */
(function () {
    'use strict';

    // 删除等危险操作的二次确认
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || typeof form.getAttribute !== 'function') { return; }
        var message = form.getAttribute('data-confirm');
        if (!message) { return; }
        if (!window.confirm(message)) {
            ev.preventDefault();
        }
    }, false);

    // 验证码图：点击换一张（追加时间戳绕过图片缓存）
    document.addEventListener('click', function (ev) {
        var el = ev.target;
        if (!el || typeof el.getAttribute !== 'function') { return; }
        var base = el.getAttribute('data-captcha-src');
        if (!base) { return; }
        el.setAttribute('src', base + '?v=' + Date.now());
    }, false);
})();
