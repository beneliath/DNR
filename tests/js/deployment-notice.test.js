const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { noticePresentation } = require('../../src/assets/js/deployment-notice.js');
const notice = { id: 'a'.repeat(32), phase: 'pending', started_at: 1000, not_before: 1300, expires_at: 2000 };

test('shared countdown is normal, bounded, and never restarts for a newly opened page', () => {
    assert.equal(noticePresentation(notice, 1000).timer, '5:00');
    assert.equal(noticePresentation(notice, 1120).timer, '3:00');
    assert.equal(noticePresentation(notice, 1299.1).timer, '0:01');
    assert.equal(noticePresentation(notice, 900).timer, '5:00');
});

test('zero and slow preparation say pending until the server actually begins deployment', () => {
    for (const now of [1300, 1480]) {
        assert.equal(noticePresentation(notice, now).title, 'Update pending');
        assert.equal(noticePresentation(notice, now).timer, '');
    }
    assert.equal(noticePresentation({ ...notice, phase: 'deploying' }, 1480).title, 'Deployment in progress');
});

test('cancelled, completed, and stale notices clear; failed maintenance stays visible', () => {
    assert.equal(noticePresentation(null, 1100), null);
    assert.equal(noticePresentation(notice, 2000), null);
    for (const phase of ['cancelled', 'complete']) assert.equal(noticePresentation({ ...notice, phase }, 1100), null);
    assert.equal(noticePresentation({ ...notice, phase: 'failed' }, 3000).title, 'Update needs attention');
});

test('browser polling updates the banner without credentials, preserves it on outage, and clears it on cancellation', async () => {
    const nodes = Object.fromEntries(['title', 'detail', 'timer'].map(key => [key, { textContent: '', hidden: false }]));
    const banner = { hidden: true, offsetHeight: 74, dataset: { statusUrl: 'deployment_status.php' },
        querySelector: selector => nodes[selector.match(/data-deployment-(\w+)/)[1]] };
    const events = {};
    const intervals = [];
    const classes = new Set();
    const styles = {};
    let payload = { server_now: 1120, notice };
    let fail = false;
    const context = {
        document: { hidden: false, querySelector: () => banner,
            body: { classList: { toggle: (key, value) => value ? classes.add(key) : classes.delete(key) },
                style: { setProperty: (key, value) => { styles[key] = value; } } },
            addEventListener: (key, callback) => { events[key] = callback; } },
        window: { setInterval: callback => intervals.push(callback), setTimeout: () => 1, clearTimeout: () => {},
            addEventListener: (key, callback) => { events[key] = callback; } },
        AbortController, Date,
        fetch: async (url, options) => {
            assert.equal(url, 'deployment_status.php');
            assert.equal(options.credentials, 'omit');
            assert.equal(options.cache, 'no-store');
            if (fail) throw new Error('offline');
            return { ok: true, json: async () => payload };
        },
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/deployment-notice.js'), 'utf8'), context);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(banner.hidden, false);
    assert.equal(nodes.timer.textContent, '3:00');
    assert.equal(styles['--deployment-banner-height'], '74px');
    fail = true;
    await events.online();
    assert.equal(banner.hidden, false);
    assert.equal(nodes.title.textContent, 'Scheduled update in');
    fail = false;
    payload = { server_now: 1400, notice: null };
    await events.pageshow();
    assert.equal(banner.hidden, true);
    assert.equal(classes.has('deployment-notice-active'), false);
    assert.equal(styles['--deployment-banner-height'], '0px');
});
