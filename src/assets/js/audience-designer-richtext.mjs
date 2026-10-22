import { mountTextColor } from './audience-richtext-colors.mjs';
import { mountRichImageTools, normalizeRichImage } from './audience-richtext-images.mjs';

const tags = new Set(['p','div','span','font','br','hr','h1','h2','h3','h4','blockquote','strong','b','em','i','u','s','ul','ol','li','a','img']);
const css = /^(?:color|background-color|font-family|font-size|font-weight|font-style|text-decoration|text-align|direction|line-height|letter-spacing)\s*:/i;
export function normalizeRichLink(url) {
  url=url.trim();
  if(/^[A-Za-z0-9.!#$%&'*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/.test(url))return 'mailto:'+url;
  if(/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?::[0-9]{1,5})?(?:[/?#][^\s<>"\x00-\x1f]*)?$/.test(url))return 'https://'+url;
  return url;
}
export function cleanRichHtml(html, doc = document) {
  const template = doc.createElement('template'); template.innerHTML = html;
  for (const node of [...template.content.querySelectorAll('*')]) {
    const tag = node.tagName.toLowerCase();
    if (['script','style','iframe','object','svg','math','form','input'].includes(tag)) { node.remove(); continue; }
    if (!tags.has(tag)) { node.replaceWith(...node.childNodes); continue; }
    if(tag==='img')normalizeRichImage(node);
    if(tag==='a'&&node.hasAttribute('href'))node.setAttribute('href',normalizeRichLink(node.getAttribute('href')));
    for (const attr of [...node.attributes]) {
      const {name, value} = attr;
      if (name === 'style'&&tag!=='img') node.setAttribute(name,value.split(';').filter(part=>css.test(part.trim())&&!/(?:url|expression|javascript|behavior|binding|var\()/i.test(part)).join(';'));
      else if(tag==='img'&&['style','width','align'].includes(name))continue;
      else if(name==='href'&&tag==='a'&&/^(?:https?:\/\/[^\s<>"\x00-\x1f]+|mailto:[^\s<>"\x00-\x1f]+|tel:[+\d ()-]+|#[a-z][\w-]*|\*\|(?:UNSUB|UPDATE_PROFILE|ARCHIVE|SURVEY:[a-f0-9]{64}|POLL:[a-f0-9]{64}:[0-9]{1,2})\|\*)$/i.test(value))continue;
      else if(name==='src'&&tag==='img'&&/^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$/.test(value))continue;
      else if(name==='alt'&&tag==='img')continue;
      else if(name==='id'&&/^[a-z][\w-]{0,79}$/i.test(value))continue;
      else if(tag==='font'&&['color','face','size'].includes(name))continue;
      else node.removeAttribute(name);
    }
    if(tag==='img'&&!node.hasAttribute('src'))node.remove();
  }
  return template.innerHTML;
}

// Parsed style attributes can be blocked by the page CSP. Reapply sanitized
// declarations through the CSSOM, consistently for the canvas and editor.
export function applyRichStyles(root) {
  for(const node of root.querySelectorAll('[style]')){
    const declarations=document.createElement('span').style;
    declarations.cssText=node.getAttribute('style');node.removeAttribute('style');
    for(const property of declarations)node.style.setProperty(property,declarations.getPropertyValue(property));
  }
}
export function setRichContent(root,html) {
  const template=document.createElement('template');template.innerHTML=cleanRichHtml(html);
  applyRichStyles(template.content);root.replaceChildren(template.content);
}

const iconPaths = {
  link:'M9 15l6-6M8 17l-1 1a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 8a4 4 0 0 0 6 0l4-4a4 4 0 0 0-6-6l-1 1',
  unlink:'M8 17l-1 1a4 4 0 0 1-6-6l3-3m12-2 1-1a4 4 0 0 1 6 6l-3 3M4 2l18 20',
  image:'M2 3h20v18H2zM3 17l6-6 5 5 3-3 5 5M17 7h.01',
  numbered:'M10 5h12M10 12h12M10 19h12M2 3h2v5M2 8h4M2 11h4l-4 5h4M2 19h4v4H2',
  bulleted:'M10 5h12M10 12h12M10 19h12M3 4h2v2H3zM3 11h2v2H3zM3 18h2v2H3z',
  paste:'M7 4H3v18h15V4h-4M7 2h7v4H7zM7 10h8M11 10v8',
  richPaste:'M7 4H3v18h18V4h-7M7 2h7v4H7zM7 10l2 8 3-6 3 6 2-8',
  line:'M3 3h18v18H3zM6 7h12M6 10h12M6 14h12M6 18h12',
  anchor:'M12 7v15M8 11h8M3 15a9 9 0 0 0 18 0M3 15l-2 3m20-3 2 3M15 4a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
  left:'M3 4h18M3 9h11M3 14h18M3 19h11',
  center:'M3 4h18M6 9h12M3 14h18M6 19h12',
  right:'M3 4h18M10 9h11M3 14h18M10 19h11',
  justify:'M3 4h18M3 9h18M3 14h18M3 19h18',
  undo:'M3 10h11a7 7 0 0 1 7 7M3 10l6-6m-6 6 6 6',
  redo:'M21 10H10a7 7 0 0 0-7 7m18-7-6-6m6 6-6 6',
};

// Apply colors through the saved Range without focusing the editing host. In
// WebKit, execCommand can steal focus even when focus() is not called explicitly.
export function colorTextRange(root,range,property,value) {
  const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),parts=[];
  let node;
  while((node=walker.nextNode())){
    if(!range.intersectsNode(node))continue;
    const start=node===range.startContainer?range.startOffset:0,end=node===range.endContainer?range.endOffset:node.length;
    if(end>start)parts.push({node,start,end});
  }
  if(!parts.length)return range;
  const selected=[];
  for(const part of parts){
    let text=part.node;
    if(part.end<text.length)text.splitText(part.end);
    if(part.start)text=text.splitText(part.start);
    if(value==='inherit'||value==='transparent'){
      // Split inline ancestors around this run before clearing their color, so
      // Automatic / No color do not change unselected neighbouring text.
      let branch=text;
      while(branch.parentElement!==root&&/^(SPAN|FONT|B|STRONG|I|EM|U|S|A)$/.test(branch.parentElement.tagName)){
        const parent=branch.parentElement,before=parent.cloneNode(false),after=parent.cloneNode(false);before.removeAttribute('id');after.removeAttribute('id');
        while(parent.firstChild!==branch)before.append(parent.firstChild);
        while(branch.nextSibling)after.append(branch.nextSibling);
        if(before.childNodes.length)parent.before(before);if(after.childNodes.length)parent.after(after);
        parent.style[property]='';if(property==='color')parent.removeAttribute('color');branch=parent;
      }
    }
    const span=document.createElement('span');span.style[property]=value;
    text.replaceWith(span);span.append(text);selected.push(text);
  }
  const next=document.createRange();next.setStart(selected[0],0);next.setEnd(selected.at(-1),selected.at(-1).length);return next;
}

export function mountRichText({host,block,makeButton:button,makeElement:el,history,changed,renderCanvas,onSelection,openLink,mergeTags,openImage,fonts,onFont=()=>{}}) {
  const lifecycle=new AbortController(), {signal}=lifecycle;
  const rich=el('div',undefined,'designer-rich-text');rich.contentEditable='true';rich.setAttribute('role','textbox');rich.setAttribute('aria-label','Text');rich.setAttribute('aria-multiline','true');
  if(block.html)setRichContent(rich,block.html);else (block.text||'').split(/\r?\n/).forEach((line,index)=>{if(index)rich.append(el('br'));rich.append(document.createTextNode(line));});
  const applyStyles=()=>applyRichStyles(rich);
  let range=null,applying=false,lastHtml=rich.innerHTML;
  const past=[],future=[],stateButtons=[];
  const source=el('textarea',undefined,'designer-rich-source');source.setAttribute('aria-label','Text HTML source');source.hidden=true;source.spellcheck=false;
  const remember=()=>{
    const selection=window.getSelection();
    if(!applying&&document.activeElement===rich&&selection?.rangeCount&&rich.contains(selection.anchorNode)&&rich.contains(selection.focusNode)){
      range=selection.getRangeAt(0).cloneRange();onSelection(range);
      for(const [control,name]of stateButtons)control.setAttribute('aria-pressed',String(document.queryCommandState(name)));
    }
  };
  const restore=(focus=true)=>{
    // Capture a newer selection before consulting the saved range. A color
    // window or a toolbar input may own focus without owning the text range.
    remember();applying=true;
    if(focus)rich.focus({preventScroll:true});
    if(!range||!rich.contains(range.commonAncestorContainer)){range=document.createRange();range.selectNodeContents(rich);range.collapse(false);}
    const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);applying=false;
  };
  const sync=()=>{
    // Native editing commands (including typing in formatted text) may rewrite
    // spans as HTML attributes. Restore their styles under style-src-attr:none.
    applyStyles();
    const html=rich.innerHTML;
    if(html!==lastHtml){past.push(lastHtml);if(past.length>60)past.shift();future.length=0;lastHtml=html;block.html=html;block.text=rich.innerText;changed();renderCanvas();}
    undo.disabled=!past.length;redo.disabled=!future.length;remember();
  };
  const edit=(fn,{focus=true,restoreSelection=true}={})=>{if(restoreSelection)restore(focus);history();applying=true;try{fn();}finally{applying=false;}sync();};
  const command=(name,value,options)=>edit(()=>{document.execCommand('styleWithCSS',false,true);document.execCommand(name,false,value);},options);
  rich.addEventListener('beforeinput',()=>history(),{signal});
  rich.addEventListener('input',()=>{if(!applying)sync();},{signal});
  rich.addEventListener('mouseup',remember,{signal});rich.addEventListener('keyup',remember,{signal});
  document.addEventListener('selectionchange',remember,{signal});
  rich.addEventListener('paste',e=>{e.preventDefault();const html=e.clipboardData.getData('text/html');edit(()=>{document.execCommand(html?'insertHTML':'insertText',false,html?cleanRichHtml(html):e.clipboardData.getData('text/plain'));applyStyles();});},{signal});

  const toolbar=el('div',undefined,'designer-rich-toolbar');toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Text formatting');
  const first=el('div',undefined,'designer-rich-toolbar-row'),second=el('div',undefined,'designer-rich-toolbar-row');toolbar.append(first,second);
  let row=first;
  const tool=(label,text,action,icon)=>{
    const control=button(text,action);control.setAttribute('aria-label',label);control.title=label;control.className='designer-rich-tool';
    if(icon){const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');const path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d',iconPaths[icon]);svg.append(path);control.replaceChildren(svg);}
    control.onmousedown=e=>{remember();e.preventDefault();};row.append(control);return control;
  };
  for(const [name,label,text]of [['bold','Bold selection','B'],['italic','Italic selection','I'],['underline','Underline selection','U']]){const control=tool(label,text,()=>command(name));control.classList.add('designer-rich-'+name);stateButtons.push([control,name]);}
  tool('Insert link','',()=>{remember();openLink();},'link');tool('Remove link','',()=>command('unlink'),'unlink');
  tool('Insert image from content studio','',()=>{remember();openImage(value=>{edit(()=>{const image=document.createElement('img');image.src=value.image;image.alt=value.alt||'';image.width=Math.min(240,Math.max(1,rich.clientWidth-26));normalizeRichImage(image);const target=window.getSelection().getRangeAt(0);target.deleteContents();target.insertNode(image);target.setStartAfter(image);target.collapse(true);imageTools.select(image);});});},'image');
  for(const [name,label,icon]of [['insertOrderedList','Numbered list','numbered'],['insertUnorderedList','Bulleted list','bulleted']]){const control=tool(label,'',()=>command(name),icon);stateButtons.push([control,name]);}

  const dialogs=[];
  const inputDialog=(title,control,help,commit)=>{
    const dialog=el('dialog',undefined,'designer-rich-dialog');dialog.setAttribute('aria-label',title);const form=el('form');form.append(el('h2',title),el('p',help),control);const actions=el('div',undefined,'designer-rich-dialog-actions');actions.append(button('Cancel',()=>dialog.close()));const submit=el('button','Insert');submit.type='submit';actions.append(submit);form.append(actions);dialog.append(form);host.append(dialog);dialogs.push(dialog);
    form.onsubmit=e=>{e.preventDefault();dialog.close();if(commit()===false)dialog.showModal();};return dialog;
  };
  const paste=(formatted)=>{
    remember();const input=el(formatted?'div':'textarea',undefined,formatted?'designer-rich-paste':'');input.setAttribute('aria-label',formatted?'Paste formatted text':'Paste plain text');
    if(formatted){input.contentEditable='true';input.setAttribute('role','textbox');input.addEventListener('paste',e=>{e.preventDefault();const html=e.clipboardData.getData('text/html');document.execCommand(html?'insertHTML':'insertText',false,html?cleanRichHtml(html):e.clipboardData.getData('text/plain'));input.querySelectorAll('[style]').forEach(n=>{const value=n.getAttribute('style');n.removeAttribute('style');n.style.cssText=value;});});}
    const dialog=inputDialog(formatted?'Paste from Rich Text Editor':'Paste as plain text',input,formatted?'Paste your text here. Formatting is kept; unsupported content is removed.':'Paste your text here to insert it without formatting.',()=>{edit(()=>{document.execCommand(formatted?'insertHTML':'insertText',false,formatted?cleanRichHtml(input.innerHTML):input.value);applyStyles();});});dialog.showModal();input.focus();
  };
  tool('Paste as plain text','',()=>paste(false),'paste');tool('Paste from Rich Text Editor','',()=>paste(true),'richPaste');
  tool('Clear Styles','Clear Styles',()=>command('removeFormat')).classList.add('designer-rich-label-tool');
  const sourceToggle=tool('Edit text HTML source','<>',()=>{
    const show=source.hidden;if(show){source.value=rich.innerHTML;remember();}else{history();setRichContent(rich,source.value);range=null;sync();}
    source.hidden=!show;rich.hidden=show;sourceToggle.setAttribute('aria-pressed',String(show));toolbar.querySelectorAll('button,select').forEach(n=>{if(n!==sourceToggle&&n!==collapse)n.disabled=show;});if(!show){undo.disabled=!past.length;redo.disabled=!future.length;}
  });
  row=second;
  const select=(label,options,fn)=>{
    const input=el('select');input.setAttribute('aria-label',label);input.title=label;for(const [value,text]of options){const option=el('option',text);option.value=value;input.append(option);}input.onpointerdown=remember;input.onchange=()=>{fn(input.value);};row.append(input);return input;
  };
  select('Formatting style',[['','Default Text'],['p','Paragraph'],['h1','Heading 1'],['h2','Heading 2'],['h3','Heading 3'],['h4','Heading 4'],['blockquote','Quote']],value=>value&&command('formatBlock',value));
  select('Selected text font',[['','Font'],...Object.entries(fonts||Object.fromEntries(['Arial','Georgia','Verdana','Tahoma','Times New Roman'].map(v=>[v,v])))],value=>value&&edit(()=>{
    onFont(value);document.execCommand('styleWithCSS',false,true);document.execCommand('fontName',false,value);
  }));
  select('Selected text size',[['','Size'],...[8,9,10,11,12,13,14,16,18,20,22,24,26,28,30,32,35,36,40,44,48,54,60,66,72,80,88,96].map(v=>[String(v),String(v)])],value=>value&&edit(()=>{
    document.execCommand('styleWithCSS',false,true);document.execCommand('fontSize',false,'7');
    applyStyles();
    const selection=window.getSelection().getRangeAt(0);
    for(const n of rich.querySelectorAll('font[size="7"],span[style]'))if(selection.intersectsNode(n)&&(n.getAttribute('size')==='7'||n.style.fontSize==='xxx-large')){n.removeAttribute('size');n.style.fontSize=value+'px';}
  }));
  const applyColor=(property,value,{focus=true,rememberSelection=true}={})=>{
    if(rememberSelection)remember();if(!range||!rich.contains(range.commonAncestorContainer))return;
    history();applying=true;
    try{
      range=colorTextRange(rich,range,property,value);onSelection(range);
      if(focus){rich.focus({preventScroll:true});const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);}
    }finally{applying=false;}
    sync();
  };
  for(const [label,property,highlight]of [['Selected text color','color',false],['Selected text highlight','backgroundColor',true]])mountTextColor({host:row,label,highlight,makeElement:el,makeButton:button,remember,apply:(value,options)=>applyColor(property,value,options),signal});
  row=first;
  tool('Insert horizontal line','',()=>command('insertHorizontalRule'),'line');
  tool('Insert anchor','',()=>{
    remember();const input=el('input');input.setAttribute('aria-label','Anchor name');input.required=true;input.pattern='[a-zA-Z][a-zA-Z0-9_-]{0,79}';input.maxLength=80;
    const dialog=inputDialog('Insert anchor',input,'Name this position so another link can jump here. Use letters, numbers, hyphens, or underscores.',()=>{if([...rich.querySelectorAll('[id]')].some(n=>n.id===input.value)){input.setCustomValidity('This anchor name is already in use.');input.reportValidity();return false;}edit(()=>{const marker=el('span','\u200b');marker.id=input.value;const selection=window.getSelection().getRangeAt(0);selection.collapse(true);selection.insertNode(marker);selection.setStartAfter(marker);selection.collapse(true);});});input.oninput=()=>input.setCustomValidity('');dialog.showModal();input.focus();
  },'anchor');
  row=second;
  const merge=select('Insert merge tag',[['','Merge Tags'],...Object.entries(mergeTags)],value=>{if(value)command('insertText',`*|${value}|*`);merge.value='';});
  const direction=value=>edit(()=>{
    const selection=window.getSelection().getRangeAt(0);let nodes=[...rich.querySelectorAll('p,div,h1,h2,h3,h4,li,blockquote')].filter(n=>selection.intersectsNode(n));
    if(!nodes.length){const wrapper=el('div');wrapper.append(...rich.childNodes);rich.append(wrapper);nodes=[wrapper];}
    nodes.forEach(n=>n.style.direction=value);
  });
  tool('Left to right text','¶→',()=>direction('ltr'));tool('Right to left text','←¶',()=>direction('rtl'));
  for(const [name,label,icon]of [['justifyLeft','Align left','left'],['justifyCenter','Align center','center'],['justifyRight','Align right','right'],['justifyFull','Justify text','justify']]){const control=tool(label,'',()=>command(name),icon);stateButtons.push([control,name]);}
  const travel=(from,to)=>{
    if(!from.length)return;history();to.push(lastHtml);lastHtml=from.pop();setRichContent(rich,lastHtml);range=null;block.html=lastHtml;block.text=rich.innerText;changed();renderCanvas();restore();remember();undo.disabled=!past.length;redo.disabled=!future.length;
  };
  row=first;
  const undo=tool('Undo text change','',()=>travel(past,future),'undo'),redo=tool('Redo text change','',()=>travel(future,past),'redo');undo.disabled=redo.disabled=true;
  tool('Strikethrough','S̶',()=>command('strikeThrough'));
  const collapse=button('▴',()=>{second.hidden=!second.hidden;collapse.textContent=second.hidden?'▾':'▴';collapse.setAttribute('aria-expanded',String(!second.hidden));collapse.setAttribute('aria-label',second.hidden?'Expand text toolbar':'Collapse text toolbar');});collapse.className='designer-rich-collapse';collapse.setAttribute('aria-label','Collapse text toolbar');collapse.setAttribute('aria-expanded','true');first.append(collapse);
  rich.addEventListener('keydown',e=>{if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.stopPropagation();e.shiftKey?travel(future,past):travel(past,future);}},{signal});
  source.oninput=()=>{history();setRichContent(rich,source.value);range=null;sync();};
  host.append(toolbar,rich,source);
  const imageTools=mountRichImageTools({host,rich,makeElement:el,makeButton:button,edit:(fn,options)=>edit(fn,{...options,restoreSelection:false}),history,sync,signal});
  return ()=>{lifecycle.abort();dialogs.forEach(d=>d.remove());};
}
