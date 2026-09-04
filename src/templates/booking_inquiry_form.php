<?php
$inquiry_form_values = is_array($inquiry_form_values ?? null) ? $inquiry_form_values : [];
$inquiry_form_action = (string) ($inquiry_form_action ?? 'add_inquiry.php');
$inquiry_form_submit_label = (string) ($inquiry_form_submit_label ?? 'Save Inquiry');
$inquiry_organizations = is_array($inquiry_organizations ?? null) ? $inquiry_organizations : [];
$inquiry_contacts = is_array($inquiry_contacts ?? null) ? $inquiry_contacts : [];
$inquiry_owners = is_array($inquiry_owners ?? null) ? $inquiry_owners : [];
$inquiry_sources = bookingInquirySelectableSources();
$inquiry_priorities = bookingInquiryPriorities();
$inquiry_event_types = \Dnr\Domain\ReferenceData::eventTypes();
$value = static fn(string $key, string $fallback = ''): string => (string) ($inquiry_form_values[$key] ?? $fallback);
?>
<form method="post" action="<?php echo htmlspecialchars($inquiry_form_action, ENT_QUOTES, 'UTF-8'); ?>" class="inquiry-form">
    <?php echo csrfInput(); ?>
    <?php if (!empty($inquiry_id)): ?><input type="hidden" name="id" value="<?php echo (int) $inquiry_id; ?>"><?php endif; ?>
    <?php if ($value('updated_at') !== ''): ?><input type="hidden" name="inquiry_version" value="<?php echo htmlspecialchars($value('updated_at'), ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <?php if (!empty($inbound_email_message_id)): ?><input type="hidden" name="inbound_email_message_id" value="<?php echo (int) $inbound_email_message_id; ?>"><?php endif; ?>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required Fields</p>

    <section class="form-section">
        <div class="inquiry-form-section-heading"><span>01</span><div><h2>Request</h2><p>Capture enough context to qualify the opportunity without inventing event details.</p></div></div>
        <div class="form-group">
            <label for="inquiry-title" class="required">Inquiry Title</label>
            <input type="text" id="inquiry-title" name="title" maxlength="255" required value="<?php echo htmlspecialchars($value('title'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Organization or proposed event">
        </div>
        <div class="inquiry-form-grid">
            <div class="form-group">
                <label for="inquiry-organization">Organization</label>
                <select id="inquiry-organization" name="organization_id">
                    <option value="">Not Identified Yet</option>
                    <?php foreach ($inquiry_organizations as $organization): ?>
                        <option value="<?php echo (int) $organization['id']; ?>"<?php echo (int) $value('organization_id') === (int) $organization['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $organization['organization_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="inquiry-contact">Primary Contact</label>
                <select id="inquiry-contact" name="primary_contact_id">
                    <option value="">Not Identified Yet</option>
                    <?php foreach ($inquiry_contacts as $contact): ?>
                        <?php $contact_label = trim($contact['contact_last_name'] . ', ' . $contact['contact_first_name']) . (!empty($contact['organization_name']) ? ' · ' . $contact['organization_name'] : ' · Standalone'); ?>
                        <option value="<?php echo (int) $contact['id']; ?>"<?php echo (int) $value('primary_contact_id') === (int) $contact['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($contact_label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group inquiry-request-summary">
            <label for="inquiry-summary">What Is Being Requested?</label>
            <textarea id="inquiry-summary" name="request_summary" rows="8" maxlength="100000" placeholder="Audience, goals, event shape, presentation requests, constraints, and open questions"><?php echo htmlspecialchars($value('request_summary'), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
        <div class="inquiry-form-grid inquiry-event-type-grid">
            <div class="form-group">
                <label for="event_type">Likely Event Type</label>
                <select id="event_type" name="event_type">
                    <?php foreach ($inquiry_event_types as $event_type): ?><option value="<?php echo htmlspecialchars($event_type, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $value('event_type', 'conference') === $event_type ? ' selected' : ''; ?>><?php echo htmlspecialchars(ucwords($event_type), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="other_event_type_div"<?php echo $value('event_type', 'conference') === 'other' ? '' : ' hidden'; ?>>
                <label for="event_type_other">Other Event Type</label>
                <input type="text" id="event_type_other" name="event_type_other" maxlength="50" value="<?php echo htmlspecialchars($value('event_type_other'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="inquiry-form-section-heading"><span>02</span><div><h2>Timing &amp; Location</h2><p>Date ranges remain tentative until conversion creates the engagement.</p></div></div>
        <div class="inquiry-date-ranges">
            <fieldset><legend>Preferred Range</legend><div class="inquiry-form-grid"><div><label for="preferred-start">Start</label><input type="date" id="preferred-start" name="preferred_start_date" value="<?php echo htmlspecialchars($value('preferred_start_date'), ENT_QUOTES, 'UTF-8'); ?>"></div><div><label for="preferred-end">End</label><input type="date" id="preferred-end" name="preferred_end_date" value="<?php echo htmlspecialchars($value('preferred_end_date'), ENT_QUOTES, 'UTF-8'); ?>"></div></div></fieldset>
            <fieldset><legend>Alternate Range</legend><div class="inquiry-form-grid"><div><label for="alternate-start">Start</label><input type="date" id="alternate-start" name="alternate_start_date" value="<?php echo htmlspecialchars($value('alternate_start_date'), ENT_QUOTES, 'UTF-8'); ?>"></div><div><label for="alternate-end">End</label><input type="date" id="alternate-end" name="alternate_end_date" value="<?php echo htmlspecialchars($value('alternate_end_date'), ENT_QUOTES, 'UTF-8'); ?>"></div></div></fieldset>
        </div>
        <div class="address-section is-saved-address-section inquiry-location-section">
            <h3>Event Location</h3>
            <div class="address-fields">
                <div class="form-field">
                    <label for="event_address_line_1">Address Line 1</label>
                    <input type="text" name="event_address_line_1" id="event_address_line_1" maxlength="255" value="<?php echo htmlspecialchars($value('event_address_line_1'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-field">
                    <label for="event_address_line_2">Address Line 2</label>
                    <input type="text" name="event_address_line_2" id="event_address_line_2" maxlength="255" value="<?php echo htmlspecialchars($value('event_address_line_2'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="address-row event-address-row">
                    <div class="form-field event-address-city-field">
                        <label for="event_city">City</label>
                        <input type="text" name="event_city" id="event_city" maxlength="100" value="<?php echo htmlspecialchars($value('event_city'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-field event-address-state-field">
                        <label for="event_state">State</label>
                        <div data-address-region-control data-address-region-for="event">
                            <input type="text" name="event_state" id="event_state" maxlength="100" value="<?php echo htmlspecialchars($value('event_state'), ENT_QUOTES, 'UTF-8'); ?>" data-address-region-input>
                        </div>
                    </div>
                    <div class="form-field event-address-zipcode-field">
                        <label for="event_zipcode">Zipcode</label>
                        <input type="text" name="event_zipcode" id="event_zipcode" maxlength="20" value="<?php echo htmlspecialchars($value('event_zipcode'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-field event-address-country-field">
                        <label>Country</label>
                        <?php echo addressCountryPicker(
                            'event_country',
                            $value('event_country', applicationDefaultCountry()),
                            'event'
                        ); ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="form-section">
        <div class="inquiry-form-section-heading"><span>03</span><div><h2>Ownership &amp; Next Action</h2><p>Every active inquiry should make its next move visible.</p></div></div>
        <div class="inquiry-form-grid">
            <div class="form-group"><label for="inquiry-owner">Owner</label><select id="inquiry-owner" name="owner_user_id"><option value="">Unassigned</option><?php foreach ($inquiry_owners as $owner): ?><option value="<?php echo (int) $owner['id']; ?>"<?php echo (int) $value('owner_user_id') === (int) $owner['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($owner['username'] . ' · ' . ucfirst($owner['role']), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="inquiry-priority">Priority</label><select id="inquiry-priority" name="priority"><?php foreach ($inquiry_priorities as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $value('priority', 'normal') === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="inquiry-source-fields">
            <div class="form-group"><label for="inquiry-source">Source</label><select id="inquiry-source" name="source"><?php foreach ($inquiry_sources as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $value('source', 'other') === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            <div class="form-group inquiry-source-detail-field"><label for="inquiry-source-detail">Source Detail</label><input id="inquiry-source-detail" name="source_detail" maxlength="255" value="<?php echo htmlspecialchars($value('source_detail'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Referrer, mailbox, form, or campaign"></div>
        </div>
        <div class="inquiry-next-action-fields">
            <div class="form-group inquiry-next-action-detail-field"><label for="inquiry-next-action">Next Action</label><input id="inquiry-next-action" name="next_action" maxlength="255" value="<?php echo htmlspecialchars($value('next_action'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Call host, request venue details, send proposal…"></div>
            <div class="form-group"><label for="inquiry-next-action-due">Due</label><input type="date" id="inquiry-next-action-due" name="next_action_due_date" value="<?php echo htmlspecialchars($value('next_action_due_date'), ENT_QUOTES, 'UTF-8'); ?>"></div>
        </div>
    </section>

    <div class="engagement-page-actions inquiry-form-actions">
        <a href="<?php echo !empty($inquiry_id) ? 'view_inquiry.php?id=' . (int) $inquiry_id : 'inquiries.php'; ?>" class="button-secondary">Cancel</a>
        <button type="submit" name="save_inquiry" value="1" class="save-button inquiry-primary-action"><?php echo htmlspecialchars($inquiry_form_submit_label, ENT_QUOTES, 'UTF-8'); ?></button>
    </div>
</form>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="address-region-data"><?php echo json_encode(
    addressRegionClientData(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
); ?></script>
