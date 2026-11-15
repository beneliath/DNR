'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), net = require('node:net');
const {spawn} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'dnr-date-range-'));
  const router = path.join(temp, 'router.php');
  fs.writeFileSync(router, `<?php if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/audience.php'){require '${root}/tests/browser/audience-date-range-fixture.php';return true;}return false;`);
  const port = await new Promise(resolve => {
    const probe = net.createServer();
    probe.listen(0, '127.0.0.1', () => {const port = probe.address().port; probe.close(() => resolve(port));});
  });
  const origin = `http://127.0.0.1:${port}`;
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'src'), router], {
    cwd: root, env: {...process.env, DNR_REQUIRE_HTTPS:'0', DNR_ACCOUNTS_ENABLED:'1', DNR_ACCOUNT_MODE:'primary',
      DNR_ACCOUNT_KEY:'shalom-in-messiah', DNR_PUBLIC_BASE_URL:origin+'/a/shalom-in-messiah'}, stdio:'ignore'
  });
  let browser, checks = 0;
  try {
    for (let attempt = 0; attempt < 30; attempt++) {
      try {if ((await fetch(origin+'/audience.php?view=home')).ok) break;} catch {}
      if (attempt === 29) throw Error('Date preference fixture did not start');
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    browser = await chromium.launch({headless:true});
    const page = await browser.newPage({viewport:{width:1400,height:1000}});
    page.setDefaultTimeout(7000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const form = page.locator('[data-analytics-range]'), preset = form.locator('[name=range]');
    const navigate = async section => {
      await Promise.all([page.waitForNavigation(), page.getByRole('navigation').getByRole('link', {name:section,exact:true}).click()]);
    };
    const assertRange = async (range, dates) => {
      assert.equal(await preset.count(), 1, 'One preset control, with no duplicate hidden range');
      assert.equal(await preset.inputValue(), range);
      if (dates) for (const [key, value] of Object.entries(dates)) {
        assert.equal(await form.locator(`[name=${key}]`).inputValue(), value);
        assert.equal(await page.locator(`#analytics-range-summary time`).nth(key === 'start' ? 0 : 1).getAttribute('datetime'), value);
      }
      checks++;
    };
    const apply = async () => Promise.all([page.waitForNavigation(), form.getByRole('button', {name:'Apply',exact:true}).click()]);
    const sections = ['Home','email','audience','conversions','reports','custom','activity','links','devices','locations','insights'];
    const choices = new Map();
    await page.goto(origin+'/audience.php?view=home');
    for (const [index, section] of sections.entries()) {
      await navigate(section);
      await assertRange(section === 'Home' ? '30' : section === 'locations' ? '7' : '90');
      const selected = index % 2 ? 'month' : '7';
      await page.locator('#analytics-range-trigger').click();
      await preset.selectOption(selected);await apply();await assertRange(selected);
      await navigate(section === 'Home' ? 'email' : 'Home');await navigate(section);await assertRange(selected);
      const dates = {start:`2026-10-${String(index+1).padStart(2,'0')}`,end:'2026-10-31'};
      await page.locator('#analytics-range-trigger').click();
      await form.getByLabel('Start Date',{exact:true}).filter({visible:true}).fill(dates.start);
      await form.getByLabel('End Date',{exact:true}).filter({visible:true}).fill(dates.end);
      await apply();await assertRange('custom',dates);choices.set(section,dates);
      await navigate(section === 'Home' ? 'email' : 'Home');await navigate(section);await assertRange('custom',dates);
      await page.reload();await assertRange('custom',dates);
      await page.locator('#analytics-range-trigger').click();await preset.selectOption('365');
      await form.getByRole('button',{name:'Cancel',exact:true}).click();
      await navigate(section === 'Home' ? 'email' : 'Home');await navigate(section);await assertRange('custom',dates);
    }
    for (const [section, dates] of choices) {await navigate(section);await assertRange('custom',dates);}
    await page.goto(origin+'/audience.php?view=analytics&section=email&range=30');await assertRange('30');
    await navigate('Home');await navigate('email');await assertRange('custom',choices.get('email'));
    const cookies = (await page.context().cookies()).filter(cookie => cookie.name.startsWith('dnr_mm_date_range_'));
    assert.equal(cookies.length, sections.length);
    assert(cookies.every(cookie => cookie.httpOnly && cookie.path === '/' && cookie.sameSite === 'Lax' && cookie.expires > Date.now()/1000+86400));
    assert.deepEqual(errors, []);checks+=3;
    console.log(`MessageMonkey date ranges: ${checks} browser checks passed across Home and ${sections.length-1} Analytics views.`);
  } finally {
    await browser?.close();server.kill();fs.rmSync(temp,{recursive:true,force:true});
  }
})().catch(error => {console.error(error);process.exitCode=1;});
