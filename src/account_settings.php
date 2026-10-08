<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';
startSecureSession();
requireAdmin();
if (!accountsEnabled()) { http_response_code(404); exit('Not found.'); }
header('Cache-Control: no-store');
$profile = currentAccountProfile();
$timezones = DateTimeZone::listIdentifiers();
$currentTimezone = applicationTimezoneName();
if (!in_array($currentTimezone, $timezones, true)) {
    $timezones[] = $currentTimezone;
    sort($timezones);
}
$fields = [
    'DNR_CALENDAR_NAME' => ['Calendar Name', applicationCalendarName()],
    'DNR_TIMEZONE' => ['Time Zone', applicationTimezoneName()],
    'DNR_DEFAULT_SPEAKER' => ['Default Speaker', applicationDefaultSpeaker()],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('account_settings.php');
    try {
        $name = trim(\Dnr\Http\RequestInput::string($_POST, 'name'));
        if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new InvalidArgumentException('Enter a valid Account name.');
        $settings = [];
        foreach ($fields as $key => [$label, $default]) {
            $value = trim(\Dnr\Http\RequestInput::string($_POST, $key));
            if (strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Enter a valid ' . $label . '.');
            $settings[$key] = $value;
        }
        if (!in_array($settings['DNR_TIMEZONE'], $timezones, true)) {
            throw new InvalidArgumentException('Select a supported time zone.');
        }
        deploymentConfig()->withAccountOverrides($settings);
        $conn->execute_query('UPDATE account_profile SET name = ?, settings = ?, version = version + 1 WHERE id = 1 AND version = ?',
            [$name, json_encode($settings, JSON_THROW_ON_ERROR), (int) ($_POST['version'] ?? 0)]);
        if ($conn->affected_rows !== 1) throw new InvalidArgumentException('Settings changed since you opened this page. Reload and try again.');
        logSecurityEvent($conn, 'account_settings_updated', (int) $_SESSION['user_id'], (int) $_SESSION['user_id']);
        flushAccountDirectory();
        header('Location: account_settings.php?saved=1'); exit;
    } catch (InvalidArgumentException $exception) { $error = $exception->getMessage(); }
    catch (Throwable $exception) { $error = 'Account settings could not be saved.'; }
}
?>
<!DOCTYPE html><html lang="en"><?php renderPageHead(applicationPageTitle('Account Settings')); ?>
<body><?php include 'templates/header.php'; ?><main class="container">
<h1>Account Settings</h1><p>These settings apply only to <?php echo htmlspecialchars($profile['name']); ?>.</p>
<?php if (isset($error)): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success">Account settings saved.</p><?php endif; ?>
<form method="post" action="account_settings.php" class="account-settings-form"><?php echo csrfInput(); ?><input type="hidden" name="version" value="<?php echo (int) $profile['version']; ?>">
<div class="form-group"><label for="name">Account Name</label><input type="text" id="name" name="name" maxlength="160" value="<?php echo htmlspecialchars($profile['name']); ?>" required></div>
<?php foreach ($fields as $key => [$label, $default]): $value = $profile['settings'][$key] ?? $default; ?>
<div class="form-group"><label for="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></label>
<?php if ($key === 'DNR_TIMEZONE'): ?>
<select id="<?php echo $key; ?>" name="<?php echo $key; ?>" required>
<?php foreach ($timezones as $timezone): ?>
<option value="<?php echo htmlspecialchars($timezone); ?>"<?php echo $value === $timezone ? ' selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $timezone)); ?></option>
<?php endforeach; ?>
</select>
<?php else: ?>
<input type="text" id="<?php echo $key; ?>" name="<?php echo $key; ?>" maxlength="255" value="<?php echo htmlspecialchars($value); ?>">
<?php endif; ?></div>
<?php endforeach; ?>
<button type="submit" class="save-button">Save Settings</button></form>
<p>Email is sent and received through moed@beneliath.com. Replies are linked to your records using their email routing markers.</p>
</main><?php include 'templates/footer.php'; ?></body></html>
