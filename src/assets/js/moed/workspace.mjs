export function initializeInquiryTabs() {
    document.querySelectorAll('[data-inquiry-tabs], [data-record-tabs]').forEach(function (group) {
        const tabs = Array.from(group.querySelectorAll('[role="tab"]'));
        const panels = tabs.map(function (tab) {
            return document.getElementById(tab.getAttribute('aria-controls') || '');
        });
        if (tabs.length === 0 || panels.some(function (panel) { return !panel; })) return;

        const pickerLabel = document.createElement('label');
        pickerLabel.className = 'record-section-picker';
        pickerLabel.textContent = 'Section';
        const picker = document.createElement('select');
        picker.setAttribute('aria-label', 'Record section');
        tabs.forEach(function (tab, index) {
            picker.add(new Option(tab.textContent.trim().replace(/\s+/g, ' '), String(index)));
        });
        pickerLabel.appendChild(picker);
        group.prepend(pickerLabel);
        group.classList.add('has-section-picker');
        picker.addEventListener('change', function () { activate(Number(picker.value), false, true); });

        const activate = function (index, focus, remember) {
            tabs.forEach(function (tab, tabIndex) {
                const selected = tabIndex === index;
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
                panels[tabIndex].hidden = !selected;
            });
            // Keep this history entry on the chosen tab without creating a
            // Back-button step for every tab click or moving the scroll position.
            picker.value = String(index);
            if (remember) window.history.replaceState(window.history.state, '', '#' + panels[index].id);
            if (focus) tabs[index].focus();
        };

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { activate(index, false, true); });
            tab.addEventListener('keydown', function (event) {
                let next = null;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === null) return;
                event.preventDefault();
                activate(next, true, true);
            });
        });

        const hashTarget = function (hash) {
            let id = hash.slice(1);
            if (!id) return null;
            try { id = decodeURIComponent(id); } catch (error) { /* Keep literal malformed fragments. */ }
            return document.getElementById(id);
        };
        const panelForTarget = function (target) {
            return target ? panels.findIndex(function (panel) {
                return panel === target || panel.contains(target);
            }) : -1;
        };
        const requestedTarget = hashTarget(window.location.hash);
        const requestedPanel = panelForTarget(requestedTarget);
        const selectedTab = tabs.findIndex(function (tab) {
            return tab.getAttribute('aria-selected') === 'true';
        });
        activate(requestedPanel >= 0 ? requestedPanel : Math.max(0, selectedTab), false);
        if (requestedPanel >= 0) requestedTarget.scrollIntoView({ block: 'start' });

        window.addEventListener('hashchange', function () {
            const target = hashTarget(window.location.hash);
            const index = panelForTarget(target);
            if (index < 0) return;
            activate(index, false);
            target.scrollIntoView({ block: 'start' });
        });

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('a[href^="#"]');
            if (!link) return;
            const index = panelForTarget(hashTarget(link.getAttribute('href')));
            if (index >= 0) activate(index, false);
        });

        const openNoteButton = group.querySelector('[data-inquiry-open-note]');
        const addNote = group.querySelector('.inquiry-add-note');
        if (openNoteButton && addNote) {
            openNoteButton.addEventListener('click', function () {
                activate(0, false, true);
                addNote.open = true;
                const textarea = addNote.querySelector('textarea');
                if (textarea) textarea.focus();
            });
        }
    });

    document.querySelectorAll('[data-inquiry-stage-target]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const select = document.getElementById('inquiry-stage');
            const reason = document.getElementById('inquiry-stage-reason');
            const target = trigger.dataset.inquiryStageTarget || '';
            if (!select || !Array.from(select.options).some(function (option) { return option.value === target; })) return;
            select.value = target;
            window.setTimeout(function () { (reason || select).focus(); }, 0);
        });
    });

    document.querySelectorAll('[data-inquiry-filter]').forEach(function (form) {
        form.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { form.requestSubmit(); });
        });
    });
}

export function initializeDisclosurePopovers() {
    const disclosures = Array.from(document.querySelectorAll('details[data-disclosure-popover]'));
    if (disclosures.length === 0) return;

    function closeDisclosure(disclosure, restoreFocus) {
        if (!disclosure.open) return;
        disclosure.open = false;
        if (restoreFocus) {
            const summary = disclosure.querySelector(':scope > summary');
            if (summary) summary.focus();
        }
    }

    disclosures.forEach(function (disclosure) {
        disclosure.addEventListener('toggle', function () {
            if (!disclosure.open) return;
            disclosures.forEach(function (candidate) {
                if (candidate !== disclosure) closeDisclosure(candidate, false);
            });
        });
        disclosure.querySelectorAll('[data-disclosure-popover-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeDisclosure(disclosure, true);
            });
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const openDisclosure = disclosures.find(function (disclosure) {
            return disclosure.open;
        });
        if (!openDisclosure) return;
        event.preventDefault();
        closeDisclosure(openDisclosure, true);
    });
}

export function initializeDashboardHighlights() {
    document.querySelectorAll('[data-dashboard-highlight]').forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const panel = document.getElementById(card.dataset.dashboardHighlight);
            if (!panel) return;
            panel.classList.remove('dashboard-panel-highlight');
            // Flush the removed class so each click restarts the outline animation.
            panel.getBoundingClientRect();
            panel.classList.add('dashboard-panel-highlight');
        });
    });
}

export function initializeStatusMessages() {
    document.querySelectorAll('.error:not([role]), .dialog-inline-error:not([role])').forEach(function (message) {
        message.setAttribute('role', 'alert');
    });
    document.querySelectorAll('.success:not([role]), .warning:not([role])').forEach(function (message) {
        message.setAttribute('role', 'status');
    });
}

export function initializeIconTooltips() {
    const selector = '.action-icon-button, [data-tooltip]';
    function normalize(control) {
        if (!control.matches(selector)) return;
        const title = control.getAttribute('title');
        if (title === null) return;
        if (!control.getAttribute('data-tooltip')) control.setAttribute('data-tooltip', title);
        if (!control.getAttribute('aria-label') && !control.getAttribute('aria-labelledby') && !control.textContent.trim()) {
            control.setAttribute('aria-label', title);
        }
        control.removeAttribute('title');
    }
    document.querySelectorAll(selector).forEach(normalize);
    // AJAX lists and copy controls can insert icons or update their titles.
    if (typeof MutationObserver !== 'undefined') {
        new MutationObserver(function (changes) {
            changes.forEach(function (change) {
                if (change.type === 'attributes') normalize(change.target);
                else change.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    normalize(node);
                    node.querySelectorAll(selector).forEach(normalize);
                });
            });
        }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['title', 'data-tooltip', 'class'] });
    }
}
