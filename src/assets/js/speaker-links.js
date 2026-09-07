(function () {
    'use strict';
    document.querySelectorAll('[data-speaker-custom-links]').forEach(function (section) {
        const rows = section.querySelector('[data-custom-link-rows]');
        const template = section.querySelector('[data-custom-link-template]');
        const add = section.querySelector('[data-add-custom-link]');
        const status = section.querySelector('[data-custom-link-status]');
        const maximum = Number(section.dataset.maxLinks);
        let nextIndex = rows.children.length;

        function update() {
            add.disabled = rows.children.length >= maximum;
            rows.querySelectorAll('[data-custom-link-row]').forEach(function (row) {
                const label = row.querySelector('[data-custom-link-label]');
                const url = row.querySelector('[data-custom-link-url]');
                const required = Boolean(label.value.trim() || url.value.trim() || row.querySelector('[data-custom-link-key]').value);
                label.required = required;
                url.required = required;
                row.querySelector('[data-remove-custom-link]').setAttribute('aria-label', 'Remove ' + (label.value.trim() || 'custom link'));
            });
        }

        add.addEventListener('click', function () {
            if (rows.children.length >= maximum) return;
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name], [id], [for]').forEach(function (element) {
                ['name', 'id', 'for'].forEach(function (attribute) {
                    if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(nextIndex)));
                });
            });
            nextIndex += 1;
            rows.appendChild(fragment);
            update();
            rows.lastElementChild.querySelector('[data-custom-link-label]').focus();
            status.textContent = 'Custom link added. Save the speaker to apply changes.';
        });
        rows.addEventListener('click', function (event) {
            const remove = event.target.closest('[data-remove-custom-link]');
            if (!remove) return;
            remove.closest('[data-custom-link-row]').remove();
            update();
            add.focus();
            status.textContent = 'Custom link removed. Published links and statistics are retained. Save the speaker to apply changes.';
        });
        rows.addEventListener('input', update);
        update();
    });
}());
