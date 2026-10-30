import {$, $$, el, request} from './dom.mjs';

export function createWorkspaceDialogs() {
    const campaignCreate = $('#campaign-create');
    const accountBanner = $('.account-identity-banner');
    const previewBanner = $('[data-role-preview-banner]');
    const deploymentBanner = $('[data-deployment-notice]');
    const adminBanner = $('[data-admin-unlock]');
    const mainToolbar = $('body > .audience-topbar');
    const campaignBack = campaignCreate?.querySelector('[data-campaign-create-back]');
    const campaignShell = [deploymentBanner, accountBanner, adminBanner, previewBanner, mainToolbar].filter(Boolean);
    const shellAnchors = new Map();
    const restoreCampaignShell = () => {
        if (shellAnchors.size) {
            if (campaignBack) campaignCreate.prepend(campaignBack);
            shellAnchors.forEach((anchor, node) => anchor.replaceWith(node));
            shellAnchors.clear();
        }
    };
    campaignCreate?.addEventListener('close', () => { if (!campaignCreate.open) restoreCampaignShell(); });
    mainToolbar?.querySelector('[data-audience-nav-toggle]')?.addEventListener('click', () => {
        if (campaignCreate?.open) { campaignCreate.close(); restoreCampaignShell(); }
    }, true);
    const show = id => {
        const dialog = document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        // Keep the live banners and toolbar inside the full-screen modal's focus scope.
        if (dialog === campaignCreate && mainToolbar) {
            campaignShell.forEach(node => {
                const anchor = document.createComment('Campaign shell');
                node.before(anchor); shellAnchors.set(node, anchor);
            });
            dialog.prepend(...campaignShell);
            if (campaignBack) mainToolbar.querySelector('.audience-brand').prepend(campaignBack);
        }
        dialog.showModal();
    };
    $$('[data-open-dialog]').filter(b => !b.closest('[data-audience-filter-summary], [data-audience-results]')).forEach(b => b.addEventListener('click', async event => {
        event.preventDefault();
        if (b.hasAttribute('data-admin-unlock-required') && !(await window.DnrAdminUnlock?.request())) return;
        show(b.dataset.openDialog);
    }));
    document.addEventListener('invalid', event => {
        let details = event.target.closest('details');
        while (details) { details.open = true; details = details.parentElement.closest('details'); }
    }, true);
    $$('dialog.person-editor').forEach(dialog => dialog.addEventListener('close', () => dialog.querySelector('form')?.reset()));
    $$('dialog[data-reopen-dialog]').forEach(dialog => show(dialog.id));
    $$('[data-close-dialog]').forEach(b => b.addEventListener('click', () => b.closest('dialog')?.close()));
    return show;
}

export function createFormConfirmations() {
    const confirmedForms = new WeakSet();
    const approvedDefinitionDeletes = new WeakSet(), checkingDefinitionDeletes = new WeakSet();
    const confirmation = el('dialog', {'aria-labelledby': 'audience-confirm-title', 'aria-describedby': 'audience-confirm-message'});
    const confirmationForm = el('form', {method: 'dialog'}), confirmationMessage = el('p', {id: 'audience-confirm-message'});
    const confirmationActions = el('div', {class: 'form-actions'});
    confirmationActions.append(el('button', {value: 'cancel', autofocus: true}, 'Cancel'), el('button', {value: 'continue', class: 'primary'}, 'Continue'));
    confirmationForm.append(el('h2', {id: 'audience-confirm-title'}, 'Confirm action'), confirmationMessage, confirmationActions);
    confirmation.append(confirmationForm); document.body.append(confirmation);
    let pendingConfirmation = null;
    confirmation.addEventListener('close', () => {
        const pending = pendingConfirmation; pendingConfirmation = null;
        if (confirmation.returnValue === 'continue' && pending) { confirmedForms.add(pending.form); pending.form.requestSubmit(pending.submitter || undefined); }
    });
    $$('form[data-confirm]').forEach(form => form.addEventListener('submit', async event => {
        if (approvedDefinitionDeletes.has(form)) { approvedDefinitionDeletes.delete(form); return; }
        const confirmed = confirmedForms.delete(form), protectedDelete = form.matches('[data-tag-delete-form], [data-segment-delete-form], [data-audience-protected-form]') || event.submitter?.matches('[data-touchpoint-delete][data-admin-unlock-required]');
        if (confirmed && !protectedDelete) return;
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (protectedDelete) {
            if (checkingDefinitionDeletes.has(form)) return;
            checkingDefinitionDeletes.add(form);
            let unlocked;
            try { unlocked = await window.DnrAdminUnlock?.request(); }
            finally { checkingDefinitionDeletes.delete(form); }
            if (!unlocked) return;
            if (confirmed) { approvedDefinitionDeletes.add(form); form.requestSubmit(event.submitter || undefined); return; }
        }
        pendingConfirmation = {form, submitter: event.submitter};
        confirmationMessage.textContent = event.submitter?.dataset.confirmMessage || form.dataset.confirm; confirmation.returnValue = ''; confirmation.showModal();
    }));
    return {approve: form => confirmedForms.add(form)};
}

