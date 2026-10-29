const test = require('node:test');
const assert = require('node:assert/strict');

test('font selection matches computed font stacks to system and Brand Kit choices',async()=>{
  const {matchingFontChoice}=await import('../../src/assets/js/audience-designer-fonts.mjs');
  const choices={Arial:'Arial','Times New Roman':'Times New Roman','MOEDBrandFont,Tahoma':'Ubuntu Light (Brand Kit)'};
  assert.equal(matchingFontChoice('arial, sans-serif',choices),'Arial');
  assert.equal(matchingFontChoice('"Times New Roman", serif',choices),'Times New Roman');
  assert.equal(matchingFontChoice('MOEDBrandFont, Tahoma',choices),'MOEDBrandFont,Tahoma');
  assert.equal(matchingFontChoice('"Helvetica Neue", sans-serif',choices),'Helvetica Neue');
});

test('explicit editor palette choices stop inheriting while block overrides leave the defaults alone', async()=>{
  const {setDesignColor}=await import('../../src/assets/js/audience-design-model.mjs');
  const design={styles:{link:'#3261b5',button:'#c10304',buttonText:'#ffffff',brandColors:{link:true,button:true,buttonText:true}}};
  const block={color:'#ffffff'};
  setDesignColor(design,block,'color','#334455');
  assert.deepEqual(design.styles.brandColors,{link:true,button:true,buttonText:true});
  for(const key of ['link','button','buttonText'])setDesignColor(design,design.styles,key,'#007c89');
  const saved=JSON.parse(JSON.stringify(design));
  assert.deepEqual(saved.styles.brandColors,{link:false,button:false,buttonText:false});
  assert.equal(saved.styles.link,'#007c89');
});

test('Brand Kit font selection snapshots its bytes without changing the other text defaults', async () => {
  const {defaultDesignFont,designFontChoices,selectDesignFont}=await import('../../src/assets/js/audience-designer-fonts.mjs');
  const design={styles:{font:'Tahoma',webFont:''}};
  const brand={data:'data:font/ttf;base64,fixture',name:'Example Light'};
  assert.equal(defaultDesignFont(design),'Tahoma');
  assert.equal(defaultDesignFont({styles:{font:'Tahoma',webFont:brand.data,webFontDefault:true}}),'MOEDBrandFont,Tahoma');
  assert.equal(defaultDesignFont({styles:{font:'Tahoma',webFont:brand.data,webFontDefault:false}}),'Tahoma');
  assert.equal(designFontChoices(design,brand)['MOEDBrandFont,Tahoma'],'Example Light (Brand Kit)');
  selectDesignFont(design,brand,'Georgia');
  assert.equal(design.styles.webFont,'');
  selectDesignFont(design,brand,'MOEDBrandFont,Tahoma');
  brand.data='replacement';
  const saved=JSON.parse(JSON.stringify(design));
  assert.equal(saved.styles.webFont,'data:font/ttf;base64,fixture');
  assert.equal(saved.styles.webFontName,'Example Light');
  assert.equal(saved.styles.webFontDefault,false);
  assert.equal(saved.styles.font,'Tahoma');
  assert.equal(designFontChoices(saved)['MOEDBrandFont,Tahoma'],'Example Light (Brand Kit)');
  assert.equal(Object.keys(designFontChoices({styles:{font:'Arial'}})).length,5);
});

test('Style Save tracks changes in every style panel without counting content edits', async () => {
  const {stylePanelSignature}=await import('../../src/assets/js/audience-designer-styles.mjs');
  const design={styles:{font:'Arial'},regions:{page:{},header:{},footer:{}},typography:{heading1:{},paragraph:{}},badge:{enabled:false},preheader:{text:''},footer:{text:''},mobile:{},rows:[{id:'content'}]};
  const original=stylePanelSignature(design);
  for(const [section,key,value] of [['styles','font','Georgia'],['regions','header',{font:'Georgia'}],['typography','heading1',{size:28}],['badge','enabled',true],['preheader','text','Preview'],['footer','text','Footer'],['mobile','size',14]]){
    const changed=structuredClone(design);
    changed[section][key]=value;
    assert.notEqual(stylePanelSignature(changed),original,section);
  }
  const contentOnly=structuredClone(design);contentOnly.rows[0].id='different';
  assert.equal(stylePanelSignature(contentOnly),original);
});

