<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
require_once __DIR__ . '/inquiry_relationship_helpers.php';
startSecureSession();
requireLogin();

if (!canManageBookingInquiries((string) ($_SESSION['role'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden.');
}
$inquiry_id = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
$inquiry = $inquiry_id ? fetchBookingInquiry($conn, $inquiry_id) : null;
if (!$inquiry) {
    header('Location: inquiries.php');
    exit();
}
if ($inquiry['stage'] === 'booked' || !empty($inquiry['archived_at'])) {
    header('Location: view_inquiry.php?id=' . $inquiry_id);
    exit();
}
$error = '';
$inquiry_form_values = $inquiry;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_inquiry'])) {
    requireValidCsrfToken();
    $inquiry_form_values = array_merge($inquiry, $_POST);
    $inquiry_form_values['updated_at'] = is_scalar($_POST['inquiry_version'] ?? null)
        ? (string) $_POST['inquiry_version'] : '';
    try {
        $data = normalizeBookingInquiryInput($conn, $_POST);
        updateBookingInquiry(
            $conn,
            $inquiry_id,
            $data,
            (string) ($_POST['inquiry_version'] ?? '')
        );
        $_SESSION['inquiry_action_message'] = 'Inquiry details updated.';
        header('Location: view_inquiry.php?id=' . $inquiry_id);
        exit();
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry could not be updated. Please try again.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_inquiry_relationships'])) {
    requireValidCsrfToken();
    $inquiry_form_values = array_merge($inquiry_form_values, $_POST);
    $inquiry_form_values['updated_at'] = is_scalar($_POST['inquiry_version'] ?? null)
        ? (string) $_POST['inquiry_version'] : '';
}
$relationship_options = inquiryRelationshipFormData($conn, $inquiry_form_values, $_GET);
$inquiry_organizations = $relationship_options['organizations'];
$inquiry_contacts = $relationship_options['contacts'];
$inquiry_owners = bookingInquiryOwners($conn);
$inquiry_form_action = 'edit_inquiry.php?id=' . $inquiry_id;
$inquiry_form_submit_label = 'Save Changes';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Edit Inquiry'), ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/booking_inquiries.min.css',
]]); ?>
<body class="inquiry-workflow-body inquiry-form-body">
<?php include 'templates/header.php'; ?>
<main class="container inquiry-form-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="inquiries.php">Booking Pipeline</a><span aria-hidden="true">/</span><a href="view_inquiry.php?id=<?php echo $inquiry_id; ?>">Inquiry</a><span aria-hidden="true">/</span><span>Edit</span></nav>
    <header class="page-heading inquiry-form-heading"><div><p class="eyebrow">Inquiry #<?php echo $inquiry_id; ?></p><h1>Edit Inquiry</h1><p class="page-intro">Keep the request and its next action current.</p></div></header>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php include 'templates/booking_inquiry_form.php'; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
