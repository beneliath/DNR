'use strict';
// Optional real-browser regression, using the actual calendar template without a database.
// DNR_PLAYWRIGHT_MODULE and CHROMIUM_EXECUTABLE may point to an existing browser runtime.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

function calendar(month) {
    return execFileSync('php', ['-r', `
        require 'src/calendar_helpers.php';
        $calendar_month = calendarMonthContext(${JSON.stringify(month)}, '2026-10-13');
        $calendar_day = calendarDayContext($calendar_month['month'] . '-13', '2026-10-13');
        $calendar_view_mode = 'everything';
        $has_requested_day = false;
        $calendar_viewer_error = '';
        $calendar_month_event_count = $calendar_month_birthday_count = 1;
        $calendar_month_task_count = 46;
        $calendar_task_status_labels = ['open' => 'Open'];
        $calendar_task_priority_labels = ['high' => 'High'];
        $can_manage_calendar_tasks = true;
        $user_id = 1;
        $calendar_events_by_date = [$calendar_day['date'] => [
            ['id' => 42, 'event_title' => 'Leadership gathering', 'confirmation_status' => 'confirmed'],
            ['id' => 7, 'calendar_item_type' => 'birthday', 'contact_first_name' => 'Alex', 'contact_last_name' => 'Rivera']
        ]];
        $calendar_tasks_by_date = [];
        foreach ([13, 17, 28] as $number) {
            $date = $calendar_month['month'] . '-' . $number;
            for ($i = 1; $i <= 15; $i++) {
                $calendar_tasks_by_date[$date][] = ['id' => $i, 'title' => 'Task ' . $i . ': Prepare <materials> & follow up with everyone attending the leadership gathering',
                    'assigned_to' => 1, 'status' => 'open', 'priority' => 'high'];
            }
        }
        $calendar_tasks_by_date[$calendar_month['month'] . '-15'] = [['id' => 50, 'title' => 'One task', 'assigned_to' => 1]];
        include 'src/templates/calendar_month_viewer.php';
    `], { cwd: root, encoding: 'utf8' });
}

