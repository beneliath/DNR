(() => {
    'use strict';
    const create = document.getElementById('collection-create');
    const defaultKinds = create ? Array.from(create.querySelectorAll('[data-collection-kind]')).filter(label => !label.hidden).map(label => label.dataset.collectionKind) : [];
    document.querySelectorAll('[data-open-dialog="collection-create"]').forEach(button => button.addEventListener('click', () => {
        create?.querySelectorAll('[data-collection-kind]').forEach(label => { label.hidden = !defaultKinds.includes(label.dataset.collectionKind); });
        const radio = create?.querySelector(`[data-collection-kind="${defaultKinds[0]}"] input`); if (radio) radio.checked = true;
    }));
    document.querySelectorAll('[data-collection-create]').forEach(button => button.addEventListener('click', () => {
        const radio = create?.querySelector(`input[name="kind"][value="${button.dataset.collectionCreate}"]`);
        create?.querySelectorAll('[data-collection-kind]').forEach(label => { label.hidden = label.dataset.collectionKind !== button.dataset.collectionCreate; });
        if (radio) radio.checked = true;
        create?.showModal(); create?.querySelector('input[name="name"]')?.focus();
    }));
    document.querySelector('[data-collection-print]')?.addEventListener('click', () => window.print());
    const form = document.getElementById('collection-editor');
    const configNode = document.getElementById('collection-config');
    if (!form || !configNode) return;
    const config = JSON.parse(configNode.textContent);
    let model = structuredClone(config.document);
    const frame = document.querySelector('[data-collection-preview]');
    const status = document.querySelector('[data-collection-preview-status]');
    const hidden = form.querySelector('[data-collection-document]');
    let timer, request, sequence = 0;
    const readLines = value => value.split('\n').map(s => s.trim()).filter(Boolean);
    const sync = () => {
        form.querySelectorAll('[data-doc]').forEach(input => { model[input.dataset.doc] = input.type === 'number' ? Number(input.value) : input.value; });
        form.querySelectorAll('[data-doc-lines]').forEach(input => { model[input.dataset.docLines] = readLines(input.value); });
        model.messages ||= {};
        form.querySelectorAll('[data-doc-message]').forEach(input => { model.messages[input.dataset.docMessage] = input.value; });
        model.advanced ||= {enabled:false,pages:{}};
        model.advanced.enabled = Boolean(form.querySelector('[data-advanced-enabled]')?.checked);
        form.querySelectorAll('[data-advanced-page]').forEach(input => { model.advanced.pages[input.dataset.advancedPage] = input.value; });
        model.labels ||= {};
        form.querySelectorAll('[data-doc-label]').forEach(input => { model.labels[input.dataset.docLabel] = input.value; });
        model.system ||= {};
        form.querySelectorAll('[data-doc-system]').forEach(input => { model.system[input.dataset.docSystem] = input.value; });
        const arrayKeys = new Set(Array.from(form.querySelectorAll('[data-doc-array]'), el => el.dataset.docArray));
        arrayKeys.forEach(key => { model[key] = Array.from(form.querySelectorAll(`[data-doc-array="${key}"]:checked`), el => Number(el.value)); });
        const fields = Array.from(form.querySelectorAll('[data-field]'));
        if (fields.length) {
            model.fields = fields.filter(row => row.querySelector('[data-field-visible]').checked).map(row => row.dataset.field);
            model.required = fields.filter(row => row.querySelector('[data-field-required]').checked).map(row => row.dataset.field);
        }
        form.querySelectorAll('[data-popup]').forEach(input => { model.popup[input.dataset.popup] = input.type === 'number' ? Number(input.value) : input.value; });
        form.querySelectorAll('[data-popup-lines]').forEach(input => { model.popup[input.dataset.popupLines] = readLines(input.value); });
        const countries = form.querySelector('[data-popup-countries]'); if (countries) model.popup.countries = Array.from(countries.selectedOptions, option => option.value);
        hidden.value = JSON.stringify(model);
    };
    const preview = async () => {
        if (config.previewOnly) return;
        sync(); request?.abort(); request = new AbortController(); const current = ++sequence;
        const data = new FormData(form); data.set('action', 'collection_preview');data.set('screen', document.querySelector('[data-preview-screen]')?.value || 'signup');
        status.textContent = 'Updating…';
        try {
            const response = await fetch(location.href, {method:'POST', body:data, signal:request.signal, credentials:'same-origin', headers:{Accept:'application/json'}});
            if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign in to MessageMonkey in another tab, then try again. Your draft is still open here.');
            const result = await response.json();
            if (current !== sequence) return;
            if (!response.ok || result.error) throw new Error(result.error || 'Preview unavailable.');
            frame.srcdoc = result.html; status.textContent = '';
        } catch (error) { if (error.name !== 'AbortError' && current === sequence) status.textContent = error.message || 'Preview unavailable. Save your work and try again.'; }
    };
    const fit = () => { try { if (frame.contentDocument?.body) { frame.style.height = '1px'; const root = frame.contentDocument.documentElement; frame.style.height = Math.max(320, Math.ceil(Math.max(root.scrollHeight, root.getBoundingClientRect().height)) + 2) + 'px'; } } catch (_) {} };
    frame.addEventListener('load', () => { fit(); frame.contentDocument?.querySelectorAll('img').forEach(img => img.addEventListener('load', fit, {once:true})); frame.contentDocument?.fonts?.ready.then(fit); });
    let frameWidth = frame.getBoundingClientRect().width;
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => {
        const width = frame.getBoundingClientRect().width;
        if (width !== frameWidth) { frameWidth = width; requestAnimationFrame(fit); }
    }).observe(frame);
    window.addEventListener('resize', fit);
    fit();
    const changed = () => { sync(); clearTimeout(timer); timer = setTimeout(preview, 300); queueMicrotask(record); };
    form.addEventListener('input', changed); form.addEventListener('change', changed);
    form.addEventListener('submit', () => { clearTimeout(timer); sync(); });
    let selectedTab = config.kind === 'survey' ? 'questions' : 'design';
    const selectTab = tab => {
        selectedTab = tab;
        if(config.kind === 'survey'){const title=document.querySelector('.audience-page-heading h1');if(title)title.textContent=({questions:'Add questions',design:'Design survey',setup:'Survey details',publish:'Share your survey',translate:'Translate it'})[tab]||'Survey';const next=document.querySelector('[data-survey-next]'), publish=document.querySelector('[data-survey-publish]');if(next){next.hidden=tab==='publish';next.textContent=tab==='questions'?'Continue to design':tab==='design'?'Continue to details':'Continue to share';}if(publish)publish.hidden=tab!=='publish';}
        const railTab = tab;
        document.querySelector('[data-collection-builder]').classList.toggle('survey-questions-active', config.kind === 'survey' && tab === 'questions');
        if (tab === 'outline') tab = 'design';
        form.querySelectorAll('[data-collection-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.collectionTab === tab)));
        form.querySelectorAll('[data-collection-panel]').forEach(panel => { panel.hidden = panel.dataset.collectionPanel !== tab; });
        document.querySelectorAll('[data-collection-rail]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.collectionRail === railTab)));
    };
    document.querySelector('[data-survey-next]')?.addEventListener('click',()=>selectTab(selectedTab==='questions'?'design':selectedTab==='design'?'setup':'publish'));
    form.querySelectorAll('[data-collection-tab]').forEach(button => button.addEventListener('click', () => selectTab(button.dataset.collectionTab)));
    document.querySelectorAll('[data-collection-rail]').forEach(button => button.addEventListener('click', () => {
        selectTab(button.dataset.collectionRail);
        if (button.dataset.collectionRail === 'outline') form.querySelector('[data-collection-fields]')?.scrollIntoView({block:'center'});
    }));
    document.querySelector('[data-collection-focus-preview]')?.addEventListener('click', event => {
        const hidden = form.classList.toggle('preview-hidden');
        frame.closest('.collection-preview').classList.toggle('preview-focus', hidden);
        event.currentTarget.textContent = hidden ? 'Back to editing' : 'Preview';
    });
    document.querySelectorAll('[data-collection-device]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-collection-device]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
        frame.classList.toggle('mobile', button.dataset.collectionDevice === 'mobile');
        requestAnimationFrame(fit);
    }));
    form.querySelectorAll('[data-field-move]').forEach(button => button.addEventListener('click', () => {
        const row = button.closest('[data-field]');
        if (button.dataset.fieldMove === 'up' && row.previousElementSibling) row.previousElementSibling.before(row);
        if (button.dataset.fieldMove === 'down' && row.nextElementSibling) row.nextElementSibling.after(row);
        changed(); button.focus();
    }));
    form.querySelectorAll('[data-field-required]').forEach(box => box.addEventListener('change', () => { if (box.checked) box.closest('[data-field]').querySelector('[data-field-visible]').checked = true; }));
    const el = (tag, text, className) => { const node = document.createElement(tag); if (['form', 'input', 'textarea'].includes(tag)) node.setAttribute('autocomplete', 'off'); if (text) node.textContent = text; if (className) node.className = className; return node; };
    const control = (card, label, value, change, type = 'text', choices = []) => {
        const wrap = el('label', label); const input = el(type === 'select' ? 'select' : type === 'textarea' ? 'textarea' : 'input');
        if (type === 'select') choices.forEach(([key, title]) => { const option = el('option', title); option.value = key; input.append(option); });
        else if (type !== 'textarea') input.type = type;
        if (type === 'checkbox') input.checked = Boolean(value); else input.value = String(value ?? '');
        input.addEventListener(type === 'select' || type === 'checkbox' ? 'change' : 'input', () => { change(type === 'checkbox' ? input.checked : input.value); changed(); });
        wrap.append(input); card.append(wrap); return input;
    };
    const lists = {
        questions: form.querySelector('[data-question-list]'), sections: form.querySelector('[data-section-list]'),
        variants: form.querySelector('[data-variant-list]'), quiz: form.querySelector('[data-quiz-list]')
    };
    const api = async (action, values = {}, file = null) => {
        const data = new FormData(); data.set('action', action); data.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
        Object.entries(values).forEach(([key, value]) => data.set(key, String(value)));
        if (file) data.set('image', file);
        const response = await fetch(location.href, {method:'POST', body:data, credentials:'same-origin', headers:{Accept:'application/json'}});
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign in to MessageMonkey in another tab, then try again. Your draft is still open here.');
        const result = await response.json(); if (!response.ok || result.error) throw new Error(result.error || 'Unable to load the image.'); return result;
    };
    const imageDialog = document.getElementById('collection-images'); let chooseImage = null; let imagePage = 1;
    const loadImages = async () => {
        const feedback = imageDialog.querySelector('[data-collection-image-status]'); feedback.textContent = 'Loading…';
        try {
            const result = await api('collection_library', {search:imageDialog.querySelector('[data-collection-image-search]').value, page:imagePage});
            const list = imageDialog.querySelector('[data-collection-image-list]'); list.replaceChildren();
            result.items.forEach(item => { const button = el('button', item.name); button.type = 'button'; button.addEventListener('click', async () => {
                try { const image = await api('collection_library_image', {image_id:item.id}); chooseImage?.(image.image); imageDialog.close(); } catch (error) { feedback.textContent = error.message; }
            }); list.append(button); }); feedback.textContent = result.items.length ? '' : 'No images found.';
            imageDialog.querySelector('[data-collection-image-prev]').disabled = imagePage <= 1;
            imageDialog.querySelector('[data-collection-image-next]').disabled = imagePage * 20 >= result.total;
        } catch (error) { feedback.textContent = error.message || 'Images are unavailable.'; }
    };
    imageDialog.querySelector('[data-collection-image-find]').addEventListener('click', () => { imagePage = 1; loadImages(); });
    imageDialog.querySelector('[data-collection-image-prev]').addEventListener('click', () => { imagePage = Math.max(1, imagePage - 1); loadImages(); });
    imageDialog.querySelector('[data-collection-image-next]').addEventListener('click', () => { imagePage++; loadImages(); });
    const imageButtons = (card, apply) => {
        const row = el('div', '', 'collection-image-buttons'); const browse = el('button', 'Browse images'); browse.type = 'button';
        browse.addEventListener('click', () => { chooseImage = apply; imagePage = 1; imageDialog.showModal(); loadImages(); });
        const uploadLabel = el('label', 'Upload'); const upload = el('input'); upload.type = 'file'; upload.accept = 'image/png,image/jpeg,image/webp';
        upload.addEventListener('change', async () => {
            if (!upload.files?.[0]) return; status.textContent = 'Uploading…';
            try { const result = await api('collection_image', {}, upload.files[0]); apply(result.image); status.textContent = ''; } catch (error) { status.textContent = error.message || 'Unable to upload this image.'; }
        }); uploadLabel.append(upload); row.append(browse, uploadLabel); card.append(row);
    };
    const renderList = key => {
        const list = lists[key]; if (!list) return; list.replaceChildren();
        model[key].forEach((item, index) => {
            const card = el('div', '', 'collection-item'); const head = el('div', '', 'collection-list-head');
            card.draggable = true;
            card.addEventListener('dragstart', event => { event.dataTransfer.setData('text/plain', key + ':' + index); event.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragover', event => { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; });
            card.addEventListener('drop', event => { event.preventDefault(); const [source, from] = event.dataTransfer.getData('text/plain').split(':'); const n = Number(from); if (source !== key || !Number.isInteger(n) || n < 0 || n >= model[key].length) return; const [item] = model[key].splice(n, 1); model[key].splice(index, 0, item); renderList(key); changed(); });
            head.append(el('strong', `${key === 'questions' ? 'Question' : key === 'sections' ? 'Section' : key === 'variants' ? 'Variation' : 'Quiz choice'} ${index + 1}`));
            const move = delta => { const to = index + delta; if (to < 0 || to >= model[key].length) return; [model[key][index], model[key][to]] = [model[key][to], model[key][index]]; renderList(key); changed(); };
            [['↑', () => move(-1), 'Move up'], ['↓', () => move(1), 'Move down'], ['×', () => { model[key].splice(index, 1); renderList(key); changed(); }, 'Remove']].forEach(([text, action, label]) => {
                const button = el('button', text); button.type = 'button'; button.setAttribute('aria-label', `${label} ${index + 1}`); button.addEventListener('click', action); head.append(button);
            }); card.append(head);
            if (key === 'questions') {
                card.classList.add('collection-question');
                const main = el('div', '', 'collection-question-main'), inspector = el('aside', '', 'collection-question-settings');card.append(main, inspector);
                control(main, 'What would you like to ask?', item.label, value => { item.label = value; }).required = true;
                control(inspector, 'Section type', item.type, value => { item.type = value; renderList(key); }, 'select', [['radio','Radio buttons'],['checkboxes','Checkboxes'],['text','Open text'],['range','Range (1–10)'],['dropdown','Dropdown'],['email','Email'],['contact','Contact information'],['content','Content block']]);
                control(inspector, 'Required', item.required, value => { item.required = value; }, 'checkbox');
                if (['radio','checkboxes','dropdown'].includes(item.type)) {
                    control(inspector, 'Allow an “Other” answer', item.other, value => { item.other = value; }, 'checkbox');
                    const options = el('div', '', 'collection-answer-options');main.append(options);
                    item.options.forEach((option, optionIndex) => {
                        const row = el('div', '', 'collection-answer-option');options.append(row);
                        const input = control(row, 'Option value', option, value => { const previous=item.options[optionIndex];item.options[optionIndex]=value;if(item.answer_tags?.[previous]){item.answer_tags[value]=item.answer_tags[previous];delete item.answer_tags[previous];} });
                        const add = () => { if(item.options.length>=30)return;item.options.splice(optionIndex+1,0,'New option');renderList(key);changed();lists.questions.children[index].querySelectorAll('.collection-answer-option input')[optionIndex+1]?.focus(); };
                        const remove = () => { if(item.options.length<=2)return;delete item.answer_tags?.[item.options[optionIndex]];item.options.splice(optionIndex,1);renderList(key);changed(); };
                        for(const [text,label,action] of [['−','Remove question option',remove],['＋','Add question option',add]]){const button=el('button',text);button.type='button';button.setAttribute('aria-label',label);button.addEventListener('click',action);row.append(button);}
                        input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();add();}else if(event.key==='Backspace'&&!input.value&&item.options.length>2){event.preventDefault();remove();}});
                    });
                    main.append(el('p','Tip: Add or remove options using Enter and Backspace.','audience-help'));
                    if(config.tags?.length){const tagging=el('details');tagging.append(el('summary','Manage tagging'));inspector.append(tagging);item.options.forEach(option=>{const label=el('label',option), select=el('select');select.multiple=true;select.setAttribute('aria-label',`Tags for ${option}`);config.tags.forEach(tag=>{const choice=el('option',tag.name);choice.value=String(tag.id);choice.selected=(item.answer_tags?.[option]||[]).includes(Number(tag.id));select.append(choice);});select.addEventListener('change',()=>{item.answer_tags||={};item.answer_tags[option]=[...select.selectedOptions].map(o=>Number(o.value));changed();});label.append(select);tagging.append(label);});tagging.append(el('p','Tags apply only to verified recipients using their private email link.','audience-help'));}
                }
                const duplicate=el('button','Duplicate question');duplicate.type='button';duplicate.addEventListener('click',()=>{if(model.questions.length>=30)return;const copy=structuredClone(item);copy.id='q'+crypto.randomUUID().replaceAll('-','').slice(0,20);model.questions.splice(index+1,0,copy);renderList(key);changed();});inspector.append(duplicate);
                const remove=el('button','Delete question','danger-text');remove.type='button';remove.addEventListener('click',()=>{model.questions.splice(index,1);renderList(key);changed();});inspector.append(remove);
            } else if (key === 'sections') {
                control(card, 'Section type', item.type, value => { item.type = value; renderList(key); }, 'select', [['heading','Heading'],['text','Text'],['image','Image'],['button','Button'],['divider','Divider']]);
                if (item.type !== 'divider') control(card, item.type === 'image' ? 'Alternative text' : 'Text', item.text, value => { item.text = value; }, 'textarea');
                if (['image','button'].includes(item.type)) {
                    const urlInput = control(card, 'HTTPS address', item.url, value => { item.url = value; }, 'url');
                    if (item.type === 'image') imageButtons(card, value => { item.url = value; urlInput.value = value; changed(); });
                }
            } else if (key === 'variants') {
                ['title','intro','button'].forEach(prop => control(card, prop === 'title' ? 'Heading' : prop === 'intro' ? 'Introduction' : 'Button label', item[prop], value => { item[prop] = value; }));
            } else {
                control(card, 'Choice', item.label, value => { item.label = value; });
                control(card, 'Outcome message', item.outcome, value => { item.outcome = value; }, 'textarea');
            }
            list.append(card);
        });
    };
    Object.keys(lists).forEach(renderList);
    const logoInput = form.querySelector('[data-doc="logo"]'); if (logoInput) imageButtons(logoInput.closest('details'), value => { logoInput.value = value; model.logo = value; changed(); });
    form.querySelector('[data-add-question]')?.addEventListener('click', () => { if (model.questions.length >= 30) return; model.questions.push({id:'q'+Date.now().toString(36),type:'radio',label:'New question',required:false,options:['Option 1','Option 2']}); renderList('questions'); changed(); });
    form.querySelector('[data-add-section]')?.addEventListener('click', () => { if (model.sections.length >= 30) return; model.sections.push({type:'text',text:'Add your message here.',url:''}); renderList('sections'); changed(); });
    form.querySelector('[data-add-variant]')?.addEventListener('click', () => { if (model.variants.length >= 2) return; model.variants.push({title:model.title,intro:model.intro,button:model.button}); renderList('variants'); changed(); });
    form.querySelector('[data-add-quiz]')?.addEventListener('click', () => { if (model.quiz.length >= 10) return; model.quiz.push({label:'New choice',outcome:'Thank you for sharing your interest.'}); renderList('quiz'); changed(); });
    form.querySelectorAll('[data-add-field]').forEach(button => button.addEventListener('click', () => {
        const row = form.querySelector(`[data-field="${button.dataset.addField}"]`); if (!row) return;
        row.querySelector('[data-field-visible]').checked = true; selectTab('design'); row.scrollIntoView({block:'center'}); changed();
    }));
    form.querySelectorAll('[data-add-layout]').forEach(button => button.addEventListener('click', () => {
        if (model.sections.length >= 30) return;
        model.sections.push({type:button.dataset.addLayout,text:button.dataset.addLayout === 'divider' ? '' : 'Add your message here.',url:''});
        renderList('sections'); selectTab('design'); const list = lists.sections; if (list) { list.closest('details').open = true; list.lastElementChild?.scrollIntoView({block:'center'}); } changed();
    }));
    form.querySelector('[data-add-survey-question]')?.addEventListener('click', () => { form.querySelector('[data-add-question]')?.click(); selectTab('design'); });
    document.querySelector('[data-preview-screen]')?.addEventListener('change', preview);
    selectTab(config.kind === 'survey' ? 'questions' : 'design');
    sync();
    const history = [JSON.stringify(model)]; let historyIndex = 0; let restoring = false;
    const undo = document.querySelector('[data-collection-undo]'); const redo = document.querySelector('[data-collection-redo]');
    const record = () => {
        if (restoring) return; sync(); const value = JSON.stringify(model);
        if (history[historyIndex] !== value) { history.splice(historyIndex + 1); history.push(value); while (history.length > 1 && (history.length > 30 || history.reduce((total, item) => total + item.length, 0) > 8000000)) history.shift(); historyIndex = history.length - 1; }
        if (undo) undo.disabled = historyIndex <= 0; if (redo) redo.disabled = historyIndex >= history.length - 1;
    };
    form.addEventListener('input', record); form.addEventListener('change', record);
    const restore = index => {
        if (index < 0 || index >= history.length) return; restoring = true; historyIndex = index; model = JSON.parse(history[index]);
        form.querySelectorAll('[data-doc]').forEach(input => { input.value = model[input.dataset.doc]; });
        form.querySelectorAll('[data-doc-label]').forEach(input => { input.value = model.labels[input.dataset.docLabel] || input.value; });
        form.querySelectorAll('[data-doc-system]').forEach(input => { input.value = model.system[input.dataset.docSystem] || ''; });
        form.querySelectorAll('[data-doc-message]').forEach(input => { input.value = model.messages[input.dataset.docMessage] || ''; });
        form.querySelectorAll('[data-advanced-page]').forEach(input => { input.value = model.advanced.pages[input.dataset.advancedPage] || ''; });
        const advanced = form.querySelector('[data-advanced-enabled]');if(advanced)advanced.checked=Boolean(model.advanced.enabled);
        form.querySelectorAll('[data-doc-lines]').forEach(input => { input.value = model[input.dataset.docLines].join('\n'); });
        form.querySelectorAll('[data-doc-array]').forEach(input => { input.checked = model[input.dataset.docArray].includes(Number(input.value)); });
        form.querySelectorAll('[data-popup]').forEach(input => { input.value = model.popup[input.dataset.popup]; });
        form.querySelectorAll('[data-popup-lines]').forEach(input => { input.value = model.popup[input.dataset.popupLines].join('\n'); });
        form.querySelector('[data-popup-countries]')?.querySelectorAll('option').forEach(option => { option.selected = model.popup.countries.includes(option.value); });
        form.querySelectorAll('[data-field]').forEach(row => { row.querySelector('[data-field-visible]').checked = model.fields.includes(row.dataset.field); row.querySelector('[data-field-required]').checked = model.required.includes(row.dataset.field); });
        const fieldList = form.querySelector('[data-collection-fields]'); if (fieldList) [...model.fields].reverse().forEach(key => { const row = fieldList.querySelector(`[data-field="${key}"]`); if (row) fieldList.prepend(row); });
        Object.keys(lists).forEach(renderList); restoring = false; record(); preview();
    };
    undo?.addEventListener('click', () => restore(historyIndex - 1)); redo?.addEventListener('click', () => restore(historyIndex + 1));
    if (!config.canManage) form.querySelectorAll('button,input,textarea,select').forEach(input => { input.disabled = true; });
    selectTab(config.kind === 'survey' ? 'questions' : 'elements'); sync(); preview();
})();
