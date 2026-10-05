<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/short_link_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$conn = applicationDatabaseConnection();
$presentationId = \Dnr\Http\RequestInput::positiveInt($_GET, 'presentation_id');
if ($presentationId === null) {
    http_response_code(400);
    exit('Select a valid presentation.');
}
$stmt = $conn->prepare('SELECT p.topic_title, p.engagement_id, e.event_title, s.name AS speaker_name
    FROM presentations p JOIN engagements e ON e.id = p.engagement_id
    JOIN speakers s ON s.id = p.speaker_id WHERE p.id = ?');
$stmt->bind_param('i', $presentationId);
$stmt->execute();
$presentation = $stmt->get_result()->fetch_assoc();
if (!$presentation) {
    http_response_code(404);
    exit('Presentation not found.');
}
$linkId = array_key_exists('link_id', $_GET) ? \Dnr\Http\RequestInput::positiveInt($_GET, 'link_id') : null;
$link = null;
if (array_key_exists('link_id', $_GET)) {
    if ($linkId === null) {
        http_response_code(400);
        exit('Select a valid link.');
    }
    $link = $conn->execute_query('SELECT * FROM short_links WHERE id = ? AND presentation_id = ?', [$linkId, $presentationId])->fetch_assoc();
    if (!$link) {
        http_response_code(404);
        exit('Link not found in this presentation.');
    }
}
$resetTitle = $linkId === null ? 'Reset All Presentation Statistics' : 'Reset Link Statistics';
$resetUrl = 'reset_presentation_stats.php?presentation_id=' . $presentationId;
if ($linkId !== null) $resetUrl .= '&link_id=' . $linkId;
$backUrl = 'view_engagement.php?id=' . (int) $presentation['engagement_id'] . '#engagement-presentations';
$returnUrl = safeRecordReturnUrl($_GET['return_to'] ?? null, '');
if ($returnUrl !== '') {
    $backUrl = $returnUrl;
    $resetUrl = recordUrlWithQuery($resetUrl, ['return_to' => $returnUrl]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
}
requireRecentAdminElevation($resetUrl);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (\Dnr\Http\RequestInput::string($_POST, 'action') !== 'reset_statistics') {
            throw new InvalidArgumentException('Select a valid reset action.');
        }
        resetPresentationShortLinkStats($conn, $presentationId, (int) $_SESSION['user_id'], $linkId);
        if ($linkId === null) $_SESSION['_presentation_stats_reset'] = $presentationId;
        else $_SESSION['_link_stats_reset'] = $linkId;
        if ($returnUrl !== '') {
            $message = $linkId === null ? 'All presentation statistics reset to zero.' : 'Link statistics reset to zero.';
            if (parse_url($returnUrl, PHP_URL_PATH) === 'edit_engagement.php') $_SESSION['presentation_action_message'] = $message;
            else $_SESSION['engagement_action_message'] = $message;
        }
        header('Location: ' . ($returnUrl !== '' ? $returnUrl : 'short_links.php?' . ($linkId === null ? 'presentation_id=' . $presentationId : 'id=' . $linkId)));
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage() : 'Unable to reset these statistics. Try again.';
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Presentation statistics reset failed', ['presentation_id' => $presentationId, 'error' => $exception->getMessage()]);
        }
    }
}
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle($resetTitle), [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css'],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container security-container">
    <h1><?php echo $h($resetTitle); ?></h1>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo $h($error); ?></p><?php endif; ?>
    <section class="security-card">
        <h2><?php echo $h($presentation['topic_title'] ?: 'Untitled Presentation'); ?></h2>
        <p><?php echo $h($presentation['event_title']); ?> · <?php echo $h($presentation['speaker_name']); ?></p>
        <?php if ($link !== null): ?>
        <h3><?php echo $h(shortLinkLabel($link)); ?></h3>
        <p>This permanently clears all recorded visits for this link across every date. This cannot be undone. Other links are unaffected.</p>
        <?php else: ?>
        <p>This permanently clears all recorded QR and link visits for this presentation, across every date, including disabled links and links for previous speakers. This cannot be undone.</p>
        <?php endif; ?>
        <p>QR codes, destinations, uploaded notes, and presentation details stay the same. New visits start counting from zero. Other presentations are unaffected.</p>
        <form method="post" action="<?php echo $h($resetUrl); ?>" class="security-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="reset_statistics">
            <a href="<?php echo $h($backUrl); ?>" class="button-secondary">Cancel</a>
            <button type="submit" class="danger-button presentation-stats-reset">Reset Statistics to Zero</button>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
