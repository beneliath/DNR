// Runs at the start of the workspace body, before navigation and table cells are parsed.
import {audienceMobileQuery} from './audience-mobile.mjs';

try {
    const body = document.body;
    if (!matchMedia(audienceMobileQuery).matches && localStorage.getItem('moed:audience:sidebar-collapsed') === '1') {
        body.classList.add('audience-sidebar-collapsed');
    }
    const historyKey = body.dataset.mmHistoryKey;
    if (historyKey && (localStorage.getItem(historyKey) || JSON.parse(localStorage.getItem(historyKey+'-hidden'))?.length)) {
        body.setAttribute('data-mm-pending-history','');
    }
    if (body.hasAttribute('data-mm-campaign-list')) {
        const columns = JSON.parse(localStorage.getItem('campaign-columns:' + body.dataset.audienceKey));
        if (Array.isArray(columns)) body.dataset.mmHiddenColumns = columns.filter(key => typeof key === 'string').join(' ');
    }
} catch { /* Use the default layout when browser storage is unavailable. */ }
