// Connections is the single pagination pattern for MessageMonkey, including async lists.
export const audiencePageSizes = [10, 25, 50, 100];
const preferenceKey = label => {
  const identity = document.body?.dataset.audienceKey;
  if (!identity) return '';
  const url = new URL(location.href), view = url.searchParams.get('view') || 'contacts';
  return `mm-page-size:${identity}:${view}:${label}`;
};

function rememberedSize(key) {
  try { const size = Number(localStorage.getItem(key));return audiencePageSizes.includes(size) ? size : null; }
  catch { return null; }
}

export function wireAudiencePagination(root = document) {
  root.addEventListener('change', event => {
    if (event.target.matches('[data-mm-page-size]')) event.target.closest('form')?.requestSubmit();
  });
}

export function renderAudiencePagination(host, {total, page = 1, pageSize = 25, onChange, label = 'Results pages'}) {
  const key = preferenceKey(label);
  if (key && host.dataset.mmPageSizePreference !== key) {
    host.dataset.mmPageSizePreference = key;
    const size = rememberedSize(key);
    if (size && size !== pageSize) { onChange({page: 1, pageSize: size});return; }
  }
  const pages = Math.max(1, Math.ceil(total / pageSize));
  page = Math.max(1, Math.min(pages, page));
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  host.replaceChildren();host.classList.add('mm-pagination');host.dataset.mmPagination = '';
  host.setAttribute('role', 'navigation');host.setAttribute('aria-label', label);
  const size = node('div', undefined, 'mm-pagination-size'), sizeLabel = node('label', 'Items per page '), select = node('select');
  [...new Set([...audiencePageSizes, pageSize])].sort((a,b)=>a-b).forEach(value => {
    const option = node('option', String(value));option.value = value;option.selected = value === pageSize;select.append(option);
  });
  select.addEventListener('change', () => {
    const size = Number(select.value);
    if (key && audiencePageSizes.includes(size)) { try { localStorage.setItem(key, String(size)); } catch { /* Storage may be disabled. */ } }
    onChange({page: 1, pageSize: size});
  });
  sizeLabel.append(select);size.append(sizeLabel);
  const format = value => value.toLocaleString('en-US');
  const range = node('span', `${total ? format((page-1)*pageSize+1)+'–'+format(Math.min(page*pageSize,total)) : '0'} of ${format(total)}`, 'mm-pagination-range');
  range.setAttribute('aria-live', 'polite');
  const controls = node('div', undefined, 'mm-pagination-controls');
  for (const [name, iconPath, target, enabled] of [['Previous page','M20 12H4m7-7-7 7 7 7',page-1,page>1],['Next page','M4 12h16m-7-7 7 7-7 7',page+1,page<pages]]) {
    if (name === 'Next page') controls.append(node('span', `${page} / ${pages}`, 'mm-pagination-page'));
    const button = node('button', undefined, 'mm-pagination-arrow');button.type = 'button';button.disabled = !enabled;
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');svg.setAttribute('aria-hidden', 'true');svg.setAttribute('focusable', 'false');
    const path = document.createElementNS(svg.namespaceURI, 'path');path.setAttribute('d', iconPath);svg.append(path);button.append(svg);
    button.setAttribute('aria-label', name);button.dataset.tooltip = name;
    button.addEventListener('click', () => onChange({page: target, pageSize}));controls.append(button);
  }
  host.append(size, range, controls);
}
