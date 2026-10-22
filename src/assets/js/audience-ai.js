(() => {
  'use strict';
  const configNode = document.getElementById('audience-ai-config');
  const root = document.querySelector('[data-ai-root]');
  if (!configNode || !root) return;
  const config = JSON.parse(configNode.textContent);
  const find = selector => root.querySelector(selector);
  const form = find('[data-ai-form]');
  const status = find('[data-ai-status]');
  const output = find('[data-ai-result]');
  const chat = config.mode === 'analytics' && root.hasAttribute('data-ai-chat');
  const transcript = chat ? find('[data-ai-chat-transcript]') : null;
  const shownQuestions = new Set(), shownAnswers = new Set(), questionTexts = new Map();
  const resetHistoryButton = document.querySelector('[data-ai-history-reset]');
  const historyResetKey = `moed-audience-ai-history-reset-${config.key}-${config.mode}`;
  const historyHiddenKey = `${historyResetKey}-hidden`;
  let historyItems = [], historyCutoff = '';
  let canRemoveHistory = false, historyChangePending = false;
  let hiddenRequests = new Set();
  try { historyCutoff = localStorage.getItem(historyResetKey) || ''; } catch { /* The list still works without storage. */ }
  try { const saved=JSON.parse(localStorage.getItem(historyHiddenKey)||'[]');if(Array.isArray(saved))hiddenRequests=new Set(saved.filter(id=>typeof id==='string')); } catch { /* Removing an entry still works for the current page. */ }
  const storageKey = `moed-audience-ai-${config.key}`;
  const maxWaitMs = config.mode === 'analytics' ? 100000 : 30000;
  let active = '', completed = '', previous = '', displayedRequest = '', generation = 0, target = null, busy = false;
  const node = (tag, text, className) => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; if (className) element.className = className; return element; };
  const announce = text => { status.textContent = text; };
  const clearPending = () => { try { sessionStorage.removeItem(storageKey); } catch { /* Recovery is optional. */ } };
  function resetChat() {
    if (!chat) return;
    transcript.replaceChildren(); transcript.hidden = true; shownQuestions.clear(); shownAnswers.clear(); questionTexts.clear();
    displayedRequest='';renderHistory();
    find('[data-ai-welcome]').hidden = false; find('[data-ai-chat-suggestions]').hidden = false;
    form.elements.prompt.value = ''; form.elements.prompt.focus();
  }
  function question(text, uuid) {
    if (!chat || shownQuestions.has(uuid)) return;
    displayedRequest=uuid;renderHistory();
    shownQuestions.add(uuid); questionTexts.set(uuid,text); transcript.hidden = false;
    find('[data-ai-welcome]').hidden = true; find('[data-ai-chat-suggestions]').hidden = true;
    const message = node('article', undefined, 'analytics-ai-message analytics-ai-question-message');
    message.append(node('h2', 'You', 'sr-only'), node('p', text)); transcript.append(message);
    message.scrollIntoView({block:'nearest'});
  }
  function failure(message, uuid, prompt = '') {
    announce(message);
    if (!chat || shownAnswers.has(uuid)) return;
    question(prompt || questionTexts.get(uuid) || 'Saved request', uuid);
    shownAnswers.add(uuid);
    const answer=node('article',undefined,'analytics-ai-message analytics-ai-answer analytics-ai-error');
    answer.append(node('h3','The request could not be completed'),node('p',message));
    const retry=node('button','Retry');retry.type='button';
    retry.addEventListener('click',()=>{if(busy)return;form.elements.prompt.value=questionTexts.get(uuid)||prompt;form.requestSubmit();});
    answer.append(retry);transcript.append(answer);answer.scrollIntoView({block:'nearest'});status.classList.add('sr-only');
  }
  function updateScope(changes) {
    if (!chat) return;
    const next = {...config.window, ...changes};
    if (next.dataset !== 'email') Object.assign(next, {bots:'filtered', channel:'all', messages_mode:'all', messages:[], segment:0});
    else next.source = '';
    if (JSON.stringify(next) !== JSON.stringify(config.window)) previous = '';
    config.window = next;
    find('[data-ai-chat-scope]').textContent = `${next.start} to ${next.end} UTC · ${next.dataset}`;
  }
  const setBusy = value => {
    busy = value;
    find('[data-ai-generate]').disabled = value || !config.enabled;
    find('[data-ai-stop]').hidden = !value;
    const thinking = find('[data-ai-thinking]'); if (thinking) thinking.hidden = !value;
    if (chat) status.classList.toggle('sr-only', value);
    root.setAttribute('aria-busy', String(value));
  };
  async function api(values, query = '') {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 7000);
    try {
      const response = await fetch(config.endpoint + query, values ? {method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':form.querySelector('[name=csrf_token]').value},body:JSON.stringify(values)} : {credentials:'same-origin',signal:controller.signal});
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || 'The assistant request could not be completed');
      return result;
    } finally { clearTimeout(timer); }
  }
  function checks(result) {
    const box = find('[data-ai-check-result]'); box.replaceChildren(); box.hidden = false;
    box.append(node('h3','Content checks'));
    const list = node('dl',undefined,'ai-facts');
    result.facts.forEach(fact => { const item = node('div'); item.append(node('dt',fact.label),node('dd',String(fact.value)),node('small',fact.note)); list.append(item); });
    box.append(list);
    result.findings.forEach(text => box.append(node('p',text)));
    box.append(node('p',result.note,'ai-disclosure'));
  }
  function chart(evidence, box) {
    const series = evidence.series;
    if (evidence.window.dataset && evidence.window.dataset !== 'email') return;
    if (!series.length) return;
    if (!series.some(row => row.accepted)) { box.append(node('p','No accepted email in this report scope')); return; }
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace,'svg'); svg.setAttribute('viewBox','0 0 640 160'); svg.setAttribute('role','img'); svg.setAttribute('aria-label',`Accepted email by period from ${evidence.window.start} to ${evidence.window.end} UTC`);
    const max = Math.max(...series.map(row => row.accepted),1); const width = 640 / series.length;
    series.forEach((row,index) => { const bar = document.createElementNS(namespace,'rect'); const height = row.accepted/max*140; bar.setAttribute('x',String(index*width)); bar.setAttribute('y',String(150-height)); bar.setAttribute('width',String(Math.max(.5,width-1))); bar.setAttribute('height',String(height)); const title = document.createElementNS(namespace,'title'); title.textContent = `${row.day}${row.until ? ` – ${row.until}` : ''} UTC: ${row.accepted} accepted messages`; bar.append(title); svg.append(bar); });
    const figure = node('figure',undefined,'ai-chart'); figure.append(svg,node('figcaption','Accepted email by period · UTC · application-calculated')); box.append(figure);
  }
  function render(reply, uuid, prompt = '') {
    if (reply?.purpose !== 'audience-ai' || reply.reason) { output.hidden = true; failure(reply?.purpose === 'audience-ai' ? reply.message : 'The AI service did not return a usable answer. Please try again.',uuid,prompt); return; }
    if (reply.mode !== config.mode) { announce('This request belongs to the other assistant. Open it from the matching tab'); return; }
    if (chat && shownAnswers.has(uuid)) return;
    completed = uuid; previous = uuid; output.hidden = chat;
    announce(config.mode === 'analytics' ? '' : 'Suggestion ready for your review');
    const recommendations = chat ? node('section') : find('[data-ai-recommendations]'); recommendations.replaceChildren();
    if (reply.recommendations.length) { recommendations.append(node('h3','Suggested next steps')); const list = node('ul'); reply.recommendations.forEach(text => list.append(node('li',text))); recommendations.append(list); }
    if (reply.mode === 'analytics') {
      question(prompt || form.elements.prompt.value || 'Saved analytics request', uuid);
      const box = chat ? node('article', undefined, 'analytics-ai-message analytics-ai-answer') : find('[data-ai-analysis]'); box.hidden = false; box.replaceChildren();
      box.append(node('h3',reply.interpretation === 'clarification' ? 'A quick clarification' : reply.interpretation === 'calculated' ? 'Report results' : reply.interpretation === 'data-only' ? 'Query results' : 'AI interpretation'));
      if (reply.summary) box.append(node('p',reply.summary));
      const evidence = reply.evidence;
      const cited = new Set(reply.observations.flatMap(item=>item.evidence_ids || [item.evidence_id]));
      box.append(node('p',evidence.scope_description || `Source report (${evidence.window.dataset || 'email'}): ${evidence.window.start} to ${evidence.window.end} UTC · ${evidence.window.bots==='filtered'?'Known bots excluded':'All observed activity'} · Comparison: ${evidence.window.compare}`,'ai-scope'));
      if(Object.keys(config.window).some(key=>JSON.stringify(config.window[key])!==JSON.stringify(evidence.window[key]))){previous='';announce('Saved analysis opened for its original report scope. Your next question will use the current report filters');}
      if (evidence.includes_samples) box.append(node('p','Local sample data included. These observations include fictional test activity','audience-notice'));
      chart(evidence,box);
      for (const result of evidence.tables || []) {
        const scroll=node('div',undefined,'analytics-ai-table-scroll'),table=node('table'),caption=node('caption');scroll.id=`ai-evidence-${uuid}-${result.id}`;
        let loaded=result.rows.length,next=result.next_offset;
        const updateCaption=total=>{caption.textContent=Number.isInteger(total)?`${result.title} · Showing ${loaded} of ${total}`:result.title;};updateCaption(result.total);table.append(caption);
        const header=node('tr');result.columns.forEach(column=>{const cell=node('th',column.label);cell.setAttribute('scope','col');header.append(cell);});const head=node('thead');head.append(header);table.append(head);
        const body=node('tbody');const appendRows=rows=>rows.forEach(row=>{const line=node('tr');result.columns.forEach(column=>line.append(node('td',String(row[column.key]??''))));body.append(line);});appendRows(result.rows);table.append(body);scroll.append(table);box.append(scroll);
        // Older saved replies used a smaller first page and have no explicit cursor.
        if(next===undefined&&Number.isInteger(result.total)&&loaded<result.total)next=loaded;
        if(Number.isInteger(next)){
          const more=node('button','Show more results'),notice=node('p','','ai-scope');more.type='button';notice.setAttribute('role','status');box.append(more,notice);
          more.addEventListener('click',async()=>{
            more.disabled=true;more.textContent='Loading…';notice.textContent='';
            try{
              const response=await api(null,`?action=contacts&request_id=${encodeURIComponent(uuid)}&table_id=${encodeURIComponent(result.id)}&offset=${next}`);
              if(response.table?.id!==result.id||!Array.isArray(response.table.rows))throw new Error('The contact results could not be loaded.');
              appendRows(response.table.rows);loaded+=response.table.rows.length;next=response.table.next_offset;updateCaption(response.table.total);more.hidden=!Number.isInteger(next);
            }catch(error){notice.textContent=error.message;}
            finally{more.disabled=false;more.textContent='Show more results';}
          });
        }
        if (!result.rows.length) box.append(node('p','No audience records matched this lookup.'));
      }
      const displayedFacts = reply.interpretation === 'data-only' ? evidence.facts : evidence.facts.filter(fact=>cited.has(fact.id));
      const cards = node('dl',undefined,'ai-facts');
      displayedFacts.forEach(fact => { const item = node('div'); item.id = `ai-evidence-${uuid}-${fact.id}`; const display = value => value === null ? '—' : Number(value).toLocaleString(undefined,{maximumFractionDigits:2})+(fact.unit === '%' ? '%' : ['count','messages'].includes(fact.unit) ? '' : ` ${fact.unit}`); item.append(node('dt',fact.label),node('dd',display(fact.value)),node('small',fact.previous === null ? 'No comparison baseline' : `Previous: ${display(fact.previous)}${fact.change === null ? '' : fact.change === 0 ? ' · Unchanged' : ` · ${fact.change>0?'↑':'↓'} ${Math.abs(fact.change)}%`}`)); cards.append(item); }); if(displayedFacts.length)box.append(cards);
      reply.observations.forEach(observation => {
        const p = node('p',observation.text+' ');
        (observation.evidence_ids || [observation.evidence_id]).forEach(id => {
          const source = evidence.facts.find(item => item.id === id) || (evidence.tables || []).find(item => item.id === id);
          if(source){const link = node('a',`Source: ${source.label || source.title}`); link.href = `#ai-evidence-${uuid}-${source.id}`; p.append(link,document.createTextNode(' '));}
        });
        box.append(p);
      });
      const detail = node('details'); detail.append(node('summary','Source calculations and limitations'));
      Object.entries(evidence.calculations).forEach(([key,text])=>detail.append(node('p',`${key}: ${text}`)));
      evidence.limitations.forEach(text=>detail.append(node('p',text))); box.append(detail);
      if (chat) { shownAnswers.add(uuid); box.append(recommendations); transcript.append(box); box.scrollIntoView({block:'start'}); }
    } else {
      find('[data-ai-headline]').value = reply.headline;
      find('[data-ai-text]').value = reply.text;
      const subjects = find('[data-ai-subjects]'); subjects.replaceChildren(node('h3','Subject ideas'));
      reply.subjects.forEach(text => { const row = node('div'); const use = node('button','Use this subject'); use.type = 'button'; use.addEventListener('click',()=>{form.elements.subject.value=text; announce('Subject selected in this workspace. The campaign is unchanged');}); const copy=node('button','Copy subject');copy.type='button';copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(text);announce('Subject copied');}catch{announce('Select the subject text to copy it');}}); row.append(node('p',text),use,copy);subjects.append(row); });
      checks(reply.checks);
    }
  }
  function renderHistory() {
    const list = document.querySelector('[data-ai-history]'); if (!list) return;
      const items = historyItems.filter(item => item.created_at > historyCutoff && !hiddenRequests.has(item.client_request_id)); list.replaceChildren(); document.querySelector('[data-ai-history-empty]').hidden = items.length>0;
      if (resetHistoryButton) { resetHistoryButton.hidden = !canRemoveHistory; resetHistoryButton.disabled = items.length === 0 || historyChangePending; }
      items.forEach(item => { const li=node('li');const button=node('button',item.question,'ai-history-open');button.type='button';if(item.client_request_id===displayedRequest){button.className+=' is-current';button.setAttribute('aria-current','true');}button.addEventListener('click',async()=>{
          if(busy&&active===item.client_request_id){question(item.question,item.client_request_id);return;}
          if(busy){generation++;active='';clearPending();setBusy(false);}
          const token=++generation;previous='';completed='';output.hidden=true;resetChat();question(item.question,item.client_request_id);
          try{
            const result=await api(null,`?request_id=${encodeURIComponent(item.client_request_id)}`);if(token!==generation)return;
            if(result.state==='complete'){complete({...result,question:item.question},item.client_request_id);}
            else if(result.state==='pending'){
              active=item.client_request_id;setBusy(true);announce('Your request is being processed');
              const created=Date.parse(item.created_at.replace(' ','T')+'Z');const started=Number.isFinite(created)?created:Date.now();
              try{sessionStorage.setItem(storageKey,JSON.stringify({uuid:active,started,mode:config.mode}));}catch{/* Recovery is optional. */}
              return poll(active,token,started);
            }else failure('This request was interrupted. Please retry it.',item.client_request_id,item.question);
          }catch(error){if(token===generation)failure(error.message,item.client_request_id,item.question);}
        });
        li.append(button);
        if (canRemoveHistory) {
          const remove=node('button',undefined,'ai-history-remove');remove.type='button';remove.title='Remove from recent requests (Admin Unlock required)';remove.setAttribute('aria-label',`Remove request: ${item.question}`);remove.setAttribute('data-admin-unlock-required','');
          const ns='http://www.w3.org/2000/svg',icon=document.createElementNS(ns,'svg'),circle=document.createElementNS(ns,'circle'),cross=document.createElementNS(ns,'path');icon.setAttribute('viewBox','0 0 24 24');icon.setAttribute('aria-hidden','true');circle.setAttribute('cx','12');circle.setAttribute('cy','12');circle.setAttribute('r','9');cross.setAttribute('d','m8 8 8 8M16 8l-8 8');icon.append(circle,cross);remove.append(icon);
          remove.addEventListener('click',()=>changeHistory(()=>{hiddenRequests.add(item.client_request_id);try{localStorage.setItem(historyHiddenKey,JSON.stringify([...hiddenRequests].slice(-200)));}catch{/* Current-page removal remains available. */}announce('Request removed from the list.');}));
          li.append(remove);
        }
        li.append(node('small',`${item.created_at.replace(/\.[0-9]+$/,'')} UTC · ${item.outcome}`));list.append(li); });
  }
  async function changeHistory(change) {
    if (!canRemoveHistory || historyChangePending) return;
    historyChangePending = true;
    if (resetHistoryButton) resetHistoryButton.disabled = true;
    try {
      if (!(await window.DnrAdminUnlock?.request()) || !canRemoveHistory) return;
      change();
    } catch { announce('Unable to verify Admin Unlock. The request list is unchanged.'); }
    finally { historyChangePending = false; renderHistory(); }
  }
  async function history() {
    try {
      const data = await api(null,'?action=history'); historyItems = data.items.filter(item => item.manual_topic === `audience-ai:${config.mode}`); canRemoveHistory = data.can_remove_history === true; renderHistory();
      if(chat&&active){const item=historyItems.find(item=>item.client_request_id===active);if(item)question(item.question,active);}
    } catch { /* History failure never prevents manual editing. */ }
  }
  resetHistoryButton?.addEventListener('click', () => changeHistory(() => {
    historyCutoff = historyItems.reduce((latest, item) => item.created_at > latest ? item.created_at : latest, historyCutoff);
    try { localStorage.setItem(historyResetKey, historyCutoff); } catch { /* The current list can still be reset. */ }
    announce('Request list reset. Saved requests remain available for review.');
  }));
  function complete(result, uuid) {
    clearPending(); active=''; setBusy(false);
    if (chat && result.response?.evidence?.window) updateScope(Object.fromEntries(Object.keys(config.window).map(key => [key,result.response.evidence.window[key]])));
    try { render(result.response, uuid, result.question); }
    catch { failure('The answer was received but could not be displayed. Please try again.',uuid,result.question); }
    history();
  }
  async function poll(uuid, token, started) {
    while (token===generation && Date.now()-started<maxWaitMs) {
      try {
        const result=await api(null,`?request_id=${encodeURIComponent(uuid)}`);
        if(token!==generation)return;
        if(result.state==='complete'){complete(result,uuid);return;}
        if(result.state==='interrupted')break;
        announce(result.stage==='generating'?'Preparing your suggestion':'Your request is queued');
      } catch { if(Date.now()-started>maxWaitMs-5000)break; }
      await new Promise(resolve=>setTimeout(resolve,900));
    }
    if(token===generation){clearPending();active='';setBusy(false);failure('The AI service did not finish in time. Please try again.',uuid);history();}
  }
  async function stop() {
    const uuid=active;generation++;active='';clearPending();setBusy(false);
    if(uuid)try{await api({action:'cancel',request_id:uuid});}catch{ /* Worker deadline also prevents late publication. */ }
    announce('Request stopped. Your content is unchanged');
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;
    const values=Object.fromEntries(new FormData(form)); delete values.csrf_token;
    if (chat && !values.prompt?.trim()) return;
    if (chat) form.elements.prompt.style.height = '';
    const uuid=crypto.randomUUID(),token=++generation;active=uuid;setBusy(true);output.hidden=true;announce('Saving your request');
    question(values.prompt, uuid);
    if (chat) find('[data-ai-thinking]').scrollIntoView({block:'nearest'});
    const request={...values,mode:config.mode,request_id:uuid,window:config.window,parent:previous};
    form.elements.prompt.value = ''; form.elements.prompt.dispatchEvent(new Event('input'));
    try {
      try{sessionStorage.setItem(storageKey,JSON.stringify({uuid,started:Date.now(),mode:config.mode}));}catch{ /* No source text is persisted in browser recovery. */ }
      const result=await api(request);if(token!==generation)return;
      if(result.state==='complete'){complete(result,uuid);}
      else poll(uuid,token,Date.now());
    }catch(error){if(token===generation){active='';setBusy(false);clearPending();failure(error.message,uuid,values.prompt);}}
  });
  find('[data-ai-stop]').addEventListener('click',stop);
  find('[data-ai-new]').addEventListener('click',async()=>{generation++;if(busy)await stop();previous='';completed='';output.hidden=true;find('[data-ai-check-result]').hidden=true;resetChat();announce('Ready for a new request');});
  root.querySelectorAll('[data-ai-starter]').forEach(button=>button.addEventListener('click',()=>{if(busy)return;if(chat&&button.dataset.aiWindow)updateScope(JSON.parse(button.dataset.aiWindow));form.elements.prompt.value=button.textContent;form.elements.prompt.dispatchEvent(new Event('input'));form.elements.prompt.focus();}));
  if (chat) {
    const resize = () => { const input=form.elements.prompt; input.style.height='auto'; input.style.height=`${Math.min(160,input.scrollHeight)}px`; };
    form.elements.prompt.addEventListener('input',resize); resize();
    form.elements.prompt.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();form.requestSubmit();}});
    find('[data-ai-history-toggle]').addEventListener('click',event=>{const button=event.currentTarget,panel=find('#analytics-ai-history');panel.hidden=!panel.hidden;root.classList.toggle('history-collapsed',panel.hidden);button.setAttribute('aria-expanded',String(!panel.hidden));button.querySelector('span').textContent=panel.hidden?'Show chat history':'Hide chat history';});
    const measure = () => root.style.setProperty('--analytics-ai-offset',`${Math.max(0,root.getBoundingClientRect().top)}px`);
    window.addEventListener('resize',measure); measure();
  }
  find('[data-ai-checks]')?.addEventListener('click',async()=>{try{checks(await api({action:'checks',text:form.elements.text.value,subject:form.elements.subject.value}));announce('Content checks updated without an AI request');}catch(error){announce(error.message);}});
  find('[data-ai-refine]')?.addEventListener('click',()=>{form.elements.text.value=find('[data-ai-text]').value;form.elements.operation.value='rewrite';form.elements.prompt.focus();announce('Reviewed text is now the source for your next request');});
  find('[data-ai-copy]')?.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(find('[data-ai-text]').value);announce('Reviewed text copied');}catch{find('[data-ai-text]').focus();find('[data-ai-text]').select();announce('Text selected. Use your keyboard to copy it');}});
  find('[data-ai-save]')?.addEventListener('submit',async event=>{
    event.preventDefault();const button=event.currentTarget.querySelector('button');button.disabled=true;
    try{const result=await api({action:'save_template',request_id:completed,text:find('[data-ai-text]').value,headline:find('[data-ai-headline]').value,...Object.fromEntries(new FormData(event.currentTarget))});location.assign(result.redirect);}catch(error){announce(error.message);button.disabled=false;}
  });
  find('[data-ai-apply]')?.addEventListener('click',()=>{if(!target){announce('Choose a text block before opening the assistant');return;}const event=new CustomEvent('audience-ai-apply',{detail:{target,text:find('[data-ai-text]').value},cancelable:true});document.dispatchEvent(event);if(event.defaultPrevented){root.closest('dialog')?.close();announce('Reviewed text applied');}else announce('The selected block changed. Close the assistant and select the text again');});
  document.addEventListener('audience-ai-open',event=>{
    if(busy){announce('Stop the current request before changing source text');return;}
    target=event.detail.target;previous='';completed='';output.hidden=true;find('[data-ai-check-result]').hidden=true;
    form.elements.text.value=event.detail.text;form.elements.operation.value='rewrite';form.elements.prompt.value='';announce('Review the source text and choose what to change');root.closest('dialog')?.showModal();
  });
  root.closest('dialog')?.addEventListener('close',()=>{if(busy)stop();});
  history();
  try{const pending=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(pending&&pending.mode===config.mode&&Date.now()-pending.started<maxWaitMs){active=pending.uuid;setBusy(true);poll(active,++generation,pending.started);}else clearPending();}catch{clearPending();}
})();
