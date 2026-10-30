function copyWithFallback(value) {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.readOnly = true;
    textarea.className = 'clipboard-fallback';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();
    if (!copied) throw new Error('The browser declined the copy command.');
}

async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }
    copyWithFallback(value);
}

export function initializeCopyTextButtons() {
    document.querySelectorAll('[data-copy-text]').forEach(function (button) {
        const status = document.getElementById(button.dataset.copyStatus || '');
        const idleLabel = button.getAttribute('aria-label') || 'Copy';
        const idleTitle = button.getAttribute('title') || idleLabel;
        const idleTooltip = button.dataset.tooltip || idleTitle;
        let feedbackTimer;

        button.addEventListener('click', async function () {
            window.clearTimeout(feedbackTimer);
            button.classList.remove('is-copied', 'is-copy-failed');
            const finishFeedback = window.DnrButtonFeedback?.begin(button);
            try {
                await copyText(button.dataset.copyText || '');
                button.classList.add('is-copied');
                button.setAttribute('aria-label', 'Email Routing Marker Copied');
                button.setAttribute('title', 'Copied');
                button.dataset.tooltip = 'Copied';
                if (status) status.textContent = 'Email routing marker copied to the clipboard.';
            } catch (error) {
                button.classList.add('is-copy-failed');
                button.setAttribute('aria-label', 'Email Routing Marker Could Not Be Copied');
                button.setAttribute('title', 'Copy Failed');
                button.dataset.tooltip = 'Copy Failed';
                if (status) status.textContent = 'The email routing marker could not be copied.';
            } finally {
                finishFeedback?.();
            }

            feedbackTimer = window.setTimeout(function () {
                button.classList.remove('is-copied', 'is-copy-failed');
                button.setAttribute('aria-label', idleLabel);
                button.setAttribute('title', idleTitle);
                button.dataset.tooltip = idleTooltip;
            }, 2000);
        });
    });
}

async function qrImageAsPng(url) {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('The QR code image could not be loaded.');
    const source = await response.blob();
    if (!source.type.startsWith('image/')) throw new Error('The QR code response is not an image.');
    if (source.type === 'image/png') return source;
    if (typeof createImageBitmap !== 'function') throw new Error('Image conversion is unavailable.');

    const bitmap = await createImageBitmap(source);
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Image conversion is unavailable.');
    context.drawImage(bitmap, 0, 0);
    if (typeof bitmap.close === 'function') bitmap.close();
    return await new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
            if (blob) resolve(blob);
            else reject(new Error('The QR code could not be converted.'));
        }, 'image/png');
    });
}

let activeQrCopyButton = null;
let activeQrCopyStatus = null;

function activateQrCopyFeedback(button) {
    const container = button.closest('.presentation-qr-card, .presentation-qr-display');
    const status = container ? container.querySelector('[data-copy-status]') : null;
    if (activeQrCopyStatus && activeQrCopyStatus !== status) {
        activeQrCopyStatus.textContent = '';
        activeQrCopyStatus.classList.remove('is-error');
    }
    if (activeQrCopyButton && activeQrCopyButton !== button) {
        activeQrCopyButton.classList.remove('is-copied', 'is-copy-failed');
    }
    activeQrCopyButton = button;
    activeQrCopyStatus = status;
    return status;
}

function qrCopyStatus(button, message, failed) {
    const status = activateQrCopyFeedback(button);
    if (status) {
        status.textContent = message;
        status.classList.toggle('is-error', Boolean(failed));
    }
    button.classList.toggle('is-copied', !failed);
    button.classList.toggle('is-copy-failed', Boolean(failed));
    window.setTimeout(function () {
        button.classList.remove('is-copied', 'is-copy-failed');
    }, 2200);
}

function openQrFallback(button, url) {
    const preview = document.getElementById('qr-code-preview');
    const previewTitle = document.getElementById('qr-code-preview-title');
    const previewImage = document.getElementById('qr-code-preview-image');
    if (!preview || !previewTitle || !previewImage || typeof preview.showModal !== 'function') {
        qrCopyStatus(button, 'QR code preview is unavailable.', true);
        return;
    }
    const buttonLabel = button.getAttribute('aria-label') || 'Copy QR Code';
    const qrContext = buttonLabel
        .replace(/^Copy\s+/i, '')
        .replace(/\s+QR code$/i, '')
        .trim() || 'QR';
    previewTitle.textContent = qrContext + ' QR Code Preview';
    previewImage.alt = qrContext + ' QR code preview';
    previewImage.src = url;
    if (!preview.open) preview.showModal();
    qrCopyStatus(button, 'QR code opened in the app preview.', false);
}

