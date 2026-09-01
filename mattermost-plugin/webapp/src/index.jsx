import {shortMOEDSidebarChannelDisplayName} from './sidebar_channel_label.mjs';

const React = window.React;
const {useEffect, useLayoutEffect, useRef, useState} = React;

const PLUGIN_ID = 'org.moed.mattermost';
const PLUGIN_API = `/plugins/${PLUGIN_ID}/api/v1`;

const STYLES = `
.moed-card{width:min(100%,980px);margin:8px 0 4px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.18);border-radius:12px;background:var(--center-channel-bg);color:var(--center-channel-color);box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden}.moed-card *{box-sizing:border-box}.moed-card__header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:20px 22px 16px}.moed-card__eyebrow{margin:0 0 4px;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.moed-card__title{margin:0;color:var(--center-channel-color);font-size:18px;font-weight:700;line-height:1.25}.moed-card__intro{margin:6px 0 0;color:rgba(var(--center-channel-color-rgb,61,60,64),.72);font-size:13px}.moed-open-link{flex:0 0 auto;color:var(--link-color)!important;font-size:13px;font-weight:650;text-decoration:none}.moed-open-link:hover{text-decoration:underline}.moed-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:0 22px 18px}.moed-summary__item{display:grid;grid-template-columns:38px minmax(0,1fr);align-items:center;gap:10px;min-height:72px;padding:12px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:10px;background:rgba(var(--center-channel-color-rgb,61,60,64),.025)}.moed-summary__icon{display:grid;width:38px;height:38px;place-items:center;border-radius:9px;background:rgba(var(--button-bg-rgb,28,88,217),.11);color:var(--button-bg);font-size:20px}.moed-summary__item--danger .moed-summary__icon{background:rgba(210,75,78,.12);color:#d24b4e}.moed-summary__label{display:block;color:rgba(var(--center-channel-color-rgb,61,60,64),.68);font-size:11px;line-height:1.2}.moed-summary__value{display:block;margin-top:2px;color:var(--center-channel-color);font-size:23px;font-variant-numeric:tabular-nums;font-weight:700;line-height:1}.moed-task-list{margin:0 22px 18px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:10px;overflow:hidden}.moed-task{display:grid;grid-template-columns:minmax(220px,1fr) auto auto;align-items:center;gap:14px;padding:13px 14px}.moed-task+.moed-task{border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.12)}.moed-task__main{display:flex;min-width:0;align-items:center;gap:11px}.moed-task__marker{display:grid;width:36px;height:36px;flex:0 0 auto;place-items:center;border-radius:50%;background:rgba(var(--button-bg-rgb,28,88,217),.1);color:var(--button-bg);font-size:17px}.moed-task__title{display:block;overflow:hidden;color:var(--center-channel-color);font-size:13px;font-weight:650;text-overflow:ellipsis;white-space:nowrap}.moed-task__subject{display:block;margin-top:2px;overflow:hidden;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:12px;text-overflow:ellipsis;white-space:nowrap}.moed-chip{display:inline-flex;min-height:24px;align-items:center;padding:3px 9px;border-radius:999px;background:rgba(var(--button-bg-rgb,28,88,217),.09);color:var(--button-bg);font-size:11px;font-weight:650;white-space:nowrap}.moed-chip--danger{background:rgba(210,75,78,.12);color:#c83f43}.moed-chip--waiting{background:rgba(221,163,42,.15);color:#946408}.moed-task__actions{display:flex;gap:7px}.moed-button{min-height:32px;padding:6px 12px;border:1px solid var(--button-bg);border-radius:7px;background:transparent;color:var(--button-bg);font-size:12px;font-weight:650;cursor:pointer}.moed-button--primary{background:var(--button-bg);color:var(--button-color)}.moed-button:hover{filter:brightness(.96)}.moed-button:disabled{cursor:wait;opacity:.55}.moed-empty{margin:0 22px 18px;padding:28px 18px;border:1px dashed rgba(var(--center-channel-color-rgb,61,60,64),.2);border-radius:10px;color:rgba(var(--center-channel-color-rgb,61,60,64),.7);text-align:center}.moed-feedback{margin:0 22px 16px;padding:9px 11px;border-radius:7px;background:rgba(var(--button-bg-rgb,28,88,217),.08);color:var(--center-channel-color);font-size:12px}.moed-feedback--error{background:rgba(210,75,78,.12);color:var(--error-text,#c83f43)}.moed-event__description{display:-webkit-box;margin:0;padding:0 22px 18px;overflow:hidden;color:rgba(var(--center-channel-color-rgb,61,60,64),.76);font-size:13px;line-height:1.5;-webkit-box-orient:vertical;-webkit-line-clamp:3}.moed-event-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 24px;padding:0 22px 18px}.moed-field__label{display:block;margin-bottom:3px;color:rgba(var(--center-channel-color-rgb,61,60,64),.58);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.moed-field__value{display:block;color:var(--center-channel-color);font-size:13px;line-height:1.4}.moed-field--wide{grid-column:1/-1}.moed-routing-marker{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 22px 18px;padding:12px 14px;border:1px solid rgba(var(--button-bg-rgb,28,88,217),.18);border-radius:9px;background:rgba(var(--button-bg-rgb,28,88,217),.05)}.moed-routing-marker__text{display:grid;min-width:0;gap:3px}.moed-routing-marker__control{display:flex;flex:0 0 auto;align-items:center;gap:8px}.moed-routing-marker__control code{padding:5px 8px;border-radius:5px;background:rgba(var(--center-channel-color-rgb,61,60,64),.08);color:var(--center-channel-color);font-size:13px;font-weight:650}.moed-routing-marker__help{color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:11px}.moed-routing-marker__copy{display:inline-flex;min-height:30px;align-items:center;gap:6px;padding:5px 9px;border:1px solid var(--button-bg);border-radius:6px;background:transparent;color:var(--button-bg);font-size:11px;font-weight:650;cursor:pointer}.moed-routing-marker__copy:hover{background:rgba(var(--button-bg-rgb,28,88,217),.08)}.moed-routing-marker__copy svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}.moed-routing-marker__copy--failed{border-color:var(--error-text,#c83f43);color:var(--error-text,#c83f43)}.moed-event-work{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;padding:0 22px 18px}.moed-event-work__item{padding:11px 12px;border-radius:9px;background:rgba(var(--center-channel-color-rgb,61,60,64),.04)}.moed-event-work__item strong{display:block;font-size:18px}.moed-event-work__item span{color:rgba(var(--center-channel-color-rgb,61,60,64),.6);font-size:11px}.moed-presentations{margin:0;padding:0 22px 18px;list-style:none}.moed-presentations li{padding:7px 0;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.1);font-size:12px}.moed-card__footer{display:flex;justify-content:center;padding:12px 22px;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.1)}.moed-modal-backdrop{position:fixed;z-index:9999;inset:0;display:grid;place-items:center;padding:24px;background:rgba(0,0,0,.54)}.moed-modal{width:min(100%,560px);max-height:calc(100vh - 48px);overflow:auto;border-radius:12px;background:var(--center-channel-bg,#fff);color:var(--center-channel-color,#3d3c40);box-shadow:0 18px 60px rgba(0,0,0,.3)}.moed-modal__header{display:flex;align-items:center;justify-content:space-between;padding:19px 22px;border-bottom:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14)}.moed-modal__header h2{margin:0;color:inherit;font-size:18px}.moed-modal__close{width:32px;height:32px;border:0;border-radius:6px;background:transparent;color:inherit;font-size:24px;cursor:pointer}.moed-modal__body{display:grid;gap:15px;padding:20px 22px}.moed-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.moed-label{display:grid;gap:6px;color:inherit;font-size:12px;font-weight:650}.moed-input,.moed-textarea,.moed-select{width:100%;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.28);border-radius:7px;background:var(--center-channel-bg,#fff);color:var(--center-channel-color,#3d3c40);font:inherit;font-size:13px}.moed-input,.moed-select{height:38px;padding:7px 10px}.moed-textarea{min-height:122px;padding:9px 10px;line-height:1.45;resize:vertical}.moed-modal__hint{margin:0;color:rgba(var(--center-channel-color-rgb,61,60,64),.64);font-size:12px}.moed-modal__error{margin:0;padding:9px 11px;border-radius:7px;background:rgba(210,75,78,.12);color:var(--error-text,#c83f43);font-size:12px}.moed-modal__footer{display:flex;justify-content:flex-end;gap:9px;padding:15px 22px;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14)}@media(max-width:760px){.moed-summary{grid-template-columns:1fr 1fr}.moed-task{grid-template-columns:minmax(0,1fr);align-items:start}.moed-task__actions{flex-wrap:wrap}.moed-event-grid{grid-template-columns:1fr}.moed-field--wide{grid-column:auto}.moed-routing-marker{align-items:flex-start;flex-direction:column}.moed-routing-marker__control{flex-wrap:wrap}}@media(max-width:480px){.moed-card__header{flex-direction:column}.moed-summary{grid-template-columns:1fr}.moed-event-work{grid-template-columns:1fr}.moed-form-row{grid-template-columns:1fr}}
`;

