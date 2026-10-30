'use strict';

// Render the real page from a disposable ingress image, then exercise a routing
// outage in browsers. No production requests, database or application sessions.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium, webkit} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const image = process.env.DNR_MAINTENANCE_INGRESS_IMAGE;
if (!image) throw new Error('Set DNR_MAINTENANCE_INGRESS_IMAGE to the built ingress image');
const root = path.resolve(__dirname, '../..');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'moed-maintenance-browser-'));
const prefix = '/a/test-account';
const origin = 'http://maintenance.test';
const target = origin + prefix + '/audience_collect_public.php?key=fixture&choice=2#form';
const script = fs.readFileSync(path.join(root, 'src/assets/js/maintenance-timer.js'));

function render(seconds, phase = 'deploying') {
    const now = Date.now() / 1000;
    fs.writeFileSync(path.join(directory, 'notice.json'), JSON.stringify({id: 'a'.repeat(32), phase,
        started_at: now - 600, countdown_started_at: now - 600, not_before: now - 300,
        expires_at: now + 21600, ...(seconds === null ? {} : {expected_back_at: now + seconds})}));
    return execFileSync('docker', ['run', '--rm', '--network', 'none', '--read-only',
        '--mount', `type=bind,src=${directory},dst=/run/dnr/deployment,readonly`, '--entrypoint', 'php', image,
        '-r', `$_SERVER['HTTP_X_FORWARDED_PREFIX']='${prefix}'; require '/var/www/html/maintenance.php';`],
    {encoding: 'utf8'});
}

(async () => {
    try {
        const cases = [{seconds: 80, phase: 'deploying'}, {seconds: 600, phase: 'deploying'}, {seconds: null, phase: 'failed'}]
            .map(fixture => ({...fixture, markup: render(fixture.seconds, fixture.phase)}));
        for (const [name, engine, viewport] of [
            ['desktop Chromium', chromium, {width: 1440, height: 1000}],
            ['iPhone WebKit', webkit, {width: 390, height: 844}],
        ]) {
            const browser = await engine.launch({headless: true});
            try {
                for (const fixture of cases) {
                    const page = await browser.newPage({viewport});
                    const errors = [], documents = [], requests = [];
                    let phase = fixture.phase, statusCode = 200, backendReady = true, checks = 0;
                    page.on('pageerror', error => errors.push(error.message));
                    await page.addInitScript(() => {
                        const schedule = window.setInterval.bind(window);
                        window.elapsedTime = 0;
                        Object.defineProperty(performance, 'now', {value: () => window.elapsedTime});
                        window.setInterval = (callback, delay) => {
                            if (delay === 30000) {
                                window.recoveryCheck = callback;
                                return schedule(() => {}, delay);
                            }
                            if (delay === 250) window.timerTick = callback;
                            return schedule(callback, delay);
                        };
                    });
                    await page.route(origin + '/**', route => {
                        const request = route.request(), url = new URL(request.url());
                        assert.equal(request.method(), 'GET', 'Recovery must never replay a form POST');
                        requests.push({path: url.pathname, phase, backendReady, statusCode});
                        if (url.pathname.endsWith('/assets/js/maintenance-timer.js')) {
                            return route.fulfill({contentType: 'text/javascript', body: script});
                        }
                        if (url.pathname.endsWith('/deployment_status.php')) {
                            checks++;
                            return route.fulfill({status: statusCode, contentType: statusCode === 200 ? 'application/json' : 'text/plain',
                                body: statusCode === 200 ? JSON.stringify({server_now: Date.now() / 1000,
                                    notice: phase === 'complete' ? null : {phase}, deployment_complete: phase === 'complete'}) : '404 page not found'});
                        }
                        if (url.pathname.endsWith('/ready.php')) {
                            return route.fulfill({status: backendReady ? 200 : 503, contentType: 'application/json',
                                body: JSON.stringify({status: backendReady ? 'ready' : 'not_ready'})});
                        }
                        assert.ok(request.isNavigationRequest(), 'Public content must not be fetched as a readiness probe');
                        const status = phase === 'complete' && backendReady ? 200 : 503;
                        documents.push(status);
                        return route.fulfill({status, contentType: 'text/html', body: status === 200
                            ? '<h1>Requested collection form</h1>' : fixture.markup});
                    });
                    await page.goto(target);
                    assert.equal(await page.locator('[data-maintenance-page]').count(), 1);
                    const check = async (returning = false) => {
                        const previous = checks;
                        await page.evaluate(() => {
                            window.elapsedTime += 30000;
                            window.timerTick?.();
                            return window.recoveryCheck();
                        }).catch(error => {
                            if (!returning || !/Execution context was destroyed/.test(error.message)) throw error;
                        });
                        assert.ok(checks > previous, 'Expected the scheduled recovery check');
                    };
                    await check(); // The backend is ready, but ingress is not finished.
                    assert.deepEqual(documents, [503]);
                    statusCode = 404;
                    await check();
                    assert.deepEqual(documents, [503]);
                    statusCode = 200;
                    await check();
                    if (fixture.seconds === 80) {
                        assert.equal(await page.locator('[data-maintenance-timer]').textContent(), '0:00:00');
                        assert.equal(await page.locator('.timer-expired').evaluate(node => getComputedStyle(node).color), 'rgb(255, 103, 103)');
                    }
                    phase = 'complete';
                    backendReady = false;
                    await check();
                    assert.deepEqual(documents, [503]);
                    backendReady = true;
                    await check(true);
                    await page.getByRole('heading', {name: 'Requested collection form'}).waitFor({timeout: 5000}).catch(error => {
                        console.error({requests, documents, errors});
                        throw error;
                    });
                    assert.equal(page.url(), target.split('#')[0]);
                    assert.deepEqual(documents, [503, 200], 'Only maintenance then content may be displayed');
                    assert.deepEqual(errors, []);
                    await page.close();
                    console.log(`${name}: ${fixture.seconds === null ? 'no estimate' : fixture.seconds + ' second estimate'} recovered directly to content`);
                }
            } finally {
                await browser.close();
            }
        }
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
