'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const playwright = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const engine = process.env.DNR_DATE_PICKER_BROWSER || 'chromium';
const coachForms = JSON.parse(fs.readFileSync(path.join(root, 'src/data/ai-coach-forms.json'), 'utf8')).forms;

(async () => {
    const browser = await playwright[engine].launch({headless: true, timeout: 30000, channel: process.env.DNR_DATE_PICKER_CHANNEL});
    try {
        const page = await browser.newPage({viewport: {width: 390, height: 844}, timezoneId: 'America/Chicago'});
        page.setDefaultTimeout(5000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin !== 'https://picker.test') return route.abort();
            if (route.request().method() === 'POST') return route.fulfill({body: 'Saved'});
            if (url.pathname.startsWith('/assets/')) {
                const file = path.join(root, 'src', url.pathname);
                return fs.existsSync(file) ? route.fulfill({path: file}) : route.fulfill({status: 404});
            }
            const fixture = url.pathname === '/campaign'
                ? ['tests/browser/audience-campaign-lifecycle-fixture.php', 'admin', 'active', 'locked', 'campaign-manager', 'agenda']
                : ['tests/browser/date-picker-fixture.php', url.pathname.slice(1)];
            const body = execFileSync('php', fixture, {cwd: root});
            return route.fulfill({contentType: 'text/html', body});
        });
        const stored = (form, name) => form.evaluate((node, key) => new FormData(node).get(key), name);
        const panel = page.locator('.date-picker-calendar');
        const visibleNative = () => page.locator('input[type=date]:visible,input[type=datetime-local]:visible').count();

        // Check each edge of the visible control against the next column, including
        // the label margin. A fixed calendar height breaks the expense and task rows.
        for (const width of [390, 900, 1600]) {
            await page.setViewportSize({width, height: 900});
            for (const [screen, date, neighbor] of [
                ['task', '[name=due_date]', '[name=assigned_to]'],
                ['expense', '[name=expense_date]', '[name=merchant]'],
            ]) {
                await page.goto('https://picker.test/' + screen);
                const field = page.locator('.date-picker-field').filter({has:page.locator(date)});
                const input = field.locator('input[type=text]:visible');
                const coachControl = screen === 'task' ? coachForms.task.controls['task-due']
                    : coachForms['reimbursement-expense'].controls['reimbursement-expense-date'];
                const coachTarget = page.locator(coachControl.selector).filter({visible: true});
                assert.equal(await coachTarget.count(), 1, screen + ' AI Coach targets the visible date editor');
                assert.equal(await coachTarget.getAttribute('id'), await input.getAttribute('id'));
                for (const dark of [false, true]) {
                    await page.evaluate(mode => document.documentElement.classList.toggle('dark-mode', mode), dark);
                    const rect = await input.boundingBox();
                    const sibling = await page.locator(neighbor).boundingBox();
                    assert.ok(Math.abs(rect.height - sibling.height) < 1, screen + ' field heights must match');
                    if (width > 390) assert.ok(Math.abs(rect.y - sibling.y) < 1, screen + ' fields must share a row');
                    function labelGap(node) {
                        const label = node.labels[0];
                        const line = Array.from(label.childNodes).find(child => child.nodeType === Node.TEXT_NODE && child.textContent.trim());
                        const range = document.createRange(); range.selectNodeContents(line);
                        return node.getBoundingClientRect().top - range.getBoundingClientRect().bottom;
                    }
                    assert.ok(Math.abs(await input.evaluate(labelGap) - await page.locator(neighbor).evaluate(labelGap)) < 1,
                        screen + ' label spacing must match');
                    const icon = await field.locator('svg').boundingBox();
                    assert.ok(Math.abs(icon.y + icon.height / 2 - rect.y - rect.height / 2) < 1);
                }
            }
        }
        await page.setViewportSize({width:390, height:844});
        // The Home palette, icon and day geometry must survive both page stylesheets.
        for (const dark of [false, true]) {
            let homeStyle;
            for (const screen of ['home', 'task']) {
                await page.goto('https://picker.test/' + screen);
                await page.evaluate(mode => document.documentElement.classList.toggle('dark-mode', mode), dark);
                if (screen === 'home') await page.locator('#analytics-range-trigger').click();
                else await page.locator('#task-due-date').evaluate(input => {
                    input.value = '2024-02-01'; input.dispatchEvent(new Event('input'));
                });
                const button = page.getByRole('button', {name: screen === 'home' ? 'Choose start date' : 'Choose due date'});
                const icon = await button.evaluate(node => {
                    const svg = node.querySelector('svg');
                    return [getComputedStyle(node).backgroundColor, getComputedStyle(svg).color, svg.getBoundingClientRect().width];
                });
                assert.deepEqual(icon, ['rgba(0, 0, 0, 0)', dark ? 'rgb(84, 201, 194)' : 'rgb(0, 124, 137)', 21]);
                await button.click();
                await page.mouse.move(0, 0);
                const style = await panel.evaluate(node => {
                    const plain = getComputedStyle(node.querySelector('[data-date="2024-02-02"]'));
                    const selected = getComputedStyle(node.querySelector('.selected'));
                    const month = getComputedStyle(node.querySelector('select'));
                    return [plain.backgroundColor, selected.backgroundColor, plain.color, plain.width, plain.height,
                        plain.fontSize, plain.fontWeight, month.height, month.borderRadius, getComputedStyle(node).backgroundColor];
                });
                assert.equal(style[0], 'rgba(0, 0, 0, 0)');
                assert.equal(style[1], icon[1]);
                if (screen === 'home') homeStyle = style;
                else assert.deepEqual(style, homeStyle);
            }
        }
        await page.goto('https://picker.test/task');
        const trigger = page.getByRole('button', {name: 'Choose due date'});
        const due = page.getByLabel('Due Date', {exact: true}).filter({visible: true});
        const form = page.locator('.follow-up-task-form');
        await trigger.click();
        assert.equal(await visibleNative(), 0);
        await trigger.click();
        assert.equal(await panel.count(), 0);
        await trigger.click();
        const bounds = await panel.boundingBox();
        assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390 && bounds.y >= 0 && bounds.y + bounds.height <= 844);
        await panel.getByLabel('Year', {exact: true}).selectOption('2028');
        await panel.getByLabel('Month', {exact: true}).selectOption('1');
        await panel.locator('[data-date="2028-02-29"]').click();
        assert.equal(await due.inputValue(), '02/29/2028');
        assert.equal(await stored(form, 'due_date'), '2028-02-29');
        await trigger.click();
        await panel.locator('[data-date="2028-02-29"]').press('ArrowRight');
        await page.keyboard.press('Enter');
        assert.equal(await stored(form, 'due_date'), '2028-03-01');
        await due.fill('');
        assert.equal(await stored(form, 'due_date'), '');
        await due.fill('02/29/2025');
        assert.equal(await due.evaluate(node => node.checkValidity()), false);
        await due.fill('2024-02-29');
        await due.press('Tab');
        assert.equal(await due.inputValue(), '02/29/2024');
        await due.press('ArrowDown');
        await page.keyboard.press('Escape');
        assert.equal(await trigger.evaluate(node => node === document.activeElement), true);

        // Controls inserted by a rule builder, a presentation row or a modal must work after startup.
        await page.evaluate(() => {
            const dialog = document.createElement('dialog');
            dialog.innerHTML = '<form><label>When (UTC)<input data-date-picker="datetime-local" name="when" value="2026-11-01T01:30" required></label>' +
                '<label>Birthday<input data-date-picker="birthday" placeholder="MM/DD" name="birthday"></label></form>';
            document.body.append(dialog);
            dialog.showModal();
        });
        const modal = page.locator('dialog[open]');
        await modal.getByRole('button', {name: 'Choose when (utc)'}).click();
        await panel.locator('[data-date="2026-11-03"]').click();
        assert.equal(await stored(modal.locator('form'), 'when'), '2026-11-03T01:30');
        await modal.locator('input[type=time]').fill('23:45');
        assert.equal(await stored(modal.locator('form'), 'when'), '2026-11-03T23:45');
        const birthday = modal.getByRole('button', {name: 'Choose birthday'});
        await birthday.click();
        assert.equal(await panel.getByLabel('Year', {exact: true}).count(), 0);
        await panel.getByLabel('Month', {exact: true}).selectOption('1');
        await panel.getByRole('button', {name: 'February 29', exact: true}).click();
        assert.equal(await stored(modal.locator('form'), 'birthday'), '02/29');
        await modal.locator('form').evaluate(node => node.reset());
        assert.equal(await modal.locator('input[type=time]').inputValue(), '01:30');
        assert.equal(await modal.locator('input[placeholder="MM/DD"]:visible').inputValue(), '');
        await modal.evaluate(node => node.close());

        const source = form.locator('[name=due_date]');
        await source.evaluate(input => { input.min = '2024-02-28'; input.max = '2024-03-01'; });
        await trigger.click();
        assert.equal(await panel.locator('[data-date="2024-02-27"]').isDisabled(), true);
        assert.equal(await panel.locator('[data-date="2024-03-02"]').isDisabled(), true);
        await panel.locator('[data-date="2024-03-01"]').click();
        await source.evaluate(input => { input.disabled = true; });
        assert.equal(await trigger.isDisabled(), true);
        assert.equal(await stored(form, 'due_date'), null);
        await source.evaluate(input => { input.disabled = false; input.readOnly = true; });
        assert.equal(await trigger.isDisabled(), true);
        assert.equal(await due.getAttribute('readonly'), '');
        await source.evaluate(input => { input.readOnly = false; input.required = true; });
        await due.fill('02/30/2024');
        await form.evaluate(node => node.reportValidity());
        await form.locator('.form-error-summary').waitFor();
        assert.equal(await form.locator('.form-error-summary li').count(), 1);
        assert.equal(await form.locator('.date-picker-box .form-field-error').count(), 0);
        await due.fill('02/29/2024');
        await form.evaluate(node => node.addEventListener('formdata', event => {
            sessionStorage.setItem('submitted-due-date', event.formData.get('due_date'));
        }, {once:true}));
        const posted = page.waitForRequest(request => request.method() === 'POST' && request.url().endsWith('/add_task.php'));
        await form.locator('button[type="submit"]').click();
        await posted;
        await page.waitForURL('**/add_task.php');
        assert.equal(await page.evaluate(() => sessionStorage.getItem('submitted-due-date')), '2024-02-29');

        await page.goto('https://picker.test/home');
        await page.locator('#analytics-range-trigger').click();
        const range = page.locator('[data-analytics-range]');
        const preset = range.locator('select[name=range]');
        const end = range.getByRole('button', {name: 'Choose end date'});
        await end.click();
        await panel.locator('[data-date="2024-02-29"]').click();
        assert.equal(await preset.inputValue(), 'custom');
        assert.equal(await stored(range, 'end'), '2024-02-29');
        await preset.selectOption('last7');
        assert.equal(await range.locator('#analytics-date-start-display').inputValue(), '02/24/2024');
        await range.getByRole('button', {name: 'Cancel'}).click();
        await page.locator('#analytics-range-trigger').click();
        assert.equal(await preset.inputValue(), 'last30');
        assert.equal(await stored(range, 'start'), '2024-02-01');
        const max = await range.locator('[name=end]').getAttribute('max');
        await range.getByLabel('End date', {exact: true}).filter({visible:true}).fill(max);
        await end.click();
        assert.equal(await panel.getByRole('button', {name: 'Next month'}).isDisabled(), true);
        await page.keyboard.press('Escape');
        assert.equal(await range.isVisible(), true);

        await page.goto('https://picker.test/campaign');
        await page.locator('[data-touchpoint-edit]').first().click();
        const touchpoint = page.locator('form[data-touchpoint-form]');
        const prior = await stored(touchpoint, 'start_at');
        const [startDate, startTime] = prior.split('T');
        assert.equal(await touchpoint.locator('.date-picker-time').first().inputValue(), startTime);
        await touchpoint.getByRole('button', {name: 'Choose start date and time (utc)'}).click();
        const nextDay = panel.locator('button[data-date]:not(.outside-month):not(.selected)').first();
        const nextISO = await nextDay.getAttribute('data-date');
        assert.notEqual(nextISO, startDate);
        await nextDay.click();
        assert.equal(await stored(touchpoint, 'start_at'), nextISO + 'T' + startTime);
        assert.equal(await visibleNative(), 0);

        await page.goto('https://picker.test/public');
        const publicBirthday = page.getByRole('button', {name: 'Choose birthday'});
        await publicBirthday.click();
        await panel.getByLabel('Month', {exact: true}).selectOption('1');
        await panel.getByRole('button', {name:'February 29', exact:true}).click();
        assert.equal(await stored(page.locator('form'), 'custom[2]'), '02-29');
        assert.equal(await visibleNative(), 0);
        await page.goto('https://picker.test/script-free');
        assert.equal(await page.locator('script[src*="date-picker"]').count(), 0);
        assert.equal(await visibleNative(), 0);
        await page.getByLabel('Date', {exact:true}).fill('2028-02-29');
        assert.equal(await stored(page.locator('form'), 'custom[1]'), '2028-02-29');
        assert.deepEqual(errors, []);
        console.log(engine + ' shared date picker passed for tasks, analytics, modal date-times, birthdays and embeds.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
