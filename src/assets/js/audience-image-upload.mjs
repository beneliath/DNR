export const imageUploadLimits = {sourceBytes:20_000_000,sourcePixels:60_000_000,storedBytes:1_000_000,storedEdge:2400};

export function imageFileSize(bytes) {
  const unit=bytes>=1_000_000?'MB':bytes>=1000?'KB':'B',scale=unit==='MB'?1_000_000:unit==='KB'?1000:1;
  return `${Number((bytes/scale).toFixed(unit==='MB'?2:unit==='KB'?1:0))} ${unit}`;
}

// Animated inputs stay byte-for-byte intact; a canvas would discard their frames.
// PNG: https://www.w3.org/TR/png-3/#11acTL
// WebP: https://developers.google.com/speed/webp/docs/riff_container
export async function isAnimatedUpload(file) {
  if(file.type==='image/gif')return true;
  if(!['image/png','image/webp'].includes(file.type))return false;
  const bytes=new Uint8Array(await file.arrayBuffer()),view=new DataView(bytes.buffer);
  const tag=offset=>String.fromCharCode(...bytes.subarray(offset,offset+4));
  if(file.type==='image/webp')return tag(12)==='VP8X'&&bytes.length>20&&(bytes[20]&2)!==0;
  for(let offset=8;offset+12<=bytes.length;){const length=view.getUint32(offset);const name=tag(offset+4);if(name==='acTL')return true;if(name==='IDAT'||name==='IEND')break;offset+=12+length;}
  return false;
}

const dataUrl=blob=>new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result));reader.onerror=()=>reject(new Error('The image could not be read. Try again.'));reader.readAsDataURL(blob);});
const encode=(canvas,type,quality)=>new Promise((resolve,reject)=>canvas.toBlob(blob=>blob?resolve(blob):reject(new Error('The image could not be resized. Try a smaller image.')),type,quality));

export async function matchAudienceBrandLogos(logos) {
  if(!logos.logo||!logos.secondary_logo)return logos;
  const load=data=>new Promise((resolve,reject)=>{const image=new Image();image.onload=()=>resolve(image);image.onerror=()=>reject(new Error('The logo could not be opened. Choose a valid PNG, JPEG or WebP.'));image.src=data;});
  const [primary,secondary]=await Promise.all([load(logos.logo),load(logos.secondary_logo)]);
  const width=primary.naturalWidth,height=primary.naturalHeight;
  if(width===secondary.naturalWidth&&height===secondary.naturalHeight)return logos;
  if(logos.secondary_logo.startsWith('data:image/svg+xml;')){
    const source=new TextDecoder().decode(Uint8Array.from(atob(logos.secondary_logo.split(',')[1]),char=>char.charCodeAt(0))),document=new DOMParser().parseFromString(source,'image/svg+xml'),root=document.documentElement;
    root.setAttribute('width',width);root.setAttribute('height',height);root.setAttribute('preserveAspectRatio','xMidYMid meet');
    return {...logos,secondary_logo:await dataUrl(new Blob([new XMLSerializer().serializeToString(root)],{type:'image/svg+xml'}))};
  }
  if(width*height>16_000_000)throw new Error('Choose logos of 16 megapixels or less.');
  const [header,base64]=logos.secondary_logo.split(','),bytes=Uint8Array.from(atob(base64),char=>char.charCodeAt(0));
  if(await isAnimatedUpload(new File([bytes],'logo',{type:header.slice(5,-7)})))throw new Error('Animated logos must already match the default logo dimensions. Resize the animation or upload a static PNG, JPEG or WebP.');
  const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;
  const ctx=canvas.getContext('2d');if(!ctx)throw new Error('Image resizing is unavailable in this browser.');
  try{
    const scale=Math.min(width/secondary.naturalWidth,height/secondary.naturalHeight),drawWidth=Math.max(1,Math.min(width,Math.round(secondary.naturalWidth*scale))),drawHeight=Math.max(1,Math.min(height,Math.round(secondary.naturalHeight*scale)));
    ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.drawImage(secondary,Math.floor((width-drawWidth)/2),Math.floor((height-drawHeight)/2),drawWidth,drawHeight);
    let output=await encode(canvas,'image/png');
    if(output.size>2_000_000)for(const quality of [.9,.75,.6]){output=await encode(canvas,'image/webp',quality);if(output.size<=2_000_000)break;}
    if(output.size>2_000_000)throw new Error('The logos could not be matched within 2 MB. Try a smaller default logo.');
    return {...logos,secondary_logo:await dataUrl(output)};
  }finally{canvas.width=canvas.height=1;}
}

export async function prepareAudienceImage(file, {storedBytes = imageUploadLimits.storedBytes} = {}) {
  if(!['image/png','image/jpeg','image/webp','image/gif'].includes(file.type))throw new Error('Choose a PNG, JPEG, WebP or GIF image.');
  if(file.size>imageUploadLimits.sourceBytes)throw new Error('Choose an image up to 20 MB. Larger photos can be exported at a smaller size first.');
  const animated=await isAnimatedUpload(file);
  if(animated&&file.size>storedBytes)throw new Error(`Animated images must be ${imageFileSize(storedBytes)} or smaller so all frames can be preserved. Optimize the animation before uploading.`);
  const url=URL.createObjectURL(file),image=new Image();
  try{
    await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(new Error('The image could not be opened. Choose a valid PNG, JPEG, WebP or GIF.'));image.src=url;});
    const originalWidth=image.naturalWidth,originalHeight=image.naturalHeight;
    if(!originalWidth||!originalHeight||originalWidth*originalHeight>imageUploadLimits.sourcePixels)throw new Error('Choose an image of 60 megapixels or less.');
    if(animated&&originalWidth*originalHeight>16_000_000)throw new Error('Animated images must be 16 megapixels or less so all frames can be preserved.');
    let output=file,width=originalWidth,height=originalHeight;
    if(!animated&&(file.size>storedBytes||Math.max(width,height)>imageUploadLimits.storedEdge)){
      let scale=Math.min(1,imageUploadLimits.storedEdge/Math.max(width,height));
      const canvas=document.createElement('canvas'),ctx=canvas.getContext('2d');if(!ctx)throw new Error('Image resizing is unavailable in this browser.');
      try{
        // PNG stays lossless with transparency. JPEG and WebP try a small quality
        // range first, then reduce dimensions further only when necessary.
        for(let attempt=0;attempt<14;attempt++){
          width=Math.max(1,Math.round(originalWidth*scale));height=Math.max(1,Math.round(originalHeight*scale));canvas.width=width;canvas.height=height;ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.drawImage(image,0,0,width,height);
          for(const quality of (file.type==='image/png'?[1]:[.9,.82,.74])){output=await encode(canvas,file.type,quality);if(output.size<=storedBytes)break;}
          if(output.size<=storedBytes)break;
          scale*=Math.min(.85,Math.sqrt(storedBytes/output.size)*.95);
        }
      }finally{canvas.width=canvas.height=1;}
      if(output.size>storedBytes)throw new Error(`The image could not be reduced to ${imageFileSize(storedBytes)}. Try a smaller image.`);
    }
    const name=output.type===file.type?file.name:file.name.replace(/\.[^.]+$/,'')+'.'+({ 'image/png':'png','image/jpeg':'jpg','image/webp':'webp' }[output.type]||'png');
    return {data:await dataUrl(output),name,bytes:output.size,originalBytes:file.size,width,height,originalWidth,originalHeight,optimized:output!==file,animated};
  }finally{URL.revokeObjectURL(url);}
}
