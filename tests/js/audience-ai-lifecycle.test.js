const test = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname,'../../src/assets/js/audience-pagination.mjs'),'utf8').replace(/^export /gm,'')+'\n'+readFileSync(require('node:path').join(__dirname,'../../src/assets/js/audience-ai.js'),'utf8').replace(/^import .*$/gm,'');
class Element {
  constructor(){this.listeners={};this.children=[];this.hidden=true;this.value='';this.textContent='';this.dataset={};this.style={setProperty(){}};this.scrollHeight=44;this.classList={toggle(){},add(){}};}
  addEventListener(type,callback){this.listeners[type]=callback;}
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=children;}
  setAttribute(name,value){(this.attributes??={})[name]=value;}
  querySelectorAll(){return this.children.flatMap(child=>[...(['button','select'].includes(child.tagName)?[child]:[]),...child.querySelectorAll()]);}
  closest(){return null;}
  focus(){}
  scrollIntoView(options){this.scrolled=options;}
  dispatchEvent(event){this.listeners[event.type]?.(event);}
}
function page(fetcher, mode='content', options={}){
  const controls=new Map();const find=selector=>{if(!controls.has(selector))controls.set(selector,new Element());return controls.get(selector);};
  const form=find('[data-ai-form]');form.elements={text:new Element(),prompt:new Element(),subject:new Element(),operation:new Element(),tone:new Element()};form.elements.text.value='Private source';form.elements.prompt.value='Rewrite';form.querySelector=()=>({value:'csrf'});
  const root={querySelector:find,querySelectorAll:selector=>selector==='[data-ai-starter]'?options.starters||[]:[],setAttribute(name,value){(this.attributes??={})[name]=value;},closest(){return null;},hasAttribute:()=>!!options.chat,style:{setProperty(){}},classList:{toggle(){}},getBoundingClientRect:()=>({top:160})};
  const dialog=find('[data-ai-history-confirm]');dialog.showModal=()=>{dialog.open=true;Promise.resolve(options.confirm?options.confirm():true).then(confirmed=>{dialog.returnValue=confirmed?'confirm':'cancel';dialog.open=false;dialog.listeners.close();});};
  let saved=options.pending?JSON.stringify(options.pending):null,sequence=0,unlockRequests=0;const requests=[];const storage=options.storage||new Map();
  const context={document:{getElementById:()=>({textContent:JSON.stringify({mode,key:'account-role-session',endpoint:'audience_ai_api.php',enabled:true,window:options.window||{start:'2026-01-01'}})}),querySelector:selector=>selector==='[data-ai-root]'?root:selector==='[data-ai-history-confirm]'&&options.omitConfirmation?null:options.chat||options.historyItems?find(selector):null,createElement:tag=>Object.assign(new Element(),{tagName:tag}),createTextNode:text=>Object.assign(new Element(),{textContent:text}),createElementNS:(_,tag)=>Object.assign(new Element(),{tagName:tag}),addEventListener(){}},
    FormData:class{constructor(){return [['text',form.elements.text.value],['prompt',form.elements.prompt.value],['operation',mode==='analytics'?'analyze':'rewrite']][Symbol.iterator]();}},
    crypto:{randomUUID:()=> `01234567-1234-1234-1234-${String(++sequence).padStart(12,'0')}`},sessionStorage:{getItem:()=>saved,setItem:(_,value)=>{saved=value;},removeItem:()=>{saved=null;}},
    localStorage:{getItem:key=>storage.get(key),setItem:(key,value)=>storage.set(key,value)},window:{addEventListener(){},DnrAdminUnlock:options.omitUnlock?undefined:{request:async()=>{unlockRequests++;return options.unlock?options.unlock():true;}}},Event:class{constructor(type){this.type=type;}},
    AbortController,setTimeout:()=>1,clearTimeout(){},navigator:{clipboard:options.clipboard},console,
    fetch:async(url,requestOptions)=>{requests.push({url,options:requestOptions});return {ok:true,json:()=>url.endsWith('?action=history')?{items:options.historyItems||[],can_remove_history:options.canRemoveHistory!==false}:fetcher(url,requestOptions)};}};
  runInNewContext(source,context);
  return {find,requests,unlockRequests:()=>unlockRequests,localStorage:storage,storage:()=>saved,submit:()=>form.listeners.submit({preventDefault(){}})};
}
const reply={purpose:'audience-ai',reason:'',mode:'content',headline:'Headline',text:'<b>Literal model text</b>',subjects:['Subject'],recommendations:['Review facts'],checks:{facts:[],findings:[],note:'Computed'}};
test('stop prevents a late response from appearing and recovery stores no source text',async()=>{
  let finish;const p=page((url,options)=>JSON.parse(options.body).action==='cancel'?{saved:true}:new Promise(resolve=>{finish=resolve;}));const submitting=p.submit();
  assert.equal(JSON.parse(p.storage()).mode,'content');assert.ok(!p.storage().includes('Private source'));
  await p.find('[data-ai-stop]').listeners.click();finish({state:'complete',response:reply});await submitting;
  assert.equal(p.find('[data-ai-result]').hidden,true);assert.equal(p.storage(),null);
  assert.equal(p.find('[data-ai-status]').textContent,'Request stopped. Your content is unchanged');
});
test('reviewed output stays editable literal text and can become the next source',async()=>{
  const p=page(()=>({state:'complete',response:reply}));await p.submit();
  assert.equal(p.find('[data-ai-text]').value,reply.text);assert.equal(p.find('[data-ai-result]').hidden,false);
  assert.equal(p.find('[data-ai-result]').scrolled.block,'start');
  assert.equal(p.find('[data-ai-generate]').hidden,true);assert.equal(p.find('[data-ai-checks]').hidden,true);
  p.find('[data-ai-text]').value='Human-reviewed text';p.find('[data-ai-refine]').listeners.click();
  assert.equal(p.find('[data-ai-form]').elements.text.value,'Human-reviewed text');
  assert.equal(p.find('[data-ai-form]').elements.operation.value,'rewrite');
  assert.equal(p.find('[data-ai-generate]').hidden,false);assert.equal(p.find('[data-ai-checks]').hidden,false);
});
test('completed writing controls return for an edited or new request',async()=>{
  const p=page(()=>({state:'complete',response:reply}));await p.submit();
  p.find('[data-ai-form]').listeners.input();
  assert.equal(p.find('[data-ai-generate]').hidden,false);
  await p.submit();assert.equal(p.find('[data-ai-generate]').hidden,true);
  await p.find('[data-ai-new]').listeners.click();
  assert.equal(p.find('[data-ai-generate]').hidden,false);assert.equal(p.find('[data-ai-checks]').hidden,false);
});
test('writing reveals the shared working dots immediately and brings content checks into view',async()=>{
  let finish;const p=page((_url,options)=>JSON.parse(options.body).action==='checks'?reply.checks:new Promise(resolve=>{finish=resolve;}));
  const submitting=p.submit();
  assert.equal(p.find('[data-ai-thinking]').hidden,false);
  assert.equal(p.find('[data-ai-thinking]').scrolled.block,'nearest');
  assert.equal(p.find('[data-ai-generate]').disabled,true);
  await tick();finish({state:'complete',response:reply});await submitting;
  assert.equal(p.find('[data-ai-thinking]').hidden,true);
  await p.find('[data-ai-checks]').listeners.click();
  assert.equal(p.find('[data-ai-check-result]').hidden,false);
  assert.equal(p.find('[data-ai-check-result]').scrolled.block,'start');
});
test('wrong-purpose and failed replies never appear as usable results',async()=>{
  for(const response of [{...reply,purpose:'coach'},{...reply,reason:'unavailable',message:'Unavailable'}]){
    const p=page(()=>({state:'complete',response}));await p.submit();assert.equal(p.find('[data-ai-result]').hidden,true);
  }
});

