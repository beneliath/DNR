<?php
$presentation_form_rows = is_array($presentation_form_rows ?? null)
    ? array_values($presentation_form_rows)
    : [];
if (!$presentation_form_rows) {
    $presentation_form_rows = [[]];
}
?>
<section id="presentations-container" class="form-section"
         data-default-speaker="<?php echo htmlspecialchars($DEFAULT_SPEAKER, ENT_QUOTES, 'UTF-8'); ?>"
         data-require-presentation-on-save="<?php echo !empty($engagement_id) ? 'true' : 'false'; ?>">
    <div class="chron-log-heading">
        <h2>Presentation(s)</h2>
        <?php if (!empty($archived_presentation_count) && !empty($engagement_id)): ?>
            <a href="restore_presentations.php?engagement_id=<?php echo (int) $engagement_id; ?>" class="restore-button">Restore Archived Presentations (<?php echo (int) $archived_presentation_count; ?>)</a>
        <?php endif; ?>
    </div>
    <p class="field-help">Topic/title, date, and time are required for each presentation. Add at least one presentation before setting the engagement status to confirmed.</p>
    <div class="presentations-outer-box">
        <div class="presentations-inner-container">
            <?php foreach ($presentation_form_rows as $presentation_index => $presentation): ?>
                <?php
                $presentation_dom_id = $presentation_index + 1;
                [$presentation_time_value, $presentation_ampm] = presentationTimeParts(
                    $presentation['presentation_time'] ?? ''
                );
                $presentation_topic = (string) ($presentation['topic_title'] ?? '');
                $presentation_speaker = array_key_exists('speaker_name', $presentation)
                    ? (string) $presentation['speaker_name']
                    : $DEFAULT_SPEAKER;
                $is_saved_presentation = !empty($presentation['id']);
                $show_delete_button = !$is_saved_presentation
                    && (count($presentation_form_rows) > 1 || $presentation_topic !== '');
                ?>
                <div class="presentation-entry" id="presentation-<?php echo $presentation_dom_id; ?>">
                    <?php if (!empty($presentation['id'])): ?>
                        <input type="hidden" name="presentations[<?php echo $presentation_dom_id; ?>][id]" value="<?php echo (int) $presentation['id']; ?>">
                    <?php endif; ?>
                    <div class="presentation-fields">
                        <div class="form-field topic">
                            <label for="presentation_topic_<?php echo $presentation_dom_id; ?>">Topic/Title<span class="required">*</span></label>
                            <input type="text" name="presentations[<?php echo $presentation_dom_id; ?>][topic_title]" id="presentation_topic_<?php echo $presentation_dom_id; ?>" maxlength="255" value="<?php echo htmlspecialchars($presentation_topic, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="datetime-row">
                            <div class="form-field">
                                <label for="presentation_date_<?php echo $presentation_dom_id; ?>">Date<span class="required">*</span></label>
                                <input type="date" name="presentations[<?php echo $presentation_dom_id; ?>][presentation_date]" id="presentation_date_<?php echo $presentation_dom_id; ?>" value="<?php echo htmlspecialchars((string) ($presentation['presentation_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field">
                                <label for="presentation_time_<?php echo $presentation_dom_id; ?>">Time<span class="required">*</span></label>
                                <div class="time-input-container">
                                    <input type="text" id="presentation_time_<?php echo $presentation_dom_id; ?>" inputmode="numeric" pattern="[0-9]{1,2}:[0-9]{2}" placeholder="HH:MM or 1530" value="<?php echo htmlspecialchars($presentation_time_value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="ampm-radio">
                                        <label><input type="radio" name="presentation_ampm_<?php echo $presentation_dom_id; ?>" value="AM" <?php echo $presentation_ampm === 'AM' ? 'checked' : ''; ?>> AM</label>
                                        <label><input type="radio" name="presentation_ampm_<?php echo $presentation_dom_id; ?>" value="PM" <?php echo $presentation_ampm === 'PM' ? 'checked' : ''; ?>> PM</label>
                                    </div>
                                </div>
                                <input type="hidden" name="presentations[<?php echo $presentation_dom_id; ?>][presentation_time]" id="presentation_time_hidden_<?php echo $presentation_dom_id; ?>" value="<?php echo htmlspecialchars((string) ($presentation['presentation_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="speaker-row">
                            <div class="form-field speaker">
                                <label for="speaker_name_<?php echo $presentation_dom_id; ?>">Speaker Name</label>
                                <input type="text" name="presentations[<?php echo $presentation_dom_id; ?>][speaker_name]" id="speaker_name_<?php echo $presentation_dom_id; ?>" maxlength="255" value="<?php echo htmlspecialchars($presentation_speaker, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field attendance">
                                <label for="expected_attendance_<?php echo $presentation_dom_id; ?>">Expected Attendance</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][expected_attendance]" id="expected_attendance_<?php echo $presentation_dom_id; ?>" min="1" step="1" value="<?php echo htmlspecialchars((string) ($presentation['expected_attendance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <?php if ($is_saved_presentation): ?>
                            <div class="remove-btn-container presentation-management-actions">
                                <?php if (canArchiveEntries($user_role ?? '')): ?>
                                    <button type="submit" form="archive-presentation-<?php echo (int) $presentation['id']; ?>" class="archive-button">Archive</button>
                                <?php endif; ?>
                                <?php if (canDeleteEntries($user_role ?? '')): ?>
                                    <button type="submit" form="delete-presentation-<?php echo (int) $presentation['id']; ?>" class="delete-button">Delete</button>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($show_delete_button): ?>
                            <div class="remove-btn-container">
                                <button type="button" onclick="removePresentation(<?php echo $presentation_dom_id; ?>)" class="remove-presentation-btn">Remove</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" onclick="addPresentation()" class="add-presentation-btn">Add Presentation</button>
    </div>
</section>
