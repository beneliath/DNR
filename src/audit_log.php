<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireAdmin();
requireAuditLogSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

function auditLogRetentionDays($value) {
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (preg_match('/\A[1-9][0-9]{0,4}\z/', $value) !== 1) {
        return null;
    }
    $days = (int) $value;
    return $days <= 36500 ? $days : null;
}

function auditLogPrune(mysqli $conn, $retention_days, $actor_user_id, $actor_username, $ip_address) {
    $statement = $conn->prepare(
        'CALL prune_security_audit_log(?, ?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare the audit-log prune operation.');
    }
    $statement->bind_param(
        'iiss',
        $retention_days,
        $actor_user_id,
        $actor_username,
        $ip_address
    );
    $statement->execute();
    $result = $statement->get_result();
    $outcome = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    while ($statement->more_results() && $statement->next_result()) {
        $extra_result = $statement->get_result();
        if ($extra_result) {
            $extra_result->free();
        }
    }
    $statement->close();
    if (!is_array($outcome)
        || !isset($outcome['deleted_count'], $outcome['cutoff_utc'])
    ) {
        throw new RuntimeException('The audit-log prune operation returned no result.');
    }
    return [
        'deleted_count' => (int) $outcome['deleted_count'],
        'cutoff_utc' => (string) $outcome['cutoff_utc'],
        'more_entries' => !empty($outcome['more_entries']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = \Dnr\Http\RequestInput::string($_POST, 'action');
    if ($action !== 'prune') {
        http_response_code(400);
        exit('Invalid audit-log action.');
    }

    $retention_days = auditLogRetentionDays($_POST['retention_days'] ?? null);
    $confirmation = \Dnr\Http\RequestInput::string($_POST, 'prune_confirmation', '', 32);
    $preview_state = $_SESSION['_audit_log_prune_preview'] ?? null;
    $previewed_at = is_array($preview_state) ? ($preview_state['previewed_at'] ?? null) : null;
    $preview_is_current = is_array($preview_state)
        && (int) ($preview_state['retention_days'] ?? 0) === $retention_days
        && (string) ($preview_state['previewed_on'] ?? '') === gmdate('Y-m-d')
        && is_int($previewed_at)
        && $previewed_at <= time()
        && (time() - $previewed_at) <= 600;
    $return_url = 'audit_log.php';
    if ($retention_days !== null) {
        $return_url .= '?' . http_build_query(['retention_days' => $retention_days])
            . '#audit-retention';
    }

    if ($retention_days === null) {
        $_SESSION['_audit_log_prune_error'] = 'Choose a retention period from 1 through 36500 days.';
    } elseif (!$preview_is_current) {
        $_SESSION['_audit_log_prune_error'] = 'Preview this retention period before pruning.';
    } elseif (!hash_equals('PRUNE', $confirmation)) {
        $_SESSION['_audit_log_prune_error'] = 'Type PRUNE exactly to confirm permanent deletion.';
    } else {
        requireRecentAdminElevation($return_url);
        try {
            $actor_user_id = (int) $_SESSION['user_id'];
            $actor_username = substr((string) ($_SESSION['username'] ?? ''), 0, 50);
            $outcome = auditLogPrune(
                $conn,
                $retention_days,
                $actor_user_id,
                $actor_username,
                requestIpAddress()
            );
            $deleted_count = $outcome['deleted_count'];
            if ($deleted_count === 0) {
                $_SESSION['_audit_log_prune_message'] = 'No audit entries were old enough to prune.';
            } else {
                $_SESSION['_audit_log_prune_message'] = sprintf(
                    'Permanently pruned %d audit %s older than %s UTC.%s',
                    $deleted_count,
                    $deleted_count === 1 ? 'entry' : 'entries',
                    $outcome['cutoff_utc'],
                    $outcome['more_entries']
                        ? ' More expired entries remain; run the reviewed prune again.'
                        : ''
                );
                unset($_SESSION['_admin_elevated_at']);
            }
            unset($_SESSION['_audit_log_prune_preview']);
        } catch (Throwable $exception) {
            applicationLog('error', 'Audit-log prune failed', [
                'retention_days' => $retention_days,
                'actor_user_id' => (int) $_SESSION['user_id'],
                'error' => $exception->getMessage(),
            ]);
            $_SESSION['_audit_log_prune_error'] = 'The audit log could not finish pruning. Any completed batches remain deleted; preview again before retrying.';
        }
    }
    header('Location: ' . $return_url);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$prune_message = (string) ($_SESSION['_audit_log_prune_message'] ?? '');
$prune_error = (string) ($_SESSION['_audit_log_prune_error'] ?? '');
unset($_SESSION['_audit_log_prune_message'], $_SESSION['_audit_log_prune_error']);

$allowed_categories = ['login', 'database_change', 'security'];
$allowed_page_sizes = [20, 50, 100];
$category = \Dnr\Http\RequestInput::string($_GET, 'category');
if (!in_array($category, $allowed_categories, true)) {
    $category = '';
}
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 256);
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$requested_from_date = \Dnr\Http\RequestInput::string($_GET, 'from');
$requested_to_date = \Dnr\Http\RequestInput::string($_GET, 'to');
$from_date = validIsoDate($requested_from_date) ? $requested_from_date : '';
$to_date = validIsoDate($requested_to_date) ? $requested_to_date : '';
$ip_filter = filter_var(
    \Dnr\Http\RequestInput::string($_GET, 'ip'),
    FILTER_VALIDATE_IP
) ?: '';

$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true)
    ? $requested_page_size
    : 20;

