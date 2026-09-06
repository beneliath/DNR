<section class="form-section engagement-contacts-section" id="engagement-contact-selector"
         data-engagement-contact-picker
         data-contact-options-url="organization_contacts.php">
    <div class="engagement-contacts-heading">
        <div>
            <h2>Event Contacts</h2>
            <p class="field-help">Add as many contacts as this event needs. Select one or more event roles for each person.</p>
        </div>
        <span class="engagement-contact-count" data-engagement-contact-count></span>
    </div>
    <h3>Contacts at This Organization</h3>
    <p class="field-help">Check roles for each existing contact you want to include in this event.</p>
    <p class="field-help engagement-contact-status" data-engagement-contact-status role="status" aria-live="polite"></p>
    <button type="button" class="button-secondary" data-retry-contact-load hidden>Retry loading contacts</button>
    <div class="engagement-contact-list" data-engagement-contact-list>
        <?php if (empty($selected_engagement_organization_id)): ?>
            <p class="engagement-contact-empty">Select an organization to load its contacts.</p>
        <?php elseif (empty($organization_contacts)): ?>
            <p class="engagement-contact-empty">No other contacts at this organization. Search existing contacts or add a new contact below.</p>
        <?php else: ?>
            <?php $engagement_contact_is_added = false; ?>
            <?php foreach ($organization_contacts as $organization_contact): ?>
                <?php include __DIR__ . '/engagement_existing_contact_card.php'; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="engagement-contact-add-section">
        <h3>Add Existing Contacts</h3>
        <p class="field-help" id="engagement-contact-search-help">Search the contact directory by name or email. Contacts you select will also be associated with this organization when you save the event, keeping their other organizations.</p>
        <label for="engagement-contact-search">Find a contact</label>
        <div class="engagement-contact-search-controls">
            <input type="search" id="engagement-contact-search" data-contact-search maxlength="100"
                   placeholder="Enter at least 2 characters" aria-describedby="engagement-contact-search-help" autocomplete="off">
            <button type="button" class="button-secondary" data-contact-search-button>Search contacts</button>
        </div>
        <p class="field-help" data-contact-search-status role="status" aria-live="polite"></p>
        <div class="engagement-contact-search-results" data-contact-search-results></div>
        <div class="engagement-contact-list" data-engagement-added-contact-list>
            <?php $engagement_contact_is_added = true; ?>
            <?php foreach ($engagement_added_contacts as $organization_contact): ?>
                <?php include __DIR__ . '/engagement_existing_contact_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="engagement-contact-add-section">
        <div class="engagement-contacts-heading">
            <div>
                <h3>Add New Contacts</h3>
                <p class="field-help">New contacts are created and associated with this organization when you save the event.</p>
            </div>
            <button type="button" class="button-secondary" data-add-new-contact>Add new contact</button>
        </div>
        <div class="engagement-contact-list" data-engagement-new-contact-list>
            <?php foreach ($engagement_new_contact_rows as $new_contact_index => $new_contact_row): ?>
                <?php include __DIR__ . '/engagement_new_contact_card.php'; ?>
            <?php endforeach; ?>
        </div>
        <template data-new-contact-template>
            <?php
            $new_contact_index = '__INDEX__';
            $new_contact_row = ['phone_country_code' => applicationDefaultPhoneCountryCode(), 'roles' => ['on_site_contact']];
            include __DIR__ . '/engagement_new_contact_card.php';
            ?>
        </template>
    </div>
</section>
