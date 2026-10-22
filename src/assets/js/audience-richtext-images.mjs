// Keep only sizing and wrapping that can also be retained by the email sanitizer.
export function normalizeRichImage(image) {
  const width=image.getAttribute('width')||image.style.width.replace(/px$/,'');
  const side=['left','right'].includes(image.getAttribute('align'))?image.getAttribute('align'):image.style.float;
  image.removeAttribute('width');image.removeAttribute('height');image.removeAttribute('align');
  image.style.cssText='max-width:100%;height:auto';
  if(/^\d{1,4}$/.test(width)&&Number(width)>0&&Number(width)<=2400){image.width=Number(width);image.style.width=Number(width)+'px';}
  if(['left','right'].includes(side)){image.setAttribute('align',side);image.style.cssFloat=side;image.style.margin=side==='left'?'0 12px 12px 0':'0 0 12px 12px';}
}

export function mountRichImageTools({host,rich,makeElement:el,makeButton:button,edit,history,sync,signal}) {
  const surface=el('div',undefined,'designer-rich-surface');rich.replaceWith(surface);surface.append(rich);
  const controls=el('div',undefined,'designer-rich-image-tools');controls.setAttribute('role','group');controls.setAttribute('aria-label','Image layout');controls.hidden=true;surface.before(controls);
  const widthLabel=el('label','Width (px)'),width=el('input');width.type='number';width.min='1';width.max='2400';width.step='1';width.setAttribute('aria-label','Image width (px)');widthLabel.append(width);controls.append(widthLabel);
  let selected=null,dragged=null,resizing=null;
  const outline=el('div',undefined,'designer-rich-image-outline'),handle=button('',()=>{});handle.className='designer-rich-image-resize';handle.setAttribute('aria-label','Resize image');handle.title='Drag to resize. Arrow keys adjust width.';outline.hidden=true;outline.append(handle);surface.append(outline);
  const caret=el('div',undefined,'designer-rich-image-caret');caret.hidden=true;surface.append(caret);
  const buttons=[];
  const valid=()=>selected&&rich.contains(selected)&&!rich.hidden;
  const position=()=>{
    if(!valid()){selected=null;outline.hidden=controls.hidden=true;return;}
    const rect=selected.getBoundingClientRect(),editor=surface.getBoundingClientRect(),parent=editor;
    outline.hidden=rect.bottom<=editor.top||rect.top>=editor.bottom;
    Object.assign(outline.style,{left:rect.left-parent.left+surface.scrollLeft-surface.clientLeft+'px',top:rect.top-parent.top+surface.scrollTop-surface.clientTop+'px',width:rect.width+'px',height:rect.height+'px'});
  };
  const refresh=()=>{
    if(!valid()){position();return;}
    controls.hidden=false;if(document.activeElement!==width)width.value=String(selected.getAttribute('width')||Math.round(selected.getBoundingClientRect().width));
    const side=selected.getAttribute('align')||selected.style.float||'none';buttons.forEach(([control,value])=>control.setAttribute('aria-pressed',String(side===value)));position();
  };
  const choose=image=>{selected=image;refresh();};
  const setWidth=value=>{const n=Math.max(1,Math.min(2400,Math.round(value)));selected.width=n;selected.style.width=n+'px';selected.style.height='auto';selected.removeAttribute('height');};
  const applyWidth=()=>{if(valid()&&width.validity.valid&&Number(width.value)>0){const value=Number(width.value);if(Number(selected.getAttribute('width'))!==value)edit(()=>setWidth(value),{focus:false});refresh();}};
  width.addEventListener('input',applyWidth,{signal});width.addEventListener('change',applyWidth,{signal});
  for(const [label,value]of [['Wrap left','left'],['Inline','none'],['Wrap right','right']]){
    const control=button(label,()=>{if(!valid())return;edit(()=>{if(!selected.hasAttribute('width'))setWidth(Math.round(selected.getBoundingClientRect().width));selected.removeAttribute('align');selected.style.cssFloat=value;normalizeRichImage(selected);},{focus:false});refresh();});
    control.setAttribute('aria-label',label==='Inline'?'Image inline without wrapping':label+' image');control.onmousedown=e=>e.preventDefault();buttons.push([control,value]);controls.append(control);
  }
  controls.append(el('small','Drag the image to move it. Drag its corner to resize.'));
  rich.addEventListener('click',event=>{const image=event.target.closest('img');choose(image&&rich.contains(image)?image:null);},{signal});
  rich.addEventListener('load',()=>refresh(),{capture:true,signal});
  rich.addEventListener('scroll',position,{signal});surface.addEventListener('scroll',position,{signal});window.addEventListener('resize',position,{signal});
  const observer=new MutationObserver(()=>{if(!resizing)refresh();});observer.observe(rich,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});signal.addEventListener('abort',()=>observer.disconnect(),{once:true});

  handle.addEventListener('pointerdown',event=>{
    if(!valid())return;event.preventDefault();event.stopPropagation();history();
    resizing={pointer:event.pointerId,x:event.clientX,y:event.clientY,width:selected.getBoundingClientRect().width,height:selected.getBoundingClientRect().height,style:selected.getAttribute('style'),attrWidth:selected.getAttribute('width'),attrHeight:selected.getAttribute('height')};
    handle.setPointerCapture(event.pointerId);
  },{signal});
  handle.addEventListener('pointermove',event=>{
    if(!resizing||event.pointerId!==resizing.pointer||!valid())return;
    const dx=event.clientX-resizing.x,dy=(event.clientY-resizing.y)*resizing.width/Math.max(1,resizing.height);
    const editor=rich.getBoundingClientRect(),space=Math.max(1,editor.width-26-(selected.getAttribute('align')?12:0));
    setWidth(Math.min(space,resizing.width+(Math.abs(dx)>=Math.abs(dy)?dx:dy)));width.value=selected.width;position();
  },{signal});
  const endResize=cancel=>{
    if(!resizing)return;const previous=resizing;resizing=null;
    if(cancel&&valid())for(const [name,value]of [['style',previous.style],['width',previous.attrWidth],['height',previous.attrHeight]]){if(value===null)selected.removeAttribute(name);else selected.setAttribute(name,value);}
    else if(valid())sync();refresh();
  };
  handle.addEventListener('pointerup',()=>endResize(false),{signal});handle.addEventListener('pointercancel',()=>endResize(true),{signal});
  handle.addEventListener('keydown',event=>{if(!valid()||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();edit(()=>setWidth(Number(width.value)+(['ArrowLeft','ArrowUp'].includes(event.key)?-10:10)),{focus:false});refresh();},{signal});

  const atPoint=(x,y)=>{
    let range=null;
    if(document.caretPositionFromPoint){const point=document.caretPositionFromPoint(x,y);if(point){range=document.createRange();range.setStart(point.offsetNode,point.offset);range.collapse(true);}}
    else if(document.caretRangeFromPoint)range=document.caretRangeFromPoint(x,y);
    return range&&rich.contains(range.startContainer)?range:null;
  };
  rich.addEventListener('dragstart',event=>{
    const image=event.target.closest('img');if(!image||!rich.contains(image))return;
    choose(image);dragged=image.parentElement.tagName==='A'&&image.parentElement.childNodes.length===1?image.parentElement:image;
    event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',image.alt||'Image');
  },{signal});
  rich.addEventListener('dragover',event=>{
    event.preventDefault();if(!dragged)return;event.dataTransfer.dropEffect='move';const range=atPoint(event.clientX,event.clientY);if(!range)return;
    const rect=range.getBoundingClientRect(),parent=surface.getBoundingClientRect();caret.hidden=false;Object.assign(caret.style,{left:rect.left-parent.left+surface.scrollLeft-surface.clientLeft+'px',top:rect.top-parent.top+surface.scrollTop-surface.clientTop+'px',height:Math.max(18,rect.height)+'px'});
  },{signal});
  rich.addEventListener('drop',event=>{
    event.preventDefault();event.stopPropagation();caret.hidden=true;
    const node=dragged;dragged=null;const target=atPoint(event.clientX,event.clientY);
    if(!node||!rich.contains(node)||!target||node.contains(target.startContainer))return;
    edit(()=>{target.insertNode(node);target.setStartAfter(node);target.collapse(true);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(target);});refresh();
  },{signal});
  rich.addEventListener('dragend',()=>{dragged=null;caret.hidden=true;},{signal});
  rich.addEventListener('dragleave',event=>{if(!rich.contains(event.relatedTarget))caret.hidden=true;},{signal});
  return {select:choose};
}
