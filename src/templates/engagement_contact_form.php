<section class="form-section engagement-contacts-section"
         data-engagement-contact-picker
         data-contact-options-url="organization_contacts.php">
    <div class="engagement-contacts-heading">
        <div>
            <h2>Event Contacts</h2>
            <p class="field-help">Assign only the contacts involved in this event. A contact may have several roles.</p>
        </div>
        <span class="engagement-contact-count" data-engagement-contact-count></span>
    </div>
    <p class="field-help engagement-contact-status" data-engagement-contact-status role="status" aria-live="polite"></p>
    <div class="engagement-contact-list" data-engagement-contact-list>
        <?php if (empty($selected_engagement_organization_id)): ?>
            <p class="engagement-contact-empty">Select an organization to load its contacts.</p>
        <?php elseif (empty($organization_contacts)): ?>
            <p class="engagement-contact-empty">This organization has no active contacts to assign.</p>
        <?php else: ?>
            <?php foreach ($organization_contacts as $organization_contact): ?>
                <?php
                $organization_contact_id = (int) $organization_contact['id'];
                $selected_contact_roles = $engagement_contact_assignment_map[$organization_contact_id] ?? [];
                $organization_contact_name = trim(
                    (string) $organization_contact['contact_first_name'] . ' '
                    . (string) $organization_contact['contact_last_name']
                );
                $organization_contact_role = organizationContactRoleLabel($organization_contact);
                ?>
                <fieldset class="engagement-contact-card">
                    <legend>
                        <span><?php echo htmlspecialchars($organization_contact_name, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($organization_contact_role !== ''): ?>
                            <small><?php echo htmlspecialchars($organization_contact_role, ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </legend>
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
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
