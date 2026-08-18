<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
startSecureSession();
requireAdmin();
requireAuditLogSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $confirmation = is_string($_POST['purge_confirmation'] ?? null)
        ? trim($_POST['purge_confirmation'])
        : '';

    if ($action !== 'purge') {
        http_response_code(400);
        exit('Invalid audit log action.');
    }

    if ($confirmation !== 'PURGE') {
        $_SESSION['audit_log_action_error'] = 'Type PURGE exactly to permanently delete the audit log.';
        header('Location: audit_log.php');
        exit();
    }

    $actor_id = (int) ($_SESSION['user_id'] ?? 0);
    $transaction_started = false;
    try {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start the audit log purge transaction.');
        }
        $transaction_started = true;
        if (!$conn->query('DELETE FROM security_audit_log')) {
            throw new RuntimeException('Unable to delete the audit log.');
        }
        $purged_entry_count = (int) $conn->affected_rows;
        $audit_recorded = recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'audit_log_purged',
            'actor_user_id' => $actor_id,
            'target_user_id' => $actor_id,
            'entity_type' => 'audit_log',
            'details' => sprintf(
                'Administrator permanently deleted %d prior audit entr%s',
                $purged_entry_count,
                $purged_entry_count === 1 ? 'y' : 'ies'
            ),
        ]);
        if (!$audit_recorded) {
            throw new RuntimeException('Unable to record the audit log purge event.');
        }
        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit the audit log purge.');
        }
        $transaction_started = false;
        $_SESSION['audit_log_action_message'] = sprintf(
            'Audit log purged. %d prior entr%s permanently deleted.',
            $purged_entry_count,
            $purged_entry_count === 1 ? 'y was' : 'ies were'
        );
    } catch (Throwable $exception) {
        if ($transaction_started) {
            $conn->rollback();
        }
        error_log('Audit log purge failed: ' . $exception->getMessage());
        $_SESSION['audit_log_action_error'] = 'The audit log could not be purged. No entries were deleted.';
    }

    header('Location: audit_log.php');
    exit();
}

$action_message = $_SESSION['audit_log_action_message'] ?? '';
$action_error = $_SESSION['audit_log_action_error'] ?? '';
unset($_SESSION['audit_log_action_message'], $_SESSION['audit_log_action_error']);

$allowed_categories = ['login', 'database_change', 'security'];
$allowed_page_sizes = [20, 50, 100];
$category = $_GET['category'] ?? '';
if (!in_array($category, $allowed_categories, true)) {
    $category = '';
}
$search = trim($_GET['q'] ?? '');

$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true)
    ? $requested_page_size
    : 20;

$requested_page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$requested_page = $requested_page ?: 1;
$where_parts = [];
if ($category !== '') {
    $where_parts[] = 'event_category = ?';
}
if ($search !== '') {
    $where_parts[] = "(
        actor_username LIKE ?
        OR target_username LIKE ?
        OR REPLACE(event_category, '_', ' ') LIKE ?
        OR REPLACE(event_type, '_', ' ') LIKE ?
        OR REPLACE(entity_type, '_', ' ') LIKE ?
        OR CAST(entity_id AS CHAR) LIKE ?
        OR entity_label LIKE ?
        OR details LIKE ?
        OR ip_address LIKE ?
        OR CAST(created_at AS CHAR) LIKE ?
    )";
}
$where_clause = $where_parts ? ' WHERE ' . implode(' AND ', $where_parts) : '';
$search_pattern = '%' . $search . '%';

$count_stmt = $conn->prepare(
    "SELECT COUNT(*) AS entry_count FROM security_audit_log{$where_clause}"
);
if (!$count_stmt) {
    die('Unable to retrieve the audit log.');
}
if ($category !== '' && $search !== '') {
    $count_stmt->bind_param(
        'sssssssssss',
        $category,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern
    );
} elseif ($category !== '') {
    $count_stmt->bind_param('s', $category);
} elseif ($search !== '') {
    $count_stmt->bind_param(
        'ssssssssss',
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern
    );
}
$count_stmt->execute();
$total_entries = (int) $count_stmt->get_result()->fetch_assoc()['entry_count'];
$count_stmt->close();

