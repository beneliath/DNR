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
  const storageKey = `moed-audience-ai-${config.key}`;
  let active = '', completed = '', previous = '', generation = 0, target = null, busy = false;
  const node = (tag, text, className) => { const element = document.createElement(tag); if (text !== undefined) element.textContent = text; if (className) element.className = className; return element; };
  const announce = text => { status.textContent = text; };
  const clearPending = () => { try { sessionStorage.removeItem(storageKey); } catch { /* Recovery is optional. */ } };
  const setBusy = value => {
    busy = value;
    find('[data-ai-generate]').disabled = value || !config.enabled;
    find('[data-ai-stop]').hidden = !value;
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
    if (!series.length || !series.some(row => row.accepted)) { box.append(node('p','No accepted email in this report scope')); return; }
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace,'svg'); svg.setAttribute('viewBox','0 0 640 160'); svg.setAttribute('role','img'); svg.setAttribute('aria-label',`Daily accepted email from ${evidence.window.start} to ${evidence.window.end} UTC`);
    const max = Math.max(...series.map(row => row.accepted),1); const width = 640 / series.length;
    series.forEach((row,index) => { const bar = document.createElementNS(namespace,'rect'); const height = row.accepted/max*140; bar.setAttribute('x',String(index*width)); bar.setAttribute('y',String(150-height)); bar.setAttribute('width',String(Math.max(.5,width-1))); bar.setAttribute('height',String(height)); const title = document.createElementNS(namespace,'title'); title.textContent = `${row.day} UTC: ${row.accepted} accepted messages`; bar.append(title); svg.append(bar); });
    const figure = node('figure',undefined,'ai-chart'); figure.append(svg,node('figcaption','Daily accepted email · UTC · application-calculated')); box.append(figure);
  }
  function render(reply, uuid) {
    if (reply.purpose !== 'audience-ai' || reply.reason) { output.hidden = true; announce(reply.purpose === 'audience-ai' ? reply.message : 'The AI service could not finish this suggestion. Your content is unchanged'); return; }
    if (reply.mode !== config.mode) { announce('This request belongs to the other assistant. Open it from the matching tab'); return; }
    completed = uuid; previous = uuid; output.hidden = false;
    announce('Suggestion ready for your review');
    const recommendations = find('[data-ai-recommendations]'); recommendations.replaceChildren();
    if (reply.recommendations.length) { recommendations.append(node('h3','Suggested next steps')); const list = node('ul'); reply.recommendations.forEach(text => list.append(node('li',text))); recommendations.append(list); }
    if (reply.mode === 'analytics') {
      const box = find('[data-ai-analysis]'); box.hidden = false; box.replaceChildren();
      box.append(node('h3','AI interpretation'),node('p',reply.summary));
      const evidence = reply.evidence;
      box.append(node('p',`Source report: ${evidence.window.start} to ${evidence.window.end} UTC · ${evidence.window.bots==='filtered'?'Bot filtered':'All observed activity'} · Comparison: ${evidence.window.compare}`,'ai-scope'));
      if(Object.keys(config.window).some(key=>JSON.stringify(config.window[key])!==JSON.stringify(evidence.window[key]))){previous='';announce('Saved analysis opened for its original report scope. Your next question will use the current report filters');}
      if (evidence.includes_samples) box.append(node('p','Local sample data included. These observations include fictional test activity','audience-notice'));
      chart(evidence,box);
      const cards = node('dl',undefined,'ai-facts');
      evidence.facts.forEach(fact => { const item = node('div'); item.id = `ai-evidence-${fact.id}`; const display = value => value === null ? '—' : Number(value).toLocaleString(undefined,{maximumFractionDigits:2})+fact.unit.replace('messages',''); item.append(node('dt',fact.label),node('dd',display(fact.value)),node('small',fact.previous === null ? 'No comparison baseline' : `Previous: ${display(fact.previous)}${fact.change === null ? '' : ` · ${fact.change>0?'↑':'↓'} ${Math.abs(fact.change)}%`}`)); cards.append(item); }); box.append(cards);
      reply.observations.forEach(observation => { const fact = evidence.facts.find(item => item.id === observation.evidence_id); const p = node('p',observation.text+' '); const link = node('a',`Source: ${fact.label}`); link.href = `#ai-evidence-${fact.id}`; p.append(link); box.append(p); });
      const detail = node('details'); detail.append(node('summary','Source calculations and limitations'));
      Object.entries(evidence.calculations).forEach(([key,text])=>detail.append(node('p',`${key}: ${text}`)));
      evidence.limitations.forEach(text=>detail.append(node('p',text))); box.append(detail);
    } else {
      find('[data-ai-headline]').value = reply.headline;
      find('[data-ai-text]').value = reply.text;
      const subjects = find('[data-ai-subjects]'); subjects.replaceChildren(node('h3','Subject ideas'));
      reply.subjects.forEach(text => { const row = node('div'); const use = node('button','Use this subject'); use.type = 'button'; use.addEventListener('click',()=>{form.elements.subject.value=text; announce('Subject selected in this workspace. The campaign is unchanged');}); const copy=node('button','Copy subject');copy.type='button';copy.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(text);announce('Subject copied');}catch{announce('Select the subject text to copy it');}}); row.append(node('p',text),use,copy);subjects.append(row); });
      checks(reply.checks);
    }
  }
  async function history() {
    const list = document.querySelector('[data-ai-history]'); if (!list) return;
    try {
      const data = await api(null,'?action=history'); const items = data.items.filter(item => item.manual_topic === `audience-ai:${config.mode}`); list.replaceChildren(); document.querySelector('[data-ai-history-empty]').hidden = items.length>0;
      items.forEach(item => { const li=node('li');const button=node('button',item.question);button.type='button';button.addEventListener('click',async()=>{if(busy){announce('Stop the current request before opening history');return;}try{const result=await api(null,`?request_id=${encodeURIComponent(item.client_request_id)}`);if(result.state==='complete')render(result.response,item.client_request_id);else announce('This request has not completed');}catch(error){announce(error.message);}});li.append(button,node('small',`${item.created_at} UTC · ${item.outcome}`));list.append(li); });
    } catch { /* History failure never prevents manual editing. */ }
  }
  async function poll(uuid, token, started) {
    while (token===generation && Date.now()-started<30000) {
      try {
        const result=await api(null,`?request_id=${encodeURIComponent(uuid)}`);
        if(token!==generation)return;
        if(result.state==='complete'){clearPending();active='';setBusy(false);render(result.response,uuid);history();return;}
        if(result.state==='interrupted')break;
        announce(result.stage==='generating'?'Preparing your suggestion':'Your request is queued');
      } catch { if(Date.now()-started>25000)break; }
      await new Promise(resolve=>setTimeout(resolve,900));
    }
    if(token===generation){clearPending();active='';setBusy(false);announce('The request did not finish in time. Your content is unchanged. You can try again');history();}
  }
  async function stop() {
    const uuid=active;generation++;active='';clearPending();setBusy(false);
    if(uuid)try{await api({action:'cancel',request_id:uuid});}catch{ /* Worker deadline also prevents late publication. */ }
    announce('Request stopped. Your content is unchanged');
  }
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;
    const values=Object.fromEntries(new FormData(form)); delete values.csrf_token;
    const uuid=crypto.randomUUID(),token=++generation;active=uuid;setBusy(true);output.hidden=true;announce('Saving your request');
    const request={...values,mode:config.mode,request_id:uuid,window:config.window,parent:previous};
    try {
      try{sessionStorage.setItem(storageKey,JSON.stringify({uuid,started:Date.now(),mode:config.mode}));}catch{ /* No source text is persisted in browser recovery. */ }
      const result=await api(request);if(token!==generation)return;
      if(result.state==='complete'){clearPending();active='';setBusy(false);render(result.response,uuid);history();}
      else poll(uuid,token,Date.now());
    }catch(error){if(token===generation){active='';setBusy(false);clearPending();announce(error.message);}}
  });
  find('[data-ai-stop]').addEventListener('click',stop);
  find('[data-ai-new]').addEventListener('click',async()=>{if(busy)await stop();previous='';completed='';output.hidden=true;find('[data-ai-check-result]').hidden=true;announce('Ready for a new request');});
  root.querySelectorAll('[data-ai-starter]').forEach(button=>button.addEventListener('click',()=>{form.elements.prompt.value=button.textContent;form.elements.prompt.focus();}));
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
  try{const pending=JSON.parse(sessionStorage.getItem(storageKey)||'null');if(pending&&pending.mode===config.mode&&Date.now()-pending.started<30000){active=pending.uuid;setBusy(true);poll(active,++generation,pending.started);}else clearPending();}catch{clearPending();}
})();
