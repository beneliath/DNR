const React = window.React;
const {useEffect, useState} = React;

const PLUGIN_ID = 'org.moed.mattermost';
const PLUGIN_API = `/plugins/${PLUGIN_ID}/api/v1`;

const STYLES = `
.moed-card{width:min(100%,980px);margin:8px 0 4px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.18);border-radius:12px;background:var(--center-channel-bg);color:var(--center-channel-color);box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden}.moed-card *{box-sizing:border-box}.moed-card__header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:20px 22px 16px}.moed-card__eyebrow{margin:0 0 4px;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.moed-card__title{margin:0;color:var(--center-channel-color);font-size:18px;font-weight:700;line-height:1.25}.moed-card__intro{margin:6px 0 0;color:rgba(var(--center-channel-color-rgb,61,60,64),.72);font-size:13px}.moed-open-link{flex:0 0 auto;color:var(--link-color)!important;font-size:13px;font-weight:650;text-decoration:none}.moed-open-link:hover{text-decoration:underline}.moed-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:0 22px 18px}.moed-summary__item{display:grid;grid-template-columns:38px minmax(0,1fr);align-items:center;gap:10px;min-height:72px;padding:12px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:10px;background:rgba(var(--center-channel-color-rgb,61,60,64),.025)}.moed-summary__icon{display:grid;width:38px;height:38px;place-items:center;border-radius:9px;background:rgba(var(--button-bg-rgb,28,88,217),.11);color:var(--button-bg);font-size:20px}.moed-summary__item--danger .moed-summary__icon{background:rgba(210,75,78,.12);color:#d24b4e}.moed-summary__label{display:block;color:rgba(var(--center-channel-color-rgb,61,60,64),.68);font-size:11px;line-height:1.2}.moed-summary__value{display:block;margin-top:2px;color:var(--center-channel-color);font-size:23px;font-variant-numeric:tabular-nums;font-weight:700;line-height:1}.moed-task-list{margin:0 22px 18px;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14);border-radius:10px;overflow:hidden}.moed-task{display:grid;grid-template-columns:minmax(220px,1fr) auto auto;align-items:center;gap:14px;padding:13px 14px}.moed-task+.moed-task{border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.12)}.moed-task__main{display:flex;min-width:0;align-items:center;gap:11px}.moed-task__marker{display:grid;width:36px;height:36px;flex:0 0 auto;place-items:center;border-radius:50%;background:rgba(var(--button-bg-rgb,28,88,217),.1);color:var(--button-bg);font-size:17px}.moed-task__title{display:block;overflow:hidden;color:var(--center-channel-color);font-size:13px;font-weight:650;text-overflow:ellipsis;white-space:nowrap}.moed-task__subject{display:block;margin-top:2px;overflow:hidden;color:rgba(var(--center-channel-color-rgb,61,60,64),.62);font-size:12px;text-overflow:ellipsis;white-space:nowrap}.moed-chip{display:inline-flex;min-height:24px;align-items:center;padding:3px 9px;border-radius:999px;background:rgba(var(--button-bg-rgb,28,88,217),.09);color:var(--button-bg);font-size:11px;font-weight:650;white-space:nowrap}.moed-chip--danger{background:rgba(210,75,78,.12);color:#c83f43}.moed-chip--waiting{background:rgba(221,163,42,.15);color:#946408}.moed-task__actions{display:flex;gap:7px}.moed-button{min-height:32px;padding:6px 12px;border:1px solid var(--button-bg);border-radius:7px;background:transparent;color:var(--button-bg);font-size:12px;font-weight:650;cursor:pointer}.moed-button--primary{background:var(--button-bg);color:var(--button-color)}.moed-button:hover{filter:brightness(.96)}.moed-button:disabled{cursor:wait;opacity:.55}.moed-empty{margin:0 22px 18px;padding:28px 18px;border:1px dashed rgba(var(--center-channel-color-rgb,61,60,64),.2);border-radius:10px;color:rgba(var(--center-channel-color-rgb,61,60,64),.7);text-align:center}.moed-feedback{margin:0 22px 16px;padding:9px 11px;border-radius:7px;background:rgba(var(--button-bg-rgb,28,88,217),.08);color:var(--center-channel-color);font-size:12px}.moed-feedback--error{background:rgba(210,75,78,.12);color:var(--error-text,#c83f43)}.moed-event__description{display:-webkit-box;margin:0;padding:0 22px 18px;overflow:hidden;color:rgba(var(--center-channel-color-rgb,61,60,64),.76);font-size:13px;line-height:1.5;-webkit-box-orient:vertical;-webkit-line-clamp:3}.moed-event-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 24px;padding:0 22px 18px}.moed-field__label{display:block;margin-bottom:3px;color:rgba(var(--center-channel-color-rgb,61,60,64),.58);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.moed-field__value{display:block;color:var(--center-channel-color);font-size:13px;line-height:1.4}.moed-field--wide{grid-column:1/-1}.moed-event-work{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;padding:0 22px 18px}.moed-event-work__item{padding:11px 12px;border-radius:9px;background:rgba(var(--center-channel-color-rgb,61,60,64),.04)}.moed-event-work__item strong{display:block;font-size:18px}.moed-event-work__item span{color:rgba(var(--center-channel-color-rgb,61,60,64),.6);font-size:11px}.moed-presentations{margin:0;padding:0 22px 18px;list-style:none}.moed-presentations li{padding:7px 0;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.1);font-size:12px}.moed-card__footer{display:flex;justify-content:center;padding:12px 22px;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.1)}.moed-modal-backdrop{position:fixed;z-index:9999;inset:0;display:grid;place-items:center;padding:24px;background:rgba(0,0,0,.54)}.moed-modal{width:min(100%,560px);max-height:calc(100vh - 48px);overflow:auto;border-radius:12px;background:var(--center-channel-bg,#fff);color:var(--center-channel-color,#3d3c40);box-shadow:0 18px 60px rgba(0,0,0,.3)}.moed-modal__header{display:flex;align-items:center;justify-content:space-between;padding:19px 22px;border-bottom:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14)}.moed-modal__header h2{margin:0;color:inherit;font-size:18px}.moed-modal__close{width:32px;height:32px;border:0;border-radius:6px;background:transparent;color:inherit;font-size:24px;cursor:pointer}.moed-modal__body{display:grid;gap:15px;padding:20px 22px}.moed-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.moed-label{display:grid;gap:6px;color:inherit;font-size:12px;font-weight:650}.moed-input,.moed-textarea,.moed-select{width:100%;border:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.28);border-radius:7px;background:var(--center-channel-bg,#fff);color:var(--center-channel-color,#3d3c40);font:inherit;font-size:13px}.moed-input,.moed-select{height:38px;padding:7px 10px}.moed-textarea{min-height:122px;padding:9px 10px;line-height:1.45;resize:vertical}.moed-modal__hint{margin:0;color:rgba(var(--center-channel-color-rgb,61,60,64),.64);font-size:12px}.moed-modal__error{margin:0;padding:9px 11px;border-radius:7px;background:rgba(210,75,78,.12);color:var(--error-text,#c83f43);font-size:12px}.moed-modal__footer{display:flex;justify-content:flex-end;gap:9px;padding:15px 22px;border-top:1px solid rgba(var(--center-channel-color-rgb,61,60,64),.14)}@media(max-width:760px){.moed-summary{grid-template-columns:1fr 1fr}.moed-task{grid-template-columns:minmax(0,1fr);align-items:start}.moed-task__actions{flex-wrap:wrap}.moed-event-grid{grid-template-columns:1fr}.moed-field--wide{grid-column:auto}}@media(max-width:480px){.moed-card__header{flex-direction:column}.moed-summary{grid-template-columns:1fr}.moed-event-work{grid-template-columns:1fr}.moed-form-row{grid-template-columns:1fr}}
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

function EventPost({post}) {
    const event = payloadFor(post);
    const summary = event.task_summary || {};
    const address = [event.event_address_line_1, event.event_address_line_2, event.event_city, event.event_state, event.event_zipcode, event.event_country].filter(Boolean).join(', ');
    const presentations = Array.isArray(event.presentations) ? event.presentations.slice(0, 3) : [];
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
            const response = await pluginRequest('/post-action', {
                post_id: request.post.id,
                action: request.action,
                title,
                details,
                due_date: dueDate,
                priority,
                entry_text: entryText,
                idempotency_key: idempotencyKey(request.action),
            });
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
    return request ? <ActionModal request={request} onClose={() => setRequest(null)}/> : null;
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
    window.dispatchEvent(new CustomEvent('moed-open-post-action', {detail: {action, post}}));
}

class MOEDPlugin {
    initialize(registry, store) {
        const style = document.createElement('style');
        style.id = 'moed-mattermost-webapp-styles';
        style.textContent = STYLES;
        document.head.appendChild(style);
        registry.registerRootComponent(Root);
        registry.registerPostTypeComponent('custom_moed_today', TodayPost);
        registry.registerPostTypeComponent('custom_moed_tasks', TasksPost);
        registry.registerPostTypeComponent('custom_moed_event', EventPost);
        registry.registerPostDropdownMenuAction('Add MOED task', (postOrID) => openPostAction(store, 'create_task', postOrID));
        registry.registerPostDropdownMenuAction('Add to MOED Chron', (postOrID) => openPostAction(store, 'save_chron', postOrID));
    }

    uninitialize() {
        const style = document.getElementById('moed-mattermost-webapp-styles');
        if (style) {
            style.remove();
        }
    }
}

window.registerPlugin(PLUGIN_ID, new MOEDPlugin());
