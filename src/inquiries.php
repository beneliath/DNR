<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
startSecureSession();
requireLogin();

$userRole = (string) ($_SESSION['role'] ?? '');
$canManage = canManageBookingInquiries($userRole);
$currentUserId = (int) $_SESSION['user_id'];
$view = \Dnr\Http\RequestInput::enum(
    $_GET,
    'view',
    ['active', 'booked', 'declined', 'all'],
    'active'
);
$owner = \Dnr\Http\RequestInput::enum(
    $_GET,
    'owner',
    ['', 'me', 'unassigned', 'mine_or_unassigned'],
    ''
);
$priority = \Dnr\Http\RequestInput::enum(
    $_GET,
    'priority',
    array_merge([''], array_keys(bookingInquiryPriorities())),
    ''
);
$timing = \Dnr\Http\RequestInput::enum(
    $_GET,
    'timing',
    ['', 'overdue', 'today', 'next_7_days', 'unscheduled', 'missing_action'],
    ''
);
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 100);
$fulltext = fulltextSearchQuery($search);
if ($fulltext === '') {
    $search = '';
}
$businessTimezone = new DateTimeZone(applicationTimezoneName());
$monthStart = new DateTimeImmutable(substr(applicationBusinessDate(), 0, 7) . '-01 00:00:00', $businessTimezone);
$nextMonthStart = $monthStart->modify('+1 month');
$monthStartUtc = $monthStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$nextMonthStartUtc = $nextMonthStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $inquiryId = filter_input(INPUT_POST, 'inquiry_id', FILTER_VALIDATE_INT);
    try {
        changeBookingInquiryStage(
            $conn,
            (int) $inquiryId,
            (string) ($_POST['stage'] ?? ''),
            is_scalar($_POST['stage_reason'] ?? null) ? (string) $_POST['stage_reason'] : null,
            (string) ($_POST['inquiry_version'] ?? ''),
            $currentUserId,
            (string) $_SESSION['username']
        );
        $_SESSION['inquiry_pipeline_message'] = 'Inquiry stage updated.';
    } catch (Throwable $exception) {
        $_SESSION['inquiry_pipeline_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry stage could not be updated.';
    }
    $returnInput = [];
    parse_str(
        is_scalar($_POST['return_query'] ?? null) ? (string) $_POST['return_query'] : '',
        $returnInput
    );
    $returnQuery = http_build_query(array_filter([
        'view' => \Dnr\Http\RequestInput::enum(
            $returnInput, 'view', ['active', 'booked', 'declined', 'all'], 'active'
        ),
        'owner' => \Dnr\Http\RequestInput::enum(
            $returnInput,
            'owner',
            ['', 'me', 'unassigned', 'mine_or_unassigned'],
            ''
        ),
        'priority' => \Dnr\Http\RequestInput::enum(
            $returnInput,
            'priority',
            array_merge([''], array_keys(bookingInquiryPriorities())),
            ''
        ),
        'timing' => \Dnr\Http\RequestInput::enum(
            $returnInput,
            'timing',
            ['', 'overdue', 'today', 'next_7_days', 'unscheduled', 'missing_action'],
            ''
        ),
        'q' => \Dnr\Http\RequestInput::string($returnInput, 'q', '', 100),
    ], static fn($value): bool => $value !== ''));
    header('Location: inquiries.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
    exit();
}

$notice = (string) ($_SESSION['inquiry_pipeline_message'] ?? '');
$error = (string) ($_SESSION['inquiry_pipeline_error'] ?? '');
unset($_SESSION['inquiry_pipeline_message'], $_SESSION['inquiry_pipeline_error']);

$where = [];
$types = '';
$values = [];
if ($view === 'active') {
    $where[] = "(inquiry.stage IN ('new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent')
        OR (inquiry.stage = 'booked' AND inquiry.converted_at >= ? AND inquiry.converted_at < ?))";
    $types .= 'ss';
    $values[] = $monthStartUtc;
    $values[] = $nextMonthStartUtc;
} elseif ($view === 'booked') {
    $where[] = "inquiry.stage = 'booked'";
} elseif ($view === 'declined') {
    $where[] = "inquiry.stage = 'declined'";
}
if ($owner === 'me') {
    $where[] = 'inquiry.owner_user_id = ?';
    $types .= 'i';
    $values[] = $currentUserId;
} elseif ($owner === 'unassigned') {
    $where[] = 'inquiry.owner_user_id IS NULL';
} elseif ($owner === 'mine_or_unassigned') {
    $where[] = '(inquiry.owner_user_id = ? OR inquiry.owner_user_id IS NULL)';
    $types .= 'i';
    $values[] = $currentUserId;
}
if ($priority !== '') {
    $where[] = 'inquiry.priority = ?';
    $types .= 's';
    $values[] = $priority;
}
$businessDate = applicationBusinessDate();
if ($timing === 'overdue') {
    $where[] = 'inquiry.next_action_due_date < ?';
    $types .= 's';
    $values[] = $businessDate;
} elseif ($timing === 'today') {
    $where[] = 'inquiry.next_action_due_date = ?';
    $types .= 's';
    $values[] = $businessDate;
} elseif ($timing === 'next_7_days') {
    $where[] = 'inquiry.next_action_due_date BETWEEN ? AND ?';
    $types .= 'ss';
    $values[] = $businessDate;
    $values[] = applicationBusinessDateOffset(7);
} elseif ($timing === 'unscheduled') {
    $where[] = 'inquiry.next_action_due_date IS NULL';
} elseif ($timing === 'missing_action') {
    $where[] = "(inquiry.next_action IS NULL OR TRIM(inquiry.next_action) = '')";
}
if ($search !== '') {
    $where[] = "(
        MATCH(inquiry.title, inquiry.request_summary, inquiry.source_detail,
              inquiry.event_city, inquiry.event_state) AGAINST (? IN BOOLEAN MODE)
        OR organization.organization_name LIKE CONCAT('%', ?, '%')
        OR contact.contact_first_name LIKE CONCAT('%', ?, '%')
        OR contact.contact_last_name LIKE CONCAT('%', ?, '%')
    )";
    $types .= 'ssss';
    array_push($values, $fulltext, $search, $search, $search);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $conn->prepare(
    "SELECT inquiry.*, organization.organization_name,
            TRIM(CONCAT_WS(' ', contact.contact_first_name, contact.contact_last_name)) AS contact_name,
            owner.username AS owner_username,
            (SELECT COUNT(*) FROM follow_up_tasks task
             WHERE task.inquiry_id = inquiry.id
               AND task.status IN ('open', 'in_progress', 'waiting')) AS open_task_count,
            TIMESTAMPDIFF(DAY, inquiry.stage_changed_at, UTC_TIMESTAMP()) AS days_in_stage
     FROM booking_inquiries inquiry
     LEFT JOIN organizations organization ON organization.id = inquiry.organization_id
     LEFT JOIN contacts contact ON contact.id = inquiry.primary_contact_id
     LEFT JOIN users owner ON owner.id = inquiry.owner_user_id
     {$whereSql}
     ORDER BY
       FIELD(inquiry.stage, 'new', 'contacted', 'qualified', 'awaiting_details',
             'proposal_sent', 'booked', 'declined'),
       FIELD(inquiry.priority, 'urgent', 'high', 'normal', 'low'),
       COALESCE(inquiry.next_action_due_date, '9999-12-31'), inquiry.id DESC
     LIMIT 500"
);
if (!$stmt) {
    abortApplication(503, 'The booking pipeline is temporarily unavailable.');
}
if ($types !== '') {
    $bind = [$types];
    foreach ($values as &$value) {
        $bind[] = &$value;
    }
    unset($value);
    $stmt->bind_param(...$bind);
}
$stmt->execute();
$inquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (\Dnr\Http\RequestInput::enum($_GET, 'export', ['', 'csv'], '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="booking-pipeline-' . applicationBusinessDate() . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        abortApplication(503, 'The pipeline export could not be created.');
    }
    fputcsv($output, ['ID', 'Inquiry', 'Organization', 'Contact', 'Stage', 'Priority', 'Preferred dates', 'Next action', 'Due', 'Owner', 'Open tasks'], ',', '"', '');
    foreach ($inquiries as $inquiry) {
        $safe = static function (mixed $value): string {
            $text = trim((string) $value);
            return preg_match('/^[=+\-@]/', $text) === 1 ? "'" . $text : $text;
        };
        fputcsv($output, [
            (int) $inquiry['id'],
            $safe($inquiry['title']),
            $safe($inquiry['organization_name']),
            $safe($inquiry['contact_name']),
            bookingInquiryStages()[$inquiry['stage']],
            bookingInquiryPriorities()[$inquiry['priority']],
            bookingInquiryDateLabel($inquiry),
            $safe($inquiry['next_action']),
            $safe($inquiry['next_action_due_date']),
            $safe($inquiry['owner_username'] ?: 'Unassigned'),
            (int) $inquiry['open_task_count'],
        ], ',', '"', '');
    }
    fclose($output);
    exit();
}

