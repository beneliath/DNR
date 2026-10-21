import {mountBlockDropSpace} from '../../src/assets/js/audience-designer-drop.mjs';
const canvas=document.querySelector('#fixture'),result=document.querySelector('#result');
let drag=null,dropped=null;
const space=mountBlockDropSpace({canvas,getDrag:()=>drag,onDrop:target=>dropped=target});
document.querySelector('#run').onclick=()=>{
  space.clear();canvas.replaceChildren();let checks=0;
  const expect=(ok,message)=>{if(!ok)throw Error(message);checks++;};
  const column=(r,c)=>{const node=document.createElement('div');node.className='designer-column';node.dataset.row=r;node.dataset.column=c;canvas.append(node);return node;};
  const first=column(0,0),empty=column(1,0);
  const blocks=['Header','Body','Footer'].map(text=>{const block=document.createElement('div');block.className='designer-block';block.textContent=text;first.append(block);return block;});
  const point=(node,part=.25)=>{const rect=node.getBoundingClientRect();return [rect.left+30,rect.top+rect.height*part];};
  const over=(node,part=.25)=>{const [clientX,clientY]=point(node,part);node.dispatchEvent(new DragEvent('dragover',{bubbles:true,cancelable:true,clientX,clientY,dataTransfer:new DataTransfer()}));};
  const gap=()=>canvas.querySelector('.designer-block-drop-space');
  try{
    drag={type:'text'};const top=blocks[0].getBoundingClientRect().top;over(blocks[0]);
    expect(gap()?.nextElementSibling===blocks[0],'Gap before first block');
    expect(blocks[0].getBoundingClientRect().top-top>=90,'Gap opens real vertical space');
    const inside=point(gap());blocks[0].dispatchEvent(new DragEvent('dragleave',{bubbles:true,clientX:inside[0],clientY:inside[1]}));
    expect(!!gap(),'Opening a gap does not cancel it on a child dragleave');
    over(gap());expect(gap()?.nextElementSibling===blocks[0],'Hovering gap keeps stable destination');
    over(blocks[1],.75);expect(gap()?.previousElementSibling===blocks[1]&&gap()?.nextElementSibling===blocks[2],'Gap moves between blocks');
    over(blocks[2],.75);expect(gap()===first.lastElementChild,'Gap after final block');
    over(empty);expect(gap()?.parentElement===empty,'Gap in empty section');
    const [clientX,clientY]=point(gap());gap().dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,clientX,clientY,dataTransfer:new DataTransfer()}));
    expect(JSON.stringify(dropped)==='[1,0,0]'&&!gap(),'Dropping uses the visible position and removes spacer');
    drag={position:[0,0,1]};over(blocks[1]);expect(!gap(),'No gap for moving into current slot');
    over(blocks[0]);expect(gap()?.nextElementSibling===blocks[0],'Existing block can move before another');
    document.dispatchEvent(new DragEvent('dragend'));expect(!gap(),'Canceling removes gap');
    drag={section:0};over(blocks[0]);expect(!gap(),'Section drags do not insert block gaps');
    drag=null;over(blocks[0]);expect(!gap(),'External drags do not insert gaps');
    drag={type:'image'};over(blocks[1]);document.body.dispatchEvent(new DragEvent('dragover',{bubbles:true}));expect(!gap(),'Leaving canvas removes gap');
    expect(blocks.every(b=>b.parentElement===first),'Hovering never changes real block order');
    drag={type:'text'};over(blocks[1]);
    result.textContent=`PASS: ${checks} insertion checks`;result.dataset.status='passed';
  }catch(error){result.textContent=`FAIL after ${checks}: ${error.message}`;result.dataset.status='failed';throw error;}
};
