(() => {
    'use strict';
    const trigger = document.querySelector('[data-notification-scope]');
    const panel = document.getElementById('audience-notifications');
    const badge = trigger?.querySelector('[data-notification-count]');
    if (!trigger || !panel || !badge) return;
    // Bell acknowledgements are separate from reading an Inbox conversation.
    // Store only opaque item keys, scoped to this account and signed-in user.
    const key = `moed:audience:notifications:${trigger.dataset.notificationScope}`;
    const clearedKey = `${key}:cleared`;
    const items = Array.from(panel.querySelectorAll('[data-notification-key]'));
    const empty = panel.querySelector('[data-notification-empty]');
    const actions = panel.querySelector('[data-notification-actions]');
    const list = panel.querySelector('[data-notification-list]');
    const clearAll = panel.querySelector('[data-notification-clear-all]');
    const close = panel.querySelector('[popovertargetaction="hide"]');
    let seen = new Set();
    let cleared = new Set();
    const readSet = (storageKey, fallback) => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
            if (Array.isArray(saved)) return new Set(saved.filter(value => typeof value === 'string'));
        } catch (_) { /* Keep the bell usable when browser storage is unavailable. */ }
        return fallback;
    };
    const readState = () => {
        seen = readSet(key, seen);
        cleared = readSet(clearedKey, cleared);
    };
    const updateBadge = () => {
        items.forEach(item => { item.hidden = cleared.has(item.dataset.notificationKey); });
        const visible = items.filter(item => !item.hidden);
        const count = visible.filter(item => !seen.has(item.dataset.notificationKey)).length;
        badge.textContent = String(count);
        badge.hidden = count === 0;
        const label = count ? `Notifications, ${count} unread` : 'Notifications';
        trigger.setAttribute('aria-label', label);
        trigger.title = label;
        if (empty) empty.hidden = visible.length > 0;
        if (actions) actions.hidden = visible.length === 0;
        if (list) list.hidden = visible.length === 0;
    };
    const saveSet = (storageKey, values) => {
        try { localStorage.setItem(storageKey, JSON.stringify(Array.from(values).slice(-500))); } catch (_) { /* Retain this page's state. */ }
    };
    const dismiss = targets => {
        readState();
        targets.forEach(item => { cleared.add(item.dataset.notificationKey); });
        saveSet(clearedKey, cleared);
        updateBadge();
    };
    items.forEach((item, index) => {
        item.querySelector('[data-notification-dismiss]')?.addEventListener('click', () => {
            dismiss([item]);
            const next = items.slice(index + 1).find(row => !row.hidden) || items.slice(0, index).reverse().find(row => !row.hidden);
            (next?.querySelector('[data-notification-dismiss]') || close)?.focus();
        });
    });
    clearAll?.addEventListener('click', () => {
        dismiss(items);
        panel.hidePopover();
        trigger.focus();
    });
    readState();
    updateBadge();
    panel.addEventListener('toggle', event => {
        if (event.newState !== 'open') return;
        readState();
        items.filter(item => !cleared.has(item.dataset.notificationKey)).forEach(item => seen.add(item.dataset.notificationKey));
        saveSet(key, seen);
        updateBadge();
    });
    window.addEventListener('storage', event => {
        if (event.key !== key && event.key !== clearedKey && event.key !== null) return;
        readState();
        updateBadge();
    });
})();
