'use strict';

import {wireAudiencePagination} from './audience-pagination.mjs';
import {wireAudienceMobile} from './audience-mobile.mjs';
import {initializeInputModality} from './input-modality.mjs';
import {wireMessageMonkeyUiStandards} from './audience-ui-standards.mjs';
import {$, $$} from './message-monkey/dom.mjs';
import {initializeMessageMonkeyShell} from './message-monkey/shell.mjs';
import {createWorkspaceDialogs, createFormConfirmations} from './message-monkey/dialogs.mjs';
import {initializeWorkspaceSearch} from './message-monkey/search.mjs';
import {initializeConnectionsWorkspace} from './message-monkey/connections.mjs';
import {initializeRecentCampaigns, initializeCampaignWorkspace} from './message-monkey/campaigns.mjs';
import {initializeContentWorkspace} from './message-monkey/content.mjs';

wireMessageMonkeyUiStandards();
wireAudiencePagination();
wireAudienceMobile();

(() => {
    initializeInputModality();
    initializeMessageMonkeyShell();
    const config = JSON.parse($('#audience-config')?.textContent || '{}');
    document.addEventListener('admin-unlock-changed', event => { if (typeof event.detail?.csrf_token === 'string') config.csrf = event.detail.csrf_token; });
    $$('[data-audience-admin-unlock]').forEach(button => button.addEventListener('click', () => window.DnrAdminUnlock?.request()));
    initializeRecentCampaigns(config);
    const show = createWorkspaceDialogs();
    initializeWorkspaceSearch(config);
    const confirmations = createFormConfirmations();
    initializeConnectionsWorkspace(config, show);
    initializeCampaignWorkspace(show, confirmations);
    initializeContentWorkspace(show);
})();
