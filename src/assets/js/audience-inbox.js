(() => {
  'use strict';
  const node = document.getElementById('inbox-config');
  if (!node) return;
  const config = JSON.parse(node.textContent);
  const templates = JSON.parse(document.getElementById('inbox-templates').textContent);
  document.body.classList.add('inbox-js');
  const csrf = document.querySelector('input[name="csrf_token"]');
  const status = document.getElementById('inbox-live-status');
  const announce = message => { status.hidden = false; status.textContent = message; };
  const dialog = document.getElementById('inbox-action-dialog');
  const linkField = document.getElementById('inbox-link-field');
  const linkInput = document.getElementById('inbox-link-url');
  const linkError = document.getElementById('inbox-link-error');
  function ask(title, message, link = false) {
    if (dialog.open) return Promise.resolve(null);
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
    if (approvedForms.has(form)) { approvedForms.delete(form); return; }
    event.preventDefault(); const submitter = event.submitter;
    if (await ask('Confirm action',form.dataset.confirm)) { approvedForms.add(form); form.requestSubmit(submitter || undefined); approvedForms.delete(form); }
  }));
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
        dirty = false; return result;
      } finally { saving = false; }
    };
    compose.addEventListener('submit',async event => { event.preventDefault(); const wasNew = !config.draft;
      try { await save(); if (wasNew) location.assign(`${config.endpoint}&panel=compose&draft=${config.draft}`); else announce('Draft saved.'); } catch(error) { announce(error.message); }
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
      const template = templates.find(t => String(t.id) === event.target.value); if (!template) return;
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
    const form = document.querySelector('.inbox-comment-form'); form.querySelector('[name="parent_id"]').value = button.dataset.inboxCommentReply;
    const parent = form.querySelector('[data-inbox-comment-parent]'); parent.hidden = false; parent.textContent = `Replying internally to comment #${button.dataset.inboxCommentReply}`;
    form.querySelector('[contenteditable]').focus(); form.scrollIntoView({behavior:'smooth',block:'center'});
  }));
  document.querySelector('.inbox-mobile-nav').addEventListener('click',event => {
    const open = document.getElementById('inbox-sidebar').classList.toggle('is-open'); event.currentTarget.setAttribute('aria-expanded',String(open));
  });
  document.addEventListener('keydown',event => { if (event.key === 'Escape') { document.getElementById('inbox-sidebar').classList.remove('is-open'); document.querySelector('.inbox-mobile-nav').setAttribute('aria-expanded','false'); } });
  let timer;
  const recipients = document.querySelector('[data-inbox-recipient-search]');
  recipients?.addEventListener('input',() => { clearTimeout(timer); const query = recipients.value;
    timer = setTimeout(async () => { try { const response = await fetch(`${config.endpoint}&inbox_api=recipients&q=${encodeURIComponent(query)}`); if (!response.ok) return; const result = await response.json(); if (recipients.value !== query) return;
      const list = document.querySelector('[data-inbox-recipients]'); list.replaceChildren(); result.items.forEach(person => { const item = document.createElement('li'), link = document.createElement('a'); link.href = `${config.endpoint}&panel=compose&contact=${person.id}`; link.textContent = `${person.first_name} ${person.last_name} <${person.email}>`; item.append(link); list.append(item); });
    } catch { announce('Recipient search is unavailable. Try the Search button.'); } },250);
  });
  document.querySelector('[data-inbox-notification-permission]')?.addEventListener('click',async () => { if (!('Notification' in window)) { announce('This browser does not support notifications.'); return; } const permission = await Notification.requestPermission(); announce(permission === 'granted' ? 'Browser permission granted. Save your notification preferences to enable alerts.' : 'Browser notifications are not allowed.'); });
  let newest = Math.max(0,...config.notifications.map(item => Number(item.id)));
  async function poll() {
    if (document.hidden) return;
    try {
      const response = await fetch(`${config.endpoint}&inbox_api=notifications&after=${newest}`); if (!response.ok) return; const result = await response.json();
      for (const item of [...result.items].reverse()) { newest = Math.max(newest,Number(item.id)); if (Number(item.thread_id) === config.thread) announce('New activity in this conversation. Refresh to view it.'); if (result.settings.browser_alerts && 'Notification' in window && Notification.permission === 'granted') { const notification = new Notification('MessageMonkey Inbox',{body:item.kind === 'comment' ? 'A team member added an internal comment.' : 'A new conversation message arrived.',tag:`message-monkey-inbox-${item.id}`}); notification.onclick = () => { window.focus(); location.assign(`${config.endpoint}&thread=${item.thread_id}`); notification.close(); }; } }
      if (config.thread && csrf) { const presence = await post('inbox_presence',{thread_id:config.thread,typing:Date.now() - lastTyped < 25000 ? 1 : 0}); const text = document.querySelector('[data-inbox-presence]'); if (text) text.textContent = presence.presence.map(p => `${p.name} ${p.is_typing ? 'is typing' : 'is viewing'}`).join(' · '); }
    } catch { /* Keep draft editing available during a temporary connection failure. */ }
  }
  if (config.thread && config.lastMessage && !compose && csrf) post('inbox_read',{thread_id:config.thread,read:1}).catch(() => announce('Read status could not be updated.'));
  setInterval(poll,20000); poll();
})();