const EMAIL_STYLES = `
.moed-channel-header-button{display:inline-flex;width:30px;height:30px;align-items:center;justify-content:center;margin-left:2px;padding:0;border:0;border-radius:4px;background:transparent;color:rgba(var(--center-channel-color-rgb,61,60,64),.55);cursor:pointer}.moed-channel-header-button:hover,.moed-channel-header-button:focus-visible{background:rgba(var(--center-channel-color-rgb,61,60,64),.08);color:var(--center-channel-color)}
.moed-channel-header-icon{position:relative;display:inline-flex;width:20px;height:20px;align-items:center;justify-content:center;color:inherit}
.moed-channel-header-icon svg{width:19px;height:19px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}
.moed-channel-header-icon--linked{color:#0f8f87}
.moed-channel-header-icon__dot{position:absolute;right:-1px;bottom:-1px;width:7px;height:7px;border:1.5px solid var(--center-channel-bg,#fff);border-radius:50%;background:#21a179}
.SidebarChannelLinkLabel_wrapper.moed-sidebar-label-active .SidebarChannelLinkLabel{display:none!important}
.moed-sidebar-channel-label{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.moed-channel-link{display:grid;gap:14px;padding:15px;border:1px solid rgba(var(--button-bg-rgb,28,88,217),.18);border-radius:10px;background:rgba(var(--button-bg-rgb,28,88,217),.05)}
.moed-channel-link__title{margin:0;font-size:16px}.moed-channel-link__meta{margin:3px 0 0;color:rgba(var(--center-channel-color-rgb,61,60,64),.65);font-size:12px}.moed-channel-link__actions{display:flex;flex-wrap:wrap;gap:9px}
.moed-loading{padding:32px;text-align:center;color:rgba(var(--center-channel-color-rgb,61,60,64),.65)}
.moed-email-modal{width:min(100%,760px)}
.moed-email-engagement{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:12px 14px;border-radius:9px;background:rgba(var(--button-bg-rgb,28,88,217),.06)}
.moed-email-engagement strong,.moed-email-engagement span{display:block}.moed-email-engagement span{margin-top:3px;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:12px}
.moed-recipient-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:210px;overflow:auto}
.moed-recipient{display:flex;gap:9px;padding:10px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.16);border-radius:8px;cursor:pointer}.moed-recipient:has(input:checked){border-color:var(--button-bg);background:rgba(var(--button-bg-rgb,28,88,217),.06)}.moed-recipient input{margin-top:3px}.moed-recipient strong,.moed-recipient small{display:block}.moed-recipient small{overflow:hidden;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);text-overflow:ellipsis;white-space:nowrap}
.moed-email-options{display:grid;gap:8px;padding:11px 13px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:8px}.moed-email-option{display:flex;align-items:flex-start;gap:9px;font-size:12px}.moed-email-option input{margin-top:2px}
.moed-email-review{display:grid;gap:14px}.moed-email-review__section{padding:12px 14px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:8px}.moed-email-review__section h3{margin:0 0 7px;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.moed-email-review__section p{margin:0;white-space:pre-wrap}.moed-email-review__section pre{max-height:240px;margin:0;overflow:auto;white-space:pre-wrap;font:inherit;font-size:12px}
.moed-delivery-summary{display:grid;gap:10px}.moed-delivery-summary__counts{display:flex;flex-wrap:wrap;gap:8px}.moed-delivery-summary__counts span{padding:5px 9px;border-radius:999px;background:rgba(var(--center-channel-color-rgb,61,60,64),.07);font-size:11px}.moed-delivery-list{display:grid;gap:6px}.moed-delivery{display:flex;justify-content:space-between;gap:12px;padding:8px 10px;border-radius:7px;background:rgba(var(--center-channel-color-rgb,61,60,64),.04);font-size:12px}.moed-delivery--failed{color:var(--error-text,#c83f43)}
@media(max-width:620px){.moed-recipient-list{grid-template-columns:1fr}.moed-email-engagement{flex-direction:column}}
`;

