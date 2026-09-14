<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/network_diagnostics_helpers.php';

function expectNetworkPerformance(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Network performance helper test failed: {$message}\n");
        exit(1);
    }
}

expectNetworkPerformance(
    networkPerformanceAddressFamily('127.0.0.1') === null
        && networkPerformanceAddressFamily('192.168.1.25') === null
        && networkPerformanceAddressFamily('2606:4700:4700::1111') === 'IPv6'
        && networkPerformanceAddressFamily('8.8.8.8') === 'IPv4',
    'only globally routable IPv4 and IPv6 addresses should be classified.'
);

$validSample = normalizeNetworkPerformanceSample([
    'page_path' => '/contacts.php?status=active',
    'ttfb_ms' => '42.27',
    'dom_content_loaded_ms' => '180.04',
    'load_ms' => '950.16',
    'image_count' => '4',
    'image_total_ms' => '1600.25',
    'image_max_ms' => '800.14',
    'contact_image_count' => '3',
    'contact_image_total_ms' => '1500.25',
    'contact_image_max_ms' => '790.14',
]);
expectNetworkPerformance(
    $validSample !== null
        && $validSample['page_path'] === 'contacts.php'
        && $validSample['ttfb_ms'] === 42.3
        && $validSample['contact_image_count'] === 3,
    'valid browser measurements should be normalized to bounded server values.'
);
expectNetworkPerformance(
    normalizeNetworkPerformanceSample(array_replace($validSample ?? [], ['page_path' => '/assets/logo.png'])) === null
        && normalizeNetworkPerformanceSample(array_replace($validSample ?? [], ['contact_image_count' => 5])) === null
        && normalizeNetworkPerformanceSample(array_replace($validSample ?? [], ['load_ms' => 120001])) === null,
    'non-page paths, impossible counts, and unbounded timings should be rejected.'
);
expectNetworkPerformance(
    networkPerformancePercentile([10, 20, 30, 40], 0.5) === 25.0
        && networkPerformancePercentile([10, 20, 30, 40], 0.75) === 32.5
        && networkPerformancePercentile([], 0.5) === null,
    'percentiles should interpolate sorted samples and preserve the empty state.'
);

$summary = summarizeNetworkPerformanceRows([
    [
        'address_family' => 'IPv4', 'page_path' => 'contacts.php', 'cloudflare_colo' => 'DFW',
        'ttfb_ms' => '40.0', 'load_ms' => '500.0', 'image_count' => '2', 'image_max_ms' => '300.0',
        'contact_image_count' => '1', 'contact_image_max_ms' => '280.0',
    ],
    [
        'address_family' => 'IPv6', 'page_path' => 'contacts.php', 'cloudflare_colo' => 'DFW',
        'ttfb_ms' => '900.0', 'load_ms' => '1500.0', 'image_count' => '2', 'image_max_ms' => '1200.0',
        'contact_image_count' => '1', 'contact_image_max_ms' => '1100.0',
    ],
]);
expectNetworkPerformance(
    $summary['sample_count'] === 2
        && $summary['families']['IPv4']['median_load_ms'] === 500.0
        && $summary['families']['IPv6']['median_contact_image_max_ms'] === 1100.0
        && $summary['families']['IPv6']['top_colo'] === 'DFW'
        && $summary['pages'][0]['ipv4_samples'] === 1
        && $summary['pages'][0]['ipv6_samples'] === 1,
    'the admin summary should preserve address-family, image, edge, and page comparisons.'
);
expectNetworkPerformance(
    networkPerformanceCloudflareColo(['HTTP_CF_RAY' => '1234567890abcdef-DFW']) === 'DFW'
        && networkPerformanceCloudflareColo(['HTTP_CF_RAY' => 'invalid']) === null,
    'Cloudflare edge codes should be accepted only from valid ray suffixes.'
);

echo "Network performance helper tests passed.\n";
