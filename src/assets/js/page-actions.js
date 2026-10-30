import {initializeAddressInputs} from './address-inputs.mjs';
import {initializeAdditionalOrganizationContacts, initializeContactRole, initializeExistingOrganizationContacts, initializeMailingAddress, updatePrimaryOrganizationContactRequirements} from './moed/contact-forms.mjs';
import {initializeEngagementForm} from './moed/engagement-forms.mjs';
import {initializeSelectAll} from './moed/record-selection.mjs';
import {initializeCopyTextButtons, initializePresentationCopy, initializeQrCopy, initializeQrPdfSelection} from './moed/clipboard.mjs';
import {initializeDatabaseBackupSubmission, initializeInvitationSubmission} from './moed/submissions.mjs';
import {initializeDashboardHighlights, initializeDisclosurePopovers, initializeIconTooltips, initializeInquiryTabs, initializeStatusMessages} from './moed/workspace.mjs';

function initialize() {
    initializeIconTooltips();
    initializeStatusMessages();
    initializeContactRole();
    initializeMailingAddress();
    initializeAddressInputs();
    updatePrimaryOrganizationContactRequirements();
    initializeAdditionalOrganizationContacts();
    initializeExistingOrganizationContacts();
    initializeEngagementForm();
    initializeSelectAll('select-all-chron-entries', 'chron_entry_ids[]');
    initializeSelectAll('select-all-presentations', 'presentation_ids[]');
    initializeCopyTextButtons();
    initializeQrCopy();
    initializeQrPdfSelection();
    initializePresentationCopy();
    initializeDatabaseBackupSubmission();
    initializeInvitationSubmission();
    initializeInquiryTabs();
    initializeDisclosurePopovers();
    initializeDashboardHighlights();
    const success = document.querySelector('.success');
    if (success && document.querySelector('.engagement-form')) {
        const reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        success.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
    }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
else initialize();
