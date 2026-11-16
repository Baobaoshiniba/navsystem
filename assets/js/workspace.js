/* Small, accessible feedback for the workspace controls. */
(function () {
    'use strict';
    // Animate a view change only when motion is allowed; no decorative loops.
    document.querySelectorAll('.editor-panel').forEach(function (panel) {
        if (window.matchMedia('(max-width: 1000px)').matches && panel.getAttribute('data-editing') !== '1') panel.open = false;
    });
})();
