import {openColorStudio} from './audience-color-studio.mjs';
import {normalizeColor} from './audience-color-values.mjs';
export function mountTextColor({host,label,highlight=false,makeElement:el,makeButton:button,remember,apply,signal,palette=[],sampleSources,getValue,getTextStyle}) {
  const wrap=el('span',undefined,'designer-text-color');let selected=highlight?'#ffff00':'#24292d';
  const trigger=button('',()=>{
    if(signal.aborted)return;remember();
    const initial=getValue?.()||selected;
    trigger.setAttribute('aria-expanded','true');
    openColorStudio({label,value:initial,palette,scope:wrap,signal,highlight,automaticValue:highlight?'transparent':'inherit',automaticLabel:highlight?'No color':'Automatic',previewText:window.getSelection()?.toString()||undefined,previewStyle:getTextStyle,sampleSources,onApply:value=>{
      selected=normalizeColor(value)||selected;letter.style.setProperty('--selected-color',value==='inherit'?'currentColor':value);apply(value,{focus:true,rememberSelection:false});
    },onClose:applied=>{trigger.setAttribute('aria-expanded','false');if(!applied&&trigger.isConnected)trigger.focus({preventScroll:true});}});
  });
  trigger.className='designer-color-trigger';trigger.setAttribute('aria-label',label);trigger.title=label;trigger.setAttribute('aria-haspopup','dialog');trigger.setAttribute('aria-expanded','false');
  const letter=el('span','A',highlight?'designer-color-letter highlight':'designer-color-letter');letter.style.setProperty('--selected-color',selected);trigger.append(letter,el('span','▾','designer-color-caret'));
  trigger.addEventListener('pointerdown',()=>remember(),{signal});wrap.append(trigger);host.append(wrap);return wrap;
}
