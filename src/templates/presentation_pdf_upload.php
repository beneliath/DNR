<?php
$pdf_key = 'speaker_notes';
$pdf_label = 'PDF Speaker Notes';
$pdf_input_id = $pdf_key . '_' . $presentation_dom_id;
$has_pdf = $is_saved_presentation && !empty($presentation['has_' . $pdf_key]);
?>
<div class="presentation-notes-card">
    <div class="presentation-asset-label"><?php echo $pdf_label; ?></div>
    <p>Anyone with the Speaker Notes QR code can open this PDF without signing in.</p>
    <?php if ($has_pdf): ?>
        <?php
        $pdf_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=notes';
        $pdf_filename = (string) ($presentation[$pdf_key . '_filename'] ?? 'speaker-notes.pdf');
        ?>
        <div class="presentation-existing-asset">
            <a href="<?php echo htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8'); ?>" class="presentation-pdf-link" target="_blank" rel="noopener">
                View <?php echo htmlspecialchars($pdf_filename, ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <?php if (!empty($presentation[$pdf_key . '_size'])): ?>
                <span><?php echo htmlspecialchars(number_format(((int) $presentation[$pdf_key . '_size']) / 1048576, 1)); ?> MB</span>
            <?php endif; ?>
            <label class="presentation-asset-remove">
                <input type="checkbox" name="presentations[<?php echo $presentation_dom_id; ?>][remove_<?php echo $pdf_key; ?>]" value="1">
                Remove current PDF
            </label>
        </div>
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
           data-presentation-file-name>
    <span class="presentation-selected-file" data-selected-file-name data-empty-file-label="<?php echo $has_pdf ? 'No replacement selected' : 'No PDF selected'; ?>"><?php echo $has_pdf ? 'No replacement selected' : 'No PDF selected'; ?></span>
    </div>
</div>
