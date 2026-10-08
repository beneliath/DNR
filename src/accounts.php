<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_lifecycle_helpers.php';
startSecureSession();
requireSuperAdmin();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A member renders primary-issued, target-specific switch forms. They still
    // require this browser's authenticated primary SuperAdmin session above.
    if (accountIsPrimary() && ($_POST['action'] ?? '') === 'switch' && isset($_POST['switch_intent'])) {
        try {
            platformVerifySwitchIntent(\Dnr\Http\RequestInput::string($_POST, 'switch_intent'),
                ['id' => $_SESSION['user_id'], 'auth_version' => $_SESSION['auth_version']],
                \Dnr\Http\RequestInput::string($_POST, 'account_key'));
        } catch (Throwable $exception) { http_response_code(403); exit('Account switch authorization expired. Reload Accounts and try again.'); }
    } else {
        requireValidCsrfToken();
    }
    try {
        $action = \Dnr\Http\RequestInput::string($_POST, 'action');
        if (in_array($action, ['archive', 'restore', 'delete', 'retry'], true) && accountIsPrimary()) {
            requireRecentAdminElevation('accounts.php');
            platformRequestAccountLifecycle($conn, \Dnr\Http\RequestInput::string($_POST, 'account_key'), $action,
                \Dnr\Http\RequestInput::string($_POST, 'confirmation'));
            header('Location: accounts.php?requested=1'); exit;
        }
        if ($action === 'create') {
            requireRecentAdminElevation('accounts.php');
            $name = \Dnr\Http\RequestInput::string($_POST, 'name');
            $key = \Dnr\Http\RequestInput::string($_POST, 'account_key');
            if (accountIsPrimary()) {
                platformCreateAccount($conn, $name, $key, (int) $_SESSION['user_id']);
            } else {
                $result = platformCall('create', platformIdentityPayload() + ['name' => $name, 'account_key' => $key,
                    'elevation_token' => $_SESSION['_platform_admin_elevation']['token']]);
                if (empty($result['ok'])) throw new InvalidArgumentException($result['error'] ?? 'The Account could not be created.');
            }
            header('Location: accounts.php?created=1'); exit;
        }
        if ($action !== 'switch') throw new InvalidArgumentException('Invalid action.');
        $key = \Dnr\Http\RequestInput::string($_POST, 'account_key');
        if (accountIsPrimary()) {
            $identity = platformEligibleIdentity($conn, (int) $_SESSION['user_id'], (int) $_SESSION['auth_version']);
            $url = platformIssueTicket($conn, $identity, $key);
        } else {
            throw new InvalidArgumentException('Open the Account using its primary-authorized switch button.');
        }
        header('Location: ' . $url); exit;
    } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) { $error = 'The Account action could not be completed. The address label may already be in use.'; }
}
if (accountIsPrimary()) {
    $accounts = platformAccountDirectory($conn);
} else {
    $directory = platformCall('list', platformIdentityPayload());
    $accounts = $directory['accounts'];
}
$confirmationAccount = null;
$confirmationAction = is_string($_GET['confirm'] ?? null) ? $_GET['confirm'] : '';
if (accountIsPrimary() && in_array($confirmationAction, ['archive', 'restore', 'delete', 'retry'], true)) {
    foreach ($accounts as $account) {
        if (!empty($account['is_primary']) || $account['account_key'] !== ($_GET['account_key'] ?? null)) continue;
        try {
            $confirmationState = accountLifecycleNextState($account['state'], $confirmationAction, !empty($account['lifecycle_error']));
            $confirmationAccount = $account;
        } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    }
}
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Accounts')); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container">
    <h1>Accounts</h1>
    <p>Each Account has its own users, records, files, and settings. You are working in <strong><?php echo htmlspecialchars(currentAccountProfile()['name']); ?></strong>.</p>
    <?php if (isset($error)): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <?php if (isset($_GET['created'])): ?><p class="success">Account requested. It will be available here when preparation finishes.</p><?php endif; ?>
    <?php if (isset($_GET['requested'])): ?><p class="success" role="status">Account action requested. Refresh this page to check its progress.</p><?php endif; ?>
    <?php if ($confirmationAccount):
        $deleting = $confirmationState === 'deleting';
        $actionLabel = $confirmationState === 'archiving' ? 'Archive Account' : ($deleting ? 'Delete Account Permanently' : 'Restore Account');
    ?>
    <section class="security-card account-lifecycle-confirmation" aria-labelledby="account-action-heading">
        <h2 id="account-action-heading"><?php echo $actionLabel; ?>: <?php echo htmlspecialchars($confirmationAccount['name']); ?></h2>
        <p><?php echo match ($confirmationState) {
            'archiving' => 'Users will be signed out, sign-in and shared links will be disabled, and background work will stop. Records and files are preserved so you can restore this Account.',
            'restoring' => 'The Account will become available again with its existing users, records, and files. Users will need to sign in again.',
            default => 'This permanently removes the Account’s live database, users, files, and deployment. It cannot be restored from this page. An encrypted, verified recovery backup is retained for operator recovery, and the address label remains reserved.',
        }; ?></p>
        <form method="post" action="accounts.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="<?php echo $confirmationAction; ?>">
            <input type="hidden" name="account_key" value="<?php echo htmlspecialchars($confirmationAccount['account_key']); ?>">
            <?php if ($deleting): ?><div class="form-group"><label for="account-delete-confirmation">Type <?php echo htmlspecialchars($confirmationAccount['account_key']); ?> to confirm</label><input type="text" id="account-delete-confirmation" name="confirmation" autocomplete="off" required></div><?php endif; ?>
            <div class="account-lifecycle-buttons"><a class="button-secondary" href="accounts.php">Cancel</a><button type="submit" class="<?php echo $deleting ? 'delete-button' : 'save-button'; ?>"><?php echo $actionLabel; ?></button></div>
        </form>
    </section>
    <?php endif; ?>
    <ul class="account-directory" aria-label="Available Accounts">
        <?php foreach ($accounts as $account):
            $isCurrent = $account['account_key'] === currentAccountKey();
            $isReady = $account['state'] === 'ready';
            $statusLabel = $isCurrent ? 'Current Account' : match ($account['state']) {
                'ready' => 'Ready', 'disabled' => 'Archived',
                'archiving' => 'Archiving', 'restoring' => 'Restoring', 'deleting' => 'Deleting', default => 'Preparing',
            };
            if (!empty($account['lifecycle_error'])) $statusLabel = 'Needs Attention';
            $canArchive = $isReady && empty($account['is_primary']);
            $manageUrl = (accountIsPrimary() ? '' : accountPrimaryPublicUrl() . '/') . 'accounts.php?' . http_build_query(['account_key' => $account['account_key']]);
        ?>
        <li class="account-directory-row<?php echo $isCurrent ? ' account-directory-current' : ''; ?>"<?php echo $isCurrent ? ' aria-current="true"' : ''; ?>>
            <span class="account-directory-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h2m4 0h2M8 11h2m4 0h2M10 21v-6h4v6"/></svg></span>
            <div class="account-directory-name">
                <div class="account-directory-heading">
                    <h2><?php echo htmlspecialchars($account['name']); ?></h2>
                    <?php if (!empty($account['is_primary'])): ?>
                    <span class="account-primary-badge"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg>Primary Account</span>
                    <?php endif; ?>
                </div>
                <span class="account-directory-address" aria-label="Address label"><?php echo htmlspecialchars($account['account_key']); ?></span>
            </div>
            <span class="account-directory-status<?php echo !$isCurrent && !$isReady ? ' account-directory-pending' : ''; ?>"><?php echo $statusLabel; ?></span>
            <div class="account-directory-action">
                <?php if ($isCurrent): ?>
                    <a class="button-secondary" href="dashboard.php">Open Dashboard</a>
                <?php elseif (!empty($account['is_primary'])): ?>
                    <a class="button-secondary" href="<?php echo htmlspecialchars(accountPublicUrl($account['public_url']) . '/dashboard.php'); ?>" aria-label="Open <?php echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?>">Open Account</a>
                <?php elseif ($isReady): ?>
                    <form method="post" action="<?php echo htmlspecialchars((accountIsPrimary() ? '' : accountPrimaryPublicUrl() . '/') . 'accounts.php'); ?>">
                        <?php if (accountIsPrimary()): echo csrfInput(); else: ?>
                        <input type="hidden" name="switch_intent" value="<?php echo htmlspecialchars($account['switch_intent'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="action" value="switch">
                        <input type="hidden" name="account_key" value="<?php echo htmlspecialchars($account['account_key']); ?>">
                        <button type="submit" class="button-secondary" aria-label="Open <?php echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?>">Open Account</button>
                    </form>
                <?php elseif ($account['state'] === 'disabled'): ?>
                    <a class="button-secondary" href="<?php echo htmlspecialchars($manageUrl . '&confirm=restore'); ?>">Restore Account</a>
                <?php elseif (!empty($account['lifecycle_error'])): ?>
                    <a class="button-secondary" href="<?php echo htmlspecialchars($manageUrl . '&confirm=retry'); ?>">Retry Action</a>
                <?php else: ?>
                    <span class="account-directory-wait"><?php echo $account['state'] === 'disabled' ? 'Access paused' : 'Action in progress'; ?></span>
                <?php endif; ?>
                <?php if ($canArchive || $account['state'] === 'disabled'): ?>
                    <a class="action-button action-icon-button <?php echo $canArchive ? 'archive-button' : 'delete-button'; ?>" href="<?php echo htmlspecialchars($manageUrl . '&confirm=' . ($canArchive ? 'archive' : 'delete')); ?>" aria-label="<?php echo $canArchive ? 'Archive ' : 'Delete '; echo htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $canArchive ? 'Archive Account' : 'Delete Account'; ?>" data-tooltip="<?php echo $canArchive ? 'Archive Account' : 'Delete Account'; ?>"><?php echo actionIconSvg($canArchive ? 'archive' : 'delete'); ?></a>
                <?php endif; ?>
            </div>
            <?php if (!empty($account['lifecycle_error'])): ?><p class="account-directory-error" role="status"><?php echo htmlspecialchars($account['lifecycle_error']); ?></p><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <section class="security-card account-create-card"><h2>Create Account</h2>
    <form method="post" action="accounts.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="create">
        <div class="form-group"><label for="name">Account Name</label><input type="text" id="name" name="name" maxlength="160" required></div>
        <div class="form-group"><label for="account_key">Address Label</label><input type="text" id="account_key" name="account_key" pattern="[a-z][a-z0-9-]{2,63}" maxlength="64" required><p class="field-help">A unique label, such as grace-community.</p></div>
        <button type="submit" class="save-button">Create Account</button>
    </form></section>
</main><?php include 'templates/footer.php'; ?></body></html>
