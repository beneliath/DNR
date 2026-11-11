<?php
$presentation_form_rows = is_array($presentation_form_rows ?? null)
    ? array_values($presentation_form_rows)
    : [];
if (!$presentation_form_rows) {
    $presentation_form_rows = [[]];
}
$has_saved_presentations = false;
foreach ($presentation_form_rows as $presentation_form_row) {
    if (!empty($presentation_form_row['id'])) {
        $has_saved_presentations = true;
        break;
    }
}
?>
<section id="presentations-container" class="form-section"
         data-default-speaker="<?php echo (int) $default_speaker_id; ?>">
    <div class="chron-log-heading">
        <h2>Presentation(s)</h2>
    </div>
    <?php if (!empty($presentation_action_message)): ?>
        <div class="success"><?php echo htmlspecialchars($presentation_action_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($presentation_action_error)): ?>
        <div class="error"><?php echo htmlspecialchars($presentation_action_error); ?></div>
    <?php endif; ?>
    <label class="checkbox-label" for="presentation_not_required">
        <input type="checkbox" id="presentation_not_required" name="presentation_not_required" value="1" aria-controls="presentation-details"<?php echo !empty($presentation_not_required) ? ' checked' : ''; ?>>
        No Presentation Required
    </label>
    <p class="field-help">Select this to confirm the event without adding a presentation.</p>
    <fieldset id="presentation-details" aria-label="Presentation Details" class="presentations-outer-box<?php echo $has_saved_presentations ? ' has-saved-presentations' : ''; ?>"<?php echo !empty($presentation_not_required) ? ' hidden disabled' : ''; ?>>
        <p class="field-help">Presentation details can be filled in later, and actual attendance can be recorded after the event.</p>
        <p class="field-help"><a href="speakers.php" target="_blank" rel="noopener">View or Manage Speakers (Opens in a New Tab)</a></p>
        <?php if (!empty($archived_presentation_count) && !empty($engagement_id)): ?>
            <a href="restore_presentations.php?engagement_id=<?php echo (int) $engagement_id; ?>" class="restore-button">Restore Archived Presentations (<?php echo (int) $archived_presentation_count; ?>)</a>
        <?php endif; ?>
        <div class="presentations-inner-container">
            <?php foreach ($presentation_form_rows as $presentation_index => $presentation): ?>
                <?php
                $presentation_dom_id = $presentation_index + 1;
                [$presentation_time_value, $presentation_ampm] = presentationTimeParts(
                    $presentation['presentation_time'] ?? ''
                );
                $presentation_topic = (string) ($presentation['topic_title'] ?? '');
                $presentation_speaker = (int) ($presentation['speaker_id'] ?? $default_speaker_id);
                $is_saved_presentation = !empty($presentation['id']);
                $show_delete_button = !$is_saved_presentation
                    && (count($presentation_form_rows) > 1 || $presentation_topic !== '');
                ?>
                <div class="presentation-entry<?php echo $is_saved_presentation ? ' is-saved-presentation' : ''; ?>" id="presentation-<?php echo $presentation_dom_id; ?>">
                    <div class="presentation-entry-banner">
                        <h3 class="presentation-entry-heading">Presentation <?php echo $presentation_dom_id; ?></h3>
                        <button type="button" class="button-secondary" data-duplicate-presentation aria-label="Duplicate Presentation <?php echo $presentation_dom_id; ?>">Duplicate Presentation</button>
                    </div>
                    <?php if (!empty($presentation['id'])): ?>
                        <input type="hidden" name="presentations[<?php echo $presentation_dom_id; ?>][id]" value="<?php echo (int) $presentation['id']; ?>">
                    <?php elseif (!empty($presentation['duplicate_from_id'])): ?>
                        <input type="hidden" name="presentations[<?php echo $presentation_dom_id; ?>][duplicate_from_id]" value="<?php echo (int) $presentation['duplicate_from_id']; ?>">
                    <?php endif; ?>
                    <div class="presentation-fields">
                        <div class="presentation-primary-fields">
                            <div class="form-field">
                                <label for="presentation_topic_<?php echo $presentation_dom_id; ?>">Topic/Title</label>
                                <input type="text" name="presentations[<?php echo $presentation_dom_id; ?>][topic_title]" id="presentation_topic_<?php echo $presentation_dom_id; ?>" maxlength="255" value="<?php echo htmlspecialchars($presentation_topic, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field">
                                <label for="speaker_id_<?php echo $presentation_dom_id; ?>">Speaker</label>
                                <select name="presentations[<?php echo $presentation_dom_id; ?>][speaker_id]" id="speaker_id_<?php echo $presentation_dom_id; ?>">
                                    <?php foreach ($speaker_options as $speaker_option): ?>
                                        <option value="<?php echo (int) $speaker_option['id']; ?>"<?php echo $presentation_speaker === (int) $speaker_option['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($speaker_option['name'] . ' — ' . $speaker_option['email'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="presentation-session-fields">
                            <div class="form-field presentation-date-field">
                                <label for="presentation_date_<?php echo $presentation_dom_id; ?>">Date</label>
                                <input type="text" data-date-picker="date" placeholder="YYYY-MM-DD" name="presentations[<?php echo $presentation_dom_id; ?>][presentation_date]" id="presentation_date_<?php echo $presentation_dom_id; ?>" value="<?php echo htmlspecialchars((string) ($presentation['presentation_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field presentation-time-field">
                                <label for="presentation_time_<?php echo $presentation_dom_id; ?>">Time</label>
                                <div class="time-input-container">
                                    <input type="text" name="presentation_time_<?php echo $presentation_dom_id; ?>" id="presentation_time_<?php echo $presentation_dom_id; ?>" inputmode="numeric" pattern="[0-9]{1,2}:[0-9]{2}" placeholder="HH:MM or 1530" value="<?php echo htmlspecialchars($presentation_time_value, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="ampm-radio">
                                        <label><input type="radio" name="presentation_ampm_<?php echo $presentation_dom_id; ?>" value="AM" <?php echo $presentation_ampm === 'AM' ? 'checked' : ''; ?>> AM</label>
                                        <label><input type="radio" name="presentation_ampm_<?php echo $presentation_dom_id; ?>" value="PM" <?php echo $presentation_ampm === 'PM' ? 'checked' : ''; ?>> PM</label>
                                    </div>
                                </div>
                                <input type="hidden" name="presentations[<?php echo $presentation_dom_id; ?>][presentation_time]" id="presentation_time_hidden_<?php echo $presentation_dom_id; ?>" value="<?php echo htmlspecialchars((string) ($presentation['presentation_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field">
                                <label for="duration_minutes_<?php echo $presentation_dom_id; ?>">Duration (Minutes)</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][duration_minutes]" id="duration_minutes_<?php echo $presentation_dom_id; ?>" min="1" max="1440" step="1" value="<?php echo htmlspecialchars((string) (array_key_exists('duration_minutes', $presentation) ? $presentation['duration_minutes'] : ($is_saved_presentation ? '' : 60)), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field">
                                <label for="expected_attendance_<?php echo $presentation_dom_id; ?>">Expected Attendance</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][expected_attendance]" id="expected_attendance_<?php echo $presentation_dom_id; ?>" min="1" step="1" value="<?php echo htmlspecialchars((string) ($presentation['expected_attendance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field">
                                <label for="actual_attendance_<?php echo $presentation_dom_id; ?>">Actual Attendance</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][actual_attendance]" id="actual_attendance_<?php echo $presentation_dom_id; ?>" min="0" step="1" value="<?php echo htmlspecialchars((string) ($presentation['actual_attendance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="presentation-assets">
                            <div class="presentation-assets-heading">
                                <h3>Presentation Files &amp; QR Codes</h3>
                                <p>PDF Speaker Notes may be up to 100 MB. PPT Slidedeck (.ppt or .pptx) may be up to 500 MB. Keep each save under 600 MB total. QR codes are generated when you save.</p>
                            </div>
                            <div class="presentation-upload-cards">
                                <?php include __DIR__ . '/presentation_pdf_upload.php'; ?>
                                <?php include __DIR__ . '/presentation_slidedeck_upload.php'; ?>
                            </div>
                            <?php if ($is_saved_presentation): ?>
                                <?php
                                $short_link_presentation_id = (int) $presentation['id'];
                                $short_link_show_reset_action = false;
                                $short_link_show_link_reset_action = true;
                                include __DIR__ . '/presentation_short_links.php';
                                unset($short_link_show_reset_action, $short_link_show_link_reset_action);
                                ?>
                            <?php else: ?>
                                <p>Save the presentation to download its unique QR codes.</p>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_saved_presentation): ?>
                            <div class="remove-btn-container presentation-management-actions">
                                <?php if (hasRole(['admin'])): ?>
                                    <a class="button-secondary presentation-stats-reset" data-admin-unlock-required href="reset_presentation_stats.php?presentation_id=<?php echo (int) $presentation['id']; ?>&amp;return_to=<?php echo rawurlencode($short_link_reset_return); ?>">Reset All Presentation Statistics</a>
                                <?php endif; ?>
                                <?php if (canArchiveEntries($user_role ?? '')): ?>
                                    <button type="submit" form="archive-presentation-<?php echo (int) $presentation['id']; ?>" class="archive-button">Archive</button>
                                <?php endif; ?>
                                <?php if (canDeleteEntries($user_role ?? '')): ?>
                                    <button type="submit" form="delete-presentation-<?php echo (int) $presentation['id']; ?>" class="delete-button">Delete</button>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($show_delete_button): ?>
                            <div class="remove-btn-container">
                                <button type="button" data-remove-presentation="<?php echo $presentation_dom_id; ?>" class="remove-presentation-btn">Remove</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" data-add-presentation class="add-presentation-btn">Add Presentation</button>
    </fieldset>
</section>
