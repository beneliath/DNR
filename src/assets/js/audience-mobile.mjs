// Mobile is a presentation of the existing controls, forms and permissions.
// Original controls handle every operation; no second set of business actions.
export const audienceMobileQuery = '(max-width: 860px), (max-width: 1024px) and (hover: none) and (pointer: coarse)';

export function wireAudienceMobile() {
    if (!document.body.hasAttribute('data-mm-workspace')) return;
    const media = matchMedia(audienceMobileQuery);
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const menu = $('#mm-mobile-menu');
    const opener = $('[data-mm-menu-open]');
    opener?.addEventListener('click', () => menu.showModal());
    $('[data-mm-menu-close]')?.addEventListener('click', () => menu.close());
    menu?.addEventListener('close', () => { if (media.matches) opener?.focus(); });
    menu?.addEventListener('click', event => {
        if (event.target !== menu) return;
        const r = menu.getBoundingClientRect();
        if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) menu.close();
    });
    let serial = 0, frame = 0;
    const cleanups = [];
    const remember = (node, attribute, value) => {
        if (node.getAttribute(attribute) === value) return;
        const before = node.getAttribute(attribute);
        node.setAttribute(attribute, value);
        cleanups.push(() => before === null ? node.removeAttribute(attribute) : node.setAttribute(attribute, before));
    };
    const makeButton = (text, className) => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = `mm-mobile-only ${className}`; button.textContent = text;
        cleanups.push(() => button.remove());
        return button;
    };
    const identify = node => { if (!node.id) remember(node, 'id', `mm-mobile-${++serial}`); return node.id; };
    const disclosure = (button, target, openText, closedText) => {
        button.setAttribute('aria-controls', identify(target));
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', () => {
            const open = !target.hasAttribute('data-mm-open');
            target.toggleAttribute('data-mm-open', open);
            button.setAttribute('aria-expanded', String(open));
            button.textContent = open ? openText : closedText;
        });
        cleanups.push(() => target.removeAttribute('data-mm-open'));
    };

    function actions() {
        const selectors = '.template-card-actions,.campaign-row-actions,.audience-tag-actions,.audience-segment-actions,.audience-field-actions,.group-toolbar,.collection-row-actions,.collection-lifecycle-actions,.flow-overview-row-actions,.flow-report-actions,.content-card-actions,.inbox-label-actions,.inbox-template-card>footer';
        for (const group of $$(selectors)) {
            if (group.hasAttribute('data-mm-actions')) continue;
            const controls = $$('.action-icon-button', group);
            if (!controls.length) continue;
            remember(group, 'data-mm-actions', '');
            for (const control of controls) {
                const label = document.createElement('span');
                label.className = 'mm-mobile-only mm-mobile-action-label';
                label.textContent = (control.dataset.tooltip || control.title || control.getAttribute('aria-label') || 'Open').replace(/\s*\(Admin Unlock required\)/i, '');
                control.append(label); cleanups.push(() => label.remove());
            }
            if (group.children.length > 2) {
                remember(group, 'data-mm-action-disclosure', '');
                const button = makeButton('More actions', 'mm-mobile-action-toggle');
                disclosure(button, group, 'Fewer actions', 'More actions');
                group.append(button);
            }
        }
    }

    function tables() {
        for (const table of $$('.audience-content table,.inbox-main .data-table')) {
            if (table.matches('.campaign-calendar') || table.closest('.collection-preview,.designer-stage,[contenteditable]') || !table.tHead || table.tHead.rows.length !== 1) continue;
            const heads = [...table.tHead.rows[0].cells];
            if (heads.some(cell => cell.colSpan > 1 || cell.rowSpan > 1)) continue;
            const reference = !!table.closest('.audience-manual');
            if (!table.hasAttribute('data-mm-card-table')) {
                remember(table, 'data-mm-card-table', '');
                remember(table, 'role', 'table');
                for(const body of table.tBodies)remember(body,'role','rowgroup');
                for(const head of heads){
                    remember(head,'aria-label',head.textContent.trim() || ($('input[type=checkbox]',head)?'Selection':'Actions'));
                    for(const control of $$('a,button,input,select',head)){
                        remember(control,'tabindex','-1');remember(control,'aria-hidden','true');
                    }
                }
                const tools = document.createElement('div');
                tools.className = 'mm-mobile-only mm-mobile-list-tools';
                table.before(tools); cleanups.push(() => tools.remove());
                const selectPage = $('thead input[type=checkbox]', table);
                if (selectPage) {
                    const select = makeButton('Select page', 'mm-mobile-select-page');
                    const sync = () => {
                        select.setAttribute('aria-pressed', String(selectPage.checked));
                        select.textContent = selectPage.checked ? 'Deselect page' : 'Select page';
                        select.disabled = selectPage.disabled;
                    };
                    select.addEventListener('click', () => { selectPage.click(); sync(); });
                    table.addEventListener('change', sync); cleanups.push(() => table.removeEventListener('change', sync));
                    sync(); tools.append(select);
                }
                // Keep sorting available when the desktop table headings are folded away.
                const sorts = $$('thead a[href],thead button[popovertarget]', table);
                if (sorts.length) {
                    const label = document.createElement('label'); label.textContent = 'Sort';
                    const select = document.createElement('select');
                    select.add(new Option('Choose sorting', ''));
                    sorts.forEach((control, index) => select.add(new Option(control.textContent.trim(), String(index))));
                    select.addEventListener('change', () => {
                        if (select.value !== '') sorts[Number(select.value)].click();
                        select.value = '';
                    });
                    label.append(select); tools.append(label);
                }
                if (!tools.children.length) tools.remove();
            }
            for (const row of $$('tbody>tr', table)) {
                if (row.hasAttribute('data-mm-card-row')) continue;
                const cells = [...row.cells];
                if (cells.length !== heads.length || cells.some(cell => cell.colSpan > 1)) continue;
                remember(row, 'data-mm-card-row', ''); remember(row, 'role', 'row');
                // Reference explanations should remain readable together,
                // without the disclosures used for operational record lists.
                if (reference) remember(row, 'data-mm-open', '');
                let primary = null;
                const details = [];
                cells.forEach((cell, index) => {
                    const heading = heads[index].textContent.trim().replace(/[↑↓↕]/g, '').trim();
                    remember(cell, 'role', cell.matches('th') ? 'rowheader' : 'cell');
                    remember(cell, 'data-mm-label', heading);
                    if ($('input:not([type=hidden]):not([type=checkbox]),select,textarea,[contenteditable=true]',cell)) remember(cell,'data-mm-control','');
                    const selection = cell.matches('.selection-cell,.definition-check,.campaign-select-column') || (!cell.textContent.trim() && $('input[type=checkbox]', cell));
                    const action = /actions/i.test(heading) || !!$('[data-mm-actions],.row-actions', cell);
                    const status = /status/i.test(heading) || cell.matches('.segment-count');
                    if (selection) {
                        remember(cell, 'data-mm-cell', 'selection');
                        const input = $('input[type=checkbox]', cell);
                        if (input && !input.closest('label')) {
                            const label = document.createElement('label');label.className='mm-mobile-selection-target';
                            input.before(label);label.append(input);
                            cleanups.push(()=>{label.before(input);label.remove();});
                        }
                    }
                    else if (action) remember(cell, 'data-mm-cell', 'actions');
                    else if (!primary) { primary = cell; remember(cell, 'data-mm-cell', 'primary'); }
                    else if (status) remember(cell, 'data-mm-cell', 'status');
                    else { remember(cell, 'data-mm-cell', 'detail'); details.push(cell); }
                });
                if (primary && details.length && !reference) {
                    if (table.matches('[data-people-table]')) {
                        const name = ['first_name','last_name'].map(key => $(`[data-column="${key}"]`,row)?.textContent.trim()).filter(Boolean).join(' ');
                        if (name) {
                            const title = document.createElement('strong'); title.className = 'mm-mobile-only mm-mobile-person-name'; title.textContent = name;
                            primary.prepend(title); cleanups.push(() => title.remove());
                        }
                    }
                    const title = primary.textContent.trim().replace(/\s+/g, ' ').slice(0, 100);
                    const button = makeButton('Details', 'mm-mobile-details-toggle');
                    button.setAttribute('aria-label', `Details for ${title}`);
                    disclosure(button, row, 'Hide details', 'Details');
                    primary.append(button);
                }
            }
        }
    }

    function filters() {
        for (const bar of $$('.audience-filter-popovers,.template-toolbar,.campaign-filters,.collection-filters,.flow-overview-filters,.content-filters,.audience-definition-toolbar')) {
            if (bar.hasAttribute('data-mm-filters')) continue;
            const controls = [...bar.children].filter(child => !child.matches('input[type=hidden],[popover],script,[hidden]'));
            if (controls.length < 3) continue;
            remember(bar, 'data-mm-filters', '');
            for (const child of controls) {
                const input = $('input[type=search],input[name=q]', child);
                const search = child.matches('input[type=search],[data-mm-search-text],.filter-search-submit') || (input && !input.closest('[popover],dialog') && !child.matches('.campaign-filter-row'));
                remember(child, 'data-mm-filter', search ? 'search' : 'secondary');
            }
            const button = makeButton('Filters & sort', 'mm-mobile-filter-toggle');
            disclosure(button, bar, 'Close filters', 'Filters & sort'); bar.append(button);
        }
        const heading = $('.audience-page-heading>.audience-actions');
        if (heading && !heading.hasAttribute('data-mm-heading-actions')) {
            const children = [...heading.children].filter(child => !child.hidden);
            if (children.length > 2) {
                remember(heading, 'data-mm-heading-actions', '');
                const primary = children.find(child => child.matches('.primary') || $('.primary', child)) || children[0];
                remember(primary, 'data-mm-heading-primary', '');
                const button = makeButton('More actions', 'mm-mobile-heading-toggle');
                disclosure(button, heading, 'Fewer actions', 'More actions'); heading.append(button);
            }
        }
        const counts = $('.audience-counts');
        if (counts && !counts.hasAttribute('data-mm-counts')) {
            remember(counts, 'data-mm-counts', '');
            const button = makeButton('Audience breakdown', 'mm-mobile-counts-toggle');
            disclosure(button, counts, 'Hide breakdown', 'Audience breakdown'); counts.prepend(button);
        }
    }

    function editor() {
        for (const [selector, first, second, initial] of [
            ['.designer-workspace', 'Email', 'Editing tools', 'first'],
            ['.collection-builder', 'Editing tools', 'Preview', 'first'],
            ['.flow-builder', 'Editing tools', 'Flow map', 'second'],
        ]) {
            const workspace = $(selector);
            if (!workspace || workspace.hasAttribute('data-mm-editor')) continue;
            remember(workspace, 'data-mm-editor', initial);
            const nav = document.createElement('nav');
            nav.className = 'mm-mobile-only mm-mobile-editor-switch';
            nav.setAttribute('aria-label', 'Editor workspace');
            const buttons = [makeButton(first, ''), makeButton(second, '')];
            const select = value => {
                workspace.setAttribute('data-mm-editor', value);
                buttons.forEach((button, index) => button.setAttribute('aria-pressed', String(value === (index ? 'second' : 'first'))));
            };
            buttons.forEach((button, index) => { button.addEventListener('click', () => select(index ? 'second' : 'first')); nav.append(button); });
            workspace.before(nav); cleanups.push(() => nav.remove()); select(initial);
            if (selector === '.designer-workspace') {
                const edit = event => {
                    if (!event.target.closest('.designer-block,.designer-layout-tools')) return;
                    select('second'); nav.scrollIntoView({block:'start'});
                };
                workspace.addEventListener('click', edit); cleanups.push(() => workspace.removeEventListener('click', edit));
            }
            if (selector === '.flow-builder') {
                const edit = event => {if(event.target.closest('.flow-node')){select('first');nav.scrollIntoView({block:'start'});}};
                workspace.addEventListener('click',edit);cleanups.push(()=>workspace.removeEventListener('click',edit));
                for(const button of $$('[data-flow-panel]')){
                    const show=()=>select('first');button.addEventListener('click',show);cleanups.push(()=>button.removeEventListener('click',show));
                }
            }
        }
    }

    function calendars() {
        for (const table of $$('.campaign-calendar')) {
            if (table.hasAttribute('data-mm-calendar')) continue;
            remember(table,'data-mm-calendar','');
            for (const cell of $$('tbody td',table)) {
                const time=$('time',cell), date=time?.dateTime || $('[data-touchpoint-new]',cell)?.dataset.touchpointNew;
                if(date && /^\d{4}-\d{2}-\d{2}$/.test(date))remember(time,'data-mm-date',new Date(date+'T12:00:00').toLocaleDateString(undefined,{weekday:'short',month:'short',day:'numeric'}));
                if($('a,.campaign-touchpoint-chip,.campaign-holiday',cell))remember(cell,'data-mm-calendar-event','');
            }
            const button=makeButton('Show empty days','mm-mobile-calendar-toggle');
            disclosure(button,table,'Hide empty days','Show empty days');table.before(button);
            if(!$('[data-mm-calendar-event]',table)){
                const empty=document.createElement('p');empty.className='mm-mobile-only';empty.textContent='Nothing scheduled in this date range';table.before(empty);cleanups.push(()=>empty.remove());
            }
        }
    }

    function assistantHistory() {
        const workspace=$('.analytics-ai-chat');
        if(!workspace || workspace.hasAttribute('data-mm-assistant'))return;
        remember(workspace,'data-mm-assistant','');
        const button=makeButton('Recent requests','mm-mobile-history-toggle');
        disclosure(button,workspace,'Hide recent requests','Recent requests');workspace.before(button);
    }

    function previews() {
        for (const thumbnail of $$('.template-thumbnail')) {
            const source = $('iframe', thumbnail);
            if (!source || thumbnail.hasAttribute('data-mm-preview')) continue;
            remember(thumbnail, 'data-mm-preview', '');
            const button = makeButton('Preview email', 'mm-mobile-preview-button');
            button.setAttribute('aria-haspopup','dialog');
            button.addEventListener('click', () => {
                const dialog = document.createElement('dialog'); dialog.className = 'mm-mobile-email-preview';
                const header = document.createElement('header');
                const title = document.createElement('h2'); title.id = `mm-mobile-preview-${++serial}`;
                title.textContent = $('h3', thumbnail.closest('.template-card'))?.textContent || 'Email preview';
                dialog.setAttribute('aria-labelledby',title.id);
                const close = document.createElement('button');close.type='button';close.textContent='Close';close.addEventListener('click',()=>dialog.close());
                const frame = document.createElement('iframe');frame.title=title.textContent;frame.setAttribute('sandbox','allow-same-origin');frame.referrerPolicy='no-referrer';
                frame.srcdoc = source.srcdoc || source.dataset.emailPreview || '';
                header.append(title,close);dialog.append(header,frame);document.body.append(dialog);
                dialog.addEventListener('close',()=>{dialog.remove();if(media.matches)button.focus();},{once:true});
                dialog.showModal();
                cleanups.push(()=>{if(dialog.open)dialog.close();dialog.remove();});
            });
            thumbnail.append(button);
        }
        for (const copy of $$('.analytics-explanation')) {
            if (copy.hasAttribute('data-mm-explanation') || copy.textContent.length < 160) continue;
            remember(copy,'data-mm-explanation','');
            const button=makeButton('About these metrics','mm-mobile-explanation-toggle');
            disclosure(button,copy,'Hide explanation','About these metrics');copy.before(button);
        }
    }

    const enhance = () => {
        frame = 0;
        if (!media.matches) return;
        actions(); tables(); filters(); editor(); previews(); calendars(); assistantHistory();
    };
    const observer = new MutationObserver(() => {
        if (media.matches && !frame) frame = requestAnimationFrame(enhance);
    });
    const sync = () => {
        observer.disconnect(); cancelAnimationFrame(frame); frame = 0;
        if (!media.matches) {
            if (menu?.open) menu.close();
            cleanups.splice(0).reverse().forEach(cleanup => cleanup());
            return;
        }
        enhance();
        observer.observe(document.body, {childList:true, subtree:true});
    };
    media.addEventListener('change', sync);
    // Let the shared controls finish their initial normalization first.
    requestAnimationFrame(sync);
}
