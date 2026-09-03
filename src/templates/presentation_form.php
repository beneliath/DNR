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
         data-default-speaker="<?php echo htmlspecialchars($DEFAULT_SPEAKER, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="chron-log-heading">
        <h2>Presentation(s)</h2>
        <?php if (!empty($archived_presentation_count) && !empty($engagement_id)): ?>
            <a href="restore_presentations.php?engagement_id=<?php echo (int) $engagement_id; ?>" class="restore-button">Restore Archived Presentations (<?php echo (int) $archived_presentation_count; ?>)</a>
        <?php endif; ?>
    </div>
    <?php if (!empty($presentation_action_message)): ?>
        <div class="success"><?php echo htmlspecialchars($presentation_action_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($presentation_action_error)): ?>
        <div class="error"><?php echo htmlspecialchars($presentation_action_error); ?></div>
    <?php endif; ?>
    <p class="field-help">Topic/title, date, time, and duration are required for each presentation. Actual attendance can be recorded after the event. Add at least one presentation before setting the engagement status to confirmed.</p>
    <div class="presentations-outer-box<?php echo $has_saved_presentations ? ' has-saved-presentations' : ''; ?>">
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
                <div class="presentation-entry<?php echo $is_saved_presentation ? ' is-saved-presentation' : ''; ?>" id="presentation-<?php echo $presentation_dom_id; ?>">
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
                                <label for="duration_minutes_<?php echo $presentation_dom_id; ?>">Duration (minutes)<span class="required">*</span></label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][duration_minutes]" id="duration_minutes_<?php echo $presentation_dom_id; ?>" min="1" max="1440" step="1" value="<?php echo htmlspecialchars((string) ($presentation['duration_minutes'] ?? 60), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="attendance-row">
                            <div class="form-field attendance">
                                <label for="expected_attendance_<?php echo $presentation_dom_id; ?>">Expected Attendance</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][expected_attendance]" id="expected_attendance_<?php echo $presentation_dom_id; ?>" min="1" step="1" value="<?php echo htmlspecialchars((string) ($presentation['expected_attendance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-field attendance">
                                <label for="actual_attendance_<?php echo $presentation_dom_id; ?>">Actual Attendance</label>
                                <input type="number" name="presentations[<?php echo $presentation_dom_id; ?>][actual_attendance]" id="actual_attendance_<?php echo $presentation_dom_id; ?>" min="0" step="1" value="<?php echo htmlspecialchars((string) ($presentation['actual_attendance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="presentation-assets">
                            <div class="presentation-assets-heading">
                                <h3>Presentation Files &amp; QR Codes</h3>
                                <p>PDF slide decks may be up to 100 MB. QR codes may be pasted or selected as JPEG, PNG, or WebP images.</p>
                            </div>
                            <div class="presentation-slide-deck-card">
                                <div class="presentation-asset-label">PDF Slide Deck</div>
                                <?php if (!empty($presentation['has_slide_deck']) && $is_saved_presentation): ?>
                                    <?php
                                    $slide_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=slides';
                                    $slide_filename = (string) ($presentation['slide_deck_filename'] ?? 'slide-deck.pdf');
                                    ?>
                                    <div class="presentation-existing-asset">
                                        <a href="<?php echo htmlspecialchars($slide_url, ENT_QUOTES, 'UTF-8'); ?>" class="presentation-pdf-link">
                                            Download <?php echo htmlspecialchars($slide_filename, ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                        <?php if (!empty($presentation['slide_deck_size'])): ?>
                                            <span><?php echo htmlspecialchars(number_format(((int) $presentation['slide_deck_size']) / 1048576, 1)); ?> MB</span>
                                        <?php endif; ?>
                                        <label class="presentation-asset-remove">
                                            <input type="checkbox" name="presentations[<?php echo $presentation_dom_id; ?>][remove_slide_deck]" value="1">
                                            Remove current PDF
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <label class="presentation-file-picker" for="slide_deck_<?php echo $presentation_dom_id; ?>">
                                    <?php echo !empty($presentation['has_slide_deck']) ? 'Replace PDF' : 'Choose PDF'; ?>
                                </label>
                                <input type="file"
                                       class="presentation-native-file"
                                       name="presentations[<?php echo $presentation_dom_id; ?>][slide_deck]"
                                       id="slide_deck_<?php echo $presentation_dom_id; ?>"
                                       accept="application/pdf,.pdf"
                                       data-presentation-file-name>
                                <span class="presentation-selected-file" data-selected-file-name>No PDF selected</span>
                            </div>
                            <div class="presentation-qr-grid">
                                <?php
                                $presentation_qr_fields = [
                                    'speaker_notes_qr' => [
                                        'label' => 'Speaker Notes QR Code',
                                        'description' => 'Links attendees to the speaker notes download.',
                                        'query_type' => 'notes_qr',
                                        'has_key' => 'has_speaker_notes_qr',
                                    ],
                                    'speaker_website_qr' => [
                                        'label' => 'Speaker Website QR Code',
                                        'description' => 'Links attendees to the speaker website.',
                                        'query_type' => 'website_qr',
                                        'has_key' => 'has_speaker_website_qr',
                                    ],
                                    'speaker_donation_qr' => [
                                        'label' => 'Speaker Donation QR Code',
                                        'description' => 'Links attendees to the speaker donation page.',
                                        'query_type' => 'donation_qr',
                                        'has_key' => 'has_speaker_donation_qr',
                                    ],
                                ];
                                ?>
                                <?php foreach ($presentation_qr_fields as $qr_field => $qr_configuration): ?>
                                    <?php
                                    $has_qr = !empty($presentation[$qr_configuration['has_key']]) && $is_saved_presentation;
                                    $qr_url = $has_qr
                                        ? 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=' . $qr_configuration['query_type']
                                        : '';
                                    $qr_input_id = $qr_field . '_' . $presentation_dom_id;
                                    $qr_status_id = $qr_input_id . '_status';
                                    ?>
                                    <div class="presentation-qr-card" data-qr-uploader>
                                        <div class="presentation-asset-label"><?php echo htmlspecialchars($qr_configuration['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <p><?php echo htmlspecialchars($qr_configuration['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <button type="button"
                                                class="presentation-qr-preview"
                                                data-qr-preview-button
                                                data-copy-qr-url="<?php echo htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                aria-label="Copy <?php echo htmlspecialchars($qr_configuration['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                <?php echo $has_qr ? '' : 'hidden'; ?>>
                                            <img data-qr-preview
                                                 <?php if ($has_qr): ?>src="<?php echo htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>
                                                 alt="<?php echo htmlspecialchars($qr_configuration['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <span>Click QR code to copy</span>
                                        </button>
                                        <div class="presentation-qr-actions">
                                            <button type="button"
                                                    class="presentation-paste-button"
                                                    data-paste-qr
                                                    aria-describedby="<?php echo htmlspecialchars($qr_status_id, ENT_QUOTES, 'UTF-8'); ?>">Paste QR code</button>
                                            <label class="presentation-file-picker" for="<?php echo htmlspecialchars($qr_input_id, ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo $has_qr ? 'Replace image' : 'Choose image'; ?>
                                            </label>
                                        </div>
                                        <input type="file"
                                               class="presentation-native-file"
                                               name="presentations[<?php echo $presentation_dom_id; ?>][<?php echo htmlspecialchars($qr_field, ENT_QUOTES, 'UTF-8'); ?>]"
                                               id="<?php echo htmlspecialchars($qr_input_id, ENT_QUOTES, 'UTF-8'); ?>"
                                               accept="image/jpeg,image/png,image/webp"
                                               data-qr-file>
                                        <?php if ($has_qr): ?>
                                            <label class="presentation-asset-remove">
                                                <input type="checkbox" name="presentations[<?php echo $presentation_dom_id; ?>][remove_<?php echo htmlspecialchars($qr_field, ENT_QUOTES, 'UTF-8'); ?>]" value="1">
                                                Remove current QR code
                                            </label>
                                        <?php endif; ?>
                                        <span class="presentation-qr-status"
                                              id="<?php echo htmlspecialchars($qr_status_id, ENT_QUOTES, 'UTF-8'); ?>"
                                              data-copy-status
                                              role="status"
                                              aria-live="polite"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($is_saved_presentation): ?>
                            <div class="remove-btn-container presentation-management-actions">
                                <?php if (!empty($engagement_id)): ?>
                                    <button type="submit"
                                            name="save_engagement"
                                            value="1"
                                            class="save-button presentation-pane-save-button"
                                            form="engagement-edit-form">Save Changes</button>
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
    </div>
</section>
