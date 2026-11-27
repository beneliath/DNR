import {initializeInputModality} from '../input-modality.mjs';
import {$, $$} from './dom.mjs';
import {initializeMessageMonkeyShell} from './shell.mjs';
import {createWorkspaceDialogs} from './dialogs.mjs';
import {initializeWorkspaceSearch} from './search.mjs';

// Shared navigation and account controls for both Home and the editing workspaces.
export function initializeWorkspaceControls() {
    initializeInputModality();
    initializeMessageMonkeyShell();
    const config = JSON.parse($('#audience-config')?.textContent || '{}');
    document.addEventListener('admin-unlock-changed', event => {
        if (typeof event.detail?.csrf_token === 'string') config.csrf = event.detail.csrf_token;
    });
    $$('[data-audience-admin-unlock]').forEach(button => button.addEventListener('click', () => window.DnrAdminUnlock?.request()));
    const show = createWorkspaceDialogs();
    initializeWorkspaceSearch(config);
    return {config, show};
}
