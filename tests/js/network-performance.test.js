const test = require('node:test');
const assert = require('node:assert/strict');
const {
    roundTiming,
    pagePerformanceSample,
    encodeNetworkPerformanceSample,
} = require('../../src/assets/js/network-performance.js');

test('collector derives navigation and same-origin image timing without an address field', () => {
    const entries = {
        navigation: [{ responseStart: 48.26, domContentLoadedEventEnd: 140.04, loadEventEnd: 390.18 }],
        resource: [
            { initiatorType: 'img', name: 'https://moed.example/contact_photo.php?id=1', duration: 820.14 },
            { initiatorType: 'img', name: 'https://moed.example/assets/logo.png', duration: 25.27 },
            { initiatorType: 'img', name: 'https://cdn.example/ignored.png', duration: 900 },
            { initiatorType: 'script', name: 'https://moed.example/app.js', duration: 42 },
        ],
    };
    const sample = pagePerformanceSample(
        { getEntriesByType: type => entries[type] || [], now: () => 999 },
        { href: 'https://moed.example/contacts.php?status=active', origin: 'https://moed.example', pathname: '/contacts.php' }
    );

    assert.deepEqual(sample, {
        page_path: '/contacts.php',
        ttfb_ms: 48.3,
        dom_content_loaded_ms: 140,
        load_ms: 390.2,
        image_count: 2,
        image_total_ms: 845.4,
        image_max_ms: 820.1,
        contact_image_count: 1,
        contact_image_total_ms: 820.1,
        contact_image_max_ms: 820.1,
    });
    assert.equal(Object.hasOwn(sample, 'address_family'), false);
    assert.equal(Object.hasOwn(sample, 'client_ip'), false);
});

test('collector validates missing navigation data and encodes the CSRF token', () => {
    assert.equal(pagePerformanceSample({ getEntriesByType: () => [] }, { href: '', origin: '', pathname: '' }), null);
    assert.equal(roundTiming(-2), 0);
    assert.equal(roundTiming(Number.POSITIVE_INFINITY), 0);
    const body = encodeNetworkPerformanceSample({ page_path: '/dashboard.php', load_ms: 12.3 }, 'csrf-value');
    assert.equal(body.get('page_path'), '/dashboard.php');
    assert.equal(body.get('load_ms'), '12.3');
    assert.equal(body.get('csrf_token'), 'csrf-value');
});
