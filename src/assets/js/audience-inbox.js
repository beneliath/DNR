(() => {
  'use strict';
  function inboxNotificationPermissionState(scope, permission = scope.Notification?.permission, attempted = false) {
    if (scope.isSecureContext === false) return {state:'insecure',label:'Secure connection required',disabled:true,message:'Open Inbox over HTTPS or localhost to use browser notifications.'};
    if (!scope.Notification || typeof scope.Notification.requestPermission !== 'function') return {state:'unsupported',label:'Browser alerts unavailable',disabled:true,message:'This browser does not support desktop notifications. Open Inbox in a supported browser to enable alerts.'};
    if (permission === 'granted') return {state:'granted',label:'Browser alerts allowed',disabled:true,message:'Browser permission is allowed. Turn on Browser notifications and save your preferences to receive alerts while Inbox is open.'};
    if (permission === 'denied') return {state:'denied',label:'Check permission',disabled:false,message:'Notifications are blocked for this site. Allow notifications in your browser’s site settings, then click Check permission. If this browser has no notification settings, open Inbox in your regular browser.'};
    return {state:'default',label:'Allow browser alerts',disabled:false,message:attempted?'Permission was not granted. If no prompt appeared, open Inbox in your regular browser and try again.':'Browser permission has not been granted yet. Choose Allow browser alerts to request it.'};
  }
  async function requestInboxNotificationPermission(scope) {
    const state = inboxNotificationPermissionState(scope);
    // Browsers cannot prompt again after permission was blocked in site settings.
    if (state.state !== 'default') return state;
    try { return inboxNotificationPermissionState(scope,await scope.Notification.requestPermission(),true); }
    catch { return {...state,message:'This browser could not request notification permission. Check its site settings, or open Inbox in your regular browser and try again.'}; }
  }
  function inboxPaneWidth(requested, layoutWidth, sidebarWidth) {
    const min = 280, max = Math.max(min,Math.min(840,layoutWidth - sidebarWidth - 360));
    return {min,max,value:Math.round(Math.min(max,Math.max(min,requested)))};
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {inboxNotificationPermissionState,requestInboxNotificationPermission,inboxPaneWidth};
  if (typeof document === 'undefined') return;
  const node = document.getElementById('inbox-config');
  if (!node) return;
  const config = JSON.parse(node.textContent);
  // Selection belongs to the current visit. Reloading returns to an unselected list.
  if ((config.thread && !config.draftWorkspace) || (config.draftWorkspace && config.draft)) {
    const landing = new URL(location.href);
    landing.searchParams.delete('thread'); landing.searchParams.delete('draft');
    landing.searchParams.delete('selection'); landing.searchParams.delete('before'); landing.searchParams.delete('message_page');
    if (config.draftWorkspace) landing.searchParams.set('panel','drafts');
    if (performance.getEntriesByType('navigation')[0]?.type === 'reload') {
      location.replace(landing.href); return;
    }
    history.replaceState(history.state,'',landing);
  }
  const templates = JSON.parse(document.getElementById('inbox-templates').textContent);
  document.body.classList.add('inbox-js');
  const divider = document.querySelector('[data-inbox-divider]');
  if (divider) {
    const layout = divider.closest('.inbox-layout'), list = divider.closest('.inbox-list'), sidebar = document.getElementById('inbox-sidebar');
    const widthKey = `moed:inbox:list-width:${document.body.dataset.audienceKey}`;
    let preferred = null, drag = null;
    try { const stored = Number(localStorage.getItem(widthKey)); if (stored >= 280 && stored <= 840) preferred = stored; } catch { /* Resizing still works without browser storage. */ }
    const resize = (requested, persist = false) => {
      if (!window.matchMedia('(min-width:721px)').matches) return;
      const sidebarWidth = getComputedStyle(sidebar).position === 'fixed' ? 0 : sidebar.getBoundingClientRect().width;
      const width = inboxPaneWidth(requested,layout.clientWidth,sidebarWidth);
      layout.style.setProperty('--inbox-list-width',`${width.value}px`);
      divider.setAttribute('aria-valuemin',String(width.min)); divider.setAttribute('aria-valuemax',String(width.max));
      divider.setAttribute('aria-valuenow',String(width.value)); divider.setAttribute('aria-valuetext',`${width.value} pixels`);
      if (persist) { preferred = width.value; try { localStorage.setItem(widthKey,String(preferred)); } catch { /* This page keeps the chosen width. */ } }
    };
    const refreshWidth = () => {
      if (preferred === null) layout.style.removeProperty('--inbox-list-width');
      resize(preferred ?? list.getBoundingClientRect().width);
    };
    const resetWidth = () => { preferred = null; try { localStorage.removeItem(widthKey); } catch { /* Optional preference storage. */ } refreshWidth(); };
    divider.addEventListener('pointerdown',event => {
      if (event.button !== 0) return;
      event.preventDefault(); drag = {x:event.clientX,width:list.getBoundingClientRect().width};
      divider.setPointerCapture(event.pointerId); divider.focus(); document.body.classList.add('inbox-is-resizing');
    });
    divider.addEventListener('pointermove',event => { if (drag) resize(drag.width + event.clientX - drag.x); });
    const finishResize = event => {
      if (!drag) return; drag = null; document.body.classList.remove('inbox-is-resizing');
      resize(list.getBoundingClientRect().width,true);
      if (divider.hasPointerCapture(event.pointerId)) divider.releasePointerCapture(event.pointerId);
    };
    divider.addEventListener('pointerup',finishResize); divider.addEventListener('pointercancel',finishResize);
    divider.addEventListener('dblclick',resetWidth);
    divider.addEventListener('keydown',event => {
      if (!['ArrowLeft','ArrowRight','Home','End','Enter'].includes(event.key)) return;
      event.preventDefault();
      if (event.key === 'Enter') { resetWidth(); return; }
      const requested = event.key === 'Home' ? 280 : event.key === 'End' ? 840 : list.getBoundingClientRect().width + (event.key === 'ArrowRight' ? 1 : -1) * (event.shiftKey ? 40 : 16);
      resize(requested,true);
    });
    window.addEventListener('resize',refreshWidth); refreshWidth();
  }
  // Use the profile timezone, including for same-day and year comparisons.
  const renderTime = time => {
    const date = new Date(time.dateTime);
    if (Number.isNaN(date.getTime())) return;
    const today = new Date();
    const sameDay = window.DnrUserTime.dateKey(date) === window.DnrUserTime.dateKey(today);
    const short = time.dataset.inboxTime === 'short';
    time.textContent = window.DnrUserTime.format(date,short
      ? (sameDay ? {hour:'numeric',minute:'2-digit'} : {month:'short',day:'numeric',...(window.DnrUserTime.dateKey(date).slice(0,4) !== window.DnrUserTime.dateKey(today).slice(0,4) ? {year:'numeric'} : {})})
      : {month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',timeZoneName:'short'});
    time.title = window.DnrUserTime.format(date,{dateStyle:'full',timeStyle:'long'});
  };
  document.querySelectorAll('[data-inbox-time]').forEach(renderTime);
  const trashBulk = document.getElementById('inbox-trash-bulk');
  if (trashBulk) {
    const selectAll = trashBulk.querySelector('[data-inbox-trash-all]');
    const selections = [...document.querySelectorAll('[data-inbox-trash-select]')];
    const count = trashBulk.querySelector('[data-inbox-trash-count]');
    const submit = trashBulk.querySelector('[data-inbox-trash-delete]');
    const update = () => {
      const chosen = selections.filter(input => input.checked).length;
      count.textContent = `${chosen} selected`;
      submit.disabled = chosen === 0;
      selectAll.checked = chosen === selections.length;
      selectAll.indeterminate = chosen > 0 && chosen < selections.length;
    };
    selectAll.addEventListener('change',() => { selections.forEach(input => { input.checked = selectAll.checked; }); update(); });
    selections.forEach(input => input.addEventListener('change',update));
    window.addEventListener('pageshow',update);
    update();
  }
  const csrf = document.querySelector('input[name="csrf_token"]');
  const status = document.getElementById('inbox-live-status');
  const announce = message => { status.hidden = false; status.textContent = message; };
  const dialog = document.getElementById('inbox-action-dialog');
  const linkField = document.getElementById('inbox-link-field');
  const linkInput = document.getElementById('inbox-link-url');
  const linkError = document.getElementById('inbox-link-error');
  function ask(title, message, link = false, trashPurge = false) {
    if (dialog.open) return Promise.resolve(null);
    dialog.classList.toggle('inbox-trash-purge-confirmation',trashPurge);
    document.getElementById('inbox-action-title').textContent = title;
    document.getElementById('inbox-action-message').textContent = message;
    linkField.hidden = !link; linkInput.value = ''; linkError.hidden = true;
    linkInput.removeAttribute('aria-invalid'); dialog.returnValue = '';
    return new Promise(resolve => {
      dialog.addEventListener('close',() => resolve(dialog.returnValue === 'continue' ? (link ? linkInput.value.trim() : true) : null),{once:true});
      dialog.showModal();
      (link ? linkInput : dialog.querySelector('[data-inbox-dialog-cancel]')).focus();
    });
  }
  dialog.querySelector('[data-inbox-dialog-cancel]').addEventListener('click',() => dialog.close('cancel'));
  dialog.querySelector('form').addEventListener('submit',event => {
    if (!linkField.hidden && !/^(?:https?:\/\/|mailto:)[^\s<>"']+$/i.test(linkInput.value.trim())) {
      event.preventDefault(); linkError.hidden = false; linkInput.setAttribute('aria-invalid','true'); linkInput.focus();
    }
  });
  async function post(action, values = {}) {
    const data = values instanceof FormData ? values : new FormData();
    if (!(values instanceof FormData)) Object.entries(values).forEach(([k,v]) => data.set(k,String(v)));
    data.set('action',action); data.set('format','json');
    if (csrf) data.set(csrf.name,csrf.value);
    const response = await fetch(config.endpoint,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error || 'The request could not be completed.');
    return result;
  }
  document.querySelectorAll('[data-inbox-rich]').forEach(root => {
    const editor = root.querySelector('[contenteditable]'); const input = root.querySelector('textarea');
    editor.addEventListener('input',() => { input.value = editor.innerHTML; });
    root.querySelectorAll('[data-format]').forEach(button => button.addEventListener('mousedown',event => event.preventDefault()));
    root.querySelectorAll('[data-format]').forEach(button => button.addEventListener('click',async () => {
      editor.focus(); let value = null;
      if (button.dataset.format === 'createLink') {
        const selection = window.getSelection();
        const range = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
        value = await ask('Add a link','Enter the destination for the selected text.',true); if (!value) return;
        editor.focus(); if (range) { selection.removeAllRanges(); selection.addRange(range); }
      }
      document.execCommand(button.dataset.format,false,value); input.value = editor.innerHTML;
    }));
    root.closest('form').addEventListener('submit',() => { input.value = editor.innerHTML; });
  });
  const approvedForms = new WeakSet();
  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit',async event => {
    // Inbox owns these confirmations; avoid the shared Audience form handler.
    event.stopImmediatePropagation();
    if (approvedForms.has(form)) { approvedForms.delete(form); return; }
    event.preventDefault(); const submitter = event.submitter;
    if (form.hasAttribute('data-admin-unlock-required') && !await window.DnrAdminUnlock?.request()) return;
    if (await ask('Confirm action',form.dataset.confirm,false,form.id === 'inbox-trash-bulk')) { approvedForms.add(form); form.requestSubmit(submitter || undefined); approvedForms.delete(form); }
  },true));
  const compose = document.querySelector('[data-inbox-compose]');
  let dirty = false, saving = false, lastTyped = 0;
  if (compose) {
    compose.addEventListener('input',event => { if (event.target.matches('[data-inbox-template]')) return; dirty = true; lastTyped = Date.now(); });
    const save = async () => {
      if (saving) throw new Error('Wait for the current save to finish.'); saving = true;
      try {
        compose.querySelector('[name="body_html"]').value = compose.querySelector('[contenteditable]').innerHTML;
        const result = await post('inbox_draft_save',new FormData(compose));
        config.draft = Number(result.draft.id); config.draftVersion = Number(result.draft.version);
        compose.querySelector('[name="draft_id"]').value = config.draft; compose.querySelector('[name="version"]').value = config.draftVersion;
        document.querySelectorAll('form').forEach(form => { if (Number(form.querySelector('[name="draft_id"]')?.value) === config.draft) { const version = form.querySelector('[name="version"]'); if (version) version.value = config.draftVersion; } });
        const row = document.querySelector(`[data-inbox-draft-id="${config.draft}"]`);
        if (row) {
          row.querySelector('.inbox-thread-subject').textContent = result.draft.subject || '(No subject)';
          row.querySelector('p').textContent = compose.querySelector('[contenteditable]').innerText.trim().slice(0,140);
          const time = row.querySelector('time'); time.dateTime = result.draft.updated_at.replace(' ','T') + 'Z'; renderTime(time);
          row.parentElement.prepend(row);
        }
        const heading = document.querySelector('[data-inbox-draft-subject]'); if (heading) heading.textContent = result.draft.subject || '(No subject)';
        const edited = document.querySelector('.inbox-draft-heading time'); if (edited) { edited.dateTime = result.draft.updated_at.replace(' ','T') + 'Z'; renderTime(edited); }
        dirty = false; return result;
      } finally { saving = false; }
    };
    compose.addEventListener('submit',async event => { event.preventDefault(); const wasNew = !config.draft;
      try { const result = await save(); if (wasNew) location.assign(result.redirect); else announce('Draft saved.'); } catch(error) { announce(error.message); }
    });
    compose.querySelector('[data-inbox-send]').addEventListener('click',async event => {
      const button = event.currentTarget; button.disabled = true;
      if (!await ask('Send message','Send this message to the displayed connection?')) { button.disabled = false; return; }
      try { await save(); const result = await post('inbox_send',{draft_id:config.draft,version:config.draftVersion}); location.assign(result.redirect); }
      catch(error) { announce(error.message); button.disabled = false; }
    });
    let selectedTemplate = compose.querySelector('[data-inbox-template]').value;
    compose.querySelector('[data-inbox-template]').addEventListener('change',async event => {
      const select = event.currentTarget;
      const template = templates.find(t => String(t.id) === event.target.value); if (!template) { selectedTemplate = select.value; return; }
      if (dirty && !await ask('Replace message','Replace the subject and body with this message template?')) { select.value = selectedTemplate; return; }
      selectedTemplate = select.value;
      compose.querySelector('[name="subject"]').value = template.subject;
      // Template HTML is sanitized by the same server allowlist on every save and read.
      compose.querySelector('[contenteditable]').innerHTML = template.body_html;
      compose.querySelector('[name="body_html"]').value = template.body_html; dirty = true;
    });
    window.addEventListener('beforeunload',event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  }
  document.querySelector('.inbox-comment-form')?.addEventListener('input',() => { lastTyped = Date.now(); });
  document.querySelectorAll('[data-inbox-comment-reply]').forEach(button => button.addEventListener('click',() => {
    const form = document.querySelector('.inbox-comment-form'); form.closest('details').open = true; form.querySelector('[name="parent_id"]').value = button.dataset.inboxCommentReply;
    const parent = form.querySelector('[data-inbox-comment-parent]'); parent.hidden = false; parent.textContent = `Replying internally to comment #${button.dataset.inboxCommentReply}`;
    form.querySelector('[data-inbox-comment-cancel]').hidden = false;
    form.querySelector('[contenteditable]').focus(); form.scrollIntoView({behavior:'smooth',block:'center'});
  }));
  document.querySelector('[data-inbox-comment-cancel]')?.addEventListener('click',event => {
    const form = event.currentTarget.closest('form'); form.querySelector('[name="parent_id"]').value = '0';
    form.querySelector('[data-inbox-comment-parent]').hidden = true; event.currentTarget.hidden = true;
  });
  document.querySelector('.inbox-mobile-nav').addEventListener('click',event => {
    const open = document.getElementById('inbox-sidebar').classList.toggle('is-open'); event.currentTarget.setAttribute('aria-expanded',String(open));
  });
  document.addEventListener('keydown',event => { if (event.key === 'Escape') { document.getElementById('inbox-sidebar').classList.remove('is-open'); document.querySelector('.inbox-mobile-nav').setAttribute('aria-expanded','false'); } });
  document.addEventListener('keydown',event => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.target.closest('input,textarea,select,[contenteditable="true"],dialog')) return;
    const search = document.getElementById('inbox-search'); if (!search || !search.checkVisibility()) return;
    event.preventDefault(); search.focus();
  });
  document.addEventListener('click',event => {
    document.querySelectorAll('.inbox-labels-picker[open],.inbox-sender-details[open]').forEach(details => { if (!details.contains(event.target)) details.open = false; });
  });
  let timer;
  const recipients = document.querySelector('[data-inbox-recipient-search]');
  recipients?.addEventListener('input',() => { clearTimeout(timer); const query = recipients.value;
    timer = setTimeout(async () => { try { const response = await fetch(`${config.endpoint}&inbox_api=recipients&q=${encodeURIComponent(query)}`); if (!response.ok) return; const result = await response.json(); if (recipients.value !== query) return;
      const list = document.querySelector('[data-inbox-recipients]'); list.replaceChildren(); result.items.forEach(person => { const item = document.createElement('li'), link = document.createElement('a'); link.href = `${config.endpoint}&panel=compose&contact=${person.id}`; link.textContent = `${person.first_name} ${person.last_name} <${person.email}>`; item.append(link); list.append(item); });
    } catch { announce('Recipient search is unavailable. Try the Search button.'); } },250);
  });
  const permissionButton = document.querySelector('[data-inbox-notification-permission]');
  const permissionStatus = document.querySelector('[data-inbox-permission-status]');
  const renderPermission = state => {
    if (!permissionButton || !permissionStatus) return;
    permissionStatus.hidden = false; permissionStatus.textContent = state.message; permissionStatus.dataset.state = state.state;
    permissionButton.textContent = state.label; permissionButton.disabled = state.disabled;
  };
  if (permissionButton) {
    renderPermission(inboxNotificationPermissionState(window));
    permissionButton.addEventListener('click',async () => {
      renderPermission({state:'pending',label:'Waiting for browser…',disabled:true,message:'Respond to your browser’s notification prompt. If no prompt appears, open Inbox in your regular browser to allow notifications.'});
      const state = await requestInboxNotificationPermission(window); renderPermission(state);
      if (state.state === 'granted') {
        const checkbox = document.querySelector('.inbox-settings [name="browser_alerts"]'); if (checkbox) checkbox.checked = true;
      }
    });
    window.addEventListener('focus',() => renderPermission(inboxNotificationPermissionState(window)));
  }
  let newest = Math.max(0,...config.notifications.map(item => Number(item.id)));
  async function poll() {
    if (document.hidden) return;
    try {
      const response = await fetch(`${config.endpoint}&inbox_api=notifications&after=${newest}`); if (!response.ok) return; const result = await response.json();
      for (const item of [...result.items].reverse()) { newest = Math.max(newest,Number(item.id)); if (Number(item.thread_id) === config.thread) announce('New activity in this conversation. Refresh to view it.'); if (result.settings.browser_alerts && 'Notification' in window && Notification.permission === 'granted') { const notification = new Notification('MessageMonkey Inbox',{body:item.kind === 'comment' ? 'A team member added an internal comment.' : 'A new conversation message arrived.',tag:`message-monkey-inbox-${item.id}`}); notification.onclick = () => { window.focus(); location.assign(`${config.endpoint}&thread=${item.thread_id}`); notification.close(); }; } }
      if (config.thread && csrf) { const presence = await post('inbox_presence',{thread_id:config.thread,typing:Date.now() - lastTyped < 25000 ? 1 : 0}); const text = document.querySelector('[data-inbox-presence]'); if (text) text.textContent = presence.presence.map(p => `${p.name} ${p.is_typing ? 'is typing' : 'is viewing'}`).join(' · '); }
    } catch { /* Keep draft editing available during a temporary connection failure. */ }
  }
  if (config.thread && config.lastMessage && (!compose || compose.hasAttribute('data-inbox-inline')) && csrf) {
    post('inbox_read',{thread_id:config.thread,read:1}).then(() => {
      const selected = document.querySelector('.inbox-thread-item.selected'); selected?.classList.remove('unread'); selected?.querySelector('[aria-label="Unread"]')?.remove();
    }).catch(() => announce('Read status could not be updated.'));
  }
  setInterval(poll,20000); poll();
})();
