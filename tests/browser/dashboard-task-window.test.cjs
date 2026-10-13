'use strict';
// Optional browser regression: actual My Tasks markup, with no database or sign-in.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
function panel(count) {
    return execFileSync('php', ['-r', `
        require 'src/application_runtime.php';
        require 'src/follow_up_task_helpers.php';
        require 'src/dashboard_helpers.php';
        require 'src/booking_inquiry_helpers.php';
        $business_date='2026-10-13'; $can_manage=true;
        $task_summary=['today'=>2];$task_status_labels=followUpTaskStatuses();$my_tasks=[];
        for($i=0;$i<${count};$i++) $my_tasks[]=['id'=>$i+1,
            'title'=>$i===1?'Prepare presentations, handouts, books, supplies, and accessibility materials for the upcoming gathering':'Example task '.($i+1),
            'due_date'=>sprintf('2026-10-%02d',9+$i),'status'=>'open','priority'=>'high','subject_type'=>'general'];
        $dashboard_upcoming_days=30;$upcoming_engagements_url='engagements.php';$inquiry_attention_cutoff='2026-10-10';
        $displayed_upcoming_engagements=$readiness_items=$financial_closeouts=$booking_inquiries=[];
        for($i=0;$i<${count};$i++) {
            $event=['id'=>$i+1,'event_title'=>'Sample event '.($i+1),'organization_name'=>'Fictional Host',
                'event_start_date'=>'2026-10-14','event_end_date'=>'2026-10-15','confirmation_status'=>'under_review',
                'readiness_issues'=>['Not confirmed','Venue address missing','No presentations','No event contacts assigned'],
                'closeout_due_date'=>'2026-10-12','days_overdue'=>$i+1];
            $displayed_upcoming_engagements[]=$event;$readiness_items[]=$event;$financial_closeouts[]=$event;
            $booking_inquiries[]=['id'=>$i+1,'title'=>'Sample inquiry '.($i+1),'next_action'=>'Review the proposed dates and confirm venue details with the host coordinator',
                'next_action_due_date'=>'2026-10-13','priority'=>'normal','stage'=>'new'];
        }
        $page=file_get_contents('src/dashboard.php');
        foreach(['my-work','upcoming-engagements','event-readiness','financial-closeouts','booking-inquiries'] as $id) {
            $start=strpos($page,'<section class="dashboard-panel" id="'.$id.'"');
            $end=strpos($page,'</section>',$start)+strlen('</section>');
            if($id==='booking-inquiries')echo '<details><summary>Booking Pipeline</summary>';
            eval('?>'.substr($page,$start,$end-$start));
            if($id==='booking-inquiries')echo '</details>';
        }
    `], { cwd: root, encoding: 'utf8' });
}
(async () => {
    const browser = await chromium.launch({ headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('http://dashboard.test/**', route => {
            const url = new URL(route.request().url());
            if (url.pathname.startsWith('/assets/')) {
                const file = path.join(root, 'src', url.pathname);
                return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript' });
            }
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head>'
                + '<meta name="viewport" content="width=device-width,initial-scale=1">'
                + '<link rel="stylesheet" href="assets/css/style.min.css"><link rel="stylesheet" href="assets/css/modern.min.css">'
                + '<link rel="stylesheet" href="assets/css/pages/dashboard.min.css"></head><body class="dashboard-body">'
                + '<main class="container dashboard-page">' + panel(Number(url.searchParams.get('count')))
                + '</main><script src="assets/js/task-attention.min.js"></script></body></html>' });
        });
        for (const count of [0, 4, 5, 8]) {
            await page.goto(`http://dashboard.test/dashboard.php?count=${count}`);
            const list = page.locator('#my-work .dashboard-task-list');
            if (count === 0) {
                assert.equal(await page.locator('#my-work .dashboard-empty-state').isVisible(), true);
            } else if (count === 4) {
                assert.equal(await list.evaluate(item => item.scrollHeight <= item.clientHeight), true, 'four tasks fit without scrolling');
                assert.equal(await list.getAttribute('tabindex'), null, 'short lists need no extra tab stop');
            } else {
                const rows = list.locator('li');
                assert.equal(await rows.count(), count, 'overflow tasks remain in the list');
                await page.waitForFunction(() => document.querySelector('#my-work ul').scrollHeight > document.querySelector('#my-work ul').clientHeight);
                const bounds = await list.boundingBox();
                const fourth = await rows.nth(3).boundingBox();
                const fifth = await rows.nth(4).boundingBox();
                assert.ok(Math.abs(fourth.y + fourth.height - bounds.y - bounds.height) < 1.5, 'window ends after the fourth row');
                assert.ok(fifth.y >= bounds.y + bounds.height - 1.5, 'fifth task starts below the window');
                const headingBefore = await page.locator('#my-work .dashboard-panel-heading').boundingBox();
                await list.focus();
                await page.keyboard.press('End');
                await page.waitForFunction(() => {
                    const item = document.querySelector('#my-work ul');
                    return item.scrollTop >= item.scrollHeight - item.clientHeight - 1;
                });
                const last = await rows.last().boundingBox();
                assert.ok(last.y + last.height <= bounds.y + bounds.height + 1.5, 'keyboard scrolling reaches the final task');
                assert.deepEqual(await page.locator('#my-work .dashboard-panel-heading').boundingBox(), headingBefore, 'heading stays outside the scrolling list');
            }
            if (count >= 4) {
                for (const [left, right] of [['my-work', 'upcoming-engagements'], ['event-readiness', 'financial-closeouts']]) {
                    const heights = await page.locator(`#${left} li, #${right} li`).evaluateAll(rows => rows.map(row => row.getBoundingClientRect().height));
                    assert.ok(Math.max(...heights) - Math.min(...heights) < 1, `${left} and ${right} use uniform row heights`);
                    const leftBounds = await page.locator(`#${left} ul`).boundingBox();
                    const rightBounds = await page.locator(`#${right} ul`).boundingBox();
                    assert.ok(Math.abs(leftBounds.height - rightBounds.height) < 1, `${left} and ${right} show the same four-row height`);
                }
            }
        }
        await page.locator('summary').click();
        for (const id of ['upcoming-engagements', 'event-readiness', 'financial-closeouts', 'booking-inquiries']) {
            const list = page.locator(`#${id} ul`);
            await page.waitForFunction(id => {
                const item = document.querySelector(`#${id} ul`);
                return Math.abs(item.children[3].getBoundingClientRect().bottom - item.getBoundingClientRect().bottom) < 1.5;
            }, id);
            assert.equal(await list.locator('li').count(), 8);
            assert.equal(await list.getAttribute('tabindex'), '0');
            await list.focus();
            const headingOffset = await list.evaluate(item => item.closest('.dashboard-panel').querySelector('.dashboard-panel-heading').getBoundingClientRect().top - item.getBoundingClientRect().top);
            await page.keyboard.press('End');
            await page.waitForFunction(id => {
                const item = document.querySelector(`#${id} ul`);
                return item.scrollTop >= item.scrollHeight - item.clientHeight - 1;
            }, id);
            const afterOffset = await list.evaluate(item => item.closest('.dashboard-panel').querySelector('.dashboard-panel-heading').getBoundingClientRect().top - item.getBoundingClientRect().top);
            assert.ok(Math.abs(afterOffset - headingOffset) < 1.5, `${id} header stays outside the scrolling list`);
        }
        await page.locator('summary').click();
        await page.setViewportSize({ width: 390, height: 1000 });
        await page.locator('summary').click();
        await page.locator('#booking-inquiries ul').evaluate(item => { item.scrollTop = 0; });
        await page.waitForFunction(() => {
            const item = document.querySelector('#booking-inquiries ul');
            return Math.abs(item.children[3].getBoundingClientRect().bottom - item.getBoundingClientRect().bottom) < 1.5;
        });
        for (const width of [390, 760, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.locator('#my-work ul').evaluate(item => { item.scrollTop = 0; });
            await page.waitForFunction(() => {
                const list = document.querySelector('#my-work ul');
                return Math.abs(list.children[3].getBoundingClientRect().bottom - list.getBoundingClientRect().bottom) < 1.5;
            });
            assert.equal(await page.locator('#my-work li').count(), 8, 'resizing retains every task');
        }
        await page.evaluate(() => document.documentElement.classList.add('dark-mode'));
        assert.equal(await page.locator('#my-work ul').evaluate(item => item.scrollHeight > item.clientHeight), true);
        assert.deepEqual(errors, []);
        console.log('Dashboard four-row windows passed for all five panels, keyboard scrolling, resizing and collapsed pipeline content.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
