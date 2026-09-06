<fieldset class="engagement-contact-card engagement-new-contact" data-new-contact-row>
    <legend><span>New contact</span></legend>
    <button type="button" class="button-secondary engagement-contact-remove" data-remove-new-contact>Remove new contact</button>
    <div class="engagement-new-contact-fields">
        <?php foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email'] as $contact_field => $contact_label): ?>
            <div class="form-group">
                <label for="event-new-contact-<?php echo $new_contact_index; ?>-<?php echo $contact_field; ?>" class="required"><?php echo $contact_label; ?></label>
                <input type="<?php echo $contact_field === 'email' ? 'email' : 'text'; ?>"
                       id="event-new-contact-<?php echo $new_contact_index; ?>-<?php echo $contact_field; ?>"
                       name="engagement_new_contacts[<?php echo $new_contact_index; ?>][<?php echo $contact_field; ?>]"
                       value="<?php echo htmlspecialchars((string) ($new_contact_row[$contact_field] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       maxlength="255" required>
            </div>
        <?php endforeach; ?>
        <div class="form-group">
            <label for="event-new-contact-<?php echo $new_contact_index; ?>-role-title">Title at this organization</label>
            <input type="text" id="event-new-contact-<?php echo $new_contact_index; ?>-role-title"
                   name="engagement_new_contacts[<?php echo $new_contact_index; ?>][role_title]"
                   value="<?php echo htmlspecialchars((string) ($new_contact_row['role_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                   maxlength="255" placeholder="For example, Pastor or Chairman">
        </div>
        <div class="form-group engagement-new-contact-phone">
            <label for="event-new-contact-<?php echo $new_contact_index; ?>-phone">Phone</label>
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('engagement_new_contacts[' . $new_contact_index . '][phone_country_code]',
                    ($new_contact_row['phone_country_code'] ?? '') ?: applicationDefaultPhoneCountryCode()); ?>
                <input type="tel" id="event-new-contact-<?php echo $new_contact_index; ?>-phone"
                       name="engagement_new_contacts[<?php echo $new_contact_index; ?>][phone]"
                       value="<?php echo htmlspecialchars((string) ($new_contact_row['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                       autocomplete="tel-national" inputmode="tel" data-phone-number>
            </div>
        </div>
    </div>
    <p class="field-help">Select one or more event roles</p>
    <div class="engagement-contact-role-options">
        <?php foreach ($engagement_contact_role_options as $contact_role_value => $contact_role_label): ?>
            <label>
                <input type="checkbox" name="engagement_new_contacts[<?php echo $new_contact_index; ?>][roles][]"
                       value="<?php echo htmlspecialchars($contact_role_value, ENT_QUOTES, 'UTF-8'); ?>"
                       <?php echo in_array($contact_role_value, $new_contact_row['roles'] ?? [], true) ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($contact_role_label, ENT_QUOTES, 'UTF-8'); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</fieldset>
