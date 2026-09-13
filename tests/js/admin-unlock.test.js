const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { adminUnlockCountdown } = require('../../src/assets/js/admin-unlock.js');

test('countdown uses the existing deadline across navigation and expires at zero', () => {
    assert.equal(adminUnlockCountdown(1300, 1000), '5:00');
    assert.equal(adminUnlockCountdown(1300, 1125), '2:55');
    assert.equal(adminUnlockCountdown(1300, 1299.2), '0:01');
    assert.equal(adminUnlockCountdown(1300, 1300), '');
    assert.equal(adminUnlockCountdown(1300, 1500), '');
    assert.equal(adminUnlockCountdown(1300, 900), '5:00');
    assert.equal(adminUnlockCountdown(NaN, 1000), '');
    assert.equal(adminUnlockCountdown(1300, NaN), '');
});

function createBrowser(serverNow = 1000) {
    const timer = { textContent: '' };
    const banner = { hidden: true, offsetHeight: 68,
        dataset: { expiresAt: '1300', serverNow: String(serverNow) }, querySelector: () => timer };
    const events = {};
    const classes = new Set();
    const styles = {};
    let now = 9_000_000; // The device clock intentionally differs from the server's.
    let tick;
    let cleared = false;
    const context = {
        document: { querySelector: () => banner,
            body: { classList: { toggle: (key, value) => value ? classes.add(key) : classes.delete(key) },
                style: { setProperty: (key, value) => { styles[key] = value; } } },
            addEventListener: (key, callback) => { events[key] = callback; } },
        window: { setInterval: callback => { tick = callback; return 1; },
            clearInterval: () => { cleared = true; },
            addEventListener: (key, callback) => { events[key] = callback; } },
        Date: { now: () => now },
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/admin-unlock.js'), 'utf8'), context);
    return { banner, timer, classes, styles, events, tick: () => tick(),
        advance: milliseconds => { now += milliseconds; }, cleared: () => cleared };
}

test('browser renders, remeasures wrapped banners, and removes the bar and spacing at expiry', () => {
    const page = createBrowser();
    assert.equal(page.banner.hidden, false);
    assert.equal(page.timer.textContent, '5:00');
    assert.equal(page.classes.has('admin-unlock-active'), true);
    assert.equal(page.styles['--admin-unlock-banner-height'], '68px');
    page.advance(1000);
    page.tick();
    assert.equal(page.timer.textContent, '4:59');
    page.banner.offsetHeight = 94;
    page.events.resize();
    assert.equal(page.styles['--admin-unlock-banner-height'], '94px');
    page.advance(299000);
    page.tick();
    assert.equal(page.banner.hidden, true);
    assert.equal(page.timer.textContent, '');
    assert.equal(page.classes.has('admin-unlock-active'), false);
    assert.equal(page.styles['--admin-unlock-banner-height'], '0px');
    assert.equal(page.cleared(), true);
});

test('background tabs and restored pages use elapsed time instead of counting timer callbacks', () => {
    for (const event of ['visibilitychange', 'pageshow']) {
        const page = createBrowser(1125);
        assert.equal(page.timer.textContent, '2:55');
        page.advance(176000);
        page.events[event]();
        assert.equal(page.banner.hidden, true);
        assert.equal(page.classes.has('admin-unlock-active'), false);
    }
    assert.equal(createBrowser(1300).banner.hidden, true);
});
