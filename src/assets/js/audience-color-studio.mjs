import {normalizeColor,hexToHsv,hsvToHex,colorPalette,colorLuminance,colorContrast} from './audience-color-values.mjs';
import {sampleSourceCanvas,sampleCanvasColor} from './audience-color-sampler.mjs';

const node=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=text;return n;};
const button=(text,action,cls='')=>{const n=node('button',cls,text);n.type='button';n.addEventListener('click',action);return n;};
function recentKey(){const account=document.body.dataset.audienceKey||location.pathname.split('/').slice(0,-1).join('/');return 'mm-color-recent:'+account;}
function recentColors(){try{return [...new Set(JSON.parse(localStorage.getItem(recentKey())||'[]').map(normalizeColor).filter(Boolean))].slice(0,8);}catch{return [];}}
function rememberColor(color){try{localStorage.setItem(recentKey(),JSON.stringify([color,...recentColors().filter(v=>v!==color)].slice(0,8)));}catch{/* Optional browser preference. */}}

/** One staged color dialog shared by rich text, design styles, forms and Brand Kit. */
export function openColorStudio({label='Color',value,palette=[],scope,signal,onApply,onClose=()=>{},automaticValue=null,automaticLabel='Automatic',previewText='Your prayers and support make a difference.',previewStyle={},highlight=false,sampleSources=()=>[]}) {
  if(signal?.aborted||!scope?.isConnected)return null;
  const initial=normalizeColor(value),fallback=highlight?'#ffff00':'#24292d';
  let current=initial||fallback,hsv=hexToHsv(current),dirty=false,generation=0,disposed=false;
  const dialog=node('dialog','audience-color-studio');dialog.setAttribute('aria-label',label+' color studio');
  const header=node('header','color-studio-heading'),title=node('h2',null,label.replace(/^Selected /,'').replace(/^./,v=>v.toUpperCase())),intro=node('p',null,'Choose a brand color or create your own'),heading=node('div');heading.append(title,intro);
  const close=()=>{if(dialog.open)dialog.close();cleanup();};
  const dismiss=button('×',close,'color-studio-close');dismiss.setAttribute('aria-label','Close color selection');header.append(heading,dismiss);
  const main=node('div','color-studio-main'),columns=node('div','color-studio-columns'),custom=node('section','color-studio-custom'),brands=node('section','color-studio-brands');
  const plane=button('',()=>{},'color-studio-plane'),marker=node('span','color-studio-marker');plane.setAttribute('role','slider');plane.setAttribute('aria-label','Color saturation and brightness');plane.setAttribute('aria-valuemin','0');plane.setAttribute('aria-valuemax','100');plane.append(marker);
  const hueLabel=node('label','color-studio-hue-label'),hueText=node('span',null,'Hue'),hueValue=node('span'),hue=node('input','color-studio-hue');hue.type='range';hue.min='0';hue.max='360';hue.step='1';hue.setAttribute('aria-label','Hue');hueLabel.append(hueText,hueValue,hue);
  const hexLabel=node('label','color-studio-field','Hex color'),hexRow=node('span','color-studio-hex-row'),chip=node('span','color-studio-chip'),hex=node('input','color-studio-hex');hex.type='text';hex.required=true;hex.pattern='#?[a-fA-F0-9]{6}';hex.maxLength=7;hex.spellcheck=false;hex.setAttribute('aria-label',label+' hex');hexRow.append(chip,hex);hexLabel.append(hexRow);
  const dropper=button('Pick from preview or image',()=>showSampler(),'color-studio-dropper');dropper.setAttribute('aria-label','Pick from preview or image');
  const dropperIcon=document.createElementNS('http://www.w3.org/2000/svg','svg'),dropperPath=document.createElementNS(dropperIcon.namespaceURI,'path');dropperIcon.setAttribute('viewBox','0 0 24 24');dropperIcon.setAttribute('aria-hidden','true');dropperPath.setAttribute('d','m14 5 5 5M5 15l9-9 4 4-9 9-5 1zM15 5l3-3 4 4-3 3');dropperIcon.append(dropperPath);dropper.prepend(dropperIcon);
  const rgb=node('div','color-studio-rgb'),channels=['R','G','B'].map(name=>{const field=node('label',null,name),input=node('input');input.type='number';input.min='0';input.max='255';input.step='1';input.required=true;input.setAttribute('aria-label',label+' '+name);field.append(input);rgb.append(field);return input;});
  const compare=node('div','color-studio-compare'),oldChip=node('span','color-studio-chip'+(!initial?' color-studio-transparent':'')),newChip=node('span','color-studio-chip');oldChip.style.backgroundColor=initial||'transparent';
  for(const [swatch,text]of [[oldChip,'Current'],[newChip,'New']]){const group=node('span');group.append(swatch,node('span',null,text));compare.append(group);}
  const validation=node('p','color-studio-error');validation.setAttribute('role','alert');validation.hidden=true;
  custom.append(plane,hueLabel,hexLabel,dropper,rgb,validation,compare);
  const brandHeading=node('div','color-studio-section-heading');brandHeading.append(node('h3',null,'Brand Kit'),node('span',null,'Saved in your account'));
  const brandList=node('div','color-studio-brand-list');brandList.setAttribute('role','group');brandList.setAttribute('aria-label','Brand Kit colors');
  const colors=colorPalette(typeof palette==='function'?palette():palette);
  const paletteButton=(color,container,brand=false)=>{
    const swatch=node('span','color-studio-swatch'),check=node('span','color-studio-check','✓');check.setAttribute('aria-hidden','true');swatch.style.backgroundColor=color.value;swatch.style.color=colorLuminance(color.value)>.35?'#24292d':'#ffffff';swatch.append(check);
    const control=button('',()=>stage(color.value),brand?'color-studio-brand':'color-studio-recent-color');control.dataset.color=color.value;control.setAttribute('aria-label',(brand?'Brand Kit '+color.label+': ':'')+color.value.toUpperCase());control.append(swatch);
    if(brand){const description=node('span','color-studio-description');description.append(node('span',null,color.label),node('code',null,color.value.toUpperCase()));control.append(description);}
    container.append(control);
  };
  colors.forEach(color=>paletteButton(color,brandList,true));
  if(!colors.length)brandList.append(node('p','color-studio-help','Save colors in Brand Kit to select them here.'));
  const recent=node('section','color-studio-recent'),recentList=node('div','color-studio-recent-list');recent.append(node('h3',null,'Recent'),recentList);recentList.setAttribute('role','group');recentList.setAttribute('aria-label','Recently applied colors');
  const previous=recentColors();previous.forEach(color=>paletteButton({value:color},recentList));if(!previous.length)recentList.append(node('p','color-studio-help','Colors you apply will appear here.'));
  brands.append(brandHeading,brandList,recent);columns.append(custom,brands);
  const preview=node('section','color-studio-preview'),previewLabel=node('span',null,'Preview'),sample=node('span','color-studio-preview-text',String(previewText).slice(0,200)||'Your prayers and support make a difference.'),contrast=node('small');preview.append(previewLabel,sample,contrast);main.append(columns,preview);
  const textStyle=typeof previewStyle==='function'?previewStyle():previewStyle;
  for(const property of ['fontFamily','fontWeight','fontStyle'])if(textStyle?.[property])sample.style[property]=textStyle[property];
  const footer=node('footer','color-studio-footer'),automatic=button(automaticLabel,()=>commit(automaticValue),'color-studio-automatic'),actions=node('div'),cancel=button('Cancel',close),apply=button('Apply color',()=>{if(valid())commit(current);},'primary color-studio-apply');apply.dataset.colorApply='';automatic.hidden=automaticValue===null;actions.append(cancel,apply);footer.append(automatic,actions);
  const sampler=node('section','color-studio-sampler');sampler.hidden=true;
  const sourceLabel=node('label','color-studio-field','Source'),sources=node('select');sources.setAttribute('aria-label','Color sample source');sourceLabel.append(sources);
  const sampleActions=node('div','color-studio-sample-actions'),file=node('input');file.type='file';file.accept='image/png,image/jpeg,image/webp,image/gif,image/svg+xml';file.hidden=true;
  const upload=button('Choose an image…',()=>file.click()),back=button('Back to colors',()=>hideSampler());sampleActions.append(upload,back,file);
  const sampleStatus=node('p','color-studio-help');sampleStatus.setAttribute('role','status');
  const sampleButton=button('',()=>chooseSample(),'color-studio-sample-canvas'),canvas=node('canvas');canvas.width=1;canvas.height=1;sampleButton.setAttribute('aria-label','Image color sampler');sampleButton.append(canvas);sampleButton.hidden=true;
  const sampled=node('div','color-studio-sampled'),sampleChip=node('span','color-studio-chip'),sampleHex=node('code'),useSample=button('Use sampled color',()=>chooseSample(),'primary');useSample.disabled=true;sampled.append(sampleChip,sampleHex,useSample);
  sampler.append(sourceLabel,sampleActions,sampleStatus,sampleButton,sampled);
  dialog.append(header,main,sampler,footer);document.body.append(dialog);
  let sourceItems=[],samplePoint={x:0,y:0},sampleColor=null;

  function live(){return !disposed&&dialog.open&&scope.isConnected&&!signal?.aborted;}
  function paint(){
    plane.style.backgroundColor=hsvToHex(hsv[0],1,1);marker.style.left=(hsv[1]*100)+'%';marker.style.top=((1-hsv[2])*100)+'%';
    plane.setAttribute('aria-valuenow',String(Math.round(hsv[1]*100)));plane.setAttribute('aria-valuetext',`${current}, saturation ${Math.round(hsv[1]*100)}%, brightness ${Math.round(hsv[2]*100)}%`);
    hue.value=String(Math.round(hsv[0]));hueValue.textContent=Math.round(hsv[0])+'°';
    hex.value=current.toUpperCase();
    const values=current.slice(1).match(/../g).map(v=>parseInt(v,16));channels.forEach((input,i)=>{if(document.activeElement!==input)input.value=String(values[i]);});
    chip.style.backgroundColor=newChip.style.backgroundColor=current;
    sample.style.color=highlight?'#24292d':current;sample.style.backgroundColor=highlight?current:'transparent';
    const ratio=colorContrast(current,highlight?'#24292d':'#ffffff');contrast.textContent=`Contrast ${highlight?'with dark text':'on white'}: ${ratio.toFixed(1)}:1${ratio<4.5?' · Low contrast for small text':''}`;
    for(const control of dialog.querySelectorAll('[data-color]'))control.setAttribute('aria-pressed',String(control.dataset.color===current));
    apply.classList.toggle('color-studio-unsaved',dirty);apply.setAttribute('aria-label',dirty?'Apply color, unsaved changes':'Apply color');
  }
  function stage(color){current=color;hsv=hexToHsv(current);dirty=current!==initial;validation.hidden=true;hex.setCustomValidity('');paint();}
  function valid(){
    const color=normalizeColor(hex.value);hex.setCustomValidity(color?'':'Enter a six-digit hex color.');
    const invalid=channels.find(input=>!input.checkValidity());
    if(!color||invalid){validation.textContent=!color?'Enter a six-digit hex color.':'Use whole RGB values between 0 and 255.';validation.hidden=false;(invalid||hex).reportValidity();return false;}
    stage(color);return true;
  }
  function commit(color){
    if(!live())return;dialog.close('applied');if(normalizeColor(color))rememberColor(color);onApply(color);cleanup();
  }
  let dragging=false;
  const point=event=>{const bounds=plane.getBoundingClientRect();hsv[1]=Math.max(0,Math.min(1,(event.clientX-bounds.left)/bounds.width));hsv[2]=1-Math.max(0,Math.min(1,(event.clientY-bounds.top)/bounds.height));const next=hsvToHex(...hsv);stage(next);};
  plane.addEventListener('pointerdown',event=>{if(event.button!==0)return;event.preventDefault();plane.focus();dragging=true;plane.setPointerCapture(event.pointerId);point(event);});
  plane.addEventListener('pointermove',event=>{if(dragging)point(event);});plane.addEventListener('pointerup',event=>{if(dragging)point(event);dragging=false;});plane.addEventListener('pointercancel',()=>dragging=false);
  plane.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();const step=event.shiftKey?.1:.01;if(event.key==='ArrowLeft')hsv[1]-=step;if(event.key==='ArrowRight')hsv[1]+=step;if(event.key==='ArrowUp')hsv[2]+=step;if(event.key==='ArrowDown')hsv[2]-=step;hsv[1]=Math.max(0,Math.min(1,hsv[1]));hsv[2]=Math.max(0,Math.min(1,hsv[2]));stage(hsvToHex(...hsv));});
  hue.addEventListener('input',()=>{hsv[0]=Number(hue.value);const next=hsvToHex(...hsv);current=next;dirty=current!==initial;paint();});
  hex.addEventListener('input',()=>{hex.setCustomValidity('');const color=normalizeColor(hex.value);if(color)stage(color);});
  channels.forEach(input=>input.addEventListener('input',()=>{if(channels.every(n=>n.value!==''&&n.checkValidity()))stage('#'+channels.map(n=>Number(n.value).toString(16).padStart(2,'0')).join(''));}));
  dialog.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.tagName==='INPUT'&&sampler.hidden){event.preventDefault();if(valid())commit(current);}});
  dialog.addEventListener('cancel',event=>{if(!sampler.hidden){event.preventDefault();hideSampler();}});

  async function showSampler(){
    main.hidden=footer.hidden=true;sampler.hidden=false;title.textContent='Pick a color';intro.textContent='Sample the preview, an image in your design, or an image from your computer.';
    sourceItems=(typeof sampleSources==='function'?sampleSources():sampleSources)||[];sourceItems=sourceItems.slice(0,51);sources.replaceChildren();
    sourceItems.forEach((source,i)=>{const option=node('option',null,source.label||'Image '+(i+1));option.value=String(i);sources.append(option);});sourceLabel.hidden=!sourceItems.length;
    if(sourceItems.length)await loadSource(sourceItems[0]);else{sampleStatus.textContent='Choose an image to start sampling.';sampleButton.hidden=true;useSample.disabled=true;upload.focus();}
  }
  function hideSampler(){generation++;sampler.hidden=true;main.hidden=footer.hidden=false;title.textContent=label.replace(/^Selected /,'').replace(/^./,v=>v.toUpperCase());intro.textContent='Choose a brand color or create your own';dropper.focus();}
  async function loadSource(source){
    const version=++generation;sampleStatus.textContent='Preparing image…';sampleButton.hidden=true;useSample.disabled=true;sampleColor=null;
    try{
      const loaded=await sampleSourceCanvas(source);if(!live()||version!==generation)return;
      canvas.width=loaded.width;canvas.height=loaded.height;canvas.getContext('2d',{willReadFrequently:true}).drawImage(loaded,0,0);
      samplePoint={x:Math.floor(canvas.width/2),y:Math.floor(canvas.height/2)};sampleButton.hidden=false;readSample();sampleStatus.textContent='Click to pick a color. Arrow keys move one pixel; Shift moves ten. Enter selects.';sampleButton.focus();
    }catch(error){if(live()&&version===generation){sampleStatus.textContent=error.message;sampleStatus.setAttribute('role','alert');}}
  }
  function readSample(){sampleColor=sampleCanvasColor(canvas,samplePoint.x,samplePoint.y);sampleChip.style.backgroundColor=sampleColor;sampleHex.textContent=sampleColor.toUpperCase();sampleButton.setAttribute('aria-label','Image color sampler, '+sampleColor.toUpperCase());useSample.disabled=false;}
  function chooseSample(){if(!sampleColor)return;stage(sampleColor);hideSampler();}
  const sampleAt=event=>{const bounds=canvas.getBoundingClientRect();samplePoint.x=Math.max(0,Math.min(canvas.width-1,Math.floor((event.clientX-bounds.left)*canvas.width/bounds.width)));samplePoint.y=Math.max(0,Math.min(canvas.height-1,Math.floor((event.clientY-bounds.top)*canvas.height/bounds.height)));readSample();};
  sampleButton.addEventListener('pointermove',sampleAt);sampleButton.addEventListener('pointerdown',sampleAt);
  sampleButton.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key))return;event.preventDefault();const step=event.shiftKey?10:1;if(event.key==='ArrowLeft')samplePoint.x-=step;if(event.key==='ArrowRight')samplePoint.x+=step;if(event.key==='ArrowUp')samplePoint.y-=step;if(event.key==='ArrowDown')samplePoint.y+=step;samplePoint.x=Math.max(0,Math.min(canvas.width-1,samplePoint.x));samplePoint.y=Math.max(0,Math.min(canvas.height-1,samplePoint.y));readSample();});
  sources.addEventListener('change',()=>loadSource(sourceItems[Number(sources.value)]));
  file.addEventListener('change',async()=>{const upload=file.files[0];if(!upload)return;if(upload.size>20000000){sampleStatus.textContent='Choose an image up to 20 MB.';return;}const url=URL.createObjectURL(upload);try{await loadSource({src:url});}finally{URL.revokeObjectURL(url);file.value='';}});
  const observer=new MutationObserver(()=>{if(!scope.isConnected)close();});observer.observe(document.body,{childList:true,subtree:true});
  const abort=()=>{close();if(!dialog.open)cleanup();};signal?.addEventListener('abort',abort,{once:true});
  function cleanup(){if(disposed)return;disposed=true;generation++;observer.disconnect();signal?.removeEventListener('abort',abort);dialog.remove();onClose(dialog.returnValue==='applied');}
  dialog.addEventListener('close',cleanup,{once:true});
  paint();dialog.showModal();hex.focus();return dialog;
}