const reportWindow={start:'2026-01-01',end:'2026-01-31',compare:'previous',bots:'filtered',channel:'all',messages_mode:'all',messages:[],segment:0,dataset:'email',source:''};
const analyticsReply={purpose:'audience-ai',reason:'',mode:'analytics',summary:'Observed results',recommendations:[],observations:[{evidence_id:'accepted',text:'Sending volume changed.'}],evidence:{window:reportWindow,series:[],facts:[{id:'accepted',label:'Accepted messages',value:7,previous:4,change:75,unit:'count'}],includes_samples:false,calculations:{},limitations:[]}};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const textOf=element=>[element.textContent,...element.children.map(textOf)].join(' ');
const removeAction=entry=>entry.children.find(child=>child.className==='ai-history-remove');

test('informational analytics answers do not pretend to be a sourced report',async()=>{
  const p=page(()=>({state:'complete',response:{...analyticsReply,interpretation:'information',summary:'Hello! Ask about your contacts.'}}),'analytics',{chat:true,window:reportWindow});
  await p.submit();
  const transcript=textOf(p.find('[data-ai-chat-transcript]'));
  assert.match(transcript,/Hello! Ask about your contacts/);
  assert.ok(!transcript.includes('Source report')&&!transcript.includes('Source calculations'));
});

test('analytics recovery keeps waiting within the semantic validation deadline',async()=>{
  const p=page(()=>({state:'complete',question:'A longer analysis',response:analyticsReply}),'analytics',{
    chat:true,window:reportWindow,pending:{uuid:'slow-review',mode:'analytics',started:Date.now()-60000}
  });
  await tick();
  assert.ok(p.requests.some(request=>request.url.includes('request_id=slow-review')));
  assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Observed results'));
  assert.equal(p.storage(),null);
});

