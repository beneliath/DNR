(function () {
    'use strict';

    function formatMilliseconds(value) {
        return Number.isFinite(value) ? `${value < 10 ? value.toFixed(1) : Math.round(value)} ms` : '—';
    }

    function networkAssessment(families) {
        const ipv4 = families.IPv4 || {};
        const ipv6 = families.IPv6 || {};
        if (!ipv4.sample_count && !ipv6.sample_count) {
            return { state: 'pending', status: 'Waiting', title: 'Waiting for Remote Traffic', detail: 'Measurements will appear after public clients load the updated MOED application.' };
        }
        if (!ipv6.sample_count) {
            return { state: 'warning', status: 'IPv4 only', title: 'No Remote IPv6 Sample Has Arrived', detail: `${ipv4.sample_count} IPv4 page load${ipv4.sample_count === 1 ? '' : 's'} measured. This does not establish whether IPv6 is healthy.` };
        }
        if (!ipv4.sample_count) {
            return { state: 'warning', status: 'IPv6 only', title: 'No Remote IPv4 Sample Has Arrived', detail: `${ipv6.sample_count} IPv6 page load${ipv6.sample_count === 1 ? '' : 's'} measured. Both families are needed for comparison.` };
        }
        const difference = ipv6.median_load_ms - ipv4.median_load_ms;
        const meaningfulGap = Math.max(250, ipv4.median_load_ms * 0.5);
        if (difference > meaningfulGap) {
            return { state: 'danger', status: 'IPv6 slower', title: 'Remote IPv6 Page Loads Are Slower', detail: `The median IPv6 page load adds ${formatMilliseconds(difference)} compared with IPv4.` };
        }
        if (-difference > meaningfulGap) {
            return { state: 'warning', status: 'IPv4 slower', title: 'Remote IPv4 Page Loads Are Slower', detail: `The median IPv4 page load adds ${formatMilliseconds(-difference)} compared with IPv6.` };
        }
        return { state: 'success', status: 'Comparable', title: 'Remote IPv4 and IPv6 Loads Are Comparable', detail: 'No material address-family gap appears in the collected MOED page loads.' };
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = { formatMilliseconds, networkAssessment };
    if (typeof document === 'undefined') return;

    const root = document.querySelector('[data-network-diagnostics]');
    if (!root || typeof window.fetch !== 'function') return;
    const refreshButton = root.querySelector('[data-network-refresh]');
    const results = root.querySelector('[data-network-results]');
    let loading = false;

    function setText(selector, value) {
        const element = root.querySelector(selector);
        if (element) element.textContent = value;
    }

    function renderFamily(key, family) {
        const card = root.querySelector(`[data-network-card="${key}"]`);
        const available = family.sample_count > 0;
        if (card) card.dataset.state = available ? 'available' : 'pending';
        setText(`[data-network-state="${key}"]`, available ? 'Measured' : 'Waiting');
        setText(`[data-network-load="${key}"]`, available ? String(Math.round(family.median_load_ms)) : '—');
        setText(`[data-network-samples="${key}"]`, String(family.sample_count || 0));
        setText(`[data-network-p75="${key}"]`, formatMilliseconds(family.p75_load_ms));
        setText(`[data-network-ttfb="${key}"]`, formatMilliseconds(family.median_ttfb_ms));
        setText(`[data-network-colo="${key}"]`, family.top_colo || 'Direct / unknown');
        setText(`[data-contact-images="${key}"]`, formatMilliseconds(family.median_contact_image_max_ms));
        setText(`[data-contact-samples="${key}"]`, family.contact_sample_count
            ? `${family.contact_sample_count} page${family.contact_sample_count === 1 ? '' : 's'}`
            : 'No samples');
    }

    function renderPages(pages) {
        const body = root.querySelector('[data-network-pages]');
        if (!body) return;
        body.replaceChildren();
        if (!pages.length) {
            const row = body.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 5;
            cell.textContent = 'No remote measurements have arrived in the last 24 hours.';
            return;
        }
        pages.forEach(function (page) {
            const row = body.insertRow();
            [page.page_path, formatMilliseconds(page.ipv4_p75_ms), page.ipv4_samples,
                formatMilliseconds(page.ipv6_p75_ms), page.ipv6_samples].forEach(function (value, index) {
                const cell = row.insertCell();
                if (index === 3) {
                    const percentile = document.createElement('span');
                    percentile.className = 'network-ipv6-percentile';
                    percentile.textContent = String(value);
                    cell.appendChild(percentile);
                } else {
                    cell.textContent = String(value);
                }
            });
        });
    }

    function render(payload) {
        renderFamily('ipv4', payload.families.IPv4 || {});
        renderFamily('ipv6', payload.families.IPv6 || {});
        renderPages(payload.pages || []);
        const assessment = networkAssessment(payload.families || {});
        const summary = root.querySelector('[data-network-summary]');
        if (summary) summary.dataset.state = assessment.state;
        setText('[data-network-summary-title]', assessment.title);
        setText('[data-network-summary-detail]', assessment.detail);
        setText('[data-network-status]', assessment.status);
        const generated = new Date(payload.generated_at);
        setText('[data-network-updated]', Number.isNaN(generated.valueOf())
            ? 'Updated just now'
            : `Updated ${generated.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' })}`);
    }

    async function load() {
        if (loading) return;
        loading = true;
        if (refreshButton) {
            refreshButton.disabled = true;
            refreshButton.textContent = 'Refreshing…';
        }
        results?.setAttribute('aria-busy', 'true');
        try {
            const response = await window.fetch(root.dataset.summaryUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`Summary returned ${response.status}`);
            render(await response.json());
        } catch (_) {
            const summary = root.querySelector('[data-network-summary]');
            if (summary) summary.dataset.state = 'danger';
            setText('[data-network-summary-title]', 'Remote Measurements Are Unavailable');
            setText('[data-network-summary-detail]', 'Reload this page after checking the application database and network telemetry endpoint.');
            setText('[data-network-status]', 'Error');
        } finally {
            results?.setAttribute('aria-busy', 'false');
            loading = false;
            if (refreshButton) {
                refreshButton.disabled = false;
                refreshButton.textContent = 'Refresh';
            }
        }
    }

    refreshButton?.addEventListener('click', load);
    window.setInterval(load, 30000);
    load();
})();
