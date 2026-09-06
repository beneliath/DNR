<?php
// The same affiliation controls serve contact creation and editing. Keep submitted
// values visible after a validation error, including incomplete rows.
$affiliation_form_rows = is_array($additional_organization_rows ?? null)
    ? array_values($additional_organization_rows)
    : [];
if ($affiliation_form_rows === []) {
    $affiliation_form_rows = [['organization_id' => '', 'role_title' => '']];
}
$render_affiliation_row = static function (array $row, string $index) use ($contact_organization_options): void {
    $organization_value = is_scalar($row['organization_id'] ?? null) ? (string) $row['organization_id'] : '';
    $role_value = is_scalar($row['role_title'] ?? null) ? (string) $row['role_title'] : '';
    ?>
    <div class="contact-affiliation-row" data-contact-affiliation-row>
        <div class="form-group">
            <label for="additional-organization-<?php echo $index; ?>">Additional organization</label>
            <select id="additional-organization-<?php echo $index; ?>" name="additional_organizations[<?php echo $index; ?>][organization_id]" data-affiliation-organization>
                <option value="">Select an organization</option>
                <?php foreach ($contact_organization_options as $option): ?>
                    <option value="<?php echo (int) $option['id']; ?>" <?php echo $organization_value === (string) $option['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['organization_name'], ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($option['is_deleted']) ? ' (Archived)' : ''; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="additional-role-<?php echo $index; ?>">Role or title at this organization</label>
            <input type="text" id="additional-role-<?php echo $index; ?>" name="additional_organizations[<?php echo $index; ?>][role_title]" value="<?php echo htmlspecialchars($role_value, ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" placeholder="For example, Chairman" data-affiliation-role>
        </div>
        <div class="contact-affiliation-actions">
            <button type="button" class="button-secondary" data-make-primary-affiliation hidden>Make primary</button>
            <button type="button" class="button-secondary" data-remove-affiliation hidden>Remove</button>
        </div>
    </div>
    <?php
};
?>
<fieldset class="contact-affiliations" data-contact-affiliations>
    <legend>Additional organizations</legend>
    <p>A person can serve several organizations. Add each organization and the role they hold there. Make primary switches their primary organization and keeps the previous organization and role in this list.</p>
    <div data-affiliation-rows>
        <?php foreach ($affiliation_form_rows as $index => $row): ?>
            <?php $render_affiliation_row(is_array($row) ? $row : [], (string) $index); ?>
        <?php endforeach; ?>
    </div>
    <button type="button" class="button-secondary" data-add-affiliation hidden>Add another organization</button>
    <p class="field-help" data-affiliation-status role="status" aria-live="polite"></p>
    <noscript><p>To remove an additional organization, clear both its organization and role fields.</p></noscript>
    <template data-affiliation-template><?php $render_affiliation_row([], '__index__'); ?></template>
</fieldset>
