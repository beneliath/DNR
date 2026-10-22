// A button-based palette also lets the same color be applied to successive selections.
export const textColors = [
  '#000000','#800000','#8b4513','#2f4f4f','#008080','#000080','#4b0082','#696969',
  '#b22222','#a52a2a','#daa520','#006400','#40e0d0','#0000cd','#800080','#808080',
  '#ff0000','#ff8c00','#ffd700','#008000','#00ffff','#0000ff','#ee82ee','#a9a9a9',
  '#ffa07a','#ffa500','#ffff00','#00ff00','#afeeee','#add8e6','#dda0dd','#d3d3d3',
  '#fff0f5','#faebd7','#ffffe0','#f0fff0','#f0ffff','#f0f8ff','#e6e6fa','#ffffff',
];

// Match the classic editor's extended palette: twelve rows of web colors,
// followed by the grayscale row. Selection is committed only with OK.
export function extendedTextColors() {
  const colors=[];
  for(const half of [0,3])for(let blue=0;blue<6;blue++)for(let red=half;red<half+3;red++)for(let green=0;green<6;green++)colors.push('#'+[red,green,blue].map(v=>(v*51).toString(16).padStart(2,'0')).join(''));
  return [...colors,'#000000',...Array.from({length:16},(_,i)=>'#'+(i*17).toString(16).padStart(2,'0').repeat(3)),'#ffffff'];
}

export function mountTextColor({host,label,highlight=false,makeElement:el,makeButton:button,remember,apply,signal}) {
  const wrap=el('span',undefined,'designer-text-color');
  let selected=highlight?'#ffff00':'#24292d',sampleGeneration=0;
  const trigger=button('',()=>{
    remember();const open=menu.hidden;
    host.querySelectorAll('.designer-color-menu').forEach(n=>n.hidden=true);
    host.querySelectorAll('.designer-text-color>button').forEach(n=>n.setAttribute('aria-expanded','false'));
    menu.hidden=!open;trigger.setAttribute('aria-expanded',String(open));
    if(open){menu.style.left='0px';const box=menu.getBoundingClientRect();if(box.right>innerWidth-8)menu.style.left=Math.min(0,innerWidth-8-box.right)+'px';}
  });
  trigger.className='designer-color-trigger';trigger.setAttribute('aria-label',label);trigger.title=label;trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');
  const letter=el('span','A',highlight?'designer-color-letter highlight':'designer-color-letter');
  letter.style.setProperty('--selected-color',selected);trigger.append(letter,el('span','▾','designer-color-caret'));
  const menu=el('div',undefined,'designer-color-menu');menu.hidden=true;menu.setAttribute('role','dialog');menu.setAttribute('aria-label',label+' palette');
  const close=()=>{menu.hidden=true;trigger.setAttribute('aria-expanded','false');};
  const choose=value=>{if(!wrap.isConnected||signal.aborted)return;close();if(dialog.open)dialog.close('applied');sampleGeneration++;if(/^#[a-f0-9]{6}$/i.test(value))selected=value;letter.style.setProperty('--selected-color',value==='inherit'?'currentColor':value);apply(value,{focus:true,rememberSelection:false});};
  const automatic=button(highlight?'No color':'Automatic',()=>choose(highlight?'transparent':'inherit'));automatic.className='designer-color-automatic';menu.append(automatic);
  const grid=el('div',undefined,'designer-color-grid');grid.setAttribute('role','group');grid.setAttribute('aria-label','Color swatches');
  for(const value of textColors){const swatch=button('',()=>choose(value));swatch.className='designer-color-swatch';swatch.title=value.toUpperCase();swatch.setAttribute('aria-label',value.toUpperCase());swatch.style.backgroundColor=value;grid.append(swatch);}
  menu.append(grid);
  const dialog=el('dialog',undefined,'designer-color-dialog');dialog.setAttribute('aria-label',label+' — Select color');
  const header=el('header');header.append(el('h2','Select color'));const dismiss=button('×',()=>dialog.close());dismiss.setAttribute('aria-label','Close color selection');header.append(dismiss);
  const content=el('div',undefined,'designer-color-dialog-content'),colors=el('div',undefined,'designer-color-extended');colors.setAttribute('role','group');colors.setAttribute('aria-label','Color Options');
  const info=el('div',undefined,'designer-color-info'),hover=el('span',undefined,'designer-color-preview'),hoverCode=el('small'),chosen=el('span',undefined,'designer-color-preview');
  const hexLabel=el('label','Selected Color'),hex=el('input');hex.type='text';hex.required=true;hex.pattern='#?[a-fA-F0-9]{6}';hex.maxLength=7;hex.setAttribute('aria-label',label+' hex');hexLabel.append(hex);
  const normalize=value=>'#'+value.replace(/^#/,'').toLowerCase();
  const preview=value=>{hover.style.backgroundColor=value;hoverCode.textContent=value.toUpperCase();};
  const stage=value=>{hex.value=value;hex.setCustomValidity('');chosen.style.backgroundColor=value;};
  for(const value of extendedTextColors()){
    const swatch=button('',()=>stage(value));swatch.className='designer-color-option';swatch.style.backgroundColor=value;swatch.title=value.toUpperCase();swatch.setAttribute('aria-label',value.toUpperCase());swatch.onpointerenter=swatch.onfocus=()=>preview(value);colors.append(swatch);
  }
  hex.oninput=()=>{if(hex.checkValidity())chosen.style.backgroundColor=normalize(hex.value);};
  const clear=button('Clear',()=>{hex.value='';chosen.style.backgroundColor='transparent';hex.focus();});clear.className='designer-color-clear';
  info.append(el('span','Highlight'),hover,hoverCode,hexLabel,chosen,clear);
  if(typeof window.EyeDropper==='function'){
    const dropper=button('Eyedropper',async()=>{const generation=++sampleGeneration;try{const result=await new window.EyeDropper().open();if(generation===sampleGeneration&&dialog.open&&!signal.aborted)stage(result.sRGBHex);}catch{/* Cancel keeps the current color. */}});dropper.title='Pick a color from your screen';info.append(dropper);
  }
  content.append(colors,info);
  const actions=el('footer'),ok=button('OK',()=>{if(hex.reportValidity())choose(normalize(hex.value));}),cancel=button('Cancel',()=>dialog.close());ok.className='primary';actions.append(ok,cancel);dialog.append(header,content,actions);
  dialog.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target===hex){e.preventDefault();ok.click();}});
  dialog.addEventListener('close',()=>{sampleGeneration++;if(dialog.returnValue!=='applied')trigger.focus();});
  const more=button('More Colors…',()=>{remember();close();stage(selected);preview(selected);dialog.returnValue='';dialog.showModal();hex.focus();});more.className='designer-color-more';menu.append(more);
  wrap.addEventListener('mousedown',e=>{if(dialog.contains(e.target))return;remember();if(e.target.closest('button'))e.preventDefault();},{signal});
  wrap.addEventListener('keydown',e=>{if(e.key==='Escape'&&!dialog.open){close();trigger.focus();}},{signal});
  document.addEventListener('pointerdown',e=>{if(!wrap.contains(e.target)&&!e.target.closest('.designer-rich-text'))close();},{signal});
  signal.addEventListener('abort',()=>{sampleGeneration++;dialog.remove();},{once:true});
  wrap.append(trigger,menu,dialog);host.append(wrap);return wrap;
}
