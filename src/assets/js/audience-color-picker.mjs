// In-page Brand Kit color picker. No platform color window or native fallback.
const node=(tag,cls)=>{const n=document.createElement(tag);if(cls)n.className=cls;return n;};
export function hexToHsv(hex) {
  const [r,g,b]=hex.slice(1).match(/../g).map(v=>parseInt(v,16)/255),max=Math.max(r,g,b),min=Math.min(r,g,b),d=max-min;
  let h=0;if(d)h=(max===r?(g-b)/d+(g<b?6:0):max===g?(b-r)/d+2:(r-g)/d+4)*60;
  return [h,max===0?0:d/max,max];
}
export function hsvToHex(h,s,v) {
  const f=n=>{const k=(n+h/60)%6;return Math.round(255*(v-v*s*Math.max(0,Math.min(k,4-k,1)))).toString(16).padStart(2,'0');};
  return '#'+f(5)+f(3)+f(1);
}
export function enhanceColorInput(input,{label,tile=false,palette=[]}={}) {
  if(input.dataset.colorEnhanced)return input.parentElement;
  input.dataset.colorEnhanced='true';
  label||=input.getAttribute('aria-label')||input.labels?.[0]?.textContent.trim()||'Color';
  // Replace the surrounding field label so the popup does not nest labels.
  if(input.parentElement.tagName==='LABEL'){
    const original=input.parentElement,field=node('div',original.className||'audience-color-field');
    const title=node('span');title.textContent=label;field.append(title);original.replaceWith(field);field.append(input);
  }
  const wrap=node('span','audience-color-control'+(tile?' audience-color-tile':'')),trigger=node('button','audience-color-trigger');trigger.type='button';
  trigger.setAttribute('aria-label',label);trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');
  const chip=node('span','audience-color-chip'),caption=node('span','audience-color-code');trigger.append(chip,caption);
  input.before(wrap);wrap.append(input,trigger);input.hidden=true;input.tabIndex=-1;
  const panel=node('div','audience-color-popover');panel.setAttribute('popover','auto');panel.setAttribute('role','dialog');panel.setAttribute('aria-label',label+' color picker');
  const plane=node('div','audience-color-plane'),marker=node('span','audience-color-marker');plane.tabIndex=0;plane.setAttribute('role','slider');plane.setAttribute('aria-label','Color saturation and brightness');plane.setAttribute('aria-valuemin','0');plane.setAttribute('aria-valuemax','100');plane.append(marker);
  const controls=node('div','audience-color-sliders'),hue=node('input','audience-color-hue'),sample=node('span','audience-color-sample');hue.type='range';hue.min='0';hue.max='360';hue.step='1';hue.setAttribute('aria-label','Hue');controls.append(hue,sample);
  const hexLabel=node('div','audience-color-hex'),prefix=node('span');prefix.textContent='#';const hex=node('input');hex.type='text';hex.maxLength=6;hex.pattern='[a-fA-F0-9]{6}';hex.required=true;hex.setAttribute('aria-label',label+' hex color code');hexLabel.append(prefix,hex);
  panel.append(plane,controls,hexLabel);wrap.append(panel);
  let hsv=hexToHsv(input.value),current=input.value,dragging=false,session=0,openEvents;
  const paint=()=>{plane.style.backgroundColor=hsvToHex(hsv[0],1,1);marker.style.left=(hsv[1]*100)+'%';marker.style.top=((1-hsv[2])*100)+'%';hue.value=String(Math.round(hsv[0]));hex.value=current.slice(1).toUpperCase();sample.style.backgroundColor=current;plane.setAttribute('aria-valuenow',String(Math.round(hsv[1]*100)));plane.setAttribute('aria-valuetext',`${current}, saturation ${Math.round(hsv[1]*100)}%, brightness ${Math.round(hsv[2]*100)}%`);};
  const sync=()=>{current=input.value;hsv=hexToHsv(current);chip.style.backgroundColor=current;caption.textContent=current.toUpperCase();paint();};
  const close=()=>{if(panel.matches(':popover-open'))panel.hidePopover();};
  const commit=()=>{if(!wrap.isConnected||input.matches(':disabled'))return;input.value=current;chip.style.backgroundColor=current;caption.textContent=current.toUpperCase();input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));};
  const select=()=>{commit();close();trigger.focus();};
  const locate=()=>{const r=trigger.getBoundingClientRect(),w=Math.min(312,innerWidth-16);panel.style.width=w+'px';panel.style.left=Math.max(8,Math.min(r.left,innerWidth-w-8))+'px';const height=panel.offsetHeight;panel.style.top=Math.max(8,r.bottom+6+height<=innerHeight-8?r.bottom+6:r.top-height-6)+'px';};
  trigger.onclick=()=>{if(input.matches(':disabled'))return;if(panel.matches(':popover-open')){close();return;}sync();panel.showPopover();locate();trigger.setAttribute('aria-expanded','true');session++;openEvents?.abort();openEvents=new AbortController();window.addEventListener('resize',close,{signal:openEvents.signal});document.addEventListener('scroll',close,{capture:true,signal:openEvents.signal});};
  panel.addEventListener('toggle',()=>{const open=panel.matches(':popover-open');trigger.setAttribute('aria-expanded',String(open));if(!open){session++;openEvents?.abort();dragging=false;sync();}});
  const point=e=>{const r=plane.getBoundingClientRect();hsv[1]=Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));hsv[2]=1-Math.max(0,Math.min(1,(e.clientY-r.top)/r.height));current=hsvToHex(...hsv);paint();};
  plane.onpointerdown=e=>{if(e.button!==0)return;e.preventDefault();dragging=true;plane.setPointerCapture(e.pointerId);point(e);};
  plane.onpointermove=e=>{if(dragging)point(e);};plane.onpointerup=e=>{if(!dragging)return;point(e);dragging=false;select();};plane.onpointercancel=()=>{dragging=false;sync();};
  plane.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();select();return;}if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;e.preventDefault();const step=e.shiftKey ? .1 : .01;if(e.key==='ArrowLeft')hsv[1]-=step;if(e.key==='ArrowRight')hsv[1]+=step;if(e.key==='ArrowUp')hsv[2]+=step;if(e.key==='ArrowDown')hsv[2]-=step;hsv[1]=Math.max(0,Math.min(1,hsv[1]));hsv[2]=Math.max(0,Math.min(1,hsv[2]));current=hsvToHex(...hsv);paint();};
  hue.oninput=()=>{hsv[0]=Number(hue.value);current=hsvToHex(...hsv);paint();};hue.onchange=select;
  const hexCommit=()=>{if(!hex.checkValidity())return;current='#'+hex.value.toLowerCase();hsv=hexToHsv(current);select();};
  hex.onchange=hexCommit;hex.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();if(hex.reportValidity())hexCommit();}};
  panel.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();trigger.focus();}});
  if(typeof window.EyeDropper==='function'){
    const dropper=node('button','audience-color-dropper');dropper.type='button';dropper.title='Pick color';dropper.setAttribute('aria-label','Pick color with eyedropper');
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');const path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d','m14 5 5 5M5 15l9-9 4 4-9 9-5 1zM15 5l3-3 4 4-3 3');svg.append(path);dropper.append(svg);controls.prepend(dropper);
    dropper.onclick=async()=>{const generation=session;try{const result=await new window.EyeDropper().open();if(generation===session&&panel.matches(':popover-open')&&wrap.isConnected){current=result.sRGBHex;hsv=hexToHsv(current);select();}}catch{/* Escape leaves the color unchanged. */}};
  }
  const brandColors=palette.filter(color=>/^#[a-f0-9]{6}$/i.test(color.value));
  if(brandColors.length){
    const colors=node('div','audience-color-brand-palette'),heading=node('strong');heading.textContent='Brand Kit colors';colors.append(heading);
    brandColors.forEach(color=>{const swatch=node('button','audience-color-brand-swatch');swatch.type='button';swatch.style.backgroundColor=color.value;swatch.setAttribute('aria-label',`Brand Kit ${color.label}: ${color.value.toUpperCase()}`);
      swatch.onclick=()=>{if(input.matches(':disabled'))return;current=color.value.toLowerCase();hsv=hexToHsv(current);select();};colors.append(swatch);
    });panel.append(colors);
  }
  input.addEventListener('change',sync);input.form?.addEventListener('reset',()=>queueMicrotask(sync));sync();return wrap;
}
