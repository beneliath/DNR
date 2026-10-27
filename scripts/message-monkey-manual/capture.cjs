/* Native 3x PNG capture from the disposable MessageMonkey guide preview. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const work = path.join(root, 'tmp/pdfs/message-monkey-manual');
const output = path.join(root, 'docs/message-monkey-user-guide');
const base = process.env.DNR_MANUAL_BASE_URL || 'http://127.0.0.1:18146';
if (!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)) throw Error('Use a disposable loopback preview.');
const credentials = JSON.parse(fs.readFileSync(path.join(work, 'credentials.json')));
const shots = JSON.parse(fs.readFileSync(path.join(output, 'screenshots.json')));
const only = process.env.DNR_MANUAL_ONLY?.split(',');
fs.mkdirSync(path.join(output, 'screenshots'), { recursive: true });
fs.mkdirSync(path.join(work, 'states'), { recursive: true });
function totp() {
  const bits = [...credentials.totp_secret.toUpperCase().replace(/=+$/, '')].map(c => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = crypto.createHmac('sha1', key).update(counter).digest();
  return ((h.readUInt32BE(h[19] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
  const session = path.join(work, 'session.json');
  const context = await browser.newContext({ ...(fs.existsSync(session) ? { storageState: session } : {}), viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 3, reducedMotion: 'reduce' });
  await context.route('**/*', route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== base && !['data:', 'blob:'].includes(url.protocol)) return route.abort();
    if (request.method() === 'POST' && !['login.php', 'verify_2fa.php', 'admin_elevation.php'].includes(url.pathname.split('/').pop())) {
      const body = request.postData() || '';
      const action = request.headers()['content-type']?.includes('multipart/form-data')
        ? body.match(/name="action"\r?\n\r?\n([^\r\n]+)/)?.[1]
        : new URLSearchParams(body).get('action');
      // Preview rendering is read-only; read/presence records affect only this fictional preview.
      if (!['design_preview', 'design_people', 'collection_preview', 'inbox_read', 'inbox_presence'].includes(action)) return route.abort('blockedbyclient');
    }
    return route.continue();
  });
  const page = await context.newPage(); page.setDefaultTimeout(8000);
  let pageErrors = []; page.on('pageerror', error => pageErrors.push(error.stack || error.message));
  await page.goto(base + '/dashboard.php');
  if (page.url().includes('login.php')) {
    await page.locator('[name=username]').fill(credentials.username);
    await page.locator('[name=password]').fill(credentials.password);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    if (page.url().includes('verify_2fa.php')) {
      await page.locator('[name=authentication_code]').fill(totp());
      await page.getByRole('button', { name: 'Verify', exact: true }).click();
    }
    if (/login\.php|verify_2fa\.php/.test(page.url())) throw Error('Preview sign-in failed');
  }
  await context.storageState({ path: session });
  const failures = [];
  for (const shot of shots) {
    if (only && !only.includes(shot.id)) continue;
    try {
      pageErrors = [];
      await page.setViewportSize(shot.viewport || { width: 1280, height: 1000 });
      await page.evaluate(theme => localStorage.setItem('theme', theme), shot.theme || 'light');
      let response = await page.goto(base + '/' + shot.route, { waitUntil: 'networkidle' });
      // Hash-only navigation does not rerun the theme initializer.
      if (!response) response = await page.reload({ waitUntil: 'networkidle' });
      if ((response && response.status() !== 200) || /login\.php|verify_2fa\.php/.test(page.url())) throw Error('HTTP or authentication error: ' + response?.status());
      for (const action of shot.actions || []) {
        const locator = action.role ? page.getByRole(action.role, { name: action.name, exact: true }) : page.locator(action.selector);
        if (action.op === 'fill') await locator.fill(action.value);
        else if (action.op === 'select') await locator.selectOption(action.value);
        else if (action.op === 'check') await locator.check();
        else if (action.op === 'scroll') await locator.scrollIntoViewIfNeeded();
        else if (action.op === 'hover') await locator.hover();
        else await locator.click();
      }
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(200);
      if (shot.id === 'designer' && await page.locator('.designer-block').count() === 0) throw Error('The email canvas did not render its blocks.');
      if (shot.id === 'mobile') {
        const overlaps = await page.locator('.audience-count-pill').evaluateAll(pills => {
          const bounds = pills.map(pill => {
            const rect = pill.getBoundingClientRect(), style = getComputedStyle(pill);
            const extra = style.outlineStyle === 'none' ? 0 : parseFloat(style.outlineWidth) + Math.max(0, parseFloat(style.outlineOffset));
            return { left: rect.left - extra, right: rect.right + extra, top: rect.top - extra, bottom: rect.bottom + extra };
          });
          return bounds.some((a, i) => bounds.slice(i + 1).some(b => Math.min(a.right, b.right) > Math.max(a.left, b.left) + 1 && Math.min(a.bottom, b.bottom) > Math.max(a.top, b.top) + 1));
        });
        if (overlaps) throw Error('Connection count selection outlines overlap in the mobile figure.');
      }
      const body = await page.locator('body').innerText();
      fs.writeFileSync(path.join(work, 'states', shot.id + '.txt'), body);
      fs.writeFileSync(path.join(work, 'states', shot.id + '.html'), await page.content());
      fs.writeFileSync(path.join(work, 'states', shot.id + '-errors.json'), JSON.stringify(pageErrors));
      if (pageErrors.length) throw Error('Browser error: ' + pageErrors.join('; '));
      if (/Fatal error:|Warning:.*\.php on line|Uncaught .*Error:/.test(body)) throw Error('Application error in rendered page: ' + body.slice(-1300));
      const file = 'screenshots/' + shot.id + '.png';
      await page.screenshot({ path: path.join(output, file), type: 'png', scale: 'device', animations: 'disabled', ...(shot.clip ? { clip: shot.clip } : {}) });
      shot.file = file;
      shot.capture = { source: 'Native browser PNG; no resizing or sharpening', device_scale_factor: 3, viewport_css: page.viewportSize(), clip_css: shot.clip || null, theme: shot.theme || 'light', captured_at: new Date().toISOString().slice(0, 10), sha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(output, file))).digest('hex') };
      console.log('Captured ' + shot.id);
    } catch (error) { failures.push({ id: shot.id, error: error.message }); console.error('Failed ' + shot.id + ': ' + error.message.split('\n')[0]); }
  }
  fs.writeFileSync(path.join(output, 'screenshots.json'), JSON.stringify(shots, null, 2) + '\n');
  fs.writeFileSync(path.join(work, 'capture-failures.json'), JSON.stringify(failures, null, 2));
  await browser.close();
  if (failures.length) process.exitCode = 1;
})().catch(error => { console.error(error.message); process.exitCode = 1; });