$cursor = decodePaginationCursor(
    \Dnr\Http\RequestInput::string($_GET, 'cursor'),
    ['created_at', 'id']
);
$where_parts = [];
$filter_types = '';
$filter_values = [];
if ($category !== '') {
    $where_parts[] = 'event_category = ?';
    $filter_types .= 's';
    $filter_values[] = $category;
}
if ($fulltext_query !== '') {
    $where_parts[] = 'MATCH(
        actor_username, target_username, event_category, event_type,
        entity_type, entity_label, details, ip_address
    ) AGAINST (? IN BOOLEAN MODE)';
    $filter_types .= 's';
    $filter_values[] = $fulltext_query;
}
if ($from_date !== '') {
    $where_parts[] = 'created_at >= ?';
    $filter_types .= 's';
    $filter_values[] = $from_date . ' 00:00:00';
}
if ($to_date !== '') {
    $where_parts[] = 'created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $filter_types .= 's';
    $filter_values[] = $to_date . ' 00:00:00';
}
if ($ip_filter !== '') {
    $where_parts[] = 'ip_address = ?';
    $filter_types .= 's';
    $filter_values[] = $ip_filter;
}
if ($cursor !== null && ctype_digit((string) $cursor['id'])) {
    $where_parts[] = '(created_at < ? OR (created_at = ? AND id < ?))';
    $filter_types .= 'ssi';
    $filter_values[] = (string) $cursor['created_at'];
    $filter_values[] = (string) $cursor['created_at'];
    $filter_values[] = (int) $cursor['id'];
} else {
    $cursor = null;
}
$where_clause = $where_parts ? ' WHERE ' . implode(' AND ', $where_parts) : '';
$query_limit = $page_size + 1;

$entries_stmt = $conn->prepare(
    "SELECT id, actor_username, target_username, event_category, event_type,
            entity_type, entity_id, entity_label, details, ip_address, created_at
     FROM security_audit_log{$where_clause}
     ORDER BY created_at DESC, id DESC
     LIMIT ?"
);
if (!$entries_stmt) {
    abortApplication(503, 'The audit log is temporarily unavailable.', ['error' => $conn->error]);
}
$entry_types = $filter_types . 'i';
$entry_values = array_merge($filter_values, [$query_limit]);
$entry_bind = [$entry_types];
foreach ($entry_values as &$entry_value) $entry_bind[] = &$entry_value;
unset($entry_value);
$entries_stmt->bind_param(...$entry_bind);
$entries_stmt->execute();
$entries = $entries_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_older_entries = count($entries) > $page_size;
if ($has_older_entries) {
    array_pop($entries);
}
$next_cursor = null;
if ($has_older_entries && $entries !== []) {
    $last_entry = $entries[array_key_last($entries)];
    $next_cursor = encodePaginationCursor([
        'created_at' => (string) $last_entry['created_at'],
        'id' => (int) $last_entry['id'],
    ]);
}

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
    'inbound_email_messages' => 'inbound email',
    'engagements' => 'engagement',
    'presentations' => 'presentation',
    'audit_log' => 'audit log',
    'calendar_subscription' => 'calendar subscription',
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
    'user_deleted' => 'User deleted',
    'database_backup_created' => 'Database backup created',
    'database_backup_auth_failed' => 'Database backup verification failed',
    'database_restored' => 'Database restored',
    'database_restore_auth_failed' => 'Database restore verification failed',
    'audit_log_purged' => 'Audit log purged',
    'audit_log_pruned' => 'Audit log pruned',
    'audit_log_prune_batch' => 'Audit log pruning batch',
    'calendar_subscription_created' => 'Calendar subscription created',
    'calendar_subscription_revoked' => 'Calendar subscription revoked',
    'calendar_subscriptions_purged' => 'Revoked calendar subscriptions purged',
];
$audit_timezone_name = applicationTimezoneName();
$audit_timezone = applicationTimezone();
$retention_requested = array_key_exists('retention_days', $_GET);
$retention_input_value = $retention_requested
    ? \Dnr\Http\RequestInput::string($_GET, 'retention_days', '', 5)
    : '365';
