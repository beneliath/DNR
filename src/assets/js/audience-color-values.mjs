export function normalizeColor(value) {
  if(typeof value!=='string')return null;
  const hex=value.trim();
  if(/^#?[a-f\d]{6}$/i.test(hex))return '#'+hex.replace('#','').toLowerCase();
  const rgb=hex.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)$/i);
  if(!rgb||rgb.slice(1,4).some(v=>Number(v)>255)||(rgb[4]!==undefined&&Number(rgb[4])!==1))return null;
  return '#'+rgb.slice(1,4).map(v=>Number(v).toString(16).padStart(2,'0')).join('');
}
export function hexToHsv(hex) {
  const [r,g,b]=hex.slice(1).match(/../g).map(v=>parseInt(v,16)/255),max=Math.max(r,g,b),min=Math.min(r,g,b),d=max-min;
  let h=0;if(d)h=(max===r?(g-b)/d+(g<b?6:0):max===g?(b-r)/d+2:(r-g)/d+4)*60;
  return [h,max===0?0:d/max,max];
}
export function hsvToHex(h,s,v) {
  const f=n=>{const k=(n+h/60)%6;return Math.round(255*(v-v*s*Math.max(0,Math.min(k,4-k,1)))).toString(16).padStart(2,'0');};
  return '#'+f(5)+f(3)+f(1);
}
export function colorPalette(palette) {
  const colors=new Map();
  for(const item of Array.isArray(palette)?palette:[]){
    const value=normalizeColor(item?.value);if(!value)continue;
    const label=typeof item.label==='string'?item.label.slice(0,100):value.toUpperCase();
    if(colors.has(value)){const color=colors.get(value);if(!color.labels.includes(label))color.labels.push(label);}
    else colors.set(value,{value,labels:[label]});
  }
  return [...colors.values()].map(item=>({value:item.value,label:item.labels.join(' / ')}));
}
export function colorLuminance(hex) {
  return hex.slice(1).match(/../g).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
}
export function colorContrast(a,b) {const x=colorLuminance(a),y=colorLuminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