function payloadFor(post) {
    const raw = post && post.props ? post.props.moed : null;
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw);
        } catch (_) {
            return {};
        }
    }
    return raw || {};
}

function FormattedPostMessage({message}) {
    if (!message) {
        return null;
    }
    if (window.PostUtils && window.PostUtils.formatText && window.PostUtils.messageHtmlToComponent) {
        return window.PostUtils.messageHtmlToComponent(window.PostUtils.formatText(message));
    }
    return message;
}

function formatDate(value) {
    if (!value) {
        return 'Not scheduled';
    }
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric'});
}

function dateRange(start, end) {
    return !end || start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`;
}

function humanize(value) {
    return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function idempotencyKey(prefix) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return `${prefix}:${window.crypto.randomUUID()}`;
    }
    return `${prefix}:${Date.now()}:${Math.random().toString(36).slice(2)}`;
}

function stableIdempotencyKey(prefix, payload, stateRef) {
    const fingerprint = JSON.stringify(payload);
    if (stateRef.current.fingerprint !== fingerprint || !stateRef.current.key) {
        stateRef.current = {fingerprint, key: idempotencyKey(prefix)};
    }
    return stateRef.current.key;
}

function mattermostCSRFToken() {
    if (typeof document === 'undefined' || typeof document.cookie !== 'string') {
        return '';
    }
    const cookie = document.cookie.split(';').map((value) => value.trim()).find((value) => value.startsWith('MMCSRF='));
    return cookie ? cookie.slice('MMCSRF='.length) : '';
}

async function pluginRequest(path, body) {
    const headers = {'Content-Type': 'application/json'};
    const csrfToken = mattermostCSRFToken();
    if (csrfToken) {
        headers['X-CSRF-Token'] = csrfToken;
    }
    const response = await fetch(`${PLUGIN_API}${path}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify(body),
    });
    let payload = {};
    try {
        payload = await response.json();
    } catch (_) {
        payload = {};
    }
    if (!response.ok) {
        throw new Error(payload.error || 'MOED could not complete that action.');
    }
    return payload;
}

async function pluginGet(path) {
    const response = await fetch(`${PLUGIN_API}${path}`, {credentials: 'same-origin'});
    let payload = {};
    try {
        payload = await response.json();
    } catch (_) {
        payload = {};
    }
    if (!response.ok) {
        throw new Error(payload.error || 'MOED could not load that information.');
    }
    return payload;
}

async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(value);
        return;
    }
    const fallback = document.createElement('textarea');
    fallback.value = value;
    fallback.readOnly = true;
    fallback.style.position = 'fixed';
    fallback.style.opacity = '0';
    document.body.appendChild(fallback);
    fallback.select();
    const copied = document.execCommand('copy');
    fallback.remove();
    if (!copied) {
        throw new Error('Copy failed');
    }
}

function SummaryTile({label, value, icon, danger}) {
    return <div className={`moed-summary__item${danger ? ' moed-summary__item--danger' : ''}`}>
        <span className='moed-summary__icon' aria-hidden='true'>{icon}</span>
        <span><span className='moed-summary__label'>{label}</span><strong className='moed-summary__value'>{value || 0}</strong></span>
    </div>;
}

function dueLabel(task, businessDate) {
    if (task.status === 'waiting') {
        return {text: 'Waiting', className: 'moed-chip--waiting'};
    }
    if (!task.due_date) {
        return {text: 'No due date', className: ''};
    }
    if (task.due_date < businessDate) {
        const days = Math.max(1, Math.round((new Date(`${businessDate}T00:00:00`) - new Date(`${task.due_date}T00:00:00`)) / 86400000));
        return {text: `${days} day${days === 1 ? '' : 's'} overdue`, className: 'moed-chip--danger'};
    }
    if (task.due_date === businessDate) {
        return {text: 'Due today', className: ''};
    }
    return {text: formatDate(task.due_date), className: ''};
}

function TaskRow({task, businessDate, channelId, onUpdate}) {
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const due = dueLabel(task, businessDate);
    const allowed = Array.isArray(task.allowed_actions) ? task.allowed_actions : [];
    const actions = [
        ['assign_to_me', 'Assign to me', false],
        ['start', 'Start', false],
        ['complete', 'Complete', true],
        ['reopen', 'Reopen', false],
    ].filter(([action]) => allowed.includes(action));

    const run = async (action) => {
        setBusy(action);
        setError('');
        try {
            const response = await pluginRequest('/web/task-action', {
                channel_id: channelId,
                task_id: task.id,
                task_action: action,
                expected_version: task.updated_at,
                idempotency_key: idempotencyKey(`task-${task.id}-${action}`),
            });
            onUpdate(response.task, response.message);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy('');
        }
    };

    return <div className='moed-task'>
        <div className='moed-task__main'>
            <span className='moed-task__marker' aria-hidden='true'>✓</span>
            <span><a className='moed-task__title' href={task.url} target='_blank' rel='noreferrer'>{task.title}</a><span className='moed-task__subject'>{task.subject}</span>{error && <span className='moed-task__subject' role='alert'>{error}</span>}</span>
        </div>
        <span className={`moed-chip ${due.className}`}>{due.text}</span>
        <span className='moed-task__actions'>{actions.map(([action, label, primary]) => <button key={action} className={`moed-button${primary ? ' moed-button--primary' : ''}`} disabled={Boolean(busy)} onClick={() => run(action)}>{busy === action ? 'Working…' : label}</button>)}</span>
    </div>;
}

