(function () {
    'use strict';
    const workflowLabels = { engagement: 'add a new event', notes: 'add speaker notes pdf', presentation: 'add a powerpoint', waiting: 'understand waiting tasks' };

    function nextStep(page, workflow, state, role, definitions = {}) {
        if (!workflow) return '';
        const definition = definitions[workflow];
        if (definition && !definition.roles.includes(role)) return 'read-only';
        if (definition && definition.rules) {
            const rule = definition.rules.find(rule => rule.pages.includes(page) && Object.entries(rule.conditions).every(([key, value]) => state[key] === value));
            return rule ? rule.step : definition.default_step;
        }
        if (role === 'reviewer') return 'read-only';
        if (workflow === 'presentation' || workflow === 'notes') {
            const prefix = workflow === 'notes' ? 'notes-' : 'presentation-';
            if (page === 'engagements.php') return prefix + 'record';
            if (page === 'view_engagement.php') {
                if (state.submitted) return prefix + 'check';
                return state.presentationsVisible ? prefix + 'edit' : prefix + 'tab';
            }
            if (page === 'edit_engagement.php') {
                if (state.formErrors) return prefix + 'errors';
                if (state.fileConflict) return prefix + 'conflict';
                if (state.filesTooLarge) return prefix + 'size';
                return state.fileSelected ? prefix + 'save' : prefix + 'choose';
            }
            return prefix + 'start';
        }
        if (workflow === 'engagement') {
            if (page === 'engagements.php' && state.newSubmitted) return 'engagement-check';
            if (page !== 'index.php') return 'engagement-start';
            if (state.formErrors) return 'engagement-errors';
            if (!state.organizationFilled) return 'engagement-organization';
            if (!state.titleFilled) return 'engagement-title';
            if (!state.startFilled) return 'engagement-start-date';
            if (!state.endFilled) return 'engagement-end-date';
            if (state.dateOrderInvalid) return 'engagement-date-order';
            if (state.otherTypeMissing) return 'engagement-other-type';
            return state.detailsReviewed ? 'engagement-save' : 'engagement-review';
        }
        if (workflow === 'waiting') {
            if (page === 'tasks.php') return 'waiting-record';
            if (page === 'edit_task.php' || page === 'add_task.php') {
                if (state.formErrors) return 'waiting-errors';
                if (!state.waiting) return 'waiting-status';
                return state.waitingFilled ? 'waiting-save' : 'waiting-description';
            }
            return 'waiting-start';
        }
        return '';
    }

    function presentationsAreOpen(tab, panel) {
        // Layout boxes exist before record tabs initialize; selection is authoritative.
        return !!tab && tab.getAttribute('aria-selected') === 'true' && !!panel && !panel.hidden;
    }

    function shouldSubmitQuestion(event) {
        return event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229;
    }

    function replyText(data) {
        const answer = typeof data.message === 'string' ? data.message.trim() : '';
        const followUp = typeof data.question === 'string' ? data.question.trim() : '';
        const normalize = text => text.normalize('NFKC').toLowerCase().replace(/[\p{P}\p{Z}\s]+/gu, ' ').trim();
        const questionText = normalize(followUp);
        return answer + (questionText && !(' ' + normalize(answer) + ' ').includes(' ' + questionText + ' ') ? '\n\n' + followUp : '');
    }

    function restoredReplyText(content) {
        const paragraphs = content.split(/\n\s*\n/);
        const last = paragraphs[paragraphs.length - 1].trim();
        if (paragraphs.length < 2 || !last.endsWith('?')) return content;
        // Older tab history already contains the concatenated fields. Only remove
        // a final question when the same question is present in the preceding text.
        return replyText({message:paragraphs.slice(0, -1).join('\n\n'), question:last});
    }

    function safeMessages(messages, labels = workflowLabels) {
        if (!Array.isArray(messages)) return [];
        return messages.slice(-12).filter(function (message) {
            return message && ['user', 'assistant'].includes(message.role)
                && typeof message.content === 'string' && message.content.length <= 2000;
        }).map(function (message) {
            return { id: typeof message.id === 'string' && /^[0-9a-f-]{36}$/.test(message.id) ? message.id : '',
                loggedRequest: message.loggedRequest === true,
                guideStep: typeof message.guideStep === 'string' && /^[a-z-]+$/.test(message.guideStep) ? message.guideStep : '',
                role: message.role, content: message.role === 'assistant' ? restoredReplyText(message.content) : message.content,
                sources: (Array.isArray(message.sources) ? message.sources : []).slice(0, 4).filter(function (source) {
                    return source && typeof source.id === 'string' && /^manual-topic-[a-z0-9#-]+$/.test(source.id)
                        && typeof source.title === 'string' && source.title.length <= 250;
                }).map(function (source) {
                    return { id: source.id, title: source.title, page: Number.isInteger(source.page) && source.page > 0 && source.page <= 1000 ? source.page : null };
                }), mode: ['guide', 'manual', 'conversation'].includes(message.mode) ? message.mode : 'guide',
                feedback: ['helpful', 'needs_work', 'control_missing'].includes(message.feedback) ? message.feedback : '',
                workflowOptions: [...new Set(Array.isArray(message.workflowOptions) ? message.workflowOptions : [])].filter(id => typeof id === 'string' && Object.hasOwn(labels, id)),
                workflow: typeof message.workflow === 'string' && Object.hasOwn(labels, message.workflow) ? message.workflow : '' };
        });
    }

    function safePending(value) {
        if (!value || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(value.id)
            || !Number.isFinite(value.startedAt) || !value.request || value.request.request_id !== value.id
            || typeof value.request.question !== 'string' || value.request.question.length > 1200) return null;
        return value;
    }

    function questionRequest(id, text, history, page, step, ui, topic) {
        return { request_id: id, question: text, history: history, page: page, step: step, ui: ui, topic: topic };
    }

    if (typeof module === 'object' && module.exports) module.exports = { nextStep, safeMessages, presentationsAreOpen, shouldSubmitQuestion, safePending, replyText, questionRequest };
    if (typeof document === 'undefined') return;
    const panel = document.querySelector('[data-coach]');
    if (!panel) return;
    const guideCard = panel.querySelector('[data-coach-step]');
    const guideFields = ['workflow-title', 'step-message', 'show', 'go', 'source', 'step-note', 'acknowledge', 'end', 'missing'];
    const find = function (name) {
        if (name === 'step') return guideCard;
        return (guideFields.includes(name) ? guideCard : panel).querySelector('[data-coach-' + name + ']');
    };
    const messageNodes = new Map();
    const steps = JSON.parse(find('steps').textContent);
    const definitions = JSON.parse(panel.querySelector('[data-coach-workflows]')?.textContent || '{}');
    const controls = JSON.parse(panel.querySelector('[data-coach-controls]')?.textContent || '{}');
    Object.entries(definitions).forEach(([id, definition]) => { workflowLabels[id] = definition.label; });
    const page = panel.dataset.page;
    const role = panel.dataset.role;
    const narrow = window.matchMedia('(max-width: 1100px)');
    const storageName = 'moed-coach-session';
    const openers = Array.from(document.querySelectorAll('[data-coach-open]'));
    const question = panel.querySelector('textarea');
    const scrollArea = panel.querySelector('.coach-scroll');
    let saved = {};
    try {
        const value = JSON.parse(window.sessionStorage.getItem(storageName) || '{}');
        if (value.key === panel.dataset.storageKey) saved = value;
        else window.sessionStorage.removeItem(storageName);
    } catch (_) { /* Storage is optional. */ }
    let conversationScroll = Math.max(0, Number(saved.scrollTop) || 0);
    let messages = safeMessages(saved.messages);
    let pending = safePending(saved.pending);
    let pollTimer = null;
    let workflow = typeof saved.workflow === 'string' && Object.hasOwn(workflowLabels, saved.workflow) ? saved.workflow : '';
    messages.forEach(function (message) { if (!message.id) message.id = window.crypto.randomUUID(); });
    let activeGuideId = typeof saved.activeGuideId === 'string' ? saved.activeGuideId : '';
    if (workflow && !activeGuideId) activeGuideId = messages.filter(function (m) { return m.workflow === workflow; }).slice(-1)[0]?.id || '';
    let submittedRecord = typeof saved.submittedRecord === 'string' ? saved.submittedRecord : '';
    let detailsReviewed = false;
    let currentStep = '';
    let controller = null;
    let requestNumber = 0;
    let returnFocus = null;
    const inertNodes = new Map();
    find('context').textContent = panel.dataset.pageLabel || 'MOED';
    document.body.classList.add('has-coach');

    function persist() {
        try {
            window.sessionStorage.setItem(storageName, JSON.stringify({ key: panel.dataset.storageKey,
                open: !panel.hidden, activeGuideId: activeGuideId, scrollTop: panel.hidden ? conversationScroll : scrollArea.scrollTop, pending: pending, workflow: workflow, submittedRecord: submittedRecord, messages: messages.slice(-12) }));
        } catch (_) { /* Do not block help if browser storage is full or disabled. */ }
    }

    function updateModal() {
        inertNodes.forEach(function (value, node) { node.inert = value; });
        inertNodes.clear();
        panel.removeAttribute('role');
        panel.removeAttribute('aria-modal');
        if (narrow.matches && !panel.hidden) {
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
            Array.from(document.body.children).forEach(function (node) {
                if (node === panel || node.tagName === 'SCRIPT' || node.tagName === 'STYLE') return;
                inertNodes.set(node, node.inert);
                node.inert = true;
            });
        }
    }

    function setOpen(open, focus) {
        const wasHidden = panel.hidden;
        if (open && wasHidden) returnFocus = document.activeElement;
        if (!open && !wasHidden) conversationScroll = scrollArea.scrollTop;
        panel.hidden = !open;
        document.body.classList.toggle('coach-open', open);
        openers.forEach(function (button) { button.setAttribute('aria-expanded', String(open)); });
        updateModal();
        if (open && wasHidden) window.requestAnimationFrame(function () { scrollArea.scrollTop = conversationScroll; });
        if (focus) {
            if (open) question.focus();
            else if (returnFocus && returnFocus.isConnected && !returnFocus.closest('[data-coach]')) returnFocus.focus();
            else openers[0].focus();
        }
        persist();
    }

    function state() {
        const value = function (id) { return (document.getElementById(id)?.value || '').trim(); };
        const status = document.getElementById('task-status');
        const waiting = document.getElementById('task-waiting-on');
        const presentations = document.getElementById('engagement-presentations');
        const attachmentPrefix = workflow === 'notes' ? 'speaker_notes_' : 'ppt_slidedeck_';
        const attachmentInputs = Array.from(document.querySelectorAll('input[type="file"][id^="' + attachmentPrefix + '"]'));
        const allFiles = Array.from(document.querySelectorAll('#engagement-edit-form input[type="file"]')).flatMap(function (input) { return Array.from(input.files || []); });
        return {
            organizationFilled: !!value('organization_id'), titleFilled: !!value('event_title'),
            startFilled: !!value('event_start_date'), endFilled: !!value('event_end_date'),
            dateOrderInvalid: value('event_end_date') < value('event_start_date'),
            otherTypeMissing: value('event_type') === 'other' && !value('event_type_other'),
            detailsReviewed: detailsReviewed, newSubmitted: submittedRecord === 'new-engagement',
            fileSelected: attachmentInputs.some(function (input) { return input.files && input.files.length > 0; }),
            fileConflict: attachmentInputs.some(function (input) {
                const card = input.closest('.presentation-notes-card');
                return input.files && input.files.length > 0 && card && !!card.querySelector('input[type="checkbox"]:checked');
            }),
            filesTooLarge: attachmentInputs.some(function (input) { return Array.from(input.files || []).some(function (file) { return file.size > (workflow === 'notes' ? 100 : 500) * 1048576; }); })
                || allFiles.reduce(function (total, file) { return total + file.size; }, 0) >= 600 * 1048576,
            formErrors: !!targetFor('form-errors'),
            waiting: !!status && status.value === 'waiting', waitingFilled: !!waiting && waiting.value.trim() !== '',
            presentationsVisible: presentationsAreOpen(document.getElementById('engagement-presentations-tab'), presentations),
            tasksVisible: presentationsAreOpen(document.getElementById('engagement-tasks-tab'), document.getElementById('engagement-tasks')),
            taskTitleFilled: !!value('task-title'), taskSubmitted: submittedRecord === 'task',
            datesSubmitted: submittedRecord === 'event-dates:' + (new URL(window.location.href).searchParams.get('id') || ''),
            calendarDeviceFilled: !!value('subscription-label'), calendarLinkVisible: !!targetFor('calendar-copy'),
            calendarContentSelected: !!document.querySelector('.calendar-content-options input:checked'),
            submitted: submittedRecord !== '' && submittedRecord === new URL(window.location.href).searchParams.get('id')
        };
    }

    function targetFor(name) {
        // Deliberately fixed selectors. No selector, URL, or script comes from the model.
        const selectors = Object.fromEntries(Object.entries(controls).map(([id, control]) => [id, control.selector]));
        const matches = selectors[name] ? Array.from(document.querySelectorAll(selectors[name])).filter(function (node) { return !node.closest('[hidden]') && node.getClientRects().length > 0; }) : [];
        // With several presentations, highlight the section so the user chooses the right one.
        const element = (name === 'ppt-picker' || name === 'pdf-picker') && matches.length > 1
            ? document.getElementById('presentations-container') : matches[0];
        return element && !element.disabled && !element.closest('[hidden]') && element.getClientRects().length > 0 ? element : null;
    }

    function uiContext() {
        const ids = Object.keys(controls);
        const selected = document.querySelector('[role="tab"][aria-selected="true"]');
        const tab = (selected?.id || '').replace(/^engagement-/, '').replace(/-tab$/, '');
        return { observed: true, visible_controls: ids.filter(id => !!targetFor(id)), active_tab: tab, form_errors: !!targetFor('form-errors') };
    }

    async function coachEvent(action, id, extra) {
        const response = await fetch(panel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', redirect: 'error', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrfToken },
            signal: AbortSignal.timeout(5000),
            body: JSON.stringify({ action: action, request_id: id, location: { page: page, step: currentStep, workflow: workflow }, ...extra }) });
        if (!response.ok) throw new Error('Feedback could not be saved.');
        return response.json();
    }

    function clearHighlight() {
        document.querySelectorAll('.coach-control-highlight').forEach(function (node) { node.classList.remove('coach-control-highlight'); });
    }

    function updateStep() {
        const next = nextStep(page, workflow, state(), role, definitions);
        if (next !== currentStep) clearHighlight();
        currentStep = next;
        find('step').hidden = !steps[next];
        find('welcome').hidden = messages.length > 0 || !!next;
        if (!steps[next]) return;
        const step = steps[next];
        const activeMessage = messages.find(function (m) { return m.id === activeGuideId; });
        if (activeMessage) activeMessage.guideStep = next;
        const targetVisible = step.target && !!targetFor(step.target);
        find('acknowledge').hidden = !step.acknowledge || (step.target && !targetVisible);
        find('workflow-title').textContent = workflowLabels[workflow] || 'walkthrough';
        find('step-message').textContent = step.target && !targetVisible
            ? 'The next step uses ' + (step.label || 'this control').replace(/^show /i, '') + ', but it is not visible on this page. I cannot determine why it is missing. Use the manual or report the missing control below.'
            : step.message;
        find('show').hidden = !targetVisible;
        find('show').textContent = (step.label || 'show me').toLowerCase();
        find('go').hidden = !step.href;
        if (step.href) { find('go').href = step.href; find('go').textContent = step.label.toLowerCase(); }
        find('step-note').textContent = step.target && !targetVisible
            ? 'That control is not visible here. Check the selected page or tab, or report it with i can’t see that control.'
            : 'You make the changes; the coach follows your progress.';
    }

    function showSource(source) {
        const id = source.id;
        if (!/^manual-topic-[a-z0-9#-]+$/.test(id)) return;
        if (Number.isInteger(source.page) && source.page > 0 && source.page <= 1000) {
            window.open('assets/docs/moed-comprehensive-user-manual.pdf#page=' + source.page, '_blank', 'noopener');
            return;
        }
        if (narrow.matches) setOpen(false, false);
        if (page === 'help.php') document.dispatchEvent(new CustomEvent('moed:manual-topic', { detail: id }));
        else window.location.assign('help.php#' + encodeURIComponent(id));
    }

    function pastGuide(message) {
        const definition = steps[message.guideStep];
        if (!definition) return null;
        const card = document.createElement('section'); card.className = 'coach-step coach-step-history';
        const title = document.createElement('strong');
        title.textContent = message.workflow === 'engagement' ? 'Add a new event' : message.workflow === 'notes' ? 'Add speaker notes PDF' : message.workflow === 'presentation' ? 'Add a PowerPoint' : 'Understand Waiting tasks';
        const copy = document.createElement('p'); copy.textContent = definition.message;
        const resume = document.createElement('button'); resume.type = 'button'; resume.className = 'button-secondary'; resume.textContent = 'resume walkthrough';
        resume.addEventListener('click', function () { requestWorkflow(message.workflow); });
        card.append(title, copy, resume); return card;
    }

    function archiveGuide() {
        const message = messages.find(function (m) { return m.id === activeGuideId; });
        const node = messageNodes.get(activeGuideId);
        guideCard.remove();
        if (message && node) { const card = pastGuide(message); if (card) node.append(card); }
        activeGuideId = '';
    }

    function endWorkflow() {
        archiveGuide();
        workflow = ''; submittedRecord = ''; detailsReviewed = false;
        clearHighlight(); updateStep();
    }

    function appendMessage(message) {
        const item = document.createElement('article');
        item.className = 'coach-message coach-message-' + message.role;
        if (message.id) messageNodes.set(message.id, item);
        const label = document.createElement('strong');
        label.textContent = message.role === 'user' ? 'You' : message.mode === 'manual' ? 'ai coach · Comprehensive Manual' : message.mode === 'conversation' ? 'ai coach' : 'ai coach · guided step';
        const copy = document.createElement('p');
        copy.textContent = message.content;
        item.append(label, copy);
        if (message.role === 'assistant' && message.id && message.loggedRequest) {
            const feedback = document.createElement('div'); feedback.className = 'coach-feedback';
            [['helpful', 'helpful'], ['needs_work', 'needs work']].forEach(function ([value, label]) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button-secondary';
                button.textContent = label; button.setAttribute('aria-pressed', String(message.feedback === value));
                button.addEventListener('click', async function () {
                    try {
                        const result = await coachEvent('feedback', message.id, { feedback: value, ui: uiContext() });
                        if (!result.saved) { find('status').textContent = 'This answer is no longer in the request log.'; return; }
                        message.feedback = value;
                        feedback.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
                        find('status').textContent = 'Thank you. Your feedback was saved.'; persist();
                    } catch (_) { find('status').textContent = 'Feedback could not be saved. Please try again.'; }
                });
                feedback.append(button);
            });
            item.append(feedback);
        }
        if (message.sources && message.sources.length) {
            const sources = document.createElement('div');
            sources.className = 'coach-sources';
            message.sources.forEach(function (source) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-secondary';
                button.textContent = source.title + (source.page ? ' · PDF p. ' + source.page : '');
                button.addEventListener('click', function () { showSource(source); });
                sources.append(button);
            });
            item.append(sources);
        }
        if (message.workflowOptions && message.workflowOptions.length) {
            const options = document.createElement('div'); options.className = 'coach-starters';
            message.workflowOptions.forEach(function (id) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button-secondary';
                button.textContent = workflowLabels[id]; button.addEventListener('click', function () { requestWorkflow(id); }); options.append(button);
            });
            item.append(options);
        }
        if (message.workflow && !message.guideStep) {
            const start = document.createElement('button');
            start.type = 'button';
            start.className = 'button-primary';
            start.textContent = 'start walkthrough: ' + workflowLabels[message.workflow];
            start.addEventListener('click', function () { requestWorkflow(message.workflow); });
            item.append(start);
        }
        if (message.id && message.id === activeGuideId && workflow) item.append(guideCard);
        else if (message.guideStep) { const card = pastGuide(message); if (card) item.append(card); }
        find('messages').append(item);
    }

    function renderMessages() {
        guideCard.remove();
        messageNodes.clear();
        find('messages').replaceChildren();
        updateStep();
        messages.forEach(appendMessage);
    }

    function scrollToLatestExchange() {
        window.requestAnimationFrame(function () {
            const questions = find('messages').querySelectorAll('.coach-message-user');
            const anchor = questions[questions.length - 1];
            if (anchor) scrollArea.scrollTop += anchor.getBoundingClientRect().top - scrollArea.getBoundingClientRect().top - 12;
            else scrollArea.scrollTop = scrollArea.scrollHeight;
        });
    }

    function addMessage(message) {
        messages.push(message);
        messages = messages.slice(-12);
        if (activeGuideId && !messages.some(function (entry) { return entry.id === activeGuideId; })) endWorkflow();
        // Append only the new answer to the live region, rather than re-announcing history.
        appendMessage(message);
        while (find('messages').children.length > 12) find('messages').firstElementChild.remove();
        messageNodes.forEach(function (_, id) { if (!messages.some(function (entry) { return entry.id === id; })) messageNodes.delete(id); });
        find('welcome').hidden = true;
        scrollToLatestExchange();
        persist();
    }

    function setBusy(busy) {
        find('send').disabled = busy;
        find('stop').hidden = !busy;
        question.readOnly = busy;
        find('status').textContent = busy ? 'Thinking through your question…' : '';
    }

    openers.forEach(function (button) { button.addEventListener('click', function () { setOpen(true, true); updateStep(); }); });
    find('close').addEventListener('click', function () { setOpen(false, true); });
    document.querySelectorAll('#app-sidebar a[href="help.php"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            setOpen(false, false);
        });
    });
    narrow.addEventListener('change', function () { updateModal(); if (!panel.hidden && narrow.matches) question.focus(); });
    panel.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
        if (event.key === 'Tab' && narrow.matches) {
            const focusable = Array.from(panel.querySelectorAll('button, a[href], textarea')).filter(function (node) { return !node.disabled && node.getClientRects().length; });
            const first = focusable[0]; const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    function requestWorkflow(name) {
        if (controller) return;
        if (!definitions[name]) return;
        question.value = definitions[name].request;
        find('form').requestSubmit(find('send'));
    }
    find('acknowledge').addEventListener('click', function () { detailsReviewed = true; updateStep(); });
    panel.querySelectorAll('[data-coach-workflow]').forEach(function (button) {
        button.addEventListener('click', function () { requestWorkflow(button.dataset.coachWorkflow); });
    });
    find('end').addEventListener('click', function () { endWorkflow(); persist(); });
    find('source').addEventListener('click', function () { if (steps[currentStep]) showSource({ id: steps[currentStep].source, page: steps[currentStep].source_page }); });
    find('show').addEventListener('click', function () {
        const step = steps[currentStep];
        const target = step && targetFor(step.target);
        if (!target) {
            find('step-note').textContent = 'That control is not visible here. Check the current tab and your access, or open the manual topic.';
            if (activeGuideId) coachEvent('feedback', activeGuideId, { feedback: 'control_missing', ui: uiContext() }).catch(function () {});
            return;
        }
        const previousScroll = scrollArea.scrollTop;
        conversationScroll = previousScroll;
        if (narrow.matches) setOpen(false, false);
        clearHighlight();
        target.classList.add('coach-control-highlight');
        target.scrollIntoView({ block: 'center', behavior: 'auto' });
        // Focus and highlight only. Never click, submit, or fill a control.
        if (!target.matches('button, input, select, textarea, a[href]')) target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
        scrollArea.scrollTop = previousScroll;
        window.requestAnimationFrame(function () {
            scrollArea.scrollTop = previousScroll;
            persist();
        });
    });
    find('missing').addEventListener('click', async function () {
        if (!activeGuideId) return;
        try {
            await coachEvent('feedback', activeGuideId, { feedback: 'control_missing', ui: uiContext() });
            find('step-note').textContent = 'Recorded for review. Check the selected tab; the matching manual topic is available below.';
        } catch (_) { find('step-note').textContent = 'Could not record that feedback. Please try again.'; }
    });
    // Tab changes can come from initial setup, keyboard navigation, links or history.
    // Observe only these attributes, so highlighting never causes an update loop.
    const tabObserver = new MutationObserver(updateStep);
    document.querySelectorAll('[role="tab"]').forEach(function (tab) {
        tabObserver.observe(tab, { attributes: true, attributeFilter: ['aria-selected'] });
        const content = document.getElementById(tab.getAttribute('aria-controls'));
        if (content) tabObserver.observe(content, { attributes: true, attributeFilter: ['hidden'] });
    });
    document.addEventListener('change', function (event) {
        if (!panel.contains(event.target)) detailsReviewed = false;
        updateStep();
    });
    document.addEventListener('input', function (event) { if (!panel.contains(event.target)) { detailsReviewed = false; updateStep(); } });
    document.addEventListener('click', function (event) {
        if (!panel.contains(event.target)) window.setTimeout(updateStep, 0);
    });
    document.addEventListener('submit', function (event) {
        if (['create-task','assign-task'].includes(workflow) && event.target.matches('.follow-up-task-form')) { submittedRecord='task'; persist(); }
        if (workflow === 'edit-event-dates' && event.target.id === 'engagement-edit-form') { submittedRecord='event-dates:' + (new URL(window.location.href).searchParams.get('id') || ''); persist(); }
        if (workflow === 'engagement' && event.target.id === 'new-engagement-form') { submittedRecord = 'new-engagement'; persist(); }
        if ((workflow === 'presentation' || workflow === 'notes') && event.target.id === 'engagement-edit-form' && state().fileSelected) {
            submittedRecord = new URL(window.location.href).searchParams.get('id') || '';
            persist();
        }
    });
    document.querySelectorAll('a[href="logout.php"], form[action="logout.php"]').forEach(function (node) {
        node.addEventListener(node.tagName === 'FORM' ? 'submit' : 'click', function () { try { window.sessionStorage.removeItem(storageName); } catch (_) { /* Optional. */ } });
    });
    function stopRequest() {
        if (pending) coachEvent('cancel', pending.id, {}).catch(function () {});
        requestNumber++;
        window.clearTimeout(pollTimer);
        pending = null;
        persist();
        if (controller) controller.abort();
        controller = null;
        setBusy(false);
    }
    find('stop').addEventListener('click', function () { stopRequest(); find('status').textContent = 'Answer cancellation requested. You can ask another question.'; });
    find('reset').addEventListener('click', function () {
        stopRequest(); guideCard.remove(); activeGuideId = ''; messages = []; workflow = ''; submittedRecord = ''; question.value = '';
        clearHighlight(); renderMessages(); persist(); question.focus();
    });
    question.addEventListener('keydown', function (event) {
        if (!shouldSubmitQuestion(event)) return;
        event.preventDefault();
        if (!controller) find('form').requestSubmit(find('send'));
    });
    function receiveReply(data, number) {
        if (number !== requestNumber || !pending) return;
        if (data.error) throw new Error(data.error);
        const reply = safeMessages([{ role: 'assistant', content: replyText(data), sources: data.sources, mode: data.mode, workflow: data.workflow, workflowOptions: data.workflow_options }])[0];
        if (!reply) throw new Error('The coach could not prepare a complete answer.');
        reply.id = pending.id; reply.loggedRequest = data.history_saved !== false;
        if (data.start_workflow === true && reply.workflow) {
            archiveGuide();
            activeGuideId = reply.id;
            workflow = reply.workflow;
            submittedRecord = ''; detailsReviewed = false;
            reply.guideStep = nextStep(page, workflow, state(), role, definitions);
        }
        pending = null;
        controller = null;
        window.clearTimeout(pollTimer);
        addMessage(reply);
        if (data.start_workflow === true && reply.workflow) { updateStep(); scrollToLatestExchange(); }
        setBusy(false);
        if (data.history_saved === false) find('status').textContent = 'Guidance is available, but this request could not be saved to the review history.';
        if (data.reason === 'busy') find('status').textContent = 'The answer queue is full. Try again shortly.';
        persist();
    }

    function failPending(message, number) {
        if (number !== requestNumber || !pending) return;
        question.value = pending.request.question;
        pending = null; controller = null;
        window.clearTimeout(pollTimer);
        setBusy(false); find('status').textContent = message; persist();
    }

    function schedulePoll(active, number) {
        if (number !== requestNumber || !pending) return;
        window.clearTimeout(pollTimer);
        if (Date.now() - pending.startedAt > 25000) {
            failPending('This answer was interrupted. Your question is restored below so you can try again.', number); return;
        }
        pollTimer = window.setTimeout(function () { pollPending(active, number); }, 1500);
    }

    async function pollPending(active, number) {
        if (number !== requestNumber || !pending) return;
        // A completed answer remains recoverable even after a long navigation or suspension.
        try {
            const response = await fetch(panel.dataset.endpoint + '?request_id=' + encodeURIComponent(pending.id), {
                credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: AbortSignal.any([active.signal, AbortSignal.timeout(5000)]) });
            if (number !== requestNumber || !pending) return;
            if (response.status === 404) {
                // Navigation may have happened before the first POST reached PHP. Reuse the same
                // ID; the server's unique key prevents a second inference if it already arrived.
                if (!pending.replayed && Date.now() - pending.startedAt > 8000 && Date.now() - pending.startedAt <= 25000) {
                    pending.replayed = true; persist(); sendPending(active, number); return;
                }
                schedulePoll(active, number); return;
            }
            if ([401, 403].includes(response.status)) { failPending('Sign in again to retrieve your answer.', number); return; }
            if (!response.ok) { schedulePoll(active, number); return; }
            const data = await response.json();
            if (data.state === 'complete') receiveReply(data.response, number);
            else if (data.state === 'interrupted') failPending('This answer was interrupted. Your question is restored below so you can try again.', number);
            else { find('status').textContent = data.stage === 'generating' ? 'Preparing your answer…' : 'Your question is queued…'; schedulePoll(active, number); }
        } catch (_) { schedulePoll(active, number); }
    }

    async function sendPending(active, number) {
        if (!pending) return;
        try {
            const response = await fetch(panel.dataset.endpoint, { method: 'POST', credentials: 'same-origin', redirect: 'error', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': panel.dataset.csrfToken }, signal: AbortSignal.any([active.signal, AbortSignal.timeout(5000)]),
                body: JSON.stringify(pending.request) });
            const data = await response.json();
            if (number !== requestNumber || !pending) return;
            if (!response.ok) { failPending(typeof data.error === 'string' ? data.error : 'The coach is unavailable.', number); return; }
            if (data.pending) schedulePoll(active, number);
            else receiveReply(data, number);
        } catch (_) {
            if (number !== requestNumber || !pending) return;
            find('status').textContent = 'Reconnecting to your answer…';
            schedulePoll(active, number);
        }
    }

    find('form').addEventListener('submit', function (event) {
        event.preventDefault();
        const text = question.value.trim();
        if (!text || controller) return;
        // Keep the previous card in place, but stop following its controls as soon
        // as a new question is submitted. Only a new walkthrough reply can start it.
        const previousStep = currentStep;
        const ui = uiContext();
        endWorkflow();
        const history = messages.slice(-6).map(function (message) { return { role: message.role, content: message.content.slice(0, 1600) }; });
        const id = window.crypto.randomUUID();
        pending = { id: id, startedAt: Date.now(), request: questionRequest(id, text, history, page, previousStep, ui,
            page === 'help.php' ? decodeURIComponent(window.location.hash.slice(1)) : '') };
        addMessage({ id: window.crypto.randomUUID(), role: 'user', content: text, sources: [], mode: 'guide' });
        question.value = '';
        controller = new AbortController();
        const number = ++requestNumber;
        setBusy(true); persist(); sendPending(controller, number);
    });
    window.addEventListener('pagehide', function () {
        window.clearTimeout(pollTimer); persist(); requestNumber++;
        if (controller) controller.abort(); controller = null;
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        let restored = {};
        try { restored = JSON.parse(window.sessionStorage.getItem(storageName) || '{}'); } catch (_) { /* Optional storage. */ }
        if (restored.key !== panel.dataset.storageKey) { window.location.reload(); return; }
        requestNumber++;
        messages = safeMessages(restored.messages); pending = safePending(restored.pending);
        workflow = Object.hasOwn(workflowLabels, restored.workflow || '') ? restored.workflow : '';
        activeGuideId = typeof restored.activeGuideId === 'string' ? restored.activeGuideId : '';
        submittedRecord = typeof restored.submittedRecord === 'string' ? restored.submittedRecord : '';
        renderMessages(); updateStep(); setBusy(!!pending);
        if (pending) { controller = new AbortController(); pollPending(controller, requestNumber); }
    });
    renderMessages();
    setOpen(page !== 'help.php' && saved.open === true && !narrow.matches, false);
    if (pending) {
        controller = new AbortController();
        const number = ++requestNumber;
        setBusy(true);
        find('status').textContent = 'Continuing your question…';
        pollPending(controller, number);
    }
})();
