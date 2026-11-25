<?php
// Render the real inquiry form with fictional data and no database writes.
require __DIR__ . '/../../src/functions.php';
require __DIR__ . '/../../src/booking_inquiry_helpers.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['user_id'] = 42;
$inquiry_id = ($argv[1] ?? '') === 'edit' ? 7 : null;
$inquiry_form_action = $inquiry_id ? 'edit_inquiry.php?id=7' : 'add_inquiry.php';
$inquiry_form_values = ['title' => 'Current inquiry', 'request_summary' => 'Current summary',
    'organization_id' => '1', 'updated_at' => $inquiry_id ? ($argv[2] ?? 'version-1') : ''];
$inquiry_organizations = [
    ['id' => 1, 'organization_name' => 'Original organization'],
    ['id' => 2, 'organization_name' => 'Draft organization'],
    ['id' => 3, 'organization_name' => 'Newly created organization'],
];
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('Inquiry Drafts', ['styles' => ['assets/css/style.min.css',
    'assets/css/modern.min.css', 'assets/css/pages/booking_inquiries.min.css']]); ?>
<body class="inquiry-form-body">
<aside hidden id="app-sidebar" data-nav-preference-user="draft-fixture"></aside>
<main class="container inquiry-form-page">
    <?php include __DIR__ . '/../../src/templates/booking_inquiry_form.php'; ?>
</main>
<?php renderScript('assets/js/inquiry-workflow.min.js', false); ?>
</body>
</html>