function TaskDashboard({post, mode}) {
    const data = payloadFor(post);
    const [tasks, setTasks] = useState(Array.isArray(data.tasks) ? data.tasks : []);
    const [feedback, setFeedback] = useState('');
    const summary = data.task_summary || {};
    const businessDate = data.business_date || new Date().toISOString().slice(0, 10);
    const openURL = mode === 'today' ? data.dashboard_url : data.work_queue_url;
    const activeTasks = tasks.filter((task) => !['completed', 'canceled'].includes(task.status));
    const updateTask = (next, message) => {
        setTasks((current) => current.map((task) => task.id === next.id ? next : task));
        setFeedback(message || 'Task updated.');
    };
    return <div className='moed-card'>
        <header className='moed-card__header'>
            <div><p className='moed-card__eyebrow'>MOED workflow</p><h3 className='moed-card__title'>{mode === 'today' ? 'Here’s your MOED day' : 'My MOED tasks'}</h3><p className='moed-card__intro'>{mode === 'today' ? formatDate(businessDate) : 'Your active assigned follow-up work'}</p></div>
            {openURL && <a className='moed-open-link' href={openURL} target='_blank' rel='noreferrer'>Open in MOED ↗</a>}
        </header>
        <section className='moed-summary' aria-label='Task summary'>
            <SummaryTile label='Overdue' value={summary.overdue} icon='◷' danger={true}/>
            <SummaryTile label='Due today' value={summary.due_today} icon='□'/>
            <SummaryTile label='Next 7 days' value={summary.next_seven_days} icon='7'/>
            <SummaryTile label='Waiting' value={summary.waiting} icon='⌛'/>
        </section>
        {feedback && <p className='moed-feedback' role='status'>{feedback}</p>}
        {activeTasks.length ? <section className='moed-task-list'>{activeTasks.map((task) => <TaskRow key={task.id} task={task} businessDate={businessDate} channelId={post.channel_id} onUpdate={updateTask}/>)}</section> : <p className='moed-empty'>You have no active assigned tasks.</p>}
        {openURL && <footer className='moed-card__footer'><a className='moed-open-link' href={openURL} target='_blank' rel='noreferrer'>Open in MOED</a></footer>}
    </div>;
}

function TodayPost(props) {
    return <TaskDashboard {...props} mode='today'/>;
}

function TasksPost(props) {
    return <TaskDashboard {...props} mode='tasks'/>;
}

function RoutingMarker({value}) {
    const [copyState, setCopyState] = useState('');
    useEffect(() => {
        if (!copyState) {
            return undefined;
        }
        const timer = window.setTimeout(() => setCopyState(''), 2200);
        return () => window.clearTimeout(timer);
    }, [copyState]);
    const copy = async () => {
        try {
            await copyText(value);
            setCopyState('copied');
        } catch (_) {
            setCopyState('failed');
        }
    };
    const label = copyState === 'copied' ? 'Copied' : copyState === 'failed' ? 'Try again' : 'Copy';
    return <section className='moed-routing-marker' aria-label='Email routing marker'>
        <span className='moed-routing-marker__text'><span className='moed-field__label'>Email routing marker</span><span className='moed-routing-marker__help'>Keep this marker in an email subject or message to route it to this engagement.</span></span>
        <span className='moed-routing-marker__control'><code>{value}</code><button className={`moed-routing-marker__copy${copyState === 'failed' ? ' moed-routing-marker__copy--failed' : ''}`} type='button' onClick={copy} aria-label={`${label} email routing marker`} aria-live='polite' title='Copy email routing marker'><svg aria-hidden='true' viewBox='0 0 24 24'>{copyState === 'copied' ? <path d='m5 12 4 4L19 6'/> : <><rect x='8' y='8' width='11' height='11' rx='2'/><path d='M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2'/></>}</svg>{label}</button></span>
    </section>;
}

function EventPost({post}) {
    const event = payloadFor(post);
    const summary = event.task_summary || {};
    const address = [event.event_address_line_1, event.event_address_line_2, event.event_city, event.event_state, event.event_zipcode, event.event_country].filter(Boolean).join(', ');
    const presentations = Array.isArray(event.presentations) ? event.presentations.slice(0, 3) : [];
    useEffect(() => {
        if (post && post.channel_id) {
            window.dispatchEvent(new CustomEvent('moed-refresh-channel-link', {detail: {channelId: post.channel_id}}));
        }
    }, [post && post.channel_id]);
    return <div>
        {post.message && <div className='moed-card__intro'><FormattedPostMessage message={post.message}/></div>}
        <article className='moed-card'>
            <header className='moed-card__header'><div><p className='moed-card__eyebrow'>MOED engagement #{event.id}</p><h3 className='moed-card__title'>{event.title}</h3><p className='moed-card__intro'>{event.organization_name}</p></div>{event.url && <a className='moed-open-link' href={event.url} target='_blank' rel='noreferrer'>Open in MOED ↗</a>}</header>
            {event.event_description && <p className='moed-event__description'>{event.event_description}</p>}
            <section className='moed-event-grid'>
                <div className='moed-field'><span className='moed-field__label'>Dates</span><span className='moed-field__value'>{dateRange(event.event_start_date, event.event_end_date)}</span></div>
                <div className='moed-field'><span className='moed-field__label'>Status</span><span className='moed-field__value'>{humanize(event.lifecycle_status)} · {humanize(event.confirmation_status)}</span></div>
                {address && <div className='moed-field moed-field--wide'><span className='moed-field__label'>Location</span><span className='moed-field__value'>{address}</span></div>}
            </section>
            {event.email_routing_marker && <RoutingMarker value={event.email_routing_marker}/>}
            <section className='moed-event-work' aria-label='Follow-up work'><div className='moed-event-work__item'><strong>{summary.active || 0}</strong><span>Active tasks</span></div><div className='moed-event-work__item'><strong>{summary.overdue || 0}</strong><span>Overdue</span></div><div className='moed-event-work__item'><strong>{summary.unassigned || 0}</strong><span>Unassigned</span></div></section>
            {presentations.length > 0 && <ul className='moed-presentations'>{presentations.map((presentation, index) => <li key={`${presentation.topic_title}-${index}`}><strong>{presentation.topic_title}</strong>{presentation.presentation_date ? ` · ${formatDate(presentation.presentation_date)}` : ''}{presentation.speaker_name ? ` · ${presentation.speaker_name}` : ''}</li>)}</ul>}
            {event.url && <footer className='moed-card__footer'><a className='moed-open-link' href={event.url} target='_blank' rel='noreferrer'>Open full engagement in MOED</a></footer>}
        </article>
    </div>;
}

