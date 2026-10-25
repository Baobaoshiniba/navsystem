/* Small, accessible feedback for the workspace controls. */
(function () {
    'use strict';
    // Animate a view change only when motion is allowed; no decorative loops.
    document.querySelectorAll('.editor-panel').forEach(function (panel) {
        if (window.matchMedia('(max-width: 1000px)').matches && panel.getAttribute('data-editing') !== '1') panel.open = false;
    });
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('.filter-pill').forEach(function (button) {
        button.addEventListener('click', function () {
            if (reduce) return;
            document.querySelectorAll('.category-block:not(.is-hidden)').forEach(function (block) {
                block.animate([{opacity: .5, transform: 'translateY(4px)'}, {opacity: 1, transform: 'none'}], {duration: 170, easing: 'ease-out'});
            });
        });
    });
})();
