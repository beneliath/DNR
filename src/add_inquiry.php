<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
require_once __DIR__ . '/inbound_email_helpers.php';
startSecureSession();
requireLogin();

$userRole = (string) ($_SESSION['role'] ?? '');
if (!canManageBookingInquiries($userRole)) {
    http_response_code(403);
    exit('Forbidden.');
}

$inbound_email_message_id = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'inbound_email_message_id'
);
$sourceMessage = null;
$sourceChron = null;
$defaults = [
    'event_type' => 'conference',
    'source' => 'other',
    'priority' => 'normal',
    'owner_user_id' => (int) $_SESSION['user_id'],
    'event_country' => applicationDefaultCountry(),
];
if ($inbound_email_message_id !== null) {
    $stmt = $conn->prepare('SELECT * FROM inbound_email_messages WHERE id = ?');
    $stmt->bind_param('i', $inbound_email_message_id);
    $stmt->execute();
    $sourceMessage = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$sourceMessage
        || !in_array((string) $sourceMessage['status'], ['pending', 'review', 'failed'], true)
    ) {
        $inbound_email_message_id = null;
        $sourceMessage = null;
    } else {
        $routing = routeInboundEmailMessage($conn, $sourceMessage);
        $contact = count($routing['contacts']) === 1 ? $routing['contacts'][0] : null;
        $organization = count($routing['organizations']) === 1
            ? $routing['organizations'][0]
            : null;
        $defaults = array_merge($defaults, [
            'title' => trim((string) ($sourceMessage['subject'] ?? '')) ?: 'Email inquiry',
            'request_summary' => trim((string) ($sourceMessage['body_text'] ?? '')),
            'source' => 'email',
            'source_detail' => (string) ($sourceMessage['sender_address'] ?? ''),
            'organization_id' => $organization['id'] ?? null,
            'primary_contact_id' => $contact['id'] ?? null,
            'next_action' => 'Review and respond to the inquiry',
            'next_action_due_date' => applicationBusinessDate(),
        ]);
        $sourceChron = formatInboundEmailChronEntry($sourceMessage);
    }
}

$error = '';
$inquiry_form_values = $defaults;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inquiry'])) {
    requireValidCsrfToken();
    $inquiry_form_values = array_merge($defaults, $_POST);
    try {
        $data = normalizeBookingInquiryInput($conn, $_POST);
        $inquiryId = createBookingInquiry(
            $conn,
            $data,
            (int) $_SESSION['user_id'],
            (string) $_SESSION['username'],
            $inbound_email_message_id,
            $sourceChron
        );
        $_SESSION['inquiry_action_message'] = $sourceMessage
            ? 'Inquiry created from inbound mail with the source correspondence preserved.'
            : 'Inquiry created.';
        header('Location: view_inquiry.php?id=' . $inquiryId);
        exit();
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry could not be saved. Please try again.';
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Unable to create booking inquiry', ['error' => $exception->getMessage()]);
        }
    }
}

$organizations = $conn->query(
    'SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name, id'
);
$inquiry_organizations = $organizations ? $organizations->fetch_all(MYSQLI_ASSOC) : [];
$contacts = $conn->query(
    "SELECT contact.id, contact.contact_first_name, contact.contact_last_name,
            organization.organization_name
     FROM contacts contact
     LEFT JOIN organizations organization ON organization.id = contact.organization_id
     WHERE contact.is_deleted = 0 AND (organization.id IS NULL OR organization.is_deleted = 0)
     ORDER BY contact.contact_last_name, contact.contact_first_name, contact.id"
);
$inquiry_contacts = $contacts ? $contacts->fetch_all(MYSQLI_ASSOC) : [];
$inquiry_owners = bookingInquiryOwners($conn);
$inquiry_form_action = 'add_inquiry.php' . ($inbound_email_message_id
    ? '?inbound_email_message_id=' . $inbound_email_message_id
    : '');
$inquiry_form_submit_label = 'Create Inquiry';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('New Inquiry'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/booking_inquiries.min.css',
    ],
]); ?>
<body class="inquiry-workflow-body inquiry-form-body">
<?php include 'templates/header.php'; ?>
<main class="container inquiry-form-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="inquiries.php">Booking Pipeline</a><span aria-hidden="true">/</span><span>New Inquiry</span></nav>
    <header class="page-heading inquiry-form-heading"><div><p class="eyebrow">Pre-engagement</p><h1>New Inquiry</h1><p class="page-intro">Capture the request, assign its next action, and qualify it before creating an engagement.</p></div></header>
    <?php if ($sourceMessage): ?><p class="inquiry-source-banner">Creating from inbound message #<?php echo (int) $sourceMessage['id']; ?>. Its retained content will become the first Chron entry.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php include 'templates/booking_inquiry_form.php'; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
