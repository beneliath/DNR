(function () {
    'use strict';
    // Derive the namespace from the browser URL, never from app-supplied HTML.
    function storageKey(pathname, key) {
        const match = String(pathname).match(/^\/a\/([a-z][a-z0-9-]{2,63})(?:\/|$)/);
        return match ? 'dnr.account.' + match[1] + ':' + key : key;
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { storageKey };
    if (typeof window === 'undefined') return;
    const path = window.location.pathname;
    const scoped = name => Object.freeze({
        getItem: key => window[name].getItem(storageKey(path, key)),
        setItem: (key, value) => window[name].setItem(storageKey(path, key), value),
        removeItem: key => window[name].removeItem(storageKey(path, key))
    });
    Object.defineProperty(window, 'DnrAccountContext', { value: Object.freeze({
        local: scoped('localStorage'), session: scoped('sessionStorage')
    }), writable: false, configurable: false });
}());