$retention_days = $retention_requested
    ? auditLogRetentionDays($retention_input_value)
    : null;
$retention_preview = null;
$retention_validation_error = '';
if ($retention_requested && $retention_days === null) {
    $retention_validation_error = 'Choose a retention period from 1 through 36500 days.';
} elseif ($retention_days !== null) {
    $retention_cutoff = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
        ->sub(new DateInterval("P{$retention_days}D"))
        ->format('Y-m-d H:i:s');
    $retention_statement = $conn->prepare(
        'SELECT COUNT(*) AS entry_count FROM security_audit_log WHERE created_at < ?'
    );
    if (!$retention_statement) {
        abortApplication(503, 'The audit log is temporarily unavailable.', ['error' => $conn->error]);
    }
    $retention_statement->bind_param('s', $retention_cutoff);
    $retention_statement->execute();
    $retention_count = (int) (
        $retention_statement->get_result()->fetch_assoc()['entry_count'] ?? 0
    );
    $retention_statement->close();
    $retention_preview = [
        'cutoff_utc' => $retention_cutoff,
        'entry_count' => $retention_count,
    ];
    $_SESSION['_audit_log_prune_preview'] = [
        'retention_days' => $retention_days,
        'previewed_on' => gmdate('Y-m-d'),
        'previewed_at' => time(),
    ];
}
$admin_actions_unlocked = hasRecentAdminElevation();
$retention_unlock_url = $retention_days === null
    ? ''
    : 'admin_elevation.php?' . http_build_query([
        'return' => 'audit_log.php?' . http_build_query([
            'retention_days' => $retention_days,
        ]) . '#audit-retention',
    ]);

