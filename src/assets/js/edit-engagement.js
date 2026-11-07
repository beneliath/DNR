(function () {
    'use strict';

    function trackUnsavedEngagement(form) {
        const save = form.elements.namedItem('save_engagement');
        if (!save) return;
        function snapshot() {
            const data = new FormData(form);
            data.delete('edit_baseline');
            data.delete('csrf_token');
            data.delete('return_to');
            // Track what the user typed even while the derived submission time is invalid.
            for (const field of form.querySelectorAll('.presentation-time-field')) {
                data.set(field.querySelector('input[type="hidden"]').name, field.querySelector('input[type="text"]').value);
            }
            return JSON.stringify(Array.from(data, ([name, value]) => [
                name, typeof value === 'string' ? value : [value.name, value.size, value.name ? value.lastModified : 0]
            ]));
        }
        const baseline = snapshot();
        const rejectedSave = save.hasAttribute('data-unsaved-changes');
        const originalHash = form.elements.namedItem('edit_baseline');
        const returnedHash = originalHash?.value;
        async function hash(values) {
            try {
                const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(values));
                return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
            } catch (_) { return ''; }
        }
        // Carry only the fingerprint through a failed POST so undo can still
        // recognize the saved record even after the server redisplays an edit.
        if (originalHash && !rejectedSave && window.crypto?.subtle) {
            hash(baseline).then(value => { originalHash.value = value; });
        }
        let pending = false;
        let revision = 0;
        function update() {
            if (pending) return;
            pending = true;
            requestAnimationFrame(async () => {
                pending = false;
                const currentRevision = ++revision;
                const current = snapshot();
                const changed = rejectedSave
                    ? (!returnedHash || !window.crypto?.subtle || await hash(current) !== returnedHash)
                    : current !== baseline;
                if (currentRevision !== revision) return;
                save.toggleAttribute('data-unsaved-changes', changed);
                if (changed) save.setAttribute('aria-description', 'Unsaved changes');
                else save.removeAttribute('aria-description');
            });
        }
        function fieldChanged(event) {
            // Chron log fields belong to this form but live outside its DOM subtree.
            if (event.target.form === form) update();
        }
        document.addEventListener('input', fieldChanged);
        document.addEventListener('change', fieldChanged);
        form.addEventListener('reset', update);
        new MutationObserver(update).observe(form, {childList: true, subtree: true});
        window.addEventListener('pageshow', update);
    }

    function restoreScrollOnReload() {
        const storage = window.DnrAccountContext.session;
        const key = 'edit-engagement-scroll';
        const scroller = () => getComputedStyle(document.body).position === 'fixed'
            ? document.body : document.scrollingElement;

        window.addEventListener('pagehide', function () {
            try {
                storage.setItem(key, JSON.stringify({url: location.href, top: scroller().scrollTop}));
            } catch (_) { /* Storage is optional. */ }
        });
        if (performance.getEntriesByType('navigation')[0]?.type !== 'reload') return;
        let saved;
        try { saved = JSON.parse(storage.getItem(key)); } catch (_) { /* Use the fragment. */ }
        const top = saved?.url === location.href && Number.isFinite(saved.top) ? saved.top : null;
        if (top === null && !location.hash) return;

        // This page scrolls inside the account shell. Restore that element after
        // the form has its final height and before revealing the reloaded content.
        const nativeRestoration = history.scrollRestoration;
        history.scrollRestoration = 'manual';
        document.documentElement.setAttribute('data-engagement-restoring', '');
        document.addEventListener('DOMContentLoaded', function () {
            requestAnimationFrame(() => {
                if (top !== null) scroller().scrollTo({top, behavior: 'instant'});
                else document.getElementById(location.hash.slice(1))?.scrollIntoView({behavior: 'instant'});
                document.documentElement.removeAttribute('data-engagement-restoring');
            });
        }, {once: true});
        window.addEventListener('pageshow', function () {
            requestAnimationFrame(() => { history.scrollRestoration = nativeRestoration; });
        }, {once: true});
    }

    restoreScrollOnReload();
    document.addEventListener('DOMContentLoaded', function () {
        trackUnsavedEngagement(document.getElementById('engagement-edit-form'));
    }, {once: true});
})();
