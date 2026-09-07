<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/speaker_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
$speaker_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$speaker = $speaker_id === null ? null : fetchSpeaker($conn, $speaker_id);
if (!$speaker) {
    http_response_code(404);
    exit('Speaker not found.');
}
$return_to = safeRecordReturnUrl($_GET['return_to'] ?? null, 'speakers.php');
$record_view_url = recordUrlWithQuery('view_speaker.php?id=' . $speaker_id, ['return_to' => $return_to]);
$bio = trim((string) ($speaker['bio'] ?? ''));
$message = (string) ($_SESSION['speaker_message'] ?? '');
unset($_SESSION['speaker_message']);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('View Speaker'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/record_workspace.min.css', 'assets/css/pages/view_contact.min.css', 'assets/css/pages/speakers.min.css']]); ?>
<body class="view-contact-body">
<?php include 'templates/header.php'; ?>
<div class="container view-contact-page" role="main">
    <?php if ($message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(recordReturnLabel($return_to), ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span><span>Speaker Details</span></nav>
    <div class="page-heading record-page-heading view-contact-heading">
        <div><h1><?php echo htmlspecialchars($speaker['name'], ENT_QUOTES, 'UTF-8'); ?></h1><p class="page-intro">Speaker</p></div>
        <?php if (hasRole(['admin', 'editor'])): ?>
            <a class="button-secondary" href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_speaker.php?id=' . $speaker_id, ['return_to' => $record_view_url]), ENT_QUOTES, 'UTF-8'); ?>">Edit Speaker</a>
        <?php endif; ?>
    </div>
    <div class="contact-overview-grid">
        <div class="contact-details contact-details-layout">
            <div class="contact-details-photo"><img src="speaker_photo.php?id=<?php echo (int) $speaker['id']; ?>&amp;size=full&amp;v=<?php echo (int) $speaker['version']; ?>" alt="Speaker photo for <?php echo htmlspecialchars($speaker['name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
            <div>
                <div class="detail-row">
                    <strong>Email</strong>
                    <a href="mailto:<?php echo htmlspecialchars($speaker['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($speaker['email'], ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
                <div class="detail-row">
                    <strong>Phone</strong>
                    <a href="tel:<?php echo htmlspecialchars($speaker['phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(formatPhoneNumberForDisplay($speaker['phone']), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
        <section class="contact-details contact-notes-panel" aria-labelledby="speaker-bio-heading">
            <h2 id="speaker-bio-heading">Bio</h2>
            <div class="contact-notes-content"><?php echo $bio !== '' ? renderTextWithLinks($bio) : 'No bio'; ?></div>
        </section>
    </div>
    <section class="contact-details contact-notes-panel" aria-labelledby="speaker-links-heading">
        <h2 id="speaker-links-heading">Links</h2>
        <div class="speaker-links-grid">
            <?php foreach (SPEAKER_URL_FIELDS as $field => $label): ?>
                <?php $url = normalizedHttpUrl($speaker[$field] ?? ''); ?>
                <div class="detail-row">
                    <strong><?php echo $label; ?></strong>
                    <?php if ($url): ?>
                        <a href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: ?>
                        Not specified
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <div class="action-buttons">
        <a class="action-button back-button" href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">Back to <?php echo htmlspecialchars(recordReturnLabel($return_to), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
