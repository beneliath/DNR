import { wireAudiencePagination } from './audience-pagination.mjs';
wireAudiencePagination();
import { enhanceColorInput } from './audience-color-picker.mjs';
import { prepareAudienceImage, imageFileSize, matchAudienceBrandLogos } from './audience-image-upload.mjs';
import { wireAudienceFileDrop, enhanceAudienceFileInput, setFileDropStatus, selectedUploadFile } from './audience-file-drop.mjs';
(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    // A pointer click can retain :focus-visible on a checkbox already focused by the keyboard.
    document.addEventListener('pointerdown', () => document.body.classList.add('audience-pointer-input'), true);
    document.addEventListener('keydown', event => {
        if (!['Shift','Control','Alt','Meta'].includes(event.key)) document.body.classList.remove('audience-pointer-input');
    }, true);
    // Match the shared shell's fixed account/preview stack, including wrapped mobile labels.
    const accountBanner = $('.account-identity-banner');
    const previewBanner = $('[data-role-preview-banner]');
    const deploymentBanner = $('[data-deployment-notice]');
    const adminBanner = $('[data-admin-unlock]');
    if (accountBanner || previewBanner || deploymentBanner || adminBanner) {
        const syncBanners = () => {
            document.body.style.setProperty('--audience-account-banner-height', `${accountBanner?.offsetHeight || 0}px`);
            document.body.style.setProperty('--audience-preview-banner-height', `${previewBanner?.offsetHeight || 0}px`);
            sidebarPosition();
        };
        const bannerObserver = new ResizeObserver(syncBanners);
        [deploymentBanner, accountBanner, previewBanner, adminBanner].filter(Boolean).forEach(banner => bannerObserver.observe(banner));
        window.addEventListener('resize', syncBanners);
        // Defer until the sidebar position helper has been initialized below.
        requestAnimationFrame(syncBanners);
    }
    $$('[data-audience-logo]').forEach(logo => {
        const dashboard = event => {
            if (!event.ctrlKey || (event.type === 'click' && event.button !== 0)) return;
            event.preventDefault();
            window.location.assign(logo.dataset.dashboardUrl);
        };
        logo.addEventListener('click', dashboard);
        // macOS emits contextmenu instead of click for Control-click.
        logo.addEventListener('contextmenu', dashboard);
    });
    // Never reuse browser form history from the rest of MOED. Explicit credential hints remain intact.
    $$('form:not([autocomplete]), input:not([autocomplete]), textarea:not([autocomplete])').forEach(control => control.setAttribute('autocomplete', 'off'));
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const syncTheme = () => {
        let savedTheme; try { savedTheme = localStorage.getItem('theme'); } catch (_) { /* Storage can be disabled. */ }
        document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark' || (!savedTheme && systemTheme.matches));
    };
    window.addEventListener('storage', event => { if (event.key === 'theme' || event.key === null) syncTheme(); });
    window.addEventListener('pageshow', syncTheme);
    systemTheme.addEventListener('change', syncTheme);
    const sidebarToggle = $('[data-sidebar-toggle]');
    const sidebarKey = 'moed:audience:sidebar-collapsed';
    const mobileNavigation = window.matchMedia('(max-width: 860px)');
    let sidebarCollapsed = false;
    const sidebarPosition = () => {
        const item = $('.audience-nav-item.selected');
        if (item) document.documentElement.style.setProperty('--audience-subnav-top', `${item.getBoundingClientRect().top}px`);
    };
    const setSidebar = collapsed => {
        sidebarCollapsed = collapsed;
        document.body.classList.toggle('audience-sidebar-collapsed', collapsed && !mobileNavigation.matches);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Minimize navigation');
            sidebarToggle.title = collapsed ? 'Expand navigation' : 'Minimize navigation';
        }
        sidebarPosition();
    };
    try { setSidebar(localStorage.getItem(sidebarKey) === '1'); } catch (_) { setSidebar(false); }
    sidebarToggle?.addEventListener('click', () => {
        const collapsed = !sidebarCollapsed;
        setSidebar(collapsed);
        try { localStorage.setItem(sidebarKey, collapsed ? '1' : '0'); } catch (_) { /* Still works without storage. */ }
    });
    window.addEventListener('resize', sidebarPosition);
    $('.audience-sidebar nav')?.addEventListener('scroll', sidebarPosition);
    // Match the main MOED mobile drawer's dismissal and keyboard behavior.
    const sidebar = $('#audience-sidebar');
    const mobileToggle = $('[data-audience-nav-toggle]');
    const navigationClose = $('[data-audience-nav-close]');
    const navigationBackdrop = $('[data-audience-nav-backdrop]');
    if (sidebar && mobileToggle && navigationClose && navigationBackdrop) {
        let navigationOpen = false;
        const suspended = new Map();
        const setNavigationOpen = (requested, restoreFocus = true) => {
            const wasOpen = navigationOpen;
            navigationOpen = requested && mobileNavigation.matches;
            suspended.forEach((wasInert, element) => { element.inert = wasInert; });
            suspended.clear();
            document.body.classList.toggle('audience-navigation-open', navigationOpen);
            mobileToggle.setAttribute('aria-expanded', String(navigationOpen));
            mobileToggle.setAttribute('aria-label', `${navigationOpen ? 'Close' : 'Open'} MessageMonkey navigation`);
            navigationBackdrop.hidden = !navigationOpen;
            sidebar.inert = mobileNavigation.matches && !navigationOpen;
            if (navigationOpen) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
                Array.from(document.body.children).filter(element =>
                    element !== sidebar && element !== navigationBackdrop && !element.matches('script, style, link, dialog')
                ).forEach(element => {
                    suspended.set(element, element.inert);
                    element.inert = true;
                });
                navigationClose.focus();
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
                if (wasOpen && restoreFocus && mobileNavigation.matches) mobileToggle.focus();
            }
        };
        mobileToggle.addEventListener('click', () => setNavigationOpen(!navigationOpen));
        navigationClose.addEventListener('click', () => setNavigationOpen(false));
        navigationBackdrop.addEventListener('click', () => setNavigationOpen(false));
        sidebar.addEventListener('click', event => {
            if (navigationOpen && event.target.closest('a[href]')) setNavigationOpen(false);
        });
        document.addEventListener('keydown', event => {
            if (!navigationOpen || $('dialog[open]')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                setNavigationOpen(false);
            } else if (event.key === 'Tab') {
                const controls = $$('a[href], button:not([disabled]), [tabindex="0"]', sidebar)
                    .filter(element => !element.closest('[hidden], [inert]') && element.getClientRects().length > 0);
                const first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                    event.preventDefault(); last?.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                    event.preventDefault(); first?.focus();
                }
            }
        });
        mobileNavigation.addEventListener('change', () => {
            setSidebar(sidebarCollapsed);
            setNavigationOpen(false);
        });
        setNavigationOpen(false, false);
    }
    const config = JSON.parse($('#audience-config')?.textContent || '{}');
    document.addEventListener('admin-unlock-changed', event => { if (typeof event.detail?.csrf_token === 'string') config.csrf = event.detail.csrf_token; });
    $$('[data-audience-admin-unlock]').forEach(button => button.addEventListener('click', () => window.DnrAdminUnlock?.request()));
    const clone = value => JSON.parse(JSON.stringify(value));
    const show = id => { const dialog = document.getElementById(id); if (dialog instanceof HTMLDialogElement && !dialog.open) dialog.showModal(); };
    const el = (tag, attrs = {}, text) => {
        const node = document.createElement(tag);
        if (['form', 'input', 'textarea'].includes(tag)) node.setAttribute('autocomplete', 'off');
        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'class') node.className = value;
            else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
            else if (value !== false && value != null) node.setAttribute(key, String(value));
        }
        if (text != null) node.textContent = text;
        return node;
    };
    const button = (text, action, label = text) => el('button', {type: 'button', onclick: action, 'aria-label': label}, text);
    const setValue = (form, name, value) => { const control = form?.elements.namedItem(name); if (control) control.value = value ?? ''; };
    const hidden = (name, value) => el('input', {type: 'hidden', name, value});
    $$('[data-open-dialog]').filter(b => !b.closest('[data-audience-filter-summary], [data-audience-results]')).forEach(b => b.addEventListener('click', async event => {
        event.preventDefault();
        if (b.hasAttribute('data-admin-unlock-required') && !(await window.DnrAdminUnlock?.request())) return;
        show(b.dataset.openDialog);
    }));
    document.addEventListener('invalid', event => {
        let details = event.target.closest('details');
        while (details) { details.open = true; details = details.parentElement.closest('details'); }
    }, true);
    $$('dialog.person-editor').forEach(dialog => dialog.addEventListener('close', () => dialog.querySelector('form')?.reset()));
    $$('dialog[data-reopen-dialog]').forEach(dialog => show(dialog.id));
    $$('[data-close-dialog]').forEach(b => b.addEventListener('click', () => b.closest('dialog')?.close()));
    const searchDialog = $('#audience-search'), searchTrigger = $('[data-global-search-open]');
    if (searchDialog && searchTrigger) {
        const form = $('[data-workspace-search]', searchDialog), input = $('input[name=q]', form);
        const results = $('[data-workspace-search-results]', searchDialog), status = $('[data-workspace-search-status]', searchDialog);
        const historyKey = `moed:audience:search:${document.body.dataset.audienceKey}`;
        let category = '', request, timer, sequence = 0;
        const history = () => {
            try { const saved = JSON.parse(localStorage.getItem(historyKey) || '[]'); return Array.isArray(saved) ? saved.filter(v => typeof v === 'string' && v.trim() && v.length <= 200).slice(0, 5) : []; }
            catch (_) { return []; }
        };
        const remember = () => {
            const value = input.value.trim(); if (!value) return;
            try { localStorage.setItem(historyKey, JSON.stringify([value, ...history().filter(v => v.toLocaleLowerCase() !== value.toLocaleLowerCase())].slice(0, 5))); } catch (_) { /* Search works without storage. */ }
        };
        const icon = name => {
            const span = el('span', {class: `audience-search-icon ${name}`, 'aria-hidden': 'true'});
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', ({history:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',campaign:'M3 5h18v14H3zM3 6l9 7 9-7',person:'M8 7a4 4 0 1 0 8 0 4 4 0 1 0-8 0M4 21v-3a8 8 0 0 1 16 0v3',page:'M5 3h10l4 4v14H5zM14 3v5h5M8 12h8M8 16h6',action:'M13 3L5 14h6l-1 7 9-12h-7z',help:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9 9a3 3 0 1 1 4 3v2M12 17h.01'})[name] || 'M3 5h18v14H3z');
            svg.append(path); span.append(svg); return span;
        };
        const section = title => { const node = el('section', {class:'audience-search-section'}); node.append(el('h2', {}, title)); results.append(node); return node; };
        const renderHistory = () => {
            const node = section('Recent searches'), terms = history();
            if (!terms.length) node.append(el('p', {class:'audience-help'}, 'No recent searches yet.'));
            terms.forEach(term => {
                const row = button(term, () => { input.value = term; remember(); search(); input.focus(); });
                row.className = 'audience-search-row'; row.replaceChildren(icon('history'), el('span', {class:'audience-search-title'}, term)); node.append(row);
            });
        };
        const renderItem = item => {
            const row = el('a', {class:'audience-search-row',href:item.href}), copy = el('span', {class:'audience-search-copy'});
            const title = el('span', {class:'audience-search-title'}, item.title);
            if (item.status) { const labels = {draft:'Draft',sent:'Sent',sending:'Sending',scheduled:'Scheduled',paused:'Paused'}; title.append(el('span', {class:`audience-search-status ${item.status === 'sent' ? 'sent' : ''}`}, labels[item.status] || item.status)); }
            copy.append(title); let detail = item.description;
            if (item.updated_at) { const date = new Date(item.updated_at.replace(' ', 'T') + 'Z'); if (!Number.isNaN(date.getTime())) detail += ` • Last edited ${new Intl.DateTimeFormat(undefined, {weekday:'short',month:'long',day:'numeric',year:'numeric'}).format(date)}`; }
            copy.append(el('span', {class:'audience-search-detail'}, detail)); row.append(icon(item.icon), copy);
            if (item.external) { row.target = '_blank'; row.rel = 'noopener noreferrer'; row.setAttribute('aria-label', `${item.title} (opens in a new tab)`); }
            row.addEventListener('click', remember); return row;
        };
        const search = async () => {
            clearTimeout(timer); request?.abort(); const turn = ++sequence, query = input.value.trim();
            results.replaceChildren(); if (!query && !category) renderHistory();
            results.setAttribute('aria-busy', 'true'); status.textContent = 'Searching…';
            const loading = el('p', {class:'audience-search-empty'}, 'Searching…'); results.append(loading);
            const endpoint = new URL(config.url || 'audience.php', location.href); endpoint.search = new URLSearchParams({api:'workspace_search',q:query,category}).toString();
            request = new AbortController();
            try {
                const response = await fetch(endpoint, {signal:request.signal,headers:{Accept:'application/json'},cache:'no-store'});
                if (!response.ok || response.redirected) throw new Error('Search unavailable');
                const data = await response.json(); if (turn !== sequence || !searchDialog.open) return;
                loading.remove();
                let count = 0;
                data.groups.forEach(group => {
                    if (!group.items.length && query) return;
                    const node = section(group.label); count += group.items.length;
                    group.items.forEach(item => node.append(renderItem(item)));
                    if (!group.items.length) node.append(el('p', {class:'audience-help'}, group.key === 'campaigns' ? 'No recent campaigns yet.' : 'No items are available for your role.'));
                    if (query && group.more && group.items.length) { const more = el('a', {class:'audience-search-more',href:group.more}, `View all ${group.label.toLocaleLowerCase()}`); more.addEventListener('click', remember); node.append(more); }
                });
                if (!count && (query || category === 'connections')) results.append(el('p', {class:'audience-search-empty'}, query ? `No results for “${query}”.` : 'Search connections by name or email address.'));
                status.textContent = query ? `${count} search results.` : 'Search suggestions loaded.';
            } catch (error) {
                if (error.name !== 'AbortError' && turn === sequence && searchDialog.open) { loading.remove(); results.append(el('p', {class:'audience-search-empty'}, 'Search is temporarily unavailable. Try again.')); status.textContent = 'Search is unavailable.'; }
            } finally { if (turn === sequence) results.removeAttribute('aria-busy'); }
        };
        searchTrigger.addEventListener('click', () => { if (!searchDialog.open) { searchDialog.showModal(); input.focus(); search(); } });
        form.addEventListener('submit', event => { event.preventDefault(); remember(); search(); });
        input.addEventListener('input', () => { request?.abort(); ++sequence; clearTimeout(timer); timer = setTimeout(search, 250); });
        $$('[data-search-category]', searchDialog).forEach(control => control.addEventListener('click', () => { category = category === control.dataset.searchCategory ? '' : control.dataset.searchCategory; $$('[data-search-category]', searchDialog).forEach(b => b.setAttribute('aria-pressed', String(b.dataset.searchCategory === category))); search(); }));
        searchDialog.addEventListener('close', () => { clearTimeout(timer); request?.abort(); ++sequence; results.removeAttribute('aria-busy'); searchTrigger.focus(); });
        searchDialog.addEventListener('click', event => { const r = searchDialog.getBoundingClientRect(); if (event.target === searchDialog && (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom)) searchDialog.close(); });
    }
    $$('[data-auto-submit]').filter(input => !input.closest('[data-audience-results]')).forEach(input => input.addEventListener('change', () => input.form.requestSubmit()));
    const definitionSelection = $('[data-definition-selection]');
    if (definitionSelection) {
        const items = $$('[data-definition-item]'), all = $('[data-definition-all]', definitionSelection);
        const actions = $('[data-definition-actions]', definitionSelection), count = $('[data-definition-count]', definitionSelection);
        const refresh = () => {
            const selected = items.filter(item => item.checked).length;
            all.checked = items.length > 0 && selected === items.length;
            all.indeterminate = selected > 0 && selected < items.length;
            all.disabled = items.length === 0;
            actions.hidden = selected === 0;
            count.textContent = `${selected} Selected`;
            $('button[type="submit"]', definitionSelection).disabled = selected === 0;
            items.forEach(item => item.closest('[data-definition-row],tr')?.classList.toggle('definition-selected', item.checked));
        };
        all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); refresh(); });
        items.forEach(item => item.addEventListener('change', refresh));
        $('[data-definition-clear]', definitionSelection).addEventListener('click', () => { items.forEach(item => { item.checked = false; }); refresh(); });
        window.addEventListener('pageshow', refresh); refresh();
    }
    const confirmedForms = new WeakSet();
    const approvedTagDeletes = new WeakSet(), checkingTagDeletes = new WeakSet();
    const confirmation = el('dialog', {'aria-labelledby': 'audience-confirm-title', 'aria-describedby': 'audience-confirm-message'});
    const confirmationForm = el('form', {method: 'dialog'}), confirmationMessage = el('p', {id: 'audience-confirm-message'});
    const confirmationActions = el('div', {class: 'form-actions'});
    confirmationActions.append(el('button', {value: 'cancel', autofocus: true}, 'Cancel'), el('button', {value: 'continue', class: 'primary'}, 'Continue'));
    confirmationForm.append(el('h2', {id: 'audience-confirm-title'}, 'Confirm action'), confirmationMessage, confirmationActions);
    confirmation.append(confirmationForm); document.body.append(confirmation);
    let pendingConfirmation = null;
    confirmation.addEventListener('close', () => {
        const pending = pendingConfirmation; pendingConfirmation = null;
        if (confirmation.returnValue === 'continue' && pending) { confirmedForms.add(pending.form); pending.form.requestSubmit(pending.submitter || undefined); }
    });
    $$('form[data-confirm]').forEach(form => form.addEventListener('submit', async event => {
        if (approvedTagDeletes.has(form)) { approvedTagDeletes.delete(form); return; }
        const confirmed = confirmedForms.delete(form), tagDelete = form.hasAttribute('data-tag-delete-form');
        if (confirmed && !tagDelete) return;
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (tagDelete) {
            if (checkingTagDeletes.has(form)) return;
            checkingTagDeletes.add(form);
            let unlocked;
            try { unlocked = await window.DnrAdminUnlock?.request(); }
            finally { checkingTagDeletes.delete(form); }
            if (!unlocked) return;
            if (confirmed) { approvedTagDeletes.add(form); form.requestSubmit(event.submitter || undefined); return; }
        }
        pendingConfirmation = {form, submitter: event.submitter};
        confirmationMessage.textContent = form.dataset.confirm; confirmation.returnValue = ''; confirmation.showModal();
    }));
    $$('[data-filter-search]').forEach(input => input.addEventListener('input', () => {
        const panel = input.closest('[data-filter-panel]');
        const options = $$('[data-filter-choice]', panel);
        options.forEach(choice => { choice.hidden = !choice.textContent.toLowerCase().includes(input.value.toLowerCase()); });
        $('[data-filter-empty]', panel).hidden = options.some(choice => !choice.hidden) || !options.length;
    }));
    const filterForm = $('.audience-filter-popovers'), peopleResults = $('[data-audience-results]'), filterSummary = $('[data-audience-filter-summary]');
    let filterRequest, filterSequence = 0, filterSearchTimer, filterPending = Promise.resolve(true), refreshPeopleColumns = () => {};
    const refreshFilters = () => {
        clearTimeout(filterSearchTimer); filterSearchTimer = undefined; const sequence = ++filterSequence; filterRequest?.abort(); filterRequest = new AbortController();
        const url = new URL(config.url, location.href), data = new FormData(filterForm);
        for (const [key,value] of data) if (!['open_filter','filter_query'].includes(key)) url.searchParams.append(key,String(value));
        peopleResults.inert = true; peopleResults.setAttribute('aria-busy','true'); $('[data-filter-error]').hidden = true;
        filterPending = (async () => {
            try {
                const response = await fetch(url,{credentials:'same-origin',headers:{Accept:'text/html'},cache:'no-store',signal:filterRequest.signal});
                if (!response.ok) throw new Error('Unable to update these filters. Try again.');
                const page = new DOMParser().parseFromString(await response.text(),'text/html');
                if (sequence !== filterSequence) return false;
                const nextResults = $('[data-audience-results]',page), nextConfig = $('#audience-config',page), nextSummary = $('[data-audience-filter-summary]',page);
                if (!nextResults || !nextConfig || !nextSummary) throw new Error('Sign in again, then reload this page to update filters.');
                const problem = $('[data-operation-error]',page); if (problem) throw new Error(problem.textContent.trim());
                const parsed = JSON.parse(nextConfig.textContent), columnsTrigger = $('[popovertarget="columns"]',peopleResults);
                if (columnsTrigger) $('[popovertarget="columns"]',nextResults)?.replaceWith(columnsTrigger);
                peopleResults.replaceChildren(...nextResults.childNodes); filterSummary.replaceChildren(...nextSummary.childNodes);
                Object.assign(config,parsed); $('#audience-config').textContent = nextConfig.textContent;
                for (const selector of ['#bulk-people-form','#save-current-segment form','#filter-campaign form']) {
                    const form = $(selector), next = $(selector,page); if (!form || !next) continue;
                    $$('input[type="hidden"]',form).forEach(input => input.remove());
                    form.append(...$$('input[type="hidden"]',next).map(input => input.cloneNode(true)));
                }
                $$('[data-instant-filter]',filterForm).forEach(input => { const next = $$('[data-instant-filter]',page).find(other => other.name === input.name && other.value === input.value); if (next) input.checked = next.checked; });
                $$('[popovertarget]',filterForm).forEach(trigger => {
                    const next = $$('[popovertarget]',page).find(other => other.getAttribute('popovertarget') === trigger.getAttribute('popovertarget'));
                    if (next) { trigger.replaceChildren(...Array.from(next.childNodes).map(node => node.cloneNode(true))); trigger.className = next.className; }
                });
                const suggestions = $('#connection-search-options'), nextSuggestions = $('#connection-search-options',page); if (suggestions && nextSuggestions) suggestions.replaceChildren(...nextSuggestions.childNodes);
                allMatching = false; selectedBoxes = $$('[data-person-select]'); updateSelection(); refreshPeopleColumns();
                window.dispatchEvent(new Event('resize'));
                history.replaceState(null,'',url); return true;
            } catch (error) {
                if (error.name === 'AbortError' || sequence !== filterSequence) return false;
                const notice = $('[data-filter-error]'); notice.textContent = error.message; notice.hidden = false; return false;
            } finally { if (sequence === filterSequence) { peopleResults.inert = false; peopleResults.removeAttribute('aria-busy'); } }
        })();
        return filterPending;
    };
    const currentFiltersReady = () => filterSearchTimer ? refreshFilters() : filterPending;
    $$('[data-instant-filter]').forEach(input => input.addEventListener('change',refreshFilters));
    filterForm?.addEventListener('submit',event => { event.preventDefault(); refreshFilters(); });
    $('#contact-search')?.addEventListener('input',() => { clearTimeout(filterSearchTimer); filterSearchTimer = setTimeout(refreshFilters,300); });
    $('#contact-search')?.addEventListener('search',refreshFilters);
    document.addEventListener('click', event => {
        $$('details.audience-menu[open], details.audience-filter[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
    });

    // Reference hover help is also available on keyboard focus and remains hoverable.
    let openTooltip = null;
    const hideTooltip = tooltip => { tooltip.hidePopover(); tooltip.hidden = true; if (openTooltip === tooltip) openTooltip = null; };
    $$('[data-audience-tooltip], .action-icon-button[data-tooltip], .audience-account-tools>[data-tooltip]').forEach((target, index) => {
        const icon = target.matches('.action-icon-button, .audience-account-tools>[data-tooltip]');
        const tooltip = el('div', {id: `audience-tooltip-${index}`, role: 'tooltip', class: `audience-tooltip${icon ? ' audience-icon-tooltip' : ''}`, popover: 'manual', hidden: true}, target.dataset.audienceTooltip || target.dataset.tooltip);
        document.body.append(tooltip); target.setAttribute('aria-describedby', tooltip.id);
        let timer;
        const hide = () => hideTooltip(tooltip);
        const display = () => {
            if (target.matches('.audience-nav-item.selected') && document.body.classList.contains('audience-sidebar-collapsed') && !mobileNavigation.matches) return;
            window.clearTimeout(timer); if (openTooltip && openTooltip !== tooltip) hideTooltip(openTooltip);
            tooltip.textContent = target.dataset.audienceTooltip || target.dataset.tooltip;
            tooltip.hidden = false; tooltip.showPopover(); openTooltip = tooltip;
            const rect = target.getBoundingClientRect(), box = tooltip.getBoundingClientRect();
            const beside = !icon && rect.right + box.width + 20 <= innerWidth;
            const above = icon && !target.closest('.admin-unlock-banner, .audience-account-tools') && rect.top >= box.height + 16;
            tooltip.classList.toggle('tooltip-below', !beside && !above);
            tooltip.classList.toggle('tooltip-above', above);
            tooltip.style.left = `${beside ? rect.right + 12 : Math.max(8, Math.min(rect.left, innerWidth - box.width - 8))}px`;
            tooltip.style.top = `${Math.max(8, Math.min(beside ? rect.top + (rect.height - box.height) / 2 : above ? rect.top - box.height - 8 : rect.bottom + 8, innerHeight - box.height - 8))}px`;
        };
        const hideLater = () => { timer = window.setTimeout(() => { if (!target.matches(':hover,:focus') && !tooltip.matches(':hover')) hide(); }, 140); };
        target.addEventListener('mouseenter', display); target.addEventListener('focus', display);
        target.addEventListener('mouseleave', hideLater); target.addEventListener('blur', hideLater);
        tooltip.addEventListener('mouseenter', () => window.clearTimeout(timer)); tooltip.addEventListener('mouseleave', hideLater);
        target.addEventListener('click', hide);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && openTooltip) { hideTooltip(openTooltip); } });
    window.addEventListener('scroll', () => { if (openTooltip) { hideTooltip(openTooltip); } }, true);

    // Native popovers render above table clipping and retain light-dismiss / Escape behavior.
    $$('[popovertarget]').filter(trigger => trigger.getAttribute('popovertargetaction') !== 'hide').forEach(trigger => {
        const panel = document.getElementById(trigger.getAttribute('popovertarget'));
        if (!panel) return;
        const place = () => {
            const rect = trigger.getBoundingClientRect(), gap = panel.hasAttribute('data-filter-panel') ? 12 : 8;
            const availableBelow = innerHeight - rect.bottom - gap - 8;
            const height = Math.min(panel.scrollHeight, innerHeight - 16);
            const below = availableBelow >= height || availableBelow >= rect.top - 16;
            const columns = panel.id === 'columns';
            panel.style.maxHeight = `${columns ? innerHeight - 16 : Math.max(120, below ? availableBelow : rect.top - gap - 8)}px`;
            const box = panel.getBoundingClientRect();
            panel.style.left = `${Math.max(8, Math.min(panel.hasAttribute('data-filter-panel') ? rect.left : rect.right - box.width, innerWidth - box.width - 8))}px`;
            panel.style.top = `${Math.max(8, columns ? Math.min(rect.bottom + gap, innerHeight - box.height - 8) : below ? rect.bottom + gap : rect.top - box.height - gap)}px`;
        };
        panel.addEventListener('toggle', event => {
            trigger.setAttribute('aria-expanded', String(event.newState === 'open'));
            if (event.newState === 'open') place();
        });
        window.addEventListener('resize', () => { if (panel.matches(':popover-open')) place(); });
        window.addEventListener('scroll', event => { if (panel.matches(':popover-open') && !panel.contains(event.target)) place(); }, true);
        if (panel.hasAttribute('data-filter-panel')) {
            const choices = () => $$('input, a[href]', panel).filter(node => !node.closest('[hidden]'));
            const enter = () => choices()[0]?.focus();
            trigger.addEventListener('keydown', event => { if (event.key === 'ArrowDown') { event.preventDefault(); panel.showPopover(); place(); enter(); } });
            panel.addEventListener('toggle', event => { if (event.newState === 'open') enter(); });
            panel.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); panel.hidePopover(); trigger.focus(); return; }
                if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
                const items = choices(), index = items.indexOf(document.activeElement);
                event.preventDefault(); items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
            });
            if (panel.hasAttribute('data-reopen-filter')) { panel.showPopover(); $('[data-filter-search]',panel)?.dispatchEvent(new Event('input')); }
        }
        if (panel.getAttribute('role') !== 'menu') return;
        const items = () => $$('[role="menuitem"], [role="menuitemradio"]', panel);
        trigger.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault(); panel.showPopover(); place();
            const choices = items(); choices[event.key === 'ArrowDown' ? 0 : choices.length - 1]?.focus();
        });
        panel.addEventListener('keydown', event => {
            const choices = items(), index = choices.indexOf(document.activeElement);
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
            choices[next]?.focus();
        });
        panel.addEventListener('click', event => {
            const item = event.target.closest('[role="menuitem"]');
            if (item && item.getAttribute('aria-disabled') !== 'true') panel.hidePopover();
        }, true);
    });
    $$('[data-segment-rename]').forEach(trigger => trigger.addEventListener('click', () => {
        const model = JSON.parse(trigger.dataset.segmentRename), form = $('#rename-segment form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, model[key])); show('rename-segment');
    }));

    // Column edits are staged until Apply; closing the panel discards the draft.
    const columnList = $('[data-column-list]');
    if (columnList) {
        const panel = $('#columns'), key = `audience-columns:${document.body.dataset.audienceKey || 'default'}`;
        const choices = $$('[data-column-choice]', columnList), defaults = choices.map(row => row.dataset.columnChoice);
        const defaultVisible = choices.filter(row => $('[data-column-toggle]', row).checked).map(row => row.dataset.columnChoice);
        let stored; try { stored = JSON.parse(localStorage.getItem(key)); } catch (_) { /* Optional preference. */ }
        // Upgrade the former default while preserving explicitly customized layouts.
        const formerDefaults = [
            ['email', 'first_name', 'last_name', 'tags', 'source', 'created_at', 'updated_at'],
            ['email', 'first_name', 'last_name', 'tags', 'email_status', 'source', 'created_at'],
            ['email', 'first_name', 'last_name', 'tags', 'email_status', 'created_at']
        ];
        if (Array.isArray(stored?.visible) && formerDefaults.some(columns => JSON.stringify([...stored.visible].sort()) === JSON.stringify([...columns].sort()))) stored = {...stored, visible:defaultVisible};
        let committed = {
            order: ['email', ...new Set([...(Array.isArray(stored?.order) ? stored.order : defaults), ...defaults].filter(k => k !== 'email' && defaults.includes(k)))],
            visible: ['email', ...(Array.isArray(stored?.visible) ? stored.visible : defaultVisible).filter(k => k !== 'email' && defaults.includes(k))]
        };
        const restoreDraft = () => committed.order.forEach(key => {
            const row = choices.find(row => row.dataset.columnChoice === key); columnList.append(row);
            $('[data-column-toggle]', row).checked = committed.visible.includes(key);
        });
        const renderColumns = () => {
            for (const row of $$('[data-people-table] tr')) {
                const cells = $$('[data-column]', row);
                for (const key of committed.order) {
                    const cell = cells.find(c => c.dataset.column === key);
                    if (cell) { cell.hidden = !committed.visible.includes(key); row.append(cell); }
                }
            }
        };
        refreshPeopleColumns = renderColumns;
        let stopColumnDrag;
        panel.addEventListener('beforetoggle', event => {
            stopColumnDrag?.();
            if (event.newState === 'open') restoreDraft();
        });
        const announce = row => { $('[data-column-announcement]').textContent = `${$('[data-column-toggle]', row).parentElement.textContent.trim()}, position ${Array.from(columnList.children).indexOf(row) + 1}`; };
        choices.forEach(row => {
            const handle = $('[data-column-drag]', row); if (!handle) return;
            handle.addEventListener('keydown', event => {
                if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
                event.preventDefault();
                if (event.key === 'ArrowUp' && row.previousElementSibling?.dataset.columnChoice !== 'email') row.previousElementSibling?.before(row);
                if (event.key === 'ArrowDown') row.nextElementSibling?.after(row);
                handle.focus(); announce(row);
            });
            handle.addEventListener('pointerdown', event => {
                if (event.button !== 0 || event.isPrimary === false) return;
                event.preventDefault(); handle.focus(); row.classList.add('dragging');
                stopColumnDrag?.();
                const pointerId = event.pointerId;
                let pointerY = event.clientY, frame;
                // Moving a row reparents its handle, which can release pointer capture.
                // Listen on the document so dragging continues after every reorder.
                const reorder = () => {
                    const others = $$('[data-column-choice]', columnList).filter(other => other !== row && other.dataset.columnChoice !== 'email');
                    const next = others.find(other => { const rect = other.getBoundingClientRect(); return pointerY < rect.top + rect.height / 2; });
                    if (next && row.nextElementSibling !== next) next.before(row);
                    else if (!next && row.nextElementSibling) columnList.append(row);
                };
                const scroll = () => {
                    const bounds = columnList.getBoundingClientRect(), before = columnList.scrollTop;
                    if (pointerY < bounds.top + 35) columnList.scrollTop -= 8;
                    else if (pointerY > bounds.bottom - 35) columnList.scrollTop += 8;
                    if (before !== columnList.scrollTop) reorder();
                    frame = requestAnimationFrame(scroll);
                };
                const move = event => {
                    if (event.pointerId !== pointerId) return;
                    event.preventDefault(); pointerY = event.clientY; reorder();
                };
                const finish = event => {
                    if (event?.pointerId != null && event.pointerId !== pointerId) return;
                    cancelAnimationFrame(frame); stopColumnDrag = null;
                    row.classList.remove('dragging'); document.removeEventListener('pointermove', move, true);
                    document.removeEventListener('pointerup', finish, true); document.removeEventListener('pointercancel', finish, true);
                    window.removeEventListener('blur', finish); announce(row); handle.focus({preventScroll:true});
                };
                document.addEventListener('pointermove', move, {capture:true, passive:false});
                document.addEventListener('pointerup', finish, true); document.addEventListener('pointercancel', finish, true);
                window.addEventListener('blur', finish);
                stopColumnDrag = finish; frame = requestAnimationFrame(scroll);
            });
        });
        $('[data-apply-columns]').addEventListener('click', () => {
            const order = $$('[data-column-choice]', columnList);
            committed = {order: order.map(row => row.dataset.columnChoice), visible: order.filter(row => $('[data-column-toggle]', row).checked).map(row => row.dataset.columnChoice)};
            renderColumns(); try { localStorage.setItem(key, JSON.stringify(committed)); } catch (_) { /* Optional preference. */ }
            panel.hidePopover(); $('[popovertarget="columns"]').focus();
        });
        restoreDraft(); renderColumns();
    }

    let selectedBoxes = $$('[data-person-select]');
    let allMatching = false;
    const updateSelection = () => {
        const ids = selectedBoxes.filter(input => input.checked).map(input => input.value);
        const active = allMatching || ids.length > 0;
        const label = allMatching ? 'All matching connections selected' : `${ids.length} connections selected`;
        const toolbar = $('[data-bulk-toolbar]'); if (toolbar) toolbar.hidden = !active;
        if ($('[data-selection-count]')) $('[data-selection-count]').textContent = label;
        if ($('[data-bulk-summary]')) $('[data-bulk-summary]').textContent = label;
        if ($('[data-select-matches]')) $('[data-select-matches]').hidden = allMatching;
        const pageBox = $('[data-select-page]');
        if (pageBox) { pageBox.checked = selectedBoxes.length > 0 && ids.length === selectedBoxes.length; pageBox.indeterminate = ids.length > 0 && ids.length < selectedBoxes.length; }
        for (const [selector, target] of [['#bulk-people-form', '[data-bulk-selection]'], ['#people-export', '[data-export-selection]']]) {
            const form = $(selector); if (!form) continue;
            setValue(form, 'selection_scope', allMatching || (!active && selector === '#people-export') ? 'all' : 'selected');
            $(target, form).replaceChildren(...(allMatching ? [] : ids.map(id => hidden('selected[]', id))));
        }
        if ($('[data-export-label]')) $('[data-export-label]').textContent = active ? '↓ Export selection' : ($('[data-export-label]').dataset.exportBaseLabel || '↓ Export connections');
    };
    peopleResults?.addEventListener('change',event => {
        if (event.target.matches('[data-auto-submit]')) { event.target.form.requestSubmit(); return; }
        if (!event.target.matches('[data-person-select], [data-select-page]')) return;
        allMatching = false; if (event.target.matches('[data-select-page]')) selectedBoxes.forEach(input => { input.checked = event.target.checked; }); updateSelection();
    });
    peopleResults?.addEventListener('click',event => {
        const action = event.target.closest('[data-select-matches], [data-clear-selection], [data-open-dialog]'); if (!action) return;
        if (action.dataset.openDialog) { show(action.dataset.openDialog); return; }
        allMatching = action.hasAttribute('data-select-matches'); selectedBoxes.forEach(input => { input.checked = allMatching; }); updateSelection();
    });
    const descriptions = {
        archive: 'Archive connections to exclude them from campaigns while retaining their profiles and permission history.',
        restore: 'Unarchive connections. Their existing email subscription status is preserved.',
        unsubscribe: 'Stop audience email for these connections. Cleaned addresses remain suppressed.',
        delete: 'Permanently remove personal data and prevent these addresses from being imported again. This cannot be undone.',
        tag: 'Add this tag without changing other tags.', untag: 'Remove this tag. Connections and their other tags will be kept.',
        group: 'Add this group choice. For single-choice categories, it replaces the previous choice.', ungroup: 'Remove this group choice. Connections will be kept.'
    };
    const bulkOperation = $('#bulk-operation');
    const updateBulk = () => {
        if (!bulkOperation) return;
        const action = bulkOperation.value;
        $('[data-bulk-tag]').hidden = !['tag', 'untag'].includes(action);
        $('#bulk-selection-tag').required = ['tag', 'untag'].includes(action);
        $('[data-bulk-group]').hidden = !['group', 'ungroup'].includes(action);
        $('#bulk-selection-group').required = ['group', 'ungroup'].includes(action);
        $('[data-bulk-delete]').hidden = action !== 'delete';
        $('#bulk-delete-word').required = action === 'delete';
        $('[data-bulk-description]').textContent = descriptions[action];
    };
    bulkOperation?.addEventListener('change', updateBulk); updateBulk();
    $$('[data-person-operation]').forEach(b => b.addEventListener('click', () => {
        const action = b.dataset.personOperation, form = $('#person-action form');
        setValue(form, 'operation', action); setValue(form, 'confirm_text', '');
        $('[data-person-action-title]').textContent = `${({restore: 'Unarchive', delete: 'Permanently delete'})[action] || action[0].toUpperCase() + action.slice(1)} connection`;
        $('[data-person-action-description]').textContent = descriptions[action];
        $('[data-person-delete-confirm]').hidden = action !== 'delete';
        $('#person-delete-word').required = action === 'delete';
        show('person-action');
    }));
    $$('[data-tag-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.tagEdit), form = $('#tag-editor form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, data[key]));
        $('[data-tag-heading]').textContent = data.id ? 'Rename tag' : 'Create tag'; show('tag-editor');
    }));
    $$('[data-bulk-tag-id]').forEach(b => b.addEventListener('click', () => { $('#bulk-tag-id').value = b.dataset.bulkTagId; show('bulk-tag'); }));
    const groupOptions = $('[data-group-options]');
    const renumberOptions = () => {
        $$('[data-group-option]', groupOptions).forEach((row, index) => {
            $('input[type=hidden]', row).name = `options[${index}][id]`;
            $('input[type=text]', row).name = `options[${index}][name]`;
        });
    };
    const addGroupOption = (option = {}) => {
        const row = el('div', {'data-group-option': '', class: 'group-option-row'});
        row.append(hidden('', option.id || 0), el('input', {type: 'text', value: option.name || '', maxlength: 100, required: true, 'aria-label': 'Group choice name'}));
        row.append(button('↑', event => { if (row.previousElementSibling) row.previousElementSibling.before(row); renumberOptions(); event.currentTarget.focus(); }, 'Move group choice up'));
        row.append(button('↓', event => { if (row.nextElementSibling) row.nextElementSibling.after(row); renumberOptions(); event.currentTarget.focus(); }, 'Move group choice down'));
        row.append(button('×', () => { const next = row.nextElementSibling || row.previousElementSibling; row.remove(); renumberOptions(); (next?.querySelector('input[type=text]') || $('[data-add-group-option]')).focus(); }, 'Remove group choice'));
        groupOptions.append(row); renumberOptions();
    };
    $('[data-add-group-option]')?.addEventListener('click', () => { addGroupOption(); $('[data-group-option]:last-child input[type=text]', groupOptions)?.focus(); });
    $$('[data-group-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.groupEdit), form = $('#group-editor form');
        ['id', 'version', 'name', 'display_type'].forEach(key => setValue(form, key, data[key]));
        groupOptions.replaceChildren(); (data.options?.length ? data.options : [{}]).forEach(addGroupOption);
        $('[data-group-heading]').textContent = data.id ? 'Edit group' : 'Create group'; show('group-editor');
    }));
    const updateFieldChoices = () => { if ($('[data-field-choices]')) $('[data-field-choices]').hidden = !['dropdown', 'radio', 'checkboxes'].includes($('#field-type').value); };
    $('#field-type')?.addEventListener('change', updateFieldChoices);
    let mergeTagEdited = false;
    $('#field-merge')?.addEventListener('input', () => { mergeTagEdited = true; });
    $('#field-label')?.addEventListener('input', event => {
        if (!mergeTagEdited && !Number($('#field-editor form').elements.namedItem('id').value)) $('#field-merge').value = event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '').replace(/^[^A-Z]+/, '').slice(0, 30);
    });
    const lockFieldType = id => {
        const field = $('#field-type'), form = field.form;
        $('[data-locked-field-type]', form)?.remove(); field.disabled = Number(id) > 0;
        if (field.disabled) { const value = hidden('field_type', field.value); value.dataset.lockedFieldType = ''; form.append(value); }
    };
    $$('[data-field-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.fieldEdit), form = $('#field-editor form'); lockFieldType(0); form.reset();
        ['id', 'version', 'label', 'merge_tag', 'field_type', 'position', 'default_value'].forEach(key => setValue(form, key, data[key] ?? (key === 'position' ? 0 : '')));
        setValue(form, 'options_text', (typeof data.options_json === 'string' ? JSON.parse(data.options_json) : data.options_json || []).join('\n'));
        ['required', 'is_public'].forEach(key => { form.elements.namedItem(key).checked = Number(data[key]) === 1; });
        lockFieldType(data.id); updateFieldChoices(); mergeTagEdited = Number(data.id) > 0;
        $('[data-field-heading]').textContent = Number(data.id) ? 'Edit field' : 'Create new field';
        show('field-editor');
    }));
    const importMethods = $$('input[name="import_method"]');
    const showImportMethod = () => {
        const selected = importMethods.find(input => input.checked)?.value;
        $$('[data-import-method]').forEach(panel => {
            panel.hidden = panel.dataset.importMethod !== selected;
            $$('input, textarea', panel).forEach(control => { control.disabled = panel.hidden; control.required = !panel.hidden; });
        });
    };
    importMethods.forEach(input => input.addEventListener('change', showImportMethod));
    if (importMethods.length) showImportMethod();
    $$('[data-import-mapping]').forEach(select => select.addEventListener('change', () => { $('[data-new-field-name]', select.parentElement).hidden = select.value !== 'new_text'; }));

    const ruleRoot = $('[data-rule-root]');
    let tree = {match: 'all', rules: []};
    const segmentTree = () => {
        const scope = $('[data-segment-status]')?.value || '';
        if (!scope) return tree;
        const conditions = tree.rules.length ? (tree.match === 'all' ? tree.rules : [tree]) : [];
        return {match:'all',rules:[{field:'email_status',operator:'is',value:scope},...conditions]};
    };
    const defaultRule = () => ({field: 'email_status', operator: 'is', value: 'subscribed'});
    const clearPreview = () => { if ($('[data-segment-results]')) $('[data-segment-results]').hidden = true; if ($('[data-segment-error]')) $('[data-segment-error]').hidden = true; };
    const ruleDepth = group => 1 + Math.max(0, ...group.rules.filter(rule => Array.isArray(rule.rules)).map(ruleDepth));
    let pickerNumber = 0, activeFieldPicker = null;
    window.addEventListener('resize', () => activeFieldPicker?.close());
    document.addEventListener('scroll', event => {
        if (activeFieldPicker && !activeFieldPicker.list.contains(event.target)) activeFieldPicker.close();
    }, true);
    // Search stays in the combobox while arrows move its active option.
    const fieldPicker = (value, select, focusKey) => {
        const wrap = el('div', {class: 'rule-field-picker'}), listId = `rule-fields-${++pickerNumber}`;
        const input = el('input', {type: 'text', role: 'combobox', 'aria-label': 'Filter field', 'aria-autocomplete': 'list', 'aria-controls': listId, 'aria-expanded': 'false', autocomplete: 'off', 'data-rule-focus': focusKey, placeholder: 'Select or search a filter', value: config.catalog[value]?.label || ''});
        const list = el('div', {id: listId, role: 'listbox', class: 'rule-field-options', 'aria-label': 'Filter fields', hidden: true});
        let active = -1, options = [];
        const close = () => { list.hidden = true; if (activeFieldPicker?.list === list) activeFieldPicker = null; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); input.value = config.catalog[value]?.label || ''; };
        const position = () => {
            const rect = input.getBoundingClientRect(), dialog = input.closest('dialog')?.getBoundingClientRect();
            const bottom = Math.min(window.innerHeight, dialog?.bottom ?? window.innerHeight) - 12;
            const top = Math.max(0, dialog?.top ?? 0) + 12;
            const below = bottom - rect.bottom - 8, above = rect.top - top - 8;
            const up = below < 240 && above > below, height = Math.max(0, Math.min(320, up ? above : below));
            list.style.position = 'fixed'; list.style.width = Math.min(Math.max(rect.width, 300), window.innerWidth - 32) + 'px';
            list.style.left = Math.max(16, Math.min(rect.left, window.innerWidth - list.offsetWidth - 16)) + 'px';
            list.style.maxHeight = height + 'px';
            list.style.top = (up ? rect.top - list.offsetHeight - 8 : rect.bottom + 8) + 'px';
        };
        const highlight = index => {
            active = index; options.forEach((option, i) => option.setAttribute('aria-selected', String(i === active)));
            if (options[active]) { input.setAttribute('aria-activedescendant', options[active].id); options[active].scrollIntoView({block: 'nearest'}); }
        };
        const search = term => {
            list.replaceChildren(); options = []; active = -1; input.removeAttribute('aria-activedescendant');
            const sections = {};
            for (const [key, entry] of Object.entries(config.catalog || {})) {
                if (!`${entry.label} ${entry.section}`.toLowerCase().includes(term.toLowerCase())) continue;
                if (!sections[entry.section]) {
                    const group = el('div', {role: 'group', 'aria-label': entry.section});
                    group.append(el('div', {class: 'rule-field-section', 'aria-hidden': 'true'}, entry.section)); list.append(group); sections[entry.section] = group;
                }
                const option = el('div', {id: `${listId}-${options.length}`, role: 'option', 'aria-selected': 'false'}, entry.label);
                option.addEventListener('mousedown', event => event.preventDefault());
                option.addEventListener('click', () => select(key)); option.dataset.field = key;
                options.push(option); sections[entry.section].append(option);
            }
            if (!options.length) list.append(el('p', {role: 'status'}, 'No matching filters'));
            if (activeFieldPicker?.list !== list) activeFieldPicker?.close();
            list.hidden = false; input.setAttribute('aria-expanded', 'true');
            activeFieldPicker = {list, close}; position();
        };
        input.addEventListener('focus', () => { input.select(); search(''); });
        input.addEventListener('click', () => { if (list.hidden) search(''); });
        input.addEventListener('input', () => search(input.value));
        input.addEventListener('blur', close);
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !list.hidden) { event.preventDefault(); event.stopPropagation(); close(); }
            else if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault(); if (list.hidden) search('');
                if (options.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
            } else if (event.key === 'Enter' && !list.hidden) { event.preventDefault(); if (options[active]) select(options[active].dataset.field); }
        });
        wrap.append(input, list); return wrap;
    };
    const renderRules = (focusKey = null) => {
        if (!ruleRoot) return;
        clearPreview(); ruleRoot.replaceChildren();
        const renderGroup = (group, depth, parent, index, path = 'root') => {
            const box = el('section', {class: 'segment-rule-group', 'aria-label': depth ? 'Nested condition group' : 'Segment conditions'});
            const header = el('div', {class: 'rule-group-heading'});
            const match = el('select', {'aria-label': 'Match conditions', 'data-rule-focus': `${path}-match`});
            match.append(el('option', {value: 'all'}, 'all'), el('option', {value: 'any'}, 'any')); match.value = group.match;
            match.addEventListener('change', () => { group.match = match.value; renderRules(`${path}-match`); });
            header.append(el('span', {}, 'Match'), match, el('span', {}, 'of the following conditions'));
            if (parent) header.append(button('Remove group', () => { parent.rules.splice(index, 1); renderRules(`${path.substring(0, path.lastIndexOf('-'))}-match`); }));
            box.append(header);
            group.rules.forEach((rule, i) => {
                if (i) box.append(el('span', {class: 'rule-join'}, group.match === 'all' ? 'AND' : 'OR'));
                const rulePath = `${path}-${i}`;
                if (Array.isArray(rule.rules)) { box.append(renderGroup(rule, depth + 1, group, i, rulePath)); return; }
                const row = el('div', {class: 'segment-condition'});
                const field = fieldPicker(rule.field, key => {
                    rule.field = key; delete rule.campaign_id; delete rule.link_hash; delete rule.bots; const definition = config.catalog[key]; rule.operator = Object.keys(config.operators[definition.kind])[0];
                    rule.value = definition.kind === 'membership' ? [] : definition.options ? Object.keys(definition.options)[0] || '' : '';
                    renderRules(`${rulePath}-operator`);
                }, `${rulePath}-field`);
                const definition = config.catalog[rule.field] || {kind: 'text'};
                const operator = el('select', {'aria-label': 'Condition operator', 'data-rule-focus': `${rulePath}-operator`});
                for (const [key, label] of Object.entries(config.operators[definition.kind] || {})) operator.append(el('option', {value: key}, label));
                operator.value = rule.operator;
                operator.addEventListener('change', () => { rule.operator = operator.value; if (definition.kind === 'membership' && !Array.isArray(rule.value)) rule.value = []; else if (definition.kind !== 'membership' && Array.isArray(rule.value)) rule.value = ''; renderRules(`${rulePath}-operator`); });
                row.append(field, operator);
                if (!['empty', 'not_empty', 'ever', 'never'].includes(rule.operator)) {
                    if (definition.kind === 'membership') {
                        const picker = el('details', {class: 'rule-membership'}), checked = Array.isArray(rule.value) ? rule.value.map(String) : [];
                        const summary = el('summary', {}, checked.length ? `${checked.length} selected` : 'Choose values'); const list = el('div', {class: 'rule-membership-options'});
                        for (const [key, label] of Object.entries(definition.options || {})) {
                            const choice = el('input', {type: 'checkbox', value: key, checked: checked.includes(key)});
                            choice.addEventListener('change', () => { rule.value = $$('input:checked', list).map(input => Number(input.value)); summary.textContent = rule.value.length ? `${rule.value.length} selected` : 'Choose values'; clearPreview(); });
                            const labelNode = el('label', {class: 'audience-check'}); labelNode.append(choice, document.createTextNode(label)); list.append(labelNode);
                        }
                        if (!list.children.length) list.append(el('p', {}, 'No values available. Create a tag or group first.'));
                        picker.append(summary, list); row.append(picker);
                    } else if (definition.kind === 'choice') {
                        const value = el('select', {'aria-label': 'Condition value'});
                        for (const [key, label] of Object.entries(definition.options || {})) value.append(el('option', {value: key}, label));
                        value.value = String(rule.value ?? ''); value.addEventListener('change', () => { rule.value = value.value; clearPreview(); }); row.append(value);
                    } else {
                        const type = ['last_days', 'not_last_days'].includes(rule.operator) || definition.kind === 'number' ? 'number' : definition.kind === 'date' || (['event', 'engagement'].includes(definition.kind) && ['before', 'after'].includes(rule.operator)) ? 'date' : 'text';
                        const value = el('input', {type, value: rule.value ?? '', 'aria-label': 'Condition value', required: true, ...(type === 'number' ? {step: ['last_days', 'not_last_days'].includes(rule.operator) ? 1 : 'any'} : {}), ...(['last_days', 'not_last_days'].includes(rule.operator) ? {min: 1, max: 36500} : {})});
                        value.addEventListener('input', () => { rule.value = value.value; clearPreview(); }); row.append(value);
                    }
                }
                if (definition.kind === 'engagement') {
                    for (const [property, label, options] of [
                        ['campaign_id', 'Campaign', {'0': 'Any campaign', ...definition.campaigns}],
                        ['bots', 'Engagement data', {filtered: 'Exclude known bots', all: 'Include detected bots'}],
                        ...(rule.field === 'engagement:click' ? [['link_hash', 'Tracked link', {'': 'Any link', ...definition.links}]] : [])
                    ]) {
                        const picker = el('select', {'aria-label': label});
                        for (const [key, text] of Object.entries(options)) picker.append(el('option', {value: key}, text));
                        picker.value = String(rule[property] ?? Object.keys(options)[0]);
                        picker.addEventListener('change', () => { rule[property] = picker.value; clearPreview(); });
                        row.append(picker);
                    }
                }
                const actions = el('div', {class: 'rule-condition-actions'});
                actions.append(button('⧉', () => { group.rules.splice(i + 1, 0, clone(rule)); renderRules(`${path}-${i + 1}-field`); }, 'Duplicate filter'));
                actions.append(button('×', () => { group.rules.splice(i, 1); renderRules(`${path}-match`); }, 'Delete filter'));
                row.append(actions);
                box.append(row);
            });
            const actions = el('div', {class: 'rule-add-actions'});
            for (const join of ['all', 'any']) actions.append(button(join === 'all' ? '＋ Add "and"' : '＋ Add "or"', () => {
                if (group.match === join || group.rules.length < 2) { group.match = join; group.rules.push(defaultRule()); renderRules(`${path}-${group.rules.length - 1}-field`); }
                else if (depth + ruleDepth(group) < 5) { const previous = clone(group); group.match = join; group.rules = [previous, defaultRule()]; renderRules(`${path}-1-field`); }
                else { const notice = $('[data-segment-error]'); notice.textContent = 'This group has reached the nesting limit. Change its match logic or add a condition within an existing group.'; notice.hidden = false; }
            }));
            if (depth < 4) actions.append(button('＋ Add group', () => { group.rules.push({match: 'any', rules: [defaultRule()]}); renderRules(`${path}-${group.rules.length - 1}-0-field`); }));
            box.append(actions); return box;
        };
        ruleRoot.append(renderGroup(tree, 0));
        if (focusKey) $$('[data-rule-focus]', ruleRoot).find(node => node.dataset.ruleFocus === focusKey)?.focus();
    };
    const openSegment = (model = {}) => {
        const form = $('#segment-form'); if (!form) return;
        ['id', 'version', 'name'].forEach(key => setValue(form, key, model[key] || (key === 'name' ? '' : 0)));
        if ($('[data-segment-status]')) $('[data-segment-status]').value = '';
        tree = clone(model.rules || {match: 'all', rules: [defaultRule()]});
        renderRules(); show('segment-builder');
    };
    $$('[data-segment-new]').forEach(b => b.addEventListener('click', () => openSegment()));
    const openCurrentSegment = async () => { if (await currentFiltersReady()) openSegment({...config.segment,rules:config.currentRules || config.rules}); };
    $$('[data-segment-current]').filter(b => !b.closest('[data-audience-filter-summary]')).forEach(b => b.addEventListener('click',openCurrentSegment));
    filterSummary?.addEventListener('click',async event => {
        const action = event.target.closest('[data-segment-current], [data-open-dialog]'); if (!action) return;
        if (action.hasAttribute('data-segment-current')) { openCurrentSegment(); return; }
        if (await currentFiltersReady()) show(action.dataset.openDialog);
    });
    $$('[data-segment-edit], [data-segment-copy]').forEach(b => b.addEventListener('click', () => {
        const model = JSON.parse(b.dataset.segmentEdit || b.dataset.segmentCopy);
        if (b.hasAttribute('data-segment-copy')) { model.id = 0; model.version = 0; model.name = `${model.name} (copy)`.slice(0, 100); }
        openSegment(model);
    }));
    $('[data-segment-status]')?.addEventListener('change',clearPreview);
    $('#segment-form')?.addEventListener('submit', () => setValue($('#segment-form'), 'rules', JSON.stringify(segmentTree())));
    const audienceUrl = params => { const url = new URL(config.url, location.href); for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value)); return url.href; };
    const request = async (url, options) => {
        const response = await fetch(url, {credentials: 'same-origin', ...options});
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error(response.redirected || response.status === 401 ? 'Your session has expired. Sign in and reload this page.' : `Unable to read the operation status (HTTP ${response.status}). Reload the page to retry.`);
        const data = await response.json(); if (!response.ok || data.error) throw new Error(data.error || 'The operation failed. Try again.'); return data;
    };
    $('[data-segment-preview]')?.addEventListener('click', async event => {
        const trigger = event.currentTarget; trigger.disabled = true; clearPreview();
        try {
            const data = new FormData($('#segment-form')); data.set('action', 'preview_segment'); data.set('rules', JSON.stringify(segmentTree()));
            const result = await request(config.url, {method: 'POST', body: data}), panel = $('[data-segment-results]');
            panel.replaceChildren(el('h3', {}, `${Number(result.count).toLocaleString()} matching connections`));
            if (result.people.length) {
                const list = el('ul'); for (const person of result.people) { const item = el('li'); item.append(el('a', {href: audienceUrl({view: 'contact', id: Number(person.id)})}, person.email)); list.append(item); } panel.append(list);
            } else panel.append(el('p', {}, 'No connections currently match these conditions.'));
            panel.hidden = false;
        } catch (error) { $('[data-segment-error]').textContent = error.message; $('[data-segment-error]').hidden = false; }
        finally { trigger.disabled = false; }
    });
    $('[data-segment-apply]')?.addEventListener('click', () => {
        const url = new URL(config.url, location.href); for (const [key,value] of Object.entries(config.listContext || {})) url.searchParams.set(key,String(value)); url.searchParams.set('rules', JSON.stringify(segmentTree())); location.assign(url);
    });

    if (config.job && ['queued', 'processing'].includes(config.job.state) && $('[data-job-panel]')) {
        let stopped = false, startedAt = 0;
        const showError = message => { $('[data-job-error]').textContent = message; $('[data-job-error]').hidden = false; };
        const start = async () => {
            startedAt = Date.now();
            const data = new FormData(); for (const [key, value] of Object.entries({action: 'process_job', id: config.job.id, audience: config.audience, csrf_token: config.csrf})) data.set(key, value);
            try { await request(config.url, {method: 'POST', body: data}); } catch (error) { if (!stopped) showError(error.message); }
        };
        const poll = async () => {
            if (stopped) return;
            try {
                const status = await request(audienceUrl({api: 'job', id: Number(config.job.id)}));
                $('[data-job-progress]').value = Number(status.processed);
                $('[data-job-progress-label]').textContent = `${Number(status.processed).toLocaleString()} of ${Number(status.total).toLocaleString()} processed`;
                $$('[data-job-stat]').forEach(node => { node.textContent = Number(status[node.dataset.jobStat]).toLocaleString(); });
                if (!['queued', 'processing'].includes(status.state)) { stopped = true; location.reload(); return; }
                if (Date.now() - startedAt > 245000) start();
                window.setTimeout(poll, 1500);
            } catch (error) { showError(`${error.message} You can reload or resume this operation from history.`); }
        };
        start(); window.setTimeout(poll, 1000);
        window.addEventListener('pagehide', () => { stopped = true; });
    }
    // Reopen failed editors with submitted values and the original version token.
    // A stale version must never be silently replaced by the latest record version.
    if (config.recovery) {
        const {dialog: id, values, error} = config.recovery, dialog = document.getElementById(id), form = dialog?.querySelector('form');
        if (form) {
            if (id === 'segment-builder') openSegment({...values, rules: config.rules});
            if (id === 'group-editor') { groupOptions.replaceChildren(); (values.options || []).forEach(addGroupOption); }
            for (const control of form.elements) {
                if (!control.name || !(control.name in values) || control.name === 'csrf_token') continue;
                if (['checkbox', 'radio'].includes(control.type)) control.checked = String(values[control.name]) === control.value;
                else if (typeof values[control.name] !== 'object') control.value = values[control.name];
            }
            if (id === 'tag-editor') $('[data-tag-heading]').textContent = Number(values.id) ? 'Rename tag' : 'Create tag';
            if (id === 'field-editor') { lockFieldType(values.id); updateFieldChoices(); mergeTagEdited = true; }
            const notice = el('p', {class: 'audience-notice error', role: 'alert', tabindex: '-1'}, error);
            form.prepend(notice); show(id); notice.focus();
            dialog.addEventListener('close', () => notice.remove(), {once: true});
        }
    } else $('[data-operation-error]')?.focus();
    const exportHistory = $('#export-history-delete');
    if (exportHistory) {
        const items = $$('[data-export-select]:not(:disabled)', exportHistory), all = $('[data-export-select-page]', exportHistory);
        const update = () => { const count = items.filter(item => item.checked).length; $('[data-export-delete-selected]', exportHistory).disabled = !count; all.checked = items.length > 0 && count === items.length; all.indeterminate = count > 0 && count < items.length; };
        all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); update(); });
        items.forEach(item => item.addEventListener('change', update));
        $$('[data-export-delete]', exportHistory).forEach(button => button.addEventListener('click', () => { items.forEach(item => { item.checked = item.value === button.dataset.exportDelete; }); update(); exportHistory.requestSubmit($('[data-export-delete-selected]', exportHistory)); }));
        update();
    }
    // Campaign actions reuse native dialogs, confirmations and top-layer menus.
    const campaignColumns = $('[data-campaign-columns]');
    if (campaignColumns) {
        const choices = $$('[data-campaign-column-toggle]:not(:disabled)', campaignColumns);
        const keys = choices.map(input => input.dataset.campaignColumnToggle);
        const storageKey = `campaign-columns:${document.body.dataset.audienceKey || 'default'}`;
        let stored; try { stored = JSON.parse(localStorage.getItem(storageKey)); } catch (_) { /* Optional preference. */ }
        const hiddenColumns = new Set(Array.isArray(stored) ? stored.filter(key => keys.includes(key)) : []);
        const render = () => {
            choices.forEach(input => { input.checked = !hiddenColumns.has(input.dataset.campaignColumnToggle); });
            $$('[data-campaign-column]').forEach(cell => { cell.hidden = hiddenColumns.has(cell.dataset.campaignColumn); });
        };
        const save = () => { try { localStorage.setItem(storageKey, JSON.stringify([...hiddenColumns])); } catch (_) { /* Works without storage. */ } };
        choices.forEach(input => input.addEventListener('change', () => {
            if (input.checked) hiddenColumns.delete(input.dataset.campaignColumnToggle);
            else hiddenColumns.add(input.dataset.campaignColumnToggle);
            render(); save();
        }));
        $('[data-campaign-columns-reset]', campaignColumns).addEventListener('click', () => { hiddenColumns.clear(); render(); save(); });
        render();
    }
    const campaignFilters = $('#campaign-filters');
    $$('[data-campaign-filter]').forEach(item => item.addEventListener('click', () => {
        setValue(campaignFilters, item.dataset.campaignFilter, item.dataset.value);
        campaignFilters?.requestSubmit();
    }));
    $('[data-campaign-direction]')?.addEventListener('click', () => {
        setValue(campaignFilters, 'direction', campaignFilters.elements.direction.value === 'asc' ? 'desc' : 'asc');
        campaignFilters.requestSubmit();
    });
    $('[data-campaign-folder-search]')?.addEventListener('input', event => {
        $$('[data-folder-name]').forEach(row => { row.hidden = !row.dataset.folderName.includes(event.target.value.trim().toLocaleLowerCase()); });
    });
    const hideCampaignPopovers = () => $$('[popover]:popover-open').forEach(panel => panel.hidePopover());
    $$('[data-campaign-folder]').forEach(button => button.addEventListener('click', () => {
        const folder = JSON.parse(button.dataset.campaignFolder), form = $('#campaign-folder form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, folder[key]));
        $('#campaign-folder-title').textContent = folder.id ? 'Edit folder' : 'Create folder';
        hideCampaignPopovers(); show('campaign-folder');
    }));
    $$('[data-campaign-folder-delete]').forEach(button => button.addEventListener('click', () => {
        const folder = JSON.parse(button.dataset.campaignFolderDelete), form = $('[data-campaign-folder-delete-form]');
        ['id', 'version'].forEach(key => setValue(form, key, folder[key]));
        hideCampaignPopovers(); form.requestSubmit();
    }));
    const campaignSelection = $('[data-campaign-selection]');
    const campaignItems = $$('[data-campaign-select-item]');
    const campaignAll = $('[data-campaign-select-page]');
    const updateCampaignSelection = () => {
        const chosen = campaignItems.filter(item => item.checked);
        const allResults = campaignSelection?.elements.selection_scope.value === 'all';
        if (campaignAll) { campaignAll.checked = chosen.length > 0 && chosen.length === campaignItems.length; campaignAll.indeterminate = chosen.length > 0 && chosen.length < campaignItems.length; }
        if ($('[data-campaign-bulk]')) $('[data-campaign-bulk]').hidden = !chosen.length;
        if ($('[data-campaign-selection-count]')) $('[data-campaign-selection-count]').textContent = allResults ? 'All matching campaigns selected' : `${chosen.length} selected`;
    };
    campaignItems.forEach(item => item.addEventListener('change', () => { setValue(campaignSelection, 'selection_scope', 'page'); updateCampaignSelection(); }));
    campaignAll?.addEventListener('change', () => { campaignItems.forEach(item => { item.checked = campaignAll.checked; }); setValue(campaignSelection, 'selection_scope', 'page'); updateCampaignSelection(); });
    $('[data-campaign-select-all]')?.addEventListener('click', () => { campaignItems.forEach(item => { item.checked = true; }); setValue(campaignSelection, 'selection_scope', 'all'); updateCampaignSelection(); });
    const populateCampaignSelection = (form, row = null) => {
        const holder = $('[data-campaign-selected-hidden]', form); holder.replaceChildren();
        if (row) { holder.append(hidden('selected[]', row.id), hidden(`versions[${row.id}]`, row.version)); return; }
        if (!campaignSelection) return;
        const data = new FormData(campaignSelection);
        for (const [name, value] of data) if (name.startsWith('selected[') || name.startsWith('versions[') || name.startsWith('selection_')) holder.append(hidden(name, value));
    };
    $$('[data-open-dialog="campaign-move"]').forEach(button => button.addEventListener('click', () => populateCampaignSelection($('#campaign-move form'))));
    const openCampaignLifecycle = async (operation, row = null) => {
        const dialog = $(`#campaign-${operation}`);
        if (!dialog) return;
        const form = $('form', dialog);
        if (!form || (operation === 'delete' && !(await window.DnrAdminUnlock?.request()))) return;
        populateCampaignSelection(form, row);
        $('[data-campaign-lifecycle-summary]', form).textContent = row ? row.name : $('[data-campaign-selection-count]')?.textContent || '';
        if (form.elements.confirm) form.elements.confirm.value = '';
        show(dialog.id);
    };
    $$('[data-campaign-lifecycle-selected]').forEach(button => button.addEventListener('click', () => openCampaignLifecycle(button.dataset.campaignLifecycleSelected)));
    $$('[data-campaign-delete-form],[data-flow-delete-form]').forEach(deleteForm => {
        let approved = false, checking = false;
        deleteForm.addEventListener('submit', async event => {
            if (approved) { approved = false; return; }
            event.preventDefault(); event.stopImmediatePropagation();
            if (checking) return;
            checking = true;
            try {
                if (await window.DnrAdminUnlock?.request()) {
                    approved = true;
                    deleteForm.requestSubmit(event.submitter || undefined);
                }
            } finally { checking = false; }
        });
    });
    $$('[data-campaign-row]').forEach(button => button.addEventListener('click', async () => {
        const row = JSON.parse(button.dataset.campaignRow), operation = button.dataset.campaignOp;
        hideCampaignPopovers();
        if (['archive','restore','delete'].includes(operation)) {
            await openCampaignLifecycle(operation, row); return;
        }
        if (operation === 'rename' || operation === 'move') {
            const dialog = operation === 'rename' ? 'campaign-rename' : 'campaign-move';
            const form = $(`#${dialog} form`); populateCampaignSelection(form, row);
            if (operation === 'rename') setValue(form, 'name', row.name);
            show(dialog); return;
        }
        const form = $('[data-campaign-operation-form]'); populateCampaignSelection(form, row); setValue(form, 'operation', operation);
        form.requestSubmit();
    }));
    $$('[data-campaign-cancel-section]').forEach(button => button.addEventListener('click', () => { button.form.reset(); button.closest('details').open = false; }));
    // Campaign checklist, recipient pickers and planning tools.
    $$('[data-recipient-picker]').forEach(picker => {
        const choices = $$('[data-recipient-choice]', picker), summary = $('[data-recipient-summary]', picker), original = picker.dataset.emptyLabel;
        $('[data-recipient-search]', picker).addEventListener('input', event => choices.forEach(choice => { choice.hidden = !choice.textContent.toLowerCase().includes(event.target.value.toLowerCase()); }));
        $$('input[type=checkbox]', picker).forEach(input => input.addEventListener('change', () => {
            if ($$('input[type=checkbox]:checked', picker).length > 10) { input.checked = false; input.setCustomValidity('Select up to 10 tags or segments.'); input.reportValidity(); }
            input.setCustomValidity('');
            const selected = $$('input[type=checkbox]:checked', picker);
            summary.firstChild.textContent = selected.length ? `${selected.length} selected` : original;
        }));
    });
    $$('[data-campaign-merge]').forEach(select => select.addEventListener('change', () => { const input = document.getElementById(select.dataset.campaignMerge); if (!select.value || !input) return; const start = input.selectionStart ?? input.value.length; input.setRangeText(select.value, start, input.selectionEnd ?? start, 'end'); input.dispatchEvent(new Event('input', {bubbles:true})); input.focus(); select.value = ''; }));
    $('[data-campaign-recipient-preview]')?.addEventListener('click', async event => {
        const form = event.target.form, output = $('[data-campaign-recipient-count]', form), body = new FormData(form); body.set('action', 'campaign_preview');
        event.target.disabled = true; try { const result = await request(form.action, {method:'POST', body}); output.textContent = `${Number(result.count).toLocaleString()} eligible email subscribers`; } catch (error) { output.textContent = error.message; } finally { event.target.disabled = false; }
    });
    $('[data-campaign-date-preset]')?.addEventListener('change', event => { const form = event.target.form; $$('input[type=date]', form).forEach(input => { input.readOnly = !['','custom'].includes(event.target.value); if (event.target.value === '') input.value = ''; }); });
    const sendForm = $('[name=send_mode]')?.form;
    if (sendForm) {
        const updateSendMode = () => {
            const mode = $('[name=send_mode]:checked', sendForm)?.value;
            $$('[data-send-time-fields]', sendForm).forEach(group => { group.hidden = group.dataset.sendTimeFields !== mode; $$('input,select', group).forEach(input => { input.disabled = group.hidden; }); });
        };
        $$('[name=send_mode]', sendForm).forEach(input => input.addEventListener('change', updateSendMode)); updateSendMode();
    }
    const reviewLink = $('[data-campaign-review-link]'); if (reviewLink) reviewLink.value = new URL(reviewLink.value, location.href).href;
    $('[data-copy-campaign-review]')?.addEventListener('click', async () => { try { await navigator.clipboard.writeText(reviewLink.value); $('[data-copy-review-status]').textContent = 'Link copied.'; } catch { reviewLink.select(); $('[data-copy-review-status]').textContent = 'Select and copy this link to share with your team.'; } });
    const experiment = $('[data-experiment-form]');
    if (experiment) {
        const update = () => {
            let combinations = 1, variables = 0; $$('[data-experiment-variable]', experiment).forEach(section => {
                const enabled = $('[data-experiment-enabled]', section).checked; const inputs = $$('[data-experiment-values] input', section); if (enabled) { variables++; combinations *= inputs.length; }
                inputs.forEach(input => { input.disabled = !enabled; });
                $$('[data-experiment-remove]', section).forEach(button => { button.disabled = !enabled || inputs.length <= 2; });
                $('[data-experiment-add]', section).disabled = !enabled || inputs.length >= 3;
            });
            const choice = $('[data-experiment-enabled]', experiment), percent = $('[name=test_percent]', experiment);
            choice.setCustomValidity(variables !== 1 || combinations > 3 ? 'Choose one variable with two or three variations.' : '');
            const timeTest = $('[data-experiment-variable=time] [data-experiment-enabled]', experiment).checked;
            percent.setCustomValidity(timeTest && Number(percent.value) !== 100 ? 'Send-time tests must include 100% of recipients.' : '');
            $('[data-experiment-summary]', experiment).textContent = `${variables} variable · ${variables ? combinations : 0} variations · ${percent.value}% of recipients`;
        };
        $$('[data-experiment-add]', experiment).forEach(button => button.addEventListener('click', () => {
            const section = button.closest('[data-experiment-variable]'), host = $('[data-experiment-values]', section), labels = $$('label', host); if (labels.length >= 3) return;
            const label = labels[0].cloneNode(true), input = $('input', label); label.firstChild.textContent = `Variation ${labels.length + 1}`; input.value = section.dataset.experimentVariable === 'content' ? `Variation ${labels.length + 1}` : ''; host.append(label); update();
        }));
        experiment.addEventListener('click', event => { const remove = event.target.closest('[data-experiment-remove]'); if (remove) { remove.closest('label').remove(); update(); } });
        experiment.addEventListener('change', update); experiment.addEventListener('input', update); update();
    }
    const socialDialog = $('#campaign-social');
    if (socialDialog) {
        const render = () => { $('[data-social-preview-title]').textContent = $('[data-social-title]').value; $('[data-social-preview-description]').textContent = $('[data-social-description]').value; };
        socialDialog.addEventListener('input', render);
        $('[data-social-image-upload]').addEventListener('change', event => {
            const file = event.target.files[0], status = $('[data-social-error]'); if (!file) return;
            if (!['image/png','image/jpeg','image/webp','image/gif'].includes(file.type) || file.size > 1000000) { status.textContent = 'Choose a PNG, JPEG, WebP or GIF image up to 1 MB.'; event.target.value = ''; return; }
            const reader = new FileReader(); reader.onload = () => { const image = new Image(); image.onload = () => { if (image.naturalWidth * image.naturalHeight > 16000000) { status.textContent = 'Images must be 16 megapixels or less.'; return; } $('[data-social-image]').value = reader.result; const preview = $('[data-social-preview-image]'); preview.src = reader.result; preview.hidden = false; status.textContent = image.naturalWidth < 600 ? 'For a larger card, choose an image at least 600 pixels wide.' : ''; }; image.src = reader.result; }; reader.readAsDataURL(file);
        });
    }
    const touchpointDialog = $('#campaign-touchpoint-editor'), touchpointForm = $('[data-touchpoint-form]');
    if (touchpointForm) {
        const fields = () => { const type = touchpointForm.elements.touchpoint_type.value; touchpointForm.elements.campaign_id.disabled = type !== 'email'; touchpointForm.elements.campaign_id.closest('label').hidden = type !== 'email'; touchpointForm.elements.event_type.closest('label').hidden = type !== 'event'; const tracking = $('[data-touchpoint-tracking]', touchpointForm); if (tracking) tracking.hidden = type !== 'email'; };
        const openTouchpoint = (model = {}, day = '') => {
            touchpointForm.reset(); const data = model.data_json ? JSON.parse(model.data_json) : {};
            for (const key of ['id','version','plan_id','campaign_id','touchpoint_type','name','state']) if (model[key] != null) setValue(touchpointForm, key, model[key]);
            for (const key of ['notes','event_type']) setValue(touchpointForm, key, data[key] || ''); if (touchpointForm.elements.use_plan_tracking) touchpointForm.elements.use_plan_tracking.checked = !!data.use_plan_tracking;
            for (const key of ['start_at','end_at']) setValue(touchpointForm, key, model[key] ? model[key].replace(' ','T').slice(0,16) : `${day || new Date().toISOString().slice(0,10)}T09:00`);
            $('#campaign-touchpoint-title').textContent = model.id ? 'Edit touchpoint' : 'Add touchpoint';
            const actions = $('[data-touchpoint-actions]'); actions.hidden = !model.id;
            if (model.id) ['id','version','plan_id'].forEach(key => setValue(actions, key, model[key] || 0));
            fields(); show('campaign-touchpoint-editor');
        };
        $$('[data-touchpoint-new]').forEach(button => button.addEventListener('click', () => openTouchpoint({}, button.dataset.touchpointNew)));
        $$('[data-touchpoint-edit]').forEach(button => button.addEventListener('click', () => openTouchpoint(JSON.parse(button.dataset.touchpointEdit))));
        touchpointForm.elements.touchpoint_type.addEventListener('change', fields); fields();
    }
    $$('[data-skip-plan-confirm]').forEach(button => button.addEventListener('click', () => confirmedForms.add(button.form)));

    // The content library and Brand Kit share image controls; uploads stay local until Save.
    $$('[data-file-drop]').forEach(wireAudienceFileDrop);
    $$('input[type=file]').forEach(input=>enhanceAudienceFileInput(input));
    $$('[data-brand-form] input[type=color]').forEach(input => enhanceColorInput(input,{tile:true}));
    const contentStatus = (form, message) => { const status = $('[data-content-status]', form); if (status) status.textContent = message; };
    const brandLogoDimensions = (form,key) => {
        const preview=$(`[data-content-preview="${key}"]`,form),label=$(`[data-brand-logo-dimensions="${key}"]`,form);
        if(label)label.textContent=form.elements[key].value&&preview?.naturalWidth?`${preview.naturalWidth} × ${preview.naturalHeight}${form.elements[key].value.startsWith('data:image/svg+xml;')?' · SVG (vector)':' pixels'}`:'';
    };
    $$('[data-brand-form] [data-content-preview]').forEach(preview=>{
        const update=()=>brandLogoDimensions(preview.closest('form'),preview.dataset.contentPreview);
        preview.addEventListener('load',update);update();
    });
    const setContentImage = (form, key, value) => {
        form.elements[key].value = value;
        const preview = $(`[data-content-preview="${key}"]`, form);
        if (preview) { preview.src = value; preview.hidden = !value; }
        if(!value)brandLogoDimensions(form,key);
        if (key !== 'image' || !value) return;
        const image = new Image(); image.onload = () => {
            if (form.elements[key].value !== value) return;
            const dimensions = $('[data-content-dimensions]', form);
            if (dimensions) dimensions.textContent = `${image.naturalWidth} × ${image.naturalHeight} pixels`;
            for (const [field, number] of Object.entries({x:0,y:0,width:image.naturalWidth,height:image.naturalHeight,output_width:image.naturalWidth,output_height:image.naturalHeight})) {
                const input = $(`[data-content-crop="${field}"]`, form); if (input) input.value = number;
            }
        }; image.src = value;
    };
    const contentUploads = new WeakMap();
    const resetContentUploads = form => {
        const state=contentUploads.get(form);state.pending.clear();
        for(const [button,disabled]of state.buttons)button.disabled=disabled;
        state.buttons=[];form.removeAttribute('aria-busy');
    };
    const uploadForms = new Set($$('[data-content-upload],[data-brand-font]').map(input => input.form));
    for (const form of uploadForms) {
        const state = {pending:new Map(),buttons:[]}; contentUploads.set(form,state);
        form.addEventListener('submit',event => { if (state.pending.size) { event.preventDefault(); event.stopImmediatePropagation(); contentStatus(form,'Please wait while the image is prepared.'); } },true);
        form.addEventListener('reset',() => resetContentUploads(form));
        form.closest('dialog')?.addEventListener('close',() => resetContentUploads(form));
    }
    const finishContentUpload = (form,input,token) => {
        const state=contentUploads.get(form); if(state.pending.get(input)!==token)return;
        state.pending.delete(input); if(!state.pending.size){for(const [button,disabled]of state.buttons)button.disabled=disabled;state.buttons=[];form.removeAttribute('aria-busy');}
    };
    $$('[data-content-upload],[data-brand-font]').forEach(input => input.addEventListener('change', async () => {
        const form = input.form; if (!form) return;
        const state=contentUploads.get(form),token={};
        if(!state.pending.size){state.buttons=$$('button:not([type]),button[type="submit"],input[type="submit"]',form).map(button=>[button,button.disabled]);state.buttons.forEach(([button])=>button.disabled=true);}
        state.pending.set(input,token);form.setAttribute('aria-busy','true');
        try {
            const file = selectedUploadFile(input); if (!file) return;
            if (input.hasAttribute('data-brand-font')) {
                if (file.size > 500000) throw new Error('Choose a WOFF, WOFF2 or TTF font up to 500 KB.');
                const extension=file.name.split('.').pop().toLowerCase();
                const data=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result));reader.onerror=()=>reject(new Error('The font could not be read. Try again.'));reader.readAsDataURL(file);});
                if(state.pending.get(input)!==token)return;
                form.elements.web_font.value=`data:font/${extension};base64,${data.split(',')[1]}`;
                form.elements.web_font_name.value=file.name;
                form.elements.font_license.checked=false;input.value='';
                setFileDropStatus(input,`${file.name} · ${imageFileSize(file.size)} — ready. Confirm its license and save.`);
                contentStatus(form,`${file.name} ready. Confirm its license and save.`);return;
            }
            if (input.dataset.contentUpload === 'image' && file.type === 'application/pdf') {
                if (file.size > 10000000) throw new Error('Choose a PDF up to 10 MB.');
                setContentImage(form, 'image', ''); form.elements.file_kind.value = 'file';
                if (!form.elements.name.value) form.elements.name.value = file.name.slice(0,100);
                setFileDropStatus(input,`${file.name} · ${imageFileSize(file.size)} — ready to save.`);
                contentStatus(form, `PDF ready · ${imageFileSize(file.size)}. Save to keep your changes.`); return;
            }
            setFileDropStatus(input,`${file.name} — preparing image…`);
            contentStatus(form, 'Preparing image… Larger images are resized automatically.');
            let prepared;
            if(form.matches('[data-brand-form]')&&(file.type==='image/svg+xml'||file.name.toLowerCase().endsWith('.svg'))){
                if(file.size>2_000_000)throw new Error('Choose an SVG logo up to 2 MB. SVG logos stay in vector format.');
                const raw=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=()=>reject(new Error('The SVG could not be read.'));reader.readAsDataURL(file);});
                const data=new FormData();data.set('csrf_token',form.elements.csrf_token.value);data.set('action','brand_logo_svg');data.set('image',`data:image/svg+xml;base64,${raw}`);
                const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
                if(!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('The SVG upload could not be processed. Reload this page and try again.');
                const result=await response.json();if(!response.ok||result.error)throw new Error(result.error||'The SVG logo could not be prepared.');
                prepared={...result,name:file.name};
            }else prepared=await prepareAudienceImage(file,{storedBytes:input.dataset.contentUpload==='image'?1_000_000:2_000_000});
            if(state.pending.get(input)!==token)return;
            setContentImage(form,input.dataset.contentUpload,prepared.data);
            if(form.matches('[data-brand-form]')){
                const logos={logo:form.elements.logo.value,secondary_logo:form.elements.secondary_logo.value};
                const matched=await matchAudienceBrandLogos(logos);
                if(state.pending.get(input)!==token)return;
                if(form.elements.logo.value===logos.logo&&form.elements.secondary_logo.value===logos.secondary_logo)setContentImage(form,'secondary_logo',matched.secondary_logo);
            }
            if(input.dataset.contentUpload==='image'){
                form.elements.file_kind.value='image';
                if(!form.elements.name.value)form.elements.name.value=prepared.name.slice(0,100);
            }
            input.value='';
            if(form.matches('[data-brand-form]')){
                setFileDropStatus(input,`${prepared.name} — ready to save.`);
                contentStatus(form,'Logo ready. Both logos use the default logo’s pixel dimensions. Save to keep your changes.');return;
            }
            setFileDropStatus(input,`${prepared.name} · ${prepared.optimized?imageFileSize(prepared.originalBytes)+' → ':''}${imageFileSize(prepared.bytes)} — ready to save.`);
            contentStatus(form,prepared.optimized?`Image optimized: ${imageFileSize(prepared.originalBytes)} → ${imageFileSize(prepared.bytes)} · ${prepared.width} × ${prepared.height} pixels. Save to keep this version.`:`Image ready · ${imageFileSize(prepared.bytes)}. Save to keep your changes.`);
        } catch(error) { if(state.pending.get(input)===token){input.value='';setFileDropStatus(input,error.message,true);contentStatus(form,error.message);} }
        finally { finishContentUpload(form,input,token); }
    }));
    $$('[data-content-clear]').forEach(button => button.addEventListener('click', () => {
        const input=$(button.dataset.contentClear==='web_font'?'[data-brand-font]':`[data-content-upload="${button.dataset.contentClear}"]`,button.form);
        if(input){finishContentUpload(button.form,input,contentUploads.get(button.form).pending.get(input));input.value='';setFileDropStatus(input);}
        setContentImage(button.form, button.dataset.contentClear, '');
        if(button.dataset.contentClear==='web_font'){button.form.elements.web_font_name.value='';button.form.elements.font_license.checked=false;}
        contentStatus(button.form, 'Removed from this form. Save to keep the change.');
    }));
    const contentEditor = $('[data-content-editor]');
    if (contentEditor) {
        // A file link opens its editor once; refreshing or closing returns to My files.
        // Keep the form's explicit action so validation errors retain the edited file.
        const contentLocation = new URL(location.href);
        if (contentLocation.searchParams.has('item')) {
            contentLocation.searchParams.delete('item');
            history.replaceState(history.state, '', contentLocation);
        }
        if (contentEditor.elements.image.value) setContentImage(contentEditor, 'image', contentEditor.elements.image.value);
        $('[data-content-new]')?.addEventListener('click', () => {
            contentEditor.reset(); for (const key of ['id','version']) contentEditor.elements[key].value = '0'; contentEditor.elements.file_kind.value = 'image';
            for (const key of ['image','name','alt','link','folder']) contentEditor.elements[key].value = '';
            setContentImage(contentEditor, 'image', ''); $('[data-content-dimensions]', contentEditor).textContent = '';
            $('#content-edit-title').textContent = 'Upload file'; contentStatus(contentEditor, ''); show('content-edit');
        });
        const editImage = rotate => {
            const image = new Image(); image.onerror = () => contentStatus(contentEditor, 'Upload an image first.');
            image.onload = () => {
                const values = Object.fromEntries($$('[data-content-crop]',contentEditor).map(input => [input.dataset.contentCrop, Number(input.value)]));
                const {x,y,width,height,output_width:ow,output_height:oh} = values;
                if (!rotate && (!Object.values(values).every(Number.isInteger) || x<0 || y<0 || width<1 || height<1 || ow<1 || oh<1 || x+width>image.naturalWidth || y+height>image.naturalHeight || ow*oh>16000000)) { contentStatus(contentEditor, 'Choose a crop inside the image and an output of 16 megapixels or less.'); return; }
                const canvas = document.createElement('canvas'); canvas.width = rotate ? image.naturalHeight : ow; canvas.height = rotate ? image.naturalWidth : oh;
                const ctx = canvas.getContext('2d'); if (!ctx) return;
                if (rotate) { ctx.translate(canvas.width,0); ctx.rotate(Math.PI/2); ctx.drawImage(image,0,0); }
                else ctx.drawImage(image,x,y,width,height,0,0,ow,oh);
                const result = canvas.toDataURL('image/png'); if (result.length > 1333358) { contentStatus(contentEditor, 'The edited image is too large. Choose smaller output dimensions.'); return; }
                setContentImage(contentEditor,'image',result); contentStatus(contentEditor,'Image edits applied. Save to keep this version.');
            }; image.src = contentEditor.elements.image.value;
        };
        $('[data-content-crop-apply]',contentEditor)?.addEventListener('click', () => editImage(false));
        $('[data-content-rotate]',contentEditor)?.addEventListener('click', () => editImage(true));
        contentEditor.addEventListener('submit',event => { if (!contentEditor.elements.image.value && !contentEditor.elements.image_file.files.length && !(contentEditor.elements.file_kind.value === 'file' && Number(contentEditor.elements.id.value) > 0)) { event.preventDefault(); contentStatus(contentEditor,'Upload an image or PDF before saving.'); } });
    }
    const contentFilters = $('.content-filters');
    if (contentFilters) {
        const defaults = { search: '', folder: '', type: '', sort: 'updated_at', direction: 'desc', layout: 'grid', per_page: '25' };
        const fields = Object.entries(defaults).map(([name, value]) => ({ control: contentFilters.elements.namedItem(name), value })).filter(field => field.control);
        const apply = $('button[type=submit],button:not([type])', contentFilters);
        const value = field => field.control.value.trim();
        const applied = fields.map(value);
        const syncFilters = () => {
            fields.forEach(field => field.control.classList.toggle('content-filter-active', value(field) !== field.value));
            apply?.classList.toggle('content-filter-apply-reminder', fields.some((field, index) => value(field) !== applied[index]));
        };
        contentFilters.addEventListener('input', syncFilters);
        contentFilters.addEventListener('change', syncFilters);
        contentFilters.addEventListener('submit', () => apply?.classList.remove('content-filter-apply-reminder'));
        window.addEventListener('pageshow', syncFilters);
        syncFilters();
    }
    const contentSelection = $('[data-content-selection]');
    if (contentSelection) {
        const all = $('[data-content-all]',contentSelection), items = $$('[data-content-select]',contentSelection);
        const update = () => { const count=items.filter(input=>input.checked).length; if (all) { all.checked=count>0&&count===items.length; all.indeterminate=count>0&&count<items.length; } const label=$('[data-content-count]',contentSelection);if(label)label.textContent=`${count} selected`; $$('button[name=operation]',contentSelection).forEach(button=>button.disabled=!count); };
        all?.addEventListener('change',()=>{items.forEach(input=>input.checked=all.checked);update();});items.forEach(input=>input.addEventListener('change',update));update();
        let trashApproved = false, checkingTrash = false;
        contentSelection.addEventListener('submit', async event => {
            const submitter = event.submitter;
            if (!['delete','purge'].includes(submitter?.value) || trashApproved) return;
            event.preventDefault();
            if (checkingTrash) return;
            checkingTrash = true;
            contentSelection.elements.confirm_delete.value = '';
            try {
                if (await window.DnrAdminUnlock?.request()) {
                    if (submitter.value === 'purge') {
                        const dialog = $('#content-delete-confirm');
                        if (!dialog) return;
                        const count = items.filter(input => input.checked).length;
                        $('[data-content-delete-message]', dialog).textContent = `Permanently delete ${count} selected file${count === 1 ? '' : 's'} from Trash? This cannot be undone. Existing emails keep their inserted copies.`;
                        const confirmed = await new Promise(resolve => {
                            dialog.addEventListener('close', () => resolve(dialog.returnValue === 'delete'), { once: true });
                            dialog.returnValue = ''; dialog.showModal();
                        });
                        if (!confirmed || !(await window.DnrAdminUnlock?.request())) return;
                        contentSelection.elements.confirm_delete.value = '1';
                    }
                    trashApproved = true;
                    contentSelection.requestSubmit(submitter);
                }
            } finally { trashApproved = false; checkingTrash = false; }
        });
    }
    $$('[data-delivery-refresh]').forEach(button=>button.addEventListener('click',()=>location.reload()));
    $$('[data-copy-delivery]').forEach(button=>button.addEventListener('click',async()=>{const status=$('[data-delivery-copy-status]',button.closest('dialog')||document);try{await navigator.clipboard.writeText(button.dataset.copyDelivery);if(status)status.textContent='Copied.';}catch{if(status)status.textContent='Copy is unavailable. Select the record value and copy it.';}}));
    const brandApply=$('[data-brand-apply]');if(brandApply){const select=brandApply.elements.template_id;const update=()=>brandApply.elements.version.value=select.selectedOptions[0]?.dataset.version||'0';select.addEventListener('change',update);update();}
})();
