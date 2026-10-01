<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_admin_helpers.php';
startSecureSession();
requireAdmin();
$conn = applicationDatabaseConnection();
$setup = reimbursementSetup($conn);
$error = '';
$saved = !empty($_SESSION['reimbursement_setup_saved']);
unset($_SESSION['reimbursement_setup_saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    require_once __DIR__ . '/two_factor_helpers.php';
    requireRecentAdminElevation('reimbursement_setup.php');
    $fields = [
        'organization_name' => 160,
        'bookkeeper_first_name' => 80,
        'bookkeeper_last_name' => 80,
        'bookkeeper_email' => 254,
        'bookkeeper_phone' => 64,
        'reviewer_email' => 254,
        'cc_email' => 254,
    ];
    $values = [];
    foreach ($fields as $field => $limit) {
        $value = $_POST[$field] ?? '';
        if (!is_string($value)) { $error = 'Enter valid text in each field.'; break; }
        $value = trim($value);
        if (mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $error = 'One or more fields contain unsupported characters or exceed their length limit.';
            break;
        }
        $values[$field] = $value;
    }
    $setup = array_merge($setup, $values);
    $phoneCountryCode = $_POST['bookkeeper_phone_country_code'] ?? applicationDefaultPhoneCountryCode();
    if ($error === '') {
        try {
            $setup['bookkeeper_phone'] = normalizePhoneNumber($phoneCountryCode, $setup['bookkeeper_phone'], 'Bookkeeper Phone Number');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
    foreach (['bookkeeper_email', 'reviewer_email', 'cc_email'] as $emailField) {
        if ($error === '' && $setup[$emailField] !== '' && !filter_var($setup[$emailField], FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address for each filled email field.';
        }
    }
    if ($error === '') {
        try {
            $conn->begin_transaction();
            $locked = $conn->query('SELECT * FROM reimbursement_setup WHERE id=1 FOR UPDATE')->fetch_assoc();
            if (!$locked) throw new InvalidArgumentException('Reimbursement setup is unavailable.');
            requireReimbursementAdminVersion($locked,$_POST['version'] ?? null);
            $conn->execute_query('UPDATE reimbursement_setup SET organization_name = ?, bookkeeper_first_name = ?,
                bookkeeper_last_name = ?, bookkeeper_email = ?, bookkeeper_phone = ?,
                reviewer_email = ?, cc_email = ?, version=version+1 WHERE id = 1',
                [$setup['organization_name'], $setup['bookkeeper_first_name'], $setup['bookkeeper_last_name'],
                 $setup['bookkeeper_email'], $setup['bookkeeper_phone'], $setup['reviewer_email'], $setup['cc_email']]);
            reimbursementEvent($conn,'setup',1,'updated','Global organization and recipient settings updated.');
            $conn->commit();
            $_SESSION['reimbursement_setup_saved'] = true;
            header('Location: reimbursement_setup.php', true, 303); exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            applicationLog('error', 'Unable to save reimbursement setup', ['error' => $exception->getMessage()]);
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to save reimbursement setup. Try again.';
        }
    }
}

[$phoneCountryCodeValue, $phoneLocalValue] = phoneNumberInputParts(
    $setup['bookkeeper_phone'],
    $phoneCountryCode ?? applicationDefaultPhoneCountryCode()
);

function reimbursementSetupH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Reimbursement Setup'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css']]); ?>
<body><?php include 'templates/header.php'; ?><main class="container reimbursement-page reimbursement-form-page">
<div class="page-heading"><div><h1>Reimbursement Setup</h1><p class="page-intro">Set the organization and bookkeeper details shown in downloaded reimbursement reports.</p></div></div>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= reimbursementSetupH($error) ?></p><?php endif; ?>
<?php if ($saved): ?><p class="success" role="status">Reimbursement setup saved.</p><?php endif; ?>
<section class="reimbursement-card"><form method="post" class="reimbursement-setup-form" data-admin-unlock-required><?= csrfInput() ?>
<input type="hidden" name="version" value="<?= reimbursementSetupH($_SERVER['REQUEST_METHOD'] === 'POST' ? (is_scalar($_POST['version'] ?? null) ? $_POST['version'] : 0) : $setup['version']) ?>">
<div class="reimbursement-form-grid">
<label class="reimbursement-setup-wide">Organization Name <input type="text" name="organization_name" autocomplete="organization" maxlength="160" value="<?= reimbursementSetupH($setup['organization_name']) ?>"></label>
<label>Bookkeeper First Name <input type="text" name="bookkeeper_first_name" autocomplete="given-name" maxlength="80" value="<?= reimbursementSetupH($setup['bookkeeper_first_name']) ?>"></label>
<label>Bookkeeper Last Name <input type="text" name="bookkeeper_last_name" autocomplete="family-name" maxlength="80" value="<?= reimbursementSetupH($setup['bookkeeper_last_name']) ?>"></label>
<label>Bookkeeper Email Address <input type="email" name="bookkeeper_email" autocomplete="email" maxlength="254" value="<?= reimbursementSetupH($setup['bookkeeper_email']) ?>"></label>
<div class="reimbursement-setup-phone"><label for="bookkeeper_phone">Bookkeeper Phone Number</label><div class="phone-input-group" data-phone-input-group><?= phoneCountryPicker('bookkeeper_phone_country_code', $phoneCountryCodeValue, 'Bookkeeper phone country code') ?><input type="tel" id="bookkeeper_phone" name="bookkeeper_phone" autocomplete="tel-national" inputmode="tel" maxlength="64" value="<?= reimbursementSetupH($phoneLocalValue) ?>" data-phone-number></div></div>
<label>Bcc Email Address <input type="email" name="reviewer_email" maxlength="254" value="<?= reimbursementSetupH($setup['reviewer_email']) ?>"></label>
<label>Cc Email Address <input type="email" name="cc_email" maxlength="254" value="<?= reimbursementSetupH($setup['cc_email']) ?>"></label>
</div>
<p class="field-help">Saved details apply to drafts and future submissions. Submitted packages retain their original content. The Catalog address is used only as Bcc and is never printed in the report.</p>
<div class="reimbursement-actions"><button type="submit" class="save-button">Save Reimbursement Setup</button></div>
</form></section>
</main><?php include 'templates/footer.php'; ?></body></html>
