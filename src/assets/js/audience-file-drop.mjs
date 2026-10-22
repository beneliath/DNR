import {imageFileSize} from './audience-image-upload.mjs';

export function setFileDropStatus(input, message = 'No file selected.', error = false) {
    const zone = input.closest('[data-file-drop]');
    if (!zone) return;
    const status = zone.querySelector('[data-file-drop-status]');
    status.textContent = message;
    status.classList.toggle('is-error', error);
    input.setCustomValidity(error ? message : '');
}

export function selectedUploadFile(input) {
    const files = input.files;
    if (!files.length) return null;
    if (files.length !== 1) throw new Error('Choose one file at a time.');
    const file = files[0];
    if (!file.size) throw new Error('Choose a file that is not empty.');
    const limit=Number(input.dataset.fileMaxBytes||0);
    if(limit&&file.size>limit)throw new Error(`Choose a file up to ${imageFileSize(limit)}.`);
    const accepted = input.accept.split(',').map(value => value.trim().toLowerCase()).filter(Boolean);
    if (accepted.length && !accepted.some(type => type.startsWith('.')
        ? file.name.toLowerCase().endsWith(type)
        : type.endsWith('/*') ? file.type.startsWith(type.slice(0, -1)) : file.type === type)) {
        throw new Error(input.dataset.fileTypes || 'Choose one of the supported file types.');
    }
    return file;
}

// Keep the native input and its existing preparation/save flow for both paths.
export function wireAudienceFileDrop(zone) {
    if(zone.dataset.fileDropWired)return;
    zone.dataset.fileDropWired='true';
    const input = zone.querySelector('input[type="file"]');
    const browse = zone.querySelector('[data-file-browse]');
    let dragDepth = 0;
    const disabled = () => input.matches(':disabled');
    const clearDrag = () => { dragDepth = 0; zone.classList.remove('is-dragging'); };
    browse.hidden = false;
    input.classList.add('sr-only');
    input.tabIndex = -1;
    browse.addEventListener('click', () => { if (!disabled()) input.click(); });
    input.addEventListener('change', event => {
        try{const file=selectedUploadFile(input);setFileDropStatus(input,file?`${file.name} · ${imageFileSize(file.size)}`:undefined);}
        catch(error){input.value='';setFileDropStatus(input,error.message,true);event.stopImmediatePropagation();}
    },true);
    input.form?.addEventListener('reset', () => { clearDrag(); setFileDropStatus(input); });
    zone.addEventListener('dragenter', event => {
        event.preventDefault();
        event.stopPropagation();
        if (!disabled() && Array.from(event.dataTransfer?.types || []).includes('Files')) {
            dragDepth++;
            zone.classList.add('is-dragging');
        }
    });
    zone.addEventListener('dragover', event => {
        event.preventDefault();
        event.stopPropagation();
        if (event.dataTransfer) event.dataTransfer.dropEffect = disabled() ? 'none' : 'copy';
    });
    zone.addEventListener('dragleave', event => {
        event.preventDefault();
        event.stopPropagation();
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) clearDrag();
    });
    zone.addEventListener('drop', event => {
        event.preventDefault();
        event.stopPropagation();
        clearDrag();
        if (disabled()) return;
        const files = event.dataTransfer?.files;
        if (!files?.length) { setFileDropStatus(input, 'Drop a file, or use Browse files to choose one.', true); return; }
        try { input.files = files; }
        catch { setFileDropStatus(input, 'Use Browse files to select this file in your browser.', true); return; }
        input.dispatchEvent(new Event('change', {bubbles: true}));
    });
}

let uploadId=0;
/** Add the shared deposit area around an existing native input, retaining its handlers and form fields. */
export function enhanceAudienceFileInput(input,{label,help}={}) {
    if(input.closest('[data-file-drop],[data-import-drop]'))return;
    const original=input.closest('label'),container=document.createElement('div');
    if(original)for(const attribute of original.attributes)if(attribute.name!=='for')container.setAttribute(attribute.name,attribute.value);
    container.classList.add('audience-upload-field');
    label||=original?.textContent.trim()||input.getAttribute('aria-label')||'Upload file';
    if(!input.id){let id;do{id=`audience-upload-${++uploadId}`;}while(document.getElementById(id));input.id=id;}
    const caption=document.createElement('label');caption.htmlFor=input.id;caption.textContent=label;
    const zone=document.createElement('div');zone.className='audience-file-drop audience-file-drop-compact';zone.dataset.fileDrop='';zone.setAttribute('role','group');zone.setAttribute('aria-label',label);
    const icon=document.createElementNS('http://www.w3.org/2000/svg','svg');icon.setAttribute('viewBox','0 0 48 48');icon.setAttribute('aria-hidden','true');
    const path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','M14 33H11a8 8 0 0 1-1-16 13 13 0 0 1 25-2 9 9 0 0 1 2 18h-3M24 39V22m-7 7 7-7 7 7');icon.append(path);
    const prompt=document.createElement('strong');prompt.textContent='Drag and drop your file here';
    const hint=document.createElement('span');hint.id=input.id+'-drop-help';hint.textContent=help||input.dataset.fileHelp||'Drop a file or browse to choose one.';
    const browse=document.createElement('button');browse.type='button';browse.dataset.fileBrowse='';browse.textContent='Browse files';
    const status=document.createElement('p');status.id=input.id+'-drop-status';status.dataset.fileDropStatus='';status.setAttribute('role','status');status.setAttribute('aria-live','polite');status.textContent='No file selected.';
    input.setAttribute('aria-describedby',[input.getAttribute('aria-describedby'),hint.id,status.id].filter(Boolean).join(' '));
    if(original)original.replaceWith(container);else input.before(container);
    zone.append(icon,prompt,hint,browse,input,status);container.append(caption,zone);wireAudienceFileDrop(zone);return container;
}
