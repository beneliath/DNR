// A real spacer keeps the proposed insertion point visible without changing
// the design. Its rectangle also acts as a stable target while blocks move.
export function mountBlockDropSpace({canvas,getDrag,onDrop}) {
  const gap=document.createElement('div');gap.className='designer-block-drop-space';
  gap.setAttribute('role','status');gap.setAttribute('aria-live','polite');
  let target=null;
  const clear=()=>{gap.remove();target=null;};
  const active=()=>{const drag=getDrag();return drag&&(drag.type||drag.position)?drag:null;};
  const update=(x,y)=>{
    const drag=active();if(!drag){clear();return;}
    const hit=document.elementFromPoint(x,y);
    if(!hit||!canvas.contains(hit)){clear();return;}
    if(gap.contains(hit))return target;
    const column=hit.closest('.designer-column');if(!column){clear();return;}
    const blocks=[...column.children].filter(n=>n.classList.contains('designer-block'));
    const index=blocks.findIndex(block=>{const rect=block.getBoundingClientRect();return y<rect.top+rect.height/2;});
    const next=[Number(column.dataset.row),Number(column.dataset.column),index<0?blocks.length:index];
    if(drag.position&&next[0]===drag.position[0]&&next[1]===drag.position[1]&&[drag.position[2],drag.position[2]+1].includes(next[2])){clear();return;}
    if(target?.join(',')===next.join(','))return target;
    target=next;
    const name=drag.type?drag.type.replace(/-/g,' '):'content';
    gap.textContent='Drop '+name+' block here';
    column.insertBefore(gap,blocks[next[2]]||null);
    return target;
  };
  const hover=event=>{
    if(!active())return;event.preventDefault();event.stopPropagation();
    event.dataTransfer.dropEffect=getDrag().type?'copy':'move';update(event.clientX,event.clientY);
  };
  canvas.addEventListener('dragenter',hover);canvas.addEventListener('dragover',hover);
  canvas.addEventListener('drop',event=>{
    if(!active())return;event.preventDefault();event.stopPropagation();
    const destination=update(event.clientX,event.clientY);clear();if(destination)onDrop(destination);
  });
  canvas.addEventListener('dragleave',event=>{
    // Moving content to open the gap can produce a child dragleave with no
    // relatedTarget. Keep the gap while the pointer is still over the canvas.
    const rect=canvas.getBoundingClientRect();
    if(event.relatedTarget? !canvas.contains(event.relatedTarget):event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)clear();
  });
  document.addEventListener('dragover',event=>{if(!canvas.contains(event.target))clear();});
  document.addEventListener('dragend',clear);document.addEventListener('drop',clear);
  return {clear,update};
}
