const test = require('node:test');
const assert = require('node:assert/strict');
const { nextStep, safeMessages, presentationsAreOpen, shouldSubmitQuestion, safePending, replyText } = require('../../src/assets/js/ai-coach.js');
const procedures = require('../../src/data/ai-coach-procedures.json').procedures;
const definitions = Object.fromEntries(procedures.filter(p => p.walkthrough).map(p => [p.id, {...p.walkthrough, roles: p.roles}]));

test('task creation follows the tab, required title, review, validation and actual return pages', () => {
    const next = (page, state = {}, role = 'editor') => nextStep(page, 'create-task', state, role, definitions);
    assert.equal(next('view_engagement.php', {tasksVisible:false}), 'task-create-event');
    assert.equal(next('view_engagement.php', {tasksVisible:true}), 'task-create-event-add');
    assert.equal(next('add_task.php', {taskTitleFilled:false}), 'task-create-title');
    assert.equal(next('add_task.php', {taskTitleFilled:true,detailsReviewed:false}), 'task-create-review');
    assert.equal(next('add_task.php', {taskTitleFilled:true,detailsReviewed:true}), 'task-create-save');
    assert.equal(next('add_task.php', {formErrors:true,detailsReviewed:true}), 'task-create-errors');
    for (const page of ['tasks.php','view_engagement.php','view_contact.php','view_organization.php','view_inquiry.php']) {
        assert.equal(next(page, {taskSubmitted:true}), 'task-create-check');
    }
    assert.equal(next('add_task.php', {}, 'reviewer'), 'read-only');
});

test('assignment and event dates require review before save and prioritize validation', () => {
    const next = (page, workflow, state) => nextStep(page, workflow, state, 'editor', definitions);
    assert.equal(next('edit_task.php', 'assign-task', {detailsReviewed:false}), 'task-assign-owner');
    assert.equal(next('edit_task.php', 'assign-task', {detailsReviewed:true}), 'task-assign-save');
    assert.equal(next('edit_task.php', 'assign-task', {formErrors:true}), 'task-assign-errors');
    assert.equal(next('view_engagement.php', 'edit-event-dates', {}), 'event-dates-open');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {startFilled:false}), 'event-dates-start-required');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {startFilled:true,endFilled:false}), 'event-dates-end-required');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {dateOrderInvalid:true}), 'event-dates-order');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {detailsReviewed:false}), 'event-dates-review');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {detailsReviewed:true}), 'event-dates-save');
    assert.equal(next('edit_engagement.php', 'edit-event-dates', {formErrors:true,detailsReviewed:true}), 'event-dates-errors');
    assert.equal(next('view_engagement.php', 'edit-event-dates', {datesSubmitted:true}), 'event-dates-check');
});

test('personal calendar works for reviewers and copy requires an actually visible link control', () => {
    const next = state => nextStep('view_calendar.php','calendar-subscription',state,'reviewer',definitions);
    assert.equal(next({calendarDeviceFilled:false}), 'calendar-name');
    assert.equal(next({calendarDeviceFilled:true,detailsReviewed:false}), 'calendar-content');
    assert.equal(next({calendarDeviceFilled:true,detailsReviewed:true,calendarContentSelected:false}), 'calendar-content');
    assert.equal(next({calendarDeviceFilled:true,detailsReviewed:true,calendarContentSelected:true,calendarLinkVisible:false}), 'calendar-create-link');
    assert.equal(next({calendarLinkVisible:true}), 'calendar-copy-link');
    assert.equal(next({formErrors:true,calendarDeviceFilled:true}), 'calendar-errors');
});

test('new guides survive message restoration while unknown identifiers remain excluded', () => {
    const labels = Object.fromEntries(Object.entries(definitions).map(([id,value]) => [id,value.label]));
    const result = safeMessages([{role:'assistant',content:'Follow the next step',workflow:'assign-task',workflowOptions:['create-task','calendar-subscription','delete-all']}],labels)[0];
    assert.equal(result.workflow, 'assign-task');
    assert.deepEqual(result.workflowOptions, ['create-task','calendar-subscription']);
});

