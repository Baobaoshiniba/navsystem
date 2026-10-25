/* Language links preserve location context and never replay a form submission. */
(function () {
    'use strict';
    function snapshot(form) {
        return JSON.stringify(Array.from(form.elements).filter(function (el) {
            return el.name && el.type !== 'hidden' && el.type !== 'submit';
        }).map(function (el) {
            return [el.name, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value];
        }));
    }
    var forms = Array.from(document.forms).filter(function (form) {
        return (form.method || '').toLowerCase() === 'post';
    });
    var initial = forms.map(snapshot);
    document.querySelectorAll('[data-language]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (link.getAttribute('aria-current') === 'true') { event.preventDefault(); return; }
            var dirty = forms.some(function (form, i) { return snapshot(form) !== initial[i]; });
            if (dirty && !window.confirm(link.getAttribute('data-unsaved'))) { event.preventDefault(); return; }
            var url = new URL(window.location.href);
            url.searchParams.set('lang', link.getAttribute('data-language'));
            link.href = url.href;
        });
    });
})();