function suggestedTitle(post) {
    const firstLine = String(post.message || '').split('\n')[0].trim();
    if (!firstLine) {
        return 'Follow up on Mattermost post';
    }
    return firstLine.length > 100 ? `${firstLine.slice(0, 97)}…` : firstLine;
}

function currentChannelId(store) {
    const state = store.getState();
    return state && state.entities && state.entities.channels ? state.entities.channels.currentChannelId || '' : '';
}

function useChannelBinding(store, channel) {
    const [channelId, setChannelId] = useState(() => channel && channel.id ? channel.id : currentChannelId(store));
    const [binding, setBinding] = useState(null);
    useEffect(() => store.subscribe(() => {
        const next = channel && channel.id ? channel.id : currentChannelId(store);
        setChannelId((current) => current === next ? current : next);
    }), [store, channel && channel.id]);
    useEffect(() => {
        let active = true;
        const refresh = async () => {
            if (!channelId) {
                setBinding(null);
                return;
            }
            try {
                const response = await pluginGet(`/channel-binding?channel_id=${encodeURIComponent(channelId)}`);
                if (active) {
                    setBinding(response);
                }
            } catch (_) {
                if (active) {
                    setBinding(null);
                }
            }
        };
        refresh();
        const refreshRequested = (event) => {
            if (!event.detail || !event.detail.channelId || event.detail.channelId === channelId) {
                refresh();
            }
        };
        const interval = window.setInterval(refresh, 10000);
        window.addEventListener('focus', refresh);
        window.addEventListener('moed-refresh-channel-link', refreshRequested);
        return () => {
            active = false;
            window.clearInterval(interval);
            window.removeEventListener('focus', refresh);
            window.removeEventListener('moed-refresh-channel-link', refreshRequested);
        };
    }, [channelId]);
    return {channelId, binding};
}

function ChannelLinkGlyph({linked}) {
    return <span className={`moed-channel-header-icon${linked ? ' moed-channel-header-icon--linked' : ''}`} aria-label={linked ? 'Channel linked to a MOED engagement' : 'Channel not linked to MOED'}><svg aria-hidden='true' viewBox='0 0 24 24'><path d='M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1'/><path d='M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1'/></svg>{linked && <span className='moed-channel-header-icon__dot'/>}</span>;
}

function openChannelLink(store, channelId) {
    window.dispatchEvent(new CustomEvent('moed-open-post-action', {detail: {action: 'channel_link', channelId: channelId || currentChannelId(store)}}));
}

function ChannelHeaderControl({store, channel}) {
    const {channelId, binding} = useChannelBinding(store, channel);
    const linked = Boolean(binding && binding.linked);
    const engagement = linked && binding.engagement ? binding.engagement : null;
    const title = engagement ? `Linked to MOED engagement #${engagement.id}: ${engagement.title}` : 'MOED channel link';
    return <button className='moed-channel-header-button' type='button' title={title} aria-label={title} onClick={() => openChannelLink(store, channelId)}><ChannelLinkGlyph linked={linked}/></button>;
}

function LegacyChannelHeaderIcon({store}) {
    const {binding} = useChannelBinding(store, null);
    return <ChannelLinkGlyph linked={Boolean(binding && binding.linked)}/>;
}

function SidebarChannelLabel({channel}) {
    const labelRef = useRef(null);
    const displayName = channel && typeof channel.display_name === 'string' ? channel.display_name : '';
    const sidebarDisplayName = shortMOEDSidebarChannelDisplayName(displayName);
    const isShortened = sidebarDisplayName !== displayName;
    useLayoutEffect(() => {
        if (!isShortened || !labelRef.current) {
            return undefined;
        }
        const wrapper = labelRef.current.closest('.SidebarChannelLinkLabel_wrapper');
        if (!wrapper) {
            return undefined;
        }
        wrapper.classList.add('moed-sidebar-label-active');
        return () => wrapper.classList.remove('moed-sidebar-label-active');
    }, [channel && channel.id, displayName, isShortened, sidebarDisplayName]);
    if (!isShortened) {
        return null;
    }
    return <span ref={labelRef} className='moed-sidebar-channel-label'>{sidebarDisplayName}</span>;
}