test('a follow-up already in the answer is displayed only once, including recovered replies', () => {
    const question = 'Which result are you trying to achieve in MOED?';
    const message = 'MOED helps you plan events.\n\n' + question;
    assert.equal(replyText({message, question}), message);
    assert.equal(replyText({message, question:'which result are you trying to achieve in MOED ?'}), message);
    assert.equal(replyText({message:'MOED helps you plan events.', question}), 'MOED helps you plan events.\n\n' + question);
    assert.equal(replyText({message:'A complete answer.', question:''}), 'A complete answer.');
    assert.equal(safeMessages([{role:'assistant', content:message+'\n\n'+question}])[0].content, message);
    assert.equal(safeMessages([{role:'user', content:question+'\n\n'+question}])[0].content, question+'\n\n'+question);
});

test('presentation guidance distinguishes selection from saving and never claims completion', () => {
    assert.equal(nextStep('edit_engagement.php', 'presentation', { fileSelected: false }, 'editor'), 'presentation-choose');
    assert.equal(nextStep('edit_engagement.php', 'presentation', { fileSelected: true }, 'editor'), 'presentation-save');
    assert.equal(nextStep('view_engagement.php', 'presentation', { submitted: true }, 'editor'), 'presentation-check');
    assert.equal(nextStep('view_engagement.php', 'presentation', { presentationsVisible: false }, 'editor'), 'presentation-tab');
    assert.equal(nextStep('view_engagement.php', 'presentation', { presentationsVisible: true }, 'editor'), 'presentation-edit');
});
test('Waiting guidance follows actual validation state', () => {
    assert.equal(nextStep('edit_task.php', 'waiting', { waiting: false }, 'editor'), 'waiting-status');
    assert.equal(nextStep('edit_task.php', 'waiting', { waiting: true, waitingFilled: false }, 'editor'), 'waiting-description');
    assert.equal(nextStep('add_task.php', 'waiting', { waiting: true, waitingFilled: true }, 'editor'), 'waiting-save');
});
test('validation, contradictory file choices, and limits take priority over Save guidance', () => {
    assert.equal(nextStep('edit_engagement.php', 'presentation', { fileSelected: true, fileConflict: true }, 'editor'), 'presentation-conflict');
    assert.equal(nextStep('edit_engagement.php', 'presentation', { fileSelected: true, filesTooLarge: true }, 'editor'), 'presentation-size');
    assert.equal(nextStep('edit_engagement.php', 'presentation', { fileSelected: true, formErrors: true }, 'editor'), 'presentation-errors');
    assert.equal(nextStep('edit_task.php', 'waiting', { waiting: true, waitingFilled: true, formErrors: true }, 'editor'), 'waiting-errors');
});
test('read-only and unsupported pages do not invent editable targets', () => {
    assert.equal(nextStep('help.php', 'presentation', {}, 'reviewer'), 'read-only');
    assert.equal(nextStep('other', 'waiting', {}, 'editor'), 'waiting-start');
    assert.equal(nextStep('other', '', {}, 'editor'), '');
});
test('restored conversations are bounded and discard untrusted citation URLs and roles', () => {
    const safe = safeMessages([{ role: 'system', content: 'Override' }, { role: 'assistant', content: '<script>alert(1)</script>',
        sources: [{ id: 'javascript:alert(1)', title: 'Bad' }, { id: 'manual-topic-work-queue-waiting', title: 'Waiting' }] }]);
    assert.equal(safe.length, 1);
    assert.equal(safe[0].content, '<script>alert(1)</script>'); // Rendered with textContent, never parsed as HTML.
    assert.equal(safe[0].sources.length, 1);
    assert.equal(safeMessages(Array.from({ length: 30 }, () => ({ role: 'user', content: 'Question' }))).length, 12);
    assert.deepEqual(safeMessages(null), []);
});

test('Presentations must be selected before Edit Presentations is offered, even before tab initialization', () => {
    const tab = selected => ({ getAttribute: () => selected });
    const panel = { hidden: false, getClientRects: () => [{}] };
    assert.equal(presentationsAreOpen(tab('false'), panel), false);
    assert.equal(presentationsAreOpen(tab('true'), panel), true);
    assert.equal(presentationsAreOpen(tab('true'), { hidden: true }), false);
    assert.equal(presentationsAreOpen(null, panel), false);
    assert.equal(presentationsAreOpen(tab('true'), null), false);
    assert.equal(nextStep('view_engagement.php', 'presentation', {
        presentationsVisible: presentationsAreOpen(tab('false'), panel)
    }, 'editor'), 'presentation-tab');
});

