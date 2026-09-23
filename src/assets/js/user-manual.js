(function () {
    function normalize(value) {
        return String(value || '').toLocaleLowerCase().normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9#]+/g, ' ').trim();
    }

    function queryTerms(value) {
        return Array.from(new Set(normalize(value).split(/\s+/).filter(Boolean)));
    }

    function matchesWord(word, term) {
        return word === term || (term.length > 3 && word.startsWith(term));
    }

    function matchingRanges(value, terms) {
        const ranges = [];
        for (const word of value.matchAll(/[\p{L}\p{N}#]+/gu)) {
            if (terms.some(function (term) { return matchesWord(normalize(word[0]), term); })) {
                ranges.push([word.index, word.index + word[0].length]);
            }
        }
        return ranges;
    }

    function searchTopics(topics, query) {
        const terms = queryTerms(query);
        if (!terms.length) return [];
        const phrase = normalize(query);
        return topics.map(function (topic, order) {
            const title = normalize(topic.title);
            const titleWords = title.split(' ');
            const words = queryTerms(topic.title + ' ' + topic.text);
            const matches = terms.every(function (term) {
                return words.some(function (word) { return matchesWord(word, term); });
            });
            // Exact headings lead, followed by heading phrases, then body matches.
            const score = (title === phrase ? 100 : 0) + ((' ' + title).includes(' ' + phrase) ? 50 : 0)
                + terms.filter(function (term) {
                    return titleWords.some(function (word) { return matchesWord(word, term); });
                }).length;
            return { topic: topic, score: score, order: order, matches: matches };
        }).filter(function (result) { return result.matches; })
            .sort(function (a, b) { return b.score - a.score || a.order - b.order; })
            .map(function (result) { return result.topic; });
    }

    function snippet(value, terms) {
        const text = value.replace(/\s+/g, ' ').trim();
        const first = matchingRanges(text, terms)[0];
        let start = first ? Math.max(0, first[0] - 65) : 0;
        if (start > 0) start = text.lastIndexOf(' ', start) + 1;
        let end = Math.min(text.length, start + 220);
        if (end < text.length) {
            const boundary = text.lastIndexOf(' ', end);
            if (boundary > start) end = boundary;
        }
        return (start > 0 ? '…' : '') + text.slice(start, end) + (end < text.length ? '…' : '');
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { normalize, queryTerms, matchingRanges, searchTopics, snippet };
    }
    if (typeof document === 'undefined') return;

    const form = document.querySelector('[data-manual-search-form]');
    const input = document.querySelector('[data-manual-search]');
    const clearButton = document.querySelector('[data-manual-clear]');
    const emptyClearButton = document.querySelector('[data-manual-empty-clear]');
    const status = document.querySelector('[data-manual-status]');
    const emptyState = document.querySelector('[data-manual-empty]');
    const resultsPanel = document.querySelector('[data-manual-results]');
    const resultList = document.querySelector('[data-manual-result-list]');
    const sections = Array.from(document.querySelectorAll('[data-manual-section]'));
    const tocLinks = Array.from(document.querySelectorAll('[data-manual-toc]'));
    if (!form || !input || !clearButton || !status || !emptyState || !resultsPanel || !resultList || !sections.length) return;

    const topics = [];
    const highlights = [];
    let disclosureState = null;
    let matches = [];
    sections.forEach(function (section) {
        // Each heading or FAQ starts a topic. Keep text nodes so highlights can be
        // removed without replacing links, disclosure controls, or their listeners.
        const walker = document.createTreeWalker(section, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        let topic = null;
        let node;
        while ((node = walker.nextNode())) {
            const element = node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement;
            if (element.closest('[aria-hidden="true"], script, style, svg, button')) continue;
            if (node.nodeType === Node.ELEMENT_NODE && node.matches('h2, h3, h4, summary')) {
                const title = (node.querySelector('span') || node).textContent.trim();
                if (!node.id) {
                    const base = 'manual-topic-' + section.id + '-' + normalize(title).replace(/ /g, '-');
                    let id = base;
                    let suffix = 2;
                    while (document.getElementById(id)) id = base + '-' + suffix++;
                    node.id = id;
                }
                topic = { title: title, heading: node, section: section, nodes: [], text: '', parts: [] };
                topics.push(topic);
            } else if (topic && node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                topic.nodes.push(node);
                if (!topic.heading.contains(node)) topic.parts.push(node.textContent.trim());
            }
        }
    });
    topics.forEach(function (topic) {
        topic.text = topic.parts.join(' ');
        delete topic.parts;
    });

    function highlightedText(value, terms) {
        const fragment = document.createDocumentFragment();
        let cursor = 0;
        matchingRanges(value, terms).forEach(function (range) {
            fragment.append(document.createTextNode(value.slice(cursor, range[0])));
            const mark = document.createElement('mark');
            mark.className = 'manual-search-match';
            mark.textContent = value.slice(range[0], range[1]);
            fragment.append(mark);
            cursor = range[1];
        });
        fragment.append(document.createTextNode(value.slice(cursor)));
        return fragment;
    }

    function openAncestors(element) {
        let details = element.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement.closest('details');
        }
    }

    function setActiveToc(id) {
        tocLinks.forEach(function (link) {
            const active = link.getAttribute('href') === '#' + id;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }

    function jumpToTopic(topic) {
        openAncestors(topic.heading);
        setActiveToc(topic.section.id);
        topic.heading.setAttribute('tabindex', '-1');
        topic.heading.focus({ preventScroll: true });
        topic.heading.scrollIntoView({ block: 'start' });
        window.history.replaceState(null, '', '#' + topic.heading.id);
    }

    function applySearch() {
        const terms = queryTerms(input.value);
        while (highlights.length) {
            const highlight = highlights.pop();
            highlight.wrapper.replaceWith(highlight.node);
        }
        if (terms.length && !disclosureState) {
            disclosureState = new Map(Array.from(document.querySelectorAll('[data-manual-section] details'))
                .map(function (details) { return [details, details.open]; }));
        }
        if (disclosureState) disclosureState.forEach(function (open, details) { details.open = open; });
        if (!terms.length) disclosureState = null;

        matches = searchTopics(topics, input.value);
        const visibleSections = new Set(matches.map(function (topic) { return topic.section; }));
        sections.forEach(function (section) { section.hidden = terms.length > 0 && !visibleSections.has(section); });
        tocLinks.forEach(function (link) {
            const section = document.getElementById(link.getAttribute('href').slice(1));
            link.hidden = Boolean(section && section.hidden);
        });

        resultList.replaceChildren();
        matches.forEach(function (topic) {
            openAncestors(topic.heading);
            topic.nodes.forEach(function (node) {
                if (!matchingRanges(node.textContent, terms).length) return;
                const wrapper = document.createElement('span');
                wrapper.append(highlightedText(node.textContent, terms));
                node.replaceWith(wrapper);
                highlights.push({ wrapper: wrapper, node: node });
            });
            const item = document.createElement('li');
            const chapter = document.createElement('small');
            chapter.textContent = topic.section.querySelector('h2').textContent;
            const link = document.createElement('a');
            link.href = '#' + topic.heading.id;
            link.append(highlightedText(topic.title, terms));
            link.addEventListener('click', function (event) {
                event.preventDefault();
                jumpToTopic(topic);
            });
            const excerpt = document.createElement('p');
            excerpt.append(highlightedText(snippet(topic.text, terms), terms));
            item.append(chapter, link, excerpt);
            resultList.append(item);
        });

        clearButton.hidden = input.value === '';
        resultsPanel.hidden = matches.length === 0;
        emptyState.hidden = !terms.length || matches.length > 0;
        if (!terms.length) {
            status.textContent = 'Showing all ' + sections.length + ' chapters.';
        } else if (!matches.length) {
            status.textContent = 'No topics match “' + input.value.trim() + '”.';
        } else {
            status.textContent = matches.length + ' matching topic' + (matches.length === 1 ? '' : 's')
                + ' in ' + visibleSections.size + ' chapter' + (visibleSections.size === 1 ? '' : 's') + '.';
        }
    }

    function clearSearch(shouldFocus) {
        input.value = '';
        applySearch();
        if (shouldFocus) input.focus();
    }

    // The coach passes a validated manual ID, never a model-generated selector.
    document.addEventListener('moed:manual-topic', function (event) {
        const topic = topics.find(function (item) { return item.heading.id === event.detail; });
        if (!topic) return;
        clearSearch(false);
        document.querySelectorAll('.coach-topic-highlight').forEach(function (node) { node.classList.remove('coach-topic-highlight'); });
        topic.heading.classList.add('coach-topic-highlight');
        jumpToTopic(topic);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (matches.length) jumpToTopic(matches[0]);
    });
    input.addEventListener('input', applySearch);
    clearButton.addEventListener('click', function () { clearSearch(true); });
    if (emptyClearButton) emptyClearButton.addEventListener('click', function () { clearSearch(true); });

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
        link.addEventListener('click', function () { setActiveToc(link.getAttribute('href').slice(1)); });
    });
    document.querySelectorAll('.manual-page-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            const visible = sections.filter(function (section) { return !section.hidden; });
            const section = link.getAttribute('href') === '#manual-top' ? visible[0] : visible[visible.length - 1];
            if (section) setActiveToc(section.id);
        });
    });
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            const visible = entries
                .filter(function (entry) { return entry.isIntersecting && !entry.target.hidden; })
                .sort(function (left, right) { return left.boundingClientRect.top - right.boundingClientRect.top; });
            if (visible.length) setActiveToc(visible[0].target.id);
        }, { rootMargin: '-12% 0px -74% 0px', threshold: [0, 0.1] });
        sections.forEach(function (section) { observer.observe(section); });
    }

    const hashTarget = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
    const hashSection = hashTarget && hashTarget.closest('[data-manual-section]');
    if (hashTarget) openAncestors(hashTarget);
    setActiveToc(hashSection ? hashSection.id : sections[0].id);
    applySearch();
    if (hashSection) {
        // Wait until the browser has restored its scroll position on navigation.
        window.addEventListener('pageshow', function () {
            window.requestAnimationFrame(function () { hashTarget.scrollIntoView({ block: 'start' }); });
        }, { once: true });
    }
})();
