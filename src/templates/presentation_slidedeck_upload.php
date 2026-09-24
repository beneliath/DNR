<?php
$deck_key = 'ppt_slidedeck';
$deck_label = 'PPT Slidedeck';
$deck_input_id = $deck_key . '_' . $presentation_dom_id;
$has_deck = $is_saved_presentation && !empty($presentation['has_' . $deck_key]);
?>
<div class="presentation-notes-card">
    <div class="presentation-upload-details">
    <div class="presentation-asset-label"><?php echo $deck_label; ?></div>
    <p>Anyone with the PPT Slidedeck QR code can download this PowerPoint file without signing in.</p>
    <?php if ($has_deck): ?>
        <?php
        $deck_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=slidedeck';
        $deck_filename = (string) ($presentation[$deck_key . '_filename'] ?? 'slidedeck.pptx');
        $deck_uploaded_at = $presentation[$deck_key . '_updated_at'] ?? null;
        $deck_uploaded_by = trim((string) ($presentation[$deck_key . '_uploaded_by_username'] ?? ''));
        ?>
        <div class="presentation-existing-asset">
            <a href="<?php echo htmlspecialchars($deck_url, ENT_QUOTES, 'UTF-8'); ?>" class="presentation-pdf-link" target="_blank" rel="noopener">
                View <?php echo htmlspecialchars($deck_filename, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php if (!empty($deck_uploaded_at)): ?>
                <span class="presentation-upload-timestamp">Uploaded <?php echo htmlspecialchars(applicationTimestampLabel($deck_uploaded_at, 'M j, Y g:i A T'), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <span class="presentation-upload-user"><?php echo $deck_uploaded_by !== '' ? 'By ' . htmlspecialchars($deck_uploaded_by, ENT_QUOTES, 'UTF-8') : 'Uploader unknown'; ?></span>
            <?php if (!empty($presentation[$deck_key . '_size'])): ?>
                <span><?php echo htmlspecialchars(number_format(((int) $presentation[$deck_key . '_size']) / 1048576, 1)); ?> MB</span>
            <?php endif; ?>
            <label class="presentation-asset-remove">
                <input type="checkbox" name="presentations[<?php echo $presentation_dom_id; ?>][remove_<?php echo $deck_key; ?>]" value="1" aria-describedby="<?php echo $deck_input_id; ?>_save_notice">
                Remove current PPT
            </label>
        </div>
        <p id="<?php echo $deck_input_id; ?>_save_notice" class="presentation-file-save-notice" data-file-save-notice data-file-type="PPT" role="status" hidden></p>
    <?php endif; ?>
    <div class="presentation-pdf-picker-row">
    <label class="presentation-file-picker" for="<?php echo $deck_input_id; ?>">
        <?php echo $has_deck ? 'Replace PPT' : 'Choose PPT'; ?>
    </label>
    <input type="file"
           class="presentation-native-file"
           name="presentations[<?php echo $presentation_dom_id; ?>][<?php echo $deck_key; ?>]"
           id="<?php echo $deck_input_id; ?>"
           accept=".ppt,.pptx,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation"
           <?php if ($has_deck): ?>aria-describedby="<?php echo $deck_input_id; ?>_save_notice"<?php endif; ?>
           data-presentation-file-name>
    <span class="presentation-selected-file" data-selected-file-name data-empty-file-label="<?php echo $has_deck ? 'No replacement selected' : 'No PowerPoint selected'; ?>"><?php echo $has_deck ? 'No replacement selected' : 'No PowerPoint selected'; ?></span>
    </div>
    </div>
    <div class="presentation-file-drop" data-file-drop>
        <button type="button" class="presentation-file-drop-button" data-file-drop-button>
            <svg class="presentation-drop-icon" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
            <strong>Drop PowerPoint here</strong>
            <span>or click to choose · .ppt or .pptx · up to 500 MB</span>
            <span>Select Save Changes to upload or replace.</span>
        </button>
        <span class="presentation-drop-status" data-file-drop-status role="status" aria-live="polite"></span>
    </div>
</div>
