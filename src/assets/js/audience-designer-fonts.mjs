const systemFonts=['Arial','Georgia','Verdana','Tahoma','Times New Roman'];
export function firstFontFamily(value) {
  return String(value||'').split(',')[0].trim().replace(/^["']|["']$/g,'');
}

export function matchingFontChoice(family,choices) {
  const first=firstFontFamily(family);
  return Object.keys(choices).find(value=>firstFontFamily(value).toLowerCase()===first.toLowerCase())||first;
}

export function designFontChoices(design,brandFont={}) {
  const choices=Object.fromEntries(systemFonts.map(name=>[name,name]));
  const font=brandFont.data?brandFont:{data:design.styles.webFont,name:design.styles.webFontName};
  if(font.data)choices['MOEDBrandFont,'+design.styles.font]=(font.name||'Uploaded font')+' (Brand Kit)';
  return choices;
}

export function defaultDesignFont(design) {
  const styles=design.styles;
  return styles.webFont&&styles.webFontDefault!==false?'MOEDBrandFont,'+styles.font:styles.font;
}

// Copy the font into the design only when selected. Saved templates and sent
// emails retain their font even if the Brand Kit is changed later.
export function selectDesignFont(design,brandFont,value) {
  if(!value.startsWith('MOEDBrandFont,')||!brandFont?.data)return;
  if(!design.styles.webFont)design.styles.webFontDefault=false;
  design.styles.webFont=brandFont.data;
  design.styles.webFontName=brandFont.name||'';
}
