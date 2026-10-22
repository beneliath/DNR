// Loaded inside the public endpoint's bounded configuration closure.
if (!config.origins.includes(location.origin) || !['popup', 'embedded'].includes(config.kind)) return;
const anchor = document.currentScript;
if (!anchor) return;
const storageKey = 'message-monkey:' + config.key;
let visitor = '';
let dismissed = 0;
try { visitor = localStorage.getItem(storageKey + ':visitor') || ''; dismissed = Number(localStorage.getItem(storageKey + ':dismissed') || 0); } catch (_) {}
if (!/^[a-f0-9]{64}$/.test(visitor)) {
    visitor = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
    try { localStorage.setItem(storageKey + ':visitor', visitor); } catch (_) {}
}
const frame = document.createElement('iframe');
frame.title = 'Newsletter signup';
frame.referrerPolicy = 'origin';
frame.src = config.url + '&visitor=' + encodeURIComponent(visitor);
frame.setAttribute('sandbox', 'allow-forms allow-same-origin allow-popups' + (config.kind === 'embedded' && config.javascript ? ' allow-scripts' : ''));
frame.style.cssText = 'display:block;width:100%;height:720px;border:0;background:white;';
if (config.kind === 'embedded') {
    window.addEventListener('message', event => {
        if (event.origin !== new URL(config.url).origin || event.source !== frame.contentWindow || event.data?.type !== 'message-monkey-form-height' || event.data.key !== config.key) return;
        if (Number.isInteger(event.data.height) && event.data.height >= 100 && event.data.height <= 20000) frame.style.height = event.data.height + 'px';
    });
    anchor.insertAdjacentElement('afterend', frame); return;
}
const rule = config.popup;
const mobile = matchMedia('(max-width:767px)').matches;
if ((rule.device === 'mobile' && !mobile) || (rule.device === 'desktop' && mobile)) return;
if ((rule.paths.length && !rule.paths.some(p => location.pathname.startsWith(p))) || rule.exclude_paths.some(p => location.pathname.startsWith(p))) return;
if (Date.now() - dismissed < rule.frequency * 86400000) return;
let opened = false;
const show = () => {
    if (opened) return;
    if (document.querySelector('dialog[data-message-monkey-popup]')) return;
    opened = true;
    const dialog = document.createElement('dialog');
    dialog.setAttribute('aria-label', 'Newsletter signup');
    dialog.setAttribute('data-message-monkey-popup', config.key);
    dialog.style.cssText = 'padding:0;width:min(640px,calc(100vw - 32px));max-height:90vh;border:0;border-radius:12px;';
    const close = document.createElement('button');
    close.textContent = '×'; close.setAttribute('aria-label', 'Close signup form');
    close.style.cssText = 'position:absolute;right:12px;top:8px;width:36px;height:36px;font-size:28px;background:white;color:#241c15;border:0;border-radius:50%;cursor:pointer;';
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { try { localStorage.setItem(storageKey + ':dismissed', String(Date.now())); } catch (_) {} dialog.remove(); });
    dialog.append(close, frame); document.body.append(dialog); dialog.showModal(); close.focus();
};
if (rule.trigger === 'exit') { if (!mobile) document.addEventListener('mouseout', event => { if (!event.relatedTarget && event.clientY <= 0) show(); }); }
else if (rule.trigger === 'scroll') window.addEventListener('scroll', () => { if (scrollY + innerHeight >= document.documentElement.scrollHeight / 2) show(); }, {passive:true});
else setTimeout(show, rule.delay * 1000);