function ChannelModal({request, onClose, onCompose}) {
    const [binding, setBinding] = useState(null);
    const [error, setError] = useState('');
    useEffect(() => {
        let active = true;
        pluginGet(`/channel-binding?channel_id=${encodeURIComponent(request.channelId)}`).then((response) => {
            if (!active) return;
            setBinding(response);
            window.dispatchEvent(new CustomEvent('moed-refresh-channel-link', {detail: {channelId: request.channelId}}));
        }).catch((requestError) => active && setError(requestError.message));
        return () => { active = false; };
    }, [request.channelId]);
    return <div className='moed-modal-backdrop' role='presentation' onMouseDown={(event) => event.target === event.currentTarget && onClose()}><section className='moed-modal' role='dialog' aria-modal='true' aria-labelledby='moed-channel-modal-title'>
        <header className='moed-modal__header'><h2 id='moed-channel-modal-title'>MOED channel link</h2><button className='moed-modal__close' type='button' aria-label='Close' onClick={onClose}>×</button></header>
        <div className='moed-modal__body'>
            {error ? <p className='moed-modal__error' role='alert'>{error}</p> : !binding ? <p className='moed-loading'>Checking the channel link…</p> : binding.linked ? <>
                <section className='moed-channel-link'><div><p className='moed-card__eyebrow'>Linked engagement #{binding.engagement.id}</p><h3 className='moed-channel-link__title'>{binding.engagement.title || `MOED engagement #${binding.engagement.id}`}</h3>{binding.engagement.organization_name && <p className='moed-channel-link__meta'>{binding.engagement.organization_name}</p>}</div><div className='moed-channel-link__actions'>{binding.engagement.url && <a className='moed-button' href={binding.engagement.url} target='_blank' rel='noreferrer'>Open in MOED ↗</a>}{binding.can_email && <button className='moed-button moed-button--primary' type='button' onClick={onCompose}>Send MOED email</button>}</div></section>
                {binding.engagement.email_routing_marker && <RoutingMarker value={binding.engagement.email_routing_marker}/>}<p className='moed-modal__hint'>This indicator means MOED actions in this channel use the engagement shown above.</p>
            </> : <><p>This channel is not linked to a MOED engagement.</p><p className='moed-modal__hint'>An editor or administrator can link it with <code>/moed link-event ID</code>.</p></>}
        </div>
        <footer className='moed-modal__footer'><button className='moed-button' type='button' onClick={onClose}>Close</button></footer>
    </section></div>;
}

