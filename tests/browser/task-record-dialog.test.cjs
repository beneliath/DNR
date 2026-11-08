'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

function fixture(duplicate) {
    return execFileSync('php', ['-r', `
        require 'src/functions.php';
        require 'src/follow_up_task_helpers.php';
        $task_users = [['id'=>1,'username'=>'Task reviewer','role'=>'admin']];
        $task_form_values = ['assigned_to'=>1,'title'=>'Example task'];
        $task_require_engagement_subject = $argv[1] === 'duplicate';
        $task_selected_subject = $task_require_engagement_subject ? '' : 'general';
        $task_form_submit_label = $task_require_engagement_subject ? 'Duplicate task' : 'Add task';
        echo '<!doctype html><html class="has-js">';
        renderPageHead('Task', ['styles'=>['assets/css/style.min.css','assets/css/modern.min.css']]);
        echo '<body class="has-app-shell"><main class="container">';
        include 'src/templates/follow_up_task_form.php';
        echo '</main></body></html>';
    `, duplicate ? 'duplicate' : 'new'], {cwd: root, encoding: 'utf8'});
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const duplicate of [false, true]) {
            const page = await browser.newPage({viewport: {width: 1600, height: 1600}});
            page.setDefaultTimeout(8000);
            const errors = [], posts = [];
            page.on('pageerror', error => errors.push(error.message));
            let count = 14, failing = false;
            await page.route('https://tasks.test/**', route => {
                const request = route.request(), url = new URL(request.url());
                if (request.method() === 'POST') {
                    posts.push(request.postData());
                    return route.fulfill({body: 'Submitted'});
                }
                if (url.pathname === '/task_subject_search.php') {
                    const results = Array.from({length: count}, (_, index) => ({
                        type: 'engagement', value: 'engagement:' + (index + 10),
                        label: 'Event ' + (index + 1) + (index % 2 ? ' with a longer description that wraps across multiple lines in the search results' : '')
                    }));
                    return route.fulfill({status: failing ? 503 : 200, contentType: 'application/json', body: JSON.stringify({results})});
                }
                if (url.pathname === '/add_task.php') return route.fulfill({contentType: 'text/html', body: fixture(duplicate)});
                const file = path.join(root, 'src', url.pathname);
                return fs.existsSync(file) ? route.fulfill({path: file}) : route.fulfill({status: 404, body: ''});
            });
            await page.goto('https://tasks.test/add_task.php');
            const change = page.getByRole('button', {name: 'Change Record', exact: true});
            const dialog = page.getByRole('dialog', {name: 'Find a Related Record'});
            const search = page.locator('#task-subject-search');
            const selected = page.locator('#task-subject');
            const save = page.locator('[name=save_task]');
            await change.waitFor({state: 'visible'});
            assert.equal(await search.isVisible(), false);
            assert.equal(await save.evaluate(button => button.classList.contains('form-submit-pending')), false);
            if (duplicate) {
                await save.click();
                await dialog.waitFor({state: 'visible'});
                assert.equal(await search.evaluate(input => input === document.activeElement), true);
                await page.keyboard.press('Escape');
                await dialog.waitFor({state: 'hidden'});
            }
            await change.click();
            for (const resultCount of [0, 10, 14, 24]) {
                count = resultCount;
                await search.fill('query ' + resultCount);
                await search.press('Enter');
                await page.waitForFunction(expected => document.querySelector('#task-subject-status').textContent.startsWith(expected ? expected + ' matching records' : 'No matching records'), resultCount);
                const size = await page.locator('#task-subject-results').evaluate(list => {
                    const bounds = list.getBoundingClientRect();
                    return {overflow: list.scrollHeight > list.clientHeight + 1,
                        visible: Array.from(list.children).filter(row => {
                            const rect = row.getBoundingClientRect();
                            return rect.top >= bounds.top && rect.bottom <= bounds.bottom + 1;
                        }).length};
                });
                assert.equal(size.overflow, resultCount > 10);
                assert.equal(size.visible, Math.min(resultCount, 10));
                assert.equal(await save.evaluate(button => button.classList.contains('form-submit-pending')), false);
            }
            const last = dialog.getByRole('button', {name: /Engagement · Event 24 /});
            await last.scrollIntoViewIfNeeded();
            await last.click();
            await dialog.waitFor({state: 'hidden'});
            assert.equal(await selected.inputValue(), 'engagement:33');
            assert.equal(await change.evaluate(button => button === document.activeElement), true);
            assert.equal(await save.evaluate(button => button.classList.contains('form-submit-pending')), true);
            await change.click();
            await search.fill('cancel this search');
            await page.keyboard.press('Escape');
            await dialog.waitFor({state: 'hidden'});
            assert.equal(await selected.inputValue(), 'engagement:33');
            await change.click();
            await page.mouse.click(2, 2);
            await dialog.waitFor({state: 'hidden'});
            assert.equal(await selected.inputValue(), 'engagement:33');
            failing = true;
            await change.click();
            await search.fill('offline');
            await page.getByText('Search is unavailable — your selection is unchanged', {exact: true}).waitFor();
            await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
            assert.equal(await selected.inputValue(), 'engagement:33');
            if (!duplicate) {
                await page.getByRole('button', {name: 'Clear Relationship', exact: true}).click();
                assert.equal(await selected.inputValue(), 'general');
                assert.equal(await save.evaluate(button => button.classList.contains('form-submit-pending')), false);
            }
            failing = false;
            await page.setViewportSize({width: 390, height: 844});
            await change.click(); await search.fill('mobile results');
            await dialog.getByRole('button', {name: /Engagement · Event 24 /}).waitFor({state: 'visible'});
            assert.ok(await dialog.evaluate(element => element.getBoundingClientRect().width <= innerWidth));
            assert.ok(await dialog.getByRole('button', {name: 'Cancel', exact: true}).evaluate(button => button.getBoundingClientRect().bottom <= innerHeight));
            assert.deepEqual(posts, []);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Task record dialog passed: visible launcher, selection, reminder integration, Escape/backdrop/Cancel, failed searches, ten-row scrolling with wrapped labels, duplicate validation, Enter, and mobile fit.');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
