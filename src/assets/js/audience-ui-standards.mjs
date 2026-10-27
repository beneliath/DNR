const SEARCH_ICON='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/></svg>';
const CLOSE_ICON='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg>';
const FILTERS='select[name=state],select[name=status],select[name=type],select[name=source],select[name=bots],select[data-flow-template-channel],select[data-flow-template-topic],select[data-flow-template-integration],select[data-template-category-filter]';
const BADGES='.audience-status,.campaign-status,.flow-status-badge,.flow-status,.inbox-status,.inbox-status-tag,.inbox-source-state,.inbox-delivery-state,[data-touchpoint-status]';

export function messageMonkeyStatusTone(value){
    const state=String(value??'').trim().toLowerCase().replace(/[ _]+/g,'-');
    if(['active','running','ongoing','sending','live','in-progress','open'].includes(state))return 'active';
    if(['complete','completed','sent','published','subscribed','verified','ready','success','delivered','approved','connected','confirmed-by-administrator','closed','resolved'].includes(state))return 'positive';
    if(['pending','scheduled','queued','processing','waiting','awaiting-receipt','retry','needs-review'].includes(state))return 'pending';
    if(['failed','error','blocked','unsubscribed','cleaned','bounced','rejected','not-connected'].includes(state))return 'negative';
    if(['draft','paused','archived','not-started','unavailable','cancelled','skipped','stopped','concluded'].includes(state))return 'neutral';
    return null;
}