$byStage = array_fill_keys(array_keys(bookingInquiryStages()), []);
foreach ($inquiries as $inquiry) {
    $boardStage = $view === 'active' && $inquiry['stage'] === 'qualified'
        ? 'awaiting_details'
        : (string) $inquiry['stage'];
    $byStage[$boardStage][] = $inquiry;
}
$displayCounts = array_map(
    static fn(array $stageInquiries): int => count($stageInquiries),
    $byStage
);
$displayStages = $view === 'active'
    ? ['new', 'contacted', 'awaiting_details', 'proposal_sent', 'booked']
    : ($view === 'booked' ? ['booked'] : ($view === 'declined' ? ['declined'] : array_keys(bookingInquiryStages())));
$stageIcons = [
    'new' => '<svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/></svg>',
    'contacted' => '<svg viewBox="0 0 24 24"><path d="M7 4h3l1.4 4-2 1.6a15 15 0 0 0 5 5l1.6-2L20 14v3c0 1.7-1.3 3-3 3C9.8 20 4 14.2 4 7c0-1.7 1.3-3 3-3Z"/></svg>',
    'qualified' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>',
    'awaiting_details' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'proposal_sent' => '<svg viewBox="0 0 24 24"><path d="m3 11 18-8-8 18-2-8-8-2Z"/><path d="m11 13 4-4"/></svg>',
    'booked' => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>',
    'declined' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>',
];
$returnQuery = http_build_query(array_filter([
    'view' => $view, 'owner' => $owner, 'priority' => $priority, 'timing' => $timing, 'q' => $search,
], static fn($value): bool => $value !== ''));
$exportQuery = http_build_query(array_filter([
    'view' => $view, 'owner' => $owner, 'priority' => $priority,
    'timing' => $timing, 'q' => $search, 'export' => 'csv',
], static fn($value): bool => $value !== ''));
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Booking Pipeline'), ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/booking_inquiries.min.css',
]]); ?>
<body class="inquiry-workflow-body inquiry-pipeline-body">
<?php include 'templates/header.php'; ?>
<main class="container inquiry-pipeline-page">
    <header class="page-heading inquiry-pipeline-heading">
        <div><h1>Booking Pipeline</h1><p class="page-intro">Capture and qualify opportunities before they become engagements.</p></div>
        <div class="page-heading-actions"><a href="inquiries.php?<?php echo htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary inquiry-export-action"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 3v12M8 7l4-4 4 4M5 13v7h14v-7"/></svg>Export</a><?php if ($canManage): ?><a href="add_inquiry.php" class="button-add inquiry-primary-action"><span class="inquiry-button-icon" aria-hidden="true">+</span>New Inquiry</a><?php endif; ?></div>
    </header>
    <?php if ($notice !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <form method="get" action="inquiries.php" class="inquiry-filter-bar" role="search" data-inquiry-filter>
        <label class="inquiry-search-field"><span class="visually-hidden">Search inquiries</span><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search inquiries, organizations, or contacts"></label>
        <div class="inquiry-filter-controls">
            <label><span class="visually-hidden">Owner</span><select name="owner" aria-label="Owner"><option value="">All</option><option value="mine_or_unassigned"<?php echo $owner === 'mine_or_unassigned' ? ' selected' : ''; ?>>Mine &amp; Unassigned</option><option value="me"<?php echo $owner === 'me' ? ' selected' : ''; ?>>My Inquiries</option><option value="unassigned"<?php echo $owner === 'unassigned' ? ' selected' : ''; ?>>Unassigned</option></select></label>
            <label><span class="visually-hidden">Target Date</span><select name="timing" aria-label="Target Date"><option value="">Target Date</option><option value="overdue"<?php echo $timing === 'overdue' ? ' selected' : ''; ?>>Overdue</option><option value="today"<?php echo $timing === 'today' ? ' selected' : ''; ?>>Due Today</option><option value="next_7_days"<?php echo $timing === 'next_7_days' ? ' selected' : ''; ?>>Next 7 Days</option><option value="unscheduled"<?php echo $timing === 'unscheduled' ? ' selected' : ''; ?>>No Target Date</option><option value="missing_action"<?php echo $timing === 'missing_action' ? ' selected' : ''; ?>>Missing Next Action</option></select></label>
            <label><span class="visually-hidden">Stage</span><select name="view" aria-label="Stage"><option value="active"<?php echo $view === 'active' ? ' selected' : ''; ?>>Stage</option><option value="booked"<?php echo $view === 'booked' ? ' selected' : ''; ?>>Booked</option><option value="declined"<?php echo $view === 'declined' ? ' selected' : ''; ?>>Declined</option><option value="all"<?php echo $view === 'all' ? ' selected' : ''; ?>>All Stages</option></select></label>
            <button type="submit" class="visually-hidden inquiry-filter-submit">Apply Filters</button>
            <?php if ($search !== '' || $owner !== '' || $priority !== '' || $timing !== '' || $view !== 'active'): ?><a href="inquiries.php" class="clear-search">Clear</a><?php endif; ?>
        </div>
    </form>

    <div class="inquiry-kanban inquiry-kanban-columns-<?php echo count($displayStages); ?>">
        <?php foreach ($displayStages as $stage): ?>
            <section class="inquiry-kanban-column inquiry-stage-<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>" aria-labelledby="stage-<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>">
                <header><div><span class="inquiry-stage-icon" aria-hidden="true"><?php echo $stageIcons[$stage]; ?></span><h2 id="stage-<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bookingInquiryStages()[$stage], ENT_QUOTES, 'UTF-8'); ?></h2><strong><?php echo $displayCounts[$stage]; ?></strong></div><?php if ($canManage): ?><a href="add_inquiry.php" aria-label="Add an inquiry to the pipeline">+</a><?php endif; ?></header>
                <div class="inquiry-card-list">
                    <?php foreach ($byStage[$stage] as $inquiry): ?>
                        <?php
                        $due = (string) ($inquiry['next_action_due_date'] ?? '');
                        $dueClass = $due !== '' && $due < applicationBusinessDate() ? ' is-overdue' : ($due === applicationBusinessDate() ? ' is-today' : '');
                        ?>
                        <article class="inquiry-card priority-<?php echo htmlspecialchars($inquiry['priority'], ENT_QUOTES, 'UTF-8'); ?>">
                            <h3><a href="view_inquiry.php?id=<?php echo (int) $inquiry['id']; ?>"><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['title']), ENT_QUOTES, 'UTF-8'); ?></a></h3>
                            <p class="inquiry-card-relationship"><?php echo htmlspecialchars((string) (bookingInquiryDisplayLabel($inquiry['organization_name']) ?: 'Organization not identified'), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($inquiry['contact_name'])): ?><span><?php echo htmlspecialchars($inquiry['contact_name'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></p>
                            <?php if (!empty($inquiry['preferred_start_date'])): ?><p class="inquiry-card-date"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg><?php echo htmlspecialchars(bookingInquiryDateLabel($inquiry), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                            <div class="inquiry-card-owner"><span class="inquiry-owner-avatar" aria-hidden="true"><?php echo htmlspecialchars(bookingInquiryInitials($inquiry['owner_username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars((string) ($inquiry['owner_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></span><small><?php echo (int) $inquiry['days_in_stage']; ?>d</small></div>
                            <footer><span class="inquiry-card-next<?php echo $stage === 'booked' ? '' : $dueClass; ?>"><?php if ($stage === 'booked'): ?>Booked<?php if (!empty($inquiry['converted_at'])): ?> <?php echo htmlspecialchars(bookingInquirySingleDateLabel(substr((string) $inquiry['converted_at'], 0, 10)), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?><?php else: ?>Next: <?php echo htmlspecialchars((string) ($inquiry['next_action'] ?: 'set next action'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span><span class="inquiry-priority"><?php echo htmlspecialchars(bookingInquiryPriorities()[$inquiry['priority']], ENT_QUOTES, 'UTF-8'); ?></span></footer>
                            <?php if ((int) $inquiry['open_task_count'] > 0): ?><a class="inquiry-card-task-count" href="tasks.php?view=all&amp;subject_type=inquiry&amp;subject_id=<?php echo (int) $inquiry['id']; ?>"><?php echo (int) $inquiry['open_task_count']; ?> open task<?php echo (int) $inquiry['open_task_count'] === 1 ? '' : 's'; ?></a><?php endif; ?>
                            <?php if ($canManage && $stage !== 'booked'): ?>
                                <details class="inquiry-card-stage-menu" data-disclosure-popover>
                                    <summary><span aria-hidden="true">•••</span><span class="visually-hidden">Move Stage</span></summary>
                                    <form method="post" action="inquiries.php" class="app-popover inquiry-stage-popover" aria-label="Move Inquiry Stage">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="inquiry_id" value="<?php echo (int) $inquiry['id']; ?>">
                                        <input type="hidden" name="inquiry_version" value="<?php echo htmlspecialchars($inquiry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="return_query" value="<?php echo htmlspecialchars($returnQuery, ENT_QUOTES, 'UTF-8'); ?>">
                                        <strong class="app-popover-title">Move Stage</strong>
                                        <label class="app-popover-field">
                                            <span>Stage</span>
                                            <select name="stage" required><option value="" selected disabled>Select Stage</option><?php foreach (bookingInquiryStages() as $key => $label): ?><?php if ($key === 'booked' || $key === $stage) continue; ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                                        </label>
                                        <label class="app-popover-field">
                                            <span>Reason <small>Required When Declining</small></span>
                                            <input name="stage_reason" maxlength="1000" placeholder="Add a reason">
                                        </label>
                                        <div class="app-popover-actions">
                                            <button type="button" class="button-secondary" data-disclosure-popover-close>Cancel</button>
                                            <button type="submit" class="save-button">Update Stage</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($byStage[$stage] === []): ?><p class="inquiry-column-empty">No inquiries in this stage.</p><?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
