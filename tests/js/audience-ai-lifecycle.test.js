const test = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname,'../../src/assets/js/audience-ai.js'),'utf8');
class Element {
  constructor(){this.listeners={};this.children=[];this.hidden=true;this.value='';this.textContent='';}
  addEventListener(type,callback){this.listeners[type]=callback;}
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=children;}
  setAttribute(){}
  closest(){return null;}
  focus(){}
}
function page(fetcher, mode='content'){
  const controls=new Map();const find=selector=>{if(!controls.has(selector))controls.set(selector,new Element());return controls.get(selector);};
  const form=find('[data-ai-form]');form.elements={text:new Element(),prompt:new Element(),subject:new Element(),operation:new Element()};form.elements.text.value='Private source';form.querySelector=()=>({value:'csrf'});
  const root={querySelector:find,querySelectorAll:()=>[],setAttribute(){},closest(){return null;}};
  let saved=null;const requests=[];
  const context={document:{getElementById:()=>({textContent:JSON.stringify({mode,key:'account-role-session',endpoint:'audience_ai_api.php',enabled:true,window:{start:'2026-01-01'}})}),querySelector:selector=>selector==='[data-ai-root]'?root:null,createElement:()=>new Element(),createElementNS:()=>new Element(),addEventListener(){}},
    FormData:class{constructor(){return [['text',form.elements.text.value],['prompt','Rewrite'],['operation','rewrite']][Symbol.iterator]();}},
    crypto:{randomUUID:()=> '01234567-1234-1234-1234-012345678901'},sessionStorage:{getItem:()=>saved,setItem:(_,value)=>{saved=value;},removeItem:()=>{saved=null;}},
    AbortController,setTimeout:()=>1,clearTimeout(){},console,
    fetch:async(url,options)=>{requests.push({url,options});return {ok:true,json:()=>fetcher(url,options)};}};
  runInNewContext(source,context);
  return {find,requests,storage:()=>saved,submit:()=>form.listeners.submit({preventDefault(){}})};
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
  p.find('[data-ai-text]').value='Human-reviewed text';p.find('[data-ai-refine]').listeners.click();
  assert.equal(p.find('[data-ai-form]').elements.text.value,'Human-reviewed text');
  assert.equal(p.find('[data-ai-form]').elements.operation.value,'rewrite');
});
test('wrong-purpose and failed replies never appear as usable results',async()=>{
  for(const response of [{...reply,purpose:'coach'},{...reply,reason:'unavailable',message:'Unavailable'}]){
    const p=page(()=>({state:'complete',response}));await p.submit();assert.equal(p.find('[data-ai-result]').hidden,true);
  }
});