$total_pages = max(1, (int) ceil($total_entries / $page_size));
$current_page = min($requested_page, $total_pages);
$offset = ($current_page - 1) * $page_size;

$entries_stmt = $conn->prepare(
    "SELECT id, actor_username, target_username, event_category, event_type,
            entity_type, entity_id, entity_label, details, ip_address, created_at
     FROM security_audit_log{$where_clause}
     ORDER BY created_at DESC, id DESC
     LIMIT ? OFFSET ?"
);
if (!$entries_stmt) {
    die('Unable to retrieve the audit log.');
}
if ($category !== '' && $search !== '') {
    $entries_stmt->bind_param(
        'sssssssssssii',
        $category,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $page_size,
        $offset
    );
} elseif ($category === '' && $search === '') {
    $entries_stmt->bind_param('ii', $page_size, $offset);
} elseif ($category !== '') {
    $entries_stmt->bind_param('sii', $category, $page_size, $offset);
} else {
    $entries_stmt->bind_param(
        'ssssssssssii',
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $search_pattern,
        $page_size,
        $offset
    );
}
$entries_stmt->execute();
$entries = $entries_stmt->get_result();

$category_labels = [
    'login' => 'Login',
    'database_change' => 'Database',
    'security' => 'Security',
];
$login_event_labels = [
    'successful_login' => 'Successful login',
    'failed_login' => 'Failed login',
    'logout' => 'Logout',
];
$database_action_labels = [
    'database_insert' => 'Created',
    'database_update' => 'Updated',
    'database_delete' => 'Deleted',
];
$entity_labels = [
    'users' => 'user',
    'organizations' => 'organization',
    'contacts' => 'contact',
    'engagements' => 'engagement',
    'presentations' => 'presentation',
    'audit_log' => 'audit log',
];
$security_event_labels = [
    'password_recovery_started' => 'Password recovery started',
    'password_recovery_factor_failed' => 'Password recovery verification failed',
    'password_recovery_factor_verified' => 'Password recovery verification succeeded',
    'password_recovered' => 'Password recovered',
    'password_changed' => 'Password changed',
    'admin_password_reset' => 'Administrator reset password',
    'admin_password_reset_auth_failed' => 'Administrator password-reset verification failed',
    'two_factor_enabled' => 'Two-factor authentication enabled',
    'two_factor_replaced' => 'Two-factor authentication replaced',
    'two_factor_disabled' => 'Two-factor authentication disabled',
    'two_factor_admin_reset' => 'Administrator reset two-factor authentication',
    'two_factor_login' => 'Two-factor login verification succeeded',
    'recovery_code_login' => 'Recovery-code login verification succeeded',
    'recovery_codes_regenerated' => 'Recovery codes regenerated',
    'database_backup_created' => 'Database backup created',
    'database_backup_auth_failed' => 'Database backup verification failed',
    'database_restored' => 'Database restored',
    'database_restore_auth_failed' => 'Database restore verification failed',
    'audit_log_purged' => 'Audit log purged',
];
$audit_timezone_name = getenv('DNR_TIMEZONE') ?: 'America/Chicago';
try {
    $audit_timezone = new DateTimeZone($audit_timezone_name);
} catch (Throwable $exception) {
    $audit_timezone_name = 'UTC';
    $audit_timezone = new DateTimeZone('UTC');
}

function auditLogPageUrl($page, $category, $page_size, $search = '') {
    $parameters = [
        'page' => $page,
        'per_page' => $page_size,
    ];
    if ($category !== '') {
        $parameters['category'] = $category;
    }
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    return 'audit_log.php?' . http_build_query($parameters);
}

