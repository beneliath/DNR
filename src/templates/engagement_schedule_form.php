<div class="engagement-schedule-row" role="group" aria-label="Schedule">
    <div class="engagement-schedule-field">
        <label for="event_start_date" class="required"><span aria-hidden="true">*</span>Start</label>
        <input type="text" data-date-picker="date" placeholder="YYYY-MM-DD" name="event_start_date" id="event_start_date" aria-label="Start" required value="<?php echo htmlspecialchars($engagement_form_values['event_start_date'] ?? ''); ?>">
    </div>
    <div class="engagement-schedule-field">
        <label for="event_end_date" class="required"><span aria-hidden="true">*</span>End</label>
        <input type="text" data-date-picker="date" placeholder="YYYY-MM-DD" name="event_end_date" id="event_end_date" aria-label="End" required value="<?php echo htmlspecialchars($engagement_form_values['event_end_date'] ?? ''); ?>">
    </div>
    <div class="engagement-schedule-field">
        <label class="label-container" for="event_type">Event Type</label>
        <select name="event_type" id="event_type">
            <?php
            $event_types = \Dnr\Domain\ReferenceData::eventTypes();
            $selected_type = $engagement_form_values['event_type'] ?? '';
            foreach ($event_types as $type) {
                $selected = ($selected_type === $type) ? 'selected' : '';
                echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
            }
            ?>
        </select>
    </div>

    <div class="engagement-schedule-field" id="other_event_type_div" <?php echo ($engagement_form_values['event_type'] ?? '') === 'other' ? '' : 'hidden'; ?>>
        <label for="event_type_other" class="required"><span aria-hidden="true">*</span>Other Event Type</label>
        <input type="text" name="event_type_other" id="event_type_other" value="<?php echo htmlspecialchars($engagement_form_values['event_type_other'] ?? ''); ?>">
    </div>
</div>