export function wireMessageMonkeyUiStandards(){
    if(!document.body){document.addEventListener('DOMContentLoaded',wireMessageMonkeyUiStandards,{once:true});return;}
    if(!document.body.classList.contains('audience-app')||document.documentElement.dataset.mmUiStandards)return;
    document.documentElement.dataset.mmUiStandards='1';
    const groups=new WeakMap();let queued=false;
    const icon=html=>{const template=document.createElement('template');template.innerHTML=html;return template.content.firstElementChild;};
    function actions(){
        const archiveGroups=new Map();
        for(const button of document.querySelectorAll('.audience-app :is(button,a)')){
            button.classList.toggle('mm-apply-button',button.matches('button')&&/^apply(?:\s|$)/i.test(button.textContent.trim()));
            button.classList.toggle('mm-clear-all',/^clear all$/i.test(button.textContent.trim()));
            const actionLabel=button.getAttribute('aria-label')||button.getAttribute('title')||button.textContent.trim(),hasIcon=!!button.querySelector('svg,img');
            const archive=hasIcon&&/^(?:archive\b|move to (?:the )?archive\b)/i.test(actionLabel);
            button.classList.toggle('mm-archive-action',archive);
            button.classList.toggle('mm-restore-action',hasIcon&&/^(?:restore|unarchive)\b/i.test(actionLabel));
            button.classList.toggle('mm-delete-action',hasIcon&&/^(?:delete\b|permanently delete\b)/i.test(actionLabel));
            button.classList.toggle('mm-report-action',hasIcon&&/^(?:view (?:flow |email |campaign )?report\b|report\b|results\b|view results\b)/i.test(actionLabel));
            button.classList.toggle('mm-duplicate-action',hasIcon&&/^(?:duplicate|replicate)\b/i.test(actionLabel));
            if(archive){
                const owner=button.parentElement.matches('form')?button.parentElement:button,group=owner.parentElement;
                if(/^(inline-)?(flex|grid)$/.test(getComputedStyle(group).display)){
                    const owners=archiveGroups.get(group)||[];if(!owners.includes(owner))owners.push(owner);archiveGroups.set(group,owners);
                }
            }
            // Shell controls and branding have their own layout and icon sizing.
            // A1 applies to explicit content action buttons, never arbitrary SVG links/buttons.
            if(button.matches('[data-audience-logo]')||button.closest('.audience-topbar,.audience-account-tools,.inbox-top,.designer-topbar'))continue;
            if(button.closest('#audience-sidebar,.audience-sidebar,.mm-search-control,.campaign-search,.search-with-icon,.filter-search,.inbox-search,.audience-search-top,.designer-color-grid'))continue;
            const close=button.matches('button')&&button.closest('dialog,[role=dialog],[popover]')&&(button.matches('[data-close-dialog],[data-design-close]')||/^close\b/i.test(button.getAttribute('aria-label')||''))&&(!button.textContent.trim()||/^[×✕✖xX]$/.test(button.textContent.trim())||button.querySelector('svg'));
            if(close){
                button.classList.add('mm-close-button');
                button.classList.remove('action-icon-button');
                if(!button.querySelector('svg'))button.replaceChildren(icon(CLOSE_ICON));
                if(!button.getAttribute('aria-label'))button.setAttribute('aria-label','Close');
                button.removeAttribute('data-tooltip');
                button.removeAttribute('data-audience-tooltip');
                button.removeAttribute('title');
            }else if(button.matches('.designer-rich-toolbar-row>button,[data-template-layout]'))button.classList.add('action-icon-button');
        }
        for(const [group,owners] of archiveGroups){
            const children=Array.from(group.children),desired=[...children.filter(child=>!owners.includes(child)),...owners];
            if(children.some((child,index)=>child!==desired[index]))for(const owner of owners)group.append(owner);
        }
    }
    function searches(){
        for(const input of document.querySelectorAll('.audience-app input[type=search]')){
            const row=input.closest('.audience-filters,.collection-filters,.content-filters,.template-toolbar,.campaign-list-toolbar,.audience-definition-controls,.campaign-plan-search,.flow-overview-filters,.flow-catalog-controls,.analytics-inline-controls,.designer-preview-controls,.inbox-recipient-search,.collection-image-browser,.mm-manual-search-row,.inbox-search-manual');
            if(row&&!input.closest('.audience-topbar,.designer-topbar,.inbox-top'))row.classList.add('mm-search-row');
            if(input.dataset.mmSearch)continue;
            input.dataset.mmSearch='1';
            const searchScope=input.form||input.closest('dialog,[popover]')||document;
            const textSubmit=input.id&&Array.from(searchScope.querySelectorAll('[data-mm-search-text]')).find(button=>button.dataset.mmSearchText===input.id);
            if(textSubmit){
                let applied=input.value.trim();
                const pending=()=>textSubmit.classList.toggle('mm-search-pending',input.value.trim()!==applied);
                const engaged=()=>{applied=input.value.trim();pending();};
                input.addEventListener('input',pending);input.addEventListener('search',pending);
                input.form?.addEventListener('submit',engaged);
                if(textSubmit.type==='button'||!input.form){textSubmit.addEventListener('click',engaged);input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();textSubmit.click();}});}
                for(const clear of searchScope.querySelectorAll('[data-mm-search-clear]'))if(clear.dataset.mmSearchClear===input.id)clear.addEventListener('click',event=>{event.preventDefault();input.value='';engaged();input.focus();});
                window.addEventListener('pageshow',pending);
            }
            const existing=input.closest('.campaign-search,.search-with-icon,.filter-search,.inbox-search,.audience-search-top,.mm-search-field');
            if(existing){if(!existing.querySelector('svg'))existing.prepend(icon(SEARCH_ICON));continue;}
            const before=input.previousElementSibling,after=input.nextElementSibling;
            if(before?.matches('svg,button:has(svg),.inbox-icon')||after?.matches('button[aria-label^="Search"]:has(svg)'))continue;
            const label=input.parentElement.matches('label')?input.parentElement:null;
            const nearby=label?label.nextElementSibling:input.nextElementSibling;
            const candidate=(nearby?.matches('button')&&/^search$/i.test(nearby.textContent.trim()))?nearby:(input.form?.method.toLowerCase()==='get'?Array.from(input.form.querySelectorAll('button')).find(button=>/^search$/i.test(button.textContent.trim())):nearby);
            let submit=candidate?.matches('button:not([data-mm-search-text])')&&(/^search$/i.test(candidate.textContent.trim())||candidate.matches('[data-collection-image-find],[data-flow-person-find]'))?candidate:null;
            if(!submit&&input.form?.method.toLowerCase()==='get'&&!input.form.querySelector('[data-mm-search-text]')){
                submit=document.createElement('button');submit.type='submit';submit.textContent='Search';
            }
            const field=document.createElement('span');field.className='mm-search-field';
            input.before(field);field.append(input);
            if(submit){
                const control=document.createElement('div');control.className='mm-search-control has-action';
                const anchor=label||field;anchor.before(control);control.append(anchor,submit);
                submit.setAttribute('aria-label',submit.getAttribute('aria-label')||submit.textContent.trim()||'Search');
                submit.classList.add('mm-search-submit');submit.replaceChildren(icon(SEARCH_ICON));
            }else field.prepend(icon(SEARCH_ICON));
        }
        for(const button of document.querySelectorAll('.mm-search-submit'))if(!button.querySelector('svg'))button.replaceChildren(icon(SEARCH_ICON));
    }
    function syncGroup(select,entry){
        const options=Array.from(select.options);
        const signature=JSON.stringify(options.map(option=>[option.value,option.textContent,option.disabled]));
        if(entry.signature!==signature){
            entry.group.replaceChildren();
            const caption=document.createElement('span');caption.className='mm-filter-label';caption.textContent=entry.label;entry.group.append(caption);
            for(const option of options){
                const button=document.createElement('button');button.type='button';button.className='mm-filter-choice';button.textContent=option.textContent;
                button.dataset.value=option.value;
                button.addEventListener('click',()=>{
                    if(select.disabled||option.disabled||select.value===option.value)return;
                    select.value=option.value;syncGroup(select,entry);select.dispatchEvent(new Event('change',{bubbles:true}));
                    if(select.form?.method.toLowerCase()==='get'&&!select.matches('[data-auto-submit],[data-analytics-submit]'))select.form.requestSubmit();
                });
                entry.group.append(button);
            }
            entry.signature=signature;
        }
        for(const button of entry.group.querySelectorAll('button')){
            button.setAttribute('aria-pressed',String(button.dataset.value===select.value));
            button.disabled=select.disabled||options.find(option=>option.value===button.dataset.value)?.disabled;
        }
    }
    function filters(){
        for(const select of document.querySelectorAll(FILTERS)){
            const entry=groups.get(select);if(entry){syncGroup(select,entry);continue;}
            const local=select.closest('[data-flow-catalog-controls],.template-toolbar,#template-filters-dialog');
            if(!local&&(!select.form||select.form.method.toLowerCase()!=='get'))continue;
            if(select.multiple||select.options.length<2||select.options.length>12)continue;
            const parent=select.closest('label');
            const text=parent?Array.from(parent.childNodes).filter(node=>node.nodeType===Node.TEXT_NODE).map(node=>node.textContent).join(' ').replace(/:\s*$/,'').trim():'';
            const label=select.getAttribute('aria-label')||text||select.name||'Filter';
            const group=document.createElement('div');group.className='mm-filter-group';group.setAttribute('role','group');group.setAttribute('aria-label',label);
            const anchor=parent&&parent.querySelectorAll('input,select,textarea').length===1?parent:select;
            anchor.before(group);anchor.classList.add('mm-filter-source');
            const next={group,label,signature:null};groups.set(select,next);syncGroup(select,next);
        }
        for(const trigger of document.querySelectorAll('.campaign-filter[popovertarget]')){
            const target=trigger.getAttribute('popovertarget');
            const name=target?.match(/^campaign-filter-(type|status)$/)?.[1];if(!name)continue;
            const menu=document.getElementById(target),choices=menu?.querySelectorAll('[data-campaign-filter]');
            if(!choices?.length)continue;
            let entry=groups.get(trigger);
            if(!entry){
                const group=document.createElement('div');group.className='mm-filter-group mm-campaign-filter-group';group.setAttribute('role','group');group.setAttribute('aria-label',name==='type'?'Type':'Status');
                const caption=document.createElement('span');caption.className='mm-filter-label';caption.textContent=name==='type'?'Type':'Status';group.append(caption);
                for(const original of choices){
                    const button=document.createElement('button');button.type='button';button.className='mm-filter-choice';button.textContent=original.textContent.trim();button.dataset.value=original.dataset.value||'';
                    button.addEventListener('click',()=>original.click());group.append(button);
                }
                trigger.before(group);trigger.classList.add('mm-filter-source');entry={group};groups.set(trigger,entry);
            }
            const value=trigger.closest('form')?.elements.namedItem(name)?.value||'';
            for(const button of entry.group.querySelectorAll('button'))button.setAttribute('aria-pressed',String(button.dataset.value===value));
        }
    }
    function statuses(){
        for(const badge of document.querySelectorAll(BADGES)){
            const tone=messageMonkeyStatusTone(badge.dataset.touchpointStatus||badge.dataset.state||badge.dataset.status||badge.textContent);
            if(tone&&badge.dataset.mmStatusTone!==tone)badge.dataset.mmStatusTone=tone;
            else if(!tone&&badge.dataset.mmStatusTone)delete badge.dataset.mmStatusTone;
        }
    }
    function refresh(){queued=false;searches();filters();actions();statuses();}
    const schedule=()=>{if(!queued){queued=true;requestAnimationFrame(refresh);}};
    refresh();new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true,characterData:true});
    document.addEventListener('change',schedule);document.addEventListener('reset',schedule);
    document.addEventListener('toggle',schedule,true);
}
