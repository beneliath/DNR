<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();
requireRecentAdminElevation('users.php');
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$user_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$actor_user_id = (int) $_SESSION['user_id'];

try {
    if (!$user_id) {
        throw new InvalidArgumentException('Select a valid user account.');
    }
    if ($action === 'deactivate') {
        $result = deactivateUserAccount($conn, $user_id, $actor_user_id);
        $_SESSION['_user_lifecycle_message'] = sprintf(
            'The account was deactivated. %d calendar subscription(s) were revoked and %d task assignment(s) were removed.',
            $result['calendar_tokens'],
            $result['task_assignments']
        );
    } elseif ($action === 'activate') {
        activateUserAccount($conn, $user_id, $actor_user_id);
        $_SESSION['_user_lifecycle_message'] = 'The account was activated. Revoked calendar links and prior task assignments were not restored.';
    } elseif ($action === 'resend_invitation') {
        $invitation = renewUserInvitation($conn, $user_id, $actor_user_id);
        sendInvitationEmail($invitation['email'], $invitation['username'], $invitation['token']);
        $_SESSION['_user_lifecycle_message'] = 'A new invitation link was sent. Every older invitation link is invalid.';
    } else {
        throw new InvalidArgumentException('Invalid account lifecycle action.');
    }
} catch (InvalidArgumentException $exception) {
    $_SESSION['_user_lifecycle_error'] = $exception->getMessage();
} catch (Throwable $exception) {
    applicationLog('error', 'User lifecycle action failed', [
        'action' => $action,
        'target_user_id' => $user_id,
        'error' => $exception->getMessage(),
    ]);
    $_SESSION['_user_lifecycle_error'] = $action === 'resend_invitation'
        ? 'The invitation was renewed, but email delivery failed. Check the mail configuration and resend it.'
        : 'The account lifecycle change could not be completed.';
}

// A step-up authorization is deliberately one lifecycle action only. Target
// sessions lose their own elevation through the auth_version change.
unset($_SESSION['_admin_elevated_at']);
header('Location: users.php');
exit();
