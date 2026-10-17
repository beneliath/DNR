const test = require('node:test');
const assert = require('node:assert/strict');

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
  const before = JSON.stringify(design);
  assert.equal(JSON.stringify(normalizeEmailDesignMaps(design)), before);
});
