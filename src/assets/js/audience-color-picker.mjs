import {openColorStudio} from './audience-color-studio.mjs';
import {colorSampleSources} from './audience-color-sampler.mjs';
export {hexToHsv,hsvToHex} from './audience-color-values.mjs';
const node=(tag,cls)=>{const n=document.createElement(tag);if(cls)n.className=cls;return n;};
export function enhanceColorInput(input,{label,tile=false,palette,sampleSources,previewStyle}={}) {
  if(input.dataset.colorEnhanced)return input.parentElement;
  input.dataset.colorEnhanced='true';
  label||=input.getAttribute('aria-label')||input.labels?.[0]?.textContent.trim()||'Color';input.dataset.colorLabel=label;
  if(input.parentElement.tagName==='LABEL'){
    const original=input.parentElement,field=node('div',original.className||'audience-color-field'),title=node('span');title.textContent=label;field.append(title);original.replaceWith(field);field.append(input);
  }
  const rendered=input.closest('[data-brand-color-tile]');
  const wrap=rendered||node('span','audience-color-control'+(tile?' audience-color-tile':''));
  const trigger=wrap.querySelector('.audience-color-trigger')||node('button','audience-color-trigger');
  const chip=trigger.querySelector('.audience-color-chip')||node('span','audience-color-chip');
  const caption=trigger.querySelector('.audience-color-code')||node('span','audience-color-code');
  if(!rendered){
    trigger.type='button';trigger.setAttribute('aria-label',label);trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');trigger.append(chip,caption);
    input.before(wrap);wrap.append(input,trigger);
  }
  input.hidden=true;input.tabIndex=-1;
  const sync=()=>{chip.style.backgroundColor=input.value;caption.textContent=input.value.toUpperCase();};
  const formPalette=()=>input.form?.matches('[data-brand-form]')?[...input.form.querySelectorAll('input[type=color]')].map(n=>({label:n.dataset.colorLabel||n.labels?.[0]?.textContent.trim()||n.name,value:n.value})):[];
  trigger.addEventListener('click',()=>{
    if(input.matches(':disabled')||trigger.getAttribute('aria-expanded')==='true')return;
    trigger.setAttribute('aria-expanded','true');
    openColorStudio({label,value:input.value,palette:palette??formPalette,scope:wrap,previewStyle,automaticValue:input.defaultValue||null,automaticLabel:'Reset Color',sampleSources:sampleSources||(()=>colorSampleSources(null,[...(input.form?.querySelectorAll('img[data-content-preview],img[data-collection-image]')||[])])),onApply:value=>{
      if(!wrap.isConnected||input.matches(':disabled'))return;
      input.value=value;sync();input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));
    },onClose:()=>{trigger.setAttribute('aria-expanded','false');if(trigger.isConnected)trigger.focus({preventScroll:true});}});
  });
  input.addEventListener('change',sync);input.form?.addEventListener('reset',()=>queueMicrotask(sync));sync();return wrap;
}
