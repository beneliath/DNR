(() => {
    'use strict';
    const trigger = document.querySelector('[data-notification-scope]');
    const panel = document.getElementById('audience-notifications');
    const badge = trigger?.querySelector('[data-notification-count]');
    if (!trigger || !panel || !badge) return;
    // Opening the bell does not dismiss notifications or read Inbox conversations.
    // Store only opaque item keys, scoped to this account and signed-in user.
    const key = `moed:audience:notifications:${trigger.dataset.notificationScope}`;
    const clearedKey = `${key}:cleared`;
    let items = Array.from(panel.querySelectorAll('[data-notification-key]'));
    const empty = panel.querySelector('[data-notification-empty]');
    const actions = panel.querySelector('[data-notification-actions]');
    const list = panel.querySelector('[data-notification-list]');
    const clearAll = panel.querySelector('[data-notification-clear-all]');
    const close = panel.querySelector('[popovertargetaction="hide"]');
    let cleared = new Set();
    const endpoint = trigger.dataset.notificationEndpoint;
    let pending = new Set(), syncing = false;
    try { cleared = new Set(JSON.parse(trigger.dataset.notificationCleared || '[]')); } catch (_) { /* Retry from the server. */ }
    const readSet = (storageKey, fallback) => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || '[]');
            if (Array.isArray(saved)) return new Set(saved.filter(value => typeof value === 'string'));
        } catch (_) { /* Keep the bell usable when browser storage is unavailable. */ }
        return fallback;
    };
    const readState = () => {
        for (const value of readSet(clearedKey, cleared)) cleared.add(value);
    };
    const updateBadge = () => {
        items.forEach(item => { item.hidden = cleared.has(item.dataset.notificationKey); });
        const visible = items.filter(item => !item.hidden);
        const count = visible.length;
        badge.textContent = String(count);
        badge.hidden = count === 0;
        const label = count ? `Notifications, ${count} uncleared` : 'Notifications';
        trigger.setAttribute('aria-label', label);
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
        targets.forEach(item => pending.add(item.dataset.notificationKey));
        updateBadge();
        void sync();
    };
    const wireItems = () => items.forEach((item, index) => {
        item.querySelector('[data-notification-dismiss]')?.addEventListener('click', () => {
            dismiss([item]);
            const next = items.slice(index + 1).find(row => !row.hidden) || items.slice(0, index).reverse().find(row => !row.hidden);
            (next?.querySelector('[data-notification-dismiss]') || close)?.focus();
        });
    });
    wireItems();
    clearAll?.addEventListener('click', () => {
        dismiss(items);
        panel.hidePopover();
        trigger.focus();
    });
    const renderItems = records => {
        // Keep the same nodes (and keyboard focus) while the list is unchanged.
        if (JSON.stringify(records.map(record => record.key)) === JSON.stringify(items.map(item => item.dataset.notificationKey))) return;
        const focusedKey = document.activeElement?.closest('[data-notification-key]')?.dataset.notificationKey;
        const rows = records.map(record => {
            const row = document.createElement('li'); row.dataset.notificationKey = record.key;
            const link = document.createElement('a'); link.href = record.href;
            for (const [tag, text] of [['strong', record.label], ['span', record.description], ['small', record.time]]) {
                const node = document.createElement(tag); node.textContent = text; link.append(node);
            }
            const button = document.createElement('button'); button.type = 'button'; button.className = 'audience-notification-dismiss';
            button.setAttribute('data-notification-dismiss', ''); button.setAttribute('aria-label', `Clear notification: ${record.label}`); button.title = 'Clear notification';
            button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7m0-7-7 7"/></svg>';
            row.append(link, button); return row;
        });
        list.replaceChildren(...rows); items = rows; wireItems();
        if (focusedKey) (items.find(item => item.dataset.notificationKey === focusedKey)?.querySelector('[data-notification-dismiss]') || close)?.focus();
    };
    async function sync() {
        if (!endpoint || syncing || document.hidden) return;
        syncing = true;
        const sent = [...pending];
        let saved = false;
        try {
            const options = {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}, signal: AbortSignal.timeout(10000)};
            if (sent.length) { options.method = 'POST'; options.body = new URLSearchParams({csrf_token: trigger.dataset.notificationCsrf, keys: JSON.stringify(sent)}); }
            const response = await fetch(endpoint, options);
            if (!response.ok) throw new Error('Notification synchronization unavailable');
            const state = await response.json();
            if (!Array.isArray(state.cleared) || !Array.isArray(state.items)) throw new Error('Invalid notification response');
            sent.forEach(key => pending.delete(key));
            saved = true;
            cleared = new Set([...state.cleared, ...pending]);
            // Keep unexpired legacy dismissals for a future page that may show them.
            saveSet(clearedKey, new Set([...readSet(clearedKey, new Set()), ...cleared]));
            renderItems(state.items); updateBadge();
        } catch (_) { /* Keep local dismissals and retry when the connection returns. */ }
        finally { syncing = false; }
        // A dismissal made while a request was in flight must not wait for polling.
        if (saved && pending.size) void sync();
    }
    readState();
    pending = new Set(items.filter(item => cleared.has(item.dataset.notificationKey)).map(item => item.dataset.notificationKey));
    updateBadge();
    void sync();
    if (endpoint) {
        window.setInterval(() => void sync(), 20000);
        window.addEventListener('focus', () => void sync());
        window.addEventListener('online', () => void sync());
        window.addEventListener('pageshow', () => void sync());
        document.addEventListener('visibilitychange', () => { if (!document.hidden) void sync(); });
        panel.addEventListener('toggle', event => { if (event.newState === 'open') void sync(); });
    }
    window.addEventListener('storage', event => {
        if (event.key !== clearedKey && event.key !== null) return;
        readState();
        items.filter(item => cleared.has(item.dataset.notificationKey)).forEach(item => pending.add(item.dataset.notificationKey));
        updateBadge();
        void sync();
    });
})();
