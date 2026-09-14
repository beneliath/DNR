<?php

declare(strict_types=1);

function expectNetworkDiagnostics(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Network diagnostics feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/network_diagnostics.php');
$endpoint = (string) file_get_contents($root . '/src/network_performance.php');
$helpers = (string) file_get_contents($root . '/src/network_diagnostics_helpers.php');
$dashboardScript = (string) file_get_contents($root . '/src/assets/js/network-diagnostics.js');
$collectorScript = (string) file_get_contents($root . '/src/assets/js/network-performance.js');
$styles = (string) file_get_contents($root . '/src/assets/css/pages/network_diagnostics.css');
$functions = (string) file_get_contents($root . '/src/functions.php');
$footer = (string) file_get_contents($root . '/src/templates/footer.php');
$migration = (string) file_get_contents($root . '/migrations/20260913_add_network_performance_samples.sql');
$grants = (string) file_get_contents($root . '/scripts/configure_database_privileges.sh');

expectNetworkDiagnostics(
    str_contains($page, 'requireAdmin();')
        && str_contains($endpoint, "if (\$method === 'GET')")
        && str_contains($endpoint, 'requireAdmin();')
        && str_contains($endpoint, 'requireLogin();')
        && str_contains($endpoint, 'requireValidCsrfToken();'),
    'summary reads must require an administrator and sample writes must require a signed-in CSRF-valid session.'
);
expectNetworkDiagnostics(
    str_contains($endpoint, 'networkPerformanceAddressFamily(requestIpAddress())')
        && str_contains($helpers, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE')
        && str_contains($helpers, "return 'IPv4';")
        && str_contains($helpers, "return filter_var(\$address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : null;")
        && str_contains($endpoint, 'session_write_close()')
        && str_contains($endpoint, "header('Cache-Control: no-store, max-age=0')"),
    'the endpoint must classify the trusted public client route, exclude local addresses, and release the session lock.'
);
expectNetworkDiagnostics(
    str_contains($collectorScript, "getEntriesByType('navigation')")
        && str_contains($collectorScript, "getEntriesByType('resource')")
        && str_contains($collectorScript, "endsWith('/contact_photo.php')")
        && str_contains($collectorScript, 'navigator.sendBeacon')
        && str_contains($collectorScript, "credentials: 'same-origin'")
        && !str_contains($collectorScript, 'requestIpAddress')
        && !str_contains($collectorScript, 'client_ip'),
    'authenticated browsers should report page and contact-image timing while leaving address-family classification to the server.'
);
expectNetworkDiagnostics(
    str_contains($footer, 'data-network-performance')
        && str_contains($footer, 'generateCsrfToken()')
        && str_contains($footer, "renderScript('assets/js/network-performance.min.js')")
        && str_contains($footer, "if (!empty(\$_SESSION['user_id']))")
        && !str_contains($collectorScript, 'localStorage')
        && !str_contains($collectorScript, 'sessionStorage'),
    'the collector should load only for authenticated pages and avoid client-side persistence.'
);
expectNetworkDiagnostics(
    str_contains($migration, 'CREATE TABLE network_performance_samples')
        && !str_contains($migration, 'ip_address')
        && !str_contains($migration, 'user_id')
        && str_contains($helpers, 'INTERVAL 30 DAY')
        && str_contains($grants, '.network_performance_samples')
        && str_contains($grants, 'SELECT, INSERT, DELETE'),
    'telemetry storage must omit client identity, expire old samples, and use the restricted application grant.'
);
expectNetworkDiagnostics(
    str_contains($page, 'data-network-summary')
        && str_contains($page, "foreach (['IPv4', 'IPv6'] as \$family)")
        && str_contains($page, 'data-network-card="<?php echo strtolower($family); ?>"')
        && str_contains($page, 'data-contact-images="ipv4"')
        && str_contains($page, 'data-network-pages')
        && str_contains($dashboardScript, "credentials: 'same-origin'")
        && str_contains($dashboardScript, 'This does not establish whether IPv6 is healthy.')
        && str_contains($functions, "'network_diagnostics.php'")
        && str_contains($styles, '@media (max-width: 760px)')
        && str_contains($styles, '.network-page-breakdown tbody td:not([colspan])')
        && str_contains($styles, 'grid-template-columns: minmax(7.5rem, 0.8fr) minmax(0, 1fr)')
        && str_contains($styles, '.network-page-breakdown .network-family-divider')
        && str_contains($page, '<th scope="col" rowspan="2">Page</th>')
        && str_contains($page, '<th scope="colgroup" colspan="2" class="network-family-heading">IPv4</th>'),
    'the responsive admin page should compare both public families, contact images, and observed page loads.'
);

echo "Network diagnostics feature tests passed.\n";
