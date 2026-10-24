import { renderAudiencePagination } from './audience-pagination.mjs';
import { enhanceColorInput } from './audience-color-picker.mjs';
import { mountRichText, setRichContent, normalizeRichLink } from './audience-designer-richtext.mjs';
import { mountBlockDropSpace } from './audience-designer-drop.mjs';
import { renderDesignStylePanel } from './audience-designer-styles.mjs';
import { designFontChoices, selectDesignFont } from './audience-designer-fonts.mjs';
import { normalizeEmailDesignMaps, moveEmailBlock } from './audience-design-model.mjs';
import { templatePreviewBoundary } from './audience-template-preview.mjs';
import { enhanceAudienceFileInput, setFileDropStatus } from './audience-file-drop.mjs';
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
      let observer, pending=false;
      const schedule=()=>{if(!pending){pending=true;requestAnimationFrame(()=>{pending=false;fit();});}};
      const fit=()=>{try{
        const doc=frame.contentDocument;if(!doc?.body)return;
        const style=frame.contentWindow.getComputedStyle(doc.body);
        const height=Math.ceil(Math.max(doc.body.scrollHeight,doc.body.getBoundingClientRect().height)+(parseFloat(style.marginTop)||0)+(parseFloat(style.marginBottom)||0))+2;
        const box=frame.parentElement,card=frame.closest('.template-card'),list=frame.closest('.template-gallery')?.classList.contains('template-list');
        const scale=Math.min(1,box.clientWidth/600,list?100/height:1);
        frame.style.height=height+'px';frame.style.transform=`scale(${scale})`;
        if(list){box.style.minHeight='';box.style.height='';frame.style.clipPath='';card.style.height='';card.style.maxHeight='';return;}
        const cardStyle=getComputedStyle(card),boxStyle=getComputedStyle(box);
        const maxHeight=Math.floor(card.getBoundingClientRect().width*2);
        const header=[...card.children].filter(node=>node!==box).reduce((sum,node)=>sum+node.getBoundingClientRect().height,0);
        const borders=(parseFloat(cardStyle.borderTopWidth)||0)+(parseFloat(cardStyle.borderBottomWidth)||0);
        const previewBorders=(parseFloat(boxStyle.borderTopWidth)||0)+(parseFloat(boxStyle.borderBottomWidth)||0);
        const available=Math.max(0,Math.floor(maxHeight-header-borders-previewBorders));
        const cropped=height*scale>available;
        const end=cropped?templatePreviewBoundary(doc,available/scale):height;
        const previewHeight=available+previewBorders;
        card.style.height=card.style.maxHeight=maxHeight+'px';
        box.style.minHeight=box.style.height=previewHeight+'px';
        frame.style.clipPath=cropped?`inset(0 0 ${height-end}px)` : '';
      }catch(_){/* A protected preview remains inert. */}};
      frame.addEventListener('load',()=>{observer?.disconnect();const doc=frame.contentDocument;if(!doc?.body)return;observer=new ResizeObserver(schedule);observer.observe(doc.body);doc.addEventListener('load',schedule,true);doc.fonts?.ready.then(schedule);schedule();});
      let width=0;new ResizeObserver(()=>{const next=frame.parentElement.clientWidth;if(next!==width){width=next;schedule();}}).observe(frame.parentElement);
      const headerObserver=new ResizeObserver(schedule);for(const node of frame.closest('.template-card').children)if(node!==frame.parentElement)headerObserver.observe(node);
      $$('[data-template-layout]').forEach(button=>button.addEventListener('click',schedule));
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
    let category = 'All', galleryPage=1, gallerySize=25, matches=[], dragging=null, dropAfter=false, dropTarget=null, dragPoint=null, pointerDrag=null, scrollFrame=0, scrollTime=0, dragProxy=null, dropMarker=null;
    const sortControl=$('[data-template-sort]'), orderStatus=$('[data-template-order-status]');
    const orderKey=`template-order:${document.body.dataset.audienceKey || 'default'}:${gallery.dataset.templateTab}`;
    const initialCards=$$('.template-card',gallery), validKeys=new Set(initialCards.map(card=>card.dataset.templateKey));
    const selectionForm=$('[data-template-selection]'),selectPage=$('[data-template-select-page]'),selectItems=$$('[data-template-select]',gallery);
    function updateTemplateSelection(){
      if(!selectionForm)return;
      const visible=selectItems.filter(input=>!input.closest('.template-card').hidden);
      selectItems.forEach(input=>{if(input.closest('.template-card').hidden)input.checked=false;});
      const count=visible.filter(input=>input.checked).length;
      $('[data-template-selection-count]').textContent=`${count} selected`;
      selectPage.checked=visible.length>0&&count===visible.length;selectPage.indeterminate=count>0&&count<visible.length;selectPage.disabled=visible.length===0;
      $('[data-template-move-selected]').disabled=count===0;
    }
    selectItems.forEach(input=>input.addEventListener('change',updateTemplateSelection));
    selectPage?.addEventListener('change',()=>{selectItems.forEach(input=>{input.checked=selectPage.checked&&!input.closest('.template-card').hidden;});updateTemplateSelection();});
    let stored;try{stored=JSON.parse(localStorage.getItem(orderKey));}catch(_){/* Optional browser preference. */}
    let customOrder=Array.isArray(stored?.order)?[...new Set(stored.order.filter(key=>validKeys.has(key)))]:[];
    if(['custom','newest','name','oldest'].includes(stored?.sort))sortControl.value=stored.sort;
    const saveOrder=()=>{try{localStorage.setItem(orderKey,JSON.stringify({order:customOrder,sort:sortControl.value}));return true;}catch(_){return false;}};
    const filter = () => {
      const q = $('[data-template-search]').value.toLowerCase(); const folder = $('[data-template-folder]')?.value || '';
      const cards = $$('.template-card', gallery); const type=$('[data-template-type]')?.value||''; const sort = sortControl.value;
      const ranks=new Map(customOrder.map((key,index)=>[key,index]));
      cards.sort((a,b) => sort==='custom' ? (ranks.get(a.dataset.templateKey)??Infinity)-(ranks.get(b.dataset.templateKey)??Infinity)||0 : sort === 'name' ? a.dataset.templateName.localeCompare(b.dataset.templateName) : (sort === 'oldest' ? 1 : -1) * a.dataset.templateDate.localeCompare(b.dataset.templateDate));
      matches=cards.filter(c=>c.dataset.templateName.includes(q)&&(category==='All'||category===c.dataset.templateCategoryValue)&&(!folder||(folder==='unfiled'?!c.dataset.templateFolderValue:folder===c.dataset.templateFolderValue))&&(!type||type===c.dataset.templateMode));
      const maxPage=Math.max(1,Math.ceil(matches.length/gallerySize));galleryPage=Math.min(maxPage,Math.max(1,galleryPage));const visible=new Set(matches.slice((galleryPage-1)*gallerySize,galleryPage*gallerySize));cards.forEach(c=>{c.hidden=!visible.has(c);gallery.append(c);});
      $('[data-template-empty]').hidden=matches.length>0;
      updateTemplateSelection();
      renderAudiencePagination($('#template-pagination'), {total:matches.length,page:galleryPage,pageSize:gallerySize,label:'Template pages',onChange:({page,pageSize})=>{galleryPage=page;gallerySize=pageSize;filter();}});

    };
    const resetFilter=()=>{galleryPage=1;filter();};$('[data-template-search]').oninput=resetFilter;sortControl.onchange=()=>{resetFilter();saveOrder();};
    if($('[data-template-type]'))$('[data-template-type]').onchange=resetFilter;
    if ($('[data-template-folder]')) $('[data-template-folder]').onchange = () => {
      const url=new URL(location.href),folder=$('[data-template-folder]').value;
      if(folder)url.searchParams.set('folder',folder);else url.searchParams.delete('folder');
      window.history.replaceState(window.history.state,'',url);resetFilter();
    };
    $$('[data-template-category]').forEach(b => b.onclick = () => { category = b.dataset.templateCategory;galleryPage=1; $$('[data-template-category]').forEach(x => x.setAttribute('aria-pressed', String(x === b))); filter(); });
    $$('[data-template-layout]').forEach(b => b.onclick = () => { gallery.classList.toggle('template-list', b.dataset.templateLayout === 'list'); $$('[data-template-layout]').forEach(x => x.setAttribute('aria-pressed', String(x === b))); });
    $('[data-template-all-filters]')?.addEventListener('click',()=>{$('[data-template-category-filter]').value=category;$('[data-template-type-filter]').value=$('[data-template-type]').value;$('#template-filters-dialog').showModal();});
    $('[data-template-apply-filters]')?.addEventListener('click',()=>{category=$('[data-template-category-filter]').value;$('[data-template-type]').value=$('[data-template-type-filter]').value;$$('[data-template-category]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.templateCategory===category)));$('#template-filters-dialog').close();resetFilter();});
    filter();
    const clearDrop=()=>initialCards.forEach(card=>card.classList.remove('template-dragging'));
    const moveCard=(card,target,after)=>{
      if(card===target)return;
      const order=$$('.template-card',gallery).filter(item=>item!==card).map(item=>item.dataset.templateKey);
      order.splice(order.indexOf(target.dataset.templateKey)+(after?1:0),0,card.dataset.templateKey);
      customOrder=order;sortControl.value='custom';
      const matchKeys=new Set(matches.map(item=>item.dataset.templateKey)),filteredOrder=order.filter(key=>matchKeys.has(key));
      galleryPage=Math.floor(filteredOrder.indexOf(card.dataset.templateKey)/20)+1;
      const saved=saveOrder();filter();
      orderStatus.textContent=`${$('h3',card).textContent} moved to position ${filteredOrder.indexOf(card.dataset.templateKey)+1} of ${filteredOrder.length}.${saved?'':' Browser storage is unavailable; this order lasts for this visit.'}`;
      $('[data-template-drag]',card).focus();
    };
    const dragTop=()=>Math.max(0,Math.min(innerHeight/2,$('.audience-topbar')?.getBoundingClientRect().bottom||0));
    const locateDrop=()=>{
      if(!dragging||!dragPoint)return;
      const bounds=gallery.getBoundingClientRect(),top=dragTop(),point=dragPoint,list=gallery.classList.contains('template-list');
      dropTarget=null;dropMarker.hidden=true;
      if(point.x<bounds.left-24||point.x>bounds.right+24||point.y<Math.max(bounds.top-24,top)||point.y>Math.min(bounds.bottom+24,innerHeight))return;
      let nearest=Infinity,rect;
      matches.filter(card=>!card.hidden).forEach(card=>{
        const box=card.getBoundingClientRect();if(box.bottom<top||box.top>innerHeight)return;
        const dx=Math.max(box.left-point.x,0,point.x-box.right),dy=Math.max(box.top-point.y,0,point.y-box.bottom),distance=dx*dx+dy*dy;
        if(distance<nearest){nearest=distance;dropTarget=card;rect=box;}
      });
      if(!dropTarget||dropTarget===dragging){dropTarget=null;return;}
      dropAfter=list?point.y>rect.top+rect.height/2:point.x>rect.left+rect.width/2;
      if(list){
        dropMarker.style.left=rect.left+'px';dropMarker.style.top=(dropAfter?rect.bottom:rect.top)+'px';dropMarker.style.width=rect.width+'px';dropMarker.style.height='4px';
      }else{
        const gap=Number.parseFloat(getComputedStyle(gallery).columnGap)||24,markerTop=Math.max(rect.top,top);
        dropMarker.style.left=((dropAfter?rect.right+gap/2:rect.left-gap/2)-2)+'px';dropMarker.style.top=markerTop+'px';dropMarker.style.width='4px';dropMarker.style.height=Math.max(0,Math.min(rect.bottom,innerHeight)-markerTop)+'px';
      }
      dropMarker.hidden=false;
    };
    const scrollDrag=time=>{
      if(!dragging||!dragPoint)return;
      const elapsed=Math.min(32,scrollTime?time-scrollTime:16);scrollTime=time;
      const edge=96,top=dragTop(),y=dragPoint.y;
      const speed=y<top+edge?-Math.min(1,(top+edge-y)/edge):y>innerHeight-edge?Math.min(1,(y-innerHeight+edge)/edge):0;
      if(speed){document.scrollingElement.scrollTop+=speed*elapsed;locateDrop();}
      scrollFrame=requestAnimationFrame(scrollDrag);
    };
    const stopDrag=commit=>{
      const source=dragging,target=dropTarget,after=dropAfter;
      pointerDrag=null;dragging=null;dragPoint=null;dropTarget=null;cancelAnimationFrame(scrollFrame);scrollTime=0;dragProxy?.remove();dropMarker?.remove();dragProxy=null;dropMarker=null;clearDrop();
      if(commit&&source&&target)moveCard(source,target,after);
    };
    initialCards.forEach(card=>{
      const handle=$('[data-template-drag]',card);
      handle.addEventListener('dragstart',event=>event.preventDefault());
      handle.addEventListener('pointerdown',event=>{
        if(event.button!==0||!event.isPrimary||pointerDrag)return;
        event.preventDefault();handle.focus();pointerDrag={id:event.pointerId,card,x:event.clientX,y:event.clientY};handle.setPointerCapture(event.pointerId);
      });
      handle.addEventListener('pointermove',event=>{
        if(!pointerDrag||pointerDrag.id!==event.pointerId)return;
        dragPoint={x:event.clientX,y:event.clientY};
        if(!dragging&&Math.hypot(event.clientX-pointerDrag.x,event.clientY-pointerDrag.y)<5)return;
        event.preventDefault();
        if(!dragging){
          dragging=card;card.classList.add('template-dragging');dragProxy=el('div',$('h3',card).textContent,'template-drag-proxy');dropMarker=el('div',undefined,'template-drop-marker');
          dragProxy.setAttribute('aria-hidden','true');dropMarker.setAttribute('aria-hidden','true');document.body.append(dragProxy,dropMarker);scrollFrame=requestAnimationFrame(scrollDrag);
        }
        dragProxy.style.transform=`translate(${Math.min(event.clientX+16,innerWidth-240)}px,${Math.min(event.clientY+16,innerHeight-70)}px)`;locateDrop();
      });
      handle.addEventListener('pointerup',event=>{if(pointerDrag?.id===event.pointerId)stopDrag(true);});
      handle.addEventListener('pointercancel',event=>{if(pointerDrag?.id===event.pointerId)stopDrag(false);});
      handle.addEventListener('lostpointercapture',event=>{if(pointerDrag?.id===event.pointerId)stopDrag(false);});
      handle.addEventListener('keydown',event=>{
        if(event.altKey||event.ctrlKey||event.metaKey||!['ArrowLeft','ArrowUp','ArrowRight','ArrowDown','Home','End'].includes(event.key))return;
        event.preventDefault();const current=matches.indexOf(card),next=event.key==='Home'?0:event.key==='End'?matches.length-1:current+(['ArrowRight','ArrowDown'].includes(event.key)?1:-1);
        if(next>=0&&next<matches.length&&next!==current)moveCard(card,matches[next],next>current);
      });
    });
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&pointerDrag){event.preventDefault();stopDrag(false);}});
    $$('[data-template-operation]').forEach(b => b.onclick = async () => {
      const d = $('#template-operation'); const r = JSON.parse(b.dataset.templateRecord); const op = b.dataset.templateOperation;
      if(op==='delete'&&!(await window.DnrAdminUnlock?.request()))return;
      b.closest('details')?.removeAttribute('open');
      ['id','version'].forEach(k => $(`[name=${k}]`, d).value = r[k]); $('[name=operation]',d).value = op;
      $('#template-operation-title').textContent = {rename:'Rename template',folder:'Move to folder',replicate:'Duplicate template',delete:'Delete template?'}[op];
      const label = $('[data-template-input-label]',d); label.hidden = op !== 'rename';
      const input = $('input', label); input.value = r.name; input.disabled = op !== 'rename'; input.required = op === 'rename';
      const folderLabel = $('[data-template-folder-label]',d); folderLabel.hidden = op !== 'folder';
      const folder = $('select',folderLabel); folder.disabled = op !== 'folder'; folder.value = r.folder;
      $('[data-template-delete-note]',d).hidden = op !== 'delete'; const submit=$('button.primary',d);submit.textContent = {rename:'Save',folder:'Move',replicate:'Duplicate',delete:'Permanently delete'}[op];submit.toggleAttribute('data-admin-unlock-required',op==='delete'); d.showModal();
    });
    const operationForm=$('[data-template-operation-form]');let deleteApproved=false;
    operationForm?.addEventListener('submit',async event=>{
      if(operationForm.elements.operation.value!=='delete'||deleteApproved){deleteApproved=false;return;}
      event.preventDefault();
      if(await window.DnrAdminUnlock?.request()){deleteApproved=true;operationForm.requestSubmit();}
    });
  }
  $('[data-template-import]')?.addEventListener('click',()=>$('#template-import-dialog').showModal());
  $('[data-template-legacy]')?.addEventListener('click',()=>$('#template-legacy-dialog').showModal());
  $$('[data-import-tab]').forEach(button=>button.onclick=()=>{const type=button.dataset.importTab;$$('[data-import-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));$('[data-import-code]').hidden=type!=='paste';$('[data-import-file]').hidden=type==='paste';const input=$('[data-import-file] input');input.value='';input.accept=type==='zip'?'.zip':'.html,.htm';input.dataset.fileMaxBytes=type==='zip'?'5000000':'1000000';setFileDropStatus(input);const hint=input.closest('[data-file-drop]')?.querySelector(':scope>span');if(hint)hint.textContent=type==='zip'?'ZIP · up to 5 MB':'HTML · up to 1 MB';$('[data-import-code] textarea').value='';$('[data-import-help]').textContent=type==='zip'?'Include one HTML file and local images. Maximum 5 MB, 100 files.':type==='html'?'Choose an HTML file up to 1 MB.':'Paste your HTML. Add named mc:edit regions to edit content visually.';});
  const configNode=$('#designer-config');if(!configNode)return;
  const config=JSON.parse(configNode.textContent), canvas=$('#email-canvas'), tools=$('#designer-tools'), status=$('#design-status');
  let design=structuredClone(config.design),version=config.version,selected=null,selectedRow=null,panel='add',addTab='content',sectionTab='manage',blockTab='content',device='desktop',styleArea='',styleDraft=null,dirty=false,timer,savePromise=null,drag=null,confirmAction=null,nameAction=null,libraryType='image',libraryPage=1,librarySize=25,libraryChoose=null,richRange=null,disposeRichText=null,cropImage=null,cropTarget=null,codeSequence=0;
  const undo=[],redo=[];const uid=prefix=>prefix+'-'+crypto.randomUUID();
  const error=message=>{$('#design-error').textContent=message;$('#design-error').hidden=!message;$$('.designer-dialog-error').forEach(n=>n.remove());const dialog=$$('dialog[open]').at(-1);if(message&&dialog){const notice=el('p',message,'designer-dialog-error');notice.setAttribute('role','alert');dialog.prepend(notice);}};
  const run=fn=>async(...args)=>{try{return await fn(...args);}catch(e){error(e.message);}};
  function normalize(){
    normalizeEmailDesignMaps(design);
    design.mode ||= 'visual';design.mobile ||= {};design.footer ||= {text:'',styles:{}};design.areas ||= {};design.rows ||= [];
    const ids=new Set();design.rows.forEach((row,r)=>{if(!row.id||ids.has(row.id))row.id=uid('section');ids.add(row.id);row.name ||= 'Section '+(r+1);row.region ||= 'body';row.styles ||= {};row.mobile ||= {};row.condition ||= {match:'all',rules:[]};row.columns.forEach(col=>col.forEach(b=>{if(!b.id||ids.has(b.id))b.id=uid('block');ids.add(b.id);b.styles ||= {};b.mobile ||= {};b.inherit ||= [];b.condition ||= {match:'all',rules:[]};b.linkDevices ??= true;b.images ||= [];b.social ||= [];b.anchor ||= '';b.caption ||= '';b.code ||= '';b.headingLevel ||= 1;b.dividerWidth ||= 100;b.dividerHeight ||= 1;b.margin ||= 0;b.imagePosition ||= 'left';b.imageWidth ||= 40;b.sharePlatforms ||= ['facebook','x','linkedin','email'];}));});
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
  const history=()=>{const snapshot=JSON.stringify(design);if(undo.at(-1)!==snapshot)undo.push(snapshot);if(undo.length>60)undo.shift();redo.length=0;};
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
  $('#design-name-dialog form').onsubmit=run(async e=>{
    e.preventDefault();const submit=$('button.primary',e.target);if(submit.disabled)return;submit.disabled=true;
    try{await nameAction($('#design-name-dialog input').value);$('#design-name-dialog').close();}finally{submit.disabled=false;}
  });
  $('#design-rename')?.addEventListener('click',()=>nameDialog('Rename template',$('[data-design-name]').textContent,async name=>{
    if(!(await save(false)))throw new Error('Save your design before renaming. Your edits remain here.');
    const result=await request('design_rename',{name});version=result.version;$('[data-design-name]').textContent=result.name;status.textContent='Saved';error('');
  }));
  const defaultBlock=type=>({id:uid('block'),type,text:{heading:'Write a compelling headline',text:'Add your text here.','boxed-text':'Highlight an important message.','image-text':'Add text to accompany your image.',button:'Learn more','image-card':'Add a caption for your image.'}[type]||'',html:'',image:'',alt:'',url:['button','video'].includes(type)?'https://example.com':'',align:type==='button'?'center':'left',padding:24,margin:0,size:type==='heading'?(design.styles.heading1||32):design.styles.fontSize,height:24,bold:false,italic:false,color:design.styles.text,background:design.styles.button,radius:design.styles.buttonRadius||4,imagePosition:'left',imageWidth:40,sharePlatforms:['facebook','x','linkedin','email'],styles:type==='boxed-text'?{background:'#f1f3f4'}:{},mobile:{},inherit:['color','size','font','lineHeight','letterSpacing','paragraphSpacing','background','radius','dividerWidth','dividerHeight'],linkDevices:true,headingLevel:1,includeToc:false,anchor:'',condition:{match:'all',rules:[]},images:type==='image-group'?[{image:'',alt:'',url:''},{image:'',alt:'',url:''}]:[],social:type==='social'?[{label:'Facebook',url:'https://facebook.com/'},{label:'Instagram',url:'https://instagram.com/'}]:[],caption:'Watch video',code:'<p>Add your HTML here.</p>',dividerWidth:design.styles.dividerWidth||100,dividerHeight:design.styles.dividerHeight||1});
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
    if(type==='textarea'||['Link URL','URL','Alt text','Section name','Anchor name'].includes(label))wrap.classList.add('designer-field-wide');
    if(options)Object.entries(options).forEach(([v,t])=>{const o=el('option',t);o.value=v;input.append(o);});else if(type!=='textarea')input.type=type;
    if(type==='checkbox')input.checked=!!value;else input.value=value??'';if(type==='textarea')input.rows=6;if(type==='number'){input.inputMode='numeric';input.step='1';}
    input.addEventListener(options||type==='checkbox'?'change':'input',()=>{if(type==='number'&&(input.value===''||!Number.isFinite(Number(input.value))))return;history();onChange(type==='number'?Number(input.value):type==='checkbox'?input.checked:input.value);changed();renderCanvas();});wrap.append(input);if(type==='color')return enhanceColorInput(input,{label}).parentElement;return wrap;
  };
  const fieldGrid=(...fields)=>{const grid=el('div',undefined,'designer-field-grid');grid.append(...fields);return grid;};
  const tabs=(options,value,fn)=>{const nav=el('div',undefined,'designer-subtabs');Object.entries(options).forEach(([key,label])=>{const b=button(label,()=>fn(key));b.setAttribute('aria-pressed',String(value===key));nav.append(b);});return nav;};
  const details=(title,open=true)=>{const d=el('details');d.open=open;d.append(el('summary',title));return d;};
  function styleField(target,key,label,type='text',options){return field(label,target[key]??(type==='color'?'#ffffff':type==='number'?0:''),v=>{target[key]=v;},type,options);}
  function blockStyle(b,row,doc=design){const design=doc,s=design.styles;const defaults={weight:b.type==='heading'?'bold':'normal',color:s.text,size:b.type==='heading'?(s['heading'+b.headingLevel]||32):b.type==='button'?(s.buttonSize||16):s.fontSize,font:s.font,lineHeight:s.lineHeight,letterSpacing:s.letterSpacing||0,paragraphSpacing:s.paragraphSpacing||16,direction:s.direction||'ltr',padding:24,margin:0,align:'left'};let style={...defaults,...design.typography[b.type==='heading'?'heading'+b.headingLevel:'paragraph'],...design.regions[row.region||'body'],...row.styles};['color','size','padding','margin','align'].forEach(k=>{if(!b.inherit.includes(k))style[k]=b[k];});style={...style,...b.styles};if(device==='mobile'){Object.entries(design.mobile).forEach(([k,v])=>{if(b.inherit.includes(k)&&!(k==='size'&&['heading','button'].includes(b.type)))style[k]=v;});if(b.inherit.includes('size')&&b.type==='heading')style.size=design.mobile['heading'+b.headingLevel]??style.size;if(b.inherit.includes('size')&&b.type==='button')style.size=design.mobile.buttonSize??style.size;style={...style,...row.mobile,...(!b.linkDevices?b.mobile:{})};}return style;}
  function applyStyle(node,s,doc=design){node.style.setProperty('--paragraph-spacing',(s.paragraphSpacing??design.styles.paragraphSpacing??16)+'px');Object.assign(node.style,{padding:`${s.padding||0}px`,margin:`${s.margin||0}px`,textAlign:s.align||'left',fontFamily:doc.styles.webFont&&doc.styles.webFontDefault!==false&&s.font===doc.styles.font?'MOEDBrandFont,'+s.font:s.font||'inherit',color:s.color||'inherit',fontSize:`${s.size||16}px`,lineHeight:(s.lineHeight||160)/100,letterSpacing:`${s.letterSpacing||0}px`,direction:s.direction||'ltr'});if(s.background)node.style.background=s.background;if(s.weight)node.style.fontWeight=s.weight;if(s.fontStyle)node.style.fontStyle=s.fontStyle;if(s.borderWidth)node.style.border=`${s.borderWidth}px ${s.borderStyle||'solid'} ${s.borderColor||'#000000'}`;for(const edge of ['Top','Bottom'])if(s['border'+edge+'Width']!==undefined)node.style['border'+edge]=`${s['border'+edge+'Width']}px ${s['border'+edge+'Style']||'solid'} ${s['border'+edge+'Color']||'#d5d9dc'}`;}
  function conditionEditor(target){
    target.condition ||= {match:'all',rules:[]};const box=details('Dynamic content',target.condition.rules.length>0);box.append(el('p','Show this content only to connections who match these conditions.','designer-panel-help'));
    box.append(fieldGrid(field('Match',target.condition.match,v=>target.condition.match=v,'select',{all:'All conditions',any:'Any condition'})));
    target.condition.rules.forEach((rule,index)=>{const row=el('div',undefined,'designer-condition designer-field-grid');row.append(field('Field',rule.field,v=>{rule.field=v;if(/^(tag|group):/.test(v)){rule.operator='is';rule.value='1';}renderTools();},'select',config.dynamicFields||config.mergeTags),field('Condition',rule.operator,v=>{rule.operator=v;},'select',{is:'is',is_not:'is not',contains:'contains',not_contains:'does not contain',empty:'is empty',not_empty:'is not empty'}),field('Value',rule.value,v=>{rule.value=v;},/^(tag|group):/.test(rule.field)?'select':'text',/^(tag|group):/.test(rule.field)?{'1':'Assigned','':'Not assigned'}:undefined),button('Remove condition',()=>mutate(()=>target.condition.rules.splice(index,1))));box.append(row);});
    box.append(button('+ Add condition',()=>mutate(()=>{if(target.condition.rules.length<10)target.condition.rules.push({field:'FNAME',operator:'not_empty',value:''});})));return box;
  }
  function addImageTools(parent,item){
    const upload=el('label','Upload image','designer-field'), input=el('input');input.type='file';input.accept='image/png,image/jpeg,image/webp,image/gif';
    input.dataset.fileMaxBytes='1000000';input.onchange=run(async()=>{const file=input.files[0];if(!file)return;if(file.size>1000000)throw new Error('Choose an image up to 1 MB.');const image=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result));reader.onerror=()=>reject(new Error('Unable to read this image.'));reader.readAsDataURL(file);});mutate(()=>{item.image=image;});});upload.append(input);parent.append(upload);enhanceAudienceFileInput(input,{help:'PNG, JPEG, WebP or GIF · up to 1 MB'});
    const actions=el('div',undefined,'designer-block-actions');actions.append(button('Content studio',()=>openLibrary('image',value=>mutate(()=>{item.image=value.image;item.alt=value.alt||item.alt;item.url=value.url||item.url;}))));
    if(item.image)actions.append(button('Edit image',()=>openCrop(item)),button('Save to content studio',()=>nameDialog('Save image','Image',async name=>{await request('design_library_save',{type:'image',name,data:JSON.stringify({image:item.image,alt:item.alt||''})});status.textContent='Image saved';})));parent.append(actions,fieldGrid(field('Alt text',item.alt||'',v=>{item.alt=v;}),field('Link URL',item.url||'',v=>{item.url=v;})));
  }
  const richMarkup=setRichContent;
  function syncRichAppearance(){
    const rich=$('.designer-rich-text',tools),copy=$('.designer-block.selected [data-email-text]',canvas);
    if(!rich||!copy)return;
    const style=getComputedStyle(copy);
    for(const property of ['font-family','font-size','font-weight','font-style','line-height','letter-spacing','color','text-align','direction'])rich.style.setProperty(property,style.getPropertyValue(property));
    let background=design.styles.body;
    for(let node=copy;node&&canvas.contains(node);node=node.parentElement){
      const color=getComputedStyle(node).backgroundColor;
      if(color!=='transparent'&&!/rgba\([^)]*,\s*0\s*\)/.test(color)){background=color;break;}
    }
    rich.style.backgroundColor=background;
    const surface=rich.closest('.designer-rich-surface');if(surface)surface.style.backgroundColor=background;
    rich.style.width=copy.getBoundingClientRect().width+'px';
    rich.style.setProperty('--paragraph-spacing',style.getPropertyValue('--paragraph-spacing')||'16px');
    rich.style.setProperty('--email-link',design.styles.link);
    rich.style.setProperty('--email-link-decoration',design.styles.linkUnderline?'underline':'none');
  }
  new ResizeObserver(syncRichAppearance).observe(canvas);
  function syncRichToolbarWidth(){
    const rows=$$('.designer-rich-toolbar-row',tools),inspector=$('.designer-inspector');
    if(!rows.length||!inspector)return;
    const widths=rows.map(row=>{
      const controls=[...row.children].filter(node=>!node.hidden),gap=parseFloat(getComputedStyle(row).columnGap)||0;
      return controls.reduce((total,node)=>total+node.getBoundingClientRect().width,0)+Math.max(0,controls.length-1)*gap;
    });
    const chrome=inspector.getBoundingClientRect().width-rows[0].getBoundingClientRect().width;
    document.body.style.setProperty('--designer-toolbar-width',Math.ceil(Math.max(...widths)+chrome+2)+'px');
  }
  window.addEventListener('resize',syncRichToolbarWidth);
  function richText(b){disposeRichText=mountRichText({fonts:designFontChoices(design,config.brandFont),onFont:value=>selectDesignFont(design,config.brandFont,value),host:tools,block:b,makeButton:button,makeElement:el,history,changed,renderCanvas,onSelection:range=>richRange=range,openLink:()=>{$('#design-link-dialog input').value='';$('#design-link-dialog').showModal();},mergeTags:config.mergeTags,openImage:choose=>openLibrary('image',choose)});queueMicrotask(()=>{syncRichAppearance();syncRichToolbarWidth();});}
  function renderTools(){
    disposeRichText?.();disposeRichText=null;richRange=null;
    if(panel!=='styles'){styleDraft=null;styleArea='';}tools.classList.toggle('designer-style-detail',panel==='styles'&&!!styleArea);tools.classList.toggle('designer-style-index',panel==='styles'&&!styleArea);tools.classList.toggle('designer-block-style',panel==='block'&&blockTab==='style');tools.replaceChildren();$$('.designer-rail button').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.panel===panel)));
    if(design.mode==='code'&&panel==='add'){
      tools.append(el('h2','Custom HTML'),button('Edit code',openCode),el('p','The original source remains editable. Use named regions for content changes.','designer-panel-help'));
      const regions=el('div');tools.append(regions);run(async()=>{const data=await request('design_code_validate',{code:design.code});if(!regions.isConnected)return;Object.entries(data.regions).forEach(([name,text])=>regions.append(field(name,design.areas[name]??text,v=>{design.areas[name]=v;},'textarea')));if(!Object.keys(data.regions).length)regions.append(el('p','No mc:edit regions found.'));})();return;
    }
    if(panel==='add'){
      tools.append(el('h2','Add content'),tabs({content:'Content',layouts:'Layouts',saved:'Saved'},addTab,v=>{addTab=v;renderTools();}));const grid=el('div',undefined,'designer-block-grid');
      if(addTab==='saved'){const actions=el('div',undefined,'designer-saved-actions');actions.append(button('Browse saved sections',()=>openLibrary('section',value=>mutate(()=>design.rows.push(freshCopy(value.rows[0]))))),button('Open content studio',()=>openLibrary('image',value=>{addBlock('image');mutate(()=>Object.assign(blockAt(),value));})));tools.append(actions);return;}
      if(addTab==='content'){
        const blocks=[['text','Text','text'],['boxed-text','Boxed Text','boxedText'],['divider','Divider','divider'],['image','Image','image'],['image-group','Image Group','imageGroup'],['image-card','Image Card','imageCard'],['image-text','Image + Text','imageCaption'],['share','Share','socialShareRevised'],['social','Social Follow','socialFollowRevised'],['button','Button','button'],['footer','Footer','footer'],['code','Code','code'],['video','Video','video']];
        for(const [type,label,icon] of blocks){const tile=button('',()=>{if(type==='footer'){panel='footer';renderTools();}else addBlock(type);});const image=el('img');image.src='assets/editor-icons/'+icon+'.svg';image.alt='';image.draggable=false;tile.append(image,el('span',label));tile.draggable=type!=='footer';tile.setAttribute('aria-label','Add '+label+' block');tile.ondragstart=event=>{drag={type};event.dataTransfer.effectAllowed='copy';event.dataTransfer.setData('text/plain',type);};tile.ondragend=finishDrag;grid.append(tile);}
        const more=details('More blocks and tools',false),actions=el('div',undefined,'designer-more-actions');for(const [type,label]of [['heading','Heading'],['spacer','Spacer'],['toc','Table of contents']])actions.append(button(label,()=>addBlock(type)));actions.append(button('Manage email sections',()=>{panel='sections';renderTools();}));more.append(actions);tools.append(grid,more);return;
      }
      else [1,2,3,4].forEach(n=>{const b=button('',()=>mutate(()=>{const row=freshRow(n,'Layout');row.columns.forEach(col=>col.push(defaultBlock('text')));design.rows.push(row);selected=null;selectedRow=null;}));b.append(el('span',Array(n).fill('▯').join('')),el('span',`${n} column${n>1?'s':''}`));grid.append(b);});tools.append(grid,button('Manage email sections',()=>{panel='sections';renderTools();},'designer-secondary-action'));
    }else if(panel==='styles'){
      if(styleArea&&!styleDraft)styleDraft=structuredClone(design);
      renderDesignStylePanel({brandFont:config.brandFont,tools,design:styleDraft||design,area:styleArea,setArea:value=>{styleArea=value;styleDraft=value?structuredClone(design):null;render();},button,el,onPreview:()=>renderCanvas(styleDraft),onSave:run(async()=>{if(!styleDraft)return;history();design=structuredClone(styleDraft);styleDraft=null;styleArea='';normalize();changed();render();await save(false);})});
    }else if(panel==='sections')renderSections();
    else if(panel==='section')renderSectionStyle();
    else if(panel==='comments')renderComments();
    else if(panel==='optimize'){tools.append(el('h2','Optimize'),el('p','Review your content before sending.','designer-panel-help'),button('Check links',openLinks),button('Preview personalized content',openPreview),button('Review plain-text email',openPlain),button('Edit footer',()=>{panel='footer';renderTools();}));}
    else if(panel==='footer'){tools.append(button('← Back',()=>{panel='add';renderTools();}),el('h2','Footer'),el('p','Identity, mailing address, preferences, and unsubscribe links stay in every email.','designer-panel-help'),fieldGrid(field('Additional footer text',design.footer.text,v=>design.footer.text=v,'textarea'),field('Text color',design.footer.styles.color||'#60676b',v=>design.footer.styles.color=v,'color'),field('Background color',design.footer.styles.background||design.styles.body,v=>design.footer.styles.background=v,'color'),field('Padding',design.footer.styles.padding??32,v=>design.footer.styles.padding=v,'number')));}
    else renderBlockTools();
  }
  function renderBlockTools(){
    const b=blockAt();if(!b){panel='add';renderTools();return;}const row=design.rows[selected[0]];
    tools.append(button('← Back to content',()=>{selected=null;panel='add';render();},'designer-back'),el('h2',{'image-card':'Image card','image-group':'Image group','boxed-text':'Boxed text','image-text':'Image + text',social:'Social follow',toc:'Table of contents'}[b.type]||b.type[0].toUpperCase()+b.type.slice(1)),tabs({content:'Content',style:'Style',settings:'Settings'},blockTab,v=>{blockTab=v;renderTools();}));
    if(blockTab==='content'){
      if(['text','heading','image-card','boxed-text','image-text'].includes(b.type))richText(b);
      if(b.type==='image-text')tools.append(fieldGrid(field('Image position',b.imagePosition,v=>b.imagePosition=v,'select',{left:'Image on left',right:'Image on right'}),field('Image width (%)',b.imageWidth,v=>b.imageWidth=v,'number')));
      if(b.type==='share'){const fields=fieldGrid();tools.append(el('p','Let readers share the browser version of this email.','designer-panel-help'),fields);for(const [key,label]of Object.entries({facebook:'Facebook',x:'X',linkedin:'LinkedIn',email:'Email'}))fields.append(field(label,b.sharePlatforms.includes(key),checked=>{b.sharePlatforms=checked?[...b.sharePlatforms,key]:b.sharePlatforms.filter(p=>p!==key);},'checkbox'));}
      if(b.type==='button')tools.append(field('Button text',b.text,v=>{b.text=v;}));
      const contentFields=['text','heading','button'].includes(b.type)?el('div',undefined,'designer-content-fields'):null;
      if(contentFields)tools.append(contentFields);
      if(['text','heading','image-card','boxed-text','image-text','button'].includes(b.type)){
        const mergeField=field('Merge tags','',v=>{if(v){b.text+=` *|${v}|*`;if(b.html)b.html+=` *|${v}|*`;renderTools();}},'select',{'':'Insert a merge tag',...config.mergeTags,'MC:TOC':'Table of Contents'});
        mergeField.classList.add('designer-merge-field');(contentFields||tools).append(mergeField);
      }
      if(['image','image-card','image-text','video'].includes(b.type))addImageTools(tools,b);
      if(b.type==='image-group'){
        b.images.forEach((image,i)=>{const group=details('Image '+(i+1));addImageTools(group,image);group.append(button('Remove image',()=>mutate(()=>b.images.splice(i,1))));tools.append(group);});if(b.images.length<4)tools.append(button('Add image',()=>mutate(()=>b.images.push({image:'',alt:'',url:''}))));
      }
      if(contentFields)contentFields.append(field('Link URL',b.url,v=>{b.url=v;}));
      if(b.type==='video')tools.append(field('Video caption',b.caption,v=>b.caption=v),el('p','Add a thumbnail and link. The video opens from the email.','designer-panel-help'));
      if(b.type==='social'){
        b.social.forEach((link,i)=>{const group=details('Link '+(i+1));group.append(fieldGrid(field('Label',link.label,v=>link.label=v),field('URL',link.url,v=>link.url=v)),button('Remove link',()=>mutate(()=>b.social.splice(i,1))));tools.append(group);});if(b.social.length<10)tools.append(button('Add social link',()=>mutate(()=>b.social.push({label:'Website',url:'https://example.com'}))));
      }
      if(b.type==='code')tools.append(field('HTML code',b.code,v=>b.code=v,'textarea'),el('p','HTML is validated and rendered in a protected preview.','designer-panel-help'));
      if(b.type==='toc')tools.append(el('p','Headings marked “Include in table of contents” appear here with anchor links.','designer-panel-help'));
      if(b.type==='spacer')tools.append(field('Height',b.height,v=>b.height=v,'number'));
    }else if(blockTab==='style'){
      const mobile=device==='mobile'&&!b.linkDevices;
      tools.append(field('Link Desktop and Mobile Styles',b.linkDevices,v=>{b.linkDevices=v;renderTools();},'checkbox'),tabs({desktop:'On Desktop',mobile:'On Mobile'},device,v=>setDevice(v)));
      const target=mobile?b.mobile:b;const effective=blockStyle(b,row);const change=(key,value)=>{target[key]=value;if(!mobile)b.inherit=b.inherit.filter(k=>k!==key);};
      const styleFields=el('div',undefined,'designer-field-grid');tools.append(styleFields);
      styleFields.append(field('Alignment',target.align||effective.align,v=>change('align',v),'select',{left:'Left',center:'Center',right:'Right',justify:'Justify'}),field('Padding',target.padding??effective.padding,v=>change('padding',v),'number'),field('Margin',target.margin??effective.margin,v=>change('margin',v),'number'));
      if(!['image','image-group','divider','spacer'].includes(b.type))styleFields.append(field('Font size',target.size??effective.size,v=>change('size',v),'number'),field('Text color',b.type==='button'?(b.styles.color||design.styles.buttonText):(target.color||effective.color),v=>{if(b.type==='button'&&!mobile)b.styles.color=v;else change('color',v);},'color'));
      const extras=mobile?b.mobile:b.styles;styleFields.append(field('Font',extras.font||effective.font,v=>{selectDesignFont(design,config.brandFont,v);extras.font=v;},'select',designFontChoices(design,config.brandFont)),field('Line height (%)',extras.lineHeight??effective.lineHeight,v=>extras.lineHeight=v,'number'),field('Letter spacing',extras.letterSpacing??effective.letterSpacing,v=>extras.letterSpacing=v,'number'),field('Paragraph spacing',extras.paragraphSpacing??effective.paragraphSpacing,v=>extras.paragraphSpacing=v,'number'));
      if(b.type==='boxed-text')styleFields.append(field('Box background',b.styles.background||'#f1f3f4',v=>b.styles.background=v,'color'));
      if(b.type==='button'||b.type==='image-card')styleFields.append(field('Background color',b.background,v=>{b.background=v;b.inherit=b.inherit.filter(k=>k!=='background');},'color'));if(b.type==='button')styleFields.append(field('Rounded corners',b.radius,v=>{b.radius=v;b.inherit=b.inherit.filter(k=>k!=='radius');},'number'));
      if(b.type==='divider')styleFields.append(field('Line color',b.color,v=>b.color=v,'color'),field('Height',b.dividerHeight,v=>{if(mobile)b.mobile.dividerHeight=v;else{b.dividerHeight=v;b.inherit=b.inherit.filter(k=>k!=='dividerHeight');}},'number'),field('Width (%)',b.dividerWidth,v=>{if(mobile)b.mobile.dividerWidth=v;else{b.dividerWidth=v;b.inherit=b.inherit.filter(k=>k!=='dividerWidth');}},'number'),field('Style',b.styles.borderStyle||'solid',v=>b.styles.borderStyle=v,'select',{solid:'Solid',dashed:'Dashed',dotted:'Dotted'}));
      const border=details(b.type==='button'?'Button border':'Block border',false),borderFields=el('div',undefined,'designer-field-grid');border.append(borderFields);borderFields.append(field('Border width',extras.borderWidth||0,v=>extras.borderWidth=v,'number'),field('Border color',extras.borderColor||'#24292d',v=>extras.borderColor=v,'color'),field('Border style',extras.borderStyle||'solid',v=>extras.borderStyle=v,'select',{solid:'Solid',dashed:'Dashed',dotted:'Dotted'}));tools.append(border);
      tools.append(button('Restore default styles',()=>mutate(()=>{b.inherit=['color','size','padding','align','font','lineHeight','letterSpacing','paragraphSpacing','margin','background','radius','dividerWidth','dividerHeight'];b.styles={};b.mobile={};})));
    }else{
      const settings=fieldGrid();tools.append(settings);
      if(b.type==='heading')settings.append(field('Heading level',b.headingLevel,v=>b.headingLevel=Number(v),'select',{'1':'Heading 1','2':'Heading 2','3':'Heading 3','4':'Heading 4'}),field('Include in table of contents',b.includeToc,v=>b.includeToc=v,'checkbox'));
      if(['heading','text','boxed-text','image-text'].includes(b.type))settings.append(field('Anchor name',b.anchor,v=>b.anchor=v));tools.append(conditionEditor(b));
      tools.append(button('Comment on this block',()=>{panel='comments';renderTools();}));
    }
    tools.append(button('Save & close',async()=>{if(await save(false)){selected=null;panel='add';render();}},'primary designer-close-block'));
    const actions=el('div',undefined,'designer-block-actions');actions.append(button('Move up',()=>move(-1)),button('Move down',()=>move(1)),button('Duplicate',duplicate),button('Delete',remove));tools.append(actions);
  }
  function renderSections(){
    tools.append(el('h2','Email Sections'),el('p','Create and edit your email structure, or insert pre-built sections.','designer-panel-help'),tabs({manage:'Manage',prebuilt:'Pre-built',saved:'Saved'},sectionTab,v=>{sectionTab=v;renderTools();}));
    if(sectionTab==='manage'){
      design.rows.forEach((row,r)=>{const item=el('div',undefined,'designer-section-row');item.draggable=true;item.ondragstart=e=>{drag={section:r};e.dataTransfer.setData('text/plain','section');};item.ondragover=e=>e.preventDefault();item.ondrop=e=>{e.preventDefault();if(drag?.section!==undefined){const from=drag.section;drag=null;mutate(()=>{const moved=design.rows.splice(from,1)[0];design.rows.splice(r,0,moved);selected=null;selectedRow=null;});}};
        item.append(button(row.name,()=>{selectedRow=r;selected=null;panel='section';render();}),button('↑',()=>rowMove(r,-1)),button('↓',()=>rowMove(r,1)));const menu=el('details',undefined,'designer-section-menu');menu.append(el('summary','•••'));const list=el('div');list.append(button('Rename',()=>nameDialog('Rename section',row.name,name=>mutate(()=>row.name=name))),button('Duplicate',()=>mutate(()=>design.rows.splice(r+1,0,freshCopy(row)))),button('Save',()=>saveSection(row)),button('Delete',()=>confirmDesignAction('Delete section?','This removes the section and its content. You can undo the change.',()=>mutate(()=>{design.rows.splice(r,1);selected=null;selectedRow=null;}))));menu.append(list);item.append(menu);tools.append(item);});
      tools.append(button('+ Add blank section',()=>mutate(()=>design.rows.push(freshRow(1,'Blank section')))));
    }else if(sectionTab==='prebuilt'){
      const presets={Header:['heading','text','boxed-text','image-text'],Hero:['image','heading','button'],Features:['heading','text','boxed-text','image-text'],Footer:['social','text']},actions=el('div',undefined,'designer-more-actions');Object.entries(presets).forEach(([name,types])=>actions.append(button(name,()=>mutate(()=>{const row=freshRow(name==='Features'?2:1,name);row.region=name==='Header'?'header':name==='Footer'?'footer':'body';row.columns.forEach(col=>types.forEach(type=>col.push(defaultBlock(type))));design.rows.push(row);}))));tools.append(actions,el('p','Pre-built sections adapt to your email styles.','designer-panel-help'));
    }else{
      tools.append(button('Browse saved sections',()=>openLibrary('section',value=>mutate(()=>design.rows.push(freshCopy(value.rows[0]))))));
    }
  }
  function renderSectionStyle(){
    const row=design.rows[selectedRow];if(!row){panel='sections';renderTools();return;}
    tools.append(button('← Email Sections',()=>{panel='sections';renderTools();}),el('h2',row.name),tabs({desktop:'Desktop',mobile:'Mobile'},device,v=>setDevice(v)));const target=device==='mobile'?row.mobile:row.styles;
    tools.append(fieldGrid(field('Section name',row.name,v=>row.name=v),field('Email region',row.region,v=>row.region=v,'select',{preheader:'Preheader',header:'Header',body:'Body',footer:'Footer'}),field('Background color',target.background||design.styles.body,v=>target.background=v,'color'),field('Text color',target.color||design.styles.text,v=>target.color=v,'color'),field('Padding',target.padding||0,v=>target.padding=v,'number'),field('Font size',target.size||design.styles.fontSize,v=>target.size=v,'number'),field('Alignment',target.align||'left',v=>target.align=v,'select',{left:'Left',center:'Center',right:'Right',justify:'Justify'})),conditionEditor(row),button('Save section',()=>saveSection(row)),button('Duplicate section',()=>mutate(()=>design.rows.splice(selectedRow+1,0,freshCopy(row)))));
  }
  function saveSection(row){nameDialog('Save section',row.name,async name=>{await request('design_library_save',{type:'section',name,data:JSON.stringify({...design,mode:'visual',rows:[row],code:'',areas:{}})});status.textContent='Section saved';});}
  function rowMove(r,delta){if(r+delta<0||r+delta>=design.rows.length)return;mutate(()=>{[design.rows[r],design.rows[r+delta]]=[design.rows[r+delta],design.rows[r]];selected=null;selectedRow=null;});}
  function move(delta){if(!selected)return;const[r,c,i]=selected,col=design.rows[r].columns[c];if(i+delta>=0&&i+delta<col.length)mutate(()=>{[col[i],col[i+delta]]=[col[i+delta],col[i]];selected=[r,c,i+delta];});else if(r+delta>=0&&r+delta<design.rows.length)mutate(()=>{const nextC=Math.min(c,design.rows[r+delta].columns.length-1),next=design.rows[r+delta].columns[nextC],index=delta<0?next.length:0;next.splice(index,0,col.splice(i,1)[0]);selected=[r+delta,nextC,index];});}
  function duplicate(){const b=blockAt();if(b)mutate(()=>{const[r,c,i]=selected;design.rows[r].columns[c].splice(i+1,0,freshCopy(b));selected=[r,c,i+1];});}
  function remove(){if(blockAt())mutate(()=>{const[r,c,i]=selected;design.rows[r].columns[c].splice(i,1);selected=null;panel='add';});}
  let dragScrollFrame=0, dragPointer=null;
  const dropSpace=mountBlockDropSpace({canvas,getDrag:()=>drag,onDrop:drop});
  function clearDropMarkers(){dropSpace.clear();}
  function finishDrag(){drag=null;dragPointer=null;cancelAnimationFrame(dragScrollFrame);dragScrollFrame=0;clearDropMarkers();}
  function scrollDraggedBlock(){
    if(!drag||!dragPointer){dragScrollFrame=0;return;}
    const stage=$('.designer-stage'),rect=stage.getBoundingClientRect(),edge=Math.min(90,rect.height/4);
    if(dragPointer.x>=rect.left&&dragPointer.x<=rect.right){const y=dragPointer.y;let delta=y<rect.top+edge?-Math.ceil(18*(1-(y-rect.top)/edge)):y>rect.bottom-edge?Math.ceil(18*(1-(rect.bottom-y)/edge)):0;stage.scrollTop+=Math.max(-24,Math.min(24,delta));if(delta)dropSpace.update(dragPointer.x,y);}
    dragScrollFrame=requestAnimationFrame(scrollDraggedBlock);
  }
  document.addEventListener('dragover',event=>{if(!drag)return;dragPointer={x:event.clientX,y:event.clientY};if(!dragScrollFrame)dragScrollFrame=requestAnimationFrame(scrollDraggedBlock);},{capture:true});
  document.addEventListener('dragend',finishDrag);document.addEventListener('drop',finishDrag);
  function drop(target){if(!drag)return;const from=drag;finishDrag();if(from.type)addBlock(from.type,target);else if(from.position)mutate(()=>{selected=moveEmailBlock(design,from.position,target);panel='block';});}
  function imageNode(item){if(!item.image)return el('div','▧ Add an image','designer-image-placeholder');const image=el('img');image.src=item.image;image.alt=item.alt||'';return image;}
  function highlightSelection(block,previous){
    // Keep the five-blink sequence on its original clock when editing redraws the canvas.
    const now=performance.now(),started=previous?.dataset.blockId===block.dataset.blockId?Number(previous.dataset.outlineBlinkStarted):now;
    block.dataset.outlineBlinkStarted=String(started);
    const elapsed=now-started;
    if(elapsed<6000){block.classList.add('designer-selection-blink');block.style.animationDelay=`-${elapsed}ms`;}
  }
  let brandFace=null;
  function renderCanvas(doc=styleDraft||design){
    const design=doc;
    const previousSelection=$('.designer-block.selected',canvas);
    normalize();canvas.replaceChildren();const s=design.styles;canvas.style.width=`${device==='mobile'?375:s.width}px`;canvas.style.border=canvas.style.borderTop=canvas.style.borderBottom='';applyStyle(canvas,{padding:0,size:s.fontSize,background:s.body,...design.regions.page},doc);canvas.style.margin='0 auto';canvas.style.fontFamily=s.webFont&&s.webFontDefault!==false?'MOEDBrandFont,'+s.font:s.font;if(canvas.dataset.brandFont!==(s.webFont||'')){canvas.dataset.brandFont=s.webFont||'';if(brandFace){document.fonts.delete(brandFace);brandFace=null;}if(s.webFont)new FontFace('MOEDBrandFont',`url(${s.webFont})`).load().then(font=>{if(canvas.dataset.brandFont===s.webFont){brandFace=font;document.fonts.add(font);}}).catch(()=>{if(canvas.dataset.brandFont===s.webFont)status.textContent='Brand font unavailable; using the email fallback.';});}canvas.style.color=s.text;canvas.style.setProperty('--email-link',s.link);canvas.style.setProperty('--email-link-decoration',s.linkUnderline?'underline':'none');canvas.style.fontSize=`${s.fontSize}px`;canvas.style.lineHeight=s.lineHeight/100;canvas.classList.toggle('mobile',device==='mobile');$('.designer-stage').style.background=s.background;
    if(design.mode==='code'){
      canvas.append(button('Edit code',openCode));const frame=el('iframe');frame.title='Custom HTML canvas';frame.sandbox='';frame.referrerPolicy='no-referrer';frame.className='designer-code-canvas';canvas.append(frame);const sequence=++codeSequence;
      run(async()=>{const response=await request('design_preview',{design:JSON.stringify(design)});if(sequence===codeSequence&&frame.isConnected)frameHtml(frame,response.html);})();return;
    }
    if(design.preheader.showArchive){const pre=el('div','View this email in your browser','designer-archive-link');pre.onclick=()=>{panel='styles';styleArea='preheader';renderTools();};canvas.append(pre);}
    design.rows.forEach((row,r)=>{
      const rn=el('div',undefined,'designer-row');rn.dataset.sectionId=row.id;applyStyle(rn,{padding:0,size:s.fontSize,...design.regions[row.region||'body'],...row.styles,...(device==='mobile'?row.mobile:{})},doc);if(selectedRow===r)rn.classList.add('selected');const rowTools=el('div',undefined,'designer-layout-tools');rowTools.append(button('↑',()=>rowMove(r,-1)),button('↓',()=>rowMove(r,1)),button('⧉',()=>mutate(()=>design.rows.splice(r+1,0,freshCopy(row)))),button('⚙',()=>{selectedRow=r;selected=null;panel='section';render();}));['Move section up','Move section down','Duplicate section','Section styles'].forEach((label,i)=>$('button:nth-child('+(i+1)+')',rowTools).setAttribute('aria-label',label));rn.append(rowTools);
      row.columns.forEach((col,c)=>{
        const cn=el('div',undefined,'designer-column');cn.dataset.row=r;cn.dataset.column=c;
        col.forEach((b,i)=>{
          const bn=el('div',undefined,'designer-block');bn.tabIndex=0;bn.setAttribute('role','button');bn.setAttribute('aria-label',`Edit ${b.type} block ${i+1}`);bn.dataset.blockId=b.id;const isSelected=selected?.join(',')===[r,c,i].join(',');bn.classList.toggle('selected',isSelected);bn.onclick=e=>{e.preventDefault();selected=[r,c,i];selectedRow=null;panel='block';render();};bn.onkeydown=e=>{if(e.target!==bn)return;if(e.key==='Enter'||e.key===' '){e.preventDefault();bn.click();}};
          if(isSelected)highlightSelection(bn,previousSelection);
          bn.draggable=true;bn.ondragstart=e=>{drag={position:[r,c,i]};e.dataTransfer.setData('text/plain','block');e.dataTransfer.effectAllowed='move';};bn.ondragend=finishDrag;
          const content=el('div',undefined,'designer-block-content'),style=blockStyle(b,row,doc);applyStyle(content,style,doc);if(b.type==='button')content.style.border='none';content.style.fontWeight=b.bold?'bold':(style.weight||'normal');content.style.fontStyle=b.italic?'italic':(style.fontStyle||'normal');
          const rich=()=>{const text=el(b.type==='heading'?'h'+b.headingLevel:'div',b.html?undefined:b.text);if(b.html){richMarkup(text,b.html);text.style.display='flow-root';}text.dataset.emailText='';text.style.whiteSpace=b.html?'normal':'pre-wrap';text.style.fontSize='inherit';text.style.margin=0;if(b.type==='heading')text.style.fontWeight='inherit';return text;};
          if(b.type==='image')content.append(imageNode(b));
          else if(b.type==='image-card'){content.append(imageNode(b));const caption=rich();caption.style.background=b.background;caption.style.padding='16px';content.append(caption);}
          else if(b.type==='image-text'){const group=el('div',undefined,'designer-image-text '+b.imagePosition);group.style.setProperty('--image-width',b.imageWidth+'%');const image=imageNode(b),copy=rich();group.append(image,copy);content.append(group);}
          else if(b.type==='share'){const group=el('div',undefined,'designer-social-links');b.sharePlatforms.forEach(platform=>group.append(el('span','Share on '+({x:'X',linkedin:'LinkedIn',facebook:'Facebook',email:'Email'}[platform]))));content.append(group);}
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
          const bar=el('div',undefined,'designer-block-hover-tools'),handle=button('',event=>{event.stopPropagation();});handle.className='designer-block-move';handle.title='Drag Block';handle.setAttribute('aria-label','Drag Block');const moveIcon=el('img');moveIcon.src='assets/editor-icons/block-move.svg';moveIcon.alt='';handle.append(moveIcon);handle.draggable=true;bar.append(handle);
          const actions=el('div',undefined,'designer-block-hover-actions');for(const [icon,label,fn]of [['edit','Edit Block',()=>{selected=[r,c,i];selectedRow=null;panel='block';render();}],['duplicate','Duplicate Block',()=>{selected=[r,c,i];duplicate();}],['delete','Delete Block',()=>{selected=[r,c,i];remove();}]]){const control=button('',event=>{event.stopPropagation();fn();});control.setAttribute('aria-label',label);const image=el('img');image.src='assets/editor-icons/block-'+icon+'.svg';image.alt='';const tip=el('span',label,'designer-block-tooltip');tip.setAttribute('role','tooltip');control.append(image,tip);actions.append(control);}bar.append(actions);bn.append(bar);cn.append(bn);
        });if(!col.length)cn.append(el('div','Drop content here','designer-drop-empty'));rn.append(cn);
      });canvas.append(rn);
    });
    const footer=el('div',undefined,'designer-email-footer');footer.tabIndex=0;footer.setAttribute('role','button');footer.setAttribute('aria-label','Edit footer');footer.append(el('p',design.footer.text),el('p','*|LIST:COMPANY|*\n*|LIST:ADDRESS|*'),el('p','Update your preferences or unsubscribe.'));applyStyle(footer,{background:s.body,color:'#60676b',size:12,padding:32,align:'center',lineHeight:160,...design.regions.footer,...design.footer.styles});footer.onclick=()=>{panel='footer';renderTools();};footer.onkeydown=e=>{if(e.key==='Enter')footer.click();};canvas.append(footer);if(design.badge.enabled){const badge=el('div','Made with MessageMonkey','designer-email-badge');badge.style.textAlign=design.badge.align;canvas.append(badge);}
    syncRichAppearance();
  }
  function render(){renderTools();renderCanvas();updateHistory();}
  function setDevice(value){device=value;$$('[data-device]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.device===device)));render();}
  async function loadLibrary(){
    const data=await request('design_library',{type:libraryType,search:$('#design-library-search').value,page:String(libraryPage),per_page:String(librarySize)});libraryPage=data.page;const host=$('#design-library-items');host.replaceChildren();
    data.items.forEach(item=>{const card=el('article',undefined,'designer-library-card');const preview=el('div',undefined,'designer-library-thumbnail');card.append(preview,el('strong',item.name));
      const use=button('Insert',run(async()=>{
        if(use.disabled)return;
        use.disabled=true;
        try{const response=await request('design_library_get',{item_id:String(item.id)});$('#design-library-dialog').close();libraryChoose(response.item.data);}
        finally{use.disabled=false;}
      }));
      if(libraryType==='image'){
        preview.title='Double-click to insert image';
        preview.addEventListener('dblclick',()=>use.click());
      }
      card.append(use,button('Rename',()=>nameDialog('Rename '+libraryType,item.name,async name=>{await request('design_library_operation',{item_id:String(item.id),item_version:String(item.version),operation:'rename',name});await loadLibrary();})),button('Delete',()=>confirmDesignAction('Delete '+libraryType+'?','Existing emails keep their own copy of this content.',async()=>{await request('design_library_operation',{item_id:String(item.id),item_version:String(item.version),operation:'delete'});await loadLibrary();})));host.append(card);
      run(async()=>{const response=await request('design_library_get',{item_id:String(item.id)});if(!card.isConnected)return;if(libraryType==='image')preview.append(imageNode(response.item.data));else preview.append(el('span','▤ '+response.item.data.rows[0].name));})();
    });if(!data.items.length)host.append(el('p','No saved '+(libraryType==='image'?'images':'sections')+' found.'));
    renderAudiencePagination($('#design-library-pages'),{total:data.total,page:libraryPage,pageSize:librarySize,label:'Content library pages',onChange:({page,pageSize})=>{libraryPage=page;librarySize=pageSize;run(loadLibrary)();}});
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
  let simulateDark=false;
  const refreshPreview=run(async()=>{const data=await request('design_preview',{...previewParams(),dark:simulateDark?'1':'0'});frameHtml($('#design-preview-dialog iframe'),data.html);$('#preview-notice').textContent=[...(data.warnings||[]),...data.report.warnings].join(' ')||'Preview is ready. Email clients may render some features differently.';return data;});
  function openPreview(){closeMenus();$('#design-preview-dialog').showModal();refreshPreview();run(loadPeople)();}
  async function loadPeople(){const select=$('#preview-person'),previous=select.value;const data=await request('design_people',{search:$('#preview-search').value});select.replaceChildren();const blank=el('option','Choose a connection');blank.value='';select.append(blank);data.people.forEach(person=>{const option=el('option',`${person.first_name} ${person.last_name} — ${person.email}`.trim());option.value=person.id;select.append(option);});if([...select.options].some(o=>o.value===previous))select.value=previous;}
  let peopleTimer;$('#preview-search').oninput=()=>{clearTimeout(peopleTimer);peopleTimer=setTimeout(()=>run(loadPeople)(),250);};$('#preview-refresh').onclick=refreshPreview;$('#preview-live').onchange=$('#preview-person').onchange=refreshPreview;
  $$('[data-preview-device]').forEach(b=>b.onclick=()=>{const frame=$('#design-preview-dialog iframe');if(b.dataset.previewDevice==='dark'){simulateDark=!simulateDark;b.setAttribute('aria-pressed',String(simulateDark));refreshPreview();}else frame.style.width=b.dataset.previewDevice==='mobile'?'375px':'100%';});
  const openPlain=run(async()=>{closeMenus();const data=await request('design_preview',{design:JSON.stringify({...design,plainText:null})});$('#plain-auto').checked=design.plainText===null||design.plainText===undefined;$('#plain-content').value=design.plainText??data.text;$('#plain-content').disabled=$('#plain-auto').checked;$('#design-plain-dialog').showModal();});
  $('#plain-auto').onchange=()=>$('#plain-content').disabled=$('#plain-auto').checked;
  $('#plain-regenerate').onclick=run(async()=>{const data=await request('design_preview',{design:JSON.stringify({...design,plainText:null})});$('#plain-content').value=data.text;});
  $('#plain-apply').onclick=()=>{mutate(()=>design.plainText=$('#plain-auto').checked?null:$('#plain-content').value);$('#design-plain-dialog').close();};
  const openHistory=run(async()=>{closeMenus();const data=await request('design_history');const host=$('#design-history-items');host.replaceChildren();data.revisions.forEach(revision=>{const row=el('article',undefined,'designer-history-row');row.append(el('strong','Version '+revision.version),el('span',revision.created_at+' UTC · '+revision.actor_label),button('Restore',()=>confirmDesignAction('Restore version '+revision.version+'?','Your current edits will be saved first, then this version will be restored as a new version.',async()=>{if(dirty&&!(await save(false)))return;const result=await request('design_restore',{revision_id:String(revision.id)});history();design=result.design;version=result.version;dirty=false;normalize();selected=null;selectedRow=null;status.textContent='Version restored';try{localStorage.removeItem(recoveryKey);}catch{}render();$('#design-history-dialog').close();})));host.append(row);});if(!data.revisions.length)host.append(el('p','Save this email to begin its version history.'));$('#design-history-dialog').showModal();});
  const openLinks=run(async()=>{closeMenus();const data=await request('design_links',previewParams());const host=$('#design-link-report');host.replaceChildren();data.report.warnings.forEach(w=>host.append(el('p',w,'designer-check-warning')));data.report.links.forEach(link=>{const row=el('article',undefined,'designer-link-row');const result=el('span',link.status);row.append(el('strong',link.label||'Link'),el('code',link.url),result);if(link.status==='Ready to check'){const check=button('Check link',run(async()=>{check.disabled=true;try{const response=await request('design_probe_link',{url:link.url});result.textContent=response.status;}finally{check.disabled=false;}}));row.append(check);}host.append(row);});if(!data.report.links.length)host.append(el('p','No links found.'));host.append(el('p','Public HTTPS links are checked individually. Redirects and provider-resolved links need review.','designer-panel-help'));$('#design-links-dialog').showModal();});
  const openCode=run(async()=>{closeMenus();const data=await request('design_source',{design:JSON.stringify(design)});$('#design-code-source').value=data.source;$('#design-code-warnings').replaceChildren();$('#design-code-dialog').showModal();});
  $('#design-code-check').onclick=run(async()=>{const data=await request('design_code_validate',{code:$('#design-code-source').value});const host=$('#design-code-warnings');host.replaceChildren();(data.warnings.length?data.warnings:['Validation complete. Scripts cannot run in the preview.']).forEach(w=>host.append(el('p',w)));Object.keys(data.regions).forEach(name=>host.append(el('p','Editable region: '+name)));});
  $('#design-code-apply').onclick=run(async()=>{const code=$('#design-code-source').value;await request('design_code_validate',{code});const apply=()=>{mutate(()=>{design.mode='code';design.code=code;design.areas={};selected=null;panel='add';});$('#design-code-dialog').close();};if(design.mode!=='code')confirmDesignAction('Switch to code?','This replaces the visual layout with custom HTML. The previous visual version remains available in Undo and saved history.',apply);else apply();});
  $('#design-link-dialog form').onsubmit=e=>{e.preventDefault();const url=normalizeRichLink($('#design-link-dialog input').value);if(!/^(https?:\/\/[^\s<>"]+|mailto:[^\s<>"]+|tel:[+0-9 ()-]+|#[a-zA-Z][a-zA-Z0-9_-]*|\*\|(UNSUB|UPDATE_PROFILE|ARCHIVE|SURVEY:[a-f0-9]{64}|POLL:[a-f0-9]{64}:[0-9]{1,2})\|\*)$/i.test(url)){error('Use an HTTP, HTTPS, email, telephone, or anchor link.');return;}const rich=$('.designer-rich-text');if(rich&&richRange){rich.focus();const selection=window.getSelection();selection.removeAllRanges();selection.addRange(richRange);document.execCommand('createLink',false,url);}$('#design-link-dialog').close();};
  $('#design-link-remove').onclick=()=>{const rich=$('.designer-rich-text');if(rich&&richRange){rich.focus();const selection=window.getSelection();selection.removeAllRanges();selection.addRange(richRange);document.execCommand('unlink',false,null);}$('#design-link-dialog').close();};
  function openCrop(target){cropTarget=target;cropImage=new Image();cropImage.onload=()=>{['crop-zoom','crop-x','crop-y'].forEach(id=>$('#'+id).value=id==='crop-zoom'?'1':'50');$('#crop-aspect').value='original';drawCrop();$('#design-crop-dialog').showModal();};cropImage.src=target.image;}
  function cropDimensions(){const aspect=$('#crop-aspect').value==='original'?cropImage.naturalWidth/cropImage.naturalHeight:Number($('#crop-aspect').value),zoom=Number($('#crop-zoom').value);let width=cropImage.naturalWidth,height=width/aspect;if(height>cropImage.naturalHeight){height=cropImage.naturalHeight;width=height*aspect;}width/=zoom;height/=zoom;return {x:(cropImage.naturalWidth-width)*Number($('#crop-x').value)/100,y:(cropImage.naturalHeight-height)*Number($('#crop-y').value)/100,width,height,aspect};}
  function drawCrop(){if(!cropImage)return;const c=$('#design-crop-canvas'),dims=cropDimensions();c.width=600;c.height=Math.round(600/dims.aspect);const ctx=c.getContext('2d');ctx.clearRect(0,0,c.width,c.height);ctx.drawImage(cropImage,dims.x,dims.y,dims.width,dims.height,0,0,c.width,c.height);}
  ['crop-aspect','crop-zoom','crop-x','crop-y'].forEach(id=>$('#'+id).oninput=drawCrop);
  $('#crop-apply').onclick=()=>{const image=$('#design-crop-canvas').toDataURL('image/png');if(image.length>933000){error('The cropped image is too large. Use a smaller image.');return;}mutate(()=>cropTarget.image=image);$('#design-crop-dialog').close();};
  const topMenus=$$('.designer-top-actions .designer-save-menu');topMenus.forEach(menu=>{menu.name='designer-top-menu';menu.addEventListener('toggle',()=>{if(menu.open)topMenus.forEach(other=>{if(other!==menu)other.open=false;});});});
  function closeMenus(){$$('.designer-save-menu').forEach(menu=>menu.open=false);}
  document.addEventListener('click',event=>{
    if(!topMenus.some(menu=>menu.contains(event.target)))closeMenus();
  },true);
  $('#design-undo').onclick=()=>travel(undo,redo);$('#design-redo').onclick=()=>travel(redo,undo);
  document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'&&!e.target.matches('input,textarea,[contenteditable]')){e.preventDefault();e.shiftKey?travel(redo,undo):travel(undo,redo);}});
  $$('.designer-rail [data-panel]').forEach(b=>b.onclick=()=>{panel=b.dataset.panel;if(panel==='styles'){styleArea='';styleDraft=null;}selectedRow=null;if(panel!=='comments')selected=null;render();});$$('[data-device]').forEach(b=>b.onclick=()=>setDevice(b.dataset.device));
  $('#design-save').onclick=()=>save(true);$('#design-save-only').onclick=()=>{closeMenus();save(false);};$('#design-preview').onclick=openPreview;$('#design-links').onclick=openLinks;$('#design-plain').onclick=openPlain;$('#design-history').onclick=openHistory;$('#design-code').onclick=openCode;$('#design-comments').onclick=()=>{panel='comments';renderTools();};$('#design-test').onclick=()=>{closeMenus();$('#design-test-dialog').showModal();};
  let testRequestKey='';
  $('#design-test').onclick=()=>{closeMenus();testRequestKey=[...crypto.getRandomValues(new Uint8Array(32))].map(n=>n.toString(16).padStart(2,'0')).join('');$('#design-test-status').textContent='';$('#design-test-dialog').showModal();};
  $('#design-test-form').onsubmit=run(async e=>{
    e.preventDefault();const submit=$('button.primary',e.target),notice=$('#design-test-status');submit.disabled=true;notice.textContent='';
    try{
      if(dirty&&!(await save(false)))return;
      const data=await request('design_test',{...Object.fromEntries(new FormData(e.target)),request_key:testRequestKey});
      const emailLabel=data.queued===1?'test email':'test emails';
      $('#design-test-result-title').textContent=data.queued===1?'Test email queued':'Test emails queued';
      $('#design-test-result-text').textContent=`${data.queued} ${emailLabel} queued. Check Email delivery → Activity for the relay receipt.`;
      $('#design-test-dialog').close();
      $('#design-test-result-dialog').showModal();
    }catch(err){notice.textContent=err.message;}finally{submit.disabled=false;}
  });
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
