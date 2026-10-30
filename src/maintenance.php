<?php

declare(strict_types=1);

require_once __DIR__ . '/deployment_notice_helpers.php';

http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Retry-After: 30');
header('X-Content-Type-Options: nosniff');

$status = deploymentNoticeStatus();
$notice = $status['notice'];
$now = $status['server_now'];
$deploying = ($notice['phase'] ?? null) === 'deploying';
$failed = ($notice['phase'] ?? null) === 'failed';
$expected = $deploying && isset($notice['expected_back_at']) ? (float) $notice['expected_back_at'] : null;
$remaining = $expected === null ? null : max(0, (int) ceil($expected - $now));
$timeLabel = $expected === null ? null : (new DateTimeImmutable('@' . (int) $expected))
    ->setTimezone(new DateTimeZone('America/Chicago'))->format('g:i A T');
$illustration = (string) file_get_contents('/opt/dnr/account-assets/maintenance-illustration.svg');
$forwardedPrefix = $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '';
$assetPrefix = is_string($forwardedPrefix) && preg_match('/\A\/a\/[a-z][a-z0-9-]{2,63}\z/', $forwardedPrefix) === 1
    ? $forwardedPrefix : '';
$timerVersion = substr(hash_file('sha256', __DIR__ . '/assets/js/maintenance-timer.js'), 0, 12);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101a24">
    <title>Under maintenance</title>
    <style>
        :root { color-scheme: dark; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #101a24; color: #f2f6f9; }
        main { box-sizing: border-box; width: min(100% - 2rem, 38rem); padding: clamp(1.5rem, 5vw, 3rem); background: #1d2b39; border-radius: 1rem; }
        .maintenance-banner { display: block; width: 100%; }
        .maintenance-banner svg { display: block; width: 100%; height: auto; border-radius: .5rem; }
        h1 { margin: 1rem 0; font-size: clamp(1.8rem, 5vw, 2.5rem); line-height: 1.15; }
        p { line-height: 1.6; }
        .timer { margin: 1.5rem 0 .25rem; color: #8ed0ff; font-size: clamp(2rem, 8vw, 3.5rem); font-variant-numeric: tabular-nums; font-weight: 750; }
        .timer-expired { color: #ff6767; animation: timer-flash 1.2s ease-in-out infinite; }
        @keyframes timer-flash { 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .timer-expired { animation: none; } }
        .detail { color: #c1cfda; }
    </style>
</head>
<body>
<main aria-labelledby="heading" data-maintenance-page
      data-status-url="<?= htmlspecialchars($assetPrefix . '/deployment_status.php', ENT_QUOTES, 'UTF-8') ?>"
      data-ready-url="<?= htmlspecialchars($assetPrefix . '/ready.php', ENT_QUOTES, 'UTF-8') ?>"
      data-wait-for-deployment="<?= $deploying || $failed ? 'true' : 'false' ?>">
    <div class="maintenance-banner" role="img" aria-label="Clock and workspace illustration"><?= $illustration ?></div>
    <h1 id="heading"><?= $failed ? 'Maintenance is taking longer than expected' : 'Temporarily unavailable' ?></h1>
    <?php if ($deploying): ?>
        <p>Updates are underway. This page reopens automatically.</p>
        <?php if ($remaining !== null): ?>
            <div class="timer<?= $remaining === 0 ? ' timer-expired' : '' ?>" role="timer" aria-label="<?= $remaining === 0 ? 'Estimated return time has passed' : 'Estimated time until service returns' ?>" data-maintenance-timer data-remaining-seconds="<?= $remaining ?>"><?= sprintf('%d:%02d:%02d', intdiv($remaining, 3600), intdiv($remaining % 3600, 60), $remaining % 60) ?></div>
            <p class="detail" data-maintenance-estimate><?= $remaining === 0 ? 'The estimate has passed. Please check back soon.' : 'Estimated return: ' . htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <p class="detail">The estimate has passed. Please check back soon.</p>
        <?php endif; ?>
    <?php elseif ($failed): ?>
        <p>We are restoring service. This page reopens automatically.</p>
    <?php else: ?>
        <p>Please check back soon. This page reopens automatically.</p>
    <?php endif; ?>
    <noscript><p class="detail">Enable JavaScript to reopen automatically, or refresh this page to try again.</p></noscript>
</main>
<script defer src="<?= htmlspecialchars($assetPrefix . '/assets/js/maintenance-timer.js?v=' . $timerVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
