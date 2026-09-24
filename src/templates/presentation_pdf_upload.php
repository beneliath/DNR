<?php
$pdf_key = 'speaker_notes';
$pdf_label = 'PDF Speaker Notes';
$pdf_input_id = $pdf_key . '_' . $presentation_dom_id;
$has_pdf = $is_saved_presentation && !empty($presentation['has_' . $pdf_key]);
?>
<div class="presentation-notes-card">
    <div class="presentation-upload-details">
    <div class="presentation-asset-label"><?php echo $pdf_label; ?></div>
    <p>Anyone with the Speaker Notes QR code can open this PDF without signing in.</p>
    <?php if ($has_pdf): ?>
        <?php
        $pdf_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=notes';
        $pdf_filename = (string) ($presentation[$pdf_key . '_filename'] ?? 'speaker-notes.pdf');
        $pdf_uploaded_at = $presentation[$pdf_key . '_updated_at'] ?? null;
        $pdf_uploaded_by = trim((string) ($presentation[$pdf_key . '_uploaded_by_username'] ?? ''));
        ?>
        <div class="presentation-existing-asset">
            <a href="<?php echo htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8'); ?>" class="presentation-pdf-link" target="_blank" rel="noopener">
                View <?php echo htmlspecialchars($pdf_filename, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php if (!empty($pdf_uploaded_at)): ?>
                <span class="presentation-upload-timestamp">Uploaded <?php echo htmlspecialchars(applicationTimestampLabel($pdf_uploaded_at, 'M j, Y g:i A T'), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <span class="presentation-upload-user"><?php echo $pdf_uploaded_by !== '' ? 'By ' . htmlspecialchars($pdf_uploaded_by, ENT_QUOTES, 'UTF-8') : 'Uploader unknown'; ?></span>
            <?php if (!empty($presentation[$pdf_key . '_size'])): ?>
                <span><?php echo htmlspecialchars(number_format(((int) $presentation[$pdf_key . '_size']) / 1048576, 1)); ?> MB</span>
            <?php endif; ?>
            <label class="presentation-asset-remove">
                <input type="checkbox" name="presentations[<?php echo $presentation_dom_id; ?>][remove_<?php echo $pdf_key; ?>]" value="1" aria-describedby="<?php echo $pdf_input_id; ?>_save_notice">
                Remove current PDF
            </label>
        </div>
        <p id="<?php echo $pdf_input_id; ?>_save_notice" class="presentation-file-save-notice" data-file-save-notice data-file-type="PDF" role="status" hidden></p>
    <?php endif; ?>
    <div class="presentation-pdf-picker-row">
    <label class="presentation-file-picker" for="<?php echo $pdf_input_id; ?>">
        <?php echo $has_pdf ? 'Replace PDF' : 'Choose PDF'; ?>
    </label>
    <input type="file"
           class="presentation-native-file"
           name="presentations[<?php echo $presentation_dom_id; ?>][<?php echo $pdf_key; ?>]"
           id="<?php echo $pdf_input_id; ?>"
           accept="application/pdf,.pdf"
           <?php if ($has_pdf): ?>aria-describedby="<?php echo $pdf_input_id; ?>_save_notice"<?php endif; ?>
           data-presentation-file-name>
    <span class="presentation-selected-file" data-selected-file-name data-empty-file-label="<?php echo $has_pdf ? 'No replacement selected' : 'No PDF selected'; ?>"><?php echo $has_pdf ? 'No replacement selected' : 'No PDF selected'; ?></span>
    </div>
    </div>
    <div class="presentation-file-drop" data-file-drop>
        <button type="button" class="presentation-file-drop-button" data-file-drop-button>
            <svg class="presentation-drop-icon" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
            <strong>Drop PDF here</strong>
            <span>or click to choose · .pdf · up to 100 MB</span>
            <span>Select Save Changes to upload or replace.</span>
        </button>
        <span class="presentation-drop-status" data-file-drop-status role="status" aria-live="polite"></span>
    </div>
</div>