(async () => {
    const browser = await chromium.launch({ headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const serve = route => {
            const url = new URL(route.request().url());
            if (url.pathname.startsWith('/assets/')) {
                const file = path.join(root, 'src', url.pathname);
                return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8' });
            }
            const markup = calendar(url.searchParams.get('month') || '2026-10');
            if (url.searchParams.has('fragment')) return route.fulfill({ contentType: 'text/html; charset=utf-8', body: markup });
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head>'
                + '<meta name="viewport" content="width=device-width,initial-scale=1">'
                + '<link rel="stylesheet" href="assets/css/style.min.css"><link rel="stylesheet" href="assets/css/modern.min.css">'
                + '<link rel="stylesheet" href="assets/css/pages/calendar_subscription.min.css">'
                + '</head><body class="calendar-subscription-body"><main class="container calendar-subscription">'
                + markup + '</main><script src="assets/js/calendar-scroll.min.js"></script></body></html>' });
        };
        await page.route('http://calendar.test/**', serve);
        await page.goto('http://calendar.test/view_calendar.php');
        const cell = date => page.locator('.calendar-month-day').filter({ has: page.locator(`time[datetime="${date}"]`) });
        const trigger = cell('2026-10-13').locator('.calendar-day-toggle');
        const panel = page.locator('#calendar-day-popover');
        await trigger.waitFor();
        assert.equal(await cell('2026-10-14').locator('button').count(), 0, 'empty dates have no popover');
        const singleTrigger = cell('2026-10-15').locator('button');
        assert.equal(await singleTrigger.count(), 1, 'a single entry also has a popover');
        await singleTrigger.hover();
        await panel.waitFor({ state: 'visible' });
        assert.equal(await panel.locator('.calendar-item').count(), 1);
        assert.equal(await panel.locator('p').textContent(), '1 item');
        await page.keyboard.press('Escape');
        const itemHeight = await cell('2026-10-13').locator('.calendar-item').first().evaluate(item => item.getBoundingClientRect().height);
        assert.ok(itemHeight > 30, 'busy-day rows retain readable height');
        assert.ok((await trigger.boundingBox()).height <= 26, 'date control fits the fixed day heading');
        await trigger.hover();
        await panel.waitFor({ state: 'visible' });
        assert.equal(await panel.locator('.calendar-item').count(), 17, 'every event, birthday and task appears');
        assert.match(await panel.locator('h3').textContent(), /Tuesday, October 13, 2026/);
        const sourceLinks = await cell('2026-10-13').locator('.calendar-item').evaluateAll(items => items.map(item => item.getAttribute('href')));
        assert.deepEqual(await panel.locator('.calendar-item').evaluateAll(items => items.map(item => item.getAttribute('href'))), sourceLinks);
        assert.match(await panel.locator('.calendar-task').first().textContent(), /<materials> &/);
        assert.equal(await panel.locator('materials').count(), 0, 'escaped titles remain text');
        if (process.env.DNR_CALENDAR_SCREENSHOT) await page.screenshot({ path: process.env.DNR_CALENDAR_SCREENSHOT });
        await panel.locator('.calendar-item').first().hover();
        await page.waitForTimeout(300);
        assert.equal(await panel.isVisible(), true, 'moving into the panel keeps it open');
        const list = panel.locator('.calendar-day-popover-items');
        assert.equal(await list.evaluate(item => item.scrollHeight > item.clientHeight), true, 'expanded content can scroll');
        await list.evaluate(item => { item.scrollTop = item.scrollHeight; });
        assert.equal(await panel.isVisible(), true, 'scrolling the panel keeps it open');
        assert.equal(await panel.locator('.calendar-item-title').first().evaluate(item => getComputedStyle(item).whiteSpace), 'normal');
        await page.mouse.move(0, 0);
        await panel.waitFor({ state: 'hidden' });

        await trigger.focus();
        await page.keyboard.press('Enter');
        await panel.waitFor({ state: 'visible' });
        assert.equal(await panel.locator('.calendar-item').first().evaluate(item => item === document.activeElement), true);
        await page.keyboard.press('Escape');
        assert.equal(await panel.isVisible(), false);
        assert.equal(await trigger.evaluate(item => item === document.activeElement), true, 'Escape restores keyboard focus');
        await trigger.click();
        await panel.locator('button').click();
        assert.equal(await panel.isVisible(), false, 'Close dismisses the panel');
        await trigger.click();
        await page.locator('#calendar-month-title').click();
        assert.equal(await panel.isVisible(), false, 'outside click dismisses the panel');

        await cell('2026-10-17').locator('button').hover();
        await panel.waitFor({ state: 'visible' });
        const bounds = await panel.boundingBox();
        assert.ok(bounds.x >= 12 && bounds.x + bounds.width <= 1428, 'right-edge dates stay within the screen');
        await page.keyboard.press('Escape');
        await cell('2026-10-28').locator('button').click();
        const lowerBounds = await panel.boundingBox();
        assert.ok(lowerBounds.y >= 12 && lowerBounds.y + lowerBounds.height <= 988, 'bottom-edge dates stay within the screen');
        await page.keyboard.press('Escape');

        await page.locator('.calendar-desktop-navigation a').last().click();
        const nextTrigger = cell('2026-11-13').locator('button');
        await nextTrigger.waitFor();
        await nextTrigger.hover();
        await panel.waitFor({ state: 'visible' });
        assert.match(await panel.locator('h3').textContent(), /November 13, 2026/, 'newly loaded dates get popovers');
        await page.evaluate(() => document.documentElement.classList.add('dark-mode'));
        assert.equal(await panel.evaluate(item => getComputedStyle(item).backgroundColor), 'rgb(23, 29, 39)', 'panel follows the dark palette');
        await page.locator('.calendar-month-scroll').evaluate(item => { item.scrollTop += 100; });
        await panel.waitFor({ state: 'hidden' });
        await page.setViewportSize({ width: 390, height: 844 });
        await panel.waitFor({ state: 'hidden' });
        assert.equal(await page.locator('.calendar-daily-agenda').isVisible(), true, 'phone agenda remains available');
        await page.waitForFunction(() => document.querySelectorAll('.calendar-day-toggle').length === 0);
        assert.equal(await page.locator('.calendar-day-toggle').count(), 0, 'hidden month grid has no extra tab stops');
        const touchPage = await browser.newPage({ viewport: { width: 1024, height: 600 }, hasTouch: true });
        touchPage.on('pageerror', error => errors.push(error.message));
        await touchPage.route('http://calendar.test/**', serve);
        await touchPage.goto('http://calendar.test/view_calendar.php');
        const touchTrigger = touchPage.locator('.calendar-day-toggle').filter({ has: touchPage.locator('time[datetime="2026-10-13"]') });
        await touchTrigger.tap();
        assert.equal(await touchPage.locator('#calendar-day-popover').isVisible(), true, 'wide touch screens can tap the date');
        await touchPage.close();
        assert.deepEqual(errors, [], 'calendar has no browser errors');
        console.log('Calendar day popover browser regression passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
