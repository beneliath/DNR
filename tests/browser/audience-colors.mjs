import {enhanceColorInput} from '../../src/assets/js/audience-color-picker.mjs';
const form=document.querySelector('#brand'),result=document.querySelector('#result');
form.querySelectorAll('input[type=color]').forEach(input=>enhanceColorInput(input,{tile:true}));
document.querySelector('#run').onclick=async()=>{
  let count=0;const expect=(ok,message)=>{if(!ok)throw Error(message);count++;};
  const input=form.elements.link,wrap=input.parentElement,trigger=wrap.querySelector('button'),panel=wrap.querySelector('[popover]'),hex=panel.querySelector('input[type=text]'),hue=panel.querySelector('input[type=range]'),plane=panel.querySelector('[role=slider]');
  try{
    trigger.click();expect(panel.matches(':popover-open'),'Click opens the in-page panel');expect(hex.value==='3261B5','Existing Brand Kit color initializes hex');expect(!panel.querySelector('input[type=color]'),'No native picker in the panel');
    expect(!!panel.querySelector('.audience-color-dropper')===(typeof window.EyeDropper==='function'),'Eyedropper uses direct browser support only');
    hex.value='123456';hex.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));expect(input.value==='#123456','Hex edits reach the saved form input');expect(new FormData(form).get('link')==='#123456','Submission retains the existing field name');expect(!panel.matches(':popover-open'),'Hex selection closes the panel');
    trigger.click();hex.value='bad!';hex.dispatchEvent(new Event('change'));expect(input.value==='#123456'&&panel.matches(':popover-open'),'Invalid hex cannot change saved value');panel.hidePopover();
    trigger.click();expect(hex.value==='123456','Reopening discards unfinished invalid input');
    const before=input.value;plane.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowRight',bubbles:true,cancelable:true}));expect(input.value===before,'Keyboard movement previews without committing');plane.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));expect(input.value!==before&&!panel.matches(':popover-open'),'Enter selects a keyboard-adjusted color');
    trigger.click();hue.value='120';hue.dispatchEvent(new Event('input'));hue.dispatchEvent(new Event('change'));expect(input.value!==before&&!panel.matches(':popover-open'),'Hue selection commits and closes');

    trigger.click();panel.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));expect(!panel.matches(':popover-open'),'Escape closes the panel');
    trigger.click();const box=panel.getBoundingClientRect();expect(box.left>=0&&box.right<=innerWidth&&box.top>=0&&box.bottom<=innerHeight,'Panel fits viewport');panel.hidePopover();
    form.reset();await Promise.resolve();expect(input.value==='#3261b5'&&trigger.textContent==='#3261B5','Form reset restores chip and submitted color');
    form.querySelector('fieldset').disabled=true;trigger.click();expect(!panel.matches(':popover-open'),'Disabled Brand Kit cannot open color picker');form.querySelector('fieldset').disabled=false;
    result.textContent=`PASS: ${count} color checks`;result.dataset.status='passed';
  }catch(e){result.textContent=`FAIL after ${count} checks: ${e.message}`;result.dataset.status='failed';throw e;}
};
