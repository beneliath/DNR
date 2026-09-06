<?php
$organization_contact_id = (int) $organization_contact['id'];
$selected_contact_roles = $engagement_contact_assignment_map[$organization_contact_id] ?? [];
$organization_contact_name = trim((string) $organization_contact['contact_first_name'] . ' '
    . (string) $organization_contact['contact_last_name']);
$organization_contact_role = organizationContactRoleLabel($organization_contact);
?>
<fieldset class="engagement-contact-card" data-existing-contact-id="<?php echo $organization_contact_id; ?>">
    <legend>
        <span><?php echo htmlspecialchars($organization_contact_name, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($organization_contact_role !== ''): ?>
            <small><?php echo htmlspecialchars($organization_contact_role, ENT_QUOTES, 'UTF-8'); ?></small>
        <?php endif; ?>
    </legend>
    <?php if ($engagement_contact_is_added): ?>
        <input type="hidden" name="engagement_added_contact_ids[]" value="<?php echo $organization_contact_id; ?>">
        <button type="button" class="button-secondary engagement-contact-remove" data-remove-added-contact>Remove from event</button>
    <?php endif; ?>
    <?php if (!empty($organization_contact['contact_email'])): ?>
        <p class="field-help engagement-contact-email"><?php echo htmlspecialchars((string) $organization_contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="engagement-contact-role-options">
        <?php foreach ($engagement_contact_role_options as $contact_role_value => $contact_role_label): ?>
            <label>
                <input type="checkbox"
                       name="engagement_contacts[<?php echo $organization_contact_id; ?>][]"
                       value="<?php echo htmlspecialchars($contact_role_value, ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo in_array($contact_role_value, $selected_contact_roles, true) ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($contact_role_label, ENT_QUOTES, 'UTF-8'); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
