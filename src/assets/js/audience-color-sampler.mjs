// Sampling stays inside the page. It never invokes the native EyeDropper API.
const MAX_PIXELS=16000000;
const imageSource=src=>typeof src==='string'&&/^(?:data:image\/(?:png|jpeg|webp|gif|svg\+xml);base64,|blob:|https?:\/\/)/i.test(src);
export function colorSampleSources(preview,extras=[]) {
  const sources=[],seen=new Set();
  if(preview?.isConnected)sources.push({label:'Email preview',element:preview});
  const images=[...(preview?.querySelectorAll('img')||[]),...extras];
  for(const image of images){
    const src=typeof image==='string'?image:image.currentSrc||image.src||image.image;
    if(!imageSource(src)||seen.has(src))continue;seen.add(src);
    sources.push({label:typeof image==='string'?'Image '+seen.size:(image.alt||image.label||'Image '+seen.size).slice(0,100),src});
  }
  return sources.slice(0,51);
}
async function loadImage(src) {
  return new Promise((resolve,reject)=>{
    const image=new Image();image.crossOrigin='anonymous';
    image.onload=()=>{if(!image.naturalWidth||image.naturalWidth*image.naturalHeight>MAX_PIXELS)reject(new Error('Choose an image with at most 16 megapixels.'));else resolve(image);};
    image.onerror=()=>reject(new Error('This image cannot be sampled. Upload a local copy instead.'));
    image.src=src;
  });
}
function imageCanvas(image) {
  const canvas=document.createElement('canvas');canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
  const context=canvas.getContext('2d',{willReadFrequently:true});context.drawImage(image,0,0);
  // Check origin cleanliness before presenting an image as sampleable.
  context.getImageData(0,0,1,1);return canvas;
}
async function previewCanvas(element) {
  if(!element.isConnected)throw new Error('The email preview changed. Reopen the color picker.');
  const width=Math.ceil(element.scrollWidth),height=Math.ceil(element.scrollHeight);
  if(!width||!height||width*height>MAX_PIXELS||element.querySelectorAll('*').length>2000)throw new Error('This preview is too large to sample. Choose one of its images instead.');
  const clone=element.cloneNode(true),originals=[element,...element.querySelectorAll('*')],copies=[clone,...clone.querySelectorAll('*')];
  for(let i=0;i<originals.length;i++){
    const original=originals[i],copy=copies[i];
    if(['SCRIPT','IFRAME','OBJECT','EMBED','BUTTON','INPUT','SELECT','TEXTAREA'].includes(copy.tagName)){copy.remove();continue;}
    for(const attribute of [...copy.attributes])if(attribute.name!=='src'&&attribute.name!=='alt')copy.removeAttribute(attribute.name);
    const style=getComputedStyle(original);
    for(const property of style)if(!['animation','transition','cursor','caret-color'].some(prefix=>property.startsWith(prefix)))copy.style.setProperty(property,style.getPropertyValue(property));
    if(copy.tagName==='IMG'){
      try{copy.src=imageCanvas(original).toDataURL('image/png');}catch{throw new Error('An image in this preview cannot be read. Choose an individual image or upload a copy.');}
    }
  }
  clone.setAttribute('xmlns','http://www.w3.org/1999/xhtml');clone.style.margin='0';clone.style.width=width+'px';clone.style.height=height+'px';clone.style.transform='none';
  const svg='<svg xmlns="http://www.w3.org/2000/svg" width="'+width+'" height="'+height+'"><foreignObject width="100%" height="100%">'+new XMLSerializer().serializeToString(clone)+'</foreignObject></svg>';
  return imageCanvas(await loadImage('data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg)));
}
export async function sampleSourceCanvas(source) {
  try{return source.element?await previewCanvas(source.element):imageCanvas(await loadImage(source.src));}
  catch(error){if(error.name==='SecurityError')throw new Error('This preview cannot be read in this browser. Choose an individual image or upload a copy.');throw error;}
}
export function sampleCanvasColor(canvas,x,y) {
  const pixel=canvas.getContext('2d').getImageData(Math.max(0,Math.min(canvas.width-1,x)),Math.max(0,Math.min(canvas.height-1,y)),1,1).data;
  // Transparent pixels use the email's white canvas, rather than implicit black.
  return '#'+[...pixel].slice(0,3).map(v=>Math.round(v*pixel[3]/255+255*(1-pixel[3]/255)).toString(16).padStart(2,'0')).join('');
}
