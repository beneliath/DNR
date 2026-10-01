(function () {
    'use strict';

    function formatMilliseconds(value) {
        return Number.isFinite(value) ? `${value < 10 ? value.toFixed(1) : Math.round(value)} ms` : '—';
    }

    function networkAssessment(families, coverage = {}) {
        if (coverage.truncated) {
            const days = coverage.window_days || 1;
            return { state: 'warning', status: 'Partial window', title: 'Recent Measurements Only', detail: `More than 5,000 measurements arrived in ${days} day${days === 1 ? '' : 's'}. These results cover only the newest samples; a missing address family may have earlier page-load measurements.` };
        }
        const ipv4 = families.IPv4 || {};
        const ipv6 = families.IPv6 || {};
        if (!ipv4.sample_count && !ipv6.sample_count) {
            return { state: 'pending', status: 'Waiting', title: 'No Browser Page-Load Measurements', detail: 'Page-load measurements require a signed-in browser using a public client address. Document downloads are shown separately. Missing measurements do not indicate a connectivity failure.' };
        }
        if (!ipv6.sample_count) {
            return { state: 'warning', status: 'IPv4 page measurements only', title: 'No IPv6 Browser Page-Load Measurements', detail: `${ipv4.sample_count} IPv4 page load${ipv4.sample_count === 1 ? '' : 's'} measured. No signed-in IPv6 browser page-load measurements are available in this window. IPv6 document traffic may still appear below. This does not establish whether IPv6 is healthy.` };
        }
        if (!ipv4.sample_count) {
            return { state: 'warning', status: 'IPv6 page measurements only', title: 'No IPv4 Browser Page-Load Measurements', detail: `${ipv6.sample_count} IPv6 page load${ipv6.sample_count === 1 ? '' : 's'} measured. No signed-in IPv4 browser page-load measurements are available in this window. IPv4 document traffic may still appear below. Missing measurements do not indicate a connectivity failure.` };
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
        setText(`[data-network-state="${key}"]`, available ? 'Measured' : 'No page measurements');
        setText(`[data-network-load="${key}"]`, available ? String(Math.round(family.median_load_ms)) : '—');
        setText(`[data-network-samples="${key}"]`, String(family.sample_count || 0));
        setText(`[data-network-p75="${key}"]`, formatMilliseconds(family.p75_load_ms));
        setText(`[data-network-ttfb="${key}"]`, formatMilliseconds(family.median_ttfb_ms));
        setText(`[data-network-colo="${key}"]`, available ? (family.top_colo || 'Direct / unknown') : 'No page measurements');
        setText(`[data-contact-images="${key}"]`, formatMilliseconds(family.median_contact_image_max_ms));
        setText(`[data-contact-samples="${key}"]`, family.contact_sample_count
            ? `${family.contact_sample_count} page${family.contact_sample_count === 1 ? '' : 's'}`
            : 'No samples');
    }

    function renderPages(pages, days) {
        const body = root.querySelector('[data-network-pages]');
        if (!body) return;
        body.replaceChildren();
        if (!pages.length) {
            const row = body.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 5;
            cell.textContent = `No browser page-load measurements are available for the last ${days} day${days === 1 ? '' : 's'}.`;
            return;
        }
        pages.forEach(function (page) {
            const row = body.insertRow();
            [page.page_path, formatMilliseconds(page.ipv4_p75_ms), page.ipv4_samples,
                formatMilliseconds(page.ipv6_p75_ms), page.ipv6_samples].forEach(function (value) {
                const cell = row.insertCell();
                cell.textContent = String(value);
            });
        });
    }

    function renderDownloads(downloads) {
        const body = root.querySelector('[data-network-downloads]');
        if (!body) return;
        body.replaceChildren();
        ['pdf', 'ppt', 'pptx'].forEach(function (type) {
            ['IPv4', 'IPv6'].forEach(function (family) {
                const sample = downloads[type]?.[family] || {};
                const row = body.insertRow();
                [`${type.toUpperCase()} / ${family}`, formatMilliseconds(sample.median_duration_ms),
                    formatMilliseconds(sample.p75_duration_ms), formatMilliseconds(sample.median_preparation_ms),
                    Number.isFinite(sample.median_response_bytes) ? `${(sample.median_response_bytes / 1024).toFixed(1)} KiB` : '—',
                    `${sample.sample_count || 0} (${sample.partial_sample_count || 0})`, sample.top_colo || '—'
                ].forEach(function (value) {
                    row.insertCell().textContent = String(value);
                });
            });
        });
    }

    function render(payload) {
        renderFamily('ipv4', payload.families.IPv4 || {});
        renderFamily('ipv6', payload.families.IPv6 || {});
        renderPages(payload.pages || [], payload.coverage?.window_days || 1);
        renderDownloads(payload.downloads || {});
        const assessment = networkAssessment(payload.families || {}, payload.coverage || {});
        const summary = root.querySelector('[data-network-summary]');
        if (summary) summary.dataset.state = assessment.state;
        setText('[data-network-summary-title]', assessment.title);
        setText('[data-network-summary-detail]', assessment.detail);
        setText('[data-network-status]', assessment.status);
        const coverage = payload.coverage || {};
        setText('[data-network-coverage]', coverage.from && coverage.through
            ? `${coverage.count} measurements from ${new Date(coverage.from).toLocaleString()} through ${new Date(coverage.through).toLocaleString()}${coverage.truncated ? ' (newest 5,000 only)' : ''}.`
            : 'No measurements in the selected window.');
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
