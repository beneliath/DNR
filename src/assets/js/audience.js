'use strict';

import {wireAudiencePagination} from './audience-pagination.mjs';
import {wireAudienceMobile} from './audience-mobile.mjs';
import {wireMessageMonkeyUiStandards} from './audience-ui-standards.mjs';
import {initializeWorkspaceControls} from './message-monkey/workspace.mjs';
import {createFormConfirmations} from './message-monkey/dialogs.mjs';
import {initializeConnectionsWorkspace} from './message-monkey/connections.mjs';
import {initializeRecentCampaigns, initializeCampaignWorkspace} from './message-monkey/campaigns.mjs';
import {initializeContentWorkspace} from './message-monkey/content.mjs';
import {initializeAudienceDefaults} from './message-monkey/settings.mjs';

wireMessageMonkeyUiStandards();
wireAudiencePagination();

(() => {
    const {config, show} = initializeWorkspaceControls();
    initializeRecentCampaigns(config);
    const confirmations = createFormConfirmations();
    initializeConnectionsWorkspace(config, show);
    initializeCampaignWorkspace(show, confirmations);
    initializeContentWorkspace(show);
    initializeAudienceDefaults();
    wireAudienceMobile();
})();
