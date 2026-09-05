<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';

function expectSessionLockPerformance(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Session lock performance test failed: {$message}\n");
        exit(1);
    }
}

$session_id = 'dnrlock' . bin2hex(random_bytes(8));
session_save_path(sys_get_temp_dir());
session_id($session_id);
expectSessionLockPerformance(session_start(), 'the test session should start.');
$_SESSION['readable_after_close'] = 'yes';
expectSessionLockPerformance(
    releaseApplicationSessionLock() && session_status() === PHP_SESSION_NONE,
    'the helper should persist the session and release its lock.'
);
expectSessionLockPerformance(
    ($_SESSION['readable_after_close'] ?? null) === 'yes',
    'closing the session should preserve request-local values for templates.'
);
expectSessionLockPerformance(session_start(), 'the persisted session should reopen.');
expectSessionLockPerformance(
    ($_SESSION['readable_after_close'] ?? null) === 'yes',
    'the released session should have been persisted.'
);
session_destroy();

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$read_only_routes = [
    'src/dashboard.php', 'src/organizations.php', 'src/engagements.php', 'src/tasks.php',
    'src/map.php',
    'src/map_geocode.php',
    'src/map_geocode_status.php',
    'src/presentation_asset.php',
    'src/contact_photo.php',
    'src/profile_picture.php',
    'src/download_engagement_pdf.php',
    'src/task_subject_search.php',
    'src/organization_contacts.php',
    'src/engagement_reschedule_options.php',
    'src/inbound_engagement_search.php',
];
foreach ($read_only_routes as $route) {
    $source = $read($route);
    expectSessionLockPerformance(
        strpos($source, 'releaseApplicationSessionLock();') > strpos($source, 'requireLogin();'),
        "{$route} should release its session only after authentication."
    );
}
$map_source = $read('src/map.php');
expectSessionLockPerformance(
    strpos($map_source, '$map_csrf_token = generateCsrfToken();')
        < strpos($map_source, 'releaseApplicationSessionLock();'),
    'the map should persist its CSRF token before releasing the session.'
);

echo "Session lock performance tests passed.\n";
