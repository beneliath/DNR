<?php
// Render the real organization and location controls with synthetic records.
require_once __DIR__ . '/../../src/functions.php';
set_include_path(__DIR__ . '/../../src' . PATH_SEPARATOR . get_include_path());

$source = file_get_contents(__DIR__ . '/../../src/index.php');
$organization_start = strpos($source, '<div class="organization-container">');
$organization_end = strpos($source, '</div>', $organization_start) + strlen('</div>');
$location_start = strpos($source, '<section class="form-section engagement-location-section">');
$location_end = strpos($source, '</section>', $location_start) + strlen('</section>');
$selected_engagement_organization_id = (int) ($argv[1] ?? 0);
$engagement_organization_options = [
    ['id' => 1, 'organization_name' => 'Chicago Organization'],
    ['id' => 2, 'organization_name' => 'Toronto Organization'],
    ['id' => 3, 'organization_name' => 'London Organization'],
    ['id' => 4, 'organization_name' => 'Organization Without an Address'],
    ['id' => 5, 'organization_name' => 'Archived Organization', 'is_deleted' => 1],
];
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('New Engagement', ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/index.min.css', 'assets/css/pages/engagement_form.min.css',
]]); ?>
<body>
<aside hidden id="app-sidebar" data-nav-preference-user="location-fixture"></aside>
<main class="container">
    <form id="new-engagement-form" class="engagement-form" data-recoverable-draft="index.php:new">
        <?php renderFormDraftPanel(); ?>
        <?php eval('?>' . substr($source, $organization_start, $organization_end - $organization_start)); ?>
        <?php eval('?>' . substr($source, $location_start, $location_end - $location_start)); ?>
        <button type="submit">Create Engagement</button>
    </form>
</main>
<script type="application/json" id="address-region-data"><?= json_encode(addressRegionClientData(), JSON_HEX_TAG) ?></script>
<?php renderScript('assets/js/page-actions.min.js', false); ?>
<?php renderScript('assets/js/relationship-search.min.js', false); ?>
</body>
</html>
