<section class="form-section engagement-lifecycle-section"
         data-engagement-lifecycle
         data-reschedule-options-url="engagement_reschedule_options.php"
         data-engagement-id="<?php echo (int) ($current_engagement_id ?? 0); ?>">
    <div class="engagement-lifecycle-heading">
        <div>
            <h2>Lifecycle</h2>
            <p class="field-help">Track whether the event is operationally active, postponed, canceled, or completed. Confirmation remains a separate planning status.</p>
        </div>
        <span class="lifecycle-badge lifecycle-<?php echo htmlspecialchars((string) $selected_lifecycle_status, ENT_QUOTES, 'UTF-8'); ?>" data-lifecycle-badge>
            <?php echo htmlspecialchars(engagementLifecycleLabel($selected_lifecycle_status), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>

    <div class="engagement-lifecycle-grid">
        <div class="form-field lifecycle-field">
            <label for="lifecycle_status">Lifecycle state</label>
            <select name="lifecycle_status" id="lifecycle_status">
                <?php foreach ($engagement_lifecycle_options as $lifecycle_value => $lifecycle_label): ?>
                    <option value="<?php echo htmlspecialchars($lifecycle_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_lifecycle_status === $lifecycle_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($lifecycle_label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-field lifecycle-field">
            <label for="confirmation_status">Confirmation status</label>
            <select name="confirmation_status" id="confirmation_status">
                <?php foreach ($engagement_confirmation_statuses as $confirmation_value): ?>
                    <option value="<?php echo htmlspecialchars($confirmation_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_confirmation_status === $confirmation_value ? ' selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($confirmation_value), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="lifecycle-dependent-field" data-cancellation-fields<?php echo $selected_lifecycle_status === 'canceled' ? '' : ' hidden'; ?>>
        <label for="cancellation_reason">Cancellation reason <span class="required" aria-hidden="true">*</span></label>
        <textarea name="cancellation_reason" id="cancellation_reason" rows="4" maxlength="1000" placeholder="Explain why the event was canceled."><?php echo htmlspecialchars((string) $selected_cancellation_reason, ENT_QUOTES, 'UTF-8'); ?></textarea>
    </div>

    <div class="lifecycle-dependent-field" data-reschedule-fields<?php echo in_array($selected_lifecycle_status, ['postponed', 'canceled'], true) ? '' : ' hidden'; ?>>
        <label for="rescheduled_to_engagement_id">Rescheduled event</label>
        <select name="rescheduled_to_engagement_id" id="rescheduled_to_engagement_id">
            <option value="">No replacement event linked</option>
            <?php foreach ($reschedule_candidates as $reschedule_candidate): ?>
                <?php $reschedule_candidate_id = (int) $reschedule_candidate['id']; ?>
                <option value="<?php echo $reschedule_candidate_id; ?>"<?php echo $selected_rescheduled_to_engagement_id === $reschedule_candidate_id ? ' selected' : ''; ?>><?php echo htmlspecialchars(engagementReferenceLabel($reschedule_candidate) . ' · ' . engagementLifecycleLabel($reschedule_candidate['lifecycle_status']), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="field-help">Link the original event to its replacement. The replacement must belong to the same organization.</p>
        <p class="field-help lifecycle-options-status" data-reschedule-status role="status" aria-live="polite"></p>
    </div>
</section>