function auditLogPageUrl($cursor, $category, $page_size, $search = '', $from = '', $to = '', $ip = '') {
    $parameters = [
        'per_page' => $page_size,
    ];
    if (is_string($cursor) && $cursor !== '') $parameters['cursor'] = $cursor;
    if ($category !== '') {
        $parameters['category'] = $category;
    }
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    if ($from !== '') $parameters['from'] = $from;
    if ($to !== '') $parameters['to'] = $to;
    if ($ip !== '') $parameters['ip'] = $ip;
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
<?php renderPageHead(applicationPageTitle('Audit Log'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/audit_log.min.css',
  ),
)); ?>
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
        </div>
    </div>
    <?php if ($prune_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($prune_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($prune_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($prune_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <p class="page-intro audit-retention-note">Audit records remain append-only during normal application use. Administrators can preview and prune expired entries below; deployment operators can use the equivalent terminal command.</p>

    <section class="audit-retention-card" id="audit-retention" aria-labelledby="audit-retention-title">
        <div class="audit-retention-heading">
            <div>
                <span class="audit-retention-kicker">Retention</span>
                <h2 id="audit-retention-title">Prune Old Entries</h2>
                <p>Keep the most recent number of days you choose and permanently delete only older entries.</p>
            </div>
            <?php if ($admin_actions_unlocked): ?>
                <span class="audit-retention-unlocked">Administrator access unlocked</span>
            <?php else: ?>
                <span class="audit-retention-locked">Fresh confirmation required to prune</span>
            <?php endif; ?>
        </div>

        <form method="get" action="audit_log.php#audit-retention" class="audit-retention-preview-form">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($from_date !== ''): ?><input type="hidden" name="from" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($to_date !== ''): ?><input type="hidden" name="to" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($ip_filter !== ''): ?><input type="hidden" name="ip" value="<?php echo htmlspecialchars($ip_filter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <label for="retention-days">Days to keep</label>
            <input type="number" id="retention-days" name="retention_days"
                   value="<?php echo htmlspecialchars($retention_input_value, ENT_QUOTES, 'UTF-8'); ?>"
                   min="1" max="36500" step="1" inputmode="numeric" required>
            <button type="submit" class="filter-button">Preview Pruning</button>
        </form>

        <?php if ($retention_validation_error !== ''): ?>
            <p class="error audit-retention-feedback"><?php echo htmlspecialchars($retention_validation_error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php elseif (is_array($retention_preview)): ?>
            <?php $preview_count = (int) $retention_preview['entry_count']; ?>
            <div class="audit-retention-preview" role="status">
                <div>
                    <span>Entries to permanently delete</span>
                    <strong><?php echo number_format($preview_count); ?></strong>
                </div>
                <p>
                    Entries before
                    <time datetime="<?php echo htmlspecialchars($retention_preview['cutoff_utc'], ENT_QUOTES, 'UTF-8'); ?>Z">
                        <?php echo htmlspecialchars($retention_preview['cutoff_utc'], ENT_QUOTES, 'UTF-8'); ?> UTC
                    </time>
                    will be deleted. The most recent <?php echo (int) $retention_days; ?> days will remain.
                </p>
            </div>

            <?php if ($preview_count > 0): ?>
                <div class="audit-retention-action">
                    <?php if (!$admin_actions_unlocked): ?>
                        <p>Confirm your administrator password and a fresh authenticator or recovery code before continuing.</p>
                        <a href="<?php echo htmlspecialchars($retention_unlock_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Unlock Pruning</a>
                    <?php else: ?>
                        <form method="post" action="audit_log.php" class="audit-retention-prune-form" autocomplete="off"
                              data-confirm="Permanently prune <?php echo $preview_count; ?> audit entries? This cannot be undone.">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="prune">
                            <input type="hidden" name="retention_days" value="<?php echo (int) $retention_days; ?>">
                            <label for="prune-confirmation">Type <strong>PRUNE</strong> to confirm permanent deletion</label>
                            <div class="audit-retention-confirm-row">
                                <input type="text" id="prune-confirmation" name="prune_confirmation"
                                       pattern="PRUNE" autocomplete="off" autocapitalize="characters"
                                       spellcheck="false" required>
                                <button type="submit" class="delete-button">Prune <?php echo number_format($preview_count); ?> Entries</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="audit-retention-empty">Nothing qualifies for pruning with this retention period.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <div class="audit-controls">
        <form method="get" action="audit_log.php" class="list-search-form audit-filter-form" role="search">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <span class="audit-search-field">
                <label class="visually-hidden" for="audit-search">Search audit log</label>
                <span class="search-icon" aria-hidden="true">⌕</span>
                <input type="search" id="audit-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search audit log">
                <?php if ($search !== '' || $from_date !== '' || $to_date !== '' || $ip_filter !== ''): ?><a href="<?php echo htmlspecialchars(auditLogPageUrl(null, $category, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
            </span>
            <label class="visually-hidden" for="audit-from">From date</label>
            <input type="date" id="audit-from" name="from" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="visually-hidden" for="audit-to">To date</label>
            <input type="date" id="audit-to" name="to" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="visually-hidden" for="audit-ip">Exact IP address</label>
            <input type="text" id="audit-ip" name="ip" value="<?php echo htmlspecialchars($ip_filter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Exact IP">
            <button type="submit" class="filter-button">Apply</button>
        </form>
        <nav class="audit-filters" aria-label="Audit log filters">
            <a href="<?php echo htmlspecialchars(auditLogPageUrl(null, '', $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
               class="filter-button<?php echo $category === '' ? ' active' : ''; ?>">All</a>
            <?php foreach ($allowed_categories as $filter_category): ?>
                <a href="<?php echo htmlspecialchars(auditLogPageUrl(null, $filter_category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
                   class="filter-button<?php echo $category === $filter_category ? ' active' : ''; ?>">
                    <?php echo htmlspecialchars($category_labels[$filter_category], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>

    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context">Showing matching entries for “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>

    <div class="audit-table-wrapper">
        <table class="audit-table data-table">
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
                <?php if ($entries === []): ?>
                    <tr><td colspan="6" class="empty-state">No audit entries match the current view.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $entry): ?>
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
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($entries !== [] || $cursor !== null): ?>
        <nav class="pagination pagination-with-size" aria-label="Audit log pages">
            <div class="page-size-selector" aria-label="Audit log entries per page">
                <span class="page-size-label">Rows per page:</span>
                <?php foreach ($allowed_page_sizes as $allowed_page_size): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl(null, $category, $allowed_page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
                       class="filter-button page-size-button<?php echo $page_size === $allowed_page_size ? ' active' : ''; ?>"
                       <?php echo $page_size === $allowed_page_size ? 'aria-current="true"' : ''; ?>><?php echo $allowed_page_size; ?></a>
                <?php endforeach; ?>
            </div>
            <span class="pagination-status">Showing up to <?php echo $page_size; ?> entries</span>
            <div class="pagination-actions">
                <?php if ($cursor !== null): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl(null, $category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Newest</a>
                <?php endif; ?>
                <?php if ($next_cursor !== null): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl($next_cursor, $category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Older</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>

</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