test('chat shows working animation immediately, then preserves follow-up turns and unique evidence links',async()=>{
  let finish;const p=page(()=>new Promise(resolve=>{finish=resolve;}),'analytics',{chat:true,window:reportWindow});
  const first=p.submit();assert.equal(p.find('[data-ai-thinking]').hidden,false);assert.equal(p.find('[data-ai-generate]').disabled,true);await tick();
  finish({state:'complete',response:analyticsReply});await first;
  assert.equal(p.find('[data-ai-thinking]').hidden,true);assert.equal(p.find('[data-ai-generate]').disabled,false);
  const transcript=p.find('[data-ai-chat-transcript]');assert.equal(transcript.children.length,2);
  assert.ok(!textOf(transcript).includes('7count'));
  p.find('[data-ai-form]').elements.prompt.value='What changed?';const second=p.submit();await tick();
  const posts=p.requests.filter(request=>request.options.method==='POST');
  assert.equal(JSON.parse(posts[1].options.body).parent,JSON.parse(posts[0].options.body).request_id);
  finish({state:'complete',response:analyticsReply});await second;
  assert.equal(transcript.children.length,4);
  const firstCard=transcript.children[1].children.find(child=>child.children.some(item=>item.id));
  const secondCard=transcript.children[3].children.find(child=>child.children.some(item=>item.id));
  assert.notEqual(firstCard.children[0].id,secondCard.children[0].id);
});

test('a prior prompt can be copied without changing the current draft',async()=>{
  const copied=[];
  const p=page(()=>({state:'complete',response:analyticsReply}),'analytics',{chat:true,window:reportWindow,clipboard:{writeText:async text=>copied.push(text)}});
  p.find('[data-ai-form]').elements.prompt.value='How many connections are in Florida?';
  await p.submit();
  p.find('[data-ai-form]').elements.prompt.value='What about Texas?';
  await p.submit();
  p.find('[data-ai-form]').elements.prompt.value='Unsaved draft';
  const firstQuestion=p.find('[data-ai-chat-transcript]').children[0];
  const copy=firstQuestion.children.find(child=>child.tagName==='button');
  assert.equal(copy.children[0].tagName,'svg');
  assert.match(copy.attributes['aria-label'],/^Copy prompt:/);
  await copy.listeners.click();
  assert.deepEqual(copied,['How many connections are in Florida?']);
  assert.equal(copy.attributes['aria-label'],'Prompt copied');
  assert.equal(p.find('[data-ai-form]').elements.prompt.value,'Unsaved draft');
});

test('recent history keeps its prompt and timestamp without a copy action',async()=>{
  const saved={client_request_id:'saved-prompt',question:'How many connections are in Germany?',manual_topic:'audience-ai:analytics',outcome:'complete',created_at:'2026-10-30 20:03:29.000000'};
  const p=page(()=>({state:'complete',response:analyticsReply}),'analytics',{chat:true,window:reportWindow,historyItems:[saved]});
  await tick();
  const entry=p.find('[data-ai-history]').children[0];
  assert.equal(entry.children[0].className,'ai-history-open');
  assert.equal(entry.children.some(child=>child.className?.includes('ai-history-copy')),false);
  assert.match(textOf(entry.children[0]),/How many connections are in Germany\?.*2026-10-30 20:03:29 UTC/);
});

test('a saved count source opens its matching connections instead of linking back to the count card',async()=>{
  const response={...analyticsReply,summary:'Two contacts match.',observations:[{evidence_id:'query_0_result',text:'Two contacts match.'}],
    evidence:{...analyticsReply.evidence,facts:[{id:'query_0_result',label:'Contacts with an address in California',value:2,previous:null,change:null,unit:'count'}],
      queries:[{tool:'count',field:'',search:'',filters:[{field:'custom:9:us_state',operator:'eq',value:'CA'}],limit:5}]}};
  const p=page(url=>url.includes('action=contacts')?{table:{id:'query_0_result',title:'Matching audience records',columns:[{key:'first_name',label:'First name'},{key:'email',label:'Email address'}],rows:[{first_name:'John',email:'john@example.test'},{first_name:'Jane',email:'jane@example.test'}],total:2,next_offset:null}}:{state:'complete',response},'analytics',{chat:true,window:reportWindow});
  await p.submit();
  const answer=p.find('[data-ai-chat-transcript]').children[1];
  const citation=answer.children.find(child=>child.tagName==='p'&&child.children.some(item=>item.tagName==='a'));
  const link=citation.children.find(child=>child.tagName==='a');
  assert.equal(link.textContent,'View matching connections');
  link.listeners.click({preventDefault(){}});await tick();
  const panel=answer.children.find(child=>child.tagName==='section'&&child.className==='analytics-ai-source-contacts');
  assert.equal(panel.hidden,false);
  assert.match(textOf(panel),/Showing 2 of 2.*John.*john@example\.test.*Jane/);
  assert.ok(p.requests.some(request=>request.url.includes('action=contacts')&&request.url.includes('table_id=query_0_result')));
});

test('stopping chat immediately shows a stopped card with Retry and ignores late answers',async()=>{
  let finish,cancel;const p=page((url,options)=>new Promise(resolve=>{if(JSON.parse(options.body).action==='cancel')cancel=resolve;else finish=resolve;}),'analytics',{chat:true,window:reportWindow});
  const prompt='Summarize my campaigns';p.find('[data-ai-form]').elements.prompt.value=prompt;
  const submitting=p.submit();const stopping=p.find('[data-ai-stop]').listeners.click();
  const transcript=p.find('[data-ai-chat-transcript]');
  assert.equal(p.find('[data-ai-thinking]').hidden,true);assert.equal(p.find('[data-ai-generate]').disabled,false);
  assert.equal(p.storage(),null);assert.equal(transcript.children.length,2);
  assert.match(textOf(transcript),/Request stopped.*This request was stopped.*Retry/);
  assert.ok(!textOf(transcript).includes('could not be completed'));
  await tick();cancel({saved:true});await stopping;
  finish({state:'complete',response:analyticsReply});await submitting;
  assert.equal(transcript.children.length,2);assert.ok(!textOf(transcript).includes('Observed results'));
  const form=p.find('[data-ai-form]');let resubmitted=false;form.requestSubmit=()=>{resubmitted=true;};
  transcript.children[1].children.find(child=>child.tagName==='button').listeners.click();
  assert.equal(resubmitted,true);assert.equal(form.elements.prompt.value,prompt);
  assert.ok(p.requests.filter(request=>request.url.endsWith('?action=history')).length>=2);
});

