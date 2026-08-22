<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireAdmin();
requireAuditLogSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

$allowed_categories = ['login', 'database_change', 'security'];
$allowed_page_sizes = [20, 50, 100];
$category = $_GET['category'] ?? '';
if (!in_array($category, $allowed_categories, true)) {
    $category = '';
}
$search = trim(substr((string) ($_GET['q'] ?? ''), 0, 256));
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$from_date = validIsoDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to_date = validIsoDate($_GET['to'] ?? '') ? $_GET['to'] : '';
$ip_filter = filter_var($_GET['ip'] ?? '', FILTER_VALIDATE_IP) ?: '';

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
$where_clause = $where_parts ? ' WHERE ' . implode(' AND ', $where_parts) : '';

$count_stmt = $conn->prepare(
    "SELECT COUNT(*) AS entry_count FROM security_audit_log{$where_clause}"
);
if (!$count_stmt) {
    abortApplication(503, 'The audit log is temporarily unavailable.', ['error' => $conn->error]);
}
if ($filter_types !== '') {
    $count_bind = [$filter_types];
    foreach ($filter_values as &$filter_value) $count_bind[] = &$filter_value;
    unset($filter_value);
    $count_stmt->bind_param(...$count_bind);
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
    abortApplication(503, 'The audit log is temporarily unavailable.', ['error' => $conn->error]);
}
$entry_types = $filter_types . 'ii';
$entry_values = array_merge($filter_values, [$page_size, $offset]);
$entry_bind = [$entry_types];
foreach ($entry_values as &$entry_value) $entry_bind[] = &$entry_value;
unset($entry_value);
$entries_stmt->bind_param(...$entry_bind);
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
    'database_backup_created' => 'Database backup created',
    'database_backup_auth_failed' => 'Database backup verification failed',
    'database_restored' => 'Database restored',
    'database_restore_auth_failed' => 'Database restore verification failed',
    'audit_log_purged' => 'Audit log purged',
    'calendar_subscription_created' => 'Calendar subscription created',
    'calendar_subscription_revoked' => 'Calendar subscription revoked',
    'calendar_subscriptions_purged' => 'Revoked calendar subscriptions purged',
];
$audit_timezone_name = getenv('DNR_TIMEZONE') ?: 'America/Chicago';
try {
    $audit_timezone = new DateTimeZone($audit_timezone_name);
} catch (Throwable $exception) {
    $audit_timezone_name = 'UTC';
    $audit_timezone = new DateTimeZone('UTC');
}

function auditLogPageUrl($page, $category, $page_size, $search = '', $from = '', $to = '', $ip = '') {
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
<?php renderPageHead('Audit Log - DNR', array (
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
    <p class="page-intro">Audit records are append-only to the web application. Retention or emergency purge operations require the database-container maintenance command.</p>

    <div class="audit-controls">
        <form method="get" action="audit_log.php" class="list-search-form audit-filter-form" role="search">
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <span class="audit-search-field">
                <label class="visually-hidden" for="audit-search">Search audit log</label>
                <span class="search-icon" aria-hidden="true">⌕</span>
                <input type="search" id="audit-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search audit log">
                <?php if ($search !== '' || $from_date !== '' || $to_date !== '' || $ip_filter !== ''): ?><a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $category, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
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
            <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, '', $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
               class="filter-button<?php echo $category === '' ? ' active' : ''; ?>">All</a>
            <?php foreach ($allowed_categories as $filter_category): ?>
                <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $filter_category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
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
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl(1, $category, $allowed_page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>"
                       class="filter-button page-size-button<?php echo $page_size === $allowed_page_size ? ' active' : ''; ?>"
                       <?php echo $page_size === $allowed_page_size ? 'aria-current="true"' : ''; ?>><?php echo $allowed_page_size; ?></a>
                <?php endforeach; ?>
            </div>
            <span class="pagination-status">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> · <?php echo $total_entries; ?> entries</span>
            <div class="pagination-actions">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl($current_page - 1, $category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Previous</a>
                <?php endif; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo htmlspecialchars(auditLogPageUrl($current_page + 1, $category, $page_size, $search, $from_date, $to_date, $ip_filter), ENT_QUOTES, 'UTF-8'); ?>" class="filter-button">Next</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>

</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
