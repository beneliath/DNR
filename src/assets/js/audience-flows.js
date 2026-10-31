(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const setupKeys=['welcome','delivery','form','organize','library'];
    const setupStorageKey=document.body.dataset.audienceKey?`moed:message-monkey:automation-setup:${document.body.dataset.audienceKey}`:null;
    let setupState={dismissed:[],library:false};
    try{const stored=JSON.parse(setupStorageKey?localStorage.getItem(setupStorageKey):'null');if(stored&&typeof stored==='object')setupState={dismissed:Array.isArray(stored.dismissed)?stored.dismissed.filter(key=>setupKeys.includes(key)):[],library:stored.library===true};}catch(_){/* Checklist storage is optional. */}
    const saveSetup=()=>{try{if(setupStorageKey)localStorage.setItem(setupStorageKey,JSON.stringify(setupState));}catch(_){/* No application action depends on this preference. */}};
    const onboarding=$('[data-flow-onboarding]');
    if(onboarding){
        let showAll=false;
        const tasks=$$('[data-flow-task]',onboarding);
        const updateSetup=(focus=false)=>{
            let completed=0,dismissed=0;
            tasks.forEach(task=>{
                const done=task.dataset.flowTaskComplete==='1'||(task.dataset.flowTask==='library'&&setupState.library), skipped=!done&&setupState.dismissed.includes(task.dataset.flowTask);
                completed+=Number(done);dismissed+=Number(skipped);task.dataset.resolved=String(done||skipped);
                task.classList.toggle('completed',done);task.classList.toggle('dismissed',skipped);
                $('[data-flow-task-state]',task).textContent=done?'Completed':skipped?'Dismissed':'To do';
                $('[data-flow-task-dismiss]',task).hidden=done||skipped;$('[data-flow-task-restore]',task).hidden=!skipped;
            });
            const next=tasks.find(task=>task.dataset.resolved==='false')||tasks[0];
            tasks.forEach(task=>{task.hidden=!showAll&&task!==next;if(!showAll)task.open=task===next&&task.dataset.resolved==='false';});
            $('[data-flow-setup-status]',onboarding).textContent=`${completed+dismissed}/${tasks.length} tasks addressed`;
            $('[data-flow-setup-detail]',onboarding).textContent=`${completed} completed · ${dismissed} dismissed`;
            $('[data-flow-setup-progress]',onboarding).value=completed+dismissed;
            $('[data-flow-tasks-restore]',onboarding).hidden=dismissed===0;
            const toggle=$('[data-flow-tasks-toggle]',onboarding);toggle.textContent=showAll?'⌃ Show fewer':'⌄ Show all';toggle.setAttribute('aria-expanded',String(showAll));
            if(focus)$('summary',next)?.focus();
        };
        tasks.forEach(task=>{
            $('[data-flow-task-dismiss]',task).addEventListener('click',()=>{setupState.dismissed=Array.from(new Set([...setupState.dismissed,task.dataset.flowTask]));saveSetup();updateSetup(true);});
            $('[data-flow-task-restore]',task).addEventListener('click',()=>{setupState.dismissed=setupState.dismissed.filter(key=>key!==task.dataset.flowTask);saveSetup();updateSetup();});
        });
        $('[data-flow-tasks-toggle]',onboarding).addEventListener('click',()=>{showAll=!showAll;updateSetup();});
        $('[data-flow-tasks-restore]',onboarding).addEventListener('click',()=>{setupState.dismissed=[];saveSetup();updateSetup(true);});updateSetup();
    }
    $$('[data-flow-open-guide]').forEach(link=>link.addEventListener('click',()=>{const guide=$('.flow-basics');if(guide)guide.open=true;}));
    $$('[data-flow-lifecycle]').forEach(button=>button.addEventListener('click',()=>{
        const dialog=$(`#flow-${button.dataset.flowLifecycle}`);if(!dialog)return;
        const record=JSON.parse(button.dataset.flowRecord);
        $('input[name=id]',dialog).value=record.id;$('input[name=version]',dialog).value=record.version;
        $('[data-flow-lifecycle-name]',dialog).textContent=record.name;
        const confirmation=$('input[name=confirm]',dialog);if(confirmation)confirmation.value='';
    }));
    $$('.flow-overview-filters select').forEach(control=>control.addEventListener('change',()=>control.form.requestSubmit()));
    const search = $('[data-flow-template-search]'), catalogControls = $('[data-flow-catalog-controls]');
    const catalogFields = {q:search, topic:$('[data-flow-template-topic]'), channel:$('[data-flow-template-channel]'), integration:$('[data-flow-template-integration]'), sort:$('[data-flow-template-sort]')};
    const filterTemplates = () => {
        const query = search.value.trim().toLocaleLowerCase(), topic=catalogFields.topic.value, channel=catalogFields.channel.value, integration=catalogFields.integration.value;
        const keys = new Set();
        $$('[data-flow-template]').forEach(card => {
            card.hidden = !card.dataset.flowTemplate.includes(query) || (topic && card.dataset.topic !== topic) || (channel && card.dataset.channel !== channel) || (integration && !card.dataset.integrations.split(' ').includes(integration));
            if (!card.hidden) keys.add(card.dataset.templateKey);
        });
        $$('[data-flow-template-section]').forEach(section => {
            section.hidden = !$$('[data-flow-template]',section).some(card => !card.hidden);
            const grid=$('.flow-template-grid',section);
            $$('[data-flow-template]',grid).sort((a,b) => (catalogFields.sort.value==='recommended' ? Number(b.dataset.recommended)-Number(a.dataset.recommended) : 0) || a.dataset.templateName.localeCompare(b.dataset.templateName)).forEach(card => grid.append(card));
        });
        $('[data-flow-template-empty]').hidden=keys.size>0;
        $('[data-flow-template-count]').textContent=`${keys.size} flow template${keys.size===1?'':'s'}`;
        $$('[data-flow-template-clear]',catalogControls).forEach(control=>{control.hidden=!(query||topic||channel||integration||catalogFields.sort.value!=='recommended');});
        const url=new URL(location.href);
        Object.entries(catalogFields).forEach(([key,control])=>{if(control.value && !(key==='sort'&&control.value==='recommended'))url.searchParams.set(key,control.value);else url.searchParams.delete(key);});
        history.replaceState(null,'',url);
        $$('[data-flow-template] h3 a').forEach(link=>{const target=new URL(link.href);Object.keys(catalogFields).forEach(key=>{if(url.searchParams.has(key))target.searchParams.set(key,url.searchParams.get(key));else target.searchParams.delete(key);});link.href=target.href;});
    };
    if(catalogControls){
        setupState.library=true;saveSetup();
        const initial=new URLSearchParams(location.search);
        Object.entries(catalogFields).forEach(([key,control])=>{if(initial.has(key)){control.value=initial.get(key);if(control.tagName==='SELECT'&&!control.value)control.selectedIndex=0;}});
        search.addEventListener('input',filterTemplates);
        Object.values(catalogFields).filter(control=>control!==search).forEach(control=>control.addEventListener('change',filterTemplates));
        $$('[data-flow-template-clear]').forEach(control=>control.addEventListener('click',event=>{event.preventDefault();Object.entries(catalogFields).forEach(([key,input])=>{input.value=key==='sort'?'recommended':'';});filterTemplates();search.focus();}));
        filterTemplates();
    }
    // Keep catalog filters when returning from a preview, without retaining its identity.
    $$('a[data-flow-catalog-back]').forEach(link=>{const url=new URL(link.href), current=new URLSearchParams(location.search);Object.keys(catalogFields).forEach(key=>{if(current.has(key))url.searchParams.set(key,current.get(key));});link.href=url.href;});
    $('[data-flow-dismiss-hero]')?.addEventListener('click',()=>{$('[data-flow-catalog-hero]').hidden=true;search.focus();});
    let previewZoom=1;
    const previewMap=$('[data-flow-static-map]'), previewScroll=$('.flow-preview-map-scroll');
    const centerPreview=()=>{if(previewScroll)previewScroll.scrollTo({top:0,left:Math.max(0,(previewMap.getBoundingClientRect().width-previewScroll.clientWidth)/2)});};
    $$('[data-static-flow-zoom]').forEach(button=>button.addEventListener('click',()=>{
        previewZoom=button.dataset.staticFlowZoom==='reset'?1:Math.max(.4,Math.min(1.6,Math.round((previewZoom+(button.dataset.staticFlowZoom==='in'?.1:-.1))*10)/10));
        previewMap.style.zoom=previewZoom;$('[data-flow-preview-zoom]').textContent=`${Math.round(previewZoom*100)}%`;
        if(button.dataset.staticFlowZoom==='reset')centerPreview();
    }));
    centerPreview();
    let personSearchSequence=0;
    $('[data-flow-person-find]')?.addEventListener('click', async event => {
        const trigger=event.currentTarget, query=$('[data-flow-person-search]').value.trim(), select=$('[data-flow-person-choice]'), status=$('[data-flow-person-status]');
        if(!query){status.textContent='Enter a name or email address';return;}const sequence=++personSearchSequence;trigger.disabled=true;status.textContent='Searching…';select.replaceChildren(new Option('Choose a connection',''));
        try{const response=await fetch('audience.php?api=workspace_search&category=connections&q='+encodeURIComponent(query),{headers:{Accept:'application/json'},cache:'no-store'});if(!response.ok||!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('Search is unavailable');const data=await response.json();if(sequence!==personSearchSequence)return;const people=data.groups.find(group=>group.key==='connections')?.items||[];people.forEach(person=>{const id=new URL(person.href,location.href).searchParams.get('id');if(/^[1-9][0-9]*$/.test(id||''))select.append(new Option(person.title+' · '+person.description,id));});status.textContent=people.length?`${people.length} matching connections`:'No connections match this search';}
        catch(_){if(sequence===personSearchSequence)status.textContent='Search is unavailable. Try again.';}finally{if(sequence===personSearchSequence)trigger.disabled=false;}
    });
    $('[data-flow-person-clear]')?.addEventListener('click',event=>{event.preventDefault();personSearchSequence++;$('[data-flow-person-find]').disabled=false;$('[data-flow-person-choice]').replaceChildren(new Option('Choose a connection',''));$('[data-flow-person-status]').textContent='';});
    const configNode = $('#flow-config'); if (!configNode) return;
    const config = JSON.parse(configNode.textContent), clone = value => JSON.parse(JSON.stringify(value));
    let doc = clone(config.document), version = config.version, name = config.name, selected = 'trigger', zoom = 1, dirty = false, timer, saving = null, sequence = 0;
    const undo = [], redo = [], map = $('[data-flow-map]'), inspector = $('[data-flow-inspector]'), status = $('[data-flow-save-status]'), error = $('[data-flow-error]');
    const saveButton = $('[data-flow-save]');
    const topbar = $('.audience-topbar');
    const syncFlowHeader = () => document.body.style.setProperty('--flow-topbar-height', `${topbar?.getBoundingClientRect().height || 0}px`);
    syncFlowHeader();
    if (topbar) new ResizeObserver(syncFlowHeader).observe(topbar);
    let manualSaving = false, saveNotice, feedbackTimer;
    const storageKey = `moed:message-monkey:flow:${config.key}:${config.id}`;
    const el = (tag, attrs = {}, text) => {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
            else if (key === 'class') element.className = value;
            else if (value !== false && value != null) element.setAttribute(key, value === true ? '' : String(value));
        }
        if (text != null) element.textContent = text;
        return element;
    };
    const button = (text, action, attrs = {}) => el('button', {type:'button', onclick:action, ...attrs}, text);
    const icon = kind => {
        const svg=document.createElementNS('http://www.w3.org/2000/svg','svg'), path=document.createElementNS('http://www.w3.org/2000/svg','path');
        svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');svg.classList.add('flow-symbol');
        path.setAttribute('d',config.iconPaths[kind] || config.iconPaths.trigger);svg.append(path);return svg;
    };
    const field = (parent, label, control) => { const wrap = el('label', {class:'flow-field'}); wrap.append(el('span', {}, label), control); parent.append(wrap); return control; };
    const options = (choices, value, change, multiple = false) => {
        const select = el('select', {multiple, onchange:() => change(multiple ? Array.from(select.selectedOptions, o => o.value) : select.value)});
        for (const [key, label] of Object.entries(choices)) { const option = el('option', {value:key}, label); option.selected = multiple ? (value || []).map(String).includes(String(key)) : String(key) === String(value); select.append(option); }
        return select;
    };
    const input = (type, value, change, attrs = {}) => { const control = el('input', {type,value,autocomplete:'off',...attrs}); control.addEventListener(type === 'checkbox' ? 'change' : 'input', () => change(type === 'checkbox' ? control.checked : type === 'number' ? Number(control.value) : control.value)); if (type === 'checkbox') control.checked = Boolean(value); return control; };
    const showError = message => { error.textContent = message; error.hidden = !message; };
    const checkpoint = () => { undo.push(clone({doc,name})); if (undo.length > 30) undo.shift(); redo.length = 0; };
    const remember = () => { try { localStorage.setItem(storageKey, JSON.stringify({version,doc,name})); } catch (_) { /* Saving does not depend on browser storage. */ } };
    const changed = (render = true) => { dirty = true; sequence++; status.textContent = 'Unsaved changes'; if (!manualSaving) { clearTimeout(feedbackTimer); if (saveNotice) saveNotice.hidden = true; if (saveButton) saveButton.textContent = 'Save'; } remember(); clearTimeout(timer); timer = setTimeout(() => save(), 1200); if (render) renderMap(); };
    const edit = (target, key, value, redraw = true) => { if (!config.editable) return; checkpoint(); target[key] = value; changed(redraw); };
    const post = async values => {
        const response = await fetch('audience.php?view=flow', {method:'POST',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:config.csrf,id:config.id,format:'json',...values}),cache:'no-store'});
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign in to MessageMonkey in another tab, then save again. Your draft is kept here.');
        const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Unable to save this flow.'); return data;
    };
    async function save() {
        clearTimeout(timer); if (!config.editable) return true; if (saving) { const result = await saving; return result && dirty ? save() : result; } if (!dirty) return true;
        const turn = sequence; status.textContent = 'Saving…'; showError('');
        saving = (async () => {
            try {
                const data = await post({action:'flow_save',version,name,document:JSON.stringify(doc)}); version = data.version;
                const heading = $('#audience-content h1'); if (heading) heading.textContent = name;
                document.title = `${name} - MOED`;
                $$('form input[name=version]').forEach(control => { control.value = version; });
                if (turn === sequence) { dirty = false; status.textContent = 'Saved'; try { localStorage.removeItem(storageKey); } catch (_) {} }
                else { remember(); status.textContent = 'Unsaved changes'; }
                renderValidation(data.errors); return true;
            } catch (e) { status.textContent = 'Not saved'; showError(e.message); return false; }
        })();
        const result = await saving; saving = null; return result;
    }
    async function saveWithFeedback() {
        if (!config.editable || manualSaving) return;
        manualSaving = true;
        clearTimeout(feedbackTimer);
        if (!saveNotice) { saveNotice = el('p', {class:'button-feedback-notice',role:'status','aria-live':'polite','aria-atomic':'true','data-flow-save-feedback':true}); document.body.append(saveNotice); }
        const hadChanges = dirty || Boolean(saving);
        saveNotice.hidden = false; saveNotice.textContent = 'Saving draft';
        if (saveButton) { saveButton.disabled = true; saveButton.textContent = 'Saving'; saveButton.setAttribute('aria-busy','true'); }
        try {
            let saved = await save();
            // Include edits made while an earlier autosave was in flight.
            while (saved && dirty) saved = await save();
            saveNotice.textContent = saved ? (hadChanges ? 'Draft saved' : 'All changes are already saved') : `Draft was not saved. ${error.textContent}`;
            if (saveButton) saveButton.textContent = saved ? '✓ Saved' : 'Save';
        } finally {
            manualSaving = false;
            if (saveButton) { saveButton.disabled = false; saveButton.removeAttribute('aria-busy'); }
            feedbackTimer = setTimeout(() => { saveNotice.hidden = true; if (saveButton) saveButton.textContent = 'Save'; }, 5000);
        }
    }
    function renderValidation(errors = []) {
        const panel = $('[data-flow-validation]'); panel.replaceChildren(el('h2', {}, 'Review your flow'));
        if (errors.length) { const list = el('ul'); errors.forEach(message => list.append(el('li', {}, message))); panel.append(list); }
        else panel.append(el('p', {}, 'Your trigger and steps are ready for review'));
        const dialog = $('#flow-turn-on'); if (dialog) { const submit = $('button.primary', dialog); submit.disabled = errors.length > 0; const old = $('ul', dialog); old?.remove(); if (errors.length) { const list = el('ul'); errors.forEach(message => list.append(el('li', {}, message))); $('form', dialog).insertBefore(list, $('.dialog-actions', dialog)); } }
    }
    const campaignChoices = () => Object.fromEntries([['0','Choose an email'], ...config.campaigns.map(c => [c.id,c.name])]);
    const tagChoices = () => Object.fromEntries([['0','Choose a tag'], ...config.tags.map(t => [t.id,t.name])]);
    const groupChoices = () => Object.fromEntries([['0','Choose a group'], ...config.groups.flatMap(g => g.options.map(o => [o.id,`${g.name}: ${o.name}`]))]);
    function triggerFields(parent, trigger, wait = false) {
        field(parent, 'Trigger', options(wait?Object.fromEntries(Object.entries(config.triggers).filter(([key])=>!['date','birthday','anniversary','manual','status'].includes(key))):config.triggers, trigger.type, value => { edit(trigger,'type',value); renderInspector(); renderSettings(); }));
        let choices;
        if (['tag','untag'].includes(trigger.type)) choices = tagChoices();
        if (['group','ungroup'].includes(trigger.type)) choices = groupChoices();
        if (['open','click'].includes(trigger.type)) choices = {'0':'Any email', ...Object.fromEntries(config.campaigns.map(c => [c.id,c.name]))};
        if (trigger.type === 'survey') choices = {'0':'Any survey', ...Object.fromEntries(config.surveys.map(s => [s.id,s.name]))};
        if (trigger.type === 'flow') choices = {'0':'Choose a flow', ...Object.fromEntries(config.flows.filter(f => f.id !== config.id).map(f => [f.id,f.name]))};
        if (choices) field(parent, 'Choose', options(choices,trigger.id || 0,value => edit(trigger,'id',Number(value))));
        if (trigger.type === 'status') field(parent,'New email status',options({'':'Any email status',subscribed:'Subscribed',unsubscribed:'Unsubscribed','non-subscribed':'Non-subscribed',cleaned:'Cleaned',pending:'Pending confirmation'},trigger.value || '',value=>edit(trigger,'value',value)));
        if (['date','birthday','profile'].includes(trigger.type)) {
            const available = config.fields.filter(f => trigger.type === 'profile' || ['date','birthday'].includes(f.field_type));
            const fields = trigger.type === 'profile' ? {'':'Any profile change',first_name:'First name',last_name:'Last name',language:'Language',timezone:'Time zone'} : {'':'Choose an audience field'};
            available.forEach(f => { fields[f.id] = f.label; });
            field(parent,'Audience field',options(fields,trigger.field || '',value => edit(trigger,'field',value)));
        }
        if (['date','birthday','anniversary'].includes(trigger.type)) { field(parent,'Days before (−) or after (+)',input('number',trigger.offset || 0,value => edit(trigger,'offset',value),{min:-365,max:365})); field(parent,'Time',input('time',trigger.time || '09:00',value => edit(trigger,'time',value))); }
        if (['open','click'].includes(trigger.type)) parent.append(el('p', {class:'audience-help'}, 'Uses observed activity with recognized bots excluded. Privacy tools can affect opens and clicks.'));
        if (trigger.type === 'survey') parent.append(el('p', {class:'audience-help'}, 'Only responses tied to a verified recipient can trigger a flow.'));
    }
    function ruleGroup(parent, group, depth = 0) {
        const box = el('fieldset', {class:'flow-rule-group'}), legend = el('legend', {}, 'Match');
        legend.append(options({all:'All conditions',any:'Any condition'},group.match,value => edit(group,'match',value))); box.append(legend);
        group.rules.forEach((rule, index) => {
            const row = el('div', {class:'flow-rule-row'});
            if (rule.rules) ruleGroup(row,rule,depth + 1);
            else {
                const definition = config.catalog[rule.field] || config.catalog.email;
                if (definition.kind === 'choice') row.classList.add('flow-rule-choice');
                row.append(options(Object.fromEntries(Object.entries(config.catalog).map(([key,value]) => [key,value.label])),rule.field,value => { checkpoint(); rule.field = value; delete rule.campaign_id; delete rule.link_hash; delete rule.bots; rule.operator = Object.keys(config.operators[config.catalog[value].kind])[0]; rule.value = config.catalog[value].kind === 'membership' ? [] : ''; changed(); renderInspector(); renderSettings(); }));
                row.append(options(config.operators[definition.kind],rule.operator,value => { edit(rule,'operator',value); renderInspector(); renderSettings(); }));
                if (!['empty','not_empty','ever','never'].includes(rule.operator)) {
                    if (definition.options) row.append(options(definition.options,rule.value,value => edit(rule,'value',value),definition.kind === 'membership'));
                    else row.append(input(['last_days','not_last_days'].includes(rule.operator) || definition.kind === 'number' ? 'number' : definition.kind === 'date' || ['before','after'].includes(rule.operator) && ['event','engagement'].includes(definition.kind) ? 'date' : 'text',rule.value || '',value => edit(rule,'value',value)));
                }
                if (definition.kind === 'engagement') {
                    row.append(options({'0':'Any campaign',...definition.campaigns},rule.campaign_id || '0',value => edit(rule,'campaign_id',value)));
                    row.append(options({filtered:'Known bots excluded',all:'Include all observations'},rule.bots || 'filtered',value => edit(rule,'bots',value)));
                    if(rule.field === 'engagement:click') row.append(options({'':'Any tracked link',...definition.links},rule.link_hash || '',value => edit(rule,'link_hash',value)));
                }
            }
            row.append(button('×',() => { checkpoint(); group.rules.splice(index,1); changed(); renderInspector(); renderSettings(); },{'aria-label':'Remove condition',disabled:!config.editable})); box.append(row);
        });
        box.append(button('Add condition',() => { checkpoint(); group.rules.push({field:'email',operator:'contains',value:''}); changed(); renderInspector(); renderSettings(); },{disabled:!config.editable}));
        if (depth < 4) box.append(button('Add group',() => { checkpoint(); group.rules.push({match:'all',rules:[{field:'email',operator:'contains',value:''}]}); changed(); renderInspector(); renderSettings(); },{disabled:!config.editable}));
        parent.append(box);
    }
    function renderSettings() {
        const parent = $('[data-flow-settings]'); parent.replaceChildren(); const fieldset = el('fieldset', {disabled:!config.editable}), settingsFields = el('div', {class:'flow-settings-fields'}); parent.append(fieldset);
        fieldset.append(el('h2', {}, 'Flow settings'), settingsFields);
        field(settingsFields,'Flow name',input('text',name,value => { if (!config.editable) return; checkpoint(); name = value; changed(false); },{maxlength:100,required:true})).parentElement.classList.add('flow-setting-wide');
        field(settingsFields,'Allow connections to repeat the flow',input('checkbox',doc.settings.repeat,value => edit(doc.settings,'repeat',value))).parentElement.classList.add('flow-setting-wide');
        const timingSettings = el('div', {class:'flow-timing-settings'}); settingsFields.append(timingSettings);
        field(timingSettings,'Minimum days before reentry',input('number',doc.settings.cooldown,value => edit(doc.settings,'cooldown',value),{min:0,max:365}));
        field(timingSettings,'Exit after (days)',input('number',doc.settings.max_days,value => edit(doc.settings,'max_days',value),{min:1,max:365}));
        field(timingSettings,'Time zone',input('text',doc.settings.timezone,value => edit(doc.settings,'timezone',value),{list:'flow-timezones'}));
        if (!$('#flow-timezones')) { const list = el('datalist', {id:'flow-timezones'}); Intl.supportedValuesOf('timeZone').forEach(zone => list.append(el('option', {value:zone}))); document.body.append(list); }
        field(settingsFields,'Use each connection’s time zone when available',input('checkbox',doc.settings.recipient_timezone,value => edit(doc.settings,'recipient_timezone',value))).parentElement.classList.add('flow-setting-wide');
        const deliveryDays = el('fieldset', {class:'flow-weekdays'}), dayChoices = el('div', {class:'flow-weekday-options'}), dayPresets = el('div', {class:'flow-weekday-presets'}), presetChecks = [];
        const syncDeliveryDays = () => {
            const selectedDays = doc.settings.weekdays.map(Number);
            $$('input',dayChoices).forEach(control => { control.checked = selectedDays.includes(Number(control.value)); });
            presetChecks.forEach(({control,days}) => {
                const count = days.filter(day => selectedDays.includes(day)).length;
                control.checked = count === days.length;
                control.indeterminate = count > 0 && count < days.length;
            });
        };
        deliveryDays.append(el('legend', {}, 'Email delivery days'), dayPresets, dayChoices);
        for (const [label, days] of [['Weekdays',[1,2,3,4,5]],['Weekends',[6,7]],['All',[1,2,3,4,5,6,7]]]) {
            const choice = el('label'), control = input('checkbox',false,checked => {
                const selectedDays = new Set(doc.settings.weekdays.map(Number));
                days.forEach(day => { if (checked) selectedDays.add(day); else selectedDays.delete(day); });
                edit(doc.settings,'weekdays',Array.from(selectedDays).sort((a,b) => a-b));
                syncDeliveryDays();
            });
            presetChecks.push({control,days});
            choice.append(control,el('span',{},label)); dayPresets.append(choice);
        }
        for (const [day, label] of Object.entries({1:'Monday',2:'Tuesday',3:'Wednesday',4:'Thursday',5:'Friday',6:'Saturday',7:'Sunday'})) {
            const choice = el('label');
            choice.append(input('checkbox',false,() => { edit(doc.settings,'weekdays',$$('input:checked',dayChoices).map(control => Number(control.value))); syncDeliveryDays(); },{value:day}),el('span',{},label));
            dayChoices.append(choice);
        }
        syncDeliveryDays();
        settingsFields.append(deliveryDays);
        field(settingsFields,'Send after',input('time',doc.settings.after,value => edit(doc.settings,'after',value)));
        field(settingsFields,'Send before',input('time',doc.settings.before,value => edit(doc.settings,'before',value)));
        fieldset.append(el('h3', {}, 'Entry filters')); ruleGroup(fieldset,doc.entry);
        fieldset.append(el('h3', {}, 'Exit conditions')); fieldset.append(el('p', {class:'audience-help'}, 'No exit conditions means connections finish their path normally. Archived and removed connections always exit.')); ruleGroup(fieldset,doc.exit);
        parent.append(el('p', {class:'audience-help'}, 'Changes apply to new entries after activation. Connections already in progress keep their revision. Delays continue to count while paused.'));
    }
    function edgeChoices(currentId) {
        // Exclude ancestors so an inspector cannot create a loop accidentally.
        const ancestors = new Set([currentId]); let more = true;
        while (more) { more = false; doc.nodes.forEach(n => { if (!ancestors.has(n.id) && ['next','yes','no'].some(key => ancestors.has(n[key]))) { ancestors.add(n.id); more = true; } }); }
        return {'':'Exit flow', ...Object.fromEntries(doc.nodes.filter(n => !ancestors.has(n.id)).map(n => [n.id,n.label]))};
    }
    function renderInspector() {
        inspector.replaceChildren(); const node = doc.nodes.find(n => n.id === selected), form = el('fieldset', {disabled:!config.editable}); inspector.append(form);
        if (!node) {
            form.append(el('h2', {}, 'Starting point'));
            const triggers = el('div', {class:'flow-trigger-list'});
            [doc.trigger,...(doc.additional_triggers || [])].forEach((trigger,index) => {
                const headingId = `flow-trigger-heading-${index+1}`, group = el('div', {class:'flow-trigger-card',role:'group','aria-labelledby':headingId}), heading = el('div', {class:'flow-trigger-heading'}), fields = el('div', {class:'flow-trigger-fields'});
                heading.append(el('strong', {id:headingId}, `Trigger ${index+1}`));
                if(index)heading.append(button('Remove trigger',()=>{checkpoint();doc.additional_triggers.splice(index-1,1);changed();renderInspector();},{disabled:!config.editable}));
                triggerFields(fields,trigger); group.append(heading,fields); triggers.append(group);
            });
            form.append(triggers,el('p',{class:'audience-help'},'Any starting trigger can add a connection. Entry filters and repeat settings apply to every trigger.'));
            const footer = el('div', {class:'flow-trigger-footer'});
            field(footer,'First step',options({'':'Choose a step',...Object.fromEntries(doc.nodes.map(n => [n.id,n.label]))},doc.start,value => edit(doc,'start',value)));
            if((doc.additional_triggers || []).length<2)footer.append(button('Add starting trigger',()=>{checkpoint();doc.additional_triggers ||= [];doc.additional_triggers.push({type:Object.keys(config.triggers).find(type=>![doc.trigger,...doc.additional_triggers].some(t=>t.type===type)) || 'manual',id:0,field:'',value:'',offset:0,time:'09:00'});changed();renderInspector();},{disabled:!config.editable}));
            form.append(footer); return;
        }
        form.append(el('h2', {}, config.types[node.type])); field(form,'Step name',input('text',node.label,value => edit(node,'label',value),{maxlength:100}));
        if (node.type === 'email') {
            field(form,'Email',options(campaignChoices(),node.campaign_id,value => { edit(node,'campaign_id',Number(value)); renderInspector(); }));
            if (node.campaign_id) { const actions = el('div', {class:'audience-actions'}); actions.append(el('a', {href:`audience.php?view=campaign&id=${node.campaign_id}`,target:'_blank',rel:'noopener'},'Edit email settings'),el('a',{href:`audience.php?view=designer&kind=campaign&id=${node.campaign_id}`,target:'_blank',rel:'noopener'},'Design email')); form.append(actions); }
            form.append(el('a',{href:'audience.php?view=campaigns&create=1',target:'_blank',rel:'noopener'},'Create an email')); form.append(el('p',{class:'audience-help'},'The activated flow keeps a copy of the email design, sender, subject and tracking settings.'));
        }
        if (node.type === 'delay') field(form,'Delay in minutes',input('number',(node.seconds || 0) / 60,value => edit(node,'seconds',Math.round(value * 60)),{min:0,max:527040}));
        if (node.type === 'until') field(form,'Wait until local time',input('time',node.time || '09:00',value => edit(node,'time',value)));
        if (node.type === 'wait') { if (!node.trigger) node.trigger = {type:'click',id:0}; triggerFields(form,node.trigger,true); field(form,'Timeout in minutes',input('number',(node.timeout || 86400) / 60,value => edit(node,'timeout',Math.round(value * 60)),{min:1,max:527040})); }
        if (node.type === 'split') form.append(el('p',{class:'audience-help'},'This legacy percentage step cannot be activated. Replace it with an If/Else condition.'));
        if (node.type === 'condition') ruleGroup(form,node.rules);
        if (['tag','group'].includes(node.type)) { field(form,'Action',options({add:'Add',remove:'Remove'},node.remove?'remove':'add',value => edit(node,'remove',value==='remove'))); field(form,node.type==='tag'?'Tag':'Group',options(node.type==='tag'?tagChoices():groupChoices(),node.type==='tag'?node.tag_id:node.option_id,value => edit(node,node.type==='tag'?'tag_id':'option_id',Number(value)))); }
        if (node.type === 'update') {
            field(form,'Audience field',options({first_name:'First name',last_name:'Last name',language:'Language',timezone:'Time zone',...Object.fromEntries(config.fields.map(f => [f.id,f.label]))},node.field || 'first_name',value => edit(node,'field',value)));
            field(form,'New value',input('text',node.value || '',value => edit(node,'value',value),{maxlength:1000}));
        }
        if (['unsubscribe','archive'].includes(node.type)) form.append(el('p',{class:'audience-help'},node.type==='unsubscribe'?'This step removes email permission. A flow cannot subscribe or resubscribe a connection.':'This step archives the connection and ends their participation.'));
        const edges = ['condition','split','wait'].includes(node.type) ? ['yes','no'] : node.type === 'exit' ? [] : ['next'];
        edges.forEach(edge => field(form,edge==='next'?'Next step':edge==='yes'?'Yes path':'No path',options(edgeChoices(node.id),node[edge],value => edit(node,edge,value))));
        const actions = el('div',{class:'audience-actions'});
        actions.append(button('Duplicate',() => { if(doc.nodes.length>=200){showError('Use at most 200 steps in a flow.');return;} checkpoint(); const copy=clone(node); copy.id=newId(); copy.label+= ' (copy)'; copy.next=''; copy.yes=''; copy.no=''; doc.nodes.push(copy); selected=copy.id; changed(); renderInspector(); },{disabled:!config.editable}),button('Delete step',() => { if (!config.editable) return; checkpoint(); const replacement=['condition','split','wait'].includes(node.type)?'':node.next; doc.nodes.forEach(n => ['next','yes','no'].forEach(edge => { if(n[edge]===node.id)n[edge]=replacement; })); if(doc.start===node.id)doc.start=replacement; doc.nodes=doc.nodes.filter(n=>n.id!==node.id); selected='trigger'; changed(); renderInspector(); },{disabled:!config.editable})); form.append(actions);
        if(config.canManage){const paused=config.pausedNodes.includes(node.id);inspector.append(button(paused?'Resume step':'Pause step',async()=>{if(!await save())return;try{const data=await post({action:'flow_operation',version,operation:paused?'resume_node':'pause_node',node:node.id});window.location.assign(data.redirect);}catch(e){showError(e.message);}}));}
    }
    const newId = () => 'step' + crypto.randomUUID().replaceAll('-','').slice(0,20);
    let insertion = null;
    function add(type, at = insertion) {
        if(type==='split')return;
        if (!config.editable) return; if(doc.nodes.length>=200){showError('Use at most 200 steps in a flow.');return;} checkpoint(); const id=newId(), node={id,type,label:config.types[type],next:'',yes:'',no:'',field:type==='update'?'first_name':'',value:'',time:'09:00',seconds:86400,timeout:86400,percent:50,tag_id:0,option_id:0,campaign_id:0,remove:false,rules:{match:'all',rules:[]}};
        if(type==='wait')node.trigger={type:'click',id:0};
        if(!at&&selected!=='trigger'){const current=doc.nodes.find(n=>n.id===selected);if(current&&!['condition','split','wait','exit'].includes(current.type))at={id:current.id,edge:'next'};}
        if(at?.id==='trigger'){node.next=doc.start;doc.start=id;}
        else if(at){const parent=doc.nodes.find(n=>n.id===at.id);node.next=parent[at.edge]||'';parent[at.edge]=id;}
        else if(!doc.start)doc.start=id;
        doc.nodes.push(node);selected=id;insertion=null;changed();renderInspector();
    }
    function renderMap() {
        const nodes=doc.nodes, triggers=[doc.trigger,...(doc.additional_triggers || [])], rank=new Map(), incoming=new Map(nodes.map(n=>[n.id,0])), byId=new Map(nodes.map(n=>[n.id,n]));
        if(selected!=='trigger'&&!byId.has(selected))selected='trigger';
        const edges=n=>['condition','split','wait'].includes(n.type)?['yes','no']:n.type==='exit'?[]:['next'];
        nodes.forEach(n=>edges(n).forEach(e=>{if(n[e]&&incoming.has(n[e]))incoming.set(n[e],incoming.get(n[e])+1);}));
        const queue=nodes.filter(n=>!incoming.get(n.id)); queue.forEach(n=>rank.set(n.id,1));
        while(queue.length){const n=queue.shift();edges(n).forEach(e=>{if(!byId.has(n[e]))return;rank.set(n[e],Math.max(rank.get(n[e])||1,rank.get(n.id)+1));incoming.set(n[e],incoming.get(n[e])-1);if(incoming.get(n[e])===0)queue.push(byId.get(n[e]));});}
        const rows=new Map();nodes.forEach(n=>{const r=rank.get(n.id)||1;if(!rows.has(r))rows.set(r,[]);rows.get(r).push(n);});
        const width=Math.max(850,triggers.length*320+100,...Array.from(rows.values(),r=>r.length*320+100)), height=(Math.max(0,...rank.values())+2)*170;
        map.replaceChildren();map.style.width=`${width}px`;map.style.height=`${height}px`;map.style.zoom=zoom;
        const positions=new Map(triggers.map((_,i)=>[`__trigger-${i}`,{x:(width-triggers.length*320)/2+i*320+30,y:30}]));
        rows.forEach((row,r)=>row.forEach((n,i)=>positions.set(n.id,{x:(width-row.length*320)/2+i*320+30,y:r*170+30})));
        const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('width',width);svg.setAttribute('height',height);svg.setAttribute('aria-hidden','true');svg.classList.add('flow-connectors');map.append(svg);
        const connect=(from,to,edge)=>{
            const a=positions.get(from),b=positions.get(to);if(!a)return;const start={x:a.x+130,y:a.y+110},end=b?{x:b.x+130,y:b.y}:{x:a.x+130+(edge==='yes'?-75:edge==='no'?75:0),y:a.y+155};
            const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d',`M${start.x},${start.y} V${start.y+30} H${end.x} V${end.y}`);svg.append(path);
            const origin=from.startsWith('__trigger-')?'trigger':from;
            const plus=button('+',()=>{insertion={id:origin,edge};selected=origin;renderMap();renderInspector();$('.flow-step-palette button:not(:disabled)')?.focus();},{class:'flow-insert','aria-label':`Add a step ${origin==='trigger'?'after trigger':`on ${edge} path`}`,disabled:!config.editable});plus.style.left=`${end.x-14}px`;plus.style.top=`${end.y-42}px`;
            plus.addEventListener('dragover',e=>{if(config.editable)e.preventDefault();});plus.addEventListener('drop',e=>{e.preventDefault();const type=e.dataTransfer.getData('text/plain');if(config.types[type]&&type!=='split')add(type,{id:origin,edge});});map.append(plus);
            if(edge==='yes'||edge==='no'){const label=el('span',{class:'flow-branch-label'},edge==='yes'?'Yes':'No');label.style.left=`${end.x+20}px`;label.style.top=`${end.y-37}px`;map.append(label);}
        };
        triggers.forEach((_,i)=>connect(`__trigger-${i}`,doc.start,'next'));nodes.forEach(n=>edges(n).forEach(edge=>connect(n.id,n[edge],edge)));
        const card=(id,title,detail,type,kind)=>{
            const identity=id.startsWith('__trigger-')?'trigger':id;
            const active=selected===identity;
            const p=positions.get(id), item=button('',()=>{selected=identity;renderMap();renderInspector();},{class:`flow-node kind-${kind}${active?' selected':''}${active&&config.editable?' editing':''}${config.pausedNodes.includes(id)?' paused':''}`,'aria-pressed':active});
            item.style.left=`${p.x}px`;item.style.top=`${p.y}px`;item.title=title+' · '+detail;item.append(icon(kind),el('span',{class:'flow-node-type'},type),el('strong',{},title),el('span',{class:'flow-node-detail'},detail));
            if(config.pausedNodes.includes(id))item.append(el('span',{class:'flow-node-paused'},'Paused'));
            const counts=config.counts.filter(c=>c.node_key===id);if(counts.length)item.append(el('small',{},counts.map(c=>`${c.n} ${c.state}`).join(' · ')));map.append(item);
        };
        triggers.forEach((t,i)=>card(`__trigger-${i}`,t.type==='status'&&t.value?`Changes to ${t.value}`:config.triggers[t.type] || 'Choose a trigger','Entry filters and repeat rules apply','Starting point','trigger'));
        nodes.forEach(n=>{const detail=n.type==='email'?config.campaigns.find(c=>Number(c.id)===Number(n.campaign_id))?.name||'Choose an email':n.type==='delay'?`${Math.round(n.seconds/60)} ${Math.round(n.seconds/60)===1?'minute':'minutes'}`:n.type==='split'?`${n.percent}% / ${100-n.percent}%`:n.type==='until'?n.time:config.types[n.type];card(n.id,n.label,detail,config.types[n.type],n.type);});
        $('[data-flow-zoom-label]').textContent=`${Math.round(zoom*100)}%`;
    }
    $$('[data-flow-add]').forEach(b=>{b.addEventListener('click',()=>add(b.dataset.flowAdd));b.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/plain',b.dataset.flowAdd);});});
    $$('[data-flow-panel]').forEach(b=>b.addEventListener('click',()=>{$$('[data-flow-panel]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));$$('[data-flow-panel-body]').forEach(x=>{x.hidden=x.dataset.flowPanelBody!==b.dataset.flowPanel;});}));
    $$('[data-flow-zoom]').forEach(b=>b.addEventListener('click',()=>{zoom=b.dataset.flowZoom==='reset'?1:Math.max(.35,Math.min(1.5,zoom+(b.dataset.flowZoom==='in'?.1:-.1)));renderMap();if(b.dataset.flowZoom==='reset')centerMap();}));
    saveButton?.addEventListener('click',saveWithFeedback);
    $('[data-flow-review]')?.addEventListener('click',async()=>{if(await save()){const dialog=$('#flow-turn-on');dialog.showModal();$('button',dialog)?.focus();}});
    $$('form[data-flow-needs-save]').forEach(form=>form.addEventListener('submit',async event=>{if(!dirty)return;event.preventDefault();if(await save())form.requestSubmit();}));
    const restoreHistory = backwards => { if(!config.editable)return; const source=backwards?undo:redo,dest=backwards?redo:undo;if(!source.length)return;dest.push(clone({doc,name}));const previous=source.pop();doc=previous.doc;name=previous.name;changed();renderInspector();renderSettings(); };
    $('[data-flow-undo]')?.addEventListener('click',()=>restoreHistory(true));$('[data-flow-redo]')?.addEventListener('click',()=>restoreHistory(false));
    document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();saveWithFeedback();}});
    window.addEventListener('beforeunload',event=>{if(dirty||saving){remember();event.preventDefault();event.returnValue='';}});
    try{const recovered=JSON.parse(localStorage.getItem(storageKey)||'null');if(recovered&&config.editable){const banner=el('div',{class:'audience-notice flow-recovery'},'A recovered flow draft is available ');banner.append(button('Recover draft',()=>{checkpoint();doc=recovered.doc;name=recovered.name;changed();renderInspector();renderSettings();banner.remove();}),button('Discard',()=>{localStorage.removeItem(storageKey);banner.remove();}));if(recovered.version!==version)banner.prepend(el('strong',{},'The saved flow changed since this draft was kept. Review differences before saving. '));$('.flow-editor').prepend(banner);}}catch(_){/* Storage is optional. */}
    const scroll = $('.flow-map-scroll'), plane = el('div', {class:'flow-map-plane'});
    map.before(plane); plane.append(map);
    let pan = null, paddingX = 0, paddingY = 0;
    const sizeCanvas = () => {
        const width = scroll.clientWidth, height = scroll.clientHeight;
        if (!width || !height || (width === paddingX && height === paddingY)) return;
        const left = scroll.scrollLeft + width - paddingX, top = scroll.scrollTop + height - paddingY;
        paddingX = width; paddingY = height;
        // Keep room on every side so even a flow that fits can be panned.
        plane.style.padding = `${paddingY}px ${paddingX}px`;
        scroll.scrollTo({left,top,behavior:'instant'});
    };
    function centerMap() {
        sizeCanvas();
        scroll.scrollTo({left:paddingX + (map.getBoundingClientRect().width - scroll.clientWidth) / 2,top:paddingY,behavior:'instant'});
    }
    scroll.addEventListener('pointerdown',event => {
        if (!event.isPrimary || event.button !== 0 || event.target.closest('button,a,input,select,textarea')) return;
        event.preventDefault();
        pan = {pointerId:event.pointerId,x:event.clientX,y:event.clientY,left:scroll.scrollLeft,top:scroll.scrollTop};
        scroll.classList.add('is-panning'); scroll.setPointerCapture(event.pointerId);
    });
    scroll.addEventListener('pointermove',event => {
        if (!pan || event.pointerId !== pan.pointerId) return;
        scroll.scrollLeft = pan.left - (event.clientX - pan.x); scroll.scrollTop = pan.top - (event.clientY - pan.y);
    });
    const stopPan = event => {
        if (!pan || event.pointerId !== pan.pointerId) return;
        pan = null; scroll.classList.remove('is-panning');
        if (scroll.hasPointerCapture(event.pointerId)) scroll.releasePointerCapture(event.pointerId);
    };
    ['pointerup','pointercancel','lostpointercapture'].forEach(type => scroll.addEventListener(type,stopPan));
    renderSettings(); renderMap(); renderInspector(); centerMap();
    new ResizeObserver(sizeCanvas).observe(scroll);
})();
