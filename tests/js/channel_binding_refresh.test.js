const assert = require('node:assert/strict');
const test = require('node:test');

let maximumBackoff;
let channelBindingRefreshDelay;

test.before(async () => {
    const refresh = await import('../../mattermost-plugin/webapp/src/channel_binding_refresh.mjs');
    maximumBackoff = refresh.CHANNEL_BINDING_MAXIMUM_BACKOFF_MILLISECONDS;
    channelBindingRefreshDelay = refresh.channelBindingRefreshDelay;
});

test('channel binding refresh applies bounded jitter and exponential backoff', () => {
    assert.equal(channelBindingRefreshDelay(0, 0), 51000);
    assert.equal(channelBindingRefreshDelay(0, 1), 69000);
    assert.equal(channelBindingRefreshDelay(1, 0.5), 120000);
    assert.equal(
        channelBindingRefreshDelay(20, 1),
        maximumBackoff,
    );
});
