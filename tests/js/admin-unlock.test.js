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

function createBrowser(serverNow = 1000, request = async () => { throw new Error('Offline'); }) {
    const timer = { textContent: '' };
    const button = { disabled: false };
    const error = { hidden: true, textContent: '' };
    const csrf = { value: 'original-token' };
    let submit;
    const form = { action: 'admin_lock.php', querySelector: selector => ({
        button, '[data-admin-lock-error]': error, '[name="csrf_token"]': csrf
    }[selector]), addEventListener: (name, callback) => { submit = callback; } };
    const banner = { hidden: true, offsetHeight: 68,
        dataset: { expiresAt: '1300', serverNow: String(serverNow) },
        querySelector: selector => selector === '[data-admin-unlock-timer]' ? timer : form };
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
        fetch: request, URLSearchParams,
        FormData: class { constructor() { return [['csrf_token', csrf.value]]; } },
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/admin-unlock.js'), 'utf8'), context);
    return { banner, timer, button, error, csrf, classes, styles, events, tick: () => tick(),
        lock: () => submit({ preventDefault() {} }),
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

test('early lock posts the session token, then removes the banner and its spacing', async () => {
    let resolve;
    const requests = [];
    const page = createBrowser(1000, (url, options) => {
        requests.push({ url, options });
        return new Promise(done => { resolve = done; });
    });
    const pending = page.lock();
    assert.equal(page.button.disabled, true);
    assert.equal(page.banner.hidden, false, 'keep the banner until the server confirms the lock');
    await page.lock();
    assert.equal(requests.length, 1, 'ignore duplicate clicks while locking');
    assert.equal(requests[0].url, 'admin_lock.php');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.body.get('csrf_token'), 'original-token');
    resolve({ ok: true, json: async () => ({ locked: true }) });
    await pending;
    assert.equal(page.banner.hidden, true);
    assert.equal(page.classes.has('admin-unlock-active'), false);
    assert.equal(page.styles['--admin-unlock-banner-height'], '0px');
    assert.equal(page.button.disabled, false);
});

test('a failed lock keeps the countdown visible and offers an inline retry error', async () => {
    const page = createBrowser(1000, async () => ({ ok: false }));
    await page.lock();
    assert.equal(page.banner.hidden, false);
    assert.equal(page.button.disabled, false);
    assert.equal(page.error.hidden, false);
    assert.match(page.error.textContent, /Please try again/);
});

test('returning to a tab synchronizes early lock and token rotation from the current session', async () => {
    let state = { unlocked: true, expires_at: 1400, server_now: 1200, csrf_token: 'rotated-token' };
    const page = createBrowser(1000, async () => ({ ok: true, json: async () => state }));
    await page.events.pageshow();
    assert.equal(page.csrf.value, 'rotated-token');
    assert.equal(page.timer.textContent, '3:20');
    state = { unlocked: false, expires_at: null, server_now: 1201, csrf_token: null };
    await page.events.visibilitychange();
    assert.equal(page.banner.hidden, true);
    assert.equal(page.styles['--admin-unlock-banner-height'], '0px');
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