export function initializeQrCopy() {
    let latestRequestId = 0;
    document.addEventListener('click', async function (event) {
        const linkButton = event.target.closest('[data-copy-qr-link]');
        if (linkButton && !linkButton.hidden) {
            const requestId = ++latestRequestId;
            activateQrCopyFeedback(linkButton);
            linkButton.disabled = true;
            const finishFeedback = window.DnrButtonFeedback?.begin(linkButton);
            try {
                await copyText(linkButton.dataset.copyQrLink || '');
                if (requestId === latestRequestId) qrCopyStatus(linkButton, 'QR link copied to the clipboard.', false);
            } catch (error) {
                if (requestId === latestRequestId) qrCopyStatus(linkButton, 'The link could not be copied. Please try again.', true);
            } finally {
                finishFeedback?.();
                linkButton.disabled = false;
            }
            return;
        }
        const button = event.target.closest('[data-copy-qr-url]');
        if (!button || button.hidden) return;
        const url = button.dataset.copyQrUrl || '';
        if (!url) return;
        const requestId = ++latestRequestId;
        activateQrCopyFeedback(button);

        if (!navigator.clipboard
            || typeof navigator.clipboard.write !== 'function'
            || typeof ClipboardItem !== 'function'
            || !window.isSecureContext
        ) {
            openQrFallback(button, url);
            return;
        }

        button.disabled = true;
        const finishFeedback = window.DnrButtonFeedback?.begin(button);
        try {
            const png = await qrImageAsPng(url);
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
            if (requestId !== latestRequestId) return;
            qrCopyStatus(button, 'QR code copied to the clipboard.', false);
        } catch (error) {
            if (requestId !== latestRequestId) return;
            openQrFallback(button, url);
        } finally {
            finishFeedback?.();
            button.disabled = false;
        }
    });
}