function EmailModal({request, onClose}) {
    const [composer, setComposer] = useState(null);
    const [templateKey, setTemplateKey] = useState('');
    const [selected, setSelected] = useState([]);
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [includeBrief, setIncludeBrief] = useState(false);
    const [contextMode, setContextMode] = useState(request.post ? 'post' : 'none');
    const [stage, setStage] = useState('compose');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState(null);
    const idempotencyState = useRef({fingerprint: '', key: ''});
    useEffect(() => {
        let active = true;
        pluginRequest('/email-compose', {channel_id: request.channelId, post_id: request.post ? request.post.id : ''}).then((response) => {
            if (!active) return;
            setComposer(response);
            const keys = Object.keys(response.templates || {});
            const key = response.templates && response.templates.booking_confirmation ? 'booking_confirmation' : keys[0] || '';
            const template = response.templates ? response.templates[key] : null;
            setTemplateKey(key);
            if (template) {
                setSubject(template.subject || '');
                setBody(template.body || '');
                setSelected(template.suggested_contact_ids || []);
            }
        }).catch((requestError) => active && setError(requestError.message));
        return () => { active = false; };
    }, [request.channelId, request.post]);
    useEffect(() => {
        if (!result || result.pending_count < 1) return undefined;
        let active = true;
        const poll = async () => {
            try {
                const response = await pluginRequest('/email-status', {channel_id: request.channelId, message_id: result.message_id});
                if (active) setResult(response);
            } catch (_) {
                // The delivery record link remains available if a status refresh is interrupted.
            }
        };
        const interval = window.setInterval(poll, 3000);
        return () => { active = false; window.clearInterval(interval); };
    }, [result && result.message_id, result && result.pending_count, request.channelId]);
    useEffect(() => {
        const closeOnEscape = (event) => event.key === 'Escape' && !busy && onClose();
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [busy, onClose]);
    const chooseTemplate = (key) => {
        const template = composer && composer.templates ? composer.templates[key] : null;
        setTemplateKey(key);
        if (template) {
            setSubject(template.subject || '');
            setBody(template.body || '');
            setSelected(template.suggested_contact_ids || []);
        }
    };
    const toggleRecipient = (id) => setSelected((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]);
    const review = () => {
        setError('');
        if (selected.length < 1) {
            setError('Choose at least one engagement contact.');
            return;
        }
        if (!subject.trim() || !body.trim()) {
            setError('Enter both a subject and message.');
            return;
        }
        const marker = composer && composer.engagement ? composer.engagement.email_routing_marker || '' : '';
        if (marker && !subject.toLocaleLowerCase().includes(marker.toLocaleLowerCase())) {
            setSubject(`${subject.trim()} ${marker}`);
        }
        setStage('review');
    };
    const send = async () => {
        setBusy(true);
        setError('');
        try {
            const payload = {
                channel_id: request.channelId,
                post_id: request.post ? request.post.id : '',
                contact_ids: selected,
                template_key: templateKey,
                subject,
                body,
                include_event_brief: includeBrief,
                include_post: contextMode === 'post',
                include_thread: contextMode === 'thread',
            };
            payload.idempotency_key = stableIdempotencyKey(
                'send-email',
                payload,
                idempotencyState,
            );
            const response = await pluginRequest('/email-send', payload);
            setResult(response);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy(false);
        }
    };
    const contacts = composer && Array.isArray(composer.contacts) ? composer.contacts : [];
    const recipients = contacts.filter((contact) => selected.includes(contact.id));
    const contextPreview = composer ? (contextMode === 'thread' ? composer.thread_context : contextMode === 'post' ? composer.post_context : '') : '';
    return <div className='moed-modal-backdrop' role='presentation' onMouseDown={(event) => event.target === event.currentTarget && !busy && onClose()}><section className='moed-modal moed-email-modal' role='dialog' aria-modal='true' aria-labelledby='moed-email-modal-title'>
        <header className='moed-modal__header'><h2 id='moed-email-modal-title'>Send via MOED email</h2><button className='moed-modal__close' type='button' aria-label='Close' disabled={busy} onClick={onClose}>×</button></header>
        <div className='moed-modal__body'>
            {error && <p className='moed-modal__error' role='alert'>{error}</p>}
            {!composer && !error ? <p className='moed-loading'>Loading the engagement email composer…</p> : composer && result ? <div className='moed-delivery-summary'>
                <p className={result.failed_count ? 'moed-modal__error' : 'moed-feedback'} role='status'><strong>{result.message}</strong></p>
                <div className='moed-delivery-summary__counts'><span>{result.sent_count} sent</span><span>{result.pending_count} pending</span><span>{result.failed_count} failed</span></div>
                <div className='moed-delivery-list'>{(result.deliveries || []).map((delivery, index) => <div className={`moed-delivery${delivery.status === 'failed' ? ' moed-delivery--failed' : ''}`} key={`${delivery.name}-${index}`}><span>{delivery.name}</span><strong>{humanize(delivery.status)}</strong></div>)}</div>
                {result.url && <a className='moed-open-link' href={result.url} target='_blank' rel='noreferrer'>Open delivery record in MOED ↗</a>}
            </div> : composer && stage === 'review' ? <div className='moed-email-review'>
                <div className='moed-email-engagement'><div><strong>{composer.engagement.title}</strong><span>{composer.engagement.organization_name} · {composer.engagement.email_routing_marker}</span></div></div>
                <section className='moed-email-review__section'><h3>Recipients</h3><p>{recipients.map((contact) => `${contact.name} <${contact.email}>`).join('\n')}</p></section>
                <section className='moed-email-review__section'><h3>Subject</h3><p>{subject}</p></section>
                <section className='moed-email-review__section'><h3>Message</h3><pre>{body}</pre></section>
                {contextPreview && <section className='moed-email-review__section'><h3>Included Mattermost context</h3><pre>{contextPreview}</pre></section>}
                {includeBrief && <section className='moed-email-review__section'><h3>Share-safe event brief</h3><pre>{composer.safe_event_brief}</pre></section>}
                <p className='moed-modal__hint'>MOED will send one private delivery per unique address, preserve the routing marker, and add the sent message to Chron.</p>
            </div> : composer ? <>
                <div className='moed-email-engagement'><div><strong>{composer.engagement.title}</strong><span>{composer.engagement.organization_name} · {composer.engagement.email_routing_marker}</span></div><a className='moed-open-link' href={composer.compose_url} target='_blank' rel='noreferrer'>Full composer ↗</a></div>
                {!composer.delivery_available && <p className='moed-modal__error'>Email delivery is not available. Ask a MOED administrator to check the mail setup.</p>}
                <label className='moed-label'>Template<select className='moed-select' value={templateKey} onChange={(event) => chooseTemplate(event.target.value)}>{Object.values(composer.templates || {}).map((template) => <option key={template.key} value={template.key}>{template.label}</option>)}</select></label>
                <fieldset className='moed-label'><legend>Recipients</legend><div className='moed-recipient-list'>{contacts.map((contact) => <label className='moed-recipient' key={contact.id}><input type='checkbox' checked={selected.includes(contact.id)} onChange={() => toggleRecipient(contact.id)}/><span><strong>{contact.name}</strong><small>{contact.email}</small><small>{(contact.role_labels || []).join(' · ')}</small></span></label>)}</div>{contacts.length === 0 && <p className='moed-modal__hint'>This engagement has no assigned contacts with a valid email address.</p>}</fieldset>
                <label className='moed-label'>Subject<input className='moed-input' required maxLength='255' value={subject} onChange={(event) => setSubject(event.target.value)}/></label>
                <label className='moed-label'>Message<textarea className='moed-textarea' required maxLength='100000' value={body} onChange={(event) => setBody(event.target.value)}/></label>
                <div className='moed-email-options'><label className='moed-email-option'><input type='checkbox' checked={includeBrief} onChange={(event) => setIncludeBrief(event.target.checked)}/><span><strong>Append the share-safe event brief</strong><br/>Includes public logistics and presentations, not internal Chron or financial details.</span></label>{composer.post_context && <><label className='moed-label'>Mattermost context<select className='moed-select' value={contextMode} onChange={(event) => setContextMode(event.target.value)}><option value='none'>Do not include</option><option value='post'>Include selected post</option><option value='thread'>Include thread excerpt</option></select></label></>}</div>
            </> : null}
        </div>
        <footer className='moed-modal__footer'>{result ? <><button className='moed-button' type='button' onClick={onClose}>Done</button></> : stage === 'review' ? <><button className='moed-button' type='button' disabled={busy} onClick={() => setStage('compose')}>Back</button><button className='moed-button moed-button--primary' type='button' disabled={busy || !composer.delivery_available} onClick={send}>{busy ? 'Sending…' : 'Send and add to Chron'}</button></> : <><button className='moed-button' type='button' onClick={onClose}>Cancel</button><button className='moed-button moed-button--primary' type='button' disabled={!composer || !composer.delivery_available || contacts.length === 0} onClick={review}>Review email</button></>}</footer>
    </section></div>;
}

function ActionModal({request, onClose}) {
    const createTask = request.action === 'create_task';
    const [title, setTitle] = useState(suggestedTitle(request.post));
    const [details, setDetails] = useState(String(request.post.message || '').slice(0, 18000));
    const [entryText, setEntryText] = useState(String(request.post.message || '').slice(0, 24000));
    const [dueDate, setDueDate] = useState('');
    const [priority, setPriority] = useState('normal');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState(null);
    const idempotencyState = useRef({fingerprint: '', key: ''});
    useEffect(() => {
        const closeOnEscape = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [onClose]);
    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            const payload = {
                post_id: request.post.id,
                action: request.action,
                title,
                details,
                due_date: dueDate,
                priority,
                entry_text: entryText,
            };
            payload.idempotency_key = stableIdempotencyKey(
                request.action,
                payload,
                idempotencyState,
            );
            const response = await pluginRequest('/post-action', payload);
            setSuccess(response);
        } catch (requestError) {
            setError(requestError.message);
        } finally {
            setBusy(false);
        }
    };
    return <div className='moed-modal-backdrop' role='presentation' onMouseDown={(event) => event.target === event.currentTarget && onClose()}><form className='moed-modal' role='dialog' aria-modal='true' aria-labelledby='moed-modal-title' onSubmit={submit}>
        <header className='moed-modal__header'><h2 id='moed-modal-title'>{createTask ? 'Add MOED task' : 'Add to MOED Chron'}</h2><button className='moed-modal__close' type='button' aria-label='Close' onClick={onClose}>×</button></header>
        <div className='moed-modal__body'>
            {success ? <p className='moed-feedback' role='status'><strong>{success.message || (createTask ? 'MOED task created.' : 'Post saved to the MOED Chron.')}</strong>{(success.url || (success.task && success.task.url)) && <> <a className='moed-open-link' href={success.url || success.task.url} target='_blank' rel='noreferrer'>Open in MOED ↗</a></>}</p> : <>
            <p className='moed-modal__hint'>This will save the selected post to the engagement linked to this channel. MOED checks your linked account and role before writing.</p>
            {createTask ? <>
                <label className='moed-label'>Task title<input className='moed-input' required maxLength='255' value={title} onChange={(event) => setTitle(event.target.value)}/></label>
                <label className='moed-label'>Notes<textarea className='moed-textarea' maxLength='18000' value={details} onChange={(event) => setDetails(event.target.value)} placeholder='Optional task instructions'/></label>
                <div className='moed-form-row'><label className='moed-label'>Due date<input className='moed-input' type='date' value={dueDate} onChange={(event) => setDueDate(event.target.value)}/></label><label className='moed-label'>Priority<select className='moed-select' value={priority} onChange={(event) => setPriority(event.target.value)}><option value='low'>Low</option><option value='normal'>Normal</option><option value='high'>High</option><option value='urgent'>Urgent</option></select></label></div>
            </> : <label className='moed-label'>Chron entry<textarea className='moed-textarea' required maxLength='24000' value={entryText} onChange={(event) => setEntryText(event.target.value)}/></label>}
            {error && <p className='moed-modal__error' role='alert'>{error}</p>}
            </>}
        </div>
        <footer className='moed-modal__footer'>{success ? <button className='moed-button moed-button--primary' type='button' onClick={onClose}>Done</button> : <><button className='moed-button' type='button' onClick={onClose}>Cancel</button><button className='moed-button moed-button--primary' disabled={busy} type='submit'>{busy ? 'Saving…' : (createTask ? 'Create task' : 'Save to Chron')}</button></>}</footer>
    </form></div>;
}

