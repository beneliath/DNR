import { normalizeEmailDesignMaps } from './audience-design-model.mjs';
(() => {
  'use strict';
  const $ = (s, root = document) => root.querySelector(s);
  const $$ = (s, root = document) => [...root.querySelectorAll(s)];
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (['form', 'input', 'textarea'].includes(tag)) n.setAttribute('autocomplete', 'off'); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
  const button = (text, action, cls) => { const b = el('button', text, cls); b.type = 'button'; b.addEventListener('click', action); return b; };
  $$('[data-design-close]').forEach(b => b.onclick = () => b.closest('dialog').close());
  const frameHtml = (frame, html) => { frame.srcdoc = html; };
  $$('[data-email-preview]').forEach(frame=>{
    if(frame.hasAttribute('data-template-preview')){
      let observer;
      const fit=()=>{try{
        const doc=frame.contentDocument;if(!doc?.body)return;
        const style=frame.contentWindow.getComputedStyle(doc.body);
        const height=Math.ceil(Math.max(doc.body.scrollHeight,doc.body.getBoundingClientRect().height)+(parseFloat(style.marginTop)||0)+(parseFloat(style.marginBottom)||0))+2;
        const box=frame.parentElement,list=frame.closest('.template-gallery')?.classList.contains('template-list');
        const scale=Math.min(1,box.clientWidth/600,list?100/height:1);
        frame.style.height=height+'px';frame.style.transform=`scale(${scale})`;
        box.style.minHeight=list?'':Math.ceil(height*scale)+2+'px';
      }catch(_){/* A protected preview remains inert. */}};
      frame.addEventListener('load',()=>{observer?.disconnect();const doc=frame.contentDocument;if(!doc?.body)return;observer=new ResizeObserver(()=>requestAnimationFrame(fit));observer.observe(doc.body);doc.addEventListener('load',fit,true);doc.fonts?.ready.then(fit);fit();});
      let width=0;new ResizeObserver(()=>{const next=frame.parentElement.clientWidth;if(next!==width){width=next;requestAnimationFrame(fit);}}).observe(frame.parentElement);
      $$('[data-template-layout]').forEach(button=>button.addEventListener('click',()=>requestAnimationFrame(fit)));
      frame.setAttribute('scrolling','no');
    }
    if (frame.hasAttribute('data-preview-autosize')) {
      let observer, pending = false;
      frame.addEventListener('load', () => {
        observer?.disconnect();
        const doc = frame.contentDocument;
        if (!doc?.body) return;
        const resize = () => {
          pending = false;
          const body = frame.contentWindow.getComputedStyle(doc.body), border = getComputedStyle(frame);
          const height = Math.ceil(Math.max(doc.body.scrollHeight, doc.body.getBoundingClientRect().height)
            + (parseFloat(body.marginTop) || 0) + (parseFloat(body.marginBottom) || 0)
            + (parseFloat(border.borderTopWidth) || 0) + (parseFloat(border.borderBottomWidth) || 0)) + 2;
          if (height > 0 && frame.style.height !== `${height}px`) frame.style.height = `${height}px`;
        };
        const schedule = () => { if (!pending) { pending = true; requestAnimationFrame(resize); } };
        observer = new ResizeObserver(schedule);
        observer.observe(doc.body);
        doc.addEventListener('load', schedule, true);
        doc.fonts?.ready.then(schedule);
        schedule();
      });
    }
    frameHtml(frame,frame.dataset.emailPreview);
  });
  const gallery = $('#template-gallery');
  if (gallery) {
    let category = 'All', galleryPage=1;
    const filter = () => {
      const q = $('[data-template-search]').value.toLowerCase(); const folder = $('[data-template-folder]')?.value || '';
      const cards = $$('.template-card', gallery); const type=$('[data-template-type]')?.value||''; const sort = $('[data-template-sort]').value;
      cards.sort((a,b) => sort === 'name' ? a.dataset.templateName.localeCompare(b.dataset.templateName) : (sort === 'oldest' ? 1 : -1) * a.dataset.templateDate.localeCompare(b.dataset.templateDate));
      const matches=cards.filter(c=>c.dataset.templateName.includes(q)&&(category==='All'||category===c.dataset.templateCategoryValue)&&(!folder||folder===c.dataset.templateFolderValue)&&(!type||type===c.dataset.templateMode));
      const maxPage=Math.max(1,Math.ceil(matches.length/20));galleryPage=Math.min(maxPage,Math.max(1,galleryPage));const visible=new Set(matches.slice((galleryPage-1)*20,galleryPage*20));cards.forEach(c=>{c.hidden=!visible.has(c);gallery.append(c);});
      $('[data-template-empty]').hidden=matches.length>0;
      const pagination=$('#template-pagination');pagination.replaceChildren();const previous=button('‹',()=>{galleryPage--;filter();});previous.setAttribute('aria-label','Previous page');previous.disabled=galleryPage===1;const next=button('›',()=>{galleryPage++;filter();});next.setAttribute('aria-label','Next page');next.disabled=galleryPage===maxPage;
      const page=el('input');page.type='number';page.min=1;page.max=maxPage;page.value=galleryPage;page.setAttribute('aria-label','Page number');page.onchange=()=>{galleryPage=Number(page.value)||1;filter();};pagination.append(el('span',`Showing results ${matches.length?(galleryPage-1)*20+1:0}–${Math.min(galleryPage*20,matches.length)} of ${matches.length}`),previous,el('span','Page'),page,el('span','of '+maxPage),next);

    };
    const resetFilter=()=>{galleryPage=1;filter();};$('[data-template-search]').oninput=resetFilter;$('[data-template-sort]').onchange=resetFilter;
    if($('[data-template-type]'))$('[data-template-type]').onchange=resetFilter;
    if ($('[data-template-folder]')) $('[data-template-folder]').onchange = resetFilter;
    $$('[data-template-category]').forEach(b => b.onclick = () => { category = b.dataset.templateCategory;galleryPage=1; $$('[data-template-category]').forEach(x => x.setAttribute('aria-pressed', String(x === b))); filter(); });
    $$('[data-template-layout]').forEach(b => b.onclick = () => { gallery.classList.toggle('template-list', b.dataset.templateLayout === 'list'); $$('[data-template-layout]').forEach(x => x.setAttribute('aria-pressed', String(x === b))); });
    $('[data-template-all-filters]')?.addEventListener('click',()=>{$('[data-template-category-filter]').value=category;$('[data-template-type-filter]').value=$('[data-template-type]').value;$('#template-filters-dialog').showModal();});
    $('[data-template-apply-filters]')?.addEventListener('click',()=>{category=$('[data-template-category-filter]').value;$('[data-template-type]').value=$('[data-template-type-filter]').value;$$('[data-template-category]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.templateCategory===category)));$('#template-filters-dialog').close();resetFilter();});
    filter();
    $$('[data-template-operation]').forEach(b => b.onclick = () => {
      const d = $('#template-operation'); const r = JSON.parse(b.dataset.templateRecord); const op = b.dataset.templateOperation;
      ['id','version'].forEach(k => $(`[name=${k}]`, d).value = r[k]); $('[name=operation]',d).value = op;
      $('#template-operation-title').textContent = {rename:'Rename template',folder:'Move to folder',replicate:'Replicate template',delete:'Delete template?'}[op];
      const label = $('[data-template-input-label]',d); label.hidden = !['rename','folder'].includes(op);
      const input = $('input', label); input.name = op === 'folder' ? 'folder' : 'name'; input.value = op === 'folder' ? r.folder : r.name; input.required = op === 'rename';
      label.firstChild.textContent = op === 'folder' ? 'Folder name (leave blank to unfile)' : 'Template name';
      $('[data-template-delete-note]',d).hidden = op !== 'delete'; $('button.primary',d).textContent = {rename:'Save',folder:'Move',replicate:'Replicate',delete:'Delete'}[op]; d.showModal();
    });
  }
  $('[data-template-import]')?.addEventListener('click',()=>$('#template-import-dialog').showModal());
  $('[data-template-legacy]')?.addEventListener('click',()=>$('#template-legacy-dialog').showModal());
  $$('[data-import-tab]').forEach(button=>button.onclick=()=>{const type=button.dataset.importTab;$$('[data-import-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));$('[data-import-code]').hidden=type!=='paste';$('[data-import-file]').hidden=type==='paste';const input=$('[data-import-file] input');input.value='';input.accept=type==='zip'?'.zip':'.html,.htm';$('[data-import-code] textarea').value='';$('[data-import-help]').textContent=type==='zip'?'Include one HTML file and local images. Maximum 5 MB, 100 files.':type==='html'?'Choose an HTML file up to 1 MB.':'Paste your HTML. Add named mc:edit regions to edit content visually.';});
  const configNode=$('#designer-config');if(!configNode)return;
  const config=JSON.parse(configNode.textContent), canvas=$('#email-canvas'), tools=$('#designer-tools'), status=$('#design-status');
  let design=structuredClone(config.design),version=config.version,selected=null,selectedRow=null,panel='add',addTab='content',sectionTab='manage',blockTab='content',device='desktop',dirty=false,timer,savePromise=null,drag=null,confirmAction=null,nameAction=null,libraryType='image',libraryPage=1,libraryChoose=null,richRange=null,cropImage=null,cropTarget=null,codeSequence=0;
  const undo=[],redo=[];const uid=prefix=>prefix+'-'+crypto.randomUUID();
  const error=message=>{$('#design-error').textContent=message;$('#design-error').hidden=!message;$$('.designer-dialog-error').forEach(n=>n.remove());const dialog=$$('dialog[open]').at(-1);if(message&&dialog){const notice=el('p',message,'designer-dialog-error');notice.setAttribute('role','alert');dialog.prepend(notice);}};
  const run=fn=>async(...args)=>{try{return await fn(...args);}catch(e){error(e.message);}};
  function normalize(){
    normalizeEmailDesignMaps(design);
    design.mode ||= 'visual';design.mobile ||= {};design.footer ||= {text:'',styles:{}};design.areas ||= {};design.rows ||= [];
    const ids=new Set();design.rows.forEach((row,r)=>{if(!row.id||ids.has(row.id))row.id=uid('section');ids.add(row.id);row.name ||= 'Section '+(r+1);row.styles ||= {};row.mobile ||= {};row.condition ||= {match:'all',rules:[]};row.columns.forEach(col=>col.forEach(b=>{if(!b.id||ids.has(b.id))b.id=uid('block');ids.add(b.id);b.styles ||= {};b.mobile ||= {};b.inherit ||= [];b.condition ||= {match:'all',rules:[]};b.linkDevices ??= true;b.images ||= [];b.social ||= [];b.anchor ||= '';b.caption ||= '';b.code ||= '';b.headingLevel ||= 1;b.dividerWidth ||= 100;b.dividerHeight ||= 1;b.margin ||= 0;}));});
  }
  normalize();
  const request=async(action,extra={})=>{
    const body=new URLSearchParams({csrf_token:$('#design-csrf [name=csrf_token]').value,action,preview_nonce:config.nonce,version:String(version),...extra});
    const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',headers:{'X-Audience-Design':'1','Content-Type':'application/x-www-form-urlencoded'},body});let data;
    try{data=await response.json();}catch{throw new Error('Your session may have expired. Sign in in another tab, then try Save. Your edits remain here.');}
    if(!response.ok||data.error)throw new Error(data.error||'Unable to save. Try again.');return data;
  };
  const recoveryKey='moed-email-recovery:'+config.recoveryKey;
  const recover=()=>{try{localStorage.setItem(recoveryKey,JSON.stringify({design,version,time:Date.now()}));}catch{error('Browser recovery storage is full. Keep this tab open until your design is saved.');}};
  const history=()=>{undo.push(JSON.stringify(design));if(undo.length>60)undo.shift();redo.length=0;};
  const changed=()=>{dirty=true;status.textContent='Unsaved changes';recover();clearTimeout(timer);timer=setTimeout(()=>save(false),1800);updateHistory();};
  const mutate=fn=>{history();fn();normalize();changed();render();};
  function updateHistory(){$('#design-undo').disabled=!undo.length;$('#design-redo').disabled=!redo.length;}
  async function save(exit){
    clearTimeout(timer);if(savePromise){const ok=await savePromise;if(!ok)return false;if(dirty)return save(exit);if(exit)location.href=config.exit;return true;}
    if(!dirty&&!exit)return true;
    const snapshot=JSON.stringify(design);status.textContent='Saving…';error('');
    savePromise=(async()=>{try{const result=await request('design_save',{design:snapshot});version=result.version;dirty=snapshot!==JSON.stringify(design);status.textContent=dirty?'Unsaved changes':'Saved';if(dirty)recover();else try{localStorage.removeItem(recoveryKey);}catch{}return true;}catch(e){error(e.message);status.textContent='Not saved';return false;}})();
    const ok=await savePromise;savePromise=null;if(!ok)return false;if(dirty){if(exit)return save(true);timer=setTimeout(()=>save(false),1800);}else if(exit)location.href=config.exit;return !dirty;
  }
  function travel(from,to){if(!from.length)return;to.push(JSON.stringify(design));design=JSON.parse(from.pop());selected=null;selectedRow=null;normalize();changed();render();}
  function confirmDesignAction(title,text,action){$('#design-confirm-title').textContent=title;$('#design-confirm-text').textContent=text;confirmAction=action;$('#design-confirm-dialog').showModal();}
  function nameDialog(title,value,action){$('#design-name-title').textContent=title;$('#design-name-dialog input').value=value;nameAction=action;$('#design-name-dialog').showModal();}
  $('#design-confirm').onclick=run(async()=>{$('#design-confirm-dialog').close();await confirmAction?.();});
  $('#design-name-dialog form').onsubmit=run(async e=>{e.preventDefault();await nameAction($('#design-name-dialog input').value);$('#design-name-dialog').close();});
  const defaultBlock=type=>({id:uid('block'),type,text:{heading:'Write a compelling headline',text:'Add your text here.',button:'Learn more','image-card':'Add a caption for your image.'}[type]||'',html:'',image:'',alt:'',url:['button','video'].includes(type)?'https://example.com':'',align:type==='button'?'center':'left',padding:24,margin:0,size:type==='heading'?(design.styles.heading1||32):design.styles.fontSize,height:24,bold:false,italic:false,color:design.styles.text,background:design.styles.button,radius:design.styles.buttonRadius||4,styles:{},mobile:{},inherit:['color','size','font','lineHeight','letterSpacing','paragraphSpacing','background','radius','dividerWidth','dividerHeight'],linkDevices:true,headingLevel:1,includeToc:false,anchor:'',condition:{match:'all',rules:[]},images:type==='image-group'?[{image:'',alt:'',url:''},{image:'',alt:'',url:''}]:[],social:type==='social'?[{label:'Facebook',url:'https://facebook.com/'},{label:'Instagram',url:'https://instagram.com/'}]:[],caption:'Watch video',code:'<p>Add your HTML here.</p>',dividerWidth:design.styles.dividerWidth||100,dividerHeight:design.styles.dividerHeight||1});
  const freshRow=(columns=1,name='Section')=>({id:uid('section'),name,styles:{},mobile:{},condition:{match:'all',rules:[]},columns:Array.from({length:columns},()=>[])});
  function freshCopy(value){const copy=structuredClone(value);const walk=x=>{if(x&&typeof x==='object'){if(x.id)x.id=uid(x.columns?'section':'block');if(x.anchor)x.anchor='';Object.values(x).forEach(walk);}};walk(copy);return copy;}
  const blockAt=()=>selected?design.rows[selected[0]]?.columns[selected[1]]?.[selected[2]]:null;
  $('#design-ai').addEventListener('click',()=>{
    const block=blockAt();
    if(!block||!['text','heading'].includes(block.type)){error('Select a text or heading block before opening Write with AI.');return;}
    const plain=block.html?new DOMParser().parseFromString(block.html,'text/html').body.textContent:block.text;
    document.dispatchEvent(new CustomEvent('audience-ai-open',{detail:{text:plain,target:{id:block.id,original:JSON.stringify(block)}}}));
  });
  document.addEventListener('audience-ai-apply',event=>{
    const block=design.rows.flatMap(row=>row.columns.flat()).find(item=>item.id===event.detail.target.id);
    if(!block||!['text','heading'].includes(block.type)||JSON.stringify(block)!==event.detail.target.original||typeof event.detail.text!=='string'||event.detail.text.length>10000)return;
    mutate(()=>{block.text=event.detail.text;block.html='';});event.preventDefault();
  });

  function addBlock(type,target){if(design.mode==='code'){error('Use Edit code for this custom-coded design. Choose a visual template to use drag-and-drop blocks.');return;}mutate(()=>{if(!design.rows.length)design.rows.push(freshRow());const p=target||selected||[design.rows.length-1,0,design.rows.at(-1).columns[0].length];const col=design.rows[p[0]].columns[p[1]],index=target?p[2]:selected?p[2]+1:col.length;col.splice(index,0,defaultBlock(type));selected=[p[0],p[1],index];selectedRow=null;panel='block';blockTab='content';});}
  const field=(label,value,onChange,type='text',options)=>{
    const wrap=el('label',label,'designer-field'), input=el(options?'select':type==='textarea'?'textarea':'input');
    if(options)Object.entries(options).forEach(([v,t])=>{const o=el('option',t);o.value=v;input.append(o);});else if(type!=='textarea')input.type=type;
    if(type==='checkbox')input.checked=!!value;else input.value=value??'';if(type==='textarea')input.rows=6;
    input.addEventListener(options||type==='checkbox'?'change':'input',()=>{if(type==='number'&&(input.value===''||!Number.isFinite(Number(input.value))))return;history();onChange(type==='number'?Number(input.value):type==='checkbox'?input.checked:input.value);changed();renderCanvas();});wrap.append(input);return wrap;
  };
  const tabs=(options,value,fn)=>{const nav=el('div',undefined,'designer-subtabs');Object.entries(options).forEach(([key,label])=>{const b=button(label,()=>fn(key));b.setAttribute('aria-pressed',String(value===key));nav.append(b);});return nav;};
  const details=(title,open=true)=>{const d=el('details');d.open=open;d.append(el('summary',title));return d;};
  function styleField(target,key,label,type='text',options){return field(label,target[key]??(type==='color'?'#ffffff':type==='number'?0:''),v=>{target[key]=v;},type,options);}
  function blockStyle(b,row){const s=design.styles;const defaults={color:s.text,size:b.type==='heading'?(s['heading'+b.headingLevel]||32):b.type==='button'?(s.buttonSize||16):s.fontSize,font:s.font,lineHeight:s.lineHeight,letterSpacing:s.letterSpacing||0,paragraphSpacing:s.paragraphSpacing||16,direction:s.direction||'ltr',padding:24,margin:0,align:'left'};let style={...defaults,...row.styles};['color','size','padding','margin','align'].forEach(k=>{if(!b.inherit.includes(k))style[k]=b[k];});style={...style,...b.styles};if(device==='mobile'){Object.entries(design.mobile).forEach(([k,v])=>{if(b.inherit.includes(k)&&!(k==='size'&&['heading','button'].includes(b.type)))style[k]=v;});if(b.inherit.includes('size')&&b.type==='heading')style.size=design.mobile['heading'+b.headingLevel]??style.size;if(b.inherit.includes('size')&&b.type==='button')style.size=design.mobile.buttonSize??style.size;style={...style,...row.mobile,...(!b.linkDevices?b.mobile:{})};}return style;}
  function applyStyle(node,s){Object.assign(node.style,{padding:`${s.padding||0}px`,margin:`${s.margin||0}px`,textAlign:s.align||'left',fontFamily:design.styles.webFont&&s.font===design.styles.font?'MOEDBrandFont,'+s.font:s.font||'inherit',color:s.color||'inherit',fontSize:`${s.size||16}px`,lineHeight:(s.lineHeight||160)/100,letterSpacing:`${s.letterSpacing||0}px`,direction:s.direction||'ltr'});if(s.background)node.style.background=s.background;if(s.borderWidth)node.style.border=`${s.borderWidth}px ${s.borderStyle||'solid'} ${s.borderColor||'#000000'}`;}
  function conditionEditor(target){
    target.condition ||= {match:'all',rules:[]};const box=details('Dynamic content',target.condition.rules.length>0);box.append(el('p','Show this content only to connections who match these conditions.','designer-panel-help'));
    box.append(field('Match',target.condition.match,v=>target.condition.match=v,'select',{all:'All conditions',any:'Any condition'}));
    target.condition.rules.forEach((rule,index)=>{const row=el('div',undefined,'designer-condition');row.append(field('Field',rule.field,v=>{rule.field=v;if(/^(tag|group):/.test(v)){rule.operator='is';rule.value='1';}renderTools();},'select',config.dynamicFields||config.mergeTags),field('Condition',rule.operator,v=>{rule.operator=v;},'select',{is:'is',is_not:'is not',contains:'contains',not_contains:'does not contain',empty:'is empty',not_empty:'is not empty'}),field('Value',rule.value,v=>{rule.value=v;},/^(tag|group):/.test(rule.field)?'select':'text',/^(tag|group):/.test(rule.field)?{'1':'Assigned','':'Not assigned'}:undefined),button('Remove condition',()=>mutate(()=>target.condition.rules.splice(index,1))));box.append(row);});
    box.append(button('+ Add condition',()=>mutate(()=>{if(target.condition.rules.length<10)target.condition.rules.push({field:'FNAME',operator:'not_empty',value:''});})));return box;
  }
  function addImageTools(parent,item){
    const upload=el('label','Upload image','designer-field'), input=el('input');input.type='file';input.accept='image/png,image/jpeg,image/webp';
    input.onchange=run(async()=>{const file=input.files[0];if(!file)return;if(file.size>700000)throw new Error('Choose an image below 700 KB.');const image=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result));reader.onerror=()=>reject(new Error('Unable to read this image.'));reader.readAsDataURL(file);});mutate(()=>{item.image=image;});});upload.append(input);parent.append(upload);
    const actions=el('div',undefined,'designer-block-actions');actions.append(button('Content studio',()=>openLibrary('image',value=>mutate(()=>{item.image=value.image;item.alt=value.alt||item.alt;item.url=value.url||item.url;}))));
    if(item.image)actions.append(button('Edit image',()=>openCrop(item)),button('Save to content studio',()=>nameDialog('Save image','Image',async name=>{await request('design_library_save',{type:'image',name,data:JSON.stringify({image:item.image,alt:item.alt||''})});status.textContent='Image saved';})));parent.append(actions,field('Alt text',item.alt||'',v=>{item.alt=v;}),field('Link URL',item.url||'',v=>{item.url=v;}));
  }
  function richMarkup(node,html){
    const template=document.createElement('template');template.innerHTML=html;const styles=[];
    $$('*',template.content).forEach(n=>{const tag=n.tagName.toLowerCase();if(!['p','div','span','font','br','strong','b','em','i','u','s','ul','ol','li','a'].includes(tag)){n.remove();return;}
      [...n.attributes].forEach(a=>{if(a.name==='style'){const safe=a.value.split(';').filter(part=>/^(?:color|font-family|font-size|font-weight|font-style|text-decoration|letter-spacing)\s*:/i.test(part)&&!/(?:url|expression|javascript|behavior|binding)/i.test(part)).join(';');styles.push([n,safe]);n.removeAttribute(a.name);}
        else if(!['href','id','color','face','size'].includes(a.name)||a.name==='href'&&!/^(?:https:\/\/|mailto:|tel:|#|\*\|)/i.test(a.value))n.removeAttribute(a.name);});
    });node.replaceChildren(template.content);styles.forEach(([n,css])=>{if(n.isConnected)n.style.cssText=css;else queueMicrotask(()=>{if(n.isConnected)n.style.cssText=css;});});
  }
  function richText(b){
    const rich=el('div',undefined,'designer-rich-text');rich.contentEditable='true';rich.setAttribute('role','textbox');rich.setAttribute('aria-label','Text');rich.setAttribute('aria-multiline','true');if(b.html)richMarkup(rich,b.html);else rich.textContent=b.text;
    rich.oninput=()=>{history();b.html=rich.innerHTML;b.text=rich.innerText;changed();renderCanvas();};rich.onpaste=e=>{e.preventDefault();document.execCommand('insertText',false,e.clipboardData.getData('text/plain'));};rich.ondrop=e=>e.preventDefault();rich.onmouseup=rich.onkeyup=()=>{const selection=window.getSelection();if(selection.rangeCount&&rich.contains(selection.anchorNode))richRange=selection.getRangeAt(0).cloneRange();};
    const toolbar=el('div',undefined,'designer-rich-toolbar');[['bold','Bold selection','B'],['italic','Italic selection','I'],['underline','Underline selection','U'],['insertUnorderedList','Bulleted list','•'],['insertOrderedList','Numbered list','1.'],['removeFormat','Clear formatting','Tx']].forEach(([command,label,icon])=>{const b=button(icon,()=>{rich.focus();document.execCommand(command,false,null);});b.setAttribute('aria-label',label);b.onmousedown=e=>e.preventDefault();toolbar.append(b);});
    const link=button('↗',()=>{$('#design-link-dialog input').value='';$('#design-link-dialog').showModal();});link.setAttribute('aria-label','Insert link');link.onmousedown=e=>e.preventDefault();toolbar.append(link);
    const applySelection=(command,value)=>{rich.focus();if(richRange){const selection=window.getSelection();selection.removeAllRanges();selection.addRange(richRange);}document.execCommand(command,false,value);};
    const font=el('select');font.setAttribute('aria-label','Selected text font');['Arial','Georgia','Verdana','Tahoma','Times New Roman'].forEach(name=>{const option=el('option',name);font.append(option);});font.onchange=()=>applySelection('fontName',font.value);toolbar.append(font);
    const color=el('input');color.type='color';color.value='#24292d';color.setAttribute('aria-label','Selected text color');color.onchange=()=>applySelection('foreColor',color.value);toolbar.append(color);
    tools.append(el('span','Text'),toolbar,rich);
  }
  function renderTools(){
    tools.replaceChildren();$$('.designer-rail button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.panel===panel)));
    if(design.mode==='code'&&panel==='add'){
      tools.append(el('h2','Custom HTML'),button('Edit code',openCode),el('p','The original source remains editable. Use named regions for content changes.','designer-panel-help'));
      const regions=el('div');tools.append(regions);run(async()=>{const data=await request('design_code_validate',{code:design.code});if(!regions.isConnected)return;Object.entries(data.regions).forEach(([name,text])=>regions.append(field(name,design.areas[name]??text,v=>{design.areas[name]=v;},'textarea')));if(!Object.keys(data.regions).length)regions.append(el('p','No mc:edit regions found.'));})();return;
    }
    if(panel==='add'){
      tools.append(el('h2','Add content'),tabs({content:'Content',layouts:'Layouts'},addTab,v=>{addTab=v;renderTools();}));const grid=el('div',undefined,'designer-block-grid');
      if(addTab==='content')Object.entries({heading:['H','Heading'],text:['¶','Paragraph'],image:['▧','Image'],'image-card':['▣','Image card'],'image-group':['▥','Image group'],button:['▰','Button'],divider:['―','Divider'],spacer:['↕','Spacer'],video:['▷','Video'],social:['↗','Social'],code:['</>','Code'],toc:['☷','Table of contents']}).forEach(([type,[icon,label]])=>{const b=button('',()=>addBlock(type));b.append(el('span',icon),el('span',label));b.draggable=true;b.ondragstart=e=>{drag={type};e.dataTransfer.effectAllowed='copy';e.dataTransfer.setData('text/plain',type);};b.ondragend=()=>drag=null;grid.append(b);});
      else [1,2,3,4].forEach(n=>{const b=button('',()=>mutate(()=>{const row=freshRow(n,'Layout');row.columns.forEach(col=>col.push(defaultBlock('text')));design.rows.push(row);selected=null;selectedRow=null;}));b.append(el('span',Array(n).fill('▯').join('')),el('span',`${n} column${n>1?'s':''}`));grid.append(b);});tools.append(grid);
    }else if(panel==='styles'){
      tools.append(el('h2','Styles'),tabs({desktop:'Desktop',mobile:'Mobile'},device,v=>setDevice(v)));
      const mobile=device==='mobile',target=mobile?design.mobile:design.styles;
      const email=details('Email');if(!mobile)email.append(styleField(target,'background','Background color','color'),styleField(target,'body','Body color','color'),styleField(target,'width','Email width','number'));else email.append(styleField(target,'padding','Padding','number'));tools.append(email);
      const text=details('Text');text.append(field('Font',target.font||design.styles.font,v=>target.font=v,'select',Object.fromEntries(['Arial','Georgia','Verdana','Tahoma','Times New Roman'].map(x=>[x,x]))),field(mobile?'Font size':'Paragraph size',target[mobile?'size':'fontSize']||design.styles.fontSize,v=>target[mobile?'size':'fontSize']=v,'number'));
      [1,2,3,4].forEach(n=>text.append(field('Heading '+n+' size',target['heading'+n]||design.styles['heading'+n]||32,v=>target['heading'+n]=v,'number')));
      text.append(field('Text color',target[mobile?'color':'text']||design.styles.text,v=>target[mobile?'color':'text']=v,'color'),field('Line height (%)',target.lineHeight||design.styles.lineHeight,v=>target.lineHeight=v,'number'),field('Letter spacing',target.letterSpacing??design.styles.letterSpacing??0,v=>target.letterSpacing=v,'number'),field('Paragraph spacing',target.paragraphSpacing??design.styles.paragraphSpacing??16,v=>target.paragraphSpacing=v,'number'),field('Text direction',target.direction||design.styles.direction||'ltr',v=>target.direction=v,'select',{ltr:'Left to right',rtl:'Right to left'}));tools.append(text);
      if(mobile){const buttonStyles=details('Button',false);buttonStyles.append(field('Button text size',target.buttonSize||design.styles.buttonSize||16,v=>target.buttonSize=v,'number'));const dividerStyles=details('Divider',false);dividerStyles.append(field('Height',target.dividerHeight||design.styles.dividerHeight||1,v=>target.dividerHeight=v,'number'),field('Width (%)',target.dividerWidth||design.styles.dividerWidth||100,v=>target.dividerWidth=v,'number'));tools.append(buttonStyles,dividerStyles);}
      if(!mobile){const link=details('Link',false);link.append(styleField(target,'link','Link color','color'),field('Underline links',target.linkUnderline??true,v=>target.linkUnderline=v,'checkbox'));const btn=details('Button',false);btn.append(field('Button text size',target.buttonSize||16,v=>target.buttonSize=v,'number'),styleField(target,'button','Button color','color'),styleField(target,'buttonText','Button text color','color'),styleField(target,'buttonRadius','Rounded corners','number'));const divider=details('Divider',false);divider.append(styleField(target,'dividerHeight','Height','number'),styleField(target,'dividerWidth','Width (%)','number'));tools.append(link,btn,divider);}
    }else if(panel==='sections')renderSections();
    else if(panel==='section')renderSectionStyle();
    else if(panel==='comments')renderComments();
    else if(panel==='optimize'){tools.append(el('h2','Optimize'),el('p','Review your content before sending.','designer-panel-help'),button('Check links',openLinks),button('Preview personalized content',openPreview),button('Review plain-text email',openPlain),button('Edit footer',()=>{panel='footer';renderTools();}));}
    else if(panel==='footer'){tools.append(button('← Back',()=>{panel='add';renderTools();}),el('h2','Footer'),el('p','Identity, mailing address, preferences, and unsubscribe links stay in every email.','designer-panel-help'),field('Additional footer text',design.footer.text,v=>design.footer.text=v,'textarea'),field('Text color',design.footer.styles.color||'#60676b',v=>design.footer.styles.color=v,'color'),field('Background color',design.footer.styles.background||design.styles.body,v=>design.footer.styles.background=v,'color'),field('Padding',design.footer.styles.padding??32,v=>design.footer.styles.padding=v,'number'));}
    else renderBlockTools();
  }
  function renderBlockTools(){
    const b=blockAt();if(!b){panel='add';renderTools();return;}const row=design.rows[selected[0]];
    tools.append(button('← Back to content',()=>{selected=null;panel='add';render();},'designer-back'),el('h2',{'image-card':'Image card','image-group':'Image group',toc:'Table of contents'}[b.type]||b.type[0].toUpperCase()+b.type.slice(1)),tabs({content:'Content',style:'Style',settings:'Settings'},blockTab,v=>{blockTab=v;renderTools();}));
    if(blockTab==='content'){
      if(['text','heading','image-card'].includes(b.type))richText(b);
      if(b.type==='button')tools.append(field('Button text',b.text,v=>{b.text=v;}));
      if(['text','heading','image-card','button'].includes(b.type))tools.append(field('Merge tags','',v=>{if(v){b.text+=` *|${v}|*`;if(b.html)b.html+=` *|${v}|*`;renderTools();}},'select',{'':'Insert a merge tag',...config.mergeTags,'MC:TOC':'Table of Contents'}));
      if(['image','image-card','video'].includes(b.type))addImageTools(tools,b);
      if(b.type==='image-group'){
        b.images.forEach((image,i)=>{const group=details('Image '+(i+1));addImageTools(group,image);group.append(button('Remove image',()=>mutate(()=>b.images.splice(i,1))));tools.append(group);});if(b.images.length<4)tools.append(button('Add image',()=>mutate(()=>b.images.push({image:'',alt:'',url:''}))));
      }
      if(['text','heading','button'].includes(b.type))tools.append(field('Link URL',b.url,v=>{b.url=v;}));
      if(b.type==='video')tools.append(field('Video caption',b.caption,v=>b.caption=v),el('p','Add a thumbnail and link. The video opens from the email.','designer-panel-help'));
      if(b.type==='social'){
        b.social.forEach((link,i)=>{const group=details('Link '+(i+1));group.append(field('Label',link.label,v=>link.label=v),field('URL',link.url,v=>link.url=v),button('Remove link',()=>mutate(()=>b.social.splice(i,1))));tools.append(group);});if(b.social.length<10)tools.append(button('Add social link',()=>mutate(()=>b.social.push({label:'Website',url:'https://example.com'}))));
      }
      if(b.type==='code')tools.append(field('HTML code',b.code,v=>b.code=v,'textarea'),el('p','HTML is validated and rendered in a protected preview.','designer-panel-help'));
      if(b.type==='toc')tools.append(el('p','Headings marked “Include in table of contents” appear here with anchor links.','designer-panel-help'));
      if(b.type==='spacer')tools.append(field('Height',b.height,v=>b.height=v,'number'));
    }else if(blockTab==='style'){
      const mobile=device==='mobile'&&!b.linkDevices;
      tools.append(field('Link Desktop and Mobile Styles',b.linkDevices,v=>{b.linkDevices=v;renderTools();},'checkbox'),tabs({desktop:'On Desktop',mobile:'On Mobile'},device,v=>setDevice(v)));
      const target=mobile?b.mobile:b;const effective=blockStyle(b,row);const change=(key,value)=>{target[key]=value;if(!mobile)b.inherit=b.inherit.filter(k=>k!==key);};
      tools.append(field('Alignment',target.align||effective.align,v=>change('align',v),'select',{left:'Left',center:'Center',right:'Right'}),field('Padding',target.padding??effective.padding,v=>change('padding',v),'number'),field('Margin',target.margin??effective.margin,v=>change('margin',v),'number'));
      if(!['image','image-group','divider','spacer'].includes(b.type))tools.append(field('Font size',target.size??effective.size,v=>change('size',v),'number'),field('Text color',b.type==='button'?(b.styles.color||design.styles.buttonText):(target.color||effective.color),v=>{if(b.type==='button'&&!mobile)b.styles.color=v;else change('color',v);},'color'));
      const extras=mobile?b.mobile:b.styles;tools.append(field('Font',extras.font||effective.font,v=>extras.font=v,'select',Object.fromEntries(['Arial','Georgia','Verdana','Tahoma','Times New Roman'].map(x=>[x,x]))),field('Line height (%)',extras.lineHeight??effective.lineHeight,v=>extras.lineHeight=v,'number'),field('Letter spacing',extras.letterSpacing??effective.letterSpacing,v=>extras.letterSpacing=v,'number'),field('Paragraph spacing',extras.paragraphSpacing??effective.paragraphSpacing,v=>extras.paragraphSpacing=v,'number'));
      if(b.type==='button'||b.type==='image-card')tools.append(field('Background color',b.background,v=>{b.background=v;b.inherit=b.inherit.filter(k=>k!=='background');},'color'));if(b.type==='button')tools.append(field('Rounded corners',b.radius,v=>{b.radius=v;b.inherit=b.inherit.filter(k=>k!=='radius');},'number'));
      if(b.type==='divider')tools.append(field('Line color',b.color,v=>b.color=v,'color'),field('Height',b.dividerHeight,v=>{if(mobile)b.mobile.dividerHeight=v;else{b.dividerHeight=v;b.inherit=b.inherit.filter(k=>k!=='dividerHeight');}},'number'),field('Width (%)',b.dividerWidth,v=>{if(mobile)b.mobile.dividerWidth=v;else{b.dividerWidth=v;b.inherit=b.inherit.filter(k=>k!=='dividerWidth');}},'number'),field('Style',b.styles.borderStyle||'solid',v=>b.styles.borderStyle=v,'select',{solid:'Solid',dashed:'Dashed',dotted:'Dotted'}));
      const border=details(b.type==='button'?'Button border':'Block border',false);border.append(field('Border width',extras.borderWidth||0,v=>extras.borderWidth=v,'number'),field('Border color',extras.borderColor||'#24292d',v=>extras.borderColor=v,'color'),field('Border style',extras.borderStyle||'solid',v=>extras.borderStyle=v,'select',{solid:'Solid',dashed:'Dashed',dotted:'Dotted'}));tools.append(border);
      tools.append(button('Restore default styles',()=>mutate(()=>{b.inherit=['color','size','padding','align','font','lineHeight','letterSpacing','paragraphSpacing','margin','background','radius','dividerWidth','dividerHeight'];b.styles={};b.mobile={};})));
    }else{
      if(b.type==='heading')tools.append(field('Heading level',b.headingLevel,v=>b.headingLevel=Number(v),'select',{'1':'Heading 1','2':'Heading 2','3':'Heading 3','4':'Heading 4'}),field('Include in table of contents',b.includeToc,v=>b.includeToc=v,'checkbox'));
      if(['heading','text'].includes(b.type))tools.append(field('Anchor name',b.anchor,v=>b.anchor=v));tools.append(conditionEditor(b));
      tools.append(button('Comment on this block',()=>{panel='comments';renderTools();}));
    }
    const actions=el('div',undefined,'designer-block-actions');actions.append(button('Move up',()=>move(-1)),button('Move down',()=>move(1)),button('Duplicate',duplicate),button('Delete',remove));tools.append(actions);
  }
  function renderSections(){
    tools.append(el('h2','Email Sections'),el('p','Create and edit your email structure, or insert pre-built sections.','designer-panel-help'),tabs({manage:'Manage',prebuilt:'Pre-built',saved:'Saved'},sectionTab,v=>{sectionTab=v;renderTools();}));
    if(sectionTab==='manage'){
      design.rows.forEach((row,r)=>{const item=el('div',undefined,'designer-section-row');item.draggable=true;item.ondragstart=e=>{drag={section:r};e.dataTransfer.setData('text/plain','section');};item.ondragover=e=>e.preventDefault();item.ondrop=e=>{e.preventDefault();if(drag?.section!==undefined){const from=drag.section;drag=null;mutate(()=>{const moved=design.rows.splice(from,1)[0];design.rows.splice(r,0,moved);selected=null;selectedRow=null;});}};
        item.append(button(row.name,()=>{selectedRow=r;selected=null;panel='section';render();}),button('↑',()=>rowMove(r,-1)),button('↓',()=>rowMove(r,1)));const menu=el('details',undefined,'designer-section-menu');menu.append(el('summary','•••'));const list=el('div');list.append(button('Rename',()=>nameDialog('Rename section',row.name,name=>mutate(()=>row.name=name))),button('Duplicate',()=>mutate(()=>design.rows.splice(r+1,0,freshCopy(row)))),button('Save',()=>saveSection(row)),button('Delete',()=>confirmDesignAction('Delete section?','This removes the section and its content. You can undo the change.',()=>mutate(()=>{design.rows.splice(r,1);selected=null;selectedRow=null;}))));menu.append(list);item.append(menu);tools.append(item);});
      tools.append(button('+ Add blank section',()=>mutate(()=>design.rows.push(freshRow(1,'Blank section')))));
    }else if(sectionTab==='prebuilt'){
      const presets={Header:['heading','text'],Hero:['image','heading','button'],Features:['heading','text'],Footer:['social','text']};Object.entries(presets).forEach(([name,types])=>tools.append(button(name,()=>mutate(()=>{const row=freshRow(name==='Features'?2:1,name);row.columns.forEach(col=>types.forEach(type=>col.push(defaultBlock(type))));design.rows.push(row);}))),el('p','Pre-built sections adapt to your email styles.','designer-panel-help'));
    }else{
      tools.append(button('Browse saved sections',()=>openLibrary('section',value=>mutate(()=>design.rows.push(freshCopy(value.rows[0]))))));
    }
  }
  function renderSectionStyle(){
    const row=design.rows[selectedRow];if(!row){panel='sections';renderTools();return;}
    tools.append(button('← Email Sections',()=>{panel='sections';renderTools();}),el('h2',row.name),tabs({desktop:'Desktop',mobile:'Mobile'},device,v=>setDevice(v)));const target=device==='mobile'?row.mobile:row.styles;
    tools.append(field('Section name',row.name,v=>row.name=v),field('Background color',target.background||design.styles.body,v=>target.background=v,'color'),field('Text color',target.color||design.styles.text,v=>target.color=v,'color'),field('Padding',target.padding||0,v=>target.padding=v,'number'),field('Font size',target.size||design.styles.fontSize,v=>target.size=v,'number'),field('Alignment',target.align||'left',v=>target.align=v,'select',{left:'Left',center:'Center',right:'Right'}),conditionEditor(row),button('Save section',()=>saveSection(row)),button('Duplicate section',()=>mutate(()=>design.rows.splice(selectedRow+1,0,freshCopy(row)))));
  }
  function saveSection(row){nameDialog('Save section',row.name,async name=>{await request('design_library_save',{type:'section',name,data:JSON.stringify({...design,mode:'visual',rows:[row],code:'',areas:{}})});status.textContent='Section saved';});}
  function rowMove(r,delta){if(r+delta<0||r+delta>=design.rows.length)return;mutate(()=>{[design.rows[r],design.rows[r+delta]]=[design.rows[r+delta],design.rows[r]];selected=null;selectedRow=null;});}
  function move(delta){if(!selected)return;const[r,c,i]=selected,col=design.rows[r].columns[c];if(i+delta>=0&&i+delta<col.length)mutate(()=>{[col[i],col[i+delta]]=[col[i+delta],col[i]];selected=[r,c,i+delta];});else if(r+delta>=0&&r+delta<design.rows.length)mutate(()=>{const nextC=Math.min(c,design.rows[r+delta].columns.length-1),next=design.rows[r+delta].columns[nextC],index=delta<0?next.length:0;next.splice(index,0,col.splice(i,1)[0]);selected=[r+delta,nextC,index];});}
  function duplicate(){const b=blockAt();if(b)mutate(()=>{const[r,c,i]=selected;design.rows[r].columns[c].splice(i+1,0,freshCopy(b));selected=[r,c,i+1];});}
  function remove(){if(blockAt())mutate(()=>{const[r,c,i]=selected;design.rows[r].columns[c].splice(i,1);selected=null;panel='add';});}
  function drop(target){if(!drag)return;const from=drag;drag=null;if(from.type)addBlock(from.type,target);else if(from.position)mutate(()=>{const[r,c,i]=from.position,block=design.rows[r].columns[c].splice(i,1)[0];let[tr,tc,ti]=target;if(r===tr&&c===tc&&i<ti)ti--;design.rows[tr].columns[tc].splice(ti,0,block);selected=[tr,tc,ti];panel='block';});}
  function imageNode(item){if(!item.image)return el('div','▧ Add an image','designer-image-placeholder');const image=el('img');image.src=item.image;image.alt=item.alt||'';return image;}
  function renderCanvas(){
    normalize();canvas.replaceChildren();const s=design.styles;canvas.style.width=`${device==='mobile'?375:s.width}px`;canvas.style.background=s.body;canvas.style.fontFamily=s.webFont?'MOEDBrandFont,'+s.font:s.font;if(s.webFont&&canvas.dataset.brandFont!==s.webFont){canvas.dataset.brandFont=s.webFont;new FontFace('MOEDBrandFont',`url(${s.webFont})`).load().then(font=>document.fonts.add(font)).catch(()=>{status.textContent='Brand font unavailable; using the email fallback.';});}canvas.style.color=s.text;canvas.style.setProperty('--email-link',s.link);canvas.style.fontSize=`${s.fontSize}px`;canvas.style.lineHeight=s.lineHeight/100;canvas.classList.toggle('mobile',device==='mobile');$('.designer-stage').style.background=s.background;
    if(design.mode==='code'){
      canvas.append(button('Edit code',openCode));const frame=el('iframe');frame.title='Custom HTML canvas';frame.sandbox='';frame.referrerPolicy='no-referrer';frame.className='designer-code-canvas';canvas.append(frame);const sequence=++codeSequence;
      run(async()=>{const response=await request('design_preview',{design:JSON.stringify(design)});if(sequence===codeSequence&&frame.isConnected)frameHtml(frame,response.html);})();return;
    }
    design.rows.forEach((row,r)=>{
      const rn=el('div',undefined,'designer-row');rn.dataset.sectionId=row.id;applyStyle(rn,{padding:0,size:s.fontSize,...row.styles,...(device==='mobile'?row.mobile:{})});if(selectedRow===r)rn.classList.add('selected');const rowTools=el('div',undefined,'designer-layout-tools');rowTools.append(button('↑',()=>rowMove(r,-1)),button('↓',()=>rowMove(r,1)),button('⧉',()=>mutate(()=>design.rows.splice(r+1,0,freshCopy(row)))),button('⚙',()=>{selectedRow=r;selected=null;panel='section';render();}));['Move section up','Move section down','Duplicate section','Section styles'].forEach((label,i)=>$('button:nth-child('+(i+1)+')',rowTools).setAttribute('aria-label',label));rn.append(rowTools);
      row.columns.forEach((col,c)=>{
        const cn=el('div',undefined,'designer-column');cn.ondragover=e=>{e.preventDefault();cn.classList.add('drop-target');};cn.ondragleave=()=>cn.classList.remove('drop-target');cn.ondrop=e=>{e.preventDefault();e.stopPropagation();cn.classList.remove('drop-target');drop([r,c,col.length]);};
        col.forEach((b,i)=>{
          const bn=el('div',undefined,'designer-block');bn.tabIndex=0;bn.setAttribute('role','button');bn.setAttribute('aria-label',`Edit ${b.type} block ${i+1}`);bn.dataset.blockId=b.id;const isSelected=selected?.join(',')===[r,c,i].join(',');bn.classList.toggle('selected',isSelected);bn.onclick=e=>{e.preventDefault();selected=[r,c,i];selectedRow=null;panel='block';render();};bn.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();bn.click();}};
          bn.draggable=true;bn.ondragstart=e=>{drag={position:[r,c,i]};e.dataTransfer.setData('text/plain','block');e.dataTransfer.effectAllowed='move';};bn.ondragend=()=>drag=null;bn.ondragover=e=>{e.preventDefault();e.stopPropagation();bn.classList.add('drop-target');};bn.ondragleave=()=>bn.classList.remove('drop-target');bn.ondrop=e=>{e.preventDefault();e.stopPropagation();drop([r,c,i]);};
          const content=el('div',undefined,'designer-block-content'),style=blockStyle(b,row);applyStyle(content,style);if(b.type==='button')content.style.border='none';content.style.fontWeight=b.bold?'bold':'normal';content.style.fontStyle=b.italic?'italic':'normal';
          const rich=()=>{const text=el(b.type==='heading'?'h'+b.headingLevel:'div',b.html?undefined:b.text);if(b.html)richMarkup(text,b.html);text.style.whiteSpace=b.html?'normal':'pre-wrap';text.style.fontSize='inherit';text.style.margin=0;if(b.type==='heading')text.style.fontWeight='700';return text;};
          if(b.type==='image')content.append(imageNode(b));
          else if(b.type==='image-card'){content.append(imageNode(b));const caption=rich();caption.style.background=b.background;caption.style.padding='16px';content.append(caption);}
          else if(b.type==='image-group'){const group=el('div',undefined,'designer-image-group');b.images.forEach(item=>group.append(imageNode(item)));content.append(group);}
          else if(b.type==='video')content.append(imageNode(b),el('div','▶ '+(b.caption||'Watch video'),'designer-video-caption'));
          else if(b.type==='social'){const group=el('div',undefined,'designer-social-links');b.social.forEach(link=>group.append(el('span',link.label)));content.append(group);}
          else if(b.type==='code')content.append(el('pre',b.code,'designer-code-placeholder'));
          else if(b.type==='toc'){const list=el('ul');design.rows.forEach(row=>row.columns.forEach(col=>col.forEach(item=>{if(item.type==='heading'&&item.includeToc)list.append(el('li',item.text||'Heading'));})));content.append(list.childNodes.length?list:el('p','Table of contents — mark headings in Settings.'));}
          else if(b.type==='divider'){const line=el('div');line.style.borderTop=`${device==='mobile'&&!b.linkDevices?(b.mobile.dividerHeight||design.mobile.dividerHeight||b.dividerHeight):b.inherit.includes('dividerHeight')?(design.styles.dividerHeight||1):b.dividerHeight}px ${b.styles.borderStyle||'solid'} ${b.color}`;line.style.width=(device==='mobile'?(b.mobile.dividerWidth||design.mobile.dividerWidth||b.dividerWidth):b.inherit.includes('dividerWidth')?(design.styles.dividerWidth||100):b.dividerWidth)+'%';line.style.margin='auto';content.append(line);}
          else if(b.type==='spacer')content.style.height=`${b.height+style.padding*2}px`;
          else if(b.type==='button'){const bt=el('span',b.text,'designer-email-button');Object.assign(bt.style,{background:b.inherit.includes('background')?s.button:b.background,color:b.styles.color||s.buttonText,borderRadius:`${b.inherit.includes('radius')?(s.buttonRadius||4):b.radius}px`,border:b.styles.borderWidth?`${b.styles.borderWidth}px ${b.styles.borderStyle||'solid'} ${b.styles.borderColor||'#24292d'}`:'none'});content.append(bt);}
          else content.append(rich());
          if(b.condition.rules.length)bn.append(el('span','Dynamic content','designer-dynamic-badge'));bn.append(content);
          if(isSelected){const bar=el('div',undefined,'designer-inline-tools');[['↑','Move up',()=>move(-1)],['↓','Move down',()=>move(1)],['⧉','Duplicate',duplicate],['×','Delete',remove]].forEach(([icon,label,fn])=>{const bt=button(icon,e=>{e.stopPropagation();fn();});bt.setAttribute('aria-label',label);bar.append(bt);});bn.append(bar);}cn.append(bn);
        });if(!col.length)cn.append(el('div','Drop content here','designer-drop-empty'));rn.append(cn);
      });canvas.append(rn);
    });
    const footer=el('div',undefined,'designer-email-footer');footer.tabIndex=0;footer.setAttribute('role','button');footer.setAttribute('aria-label','Edit footer');footer.append(el('p',design.footer.text),el('p','*|LIST:COMPANY|*\n*|LIST:ADDRESS|*'),el('p','Update your preferences or unsubscribe.'));Object.assign(footer.style,{background:design.footer.styles.background||s.body,color:design.footer.styles.color||'#60676b',padding:(design.footer.styles.padding??32)+'px'});footer.onclick=()=>{panel='footer';renderTools();};footer.onkeydown=e=>{if(e.key==='Enter')footer.click();};canvas.append(footer);
  }
  function render(){renderTools();renderCanvas();updateHistory();}
  function setDevice(value){device=value;$$('[data-device]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.device===device)));render();}
  async function loadLibrary(){
    const data=await request('design_library',{type:libraryType,search:$('#design-library-search').value,page:String(libraryPage)});const host=$('#design-library-items');host.replaceChildren();
    data.items.forEach(item=>{const card=el('article',undefined,'designer-library-card');const preview=el('div',undefined,'designer-library-thumbnail');card.append(preview,el('strong',item.name));
      const use=button('Insert',run(async()=>{const response=await request('design_library_get',{item_id:String(item.id)});$('#design-library-dialog').close();libraryChoose(response.item.data);}));
      card.append(use,button('Rename',()=>nameDialog('Rename '+libraryType,item.name,async name=>{await request('design_library_operation',{item_id:String(item.id),item_version:String(item.version),operation:'rename',name});await loadLibrary();})),button('Delete',()=>confirmDesignAction('Delete '+libraryType+'?','Existing emails keep their own copy of this content.',async()=>{await request('design_library_operation',{item_id:String(item.id),item_version:String(item.version),operation:'delete'});await loadLibrary();})));host.append(card);
      run(async()=>{const response=await request('design_library_get',{item_id:String(item.id)});if(!card.isConnected)return;if(libraryType==='image')preview.append(imageNode(response.item.data));else preview.append(el('span','▤ '+response.item.data.rows[0].name));})();
    });if(!data.items.length)host.append(el('p','No saved '+(libraryType==='image'?'images':'sections')+' found.'));
    const pages=$('#design-library-pages');pages.replaceChildren();const previous=button('Previous',()=>{libraryPage--;run(loadLibrary)();}),next=button('Next',()=>{libraryPage++;run(loadLibrary)();});previous.disabled=libraryPage<=1;next.disabled=libraryPage*20>=data.total;pages.append(previous,el('span',`${data.total} items · Page ${libraryPage}`),next);
  }
  function openLibrary(type,choose){libraryType=type;libraryChoose=choose;libraryPage=1;$('#design-library-title').textContent=type==='image'?'Content studio':'Saved sections';$('#design-library-search').value='';$('#design-library-dialog').showModal();run(loadLibrary)();}
  let libraryTimer;$('#design-library-search').oninput=()=>{clearTimeout(libraryTimer);libraryTimer=setTimeout(()=>{libraryPage=1;run(loadLibrary)();},250);};
  function renderComments(){
    tools.append(el('h2','Comments'));const host=el('div',undefined,'designer-comments');tools.append(host);const compose=el('form');const body=el('textarea');body.rows=5;body.maxLength=4000;body.required=true;body.setAttribute('aria-label','Comment');const parent=el('input');parent.type='hidden';parent.value='';const context=el('p',blockAt()?'Commenting on selected block':'Commenting on this email','designer-panel-help');const submit=el('button','Add comment','primary');submit.type='submit';compose.append(context,body,parent,submit);tools.append(compose);
    const populate=comments=>{host.replaceChildren();if(!comments.length)host.append(el('p','No comments yet.'));comments.filter(c=>!c.parent_id).forEach(comment=>{const thread=el('article',undefined,'designer-comment-thread');thread.classList.toggle('resolved',!!Number(comment.resolved));thread.append(el('strong',comment.actor_label),el('time',comment.created_at+' UTC'),el('p',comment.body));if(comment.block_key)thread.append(el('small','Block: '+comment.block_key));comments.filter(c=>String(c.parent_id)===String(comment.id)).forEach(reply=>{const child=el('div',undefined,'designer-comment-reply');child.append(el('strong',reply.actor_label),el('p',reply.body));thread.append(child);});thread.append(button('Reply',()=>{parent.value=comment.id;context.textContent='Replying to '+comment.actor_label;body.focus();}),button(Number(comment.resolved)?'Reopen':'Resolve',run(async()=>{const result=await request('design_comment_resolve',{comment_id:String(comment.id),comment_version:String(comment.version),resolved:Number(comment.resolved)?'0':'1'});populate(result.comments);})));host.append(thread);});};
    compose.onsubmit=run(async e=>{e.preventDefault();submit.disabled=true;try{const result=await request('design_comment_add',{body:body.value,parent_id:parent.value,block_key:blockAt()?.id||''});body.value='';parent.value='';context.textContent='Commenting on this email';populate(result.comments);}finally{submit.disabled=false;}});
    run(async()=>{const data=await request('design_comments');if(host.isConnected)populate(data.comments);})();
  }
  const previewParams=()=>({design:JSON.stringify(design),person_id:$('#preview-person').value,live:$('#preview-live').checked?'1':'0'});
  const refreshPreview=run(async()=>{const data=await request('design_preview',previewParams());frameHtml($('#design-preview-dialog iframe'),data.html);$('#preview-notice').textContent=[...(data.warnings||[]),...data.report.warnings].join(' ')||'Preview is ready. Email clients may render some features differently.';return data;});
  function openPreview(){closeMenus();$('#design-preview-dialog').showModal();refreshPreview();run(loadPeople)();}
  async function loadPeople(){const select=$('#preview-person'),previous=select.value;const data=await request('design_people',{search:$('#preview-search').value});select.replaceChildren();const blank=el('option','Choose a connection');blank.value='';select.append(blank);data.people.forEach(person=>{const option=el('option',`${person.first_name} ${person.last_name} — ${person.email}`.trim());option.value=person.id;select.append(option);});if([...select.options].some(o=>o.value===previous))select.value=previous;}
  let peopleTimer;$('#preview-search').oninput=()=>{clearTimeout(peopleTimer);peopleTimer=setTimeout(()=>run(loadPeople)(),250);};$('#preview-refresh').onclick=refreshPreview;$('#preview-live').onchange=$('#preview-person').onchange=refreshPreview;
  $$('[data-preview-device]').forEach(b=>b.onclick=()=>{const frame=$('#design-preview-dialog iframe');if(b.dataset.previewDevice==='dark'){frame.classList.toggle('simulate-dark');$('#preview-notice').textContent='Approximate dark-mode simulation. Actual inbox rendering requires delivery testing.';}else frame.style.width=b.dataset.previewDevice==='mobile'?'375px':'100%';});
  const openPlain=run(async()=>{closeMenus();const data=await request('design_preview',{design:JSON.stringify({...design,plainText:null})});$('#plain-auto').checked=design.plainText===null||design.plainText===undefined;$('#plain-content').value=design.plainText??data.text;$('#plain-content').disabled=$('#plain-auto').checked;$('#design-plain-dialog').showModal();});
  $('#plain-auto').onchange=()=>$('#plain-content').disabled=$('#plain-auto').checked;
  $('#plain-regenerate').onclick=run(async()=>{const data=await request('design_preview',{design:JSON.stringify({...design,plainText:null})});$('#plain-content').value=data.text;});
  $('#plain-apply').onclick=()=>{mutate(()=>design.plainText=$('#plain-auto').checked?null:$('#plain-content').value);$('#design-plain-dialog').close();};
  const openHistory=run(async()=>{closeMenus();const data=await request('design_history');const host=$('#design-history-items');host.replaceChildren();data.revisions.forEach(revision=>{const row=el('article',undefined,'designer-history-row');row.append(el('strong','Version '+revision.version),el('span',revision.created_at+' UTC · '+revision.actor_label),button('Restore',()=>confirmDesignAction('Restore version '+revision.version+'?','Your current edits will be saved first, then this version will be restored as a new version.',async()=>{if(dirty&&!(await save(false)))return;const result=await request('design_restore',{revision_id:String(revision.id)});history();design=result.design;version=result.version;dirty=false;normalize();selected=null;selectedRow=null;status.textContent='Version restored';try{localStorage.removeItem(recoveryKey);}catch{}render();$('#design-history-dialog').close();})));host.append(row);});if(!data.revisions.length)host.append(el('p','Save this email to begin its version history.'));$('#design-history-dialog').showModal();});
  const openLinks=run(async()=>{closeMenus();const data=await request('design_links',previewParams());const host=$('#design-link-report');host.replaceChildren();data.report.warnings.forEach(w=>host.append(el('p',w,'designer-check-warning')));data.report.links.forEach(link=>{const row=el('article',undefined,'designer-link-row');const result=el('span',link.status);row.append(el('strong',link.label||'Link'),el('code',link.url),result);if(link.status==='Ready to check'){const check=button('Check link',run(async()=>{check.disabled=true;try{const response=await request('design_probe_link',{url:link.url});result.textContent=response.status;}finally{check.disabled=false;}}));row.append(check);}host.append(row);});if(!data.report.links.length)host.append(el('p','No links found.'));host.append(el('p','Public HTTPS links are checked individually. Redirects and provider-resolved links need review.','designer-panel-help'));$('#design-links-dialog').showModal();});
  const openCode=run(async()=>{closeMenus();const data=await request('design_source',{design:JSON.stringify(design)});$('#design-code-source').value=data.source;$('#design-code-warnings').replaceChildren();$('#design-code-dialog').showModal();});
  $('#design-code-check').onclick=run(async()=>{const data=await request('design_code_validate',{code:$('#design-code-source').value});const host=$('#design-code-warnings');host.replaceChildren();(data.warnings.length?data.warnings:['Validation complete. Scripts cannot run in the preview.']).forEach(w=>host.append(el('p',w)));Object.keys(data.regions).forEach(name=>host.append(el('p','Editable region: '+name)));});
  $('#design-code-apply').onclick=run(async()=>{const code=$('#design-code-source').value;await request('design_code_validate',{code});const apply=()=>{mutate(()=>{design.mode='code';design.code=code;design.areas={};selected=null;panel='add';});$('#design-code-dialog').close();};if(design.mode!=='code')confirmDesignAction('Switch to code?','This replaces the visual layout with custom HTML. The previous visual version remains available in Undo and saved history.',apply);else apply();});
  $('#design-link-dialog form').onsubmit=e=>{e.preventDefault();const url=$('#design-link-dialog input').value;if(!/^(https:\/\/[^\s<>"]+|mailto:[^\s<>"]+|tel:[+0-9 ()-]+|#[a-zA-Z][a-zA-Z0-9_-]*|\*\|(UNSUB|UPDATE_PROFILE|ARCHIVE|SURVEY:[a-f0-9]{64}|POLL:[a-f0-9]{64}:[0-9]{1,2})\|\*)$/i.test(url)){error('Use an HTTPS, email, telephone, or anchor link.');return;}const rich=$('.designer-rich-text');if(rich&&richRange){rich.focus();const selection=window.getSelection();selection.removeAllRanges();selection.addRange(richRange);document.execCommand('createLink',false,$('#design-link-dialog input').value);}$('#design-link-dialog').close();};
  $('#design-link-remove').onclick=()=>{const rich=$('.designer-rich-text');if(rich&&richRange){rich.focus();const selection=window.getSelection();selection.removeAllRanges();selection.addRange(richRange);document.execCommand('unlink',false,null);}$('#design-link-dialog').close();};
  function openCrop(target){cropTarget=target;cropImage=new Image();cropImage.onload=()=>{['crop-zoom','crop-x','crop-y'].forEach(id=>$('#'+id).value=id==='crop-zoom'?'1':'50');$('#crop-aspect').value='original';drawCrop();$('#design-crop-dialog').showModal();};cropImage.src=target.image;}
  function cropDimensions(){const aspect=$('#crop-aspect').value==='original'?cropImage.naturalWidth/cropImage.naturalHeight:Number($('#crop-aspect').value),zoom=Number($('#crop-zoom').value);let width=cropImage.naturalWidth,height=width/aspect;if(height>cropImage.naturalHeight){height=cropImage.naturalHeight;width=height*aspect;}width/=zoom;height/=zoom;return {x:(cropImage.naturalWidth-width)*Number($('#crop-x').value)/100,y:(cropImage.naturalHeight-height)*Number($('#crop-y').value)/100,width,height,aspect};}
  function drawCrop(){if(!cropImage)return;const c=$('#design-crop-canvas'),dims=cropDimensions();c.width=600;c.height=Math.round(600/dims.aspect);const ctx=c.getContext('2d');ctx.clearRect(0,0,c.width,c.height);ctx.drawImage(cropImage,dims.x,dims.y,dims.width,dims.height,0,0,c.width,c.height);}
  ['crop-aspect','crop-zoom','crop-x','crop-y'].forEach(id=>$('#'+id).oninput=drawCrop);
  $('#crop-apply').onclick=()=>{const image=$('#design-crop-canvas').toDataURL('image/png');if(image.length>933000){error('The cropped image is too large. Use a smaller image.');return;}mutate(()=>cropTarget.image=image);$('#design-crop-dialog').close();};
  function closeMenus(){$$('.designer-save-menu').forEach(menu=>menu.open=false);}
  $('#design-undo').onclick=()=>travel(undo,redo);$('#design-redo').onclick=()=>travel(redo,undo);
  document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'&&!e.target.matches('input,textarea,[contenteditable]')){e.preventDefault();e.shiftKey?travel(redo,undo):travel(undo,redo);}});
  $$('.designer-rail [data-panel]').forEach(b=>b.onclick=()=>{panel=b.dataset.panel;selectedRow=null;if(panel!=='comments')selected=null;render();});$$('[data-device]').forEach(b=>b.onclick=()=>setDevice(b.dataset.device));
  $('#design-save').onclick=()=>save(true);$('#design-save-only').onclick=()=>{closeMenus();save(false);};$('#design-preview').onclick=openPreview;$('#design-links').onclick=openLinks;$('#design-plain').onclick=openPlain;$('#design-history').onclick=openHistory;$('#design-code').onclick=openCode;$('#design-comments').onclick=()=>{panel='comments';renderTools();};$('#design-test').onclick=()=>{closeMenus();$('#design-test-dialog').showModal();};
  let testRequestKey='';
  $('#design-test').onclick=()=>{closeMenus();testRequestKey=[...crypto.getRandomValues(new Uint8Array(32))].map(n=>n.toString(16).padStart(2,'0')).join('');$('#design-test-status').textContent='';$('#design-test-dialog').showModal();};
  $('#design-test-form').onsubmit=run(async e=>{e.preventDefault();const submit=$('button.primary',e.target),notice=$('#design-test-status');submit.disabled=true;try{if(dirty&&!(await save(false)))return;const data=await request('design_test',{...Object.fromEntries(new FormData(e.target)),request_key:testRequestKey});notice.textContent=`${data.queued} test email(s) queued. Check Email delivery → Activity for the relay receipt.`;}catch(err){notice.textContent=err.message;}finally{submit.disabled=false;}});
  $('#design-save-template').onclick=run(async()=>{closeMenus();dirty=true;if(await save(false))$('#design-template-dialog').showModal();});
  $('#design-template-form').onsubmit=run(async e=>{e.preventDefault();const submit=$('button.primary',e.target);submit.disabled=true;try{if(dirty&&!(await save(false)))return;await request('design_template',{name:e.target.elements.name.value,folder:e.target.elements.folder.value});$('#design-template-dialog').close();status.textContent='Template saved';}finally{submit.disabled=false;}});
  Object.values(config.starters).forEach(entry=>$('#design-starters').append(button(entry.name,()=>confirmDesignAction('Change template?','Your current layout and content will be replaced. You can undo this change.',()=>{mutate(()=>{design=structuredClone(entry.design);selected=null;selectedRow=null;panel='add';});$('#design-change-dialog').close();}))));
  config.saved.forEach(entry=>$('#design-saved').append(button(entry.name,()=>confirmDesignAction('Change template?','Your current layout and content will be replaced. You can undo this change.',()=>{mutate(()=>{design=structuredClone(entry.design);selected=null;selectedRow=null;panel='add';});$('#design-change-dialog').close();}))));if(!config.saved.length)$('#design-saved').append(el('p','No saved templates yet.'));
  $('#design-change').onclick=()=>{closeMenus();$('#design-change-dialog').showModal();};
  let recovered;try{recovered=JSON.parse(localStorage.getItem(recoveryKey)||'null');if(recovered&&Date.now()-recovered.time>7*86400000){localStorage.removeItem(recoveryKey);recovered=null;}}catch{}
  if(recovered?.design&&JSON.stringify(recovered.design)!==JSON.stringify(design)){$('#design-recovery').hidden=false;$('#design-recover').onclick=()=>confirmDesignAction('Restore recovered draft?',recovered.version===version?'Restore edits that were not saved before this tab closed.':'This recovered draft is based on an older saved version. Review it before saving over the latest design.',()=>{mutate(()=>{design=structuredClone(recovered.design);selected=null;selectedRow=null;});$('#design-recovery').hidden=true;});}
  $('#design-discard-recovery').onclick=()=>{try{localStorage.removeItem(recoveryKey);}catch{}$('#design-recovery').hidden=true;};
  window.addEventListener('beforeunload',e=>{if(dirty||savePromise){recover();e.preventDefault();e.returnValue='';}});
  render();
})();
