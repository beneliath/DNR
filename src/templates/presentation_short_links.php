<?php
require_once dirname(__DIR__) . '/short_link_helpers.php';
$short_links = fetchPresentationShortLinks($conn, $short_link_presentation_id);
$short_links = array_values(array_filter($short_links, static fn(array $link): bool =>
    (int) $link['speaker_id'] === (int) $link['current_speaker_id']
    && ($link['link_type'] !== 'notes' || (bool) $link['has_notes'])
    && ($link['link_type'] !== 'slidedeck' || (bool) $link['has_slidedeck'])
));
?>
<div class="presentation-generated-links">
    <?php if (($short_link_show_pdf_action ?? true) || ($short_link_show_stats_action ?? true)): ?>
    <div class="presentation-qr-actions">
        <?php if ($short_link_show_pdf_action ?? true): ?>
            <a class="button-secondary" href="presentation_qr_pdf_view.php?presentation_id=<?php echo (int) $short_link_presentation_id; ?>" data-qr-pdf-presentation-id="<?php echo (int) $short_link_presentation_id; ?>" aria-haspopup="dialog" aria-controls="presentation-qr-pdf-dialog" target="_blank" rel="noopener">View QR Codes PDF</a>
        <?php endif; ?>
        <?php if ($short_link_show_stats_action ?? true): ?>
            <a class="button-secondary presentation-combined-stats" href="short_links.php?presentation_id=<?php echo (int) $short_link_presentation_id; ?>">Combined Presentation Statistics</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <template id="presentation-qr-pdf-options-<?php echo (int) $short_link_presentation_id; ?>" data-presentation-title="<?php echo htmlspecialchars(trim((string) ($presentation['topic_title'] ?? '')) ?: 'Untitled presentation', ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($short_links as $short_link): ?>
            <?php $label = shortLinkLabel($short_link); $qr_available = !empty($short_link['qr_png']) && !empty($short_link['qr_url']); ?>
            <div class="qr-pdf-option" data-qr-pdf-option>
                <label class="qr-pdf-option-choice">
                <input type="checkbox" name="link_ids[]" value="<?php echo (int) $short_link['id']; ?>" <?php echo $qr_available ? 'checked' : 'disabled'; ?>>
                <?php if ($qr_available): ?>
                    <img src="short_link_qr.php?id=<?php echo (int) $short_link['id']; ?>" alt="" width="64" height="64" loading="lazy">
                <?php endif; ?>
                <span class="qr-pdf-option-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?><?php if (!$short_link['is_enabled']): ?><small>Disabled link</small><?php endif; ?><?php if (!$qr_available): ?><small>QR image awaiting setup</small><?php endif; ?></span>
                </label>
                <button type="button" class="button-secondary qr-pdf-drag-handle" data-qr-pdf-drag aria-label="Reorder <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="qr-pdf-order-help">
                    <span data-qr-pdf-position aria-hidden="true"></span>
                    <svg width="16" height="20" viewBox="0 0 16 20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="4" r="1.5"/><circle cx="11" cy="4" r="1.5"/><circle cx="5" cy="10" r="1.5"/><circle cx="11" cy="10" r="1.5"/><circle cx="5" cy="16" r="1.5"/><circle cx="11" cy="16" r="1.5"/></svg>
                </button>
            </div>
        <?php endforeach; ?>
    </template>
    <div class="presentation-view-qr-grid">
        <?php foreach ($short_links as $short_link): ?>
            <?php $qr_url = 'short_link_qr.php?id=' . (int) $short_link['id']; $label = shortLinkLabel($short_link); ?>
            <div class="presentation-qr-display">
                <div class="presentation-view-asset-label"><?php echo htmlspecialchars($label); ?><?php echo $short_link['is_enabled'] ? '' : ' (Disabled)'; ?></div>
                <?php if (!empty($short_link['qr_png'])): ?>
                <button type="button" class="presentation-view-qr-button" data-copy-qr-url="<?php echo $qr_url; ?>" aria-label="Copy <?php echo htmlspecialchars($label); ?> QR code">
                    <img src="data:image/png;base64,<?php echo base64_encode($short_link['qr_png']); ?>" alt="<?php echo htmlspecialchars($label); ?> QR code" width="160" height="160">
                    <span>Click to copy</span>
                </button>
                <span class="presentation-qr-status" data-copy-status role="status" aria-live="polite"></span>
                <?php if (!empty($short_link['qr_url'])): ?>
                <button type="button" class="button-secondary presentation-copy-link" data-copy-qr-link="<?php echo htmlspecialchars($short_link['qr_url'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Copy <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> QR link" title="Copy link">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg>
                    <span>Copy link</span>
                </button>
                <?php endif; ?>
                <div><a href="<?php echo $qr_url; ?>&amp;format=png&amp;download=1">PNG</a> · <a href="<?php echo $qr_url; ?>&amp;format=svg&amp;download=1">SVG</a></div>
                <?php else: ?><p>QR images awaiting setup</p><?php endif; ?>
                <a class="button-secondary" href="short_links.php?id=<?php echo (int) $short_link['id']; ?>" aria-label="<?php echo htmlspecialchars($label); ?> QR Code Statistics">Statistics</a>
            </div>
        <?php endforeach; ?>
    </div>
    <p>Each code is unique to this presentation. Select Combined Presentation Statistics for activity across all its codes, or Statistics on a code to review its visits and manage its destination. Speaker Notes and PPT Slidedeck codes are added after their files are uploaded. Use Copy link to copy the URL encoded in a QR code.</p>
    <?php if (($short_link_show_reset_action ?? true) && hasRole(['admin'])): ?>
        <p><a class="button-secondary presentation-stats-reset" href="reset_presentation_stats.php?presentation_id=<?php echo (int) $short_link_presentation_id; ?>">Reset Presentation Statistics</a></p>
    <?php endif; ?>
</div>
