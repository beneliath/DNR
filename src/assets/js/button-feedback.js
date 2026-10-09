(function () {
    'use strict';

    function downloadKind(control, base) {
        const url = new URL(control.href, base);
        if (url.origin !== new URL(base).origin) return null;
        const page = url.pathname.split('/').pop();
        if (['download_reimbursement.php', 'download_engagement_pdf.php', 'presentation_pdf_view.php',
            'presentation_qr_pdf_view.php', 'reimbursement_receipt.php', 'calendar.php'].includes(page)
            || (page === 'reimbursement_submit.php' && url.searchParams.get('preview') === 'package')
            || (page === 'presentation_asset.php' && ['notes', 'slidedeck'].includes(url.searchParams.get('type')))
            || (['short_link.php', 'short_link_qr.php'].includes(page) && url.searchParams.has('download'))
            || (['inquiries.php', 'ai_coach_improvements.php'].includes(page) && url.searchParams.has('export'))) return 'tracked';
        return control.hasAttribute('download') ? 'requested' : null;
    }

    function initialize(doc, win) {
        const controls = new Map();
        const forms = new Map();
        const timers = new Set();
        let notice;

        function announce(message) {
            if (!notice) {
                notice = doc.createElement('p');
                notice.className = 'button-feedback-notice';
                notice.setAttribute('role', 'status');
                notice.setAttribute('aria-live', 'polite');
                doc.body.appendChild(notice);
            }
            notice.hidden = false;
            notice.textContent = message;
        }

        function begin(control, label) {
            if (!control || controls.has(control)) return function () {};
            const attributes = ['aria-busy', 'aria-disabled', 'aria-label'].map(name => [name, control.getAttribute(name)]);
            const children = Array.from(control.childNodes);
            // Keep input values and submitter names intact: they can determine the server action.
            if (label && control.tagName !== 'INPUT' && !control.classList.contains('action-icon-button')) {
                control.textContent = label;
            }
            control.setAttribute('aria-busy', 'true');
            control.setAttribute('aria-disabled', 'true');
            const accessibleLabel = attributes[2][1] || children.map(child => child.textContent).join('').trim();
            const progressLabel = accessibleLabel ? accessibleLabel + ' — in progress' : null;
            if (progressLabel) control.setAttribute('aria-label', progressLabel);
            let finished = false;
            const finish = function () {
                if (finished) return;
                finished = true;
                controls.delete(control);
                if (label && control.tagName !== 'INPUT' && !control.classList.contains('action-icon-button')) {
                    control.replaceChildren(...children);
                }
                attributes.forEach(function ([name, value]) {
                    if (name === 'aria-label' && control.getAttribute(name) !== progressLabel) return;
                    if (value === null) control.removeAttribute(name);
                    else control.setAttribute(name, value);
                });
            };
            controls.set(control, finish);
            return finish;
        }

        function later(callback, delay) {
            const timer = win.setTimeout(function () { timers.delete(timer); callback(); }, delay);
            timers.add(timer);
        }

        function downloadToken() {
            return win.crypto?.getRandomValues
                ? Array.from(win.crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('')
                : null;
        }

        function watchDownload(token, url, finish) {
            const cookieName = 'dnr_download_' + token;
            announce('Preparing your file. Keep this page open.');
            let elapsed = 0;
            const check = function () {
                const cookie = doc.cookie.split('; ').find(entry => entry.startsWith(cookieName + '='));
                if (!cookie && elapsed < 180000) {
                    elapsed += 500;
                    later(check, 500);
                    return;
                }
                finish();
                if (cookie) {
                    const accountPath = new URL(win.location.href).pathname.match(/^\/a\/[a-z][a-z0-9-]{2,63}\//)?.[0];
                    doc.cookie = cookieName + '=; Max-Age=0; Path=' + (accountPath || new URL('.', url).pathname) + '; SameSite=Strict';
                    announce(cookie.slice(cookieName.length + 1) === 'started'
                        ? 'File ready. Check your browser’s Downloads or the opened tab for the result.'
                        : 'The file could not be opened or downloaded. Check the response page before trying again.');
                } else {
                    announce('Download confirmation was not received. Check your browser’s Downloads or the response tab before trying again.');
                }
            };
            later(check, 500);
        }

        // Stop duplicate activation before page-specific click/submit handlers run.
        doc.addEventListener('click', function (event) {
            const control = event.target.closest('button, input[type="submit"], input[type="button"], a');
            if (!controls.has(control)) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        win.addEventListener('submit', function (event) {
            if (!forms.has(event.target)) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        // Window bubbling runs after form and document handlers, including confirmations.
        win.addEventListener('submit', function (event) {
            const form = event.target;
            const control = event.submitter || form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
            if (!control) return;
            const method = (control.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
            if (event.defaultPrevented || method === 'dialog' || form.getAttribute('aria-busy') === 'true') return;
            const uploading = Array.from(form.elements).some(field => field.type === 'file' && !field.disabled && field.files?.length);
            const action = new URL(control.getAttribute('formaction') || form.getAttribute('action') || win.location.href, win.location.href);
            const token = method === 'get' && downloadKind({ href: action.href, hasAttribute: () => false }, win.location.href) === 'tracked' ? downloadToken() : null;
            const label = control.dataset.submittingLabel || (uploading ? 'Uploading…' : method === 'get' ? 'Loading…' : 'Working…');
            const finishControl = begin(control, label);
            const previous = form.getAttribute('aria-busy');
            form.setAttribute('aria-busy', 'true');
            const finish = function () {
                forms.delete(form);
                finishControl();
                if (previous === null) form.removeAttribute('aria-busy');
                else form.setAttribute('aria-busy', previous);
            };
            forms.set(form, finish);
            const target = control.getAttribute('formtarget') || form.getAttribute('target');
            if (token) {
                // GET file forms (including selected QR PDFs) also leave this page open.
                const field = doc.createElement('input');
                field.type = 'hidden';
                field.name = '_download_feedback';
                field.value = token;
                form.appendChild(field);
                const finishDownload = function () { field.remove(); finish(); };
                forms.set(form, finishDownload);
                watchDownload(token, action, finishDownload);
            } else if (target && target !== '_self') {
                announce('Request opened in another tab. Check that tab for the result.');
                later(finish, 1200);
            }
        });

        win.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('a[href]');
            if (!link) return;
            const kind = downloadKind(link, win.location.href);
            if (!kind) {
                if (link.target === '_blank' && link.matches('.action-button, .button-secondary, .export-button, .presentation-view-pdf')) {
                    const finish = begin(link, 'Opening…');
                    announce('Opening in another tab. Check that tab for the result.');
                    later(finish, 1200);
                }
                return;
            }
            const finish = begin(link, kind === 'tracked' ? 'Preparing Download…' : 'Download Requested');
            const token = kind === 'tracked' ? downloadToken() : null;
            if (!token) {
                announce('Download requested. Check your browser’s Downloads for progress.');
                later(finish, 1200);
                return;
            }
            const original = link.getAttribute('href');
            const url = new URL(link.href, win.location.href);
            url.searchParams.set('_download_feedback', token);
            link.href = url.href;
            const finishDownload = function () { finish(); link.setAttribute('href', original); };
            watchDownload(token, url, finishDownload);
            // Restore the original URL when returning from a download response/error page.
            controls.set(link, finishDownload);
        });

        win.addEventListener('pageshow', function () {
            timers.forEach(timer => win.clearTimeout(timer));
            timers.clear();
            forms.forEach(finish => finish());
            controls.forEach(finish => finish());
            if (notice) notice.hidden = true;
        });
        win.DnrButtonFeedback = Object.freeze({ begin });
        return win.DnrButtonFeedback;
    }

    if (typeof module === 'object' && module.exports) module.exports = { downloadKind, initialize };
    if (typeof document === 'undefined') return;
    initialize(document, window);
})();
