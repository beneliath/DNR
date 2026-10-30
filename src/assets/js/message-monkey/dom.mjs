export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
export const clone = value => JSON.parse(JSON.stringify(value));
export const el = (tag, attrs = {}, text) => {
    const node = document.createElement(tag);
    if (['form', 'input', 'textarea'].includes(tag)) node.setAttribute('autocomplete', 'off');
    for (const [key, value] of Object.entries(attrs)) {
        if (key === 'class') node.className = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else if (value !== false && value != null) node.setAttribute(key, String(value));
    }
    if (text != null) node.textContent = text;
    return node;
};
export const button = (text, action, label = text) => el('button', {type: 'button', onclick: action, 'aria-label': label}, text);
export const setValue = (form, name, value) => { const control = form?.elements.namedItem(name); if (control) control.value = value ?? ''; };
export const hidden = (name, value) => el('input', {type: 'hidden', name, value});
export const request = async (url, options) => {
    const response = await fetch(url, {credentials: 'same-origin', ...options});
    if (!response.headers.get('content-type')?.includes('application/json')) throw new Error(response.redirected || response.status === 401 ? 'Your session has expired. Sign in and reload this page.' : `Unable to read the operation status (HTTP ${response.status}). Reload the page to retry.`);
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || 'The operation failed. Try again.');
    return data;
};
export const workspaceUrl = (config, params) => { const url = new URL(config.url, location.href); for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value)); return url.href; };