function auditLogTimestamps($created_at, DateTimeZone $display_timezone) {
    try {
        $utc_timestamp = new DateTimeImmutable((string) $created_at, new DateTimeZone('UTC'));
        return [
            'display' => $utc_timestamp->setTimezone($display_timezone)->format('Y-m-d H:i:s T'),
            'utc' => $utc_timestamp->format('Y-m-d H:i:s') . ' UTC',
        ];
    } catch (Throwable $exception) {
        return ['display' => (string) $created_at, 'utc' => ''];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .page-heading,
        .audit-filters,
        .audit-page-size,
        .pagination {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .audit-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin: 15px 0 20px;
        }
        .page-heading {
            justify-content: space-between;
        }
        .page-heading-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .audit-filters {
            margin: 0;
        }
        .audit-page-size {
            margin-left: auto;
        }
        .filter-button {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 4px;
            background: var(--button-neutral-color);
            color: #fff;
            text-decoration: none;
        }
        .filter-button.active {
            background-color: var(--button-edit-color) !important;
        }
        .audit-table-wrapper {
            overflow-x: auto;
        }
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 75%;
        }
        .audit-table th,
        .audit-table td {
            padding: 10px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid #ddd;
        }
        .dark-mode .audit-table th,
        .dark-mode .audit-table td {
            border-bottom-color: #444;
        }
        .audit-table th {
            background: #f5f5f5;
        }
        .audit-timestamp {
            white-space: nowrap;
        }
        .dark-mode .audit-table th {
            background: #2d2d2d;
        }
        .audit-badge {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 999px;
            background: var(--button-neutral-color);
            color: #fff;
            font-size: inherit;
        }
        .audit-badge-login { background: #2e7d32; }
        .audit-badge-database_change { background: #1565c0; }
        .audit-badge-security { background: #6a1b9a; }
        .audit-detail {
            display: block;
            margin-top: 4px;
            color: #666;
            font-size: inherit;
        }
        .dark-mode .audit-detail {
            color: #bbb;
        }
        .pagination {
            justify-content: center;
            margin-top: 20px;
        }
        .empty-state {
            text-align: center !important;
        }
        .purge-confirmation-input {
            margin-bottom: 0 !important;
        }
        @media (max-width: 700px) {
            .page-heading,
            .audit-controls {
                align-items: flex-start;
                flex-direction: column;
            }
            .audit-page-size {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <div class="page-heading">
        <div>
            <h1>Audit Log</h1>
            <p class="page-intro">Login, logout, and database activity. Newest entries appear first.</p>
        </div>
        <div class="page-heading-actions">
            <a href="users.php" class="button-add">Back to Users</a>
            <form method="post" action="audit_log.php" id="purge-audit-log-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="purge">
                <button type="button" class="danger-button" id="open-purge-audit-log">Purge Log</button>
            </form>
        </div>
    </div>

    <?php if ($action_message !== ''): ?>
        <div class="success" role="status"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <div class="error" role="alert"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="audit-controls">
        <form method="get" action="audit_log.php" class="list-search-form" role="search">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <label class="visually-hidden" for="audit-search">Search audit log</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="audit-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search audit log">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $category, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <nav class="audit-filters" aria-label="Audit log filters">
            <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, '', $page_size, $search), ENT_QUOTES, 'UTF-8'); ?>"
               class="filter-button<?php echo $category === '' ? ' active' : ''; ?>">All</a>
            <?php foreach ($allowed_categories as $filter_category): ?>
                <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $filter_category, $page_size, $search), ENT_QUOTES, 'UTF-8'); ?>"
                   class="filter-button<?php echo $category === $filter_category ? ' active' : ''; ?>">
                    <?php echo htmlspecialchars($category_labels[$filter_category], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>

    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context"><?php echo $total_entries; ?> result<?php echo $total_entries === 1 ? '' : 's'; ?> for “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>

    <div class="audit-table-wrapper">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Category</th>
                    <th>User</th>
                    <th>Event</th>
                    <th>Record</th>
                    <th>IP address</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entries->num_rows === 0): ?>
                    <tr><td colspan="6" class="empty-state">No audit entries match the current view.</td></tr>
                <?php else: ?>
                    <?php while ($entry = $entries->fetch_assoc()): ?>
                        <?php
                        $entry_category = $entry['event_category'];
                        $actor = $entry['actor_username'] ?: 'System';
                        $event_label = $entry['event_type'];
                        if ($entry_category === 'login') {
                            $event_label = $login_event_labels[$entry['event_type']]
                                ?? ucwords(str_replace('_', ' ', $entry['event_type']));
                        } elseif ($entry_category === 'database_change') {
                            $event_label = $database_action_labels[$entry['event_type']] ?? ucfirst($entry['event_type']);
                        } elseif (isset($security_event_labels[$entry['event_type']])) {
                            $event_label = $security_event_labels[$entry['event_type']];
                        } else {
                            $event_label = ucwords(str_replace('_', ' ', $entry['event_type']));
                        }

                        $record = '—';
                        if (!empty($entry['entity_type'])) {
                            $entity_label = $entity_labels[$entry['entity_type']] ?? rtrim($entry['entity_type'], 's');
                            $record = ucfirst($entity_label);
                            if (!empty($entry['entity_label'])) {
                                $record .= ': ' . $entry['entity_label'];
                            }
                            if (!empty($entry['entity_id'])) {
                                $record .= ' (#' . $entry['entity_id'] . ')';
                            }
                        } elseif (!empty($entry['target_username'])) {
                            $record = 'User: ' . $entry['target_username'];
                        }
                        $timestamps = auditLogTimestamps($entry['created_at'], $audit_timezone);
                        ?>
                        <tr>
                            <td class="audit-timestamp">
                                <?php echo htmlspecialchars($timestamps['display'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($timestamps['utc'] !== ''): ?>
                                    <span class="audit-detail"><?php echo htmlspecialchars($timestamps['utc'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="audit-badge audit-badge-<?php echo htmlspecialchars($entry_category, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($category_labels[$entry_category] ?? ucfirst($entry_category), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($actor, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($event_label, ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($entry['details'])): ?>
                                    <span class="audit-detail"><?php echo htmlspecialchars($entry['details'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($record, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($entry['ip_address'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_entries > 0): ?>
        <nav class="pagination pagination-with-size" aria-label="Audit log pages">
            <div class="page-size-selector" aria-label="Audit log entries per page">
                <span class="page-size-label">Rows per page:</span>
                <?php foreach ($allowed_page_sizes as $allowed_page_size): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $category, $allowed_page_size, $search), ENT_QUOTES, 'UTF-8'); ?>"
                       class="filter-button page-size-button<?php echo $page_size === $allowed_page_size ? ' active' : ''; ?>"
                       <?php echo $page_size === $allowed_page_size ? 'aria-current="true"' : ''; ?>><?php echo $allowed_page_size; ?></a>
                <?php endforeach; ?>
            </div>
            <span class="pagination-status">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> · <?php echo $total_entries; ?> entries</span>
            <div class="pagination-actions">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl($current_page - 1, $category, $page_size, $search), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Previous</a>
                <?php endif; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl($current_page + 1, $category, $page_size, $search), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Next</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>

    <dialog id="purge-audit-log-confirmation" class="confirmation-dialog" aria-labelledby="purge-audit-log-title" aria-describedby="purge-audit-log-message">
        <h2 id="purge-audit-log-title">Purge Audit Log?</h2>
        <p id="purge-audit-log-message">This permanently deletes every current audit entry. A new entry identifying the administrator and the number of deleted entries will be retained.</p>
        <label for="purge-confirmation-input">Type <strong>PURGE</strong> to continue.</label>
        <input type="text" id="purge-confirmation-input" class="purge-confirmation-input" name="purge_confirmation" form="purge-audit-log-form" autocomplete="off" autocapitalize="characters" spellcheck="false">
        <div class="confirmation-dialog-actions">
            <button type="button" class="button-secondary" id="cancel-purge-audit-log" autofocus>Cancel</button>
            <button type="submit" class="danger-button" id="confirm-purge-audit-log" form="purge-audit-log-form" disabled>Purge permanently</button>
        </div>
    </dialog>
</main>
<script>
(function () {
    const confirmation = document.getElementById('purge-audit-log-confirmation');
    const openButton = document.getElementById('open-purge-audit-log');
    const cancelButton = document.getElementById('cancel-purge-audit-log');
    const confirmButton = document.getElementById('confirm-purge-audit-log');
    const confirmationInput = document.getElementById('purge-confirmation-input');

    if (!confirmation || !openButton || !cancelButton || !confirmButton || !confirmationInput) return;

    function resetConfirmation() {
        confirmationInput.value = '';
        confirmButton.disabled = true;
    }

    openButton.addEventListener('click', function () {
        resetConfirmation();
        confirmation.showModal();
        confirmationInput.focus();
    });
    cancelButton.addEventListener('click', function () {
        confirmation.close();
    });
    confirmation.addEventListener('cancel', resetConfirmation);
    confirmationInput.addEventListener('input', function () {
        confirmButton.disabled = confirmationInput.value !== 'PURGE';
    });
})();
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
