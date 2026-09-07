<?php $presentation_export = buildPresentationExport($engagement, $presentation); ?>
<details class="presentation-export">
<summary>Export Presentation</summary>
<div class="presentation-export-actions export-actions" data-presentation-export role="group" aria-label="Export <?php echo htmlspecialchars($presentation_export['title'], ENT_QUOTES, 'UTF-8'); ?>">
    <button type="button" class="action-button export-button" data-copy-format="text">Copy Text</button>
    <button type="button" class="action-button export-button" data-copy-format="markdown">Copy MD</button>
    <a href="presentation_pdf_view.php?presentation_id=<?php echo (int) $presentation['id']; ?>" class="action-button export-button" target="_blank" rel="noopener">View PDF</a>
    <a href="presentation_qr_pdf_view.php?presentation_id=<?php echo (int) $presentation['id']; ?>" class="action-button export-button" target="_blank" rel="noopener">View QR Codes PDF</a>
    <span class="visually-hidden" data-presentation-copy-status role="status" aria-live="polite"></span>
    <script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" data-presentation-export-data><?php echo json_encode([
        'text' => renderEngagementPlainText($presentation_export),
        'markdown' => renderEngagementMarkdown($presentation_export),
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
</div>
</details>
