// PHP encodes empty associative collections as []. Normalize maps before editing
// so named style/region properties survive JSON.stringify and autosave.
export function normalizeEmailDesignMaps(design) {
  const map = value => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  design.mobile = map(design.mobile);
  design.regions = map(design.regions);
  for (const key of ['page','preheader','header','body','footer']) design.regions[key] = map(design.regions[key]);
  design.typography = map(design.typography);
  for (const key of ['paragraph','heading1','heading2','heading3','heading4']) design.typography[key] = map(design.typography[key]);
  design.badge = map(design.badge);
  design.badge.enabled ??= false; design.badge.align ||= 'center';
  design.preheader ||= {text:'',showArchive:false};
  design.areas = map(design.areas);
  design.footer ||= {text:'',styles:{}};
  design.footer.styles = map(design.footer.styles);
  for (const row of design.rows || []) {
    row.styles = map(row.styles);
    row.mobile = map(row.mobile);
    for (const column of row.columns || []) for (const block of column) {
      block.styles = map(block.styles);
      block.mobile = map(block.mobile);
    }
  }
  return design;
}

// Moving within the same column must compensate for the removed source slot.
export function moveEmailBlock(design, from, target) {
  const [r,c,i]=from;let [tr,tc,ti]=target;
  const source=design.rows[r]?.columns[c], destination=design.rows[tr]?.columns[tc];
  if(!source?.[i]||!destination||!Number.isInteger(ti)||ti<0||ti>destination.length)return null;
  if(r===tr&&c===tc&&i<ti)ti--;
  const [block]=source.splice(i,1);destination.splice(ti,0,block);return [tr,tc,ti];
}
export function blockDropIndex(index,y,top,height){return index+(y>top+height/2?1:0);}

export function setDesignColor(design,target,key,value) {
  target[key]=value;
  if(target===design.styles&&['link','button','buttonText'].includes(key)){
    design.styles.brandColors ||= {};
    design.styles.brandColors[key]=false;
  }
}
