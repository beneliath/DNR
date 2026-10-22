import { enhanceColorInput } from './audience-color-picker.mjs';
import { designFontChoices, selectDesignFont } from './audience-designer-fonts.mjs';
import { setDesignColor } from './audience-design-model.mjs';
export function renderDesignStylePanel({tools,design,area,setArea,button,el,onPreview,onSave,brandFont}) {
  const labels={page:'Page',preheader:'Preheader',header:'Header',body:'Body',footer:'Footer',mobile:'Mobile Styles',badge:'Referral Badge'};
  if(!area){
    for(const [key,label]of Object.entries(labels)){
      const row=button('',()=>setArea(key));row.className='designer-style-link';row.append(el('span',label),el('span','›','designer-style-chevron'));tools.append(row);
    }return;
  }
  const header=el('header',undefined,'designer-style-header');
  const back=button('←',()=>setArea(''));back.setAttribute('aria-label','Back to Style');header.append(back,el('h2',labels[area]));
  const body=el('div',undefined,'designer-style-scroll'), footer=el('div',undefined,'designer-style-actions');
  const save=button('Save',()=>{const invalid=[...body.querySelectorAll('input,select,textarea')].find(input=>!input.checkValidity());if(invalid){invalid.reportValidity();return;}onSave();});save.className='primary';const cancel=button('Cancel',()=>setArea(''));cancel.className='designer-style-cancel';footer.append(save,cancel);tools.append(header,body,footer);
  const preview=()=>onPreview();
  const fonts=designFontChoices(design,brandFont);
  const label=(text,control)=>{const wrap=el(control.querySelector('.audience-color-control')?'div':'label',undefined,'designer-style-control');wrap.append(el('span',text),control);return wrap;};
  const select=(target,key,choices,value,changed=()=>{})=>{const input=el('select');for(const [v,text]of Object.entries(choices)){const option=el('option',text);option.value=v;input.append(option);}const current=String(target[key]??value);if(![...input.options].some(option=>option.value===current)){const option=el('option',current);option.value=current;input.append(option);}input.value=current;input.onchange=()=>{target[key]=typeof value==='number'?Number(input.value):input.value;changed();preview();};return input;};
  const number=(target,key,value,min,max,suffix='')=>{const wrap=el('span',undefined,'designer-style-number'),input=el('input');input.type='number';input.min=min;input.max=max;input.value=target[key]??value;input.oninput=()=>{if(!input.checkValidity())return;target[key]=Number(input.value);preview();};wrap.append(input);if(suffix)wrap.append(el('span',suffix));return wrap;};
  const color=(target,key,value)=>{const group=el('span',undefined,'designer-style-color'),picker=el('input');picker.type='color';picker.value=target[key]??value;picker.oninput=()=>{setDesignColor(design,target,key,picker.value);preview();};group.append(picker);enhanceColorInput(picker,{label:'Choose '+key+' color'});return group;};
  const section=title=>{const box=el('section',undefined,'designer-style-section'),heading=el('h3',title),content=el('div',undefined,'designer-style-section-body');box.append(heading,content);body.append(box);return content;};
  const row=(...nodes)=>{const node=el('div',undefined,'designer-style-control-row');node.append(...nodes);return node;};
  const border=(host,target,title,prefix='')=>{
    const edge=prefix?prefix[0].toUpperCase()+prefix.slice(1):'',style='border'+edge+'Style',width='border'+edge+'Width',ink='border'+edge+'Color';
    const heading=el('div',undefined,'designer-style-border-label'),remove=button('Remove',()=>{target[width]=0;input.value='none';extra.hidden=true;preview();});heading.append(el('strong',title),remove);
    const input=select(target,style,{none:'None',solid:'Solid',dashed:'Dashed',dotted:'Dotted'},'none',()=>{if(input.value==='none'){target[width]=0;delete target[style];}else if(!target[width])target[width]=1;extra.hidden=input.value==='none';});
    input.setAttribute('aria-label',title+' style');if(target[width])input.value=target[style]||'solid';
    const extra=row(label('Width',number(target,width,1,0,12,'px')),label('Color',color(target,ink,'#d5d9dc')));extra.hidden=!target[width];
    const wrap=el('div',undefined,'designer-style-border');wrap.append(heading,input,extra);host.append(wrap);
  };
  const toggles=(target,key,choices,value,title)=>{const group=el('div',undefined,'designer-style-toggle-group');group.setAttribute('role','group');group.setAttribute('aria-label',title);for(const [v,text]of Object.entries(choices)){const control=button(text,()=>{target[key]=v;for(const item of group.children)item.setAttribute('aria-pressed',String(item.dataset.value===v));preview();});control.dataset.value=v;control.setAttribute('aria-label',title+': '+v);control.setAttribute('aria-pressed',String((target[key]??value)===v));if(key==='align'){control.textContent='';const icon=el('img');icon.src='assets/editor-icons/align-'+v+'.svg';icon.alt='';control.append(icon);}group.append(control);}return group;};
  const typography=(target,title,size,heading=false)=>{
    const host=section(title);host.append(label('Font',select(target,'font',fonts,design.styles.font,()=>selectDesignFont(design,brandFont,target.font))),row(label('Size',number(target,'size',size,10,72,'px')),label('Color',color(target,'color',design.styles.text))));
    const emphasis=el('div',undefined,'designer-style-toggle-group');emphasis.setAttribute('role','group');emphasis.setAttribute('aria-label',title+' text styles');
    for(const [key,text,on,off,fallback]of [['weight','B','bold','normal',heading?'bold':'normal'],['fontStyle','I','italic','normal','normal']]){const control=button(text,()=>{target[key]=(target[key]??fallback)===on?off:on;control.setAttribute('aria-pressed',String(target[key]===on));preview();});control.className=key==='fontStyle'?'italic':'';control.setAttribute('aria-label',title+(key==='weight'?' bold':' italic'));control.setAttribute('aria-pressed',String((target[key]??fallback)===on));emphasis.append(control);}
    host.append(row(label('Styles',emphasis),label('Align',toggles(target,'align',{left:'Left',center:'Center',right:'Right',justify:'Justify'},'left',title+' alignment'))),row(label('Line height',select(target,'lineHeight',{100:'Single',125:'Slight',150:'Normal',160:'Comfortable',200:'Double'},target.lineHeight??design.styles.lineHeight)),label('Spacing',select(target,'letterSpacing',{'-1':'Tight',0:'Normal',1:'Wide',2:'Wider'},target.letterSpacing??design.styles.letterSpacing))));
  };
  const links=(target=design.styles)=>{const host=section('Link');host.append(row(label('Color',color(target,'link',design.styles.link)),label('Style',select(target,'linkUnderline',{true:'Underline',false:'None'},String(design.styles.linkUnderline)))));const input=host.querySelector('select');input.onchange=()=>{target.linkUnderline=input.value==='true';preview();};};
  if(area==='page'){
    const bg=section('Background Style');bg.append(label('Background',color(design.styles,'background',design.styles.background)));border(bg,design.regions.page,'Border top','top');
    border(section('Email Border'),design.regions.page,'Border');
    for(let n=1;n<=4;n++)typography(design.typography['heading'+n],'Heading '+n,design.styles['heading'+n],true);
    typography(design.typography.paragraph,'Paragraph text',design.styles.fontSize);
    const layout=section('Email Layout');layout.append(row(label('Width',number(design.styles,'width',600,400,800,'px')),label('Content background',color(design.styles,'body',design.styles.body))));links();
    const buttons=section('Buttons');buttons.append(row(label('Background',color(design.styles,'button',design.styles.button)),label('Text color',color(design.styles,'buttonText',design.styles.buttonText))),row(label('Size',number(design.styles,'buttonSize',16,10,72,'px')),label('Rounded corners',number(design.styles,'buttonRadius',4,0,50,'px'))));
  }else if(area==='badge'){
    const host=section('Referral Badge'),check=el('input');check.type='checkbox';check.checked=design.badge.enabled;check.onchange=()=>{design.badge.enabled=check.checked;preview();};host.append(label('Display the MessageMonkey badge',check),label('Align',toggles(design.badge,'align',{left:'Left',center:'Center',right:'Right'},'center','Badge alignment')),el('p','Adds a “Made with MessageMonkey” badge below your email footer.','designer-panel-help'));
  }else if(area==='mobile'){
    const target=design.mobile;const host=section('Mobile Styles');host.append(row(label('Paragraph size',number(target,'size',design.styles.fontSize,10,72,'px')),label('Padding',number(target,'padding',16,0,80,'px'))),label('Line height',select(target,'lineHeight',{100:'Single',125:'Slight',150:'Normal',160:'Comfortable',200:'Double'},160)));
    const headings=section('Headings');for(let n=1;n<=4;n++)headings.append(label('Heading '+n,number(target,'heading'+n,design.styles['heading'+n],10,72,'px')));
    const extra=section('Buttons and Dividers');extra.append(label('Button text size',number(target,'buttonSize',16,10,72,'px')),row(label('Divider thickness',number(target,'dividerHeight',1,1,12,'px')),label('Divider width',number(target,'dividerWidth',100,10,100,'%'))));
  }else{
    const target=design.regions[area];const bg=section('Background Style');bg.append(label('Background',color(target,'background',design.styles.body)));border(bg,target,'Border top','top');border(bg,target,'Border bottom','bottom');
    typography(target,labels[area]+' Text',area==='footer'?12:design.styles.fontSize);
    const spacing=section('Spacing');spacing.append(label('Padding',number(target,'padding',area==='footer'?32:0,0,80,'px')));
    if(area==='preheader'){
      const host=section('Preview text'),text=el('textarea');text.rows=3;text.maxLength=300;text.value=design.preheader.text;text.oninput=()=>{design.preheader.text=text.value;preview();};host.append(label('Inbox preview text',text));
      const check=el('input');check.type='checkbox';check.checked=design.preheader.showArchive;check.onchange=()=>{design.preheader.showArchive=check.checked;preview();};host.append(label('View this email in your browser',check));
    }
    if(area==='footer'){const host=section('Footer content'),text=el('textarea');text.rows=4;text.value=design.footer.text;text.oninput=()=>{design.footer.text=text.value;preview();};host.append(label('Additional footer text',text),el('p','Your identity, mailing address, preferences and unsubscribe links stay in the email footer.','designer-panel-help'));}
  }
}
