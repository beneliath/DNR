(function () {
    'use strict';
    const sidebar = document.getElementById('app-sidebar');
    if (!sidebar) return;
    const prefix = 'dnr.form-draft.' + encodeURIComponent(sidebar.dataset.navPreferenceUser) + '.';
    document.querySelectorAll('[data-clear-form-draft]').forEach(function (node) {
        try { localStorage.removeItem(prefix + node.dataset.clearFormDraft); } catch (_) {}
    });
    document.querySelectorAll('form[data-recoverable-draft]').forEach(function (form) {
        const key = prefix + form.dataset.recoverableDraft;
        const controls = () => Array.from(form.elements).filter(el => el.name && !el.disabled && !['hidden','file','password','submit','button','reset'].includes(el.type));
        const versions = () => JSON.stringify(Array.from(form.elements).filter(el => /version/.test(el.name)).map(el => [el.name,el.value]).concat([['record',form.dataset.draftVersion || '']]));
        const capture = () => controls().map(el => ({name:el.name,type:el.type,value:el.value,checked:el.checked}));
        let baseline = JSON.stringify(capture());
        let lastSaved = baseline;
        let saved = null;
        let timer;
        let submitting = false;
        const panel = document.createElement('section'); panel.className = 'form-draft-panel';
        const status = document.createElement('p'); status.setAttribute('role','status');
        const save = document.createElement('button'); save.type='button'; save.textContent='Save Draft';
        const restore = document.createElement('button'); restore.type='button'; restore.textContent='Restore Draft';
        const discard = document.createElement('button'); discard.type='button'; discard.textContent='Discard Draft';
        const recovery = document.createElement('textarea'); recovery.readOnly=true; recovery.hidden=true; recovery.rows=8; recovery.setAttribute('aria-label','Saved draft values for manual recovery');
        const note=document.createElement('p'); note.textContent='Drafts are saved for 30 days in this browser. Files must be selected again. Saving a draft does not save the record or send email.';
        panel.append(status,save,restore,discard,note,recovery); form.prepend(panel);
        try {
            saved=JSON.parse(localStorage.getItem(key) || 'null');
            if (saved && (!Array.isArray(saved.fields) || saved.expires<Date.now())) { localStorage.removeItem(key); saved=null; }
        } catch (_) { saved=null; }
        function showSaved() {
            restore.hidden=discard.hidden=!saved;
            const stale=saved && saved.version!==versions();
            restore.disabled=!!stale;
            status.textContent=saved ? (stale?'The record changed since this draft was saved. Review the saved values below and reapply only the changes you still want.':'Draft available from '+new Date(saved.at).toLocaleString()) : 'No saved draft';
            recovery.hidden=!stale;
            if(stale) recovery.value=saved.fields.map(v=>v.name+': '+(['checkbox','radio'].includes(v.type)?(v.checked?v.value:'not selected'):v.value)).join('\n');
        }
        function persist() {
            const draft={fields:capture(),version:versions(),at:Date.now(),expires:Date.now()+30*86400000};
            try {
                const text=JSON.stringify(draft);
                if(text.length>1000000) throw new Error('Draft too large');
                localStorage.setItem(key,text); saved=draft; lastSaved=JSON.stringify(draft.fields);
                showSaved(); status.textContent='Draft saved in this browser';
            } catch (_) { status.textContent='Draft could not be saved in this browser. Keep this page open or copy your changes.'; }
        }
        save.addEventListener('click',persist);
        restore.addEventListener('click',function () {
            if(!saved || saved.version!==versions()) return;
            const available=controls(); const consumed=new Set(); const missing=[];
            available.forEach(el => { if (el.type === 'checkbox') el.checked = false; });
            saved.fields.forEach(function (value) {
                const el=available.find((el,index)=>!consumed.has(index) && el.name===value.name && el.type===value.type && (!['checkbox','radio'].includes(el.type) || el.value===value.value));
                if(!el) { missing.push(value); return; }
                consumed.add(available.indexOf(el));
                if(el.tagName==='SELECT' && !Array.from(el.options).some(o=>o.value===value.value)) { missing.push(value); return; }
                if(['checkbox','radio'].includes(el.type)) el.checked=value.checked; else el.value=value.value;
                // Applying a template would overwrite the restored body and recipient choices.
                if (!el.hasAttribute('data-email-template')) {
                    el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true}));
                }
            });
            clearTimeout(timer);
            recovery.hidden=!missing.length;
            recovery.value=missing.map(v=>v.name+': '+v.value).join('\n');
            status.textContent=missing.length?'Draft restored where controls still exist. Recreate missing rows using the saved values below; select files again.':'Draft restored. Review the form and select files again before saving.';
            lastSaved=JSON.stringify(capture());
        });
        discard.addEventListener('click',function () { clearTimeout(timer); try { localStorage.removeItem(key); } catch (_) {} saved=null; showSaved(); });
        form.addEventListener('input',function (event) {
            if(panel.contains(event.target)) return;
            // Never overwrite an older draft before the user chooses to restore or discard it.
            if(saved && status.textContent.startsWith('Draft available')) return;
            if(saved && saved.version!==versions()) return;
            clearTimeout(timer); timer=setTimeout(persist,800);
        });
        form.addEventListener('submit',function () { clearTimeout(timer); persist(); submitting=true; });
        window.addEventListener('beforeunload',function (event) {
            if(!submitting && JSON.stringify(capture())!==baseline && JSON.stringify(capture())!==lastSaved) { event.preventDefault(); event.returnValue=''; }
        });
        showSaved();
    });
}());
