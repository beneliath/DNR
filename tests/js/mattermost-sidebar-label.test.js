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
