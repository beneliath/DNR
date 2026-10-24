'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {inboxNotificationPermissionState,requestInboxNotificationPermission} = require('../../src/assets/js/audience-inbox.js');

function browser(permission, result = permission) {
    let requests = 0;
    return {isSecureContext:true,Notification:{permission,async requestPermission() { requests++; return result; }},get requests() { return requests; }};
}

test('blocked notifications explain site settings and do not repeat a denied prompt', async () => {
    const scope = browser('denied');
    const state = await requestInboxNotificationPermission(scope);
    assert.equal(scope.requests,0);
    assert.equal(state.state,'denied');
    assert.equal(state.label,'Check permission');
    assert.match(state.message,/site settings/);
});

test('a dismissed prompt stays retryable and is not described as blocked', async () => {
    const scope = browser('default');
    const state = await requestInboxNotificationPermission(scope);
    assert.equal(scope.requests,1);
    assert.equal(state.state,'default');
    assert.equal(state.disabled,false);
    assert.match(state.message,/not granted/);
    assert.doesNotMatch(state.message,/blocked/);
});

test('a granted request tells the user to save their notification preference', async () => {
    const scope = browser('default','granted');
    const state = await requestInboxNotificationPermission(scope);
    assert.equal(scope.requests,1);
    assert.equal(state.state,'granted');
    assert.equal(state.disabled,true);
    assert.match(state.message,/save your preferences/);
    assert.equal((await requestInboxNotificationPermission(browser('granted'))).state,'granted');
});

test('unsupported and insecure browsers cannot request permission', async () => {
    assert.equal(inboxNotificationPermissionState({isSecureContext:true}).state,'unsupported');
    const scope = {...browser('default'),isSecureContext:false};
    assert.equal((await requestInboxNotificationPermission(scope)).state,'insecure');
});

test('permission-request failures leave actionable feedback and a retryable button', async () => {
    const scope = browser('default');
    scope.Notification.requestPermission = async () => { throw new Error('Not supported in this context'); };
    const state = await requestInboxNotificationPermission(scope);
    assert.equal(state.disabled,false);
    assert.match(state.message,/could not request/);
});

test('pane resizing preserves reading space and clamps stored or dragged widths', () => {
    const {inboxPaneWidth} = require('../../src/assets/js/audience-inbox.js');
    assert.deepEqual(inboxPaneWidth(400,1200,240),{min:280,max:600,value:400});
    assert.equal(inboxPaneWidth(900,1200,240).value,600);
    assert.equal(inboxPaneWidth(100,1200,240).value,280);
    assert.equal(inboxPaneWidth(500,721,0).value,361);
    assert.equal(inboxPaneWidth(2000,2560,250).value,840);
});