test('finishing cancellation after New chat does not restore the stopped conversation',async()=>{
  let finish,cancel;const p=page((url,options)=>new Promise(resolve=>{if(JSON.parse(options.body).action==='cancel')cancel=resolve;else finish=resolve;}),'analytics',{chat:true,window:reportWindow});
  const submitting=p.submit();const stopping=p.find('[data-ai-stop]').listeners.click();
  await p.find('[data-ai-new]').listeners.click();
  cancel({saved:true});await stopping;finish({state:'complete',response:analyticsReply});await submitting;
  assert.equal(p.find('[data-ai-chat-transcript]').children.length,0);
  assert.equal(p.find('[data-ai-welcome]').hidden,false);
  assert.equal(p.find('[data-ai-status]').textContent,'Ready for a new request');
});

test('an audience answer retains follow-up context when its data scope differs from the report pane',async()=>{
  const result={...analyticsReply,evidence:{...analyticsReply.evidence,window:{...reportWindow,dataset:'audience'}}};
  const p=page(()=>({state:'complete',response:result}),'analytics',{chat:true,window:reportWindow});
  p.find('[data-ai-form]').elements.prompt.value='How many contacts match?';await p.submit();
  p.find('[data-ai-form]').elements.prompt.value='Please list those here';await p.submit();
  const requests=p.requests.filter(request=>request.options?.method==='POST').map(request=>JSON.parse(request.options.body));
  assert.equal(requests[1].parent,requests[0].request_id);
  assert.equal(requests[1].window.dataset,'audience');
});

test('reset list persists its cutoff, starts a fresh chat, and admits later requests',async()=>{
  const options={chat:true,window:reportWindow,historyItems:[{client_request_id:'old',question:'Old request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00.000001',outcome:'complete'}]};
  const p=page(()=>({state:'complete',response:analyticsReply}),'analytics',options);await tick();await p.submit();await tick();
  await p.find('[data-ai-history-reset]').listeners.click();
  assert.equal(p.find('[data-ai-history]').children.length,0);assert.equal(p.find('[data-ai-chat-transcript]').children.length,0);
  assert.equal(p.find('[data-ai-welcome]').hidden,false);assert.equal(p.find('[data-ai-chat-suggestions]').hidden,false);
  assert.equal([...p.localStorage.values()][0],'2026-01-01 12:00:00.000001');
  options.historyItems.push({...options.historyItems[0],client_request_id:'new',question:'New request',created_at:'2026-01-01 12:00:01.000001'});
  p.find('[data-ai-form]').elements.prompt.value='Another question';await p.submit();await tick();
  assert.equal(p.find('[data-ai-history]').children.length,1);
  assert.equal(textOf(p.find('[data-ai-history]')).includes('Old request'),false);
  const requests=p.requests.filter(request=>request.options?.method==='POST').map(request=>JSON.parse(request.options.body));
  assert.equal(requests.at(-1).parent,'');
});

