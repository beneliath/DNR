// Connections is the single pagination pattern for MessageMonkey, including async lists.
export const audiencePageSizes = [10, 25, 50, 100];

export function wireAudiencePagination(root = document) {
  root.addEventListener('change', event => {
    if (event.target.matches('[data-mm-page-size]')) event.target.closest('form')?.requestSubmit();
  });
}

export function renderAudiencePagination(host, {total, page = 1, pageSize = 25, onChange, label = 'Results pages'}) {
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
  select.addEventListener('change', () => onChange({page: 1, pageSize: Number(select.value)}));
  sizeLabel.append(select);size.append(sizeLabel);
  const format = value => value.toLocaleString('en-US');
  const range = node('span', `${total ? format((page-1)*pageSize+1)+'–'+format(Math.min(page*pageSize,total)) : '0'} of ${format(total)}`, 'mm-pagination-range');
  range.setAttribute('aria-live', 'polite');
  const controls = node('div', undefined, 'mm-pagination-controls');
  for (const [name, symbol, target, enabled] of [['Previous page','←',page-1,page>1],['Next page','→',page+1,page<pages]]) {
    if (name === 'Next page') controls.append(node('span', `${page} / ${pages}`, 'mm-pagination-page'));
    const button = node('button', symbol, 'mm-pagination-arrow');button.type = 'button';button.disabled = !enabled;
    button.setAttribute('aria-label', name);button.dataset.tooltip = name;
    button.addEventListener('click', () => onChange({page: target, pageSize}));controls.append(button);
  }
  host.append(size, range, controls);
}