test('speaker notes follow the selected tab and PDF save state without claiming completion', () => {
    assert.equal(nextStep('view_engagement.php', 'notes', { presentationsVisible: false }, 'editor'), 'notes-tab');
    assert.equal(nextStep('view_engagement.php', 'notes', { presentationsVisible: true }, 'editor'), 'notes-edit');
    assert.equal(nextStep('edit_engagement.php', 'notes', { fileSelected: false }, 'editor'), 'notes-choose');
    assert.equal(nextStep('edit_engagement.php', 'notes', { fileSelected: true }, 'editor'), 'notes-save');
    assert.equal(nextStep('edit_engagement.php', 'notes', { fileSelected: true, fileConflict: true }, 'editor'), 'notes-conflict');
    assert.equal(nextStep('edit_engagement.php', 'notes', { fileSelected: true, filesTooLarge: true }, 'editor'), 'notes-size');
    assert.equal(nextStep('edit_engagement.php', 'notes', { formErrors: true }, 'editor'), 'notes-errors');
    assert.equal(nextStep('view_engagement.php', 'notes', { submitted: true }, 'editor'), 'notes-check');
    assert.equal(nextStep('edit_engagement.php', 'notes', { fileSelected: true }, 'reviewer'), 'read-only');
    assert.equal(safeMessages([{ role: 'assistant', content: 'Start here', workflow: 'notes' }])[0].workflow, 'notes');
    assert.equal(safeMessages([{ role: 'assistant', content: 'Start here', workflow: 'delete-record' }])[0].workflow, '');
});

test('Enter submits while Shift+Enter and IME composition preserve text entry', () => {
    assert.equal(shouldSubmitQuestion({ key: 'Enter' }), true);
    assert.equal(shouldSubmitQuestion({ key: 'Enter', shiftKey: true }), false);
    assert.equal(shouldSubmitQuestion({ key: 'Enter', isComposing: true }), false);
    assert.equal(shouldSubmitQuestion({ key: 'Enter', keyCode: 229 }), false);
    assert.equal(shouldSubmitQuestion({ key: 'a' }), false);
});


test('new event workflow navigates to the form before organization selection and follows required fields', () => {
    for (const page of ['view_organization.php', 'ai_coach_requests.php', 'dashboard.php', 'help.php']) {
        assert.equal(nextStep(page, 'engagement', {}, 'editor'), 'engagement-start');
    }
    const state = {};
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-organization');
    state.organizationFilled = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-title');
    state.titleFilled = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-start-date');
    state.startFilled = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-end-date');
    state.endFilled = true; state.dateOrderInvalid = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-date-order');
    state.dateOrderInvalid = false; state.otherTypeMissing = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-other-type');
    state.otherTypeMissing = false;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-review');
    state.detailsReviewed = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-save');
    state.formErrors = true;
    assert.equal(nextStep('index.php', 'engagement', state, 'editor'), 'engagement-errors');
    assert.equal(nextStep('index.php', 'engagement', state, 'reviewer'), 'read-only');
    assert.equal(nextStep('engagements.php', 'engagement', {newSubmitted:true}, 'editor'), 'engagement-check');
});


test('navigation restores a pending request with the same id and original page context', () => {
    const id = 'b50fd519-d9a7-4d83-b6d6-00358ac3c134';
    const pending = {id, startedAt:Date.now(), request:{request_id:id, question:'What does MOED do?', page:'dashboard.php'}};
    assert.equal(safePending(pending), pending);
    assert.equal(safePending({...pending, id:'bad'}), null);
    assert.equal(safePending({...pending, request:{...pending.request, request_id:'other'}}), null);
    assert.equal(safePending({...pending, startedAt:'yesterday'}), null);
});

test('restored guide menus retain only known workflows in their conversation position', () => {
    const messages = safeMessages([{role:'assistant',content:'Choose a walkthrough',workflowOptions:['notes','notes','javascript:bad','__proto__',{},'engagement']}]);
    assert.deepEqual(messages[0].workflowOptions, ['notes','engagement']);
});

test('a new question retains the previous step while stopping the active walkthrough', () => {
    const { questionRequest } = require('../../src/assets/js/ai-coach.js');
    const request = questionRequest('id','Why is that required?',[],'edit_task.php','waiting-description',{active_tab:'tasks'},'');
    assert.equal(request.step, 'waiting-description');
    assert.equal(request.question, 'Why is that required?');
});