test('new chat invalidates a pending history response',async()=>{
  let finish;const options={chat:true,window:reportWindow,historyItems:[{client_request_id:'old',question:'Old request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'complete'}]};
  const p=page(()=>new Promise(resolve=>{finish=resolve;}),'analytics',options);await tick();
  const opening=p.find('[data-ai-history]').children[0].children[0].listeners.click();
  await p.find('[data-ai-new]').listeners.click();finish({state:'complete',response:analyticsReply});await opening;
  assert.equal(p.find('[data-ai-chat-transcript]').children.length,0);assert.equal(p.find('[data-ai-welcome]').hidden,false);
});

test('reset list cancels pending work and prevents a late answer from filling the new chat',async()=>{
  let finish;const options={chat:true,window:reportWindow,historyItems:[{client_request_id:'old',question:'Old request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'complete'}]};
  const p=page((url,request)=>JSON.parse(request.body).action==='cancel'?{saved:true}:new Promise(resolve=>{finish=resolve;}),'analytics',options);
  await tick();const submitting=p.submit();
  await p.find('[data-ai-history-reset]').listeners.click();
  finish({state:'complete',response:analyticsReply});await submitting;
  assert.equal(p.find('[data-ai-chat-transcript]').children.length,0);
  assert.equal(p.find('[data-ai-welcome]').hidden,false);
  assert.equal(p.find('[data-ai-thinking]').hidden,true);
  assert.equal(p.storage(),null);
});

test('circle-X permanently deletes only the confirmed response without admin unlock',async()=>{
  const options={chat:true,window:reportWindow,historyItems:[
    {client_request_id:'first',question:'First request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'complete'},
    {client_request_id:'second',question:'Second request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:01:00',outcome:'complete'}
  ],omitUnlock:true};
  const p=page(()=>({deleted:true}),'analytics',options);await tick();
  const remove=removeAction(p.find('[data-ai-history]').children[0]);
  assert.equal(remove.className,'ai-history-remove');assert.equal(remove.children[0].tagName,'svg');
  assert.equal(remove.attributes['data-admin-unlock-required'],undefined);
  await remove.listeners.click();
  assert.equal(p.find('[data-ai-history]').children.length,1);assert.ok(textOf(p.find('[data-ai-history]')).includes('Second request'));
  const deletion=JSON.parse(p.requests.find(request=>request.options?.method==='POST').options.body);
  assert.deepEqual(deletion,{action:'delete',request_id:'first',confirmed:true});
  assert.equal(p.unlockRequests(),0);assert.equal(p.localStorage.size,0);
  assert.match(p.find('[data-ai-history-confirm-message]').textContent,/permanently delete.*cannot be undone/);
});

test('recent history highlights only the displayed chat and clears on New chat',async()=>{
  const options={chat:true,window:reportWindow,historyItems:[
    {client_request_id:'first',question:'First request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'complete'},
    {client_request_id:'second',question:'Second request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:01:00',outcome:'complete'}
  ]};
  const p=page(()=>({state:'complete',response:analyticsReply}),'analytics',options);await tick();
  await p.find('[data-ai-history]').children[0].children[0].listeners.click();
  assert.ok(p.find('[data-ai-history]').children[0].children[0].className.includes('is-current'));
  await p.find('[data-ai-history]').children[1].children[0].listeners.click();
  assert.equal(p.find('[data-ai-history]').children[0].children[0].className.includes('is-current'),false);
  assert.ok(p.find('[data-ai-history]').children[1].children[0].className.includes('is-current'));
  await p.find('[data-ai-new]').listeners.click();
  assert.equal(p.find('[data-ai-history]').children.some(item=>item.children[0].className.includes('is-current')),false);
});

test('a pending first history item opens on its first click and completes in place',async()=>{
  let finish,reads=0;
  const created=new Date().toISOString().replace('T',' ').replace('Z','');
  const options={chat:true,window:reportWindow,historyItems:[{client_request_id:'pending',question:'Working request',manual_topic:'audience-ai:analytics',created_at:created,outcome:'pending'}]};
  const p=page(()=>++reads===1?{state:'pending'}:new Promise(resolve=>{finish=resolve;}),'analytics',options);await tick();
  const opening=p.find('[data-ai-history]').children[0].children[0].listeners.click();await tick();
  assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Working request'));
  assert.ok(p.find('[data-ai-history]').children[0].children[0].className.includes('is-current'));
  assert.equal(p.find('[data-ai-thinking]').hidden,false);
  finish({state:'complete',question:'Working request',response:analyticsReply});await opening;
  assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Observed results'));assert.equal(p.find('[data-ai-thinking]').hidden,true);
});

test('reload recovery restores the question and permits opening another history entry',async()=>{
  let finish;
  const options={chat:true,window:reportWindow,pending:{uuid:'recovering',mode:'analytics',started:Date.now()},historyItems:[
    {client_request_id:'recovering',question:'Restored request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'pending'},
    {client_request_id:'saved',question:'Saved request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:01:00',outcome:'complete'}
  ]};
  const p=page(url=>url.endsWith('recovering')?new Promise(resolve=>{finish=resolve;}):{state:'complete',response:analyticsReply},'analytics',options);await tick();
  assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Restored request'));
  await p.find('[data-ai-history]').children[0].children[0].listeners.click();
  assert.ok(p.find('[data-ai-history]').children[0].children[0].className.includes('is-current'));
  await p.find('[data-ai-history]').children[1].children[0].listeners.click();
  assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Saved request'));
  assert.equal(textOf(p.find('[data-ai-chat-transcript]')).includes('Restored request'),false);
  finish({state:'complete',response:{...analyticsReply,summary:'Late recovered answer'}});await tick();
  assert.equal(textOf(p.find('[data-ai-chat-transcript]')).includes('Late recovered answer'),false);
});

test('an audience starter updates the request dataset and clears email-only filters',async()=>{
  const starter=new Element();starter.textContent='How has my audience changed?';starter.dataset.aiWindow=JSON.stringify({dataset:'audience',start:'2026-01-01',end:'2026-03-31'});
  const p=page(()=>({state:'complete',response:analyticsReply}),'analytics',{chat:true,window:{...reportWindow,bots:'all',messages_mode:'selected',messages:[7],segment:4},starters:[starter]});
  starter.listeners.click();await p.submit();
  const request=JSON.parse(p.requests.find(item=>item.options.method==='POST').options.body);
  assert.equal(request.window.dataset,'audience');assert.deepEqual(request.window.messages,[]);assert.equal(request.window.segment,0);assert.equal(request.window.bots,'filtered');
});

test('submission clears the prompt immediately while retaining the question and a visible failure with Retry',async()=>{
  let finish;const p=page(()=>new Promise(resolve=>{finish=resolve;}),'analytics',{chat:true,window:reportWindow});
  p.find('[data-ai-form]').elements.prompt.value='Find John Smith';const pending=p.submit();
  assert.equal(p.find('[data-ai-form]').elements.prompt.value,'');assert.ok(textOf(p.find('[data-ai-chat-transcript]')).includes('Find John Smith'));
  await tick();finish({state:'complete',response:{purpose:'audience-ai',mode:'analytics',reason:'unavailable',message:'The AI service did not finish in time.'}});await pending;
  const text=textOf(p.find('[data-ai-chat-transcript]'));assert.ok(text.includes('The AI service did not finish in time.'));assert.ok(text.includes('Retry'));
});

test('cancelled analytics history reopens as stopped and Retry retains its prompt',async()=>{
  const prompt='Summarize the performance of my campaigns in last 30 days';
  const stopped={purpose:'audience-ai',mode:'analytics',reason:'cancelled',message:'This request was stopped.'};
  const p=page(()=>({state:'complete',question:prompt,response:stopped}),'analytics',{chat:true,window:reportWindow,
    historyItems:[{client_request_id:'stopped',question:prompt,manual_topic:'audience-ai:analytics',created_at:'2026-10-23 20:45:11',outcome:'cancelled'}]});
  await tick();await p.find('[data-ai-history]').children[0].children[0].listeners.click();
  const transcript=p.find('[data-ai-chat-transcript]'),text=textOf(transcript);
  assert.ok(text.includes(prompt));assert.ok(text.includes('Request stopped'));assert.ok(text.includes(stopped.message));
  assert.ok(!text.includes('did not return a usable answer'));assert.ok(!text.includes('could not be completed'));
  assert.equal(p.find('[data-ai-result]').hidden,true);
  const form=p.find('[data-ai-form]');let resubmitted=false;form.requestSubmit=()=>{resubmitted=true;};
  transcript.children[1].children.find(child=>child.tagName==='button').listeners.click();
  assert.equal(resubmitted,true);assert.equal(form.elements.prompt.value,prompt);
});

test('reload recovery renders terminal cancellation and timeout without leaving the page busy',async()=>{
  for(const [reason,message] of [['cancelled','This request was stopped.'],['timeout','The AI service did not finish in time. Please try again.']]){
    const p=page(()=>({state:'complete',question:'Restored request',response:{purpose:'audience-ai',mode:'analytics',reason,message}}),'analytics',{
      chat:true,window:reportWindow,pending:{uuid:'restored-terminal',mode:'analytics',started:Date.now()}
    });await tick();
    const text=textOf(p.find('[data-ai-chat-transcript]'));
    assert.ok(text.includes(message));assert.ok(text.includes('Retry'));assert.ok(!text.includes('did not return a usable answer'));
    assert.equal(p.find('[data-ai-thinking]').hidden,true);assert.equal(p.find('[data-ai-generate]').disabled,false);assert.equal(p.storage(),null);
    if(reason==='cancelled')assert.ok(text.includes('Request stopped'));
  }
});

test('writing cancellation stays a terminal status without exposing output',async()=>{
  const p=page(()=>({state:'complete',response:{purpose:'audience-ai',mode:'content',reason:'cancelled',message:'This request was stopped.'}}));
  await p.submit();assert.equal(p.find('[data-ai-status]').textContent,'This request was stopped.');
  assert.equal(p.find('[data-ai-result]').hidden,true);assert.equal(p.find('[data-ai-form]').elements.text.value,'Private source');
});

test('saved replies display cited cards, and query results display returned contact data',async()=>{
  const legacy={...analyticsReply,evidence:{...analyticsReply.evidence,facts:[...analyticsReply.evidence.facts,{id:'unrelated',label:'Audience growth',value:123,previous:null,change:null,unit:'count'}]}};
  const p=page(()=>({state:'complete',response:legacy}),'analytics',{chat:true,window:reportWindow});await p.submit();
  assert.equal(textOf(p.find('[data-ai-chat-transcript]')).includes('Audience growth'),false);
  const queryReply={...analyticsReply,observations:[{evidence_id:'query_0_contacts',text:'John Smith has the saved address john@example.test.'}],evidence:{...analyticsReply.evidence,queries:[{tool:'contacts'}],facts:[],tables:[{id:'query_0_contacts',title:'Matching audience records',total:8,shown:1,truncated:true,columns:[{key:'name',label:'Name'},{key:'email',label:'Email address'}],rows:[{name:'John Smith',email:'john@example.test'}]}]}};
  const q=page(()=>({state:'complete',response:queryReply}),'analytics',{chat:true,window:reportWindow});await q.submit();
  assert.ok(textOf(q.find('[data-ai-chat-transcript]')).includes('john@example.test'));
  assert.ok(textOf(q.find('[data-ai-chat-transcript]')).includes('Source: Matching audience records'));
  assert.ok(textOf(q.find('[data-ai-chat-transcript]')).includes('Showing 1 of 8'));
  assert.equal(textOf(q.find('[data-ai-chat-transcript]')).includes('could not be displayed'),false);
});

test('contact pagination replaces pages without submitting another AI request',async()=>{
  const rows=Array.from({length:30},(_,index)=>({name:`Contact ${index+1}`,email:`person${index+1}@example.test`}));
  const table={id:'query_0_contacts',title:'Matching audience records',total:30,shown:10,truncated:true,next_offset:10,columns:[{key:'name',label:'Name'},{key:'email',label:'Email'}],rows:rows.slice(0,10)};
  const result={...analyticsReply,observations:[],evidence:{...analyticsReply.evidence,facts:[],tables:[table]}};
  const p=page(url=>url.includes('action=contacts')?{table:{...table,offset:10,rows:rows.slice(10,20),shown:10,truncated:true,next_offset:20}}:{state:'complete',response:result},'analytics',{chat:true,window:reportWindow});
  await p.submit();
  const answer=p.find('[data-ai-chat-transcript]').children[1];
  const pager=answer.children.find(item=>item.dataset.mmPagination==='');
  assert.ok(pager);assert.ok(textOf(answer).includes('Showing 10 of 30'));
  p.find('[data-ai-form]').elements.prompt.value='An unsent follow-up';
  await pager.children[2].children[2].listeners.click();
  await new Promise(resolve=>setImmediate(resolve));
  assert.ok(textOf(answer).includes('person20@example.test'));assert.ok(!textOf(answer).includes('person1@example.test'));
  assert.ok(textOf(pager).includes('2 / 3'));assert.equal(p.find('[data-ai-form]').elements.prompt.value,'An unsent follow-up');
  assert.equal(p.requests.filter(request=>request.options?.method==='POST').length,1);
  assert.ok(p.requests.some(request=>request.url.includes('table_id=query_0_contacts&offset=10&per_page=10')));
});

test('clarification is displayed as a question without unrelated metric cards',async()=>{
  const result={...analyticsReply,interpretation:'clarification',summary:'Which audience field do you mean?',observations:[],recommendations:[],evidence:{...analyticsReply.evidence,facts:[],tables:[],series:[]}};
  const p=page(()=>({state:'complete',response:result}),'analytics',{chat:true,window:reportWindow});await p.submit();
  const text=textOf(p.find('[data-ai-chat-transcript]'));
  assert.ok(text.includes('A quick clarification'));assert.ok(text.includes('Which audience field do you mean?'));
  assert.equal(text.includes('The request could not be completed'),false);
});


test('calculated comparisons display and link every cited source without unrelated cards',async()=>{
  const result={...analyticsReply,interpretation:'calculated',summary:'',observations:[{evidence_id:'accepted',evidence_ids:['accepted','previousCampaign'],text:'A is higher than B by 3.'}],evidence:{...analyticsReply.evidence,facts:[...analyticsReply.evidence.facts,{id:'previousCampaign',label:'Second campaign',value:4,previous:null,change:null,unit:'count'},{id:'unrelated',label:'Unrelated metric',value:100,previous:null,change:null,unit:'count'}]}};
  const p=page(()=>({state:'complete',response:result}),'analytics',{chat:true,window:reportWindow});await p.submit();
  const transcript=p.find('[data-ai-chat-transcript]');const text=textOf(transcript);
  assert.ok(text.includes('Report results'));assert.ok(text.includes('A is higher than B by 3.'));
  assert.ok(text.includes('Source: Accepted messages'));assert.ok(text.includes('Source: Second campaign'));
  assert.equal(text.includes('Unrelated metric'),false);
  const walk=el=>[el,...el.children.flatMap(walk)];const elements=walk(transcript);
  const links=elements.filter(el=>el.href?.startsWith('#ai-evidence-'));
  assert.equal(links.length,2);for(const link of links)assert.ok(elements.some(el=>el.id===link.href.slice(1)));
});

test('an equal comparison baseline is labeled unchanged without a downward arrow',async()=>{
  const result={...analyticsReply,evidence:{...analyticsReply.evidence,facts:[{...analyticsReply.evidence.facts[0],value:7,previous:7,change:0}]}};
  const p=page(()=>({state:'complete',response:result}),'analytics',{chat:true,window:reportWindow});await p.submit();
  const text=textOf(p.find('[data-ai-chat-transcript]'));
  assert.ok(text.includes('Unchanged'));assert.equal(text.includes('↓ 0%'),false);
});

const protectedHistory=[
  {client_request_id:'protected-first',question:'First protected request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:00:00',outcome:'complete'},
  {client_request_id:'protected-second',question:'Second protected request',manual_topic:'audience-ai:analytics',created_at:'2026-01-01 12:01:00',outcome:'complete'}
];
test('history removal controls are unavailable without server-granted editing permission',async()=>{
  const p=page(()=>({}),'analytics',{chat:true,historyItems:protectedHistory,canRemoveHistory:false});await tick();
  assert.equal(p.find('[data-ai-history-reset]').hidden,true);
  assert.ok(p.find('[data-ai-history]').children.every(row=>!row.children.some(child=>child.className==='ai-history-remove')));
  await p.find('[data-ai-history-reset]').listeners.click();
  assert.equal(p.unlockRequests(),0);assert.equal(p.localStorage.size,0);assert.equal(p.find('[data-ai-history]').children.length,2);
});
test('cancelling or missing confirmation preserves responses without sending deletion',async()=>{
  for(const permission of [{confirm:()=>false},{omitConfirmation:true}]){
    const p=page(()=>({}),'analytics',{chat:true,historyItems:protectedHistory,...permission});await tick();
    await removeAction(p.find('[data-ai-history]').children[0]).listeners.click();
    await p.find('[data-ai-history-reset]').listeners.click();
    assert.equal(p.find('[data-ai-history]').children.length,2);assert.equal(p.localStorage.size,0);
    assert.equal(p.requests.some(request=>request.options?.method==='POST'),false);
    assert.equal(p.find('[data-ai-history-reset]').disabled,false);
  }
});
test('history deletion waits for confirmation and ignores duplicate actions',async()=>{
  let approve;
  const p=page(()=>({deleted:true}),'analytics',{chat:true,historyItems:protectedHistory,confirm:()=>new Promise(resolve=>{approve=resolve;})});await tick();
  const pending=removeAction(p.find('[data-ai-history]').children[0]).listeners.click();
  await p.find('[data-ai-history-reset]').listeners.click();
  assert.equal(p.find('[data-ai-history]').children.length,2);assert.equal(p.requests.some(request=>request.options?.method==='POST'),false);
  approve(true);await pending;
  assert.equal(p.find('[data-ai-history]').children.length,1);assert.equal(p.requests.filter(request=>request.options?.method==='POST').length,1);
});
test('failed deletion preserves the response and reports the server error',async()=>{
  const p=page(()=>({error:'Response is unavailable.'}),'analytics',{chat:true,historyItems:protectedHistory});await tick();
  await removeAction(p.find('[data-ai-history]').children[0]).listeners.click();
  assert.equal(p.find('[data-ai-history]').children.length,2);
  assert.match(p.find('[data-ai-status]').textContent,/Response is unavailable/);
});
test('deleting the open writing response clears its source and prevents a late response restoring it',async()=>{
  let finish;
  const historyItems=[{...protectedHistory[0],manual_topic:'audience-ai:content'}];
  const p=page((url,request)=>request?.method==='POST'?{deleted:true}:new Promise(resolve=>{finish=resolve;}),'content',{historyItems});await tick();
  const opening=p.find('[data-ai-history]').children[0].children[0].listeners.click();
  await p.find('[data-ai-history]').children[0].children[1].listeners.click();
  finish({state:'complete',response:reply,input:{text:'Deleted original source'}});await opening;
  assert.equal(p.find('[data-ai-history]').children.length,0);assert.equal(p.find('[data-ai-result]').hidden,true);
  assert.equal(p.find('[data-ai-form]').elements.text.value,'');assert.equal(p.unlockRequests(),0);
});

test('opening writing history restores its saved source and writing controls',async()=>{
  const input={text:'Original <literal> source\nSecond paragraph *|FNAME|*',subject:'Saved subject',prompt:'Warm this up',operation:'tone',tone:'warm'};
  const historyItems=[{client_request_id:'saved-writing',question:'Warm this up',manual_topic:'audience-ai:content',created_at:'2026-10-22 12:00:00',outcome:'complete'}];
  const p=page(()=>({state:'complete',response:reply,input}),'content',{historyItems});await tick();
  await p.find('[data-ai-history]').children[0].children[0].listeners.click();
  for(const [name,value]of Object.entries(input))assert.equal(p.find('[data-ai-form]').elements[name].value,value);
  assert.equal(p.find('[data-ai-text]').value,reply.text);
  assert.ok(!JSON.stringify([...p.localStorage]).includes(input.text));
});

test('history without saved writing input clears source from the previously open request',async()=>{
  const historyItems=[{client_request_id:'old-writing',question:'Old instruction',manual_topic:'audience-ai:content',created_at:'2026-10-22 12:00:00',outcome:'complete'}];
  const p=page(()=>({state:'complete',response:reply,input:null}),'content',{historyItems});await tick();
  await p.find('[data-ai-history]').children[0].children[0].listeners.click();
  assert.equal(p.find('[data-ai-form]').elements.text.value,'');
  assert.equal(p.find('[data-ai-form]').elements.prompt.value,'Old instruction');
  assert.match(p.find('[data-ai-status]').textContent,/Original source text is unavailable/);
});

test('a late history response cannot replace the source of the newly selected request',async()=>{
  let finishFirst;
  const historyItems=['first','second'].map(id=>({client_request_id:id,question:id,manual_topic:'audience-ai:content',created_at:'2026-10-22 12:00:00',outcome:'complete'}));
  const p=page(url=>url.includes('request_id=first')?new Promise(resolve=>{finishFirst=resolve;}):{state:'complete',response:reply,input:{text:'Second source'}},'content',{historyItems});await tick();
  const list=p.find('[data-ai-history]');const first=list.children[0].children[0].listeners.click();
  await list.children[1].children[0].listeners.click();
  finishFirst({state:'complete',response:reply,input:{text:'First source'}});await first;
  assert.equal(p.find('[data-ai-form]').elements.text.value,'Second source');
});
