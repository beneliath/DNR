const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const script = fs.readFileSync('src/assets/js/maintenance-timer.js', 'utf8');
const complete = {server_now: 1000, notice: null, deployment_complete: true};
const response = (body, ok = true) => ({ok, json: async () => body});

async function fixture({seconds = 80, waiting = true, prefix = '/a/shalom-in-messiah'} = {}) {
    let now = 0, responder = async () => response({...complete, notice: {phase: 'deploying'}});
    const events = new Map();
    const intervals = new Map(), timeouts = new Map(), requests = [], navigations = [];
    const classes = new Set(), attributes = {};
    const timer = seconds === null ? null : {dataset: {remainingSeconds: String(seconds)},
        textContent: '', classList: {add: value => classes.add(value)},
        setAttribute: (key, value) => { attributes[key] = value; }};
    const detail = {textContent: ''};
    const page = {dataset: {statusUrl: prefix + '/deployment_status.php', readyUrl: prefix + '/ready.php',
        waitForDeployment: String(waiting)}};
    const href = 'https://moed.test' + prefix + '/audience_collect_public.php?key=fixture&choice=2#form';
    vm.runInNewContext(script, {
        document: {hidden: false, addEventListener: (name, fn) => events.set(name, fn), querySelector: selector => ({'[data-maintenance-page]': page,
            '[data-maintenance-timer]': timer, '[data-maintenance-estimate]': detail})[selector] || null},
        performance: {now: () => now}, AbortController,
        fetch: async (url, options) => { requests.push({url, options}); return responder(url, options); },
        window: {
            addEventListener: (name, fn) => events.set(name, fn),
            setInterval: (fn, delay) => { intervals.set(delay, fn); return delay; },
            clearInterval: id => intervals.delete(id),
            setTimeout: (fn, delay) => { timeouts.set(delay, fn); return delay; },
            clearTimeout: id => timeouts.delete(id),
            location: {href, replace: url => navigations.push(url), reload: () => {
                assert.fail('Recovery must use GET navigation, never reload a possible POST');
            }},
        },
    });
    await new Promise(setImmediate); // Let the initial recovery check finish.
    assert.equal(requests.length, 1, 'Check immediately, before the first 30-second interval');
    requests.length = 0;
    return {timer, detail, classes, attributes, requests, navigations, href, intervals, timeouts,
        respond: fn => { responder = fn; },
        tick: elapsed => { now = elapsed; intervals.get(250)?.(); },
        poll: () => intervals.get(30000)(),
        fire: async name => { events.get(name)(); await new Promise(setImmediate); }};
}

test('early backend readiness cannot navigate while ingress is still being replaced', async () => {
    const f = await fixture();
    f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} :
        {...complete, deployment_complete: false, notice: {phase: 'deploying'}}));
    await f.poll();
    assert.equal(f.timer.textContent, '0:01:20');
    assert.equal(f.navigations.length, 0);
    assert.deepEqual(f.requests.map(r => r.url), ['/a/shalom-in-messiah/deployment_status.php']);
});

test('missing, malformed, failed and cancelled deployment state never means completion', async () => {
    for (const body of [null, {}, {notice: null}, {server_now: 1000, notice: null},
        {...complete, deployment_complete: false}, {...complete, notice: {phase: 'failed'}}]) {
        const f = await fixture();
        f.respond(async () => response(body));
        await f.poll();
        assert.equal(f.navigations.length, 0);
    }
});

test('transient proxy 404/503, malformed JSON and network failures leave the countdown on screen', async () => {
    for (const fail of [() => response('404 page not found', false), () => response('maintenance', false),
        () => ({ok: true, json: async () => { throw new SyntaxError(); }}), () => { throw new Error('network'); }]) {
        const f = await fixture();
        f.respond(async () => fail());
        await f.poll();
        f.tick(80000);
        assert.equal(f.navigations.length, 0);
        assert.equal(f.timer.textContent, '0:00:00');
        assert.ok(f.classes.has('timer-expired'));
        assert.equal(f.timeouts.size, 0);
    }
});

test('return waits for both completion and account readiness, before or after timer expiry', async () => {
    for (const seconds of [80, 0, null]) {
        for (const prefix of ['', '/a/test-account']) {
            const f = await fixture({seconds, prefix});
            f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'not_ready'} : complete));
            await f.poll();
            assert.equal(f.navigations.length, 0);
            f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} : complete));
            await f.poll();
            assert.deepEqual(f.navigations, [f.href.split('#')[0]]);
            await f.poll();
            assert.equal(f.navigations.length, 1);
            assert.ok(f.requests.every(r => r.options.cache === 'no-store' && r.options.credentials === 'omit'));
            assert.ok(f.requests.every(r => !r.url.includes('audience_collect_public')),
                'Probing public content would record extra visits or repeat one-time actions');
        }
    }
});

test('deployment starting again between readiness and navigation keeps the screen in place', async () => {
    const f = await fixture();
    let statusChecks = 0;
    f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} : ++statusChecks === 1
        ? complete : {...complete, deployment_complete: false, notice: {phase: 'deploying'}}));
    await f.poll();
    assert.equal(f.navigations.length, 0);
});

test('generic outages and pages without estimates use the same guarded polling', async () => {
    const f = await fixture({seconds: null, waiting: false});
    f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} :
        {...complete, deployment_complete: false}));
    await f.poll();
    assert.deepEqual(f.navigations, [f.href.split('#')[0]]);
});

test('slow requests cannot overlap and aborted requests can be retried', async () => {
    const f = await fixture();
    f.respond((_url, options) => new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('timeout')));
    }));
    const pending = f.poll();
    await f.poll();
    assert.equal(f.requests.length, 1);
    f.timeouts.get(10000)();
    await pending;
    f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} : complete));
    await f.poll();
    assert.deepEqual(f.navigations, [f.href.split('#')[0]]);
});


test('expired pages recover immediately when the tab resumes or the connection returns', async () => {
    for (const event of ['pageshow', 'online', 'visibilitychange']) {
        const f = await fixture({seconds: 0});
        f.respond(async url => response(url.endsWith('/ready.php') ? {status: 'ready'} : complete));
        await f.fire(event);
        assert.deepEqual(f.navigations, [f.href.split('#')[0]]);
        assert.equal(new Set(f.requests.map(r => r.options.signal)).size, 3,
            'Every readiness/completion request must have its own timeout budget');
    }
});
