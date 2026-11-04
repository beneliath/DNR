'use strict';

// Deliver the real Brand Kit in stages, so native reload scroll restoration is
// exercised before the document has reached its final height.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const html = (process.env.DNR_BRAND_RELOAD_FIXTURE
    ? fs.readFileSync(process.env.DNR_BRAND_RELOAD_FIXTURE, 'utf8')
    : execFileSync('php', ['tests/browser/audience-tag-actions-fixture.php','admin','active','locked','2','','','brand'], {cwd:root, encoding:'utf8'}))
    .replaceAll(' blocking="render"', '');
const first = html.indexOf('<section class="content-brand-section content-brand-color-section"');
const second = html.indexOf('<section class="content-brand-section content-brand-apply"');
assert(first > 0 && second > first, 'The streamed fixture contains the real Brand Kit sections');
const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    response.setHeader('Cache-Control', 'no-store');
    if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(root, 'src', '.' + url.pathname);
        if (!file.startsWith(path.join(root, 'src/assets') + path.sep) || !fs.existsSync(file)) {
            response.writeHead(404); response.end(); return;
        }
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'image/svg+xml');
        fs.createReadStream(file).pipe(response); return;
    }
    if (url.pathname !== '/audience.php') { response.writeHead(404); response.end(); return; }
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    for (const part of [html.slice(0, first), html.slice(first, second), html.slice(second)]) {
        if (response.destroyed) return;
        response.write(part);
        await new Promise(resolve => setTimeout(resolve, 80));
    }
    response.end();
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium').split(',')) {
            const browser = await engines[engine].launch();
            try {
                const page = await browser.newPage({viewport:{width:1600, height:900}});
                await page.addInitScript(() => {
                    window.brandScrollFrames = []; let last = '', count = 0;
                    const observe = () => {
                        const main = document.getElementById('audience-content');
                        const prompt = document.querySelector('.content-brand-font-upload .audience-file-drop>strong');
                        if (main && prompt) {
                            const sample = {y:Math.round(scrollY), top:Math.round(prompt.getBoundingClientRect().top), height:document.documentElement.scrollHeight, visible:getComputedStyle(main).visibility !== 'hidden'};
                            const signature = JSON.stringify(sample);
                            if (signature !== last) { window.brandScrollFrames.push(sample); last = signature; }
                        }
                        if (++count < 180) requestAnimationFrame(observe);
                    };
                    requestAnimationFrame(observe);
                });
                await page.goto('http://127.0.0.1:' + server.address().port + '/audience.php?view=brand');
                for (const position of ['bottom','middle','top']) {
                    const saved = await page.evaluate(position => {
                        const bottom = Math.max(0, document.documentElement.scrollHeight - innerHeight);
                        const y = position === 'bottom' ? bottom : position === 'middle' ? Math.floor(bottom / 2) : 0;
                        scrollTo(0, y); return y;
                    }, position);
                    await page.reload();
                    await page.waitForFunction(() => !document.body.hasAttribute('data-mm-brand-scroll-restoring'));
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    const frames = await page.evaluate(() => window.brandScrollFrames.filter(frame => frame.visible));
                    assert(frames.length > 0, engine + ' reveals the reloaded Brand Kit');
                    assert.equal(new Set(frames.map(frame => JSON.stringify([frame.y,frame.top,frame.height]))).size, 1, engine + ' shows no visible intermediate layout when reloading from ' + position);
                    assert(Math.abs(await page.evaluate(() => scrollY) - saved) <= 1, engine + ' restores the saved ' + position + ' position');
                }
                console.log(engine + ': streamed Brand Kit reloads preserve bottom, middle and top positions without visible jumps.');
            } finally { await browser.close(); }
        }
    } finally { server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
