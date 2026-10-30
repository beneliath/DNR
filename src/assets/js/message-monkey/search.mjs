import {$, $$, el, button} from './dom.mjs';

export function initializeWorkspaceSearch(config) {
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
            if (item.updated_at) { const date = new Date(item.updated_at.replace(' ', 'T') + 'Z'); if (!Number.isNaN(date.getTime())) detail += ` • Last edited ${window.DnrUserTime.format(date, {weekday:'short',month:'long',day:'numeric',year:'numeric'})}`; }
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
}

