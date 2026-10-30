import {$, $$, request} from './dom.mjs';
import {prepareAudienceImage, imageFileSize, matchAudienceBrandLogos} from '../audience-image-upload.mjs';
import {fitBrandLogoPreview} from '../audience-logo-preview.mjs';
import {setFileDropStatus, selectedUploadFile} from '../audience-file-drop.mjs';

export function initializeContentWorkspace(show) {
    const contentStatus = (form, message) => { const status = $('[data-content-status]', form); if (status) status.textContent = message; };
    const brandDirtyStates = new WeakMap();
    $$('[data-brand-form]').forEach(form => {
        const controls = Array.from(form.elements).filter(input => input.name && !['csrf_token','action','version'].includes(input.name) && !['file','submit','button'].includes(input.type));
        const value = input => ['checkbox','radio'].includes(input.type) ? input.checked : input.value;
        const saved = new Map(controls.map(input => [input, value(input)]));
        const buttons = $$('[data-brand-save]').filter(button => button.form === form);
        const status = $('[data-brand-unsaved-status]', form); let previous = false;
        const update = () => {
            const dirty = form.dataset.brandRecovery === '1' || controls.some(input => value(input) !== saved.get(input));
            buttons.forEach(button => button.classList.toggle('brand-save-unsaved', dirty));
            if (status && dirty !== previous) status.textContent = dirty ? 'Unsaved brand settings.' : 'No unsaved brand settings.';
            previous = dirty;
        };
        brandDirtyStates.set(form, update); form.addEventListener('input', update); form.addEventListener('change', update);
        form.addEventListener('reset', () => queueMicrotask(update)); update();
    });
    const brandLogoDimensions = (form,key) => {
        const preview=$(`[data-content-preview="${key}"]`,form),label=$(`[data-brand-logo-dimensions="${key}"]`,form);
        if(label)label.textContent=form.elements[key].value&&preview?.naturalWidth?`${preview.naturalWidth} × ${preview.naturalHeight}${form.elements[key].value.startsWith('data:image/svg+xml;')?' · SVG (vector)':' pixels'}`:'';
    };
    $$('[data-brand-form] [data-content-preview]').forEach(preview=>{
        const update=()=>{brandLogoDimensions(preview.closest('form'),preview.dataset.contentPreview);fitBrandLogoPreview(preview);};
        preview.addEventListener('load',update);update();
        const stage=preview.closest('.content-brand-logo-stage');if(stage&&typeof ResizeObserver!=='undefined')new ResizeObserver(()=>fitBrandLogoPreview(preview)).observe(stage);
    });
    const setContentImage = (form, key, value) => {
        form.elements[key].value = value;
        const preview = $(`[data-content-preview="${key}"]`, form);
        if (preview) { preview.src = value; preview.hidden = !value; const stage=preview.closest('.content-brand-logo-stage');if(stage)stage.hidden=!value; }
        if(!value)brandLogoDimensions(form,key);
        brandDirtyStates.get(form)?.();
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
        if(!state.pending.size){state.buttons=Array.from(form.elements).filter(button=>button.matches('button,input')&&button.type==='submit').map(button=>[button,button.disabled]);state.buttons.forEach(([button])=>button.disabled=true);}
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
            brandDirtyStates.get(form)?.();
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
        brandDirtyStates.get(button.form)?.();
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
    const folderForm = $('[data-content-folder-form]');
    if (folderForm) {
        let approved = false, checking = false;
        folderForm.addEventListener('submit', async event => {
            const submitter = event.submitter;
            if (submitter?.value !== 'delete' || approved) return;
            event.preventDefault();
            if (checking) return;
            checking = true;
            try {
                if (await window.DnrAdminUnlock?.request()) {
                    approved = true;
                    folderForm.requestSubmit(submitter);
                }
            } finally { approved = false; checking = false; }
        });
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
    const campaignDeliveryRefresh = $('[data-campaign-delivery-auto-refresh]');
    if (campaignDeliveryRefresh) {
        const interval = Number(campaignDeliveryRefresh.dataset.campaignDeliveryAutoRefresh);
        if (interval > 0) window.setInterval(() => {
            const editing = document.activeElement?.matches('input:not([type="hidden"]), textarea, select, [contenteditable="true"]');
            if (document.visibilityState === 'visible' && !document.querySelector('dialog[open]') && !editing) location.reload();
        }, interval);
    }
    $$('[data-copy-delivery]').forEach(button=>button.addEventListener('click',async()=>{const status=$('[data-delivery-copy-status]',button.closest('dialog')||document);try{await navigator.clipboard.writeText(button.dataset.copyDelivery);if(status)status.textContent='Copied.';}catch{if(status)status.textContent='Copy is unavailable. Select the record value and copy it.';}}));
    const brandApply=$('[data-brand-apply]');if(brandApply){const select=brandApply.elements.template_id;const update=()=>brandApply.elements.version.value=select.selectedOptions[0]?.dataset.version||'0';select.addEventListener('change',update);update();}
}

