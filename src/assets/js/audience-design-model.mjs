// PHP encodes empty associative collections as []. Normalize maps before editing
// so named style/region properties survive JSON.stringify and autosave.
export function normalizeEmailDesignMaps(design) {
  const map = value => value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  design.mobile = map(design.mobile);
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
