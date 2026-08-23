<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireAdmin();

$metric = static function (mysqli $connection, string $query): array {
    $result = $connection->query($query);
    if (!$result) {
        throw new RuntimeException('Unable to load operational metrics.');
    }
    return $result->fetch_assoc() ?: [];
};

try {
    $geocoding = $metric($conn, "SELECT
        SUM(status = 'pending') AS pending,
        SUM(status = 'processing') AS processing,
        SUM(status = 'retry') AS retry,
        COALESCE(MAX(attempts), 0) AS maximum_attempts
        FROM engagement_map_geocode_queue");
    $tasks = $metric($conn, "SELECT
        SUM(status IN ('open', 'in_progress', 'waiting')) AS active,
        SUM(status IN ('open', 'in_progress', 'waiting') AND due_date < CURDATE()) AS overdue,
        SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to IS NULL) AS unassigned
        FROM follow_up_tasks");
    $authentication = $metric($conn, "SELECT
        SUM(event_category = 'login' AND event_type <> 'successful_login') AS failed_last_24_hours
        FROM security_audit_log
        WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR");
    $inboundMail = $metric($conn, "SELECT
        SUM(status = 'review') AS review_count,
        SUM(status = 'failed') AS failed_count,
        SUM(status IN ('pending', 'processing')) AS queued_count
        FROM inbound_email_messages");
    $backup = $metric($conn, "SELECT created_at AS last_backup_at, details AS last_backup_details
        FROM security_audit_log
        WHERE event_type = 'database_backup_created'
        ORDER BY created_at DESC, id DESC LIMIT 1");
    $migration = $metric($conn, 'SELECT COUNT(*) AS applied, MAX(applied_at) AS last_applied_at FROM schema_migrations');
} catch (Throwable $exception) {
    applicationLog('error', 'Operational metrics failed', ['error' => $exception->getMessage()]);
    abortApplication(503, 'Operational metrics are temporarily unavailable.');
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Operations - MOED'); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <div class="page-heading"><div><h1>Operations</h1><p class="page-intro">Deployment readiness and workload signals.</p></div></div>
    <div class="summary-grid operations-summary-grid">
        <div class="summary-card"><span><small>Active tasks</small><strong><?php echo (int) ($tasks['active'] ?? 0); ?></strong></span></div>
        <div class="summary-card summary-danger"><span><small>Overdue tasks</small><strong><?php echo (int) ($tasks['overdue'] ?? 0); ?></strong></span></div>
        <div class="summary-card"><span><small>Unassigned tasks</small><strong><?php echo (int) ($tasks['unassigned'] ?? 0); ?></strong></span></div>
        <div class="summary-card"><span><small>Geocoding retries</small><strong><?php echo (int) ($geocoding['retry'] ?? 0); ?></strong></span></div>
        <div class="summary-card"><span><small>Inbound mail review</small><strong><?php echo (int) ($inboundMail['review_count'] ?? 0); ?></strong></span></div>
        <div class="summary-card summary-danger"><span><small>Inbound mail failures</small><strong><?php echo (int) ($inboundMail['failed_count'] ?? 0); ?></strong></span></div>
        <div class="summary-card"><span><small>Failed authentication, 24h</small><strong><?php echo (int) ($authentication['failed_last_24_hours'] ?? 0); ?></strong></span></div>
        <div class="summary-card"><span><small>Applied migrations</small><strong><?php echo (int) ($migration['applied'] ?? 0); ?></strong></span></div>
    </div>
    <section class="record-section">
            <h2>Deployment State</h2>
        <dl class="operations-details">
            <div><dt>Application version</dt><dd><?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Last migration</dt><dd><?php echo htmlspecialchars((string) ($migration['last_applied_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Last encrypted backup</dt><dd><?php echo htmlspecialchars((string) ($backup['last_backup_at'] ?? 'No recorded backup'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Last backup result</dt><dd><?php echo htmlspecialchars((string) ($backup['last_backup_details'] ?? 'No recorded backup'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Geocoding pending / processing</dt><dd><?php echo (int) ($geocoding['pending'] ?? 0); ?> / <?php echo (int) ($geocoding['processing'] ?? 0); ?></dd></div>
            <div><dt>Highest geocoding attempt count</dt><dd><?php echo (int) ($geocoding['maximum_attempts'] ?? 0); ?></dd></div>
            <div><dt>Inbound mail queued / review</dt><dd><?php echo (int) ($inboundMail['queued_count'] ?? 0); ?> / <?php echo (int) ($inboundMail['review_count'] ?? 0); ?></dd></div>
        </dl>
        <p><a href="ready.php" class="button-secondary">View readiness response</a></p>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