export function initializeQrPdfSelection() {
    const dialog = document.getElementById('presentation-qr-pdf-dialog');
    const form = document.getElementById('presentation-qr-pdf-form');
    if (!dialog || !form) return;
    const options = document.getElementById('qr-pdf-options');
    const selectAll = document.getElementById('qr-pdf-select-all');
    const clearSelection = document.getElementById('qr-pdf-clear-selection');
    const count = document.getElementById('qr-pdf-selection-count');
    const prepare = document.getElementById('prepare-qr-pdf');
    const empty = document.getElementById('qr-pdf-empty');
    const error = document.getElementById('qr-pdf-error');
    const orderStatus = document.getElementById('qr-pdf-order-status');
    let trigger = null;
    let checkboxes = [];
    let drag = null;
    let dragFrame = null;

    function orderedRows() {
        return Array.from(options.querySelectorAll('[data-qr-pdf-option]'));
    }

    function updateSelection() {
        checkboxes = Array.from(options.querySelectorAll('input[name="link_ids[]"]:not(:disabled)'));
        const selected = checkboxes.filter(function (input) { return input.checked; }).length;
        const rows = orderedRows();
        let position = 0;
        rows.forEach(function (row) {
            const input = row.querySelector('input');
            const included = input.checked && !input.disabled;
            row.querySelector('[data-qr-pdf-drag]').disabled = rows.length < 2;
            row.querySelector('[data-qr-pdf-position]').textContent = included ? String(++position) : '';
        });
        selectAll.disabled = checkboxes.length === 0;
        if (clearSelection) clearSelection.hidden = selected === 0;
        count.textContent = selected + ' of ' + checkboxes.length + ' selected';
        prepare.disabled = selected === 0;
        empty.hidden = checkboxes.length > 0;
        error.hidden = selected > 0 || checkboxes.length === 0;
        return selected;
    }

    function announceOrder(row) {
        const rows = orderedRows();
        orderStatus.textContent = row.querySelector('.qr-pdf-option-label').textContent.trim()
            + ' moved to list position ' + (rows.indexOf(row) + 1) + ' of ' + rows.length + '.';
    }

    function moveRow(row, target, after) {
        options.insertBefore(row, after ? target.nextSibling : target);
        updateSelection();
    }

    function finishDrag() {
        if (!drag) return;
        const previous = drag;
        drag = null;
        window.cancelAnimationFrame(dragFrame);
        previous.row.classList.remove('is-dragging');
        if (options.hasPointerCapture(previous.pointerId)) options.releasePointerCapture(previous.pointerId);
        previous.handle.focus({ preventScroll: true });
        if (previous.active) announceOrder(previous.row);
    }

    function moveAtPointer() {
        if (!drag || !drag.active) return;
        const bounds = options.getBoundingClientRect();
        if (drag.x < bounds.left || drag.x > bounds.right) return;
        if (drag.y < bounds.top + 36) options.scrollTop -= 8;
        else if (drag.y > bounds.bottom - 36) options.scrollTop += 8;
        const target = document.elementFromPoint(drag.x, Math.max(bounds.top + 1, Math.min(bounds.bottom - 1, drag.y)))
            ?.closest('[data-qr-pdf-option]');
        const rows = orderedRows();
        if (!target || target === drag.row || !rows.includes(target)) return;
        moveRow(drag.row, target, rows.indexOf(drag.row) < rows.indexOf(target));
    }

    function scrollWhileDragging() {
        if (!drag) return;
        moveAtPointer();
        dragFrame = window.requestAnimationFrame(scrollWhileDragging);
    }

    options.addEventListener('pointerdown', function (event) {
        const handle = event.target.closest('[data-qr-pdf-drag]');
        if (!handle || handle.disabled || event.button !== 0 || event.isPrimary === false) return;
        event.preventDefault();
        handle.focus();
        drag = { handle: handle, row: handle.closest('[data-qr-pdf-option]'), pointerId: event.pointerId,
            x: event.clientX, y: event.clientY, startY: event.clientY, active: false };
        options.setPointerCapture(event.pointerId);
        dragFrame = window.requestAnimationFrame(scrollWhileDragging);
    });
    options.addEventListener('pointermove', function (event) {
        if (!drag || event.pointerId !== drag.pointerId) return;
        drag.x = event.clientX;
        drag.y = event.clientY;
        if (Math.abs(drag.y - drag.startY) > 4) {
            drag.active = true;
            drag.row.classList.add('is-dragging');
        }
        moveAtPointer();
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (name) {
        options.addEventListener(name, finishDrag);
    });
    options.addEventListener('keydown', function (event) {
        const handle = event.target.closest('[data-qr-pdf-drag]');
        if (!handle || handle.disabled || !['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const row = handle.closest('[data-qr-pdf-option]');
        const rows = orderedRows();
        const index = rows.indexOf(row);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? rows.length - 1
            : index + (event.key === 'ArrowUp' ? -1 : 1);
        if (!rows[next] || next === index) return;
        moveRow(row, rows[next], next > index);
        handle.focus();
        row.scrollIntoView({ block: 'nearest' });
        announceOrder(row);
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-qr-pdf-presentation-id]');
        if (!button) return;
        const template = document.getElementById('presentation-qr-pdf-options-' + button.dataset.qrPdfPresentationId);
        if (!template || typeof dialog.showModal !== 'function') return;
        event.preventDefault();
        trigger = button;
        document.getElementById('qr-pdf-presentation-id').value = button.dataset.qrPdfPresentationId;
        document.getElementById('presentation-qr-pdf-context').textContent = template.dataset.presentationTitle;
        options.replaceChildren(template.content.cloneNode(true));
        orderStatus.textContent = '';
        updateSelection();
        dialog.showModal();
        (selectAll.disabled ? document.getElementById('cancel-qr-pdf') : selectAll).focus();
    });
    options.addEventListener('change', updateSelection);
    selectAll.addEventListener('click', function () {
        checkboxes.forEach(function (input) { input.checked = true; });
        updateSelection();
    });
    clearSelection?.addEventListener('click', function () {
        checkboxes.forEach(function (input) { input.checked = false; });
        updateSelection();
    });
    document.getElementById('cancel-qr-pdf').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () {
        finishDrag();
        if (trigger) trigger.focus();
    });
    form.addEventListener('submit', function (event) {
        if (!updateSelection()) {
            event.preventDefault();
            return;
        }
        // Let the browser submit the selected controls directly into the new tab.
        dialog.close();
    });
}

export function initializePresentationCopy() {
    document.querySelectorAll('[data-presentation-export]').forEach(function (group) {
        const data = group.querySelector('[data-presentation-export-data]');
        const status = group.querySelector('[data-presentation-copy-status]');
        if (!data || !status) return;
        let exports;
        try { exports = JSON.parse(data.textContent || '{}'); } catch (error) { return; }
        group.querySelectorAll('[data-copy-format]').forEach(function (button) {
            const originalLabel = button.textContent;
            let feedbackTimer;
            button.addEventListener('click', async function () {
                window.clearTimeout(feedbackTimer);
                button.disabled = true;
                const finishFeedback = window.DnrButtonFeedback?.begin(button);
                try {
                    const value = exports && exports[button.dataset.copyFormat];
                    if (typeof value !== 'string' || value === '') throw new Error('Presentation export unavailable.');
                    await copyText(value);
                    button.textContent = 'Copied!';
                    status.textContent = originalLabel + ' copied to the clipboard.';
                } catch (error) {
                    button.textContent = 'Copy Failed';
                    status.textContent = originalLabel + ' could not be copied.';
                } finally {
                    finishFeedback?.();
                    button.disabled = false;
                }
                feedbackTimer = window.setTimeout(function () { button.textContent = originalLabel; }, 1800);
            });
        });
    });
}
