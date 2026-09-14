(function () {
    'use strict';

    function roundTiming(value) {
        return Number.isFinite(value) ? Math.round(Math.max(0, value) * 10) / 10 : 0;
    }

    function pagePerformanceSample(performanceObject, locationObject) {
        if (!performanceObject || typeof performanceObject.getEntriesByType !== 'function') return null;
        const navigation = performanceObject.getEntriesByType('navigation')[0];
        if (!navigation) return null;

        const images = performanceObject.getEntriesByType('resource').filter(function (entry) {
            if (entry.initiatorType !== 'img') return false;
            try {
                return new URL(entry.name, locationObject.href).origin === locationObject.origin;
            } catch (_) {
                return false;
            }
        });
        const contactImages = images.filter(function (entry) {
            try {
                return new URL(entry.name, locationObject.href).pathname.endsWith('/contact_photo.php');
            } catch (_) {
                return false;
            }
        });
        const total = function (entries) {
            return entries.reduce(function (sum, entry) { return sum + entry.duration; }, 0);
        };
        const maximum = function (entries) {
            return entries.reduce(function (largest, entry) { return Math.max(largest, entry.duration); }, 0);
        };

        return {
            page_path: locationObject.pathname,
            ttfb_ms: roundTiming(navigation.responseStart),
            dom_content_loaded_ms: roundTiming(navigation.domContentLoadedEventEnd),
            load_ms: roundTiming(navigation.loadEventEnd || performanceObject.now()),
            image_count: images.length,
            image_total_ms: roundTiming(total(images)),
            image_max_ms: roundTiming(maximum(images)),
            contact_image_count: contactImages.length,
            contact_image_total_ms: roundTiming(total(contactImages)),
            contact_image_max_ms: roundTiming(maximum(contactImages)),
        };
    }

    function encodeNetworkPerformanceSample(sample, csrfToken) {
        const body = new URLSearchParams();
        Object.entries(sample).forEach(function (entry) { body.set(entry[0], String(entry[1])); });
        body.set('csrf_token', csrfToken);
        return body;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { roundTiming, pagePerformanceSample, encodeNetworkPerformanceSample };
    }
    if (typeof document === 'undefined' || typeof window === 'undefined') return;

    const configuration = document.querySelector('[data-network-performance]');
    if (!configuration || !configuration.dataset.endpoint || !configuration.dataset.csrfToken) return;
    let submitted = false;

    function submit() {
        if (submitted) return;
        const sample = pagePerformanceSample(window.performance, window.location);
        if (!sample) return;
        submitted = true;
        const body = encodeNetworkPerformanceSample(sample, configuration.dataset.csrfToken);
        if (typeof navigator.sendBeacon === 'function' && navigator.sendBeacon(configuration.dataset.endpoint, body)) {
            return;
        }
        if (typeof window.fetch === 'function') {
            window.fetch(configuration.dataset.endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                keepalive: true,
                headers: { Accept: 'application/json' },
            }).catch(function () {});
        }
    }

    if (document.readyState === 'complete') {
        window.setTimeout(submit, 0);
    } else {
        window.addEventListener('load', function () { window.setTimeout(submit, 0); }, { once: true });
    }
})();