function Root() {
    const [request, setRequest] = useState(null);
    useEffect(() => {
        const open = (event) => setRequest(event.detail);
        window.addEventListener('moed-open-post-action', open);
        return () => window.removeEventListener('moed-open-post-action', open);
    }, []);
    if (!request) {
        return null;
    }
    if (request.action === 'channel_link') {
        return <ChannelModal request={request} onClose={() => setRequest(null)} onCompose={() => setRequest({action: 'send_email', channelId: request.channelId})}/>;
    }
    if (request.action === 'send_email') {
        return <EmailModal request={request} onClose={() => setRequest(null)}/>;
    }
    return <ActionModal request={request} onClose={() => setRequest(null)}/>;
}

function resolvePost(store, postOrID) {
    if (postOrID && typeof postOrID === 'object') {
        return postOrID;
    }
    const state = store.getState();
    return state && state.entities && state.entities.posts && state.entities.posts.posts ? state.entities.posts.posts[postOrID] : null;
}

function openPostAction(store, action, postOrID) {
    const post = resolvePost(store, postOrID);
    if (!post) {
        return;
    }
    window.dispatchEvent(new CustomEvent('moed-open-post-action', {detail: {action, post, channelId: post.channel_id}}));
}

class MOEDPlugin {
    initialize(registry, store) {
        const style = document.createElement('style');
        style.id = 'moed-mattermost-webapp-styles';
        style.textContent = STYLES + EMAIL_STYLES;
        document.head.appendChild(style);
        registry.registerRootComponent(Root);
        registry.registerPostTypeComponent('custom_moed_today', TodayPost);
        registry.registerPostTypeComponent('custom_moed_tasks', TasksPost);
        registry.registerPostTypeComponent('custom_moed_event', EventPost);
        registry.registerPostDropdownMenuAction('Add MOED task', (postOrID) => openPostAction(store, 'create_task', postOrID));
        registry.registerPostDropdownMenuAction('Add to MOED Chron', (postOrID) => openPostAction(store, 'save_chron', postOrID));
        registry.registerPostDropdownMenuAction('Send via MOED email', (postOrID) => openPostAction(store, 'send_email', postOrID));
        if (typeof registry.registerChannelHeaderIcon === 'function') {
            const MOEDChannelHeaderControl = (props) => <ChannelHeaderControl {...props} store={store}/>;
            registry.registerChannelHeaderIcon(MOEDChannelHeaderControl);
        } else {
            registry.registerChannelHeaderButtonAction(
                <LegacyChannelHeaderIcon store={store}/>,
                (channel) => openChannelLink(store, channel && channel.id ? channel.id : currentChannelId(store)),
                'MOED engagement',
                'MOED channel link',
            );
        }
        if (typeof registry.registerChannelHeaderMenuAction === 'function') {
            registry.registerChannelHeaderMenuAction('MOED engagement', (channelId) => openChannelLink(store, channelId));
        }
        if (typeof registry.registerSidebarChannelLinkLabelComponent === 'function') {
            registry.registerSidebarChannelLinkLabelComponent(SidebarChannelLabel);
        }
    }

    uninitialize() {
        const style = document.getElementById('moed-mattermost-webapp-styles');
        if (style) {
            style.remove();
        }
    }
}

window.registerPlugin(PLUGIN_ID, new MOEDPlugin());
