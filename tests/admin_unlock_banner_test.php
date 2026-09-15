<?php

require_once __DIR__ . '/../src/functions.php';

function renderAdminUnlockHeader(array $session): string {
    $_SESSION = $session;
    $_SERVER['PHP_SELF'] = 'dashboard.php';
    $request_reminder_counts = ['active' => 0];
    ob_start();
    include __DIR__ . '/../src/templates/header.php';
    return (string) ob_get_clean();
}

$admin = ['user_id' => 11, 'username' => 'unlocking-admin', 'role' => 'admin'];
$unlocked = $admin + ['_admin_elevated_at' => time()];
$cases = [
    'the administrator session that unlocked' => [$unlocked, true],
    'another administrator' => [['user_id' => 12, 'username' => 'other-admin', 'role' => 'admin'], false],
    'another session for the same administrator' => [$admin, false],
    'an editor with a stale elevation value' => [array_replace($unlocked, ['role' => 'editor']), false],
    'an administrator previewing a reviewer' => [array_replace($unlocked, ['role' => 'reviewer', 'authenticated_role' => 'admin']), false],
    'an expired unlock' => [array_replace($unlocked, ['_admin_elevated_at' => time() - 300]), false],
    'an anonymous visitor' => [[], false],
];

foreach ($cases as $description => [$session, $expected]) {
    $html = renderAdminUnlockHeader($session);
    if (str_contains($html, 'data-admin-unlock-link') !== (($session['role'] ?? '') === 'admin')) {
        throw new RuntimeException('Only administrators should have the proactive Admin Unlock navigation link.');
    }
    if (str_contains($html, 'data-admin-unlock ') !== $expected
        || str_contains($html, 'assets/js/admin-unlock.min.js') !== $expected
        || str_contains($html, 'data-admin-lock-form') !== $expected
    ) {
        fwrite(STDERR, "Incorrect admin unlock banner visibility for {$description}.\n");
        exit(1);
    }
}

$html = renderAdminUnlockHeader($unlocked);
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
$lockForm = $xpath->query('//section[@data-admin-unlock]/form[@data-admin-lock-form]')->item(0);
if (!$lockForm instanceof DOMElement || $lockForm->getAttribute('method') !== 'post'
    || $lockForm->getAttribute('action') !== 'admin_lock.php'
    || $xpath->query('.//input[@name="csrf_token" and string-length(@value) > 0]', $lockForm)->length !== 1
    || $xpath->query('.//button[@type="submit" and normalize-space(.)="Lock Admin Actions"]', $lockForm)->length !== 1
) {
    throw new RuntimeException('The unlock banner needs a CSRF-protected early-lock button.');
}

echo "Admin unlock banner session isolation tests passed.\n";
