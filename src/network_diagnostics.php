<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/network_diagnostics_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$resetError = '';
if ($method === 'POST') {
    requireValidCsrfToken();
    if (\Dnr\Http\RequestInput::string($_POST, 'action') !== 'reset_statistics') {
        http_response_code(400);
        exit('Select a valid reset action.');
    }
    requireRecentAdminElevation('network_diagnostics.php');
    try {
        resetNetworkPerformanceStatistics($conn, (int) $_SESSION['user_id']);
        $_SESSION['_network_statistics_reset'] = true;
        header('Location: network_diagnostics.php', true, 303);
        exit;
    } catch (Throwable $exception) {
        http_response_code(500);
        $resetError = 'Unable to reset network statistics. Try again.';
        applicationLog('error', 'Network statistics reset failed', ['error' => $exception->getMessage()]);
    }
}
$resetSucceeded = !empty($_SESSION['_network_statistics_reset']);
unset($_SESSION['_network_statistics_reset']);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Remote Network Performance'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/network_diagnostics.min.css',
    ],
    'scripts' => ['assets/js/network-diagnostics.min.js'],
]); ?>
<body class="network-diagnostics-body">
<?php include 'templates/header.php'; ?>
<main class="container network-diagnostics-page" data-network-diagnostics data-summary-url="network_performance.php">
    <div class="page-heading network-diagnostics-heading">
        <div>
            <h1>Remote Network Performance</h1>
            <p class="page-intro">Compare actual MOED page loads and document responses from public IPv4 and IPv6 clients during the last 24 hours.</p>
        </div>
        <div class="network-diagnostics-actions">
            <button type="button" class="button-add" data-network-refresh>Refresh</button>
            <?php if (hasRecentAdminElevation()): ?>
                <form method="post" action="network_diagnostics.php" class="network-statistics-reset-form" data-admin-unlock-required data-confirm="Clear all recorded IPv4 and IPv6 traffic statistics, including page, image, and document timings? This cannot be undone. New traffic will start counting from zero.">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="reset_statistics">
                    <button type="submit" class="button-secondary statistics-reset-button">Reset Statistics</button>
                </form>
            <?php else: ?>
                <a href="admin_elevation.php?return=network_diagnostics.php" class="button-secondary statistics-reset-button">Reset Statistics</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($resetSucceeded): ?>
        <p class="success" role="status">Network traffic statistics cleared. New IPv4 and IPv6 measurements will appear as traffic arrives.</p>
    <?php endif; ?>
    <?php if ($resetError !== ''): ?>
        <p class="error" role="alert"><?php echo htmlspecialchars($resetError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <section class="network-diagnostics-summary" aria-labelledby="network-result-heading" data-network-summary data-state="pending">
        <div>
            <p class="network-eyebrow">Remote assessment</p>
            <h2 id="network-result-heading" data-network-summary-title>Loading Remote Measurements…</h2>
            <p data-network-summary-detail>Local and private-address sessions are excluded.</p>
        </div>
        <span class="network-status-pill" data-network-status role="status" aria-live="polite">Loading</span>
    </section>

    <div class="network-metric-grid" aria-live="polite" aria-busy="true" data-network-results>
        <?php foreach (['IPv4', 'IPv6'] as $family): ?>
        <article class="network-metric-card" data-network-card="<?php echo strtolower($family); ?>" data-state="pending">
            <div class="network-card-heading">
                <div><p class="network-eyebrow">Public client route</p><h2><?php echo $family; ?></h2></div>
                <span class="network-path-state" data-network-state="<?php echo strtolower($family); ?>">Loading</span>
            </div>
            <p class="network-latency"><strong data-network-load="<?php echo strtolower($family); ?>">—</strong><span> ms median load</span></p>
            <dl class="network-route-details">
                <div><dt>Remote samples</dt><dd data-network-samples="<?php echo strtolower($family); ?>">0</dd></div>
                <div><dt>75th percentile</dt><dd data-network-p75="<?php echo strtolower($family); ?>">—</dd></div>
                <div><dt>Median TTFB</dt><dd data-network-ttfb="<?php echo strtolower($family); ?>">—</dd></div>
                <div><dt>Most-seen edge</dt><dd data-network-colo="<?php echo strtolower($family); ?>">—</dd></div>
            </dl>
        </article>
        <?php endforeach; ?>
    </div>

    <section class="network-contact-card" aria-labelledby="contact-image-heading">
        <div>
            <p class="network-eyebrow">Contact images</p>
            <h2 id="contact-image-heading">Slowest Contact Image Per Page Load</h2>
            <p>Median duration among remote pages that loaded at least one contact photo.</p>
        </div>
        <dl class="network-contact-comparison">
            <div><dt>IPv4</dt><dd><strong data-contact-images="ipv4">—</strong><span data-contact-samples="ipv4">No samples</span></dd></div>
            <div><dt>IPv6</dt><dd><strong data-contact-images="ipv6">—</strong><span data-contact-samples="ipv6">No samples</span></dd></div>
        </dl>
    </section>

    <section class="network-page-breakdown network-document-breakdown" aria-labelledby="document-download-heading">
        <div class="network-section-heading">
            <div><p class="network-eyebrow">PDF and PowerPoint downloads</p><h2 id="document-download-heading">Remote Document Responses</h2></div>
        </div>
        <p>Server timings for PDF, PPT, and PPTX responses, including inline views and byte-range requests. Duration ends when PHP finishes handling the response; it does not measure when the browser finishes downloading. Response size is the advertised size, including partial responses.</p>
        <div class="responsive-table">
            <table class="data-table">
                <thead><tr>
                    <th scope="col">Format / route</th>
                    <th scope="col">Median duration</th>
                    <th scope="col">75th percentile</th>
                    <th scope="col">Median time to headers</th>
                    <th scope="col">Median response size</th>
                    <th scope="col">Samples (partial)</th>
                    <th scope="col">Most-seen edge</th>
                </tr></thead>
                <tbody data-network-downloads><tr><td colspan="7">Loading document measurements…</td></tr></tbody>
            </table>
        </div>
        <p>Cached browser or edge responses that do not reach PHP are excluded. Time to headers includes server work and output buffering, without client network latency. Header timings are unavailable when output stays buffered until shutdown.</p>
    </section>

    <section class="network-page-breakdown" aria-labelledby="slow-page-heading">
        <div class="network-section-heading">
            <div><p class="network-eyebrow">Page detail</p><h2 id="slow-page-heading">Slowest Observed Pages</h2></div>
            <p data-network-updated>Waiting for data…</p>
        </div>
        <div class="responsive-table">
            <table class="data-table">
                <colgroup>
                    <col class="network-page-column">
                    <col span="2" class="network-family-column">
                    <col span="2" class="network-family-column">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col" rowspan="2">Page</th>
                        <th scope="colgroup" colspan="2" class="network-family-heading">IPv4</th>
                        <th scope="colgroup" colspan="2" class="network-family-heading network-family-divider">IPv6</th>
                    </tr>
                    <tr>
                        <th scope="col">75th percentile</th>
                        <th scope="col">Samples</th>
                        <th scope="col" class="network-family-divider">75th percentile</th>
                        <th scope="col">Samples</th>
                    </tr>
                </thead>
                <tbody data-network-pages><tr><td colspan="5">Loading remote measurements…</td></tr></tbody>
            </table>
        </div>
    </section>

    <section class="network-diagnostics-notes" aria-labelledby="network-details-heading">
        <h2 id="network-details-heading">What This Measures</h2>
        <ul>
            <li>Each authenticated remote browser reports its completed MOED navigation and same-origin image timings.</li>
            <li>The server determines IPv4 or IPv6 from the trusted client connection; browsers do not self-report their address.</li>
            <li>Measurements contain endpoint names, document formats, response sizes, and timings only. Client IP addresses and user identities are not stored.</li>
            <li>Results appear after the updated application receives public traffic; an empty IPv6 card means no IPv6 sample has arrived yet.</li>
        </ul>
        <p class="network-privacy-note">Samples older than 30 days are deleted automatically. This dashboard compares the most recent 24 hours.</p>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