test('PHP empty-map payloads retain device styles, regions and footer edits through save/reload', async () => {
  const {normalizeEmailDesignMaps} = await import('../../src/assets/js/audience-design-model.mjs');
  const design = normalizeEmailDesignMaps(JSON.parse('{"mobile":[],"areas":[],"footer":{"text":"","styles":[]},"rows":[{"styles":[],"mobile":[],"columns":[[{"styles":[],"mobile":[]}]]}]}'));
  design.mobile.size = 14;
  design.areas.headline = '<strong>Edited</strong>';
  design.footer.styles.color = '#123456';
  design.rows[0].styles.padding = 12;
  design.rows[0].mobile.padding = 8;
  const block = design.rows[0].columns[0][0];
  block.mobile.size = 21;
  block.styles.borderWidth = 2;
  const reloaded = normalizeEmailDesignMaps(JSON.parse(JSON.stringify(design)));
  assert.equal(reloaded.mobile.size, 14);
  assert.equal(reloaded.areas.headline, '<strong>Edited</strong>');
  assert.equal(reloaded.footer.styles.color, '#123456');
  assert.equal(reloaded.rows[0].styles.padding, 12);
  assert.equal(reloaded.rows[0].mobile.padding, 8);
  assert.equal(reloaded.rows[0].columns[0][0].mobile.size, 21);
  assert.equal(reloaded.rows[0].columns[0][0].styles.borderWidth, 2);
  assert.equal(reloaded.rows[0].columns.length, 1);
});

test('existing explicit map values and content arrays survive normalization', async () => {
  const {normalizeEmailDesignMaps} = await import('../../src/assets/js/audience-design-model.mjs');
  const design = {mobile:{size:18},areas:{intro:'Hello'},footer:{styles:{padding:32}},rows:[{styles:{background:'#ffffff'},mobile:{padding:8},columns:[[{styles:{color:'#123456'},mobile:{size:22},images:[]}]]}]};
  const before = structuredClone(design);
  const normalized = normalizeEmailDesignMaps(design);
  for (const key of Object.keys(before)) assert.deepEqual(normalized[key], before[key]);
  assert.deepEqual(normalizeEmailDesignMaps(structuredClone(normalized)), normalized);
});

 test('new region and typography maps survive JSON storage without changing content', async () => {
  const {normalizeEmailDesignMaps} = await import('../../src/assets/js/audience-design-model.mjs');
  const doc = normalizeEmailDesignMaps({regions:[],typography:[],rows:[]});
  doc.regions.header.background='#123456'; doc.typography.heading2.weight='normal';
  const loaded=normalizeEmailDesignMaps(JSON.parse(JSON.stringify(doc)));
  assert.equal(loaded.regions.header.background,'#123456');
  assert.equal(loaded.typography.heading2.weight,'normal');
});
 test('block moves preserve order across before/after and column destinations', async () => {
  const {moveEmailBlock,blockDropIndex} = await import('../../src/assets/js/audience-design-model.mjs');
  const doc={rows:[{columns:[[{id:'a'},{id:'b'},{id:'c'}],[]]}]};
  assert.deepEqual(moveEmailBlock(doc,[0,0,0],[0,0,3]),[0,0,2]);
  assert.deepEqual(doc.rows[0].columns[0].map(b=>b.id),['b','c','a']);
  assert.deepEqual(moveEmailBlock(doc,[0,0,2],[0,0,0]),[0,0,0]);
  assert.deepEqual(moveEmailBlock(doc,[0,0,1],[0,1,0]),[0,1,0]);
  assert.deepEqual(doc.rows[0].columns.map(col=>col.map(b=>b.id)),[['a','c'],['b']]);
  const before=structuredClone(doc); assert.equal(moveEmailBlock(doc,[0,0,0],[0,1,9]),null); assert.deepEqual(doc,before);
  assert.equal(blockDropIndex(2,110,100,80),2); assert.equal(blockDropIndex(2,175,100,80),3);
});
