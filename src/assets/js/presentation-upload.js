(function () {
    'use strict';
    const CHUNK_BYTES = 10 * 1024 * 1024;

    async function sendChunks(file, state, send, progress) {
        while (state.offset < file.size) {
            const end = Math.min(state.offset + CHUNK_BYTES, file.size);
            let result;
            for (let attempt = 0; ; attempt++) {
                try {
                    result = await send(file.slice(state.offset, end), state.offset, function (loaded) {
                        progress(Math.min(end, state.offset + loaded));
                    });
                    break;
                } catch (error) {
                    if (!error.retryable || attempt >= 2) throw error;
                    await new Promise(function (resolve) { setTimeout(resolve, 1000 * (attempt + 1)); });
                }
            }
            if (result.offset !== end) throw new Error('The upload could not be confirmed. Please try again.');
            state.offset = end;
            progress(end);
        }
    }
    if (typeof module === 'object' && module.exports) module.exports = { sendChunks, CHUNK_BYTES };
    if (typeof document === 'undefined') return;

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('[data-chunk-engagement]');
        if (!form) return;
        const cache = new WeakMap();
        let busy = false;
        let ready = false;
        let finalEvent = null;
        let lastSubmitter = null;
        const panel = document.createElement('section');
        panel.className = 'presentation-upload-progress';
        panel.hidden = true;
        panel.setAttribute('aria-label', 'File upload progress');
        const status = document.createElement('p');
        status.setAttribute('role', 'status');
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = 0;
        bar.setAttribute('aria-label', 'File upload progress');
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Retry Save';
        retry.hidden = true;
        retry.addEventListener('click', function () { form.requestSubmit(lastSubmitter || undefined); });
        panel.append(status, bar, retry);
        document.body.appendChild(panel);

        function request(action, row, extra, onProgress) {
            const data = new FormData();
            data.append('csrf_token', form.elements.namedItem('csrf_token').value);
            data.append('engagement_id', form.dataset.chunkEngagement);
            data.append('row', row);
            data.append('action', action);
            Object.entries(extra).forEach(function ([key, value]) { data.append(key, value); });
            return new Promise(function (resolve, reject) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'presentation_chunk.php');
                xhr.timeout = 180000;
                if (onProgress) xhr.upload.onprogress = function (event) { onProgress(event.loaded); };
                function fail(message, retryable) {
                    const error = new Error(message);
                    error.retryable = retryable;
                    reject(error);
                }
                xhr.onerror = xhr.ontimeout = function () { fail('Connection interrupted. Click Save Changes to retry the upload.', true); };
                xhr.onload = function () {
                    let body;
                    try { body = JSON.parse(xhr.responseText); } catch (_) { /* Login or proxy error page. */ }
                    if (xhr.status >= 200 && xhr.status < 300 && body) resolve(body);
                    else fail(body?.error || (xhr.status === 413
                        ? 'The server rejected an upload chunk. Please contact your administrator.'
                        : 'Upload failed. Your changes have not been saved. Check your connection or sign-in and try again.'),
                    xhr.status >= 500 || xhr.status === 408 || xhr.status === 429);
                };
                xhr.send(data);
            });
        }

        window.addEventListener('pageshow', function (event) {
            if (event.persisted && ready) window.location.reload();
        });
        window.addEventListener('beforeunload', function (event) {
            if (busy && !ready) { event.preventDefault(); event.returnValue = ''; }
        });
        // Runs after the form's existing date/presentation validation listeners.
        document.addEventListener('submit', async function (event) {
            if (event.target !== form || event.defaultPrevented) return;
            if (ready) {
                if (finalEvent) event.preventDefault();
                else finalEvent = event;
                return;
            }
            if (busy) { event.preventDefault(); return; }
            const inputs = Array.from(form.elements).filter(function (input) {
                return input.type === 'file' && !input.disabled && /\[(ppt_slidedeck|speaker_notes)\]$/.test(input.name) && input.files.length;
            });
            if (!inputs.length) return;
            event.preventDefault();
            const submitter = event.submitter;
            lastSubmitter = submitter;
            retry.hidden = true;
            const controls = Array.from(form.elements).filter(function (control) { return !control.disabled; });
            const total = inputs.reduce(function (sum, input) { return sum + input.files[0].size; }, 0);
            const added = [];
            busy = true;
            panel.hidden = false;
            bar.value = 0;
            status.textContent = 'Preparing file upload…';
            form.setAttribute('aria-busy', 'true');
            controls.forEach(function (control) { control.disabled = true; });
            try {
                let completed = 0;
                for (const input of inputs) {
                    const file = input.files[0];
                    const match = input.name.match(/^presentations\[(\d+)\]\[(ppt_slidedeck|speaker_notes)\]$/);
                    if (!match) throw new Error('Reload this page before uploading.');
                    const row = match[1];
                    const assetKey = match[2];
                    const maximum = assetKey === 'speaker_notes' ? 100 : 500;
                    if (!file.size || file.size > maximum * 1024 * 1024) throw new Error(file.name + ' must be ' + maximum + ' MB or smaller.');
                    let state = cache.get(input);
                    if (!state || state.file !== file) {
                        if (state) {
                            // Expired or unreachable uploads are also removed by the storage worker.
                            await request('cancel', row, { token: state.token }).catch(function () {});
                        }
                        const result = await request('start', row, { name: file.name, size: file.size, asset_key: assetKey });
                        if (!/^[a-f0-9]{64}$/.test(result.token)) throw new Error('Unable to start upload.');
                        state = { file, token: result.token, offset: 0 };
                        cache.set(input, state);
                    }
                    function progress(loaded) {
                        const percent = Math.floor(100 * (completed + loaded) / total);
                        bar.value = percent;
                        status.textContent = 'Uploading ' + file.name + ' — ' + percent + '% ('
                            + ((completed + loaded) / 1048576).toFixed(1) + ' of ' + (total / 1048576).toFixed(1) + ' MB). Keep this page open.';
                    }
                    progress(state.offset);
                    await sendChunks(file, state, function (chunk, offset, report) {
                        return request('chunk', row, { token: state.token, offset, chunk }, report);
                    }, progress);
                    completed += file.size;
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'presentations[' + row + '][' + (assetKey === 'speaker_notes' ? 'pdf_upload_token' : 'ppt_upload_token') + ']';
                    hidden.value = state.token;
                    form.appendChild(hidden);
                    added.push(hidden);
                }
                controls.forEach(function (control) { control.disabled = false; });
                // Exclude original files from the final form POST while keeping all other fields and the submitter.
                inputs.forEach(function (input) { input.disabled = true; });
                bar.removeAttribute('value');
                status.textContent = 'Upload complete. Saving changes…';
                ready = true;
                finalEvent = null;
                form.requestSubmit(submitter || undefined);
                if (!finalEvent || finalEvent.defaultPrevented) {
                    ready = false;
                    throw new Error('Upload complete. Check the form fields, then click Save Changes again.');
                }
                controls.forEach(function (control) { control.disabled = true; });
            } catch (error) {
                status.textContent = error.message;
                retry.hidden = false;
                bar.value = 0;
                added.forEach(function (input) { input.remove(); });
                controls.forEach(function (control) { control.disabled = false; });
                form.removeAttribute('aria-busy');
                busy = false;
            }
        });
    });
})();
