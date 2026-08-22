<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Only admins and editors can edit organizations
if ($user_role !== 'admin' && $user_role !== 'editor') {
    header("Location: organizations.php");
    exit();
}

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: organizations.php");
    exit();
}

$org_id = intval($_GET['id']);
$from = $_GET['from'] ?? '';
if ($from === 'view') {
    $cancel_url = "view_organization.php?id=$org_id";
} else {
    $cancel_url = "organizations.php";
}

// Fetch organization details
$query = "SELECT * FROM organizations WHERE id = ? AND is_deleted = 0";

$stmt = $conn->prepare($query);
if ($stmt === false) abortApplication(503, 'The organization is temporarily unavailable.', ['error' => $conn->error]);

$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: organizations.php");
    exit();
}

$organization = $result->fetch_assoc();
$address_pairs = [
    ['mailing_address_line_1', 'physical_address_line_1'],
    ['mailing_address_line_2', 'physical_address_line_2'],
    ['mailing_city', 'physical_city'],
    ['mailing_state', 'physical_state'],
    ['mailing_zipcode', 'physical_zipcode'],
    ['mailing_country', 'physical_country'],
];
$same_address = true;
foreach ($address_pairs as [$mailing_field, $physical_field]) {
    if ((string) $organization[$mailing_field] !== (string) $organization[$physical_field]) {
        $same_address = false;
        break;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $normalized = \Dnr\Domain\OrganizationInput::normalize($_POST);
    $organization_input = $normalized['data'];
    $errorMessages = $normalized['errors'];
    foreach ($organization_input as $field_name => $field_value) {
        ${$field_name} = $field_value;
    }
    $same_address = (bool) $organization_input['same_address'];
    $submitted_version = is_scalar($_POST['organization_version'] ?? null)
        ? trim((string) $_POST['organization_version'])
        : '';

    if (!$errorMessages) {
        $conn->begin_transaction();
        try {
            $lock_stmt = $conn->prepare(
                'SELECT updated_at FROM organizations WHERE id = ? AND is_deleted = 0 FOR UPDATE'
            );
            if (!$lock_stmt) throw new RuntimeException('Unable to lock the organization.');
            $lock_stmt->bind_param('i', $org_id);
            $lock_stmt->execute();
            $locked_organization = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();
            if (!$locked_organization) throw new InvalidArgumentException('That organization is no longer active.');
            if ($submitted_version === ''
                || !hash_equals((string) $locked_organization['updated_at'], $submitted_version)
            ) {
                throw new InvalidArgumentException(
                    'This organization changed after you opened it. Reload the page before saving so newer changes are not overwritten.'
                );
            }

            $check_stmt = $conn->prepare('SELECT id FROM organizations WHERE organization_name = ? AND id != ?');
            if (!$check_stmt) throw new RuntimeException('Unable to check the organization name.');
            $check_stmt->bind_param('si', $organization_name, $org_id);
            $check_stmt->execute();
            $duplicate = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();
            if ($duplicate) throw new InvalidArgumentException('An organization with this name already exists.');

        $update_stmt = $conn->prepare(
            "UPDATE organizations SET
                organization_name = ?, notes = ?, affiliation = ?, distinctives = ?, website_url = ?,
                phone = ?, fax = ?, mailing_address_line_1 = ?, mailing_address_line_2 = ?,
                mailing_city = ?, mailing_state = ?, mailing_zipcode = ?, mailing_country = ?,
                physical_address_line_1 = ?, physical_address_line_2 = ?, physical_city = ?,
                physical_state = ?, physical_zipcode = ?, physical_country = ?
             WHERE id = ? AND is_deleted = 0"
        );
        $update_stmt->bind_param(
            "sssssssssssssssssssi",
            $organization_name, $notes, $affiliation, $distinctives, $website_url, $phone, $fax,
            $mailing_address_line_1, $mailing_address_line_2, $mailing_city, $mailing_state,
            $mailing_zipcode, $mailing_country, $physical_address_line_1, $physical_address_line_2,
            $physical_city, $physical_state, $physical_zipcode, $physical_country, $org_id
        );

        if ($update_stmt->execute() && $update_stmt->affected_rows <= 1) {
            $update_stmt->close();
            $conn->commit();
            $_SESSION['success_message'] = "Organization updated successfully.";
            header("Location: view_organization.php?id=$org_id");
            exit();
        }
        throw new RuntimeException('Unable to update the organization.');
        } catch (Throwable $exception) {
            $conn->rollback();
            if (!$exception instanceof InvalidArgumentException) {
                applicationLog('error', 'Organization update failed', [
                    'organization_id' => $org_id,
                    'error' => $exception->getMessage(),
                ]);
            }
            $errorMessages[] = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to update the organization.';
        }
    }

    if ($errorMessages) {
        foreach ([
            'organization_name', 'notes', 'affiliation', 'distinctives', 'website_url',
            'phone', 'fax', 'mailing_address_line_1', 'mailing_address_line_2',
            'mailing_city', 'mailing_state', 'mailing_zipcode', 'mailing_country',
            'physical_address_line_1', 'physical_address_line_2', 'physical_city',
            'physical_state', 'physical_zipcode', 'physical_country',
        ] as $field_name) {
            $organization[$field_name] = ${$field_name};
        }
        $organization['updated_at'] = $submitted_version;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone_country_code_value = trim($_POST['phone_country_code'] ?? '+1');
    [, $phone_local_value] = phoneNumberInputParts($_POST['phone'] ?? '', $phone_country_code_value);
    $fax_country_code_value = trim($_POST['fax_country_code'] ?? '+1');
    [, $fax_local_value] = phoneNumberInputParts($_POST['fax'] ?? '', $fax_country_code_value);
} else {
    [$phone_country_code_value, $phone_local_value] = phoneNumberInputParts($organization['phone'] ?? '');
    [$fax_country_code_value, $fax_local_value] = phoneNumberInputParts($organization['fax'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Edit Organization - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/edit_organization.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (!empty($errorMessages)): ?>
        <p class="error"><?php echo implode('<br>', array_map(
            fn($message) => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            $errorMessages
        )); ?></p>
    <?php endif; ?>

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php">Organizations</a><span aria-hidden="true">/</span><span>Edit Organization</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Organization</h1><p class="page-intro">Update organization information and addresses.</p></div></div>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $org_id); ?>" class="organization-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="organization_version" value="<?php echo htmlspecialchars((string) $organization['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
            <label class="required">Organization Name</label>
            <input type="text" name="organization_name" required value="<?php echo htmlspecialchars($organization['organization_name']); ?>">
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="4"><?php echo htmlspecialchars($organization['notes']); ?></textarea>
        </div>

        <div class="form-group">
            <label>Affiliation</label>
            <input type="text" name="affiliation" value="<?php echo htmlspecialchars($organization['affiliation']); ?>">
        </div>

        <div class="form-group">
            <label>Distinctives</label>
            <input type="text" name="distinctives" value="<?php echo htmlspecialchars($organization['distinctives']); ?>">
        </div>

        <div class="form-group">
            <label>Website URL</label>
            <input type="url" name="website_url" value="<?php echo htmlspecialchars($organization['website_url']); ?>">
        </div>

        <div class="form-group">
            <label>Phone</label>
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('phone_country_code', $phone_country_code_value, 'Organization phone country code'); ?>
                <input type="tel" name="phone" value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
            </div>
        </div>

        <div class="form-group">
            <label>Fax</label>
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('fax_country_code', $fax_country_code_value, 'Organization fax country code'); ?>
                <input type="tel" name="fax" value="<?php echo htmlspecialchars($fax_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" inputmode="tel" data-phone-number>
            </div>
        </div>

        <div class="radio-group">
            <label class="required">Mailing and Physical Address the Same</label>
            <div>
                <label><input type="radio" name="same_address" value="yes" <?php echo $same_address ? 'checked' : ''; ?>> Yes</label>
                <label><input type="radio" name="same_address" value="no" <?php echo !$same_address ? 'checked' : ''; ?>> No</label>
            </div>
        </div>

        <div class="address-section" id="physical_address_section">
            <h3 class="required">Physical Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($organization['physical_address_line_1']); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($organization['physical_address_line_2']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_city" placeholder="City" value="<?php echo htmlspecialchars($organization['physical_city']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_state" placeholder="State/Province" value="<?php echo htmlspecialchars($organization['physical_state']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($organization['physical_zipcode']); ?>">
                </div>
                <div class="address-full-width">
                    <select name="physical_country">
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo $organization['physical_country'] === 'USA' ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo $organization['physical_country'] === 'CAN' ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="address-section" id="mailing_address_section">
            <h3>Mailing Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($organization['mailing_address_line_1']); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($organization['mailing_address_line_2']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_city" placeholder="City" value="<?php echo htmlspecialchars($organization['mailing_city']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_state" placeholder="State/Province" value="<?php echo htmlspecialchars($organization['mailing_state']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($organization['mailing_zipcode']); ?>">
                </div>
                <div class="address-full-width">
                    <select name="mailing_country">
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo $organization['mailing_country'] === 'USA' ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo $organization['mailing_country'] === 'CAN' ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="<?php echo $cancel_url; ?>" class="action-button back-button cancel-button">Cancel</a>
            <input type="submit" value="Save Changes" class="action-button save-button">
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
