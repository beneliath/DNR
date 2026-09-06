(function () {
    'use strict';

    function connectFieldError(field, error) {
        if (!field || !error.id) return;
        const describedBy = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
        describedBy.add(error.id);
        field.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
        field.setAttribute('aria-invalid', 'true');
    }

    function fieldLabel(field) {
        return Array.from(field.labels || []).map(function (label) {
            return label.textContent.trim().replace(/\s*\*\s*$/, '');
        }).filter(Boolean).join(' ') || field.getAttribute('aria-label') || 'This field';
    }

    function initialize(doc, win) {
        let nextId = 0;
        const connected = new WeakMap();
        const pending = new Map();
        let scheduled = false;

        function connect(field, error) {
            if (!field || !error) return;
            if (!error.id) error.id = 'form-error-' + (++nextId);
            const previous = connected.get(field) || { ids: new Set(), invalid: field.getAttribute('aria-invalid') };
            previous.ids.add(error.id);
            connected.set(field, previous);
            connectFieldError(field, error);
        }

        function errorTarget(field) {
            return doc.getElementById(field.dataset?.errorTarget || '') || field;
        }

        function clearFieldError(field) {
            const target = errorTarget(field);
            const previous = connected.get(target);
            if (!previous) return;
            const described = (target.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) {
                return id && !previous.ids.has(id);
            });
            if (described.length) target.setAttribute('aria-describedby', described.join(' '));
            else target.removeAttribute('aria-describedby');
            if (previous.invalid === null || field.validity?.valid) target.removeAttribute('aria-invalid');
            else target.setAttribute('aria-invalid', previous.invalid);
            previous.ids.forEach(function (id) {
                const message = doc.getElementById(id);
                if (message?.classList.contains('form-field-error')) message.remove();
            });
            connected.delete(target);
        }

        function linkToField(item, field, message) {
            const target = errorTarget(field);
            if (!target.id) target.id = 'form-field-' + (++nextId);
            const link = doc.createElement('a');
            link.href = '#' + target.id;
            link.textContent = message;
            link.addEventListener('click', function (event) {
                event.preventDefault();
                // Reveal an invalid control in a closed details section or record tab.
                for (let ancestor = target.parentElement; ancestor; ancestor = ancestor.parentElement) {
                    if (ancestor.tagName === 'DETAILS') ancestor.open = true;
                }
                const panel = target.closest('[role="tabpanel"][hidden]');
                if (panel) {
                    const tab = Array.from(doc.querySelectorAll('[role="tab"]')).find(function (candidate) {
                        return candidate.getAttribute('aria-controls') === panel.id;
                    });
                    if (tab) tab.click();
                    else win.location.hash = target.id;
                }
                const focus = function () {
                    target.focus();
                    target.scrollIntoView({ block: 'center', behavior: 'auto' });
                };
                if (target.closest('[hidden]')) win.setTimeout(focus, 0);
                else focus();
            });
            item.appendChild(link);
        }

        function showValidationErrors() {
            scheduled = false;
            pending.forEach(function (fields, form) {
                form.querySelector('[data-client-errors]')?.remove();
                const summary = doc.createElement('div');
                summary.className = 'error form-error-summary';
                summary.dataset.clientErrors = '';
                summary.setAttribute('role', 'alert');
                summary.tabIndex = -1;
                const heading = doc.createElement('h2');
                heading.textContent = 'Check the Details Below';
                const list = doc.createElement('ul');
                fields.forEach(function (field) {
                    if (field.disabled || field.validity.valid) return;
                    clearFieldError(field);
                    const message = field.validationMessage;
                    const label = fieldLabel(field);
                    const inline = doc.createElement('span');
                    inline.className = 'form-field-error';
                    inline.textContent = message;
                    const target = errorTarget(field);
                    const placement = target.closest('[data-phone-input-group], [data-address-region-control], [data-address-country-picker], label') || target;
                    placement.insertAdjacentElement('afterend', inline);
                    connect(errorTarget(field), inline);
                    const item = doc.createElement('li');
                    linkToField(item, field, label + ': ' + message);
                    list.appendChild(item);
                });
                if (!list.children.length) return;
                summary.append(heading, list);
                form.prepend(summary);
                summary.focus();
            });
            pending.clear();
        }

        doc.addEventListener('invalid', function (event) {
            const field = event.target;
            if (!field.form || field.form.hasAttribute('data-native-validation')) return;
            event.preventDefault();
            if (!pending.has(field.form)) pending.set(field.form, new Set());
            pending.get(field.form).add(field);
            if (!scheduled) {
                scheduled = true;
                win.setTimeout(showValidationErrors, 0);
            }
        }, true);
        doc.addEventListener('input', function (event) { clearFieldError(event.target); });
        doc.addEventListener('change', function (event) { clearFieldError(event.target); });

        // Existing pages receive focusable summaries without guessing which field failed.
        const summaries = Array.from(doc.querySelectorAll('[data-form-errors], main > .error, [role="main"] > .error, .login-container > .error'));
        summaries.forEach(function (summary) {
            if (summary.hidden || !summary.textContent.trim()) return;
            summary.classList.add('form-error-summary');
            summary.setAttribute('role', 'alert');
            summary.tabIndex = -1;
            summary.querySelectorAll('[data-error-for]').forEach(function (item) {
                const field = doc.getElementById(item.dataset.errorFor);
                if (!field) return;
                const text = item.textContent;
                const inline = doc.createElement('span');
                inline.className = 'form-field-error';
                inline.textContent = text;
                const target = errorTarget(field);
                const placement = target.closest('[data-phone-input-group], [data-address-region-control], [data-address-country-picker], label') || target;
                placement.insertAdjacentElement('afterend', inline);
                connect(target, inline);
                item.replaceChildren();
                linkToField(item, field, text);
            });
        });
        const firstSummary = summaries.find(function (summary) {
            return !summary.hidden && summary.textContent.trim() && !summary.closest('[hidden]');
        });
        if (firstSummary) firstSummary.focus();
        doc.querySelectorAll('.success:not([role])').forEach(function (message) { message.setAttribute('role', 'status'); });

        doc.querySelectorAll('.action-icon-button').forEach(function (control) {
            if (control.querySelector('.action-icon-label') || control.textContent.trim()) return;
            const label = control.dataset.tooltip || control.getAttribute('aria-label') || control.title;
            if (!label) return;
            const text = doc.createElement('span');
            text.className = 'action-icon-label';
            text.setAttribute('aria-hidden', 'true');
            text.textContent = label;
            control.appendChild(text);
        });
    }

    if (typeof module === 'object' && module.exports) module.exports = { connectFieldError, fieldLabel, initialize };
    if (typeof document === 'undefined') return;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initialize(document, window); });
    else initialize(document, window);
})();
