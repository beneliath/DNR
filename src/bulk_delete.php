<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bulk_delete_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireAdmin();
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
if ($method === 'POST') requireValidCsrfToken();
// Selections survive the unlock redirect, expire after 30 minutes, and are isolated by tab.
$drafts = $_SESSION['_bulk_delete_selections'] ?? [];
foreach ($drafts as $key => $draft) {
    if ($draft['created_at'] < time() - 1800) unset($drafts[$key]);
}
$_SESSION['_bulk_delete_selections'] = $drafts;
$action = \Dnr\Http\RequestInput::string($_POST, 'action');
if ($method === 'POST' && $action === 'review') {
    try {
        $entity = \Dnr\Http\RequestInput::string($_POST, 'entity');
        $type = bulkDeleteType($entity);
        $ids = bulkDeleteIds($_POST['selected_ids'] ?? null);
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    $return_to = safeRecordReturnUrl($_POST['return_to'] ?? null, $type['page']);
    if (parse_url($return_to, PHP_URL_PATH) !== $type['page']) $return_to = $type['page'];
    $selection = bin2hex(random_bytes(16));
    if (count($drafts) >= 5) array_shift($drafts);
    $drafts[$selection] = ['entity' => $entity, 'ids' => $ids, 'return_to' => $return_to, 'created_at' => time()];
    $_SESSION['_bulk_delete_selections'] = $drafts;
    header('Location: bulk_delete.php?selection=' . $selection, true, 303);
    exit();
}
$selection = \Dnr\Http\RequestInput::string($method === 'POST' ? $_POST : $_GET, 'selection');
$draft = $drafts[$selection] ?? null;
if ($draft === null) {
    http_response_code(400);
    exit('This deletion selection has expired or already been used. Return to the list and select the items again.');
}
$type = bulkDeleteType($draft['entity']);
$return_to = $draft['return_to'];
$review_url = 'bulk_delete.php?selection=' . $selection;
if ($method === 'POST' && $action === 'cancel') {
    unset($_SESSION['_bulk_delete_selections'][$selection]);
    $prefix = $draft['entity'] === 'user' ? '_user_lifecycle' : $draft['entity'] . '_action';
    $_SESSION[$prefix . '_message'] = 'Deletion canceled. No items were deleted.';
    header('Location: ' . $return_to, true, 303);
    exit();
}
requireRecentAdminElevation($review_url);
$error = '';
if ($method === 'POST') {
    if ($action !== 'delete') {
        http_response_code(400);
        exit('Invalid bulk deletion action.');
    }
    if ($draft['entity'] === 'user' && ($_POST['delete_confirmation'] ?? null) !== 'DELETE USERS') {
        http_response_code(400);
        $error = 'Type DELETE USERS exactly to delete the selected accounts.';
    } else {
        $result = permanentlyDeleteSelectedRecords($conn, $draft['entity'], $draft['ids'], (int) $_SESSION['user_id']);
        unset($_SESSION['_bulk_delete_selections'][$selection]);
        $prefix = $draft['entity'] === 'user' ? '_user_lifecycle' : $draft['entity'] . '_action';
        $_SESSION[$prefix . '_message'] = $result['deleted'] . ' of ' . count($draft['ids'])
            . ' selected ' . $type['plural'] . ' permanently deleted.';
        if ($result['failed'] > 0) {
            $_SESSION[$prefix . '_error'] = $result['failed'] . ' selected item(s) could not be deleted. '
                . 'They may have been removed already or be protected by related records. Review the remaining items before trying again.';
        }
        header('Location: ' . $return_to, true, 303);
        exit();
    }
}
$records = bulkDeleteRecords($conn, $draft['entity'], $draft['ids'], (int) $_SESSION['user_id']);
$eligible_count = count(array_filter($records, static fn(array $record): bool => $record['blocked'] === ''));
generateCsrfToken();
releaseApplicationSessionLock();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Review Bulk Deletion'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css']]); ?>
<body>
<?php include __DIR__ . '/templates/header.php'; ?>
<main class="container bulk-delete-review">
    <h1>Are You Sure You Want to Delete These Items?</h1>
    <p>You selected <strong><?php echo count($records); ?> <?php echo htmlspecialchars($type['plural'], ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
    <p><?php echo htmlspecialchars($type['warning'], ENT_QUOTES, 'UTF-8'); ?></p>
    <p><strong>Permanent deletion cannot be undone.</strong> Items marked as protected will be kept.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <ul class="bulk-delete-review-list">
        <?php foreach ($records as $record): ?>
            <li><strong><?php echo htmlspecialchars($record['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span>#<?php echo $record['id']; ?></span>
                <?php if ($record['blocked'] !== ''): ?><p class="error">Kept: <?php echo htmlspecialchars($record['blocked'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <form id="bulk-delete-confirm-form" method="post" action="bulk_delete.php">
        <input type="hidden" name="action" value="delete">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="selection" value="<?php echo htmlspecialchars($selection, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($draft['entity'] === 'user' && $eligible_count > 0): ?>
            <label for="bulk-delete-confirmation">Type <strong>DELETE USERS</strong> to confirm</label>
            <input id="bulk-delete-confirmation" name="delete_confirmation" required pattern="DELETE USERS" autocomplete="off" spellcheck="false">
        <?php endif; ?>
        <div class="bulk-delete-review-actions">
            <button type="submit" class="button-secondary" form="bulk-delete-cancel-form">Cancel</button>
            <button type="submit" class="delete-button" data-admin-unlock-required data-confirm-title="Are you sure?" data-confirm="Permanently delete <?php echo $eligible_count; ?> selected item(s) and the related data described above? This cannot be undone." data-confirm-label="Delete permanently"<?php echo $eligible_count === 0 ? ' disabled' : ''; ?>>Permanently delete <?php echo $eligible_count; ?> item<?php echo $eligible_count === 1 ? '' : 's'; ?></button>
        </div>
    </form>
    <form id="bulk-delete-cancel-form" method="post" action="bulk_delete.php" hidden>
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="selection" value="<?php echo htmlspecialchars($selection, ENT_QUOTES, 'UTF-8'); ?>">
    </form>
</main>
<?php include __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
