'use strict';

import {wireAudienceMobile} from './audience-mobile.mjs';
import {wireMessageMonkeyUiStandards} from './audience-ui-standards.mjs';
import {initializeWorkspaceControls} from './message-monkey/workspace.mjs';

// Home already renders its metrics and charts on the server. Only load the shared
// controls here; contact, campaign and content editors belong to their workspaces.
wireMessageMonkeyUiStandards();
initializeWorkspaceControls();
for (const button of document.querySelectorAll('[data-home-explanation-toggle]')) {
    const copy = document.getElementById(button.getAttribute('aria-controls'));
    button.addEventListener('click', () => {
        const open = copy.toggleAttribute('data-mm-open');
        button.setAttribute('aria-expanded', String(open));
        button.textContent = open ? 'Hide Explanation' : 'About These Metrics';
    });
}
wireAudienceMobile();
