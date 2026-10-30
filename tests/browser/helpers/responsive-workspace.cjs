'use strict';

const assert = require('node:assert/strict');

// Exercise the mobile disclosure before using the original action controls.
// Desktop actions remain visible without a disclosure.
async function expandRowActions(group, verifyDisclosure = false) {
    if (!(await group.count())) return false;
    // Mobile enhancement follows the initial shared-control normalization frame.
    await group.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const toggle = group.locator('.mm-mobile-action-toggle');
    if (!(await toggle.isVisible())) return false;
    const icons = group.locator('.action-icon-button');
    if (verifyDisclosure) {
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await icons.filter({visible: true}).count(), 1, 'Mobile rows start with their primary action');
        await toggle.focus();
        await toggle.press('Space');
        assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
        assert.equal(await icons.filter({visible: true}).count(), await icons.count(), 'More actions exposes every permitted operation');
        await toggle.press('Enter');
        assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
        assert.equal(await icons.filter({visible: true}).count(), 1, 'Fewer actions collapses the secondary operations');
    }
    if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
    return true;
}

async function assertActionGeometry(page, icons, mobile) {
    for (const icon of await icons.all()) {
        const box = await icon.boundingBox();
        assert.ok(box, 'Every permitted action is visible after expansion');
        if (mobile) {
            assert.ok(box.width >= 44 && box.height >= 44, `Mobile action ${await icon.getAttribute('aria-label')} has at least a 44px touch target: ${JSON.stringify(box)}`);
            assert.ok(box.x >= 0 && box.x + box.width <= page.viewportSize().width + 1, 'Mobile actions fit the viewport');
            assert.ok(await icon.locator('.mm-mobile-action-label').isVisible(), 'Mobile actions include a visible text label');
            assert.ok((await icon.locator('.mm-mobile-action-label').textContent()).trim());
        } else {
            assert.equal(box.width, 44, 'Desktop icons retain the shared 44px size');
            assert.equal(box.height, 44, 'Desktop icons retain the shared 44px size');
        }
    }
}

async function openDesignerTools(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    const workspace = page.getByRole('navigation', {name: 'Editor workspace', exact: true});
    if (await workspace.isVisible()) {
        const tools = workspace.getByRole('button', {name: 'Editing tools', exact: true});
        await tools.click();
        assert.equal(await tools.getAttribute('aria-pressed'), 'true');
    }
}

module.exports = {expandRowActions, assertActionGeometry, openDesignerTools};
