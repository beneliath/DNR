<?php
require_once dirname(__DIR__) . '/short_link_helpers.php';
$short_links = fetchPresentationShortLinks($conn, $short_link_presentation_id);
?>
<div class="presentation-generated-links">
    <?php if ($short_link_show_pdf_action ?? true): ?>
        <p><a class="button-secondary" href="presentation_qr_pdf_view.php?presentation_id=<?php echo (int) $short_link_presentation_id; ?>" target="_blank" rel="noopener">View QR Codes PDF</a></p>
    <?php endif; ?>
    <div class="presentation-view-qr-grid">
        <?php foreach ($short_links as $short_link): ?>
            <?php if ((int) $short_link['speaker_id'] !== (int) $short_link['current_speaker_id']) continue; ?>
            <?php if ($short_link['link_type'] === 'notes' && !$short_link['has_notes']) continue; ?>
            <?php $qr_url = 'short_link_qr.php?id=' . (int) $short_link['id']; $label = shortLinkLabel($short_link); ?>
            <div class="presentation-qr-display">
                <div class="presentation-view-asset-label"><?php echo htmlspecialchars($label); ?><?php echo $short_link['is_enabled'] ? '' : ' (Disabled)'; ?></div>
                <?php if (!empty($short_link['qr_png'])): ?>
                <button type="button" class="presentation-view-qr-button" data-copy-qr-url="<?php echo $qr_url; ?>" aria-label="Copy <?php echo htmlspecialchars($label); ?> QR code">
                    <img src="data:image/png;base64,<?php echo base64_encode($short_link['qr_png']); ?>" alt="<?php echo htmlspecialchars($label); ?> QR code" width="160" height="160">
                    <span>Click to copy</span>
                </button>
                <span class="presentation-qr-status" data-copy-status role="status" aria-live="polite"></span>
                <div><a href="<?php echo $qr_url; ?>&amp;format=png&amp;download=1">PNG</a> · <a href="<?php echo $qr_url; ?>&amp;format=svg&amp;download=1">SVG</a></div>
                <?php else: ?><p>QR images awaiting setup</p><?php endif; ?>
                <a class="button-secondary" href="short_links.php?id=<?php echo (int) $short_link['id']; ?>" aria-label="<?php echo htmlspecialchars($label); ?> QR Code Statistics">Statistics</a>
            </div>
        <?php endforeach; ?>
    </div>
    <p>Each code is unique to this presentation. Select Statistics on a code to review its visits and manage its destination. A Speaker Notes code is added after a PDF is uploaded.</p>
</div>
