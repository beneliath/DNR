const test = require('node:test');
const assert = require('node:assert/strict');

let shortMOEDSidebarChannelDisplayName;

test.before(async () => {
    ({shortMOEDSidebarChannelDisplayName} = await import('../../mattermost-plugin/webapp/src/sidebar_channel_label.mjs'));
});

test('Mattermost sidebar labels omit only the signed token', () => {
    assert.equal(
        shortMOEDSidebarChannelDisplayName('[MOED#1.0KpUIVspeFZEcMJyDyGlyQ] Are You Ready?'),
        '[MOED#1] Are You Ready?'
    );
});

test('Mattermost sidebar labels preserve unrelated and malformed channel names', () => {
    assert.equal(shortMOEDSidebarChannelDisplayName('[MOED#17] Event team'), '[MOED#17] Event team');
    assert.equal(shortMOEDSidebarChannelDisplayName('General'), 'General');
    assert.equal(
        shortMOEDSidebarChannelDisplayName('[MOED#17.token-is-not-22-chars] Event team'),
        '[MOED#17.token-is-not-22-chars] Event team'
    );
});

test('Mattermost sidebar labels keep Unicode titles intact', () => {
    assert.equal(
        shortMOEDSidebarChannelDisplayName('  [MOED#23.ABCDEFGHIJKLMNOPQRSTUV]   Café — אירוע'),
        '[MOED#23] Café — אירוע'
    );
});

test('Mattermost sidebar labels compact account-scoped engagement markers', () => {
    for (const account of ['abc', 'shalom-in-messiah', 'a'.repeat(64)]) {
        assert.equal(
            shortMOEDSidebarChannelDisplayName(` [MOED@${account}#E2147483647.ABCDEFGHIJKLMNOPQRSTUV] Café — אירוע`),
            '[MOED#2147483647] Café — אירוע'
        );
    }
});

test('Mattermost sidebar labels leave invalid account and inquiry markers untouched', () => {
    for (const marker of [
        '[MOED@abc#I17.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@abc#E0.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@abc#E017.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@abc#E2147483648.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED#2147483648.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@ab#E17.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@ABC#E17.ABCDEFGHIJKLMNOPQRSTUV]',
        '[MOED@bad_key#E17.ABCDEFGHIJKLMNOPQRSTUV]',
        `[MOED@${'a'.repeat(65)}#E17.ABCDEFGHIJKLMNOPQRSTUV]`,
        '[MOED@abc#E17.short]',
        '[MOED@abc#E17.ABCDEFGHIJKLMNOPQRSTUVX]',
    ]) {
        const name = `${marker} Event team`;
        assert.equal(shortMOEDSidebarChannelDisplayName(name), name);
    }
});
