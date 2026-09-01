<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mattermost_integration_helpers.php';
startSecureSession();
requireLogin();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$userId = (int) $_SESSION['user_id'];
$configured = mattermostIntegrationConfigured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if (!$configured) {
            throw new RuntimeException('Mattermost linking is not configured by the administrator.');
        }
        if ($action === 'create_code') {
            $_SESSION['_new_mattermost_link_code'] = generateMattermostLinkCode($conn, $userId);
        } elseif ($action === 'revoke') {
            $linkId = filter_input(INPUT_POST, 'link_id', FILTER_VALIDATE_INT);
            if (!$linkId || !revokeMattermostLink($conn, $userId, (int) $linkId)) {
                throw new RuntimeException('That Mattermost account link was already removed or could not be found.');
            }
            $_SESSION['_mattermost_message'] = 'Mattermost account link removed.';
        } else {
            throw new InvalidArgumentException('Select a valid Mattermost account action.');
        }
    } catch (Throwable $exception) {
        applicationLog('error', 'Mattermost account action failed', [
            'user_id' => $userId,
            'action' => $action,
            'error' => $exception->getMessage(),
        ]);
        $_SESSION['_mattermost_error'] = $exception instanceof InvalidArgumentException
            || str_contains($exception->getMessage(), 'not configured')
            ? $exception->getMessage()
            : 'The Mattermost account action could not be completed.';
    }
    header('Location: mattermost.php');
    exit;
}

$newCode = $_SESSION['_new_mattermost_link_code'] ?? null;
$message = (string) ($_SESSION['_mattermost_message'] ?? '');
$error = (string) ($_SESSION['_mattermost_error'] ?? '');
unset(
    $_SESSION['_new_mattermost_link_code'],
    $_SESSION['_mattermost_message'],
    $_SESSION['_mattermost_error']
);

try {
    $links = mattermostLinksForUser($conn, $userId);
} catch (Throwable $exception) {
    applicationLog('error', 'Mattermost account links are unavailable', [
        'user_id' => $userId,
        'error' => $exception->getMessage(),
    ]);
    $links = [];
    $error = 'Mattermost account linking is being upgraded. Ask an administrator to run the latest database migration.';
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Mattermost'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/mattermost.min.css?rev=linked-columns-1',
    ],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <div class="page-heading">
        <div>
            <h1>Mattermost</h1>
            <p class="page-intro">Link your Mattermost identity to use MOED summaries, engagement cards, and follow-up actions in chat.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?><p class="success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php if (!$configured): ?>
        <section class="security-card">
            <h2>Integration Not Configured</h2>
            <p>An administrator must install the Mattermost plugin and configure the matching MOED service secret before accounts can be linked.</p>
        </section>
    <?php else: ?>
        <?php if (is_array($newCode)): ?>
            <section class="security-card" aria-labelledby="mattermost-new-code-title">
                <h2 id="mattermost-new-code-title">Use This Code Now</h2>
                <p>This one-time code expires in 10 minutes and is shown only once. In Mattermost, enter:</p>
                <p><code>/moed connect <?php echo htmlspecialchars((string) $newCode['code'], ENT_QUOTES, 'UTF-8'); ?></code></p>
                <p>Do not send the code in a channel or share it with another person.</p>
            </section>
        <?php endif; ?>

        <section class="security-card" aria-labelledby="mattermost-link-title">
            <h2 id="mattermost-link-title">Link an Account</h2>
            <ol>
                <li>Generate a short-lived, one-time code below.</li>
                <li>Open any Mattermost channel or direct message.</li>
                <li>Run <code>/moed connect CODE</code>. Slash-command responses are visible only to you.</li>
            </ol>
            <form method="post" action="mattermost.php" class="security-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="create_code">
                <button type="submit" class="security-button">Generate One-Time Code</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="security-card" aria-labelledby="mattermost-links-title">
        <h2 id="mattermost-links-title">Linked Mattermost Accounts</h2>
        <?php if ($links === []): ?>
            <p>No Mattermost account is linked.</p>
        <?php else: ?>
            <div class="responsive-table-wrapper mattermost-links-table-wrapper">
                <table class="mattermost-links-table">
                    <thead><tr><th>Account</th><th>Instance</th><th>Linked</th><th>Last Used</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($links as $link): ?>
                        <tr>
                            <td>@<?php echo htmlspecialchars((string) $link['mattermost_username'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) $link['instance_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(applicationTimestampLabel($link['linked_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $link['last_used_at'] ? htmlspecialchars(applicationTimestampLabel($link['last_used_at']), ENT_QUOTES, 'UTF-8') : 'Never'; ?></td>
                            <td>
                                <form method="post" action="mattermost.php" data-confirm="Remove this Mattermost account link? MOED commands will stop working for that account until it is linked again.">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="link_id" value="<?php echo (int) $link['id']; ?>">
                                    <button type="submit" class="danger-button">Unlink</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="security-card" aria-labelledby="mattermost-commands-title">
        <h2 id="mattermost-commands-title">Available Commands</h2>
        <p><code>/moed today</code> shows your daily summary; <code>/moed tasks</code> shows assigned work; and <code>/moed event search TEXT</code> or <code>/moed event show ID</code> finds safe engagement details.</p>
        <p>Editors and administrators can bind a channel with <code>/moed link-event ID</code>, remove that binding with <code>/moed unlink-event</code>, and use task action buttons. Reviewers keep read-only access.</p>
        <p><a href="help.php#mattermost" class="security-button">Read the Mattermost User Guide</a></p>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
