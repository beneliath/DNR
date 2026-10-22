(() => {
    'use strict';
    const node = document.getElementById('collection-resize-config');
    if (!node || window.parent === window) return;
    const config = JSON.parse(node.textContent);
    let origin;
    try { origin = new URL(document.referrer).origin; } catch { return; }
    if (!config.origins.includes(origin)) return;
    let previous = 0;
    const resize = () => {
        const height = Math.max(100, Math.ceil(document.body.getBoundingClientRect().height) + 2);
        if (height === previous || height > 20000) return;
        previous = height;
        window.parent.postMessage({type: 'message-monkey-form-height', key: config.key, height}, origin);
    };
    new ResizeObserver(resize).observe(document.body);
    window.addEventListener('load', resize);
    resize();
})();
