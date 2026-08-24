(function () {
    const form = document.querySelector('[data-manual-search-form]');
    const input = document.querySelector('[data-manual-search]');
    const clearButton = document.querySelector('[data-manual-clear]');
    const emptyClearButton = document.querySelector('[data-manual-empty-clear]');
    const status = document.querySelector('[data-manual-status]');
    const emptyState = document.querySelector('[data-manual-empty]');
    const sections = Array.from(document.querySelectorAll('[data-manual-section]'));
    const tocLinks = Array.from(document.querySelectorAll('[data-manual-toc]'));

    if (!form || !input || !clearButton || !status || !emptyState || sections.length === 0) return;

    const normalize = function (value) {
        return String(value || '')
            .toLocaleLowerCase()
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, ' ')
            .replace(/[^a-z0-9#]+/g, ' ')
            .trim();
    };

    const searchDocuments = new Map();
    sections.forEach(function (section) {
        searchDocuments.set(section.id, normalize(
            section.textContent + ' ' + String(section.dataset.keywords || '')
        ));
    });

    function setActiveToc(id) {
        tocLinks.forEach(function (link) {
            const active = link.getAttribute('href') === '#' + id;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }

    function applySearch() {
        const query = normalize(input.value);
        const terms = query === '' ? [] : query.split(/\s+/).filter(Boolean);
        let visibleCount = 0;

        sections.forEach(function (section) {
            const documentText = searchDocuments.get(section.id) || '';
            const matches = terms.length === 0 || terms.every(function (term) {
                return documentText.includes(term);
            });
            section.hidden = !matches;
            if (matches) visibleCount += 1;
        });

        tocLinks.forEach(function (link) {
            const target = String(link.getAttribute('href') || '').replace(/^#/, '');
            const section = document.getElementById(target);
            link.hidden = Boolean(section && section.hidden);
        });

        clearButton.hidden = query === '';
        emptyState.hidden = visibleCount !== 0;
        if (query === '') {
            status.textContent = 'Showing all ' + sections.length + ' chapters.';
        } else if (visibleCount === 0) {
            status.textContent = 'No chapters match “' + input.value.trim() + '”.';
        } else {
            status.textContent = visibleCount + ' chapter' + (visibleCount === 1 ? '' : 's')
                + ' match “' + input.value.trim() + '”.';
        }
    }

    function clearSearch(shouldFocus) {
        input.value = '';
        applySearch();
        if (shouldFocus) input.focus();
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
    });
    input.addEventListener('input', applySearch);
    clearButton.addEventListener('click', function () { clearSearch(true); });
    if (emptyClearButton) {
        emptyClearButton.addEventListener('click', function () { clearSearch(true); });
    }

    document.addEventListener('keydown', function (event) {
        const target = event.target;
        const isTyping = target instanceof HTMLElement
            && (target.matches('input, textarea, select') || target.isContentEditable);
        if (event.key === '/' && !isTyping && !event.metaKey && !event.ctrlKey && !event.altKey) {
            event.preventDefault();
            input.focus();
        } else if (event.key === 'Escape' && document.activeElement === input && input.value !== '') {
            clearSearch(true);
        }
    });

    tocLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            setActiveToc(String(link.getAttribute('href') || '').replace(/^#/, ''));
        });
    });

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            const visible = entries
                .filter(function (entry) { return entry.isIntersecting && !entry.target.hidden; })
                .sort(function (left, right) { return left.boundingClientRect.top - right.boundingClientRect.top; });
            if (visible.length > 0) setActiveToc(visible[0].target.id);
        }, { rootMargin: '-12% 0px -74% 0px', threshold: [0, 0.1] });
        sections.forEach(function (section) { observer.observe(section); });
    }

    const hashTarget = window.location.hash
        ? document.getElementById(window.location.hash.replace(/^#/, ''))
        : null;
    setActiveToc(hashTarget && hashTarget.matches('[data-manual-section]') ? hashTarget.id : sections[0].id);
    applySearch();
})();
