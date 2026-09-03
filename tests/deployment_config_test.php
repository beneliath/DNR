<?php

declare(strict_types=1);

putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));

$originalEmittedPrefix = getenv('DNR_INBOUND_MARKER_PREFIX');
$originalAcceptedPrefixes = getenv('DNR_INBOUND_ACCEPTED_MARKER_PREFIXES');
putenv('DNR_INBOUND_MARKER_PREFIX=NEWNAME');
putenv('DNR_INBOUND_ACCEPTED_MARKER_PREFIXES=NEWNAME,MOED');

require_once __DIR__ . '/../src/application_runtime.php';
require_once __DIR__ . '/../src/inbound_email_helpers.php';

use Dnr\Config\DeploymentConfig;

function expectDeploymentConfig(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Deployment configuration test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$moedPath = $root . '/deployments/moed/application.yaml';
$examplePath = $root . '/deployments/example/application.yaml';
$moed = DeploymentConfig::load($moedPath, []);
$example = DeploymentConfig::load($examplePath, []);

expectDeploymentConfig(
    $moed->string('brand.display_name') === 'MOED'
        && $moed->string('brand.logo_email') === 'assets/dnr-logo-email.png'
        && $moed->string('defaults.speaker') === 'Olivier Melnick'
        && $moed->string('inbound_email.emitted_marker_prefix') === 'MOED'
        && count($moed->list('standard_event_tasks')) === 9,
    'the tracked MOED profile should preserve current production behavior.'
);
expectDeploymentConfig(
    $example->string('brand.display_name') === 'Example Ministry'
        && $example->string('brand.logo_email') === 'assets/dnr-logo-email.png'
        && $example->string('defaults.timezone') === 'America/New_York'
        && $example->list('standard_event_tasks') === [],
    'an independent deployment profile should load without source changes.'
);

$overridden = DeploymentConfig::load($examplePath, [
    'DNR_BRAND_DISPLAY_NAME' => 'Override Name',
    'DNR_DEFAULT_SPEAKER' => 'Override Speaker',
    'DNR_INBOUND_MARKER_PREFIX' => 'NEWNAME',
    'DNR_INBOUND_ACCEPTED_MARKER_PREFIXES' => 'NEWNAME,EXAMPLE',
    'DNR_TASK_UPCOMING_DAYS' => '14',
]);
expectDeploymentConfig(
    $overridden->string('brand.display_name') === 'Override Name'
        && $overridden->string('defaults.speaker') === 'Override Speaker'
        && $overridden->list('inbound_email.accepted_marker_prefixes') === ['NEWNAME', 'EXAMPLE']
        && $overridden->integer('workflow.task_upcoming_days') === 14,
    'documented environment values should override the deployment YAML with typed validation.'
);

$subdomainTiles = DeploymentConfig::load($examplePath, [
    'DNR_MAP_TILE_URL' => 'https://{s}.tiles.example.org/{z}/{x}/{y}.png',
]);
expectDeploymentConfig(
    $subdomainTiles->tileCspSource() === 'https://*.tiles.example.org',
    'a templated tile subdomain should produce a valid wildcard CSP source.'
);

$legacy = DeploymentConfig::load($examplePath, ['DEFAULT_SPEAKER' => 'Legacy Speaker']);
expectDeploymentConfig(
    $legacy->string('defaults.speaker') === 'Legacy Speaker',
    'the old DEFAULT_SPEAKER name should remain a temporary compatibility override.'
);

$invalidPath = tempnam(sys_get_temp_dir(), 'dnr-config-');
if (!is_string($invalidPath)) {
    throw new RuntimeException('Unable to create deployment configuration test fixture.');
}

$unknownRejected = false;
file_put_contents($invalidPath, "brand:\n  unsupported: value\n");
try {
    DeploymentConfig::load($invalidPath, []);
} catch (InvalidArgumentException $exception) {
    $unknownRejected = str_contains($exception->getMessage(), 'brand.unsupported');
}
expectDeploymentConfig($unknownRejected, 'unknown YAML keys should fail validation.');

$timezoneRejected = false;
file_put_contents($invalidPath, "defaults:\n  timezone: not/a-timezone\n");
try {
    DeploymentConfig::load($invalidPath, []);
} catch (InvalidArgumentException $exception) {
    $timezoneRejected = str_contains($exception->getMessage(), 'not/a-timezone');
}
expectDeploymentConfig($timezoneRejected, 'unknown timezones should fail validation.');

$unsafeAssetRejected = false;
file_put_contents($invalidPath, "brand:\n  logo_light: ../secret.svg\n");
try {
    DeploymentConfig::load($invalidPath, []);
} catch (InvalidArgumentException $exception) {
    $unsafeAssetRejected = str_contains($exception->getMessage(), 'safe assets-relative path');
}
expectDeploymentConfig($unsafeAssetRejected, 'profile logo paths should not escape the public asset directory.');
unlink($invalidPath);

$legacyMarker = '[' . 'MOED#41.' . applicationInboundMarkerTag(41, 'MOED') . ']';
$newMarker = applicationInboundMarker(42);
$legacyMarkers = parseInboundEmailEngagementMarkers('Reply ' . $legacyMarker . ' and new ' . $newMarker);
expectDeploymentConfig(
    $legacyMarkers['ids'] === [41, 42]
        && str_starts_with(applicationInboundMarker(42), '[NEWNAME#42.')
        && applicationInboundMarkerIsValid('NEWNAME', 42, applicationInboundMarkerTag(42, 'NEWNAME')),
    'a renamed deployment should emit the new prefix while accepting configured legacy markers.'
);
if ($originalEmittedPrefix === false) {
    putenv('DNR_INBOUND_MARKER_PREFIX');
} else {
    putenv('DNR_INBOUND_MARKER_PREFIX=' . $originalEmittedPrefix);
}
if ($originalAcceptedPrefixes === false) {
    putenv('DNR_INBOUND_ACCEPTED_MARKER_PREFIXES');
} else {
    putenv('DNR_INBOUND_ACCEPTED_MARKER_PREFIXES=' . $originalAcceptedPrefixes);
}

$profileSource = (string) file_get_contents($moedPath)
    . (string) file_get_contents($examplePath);
expectDeploymentConfig(
    preg_match('/^[ \t]*(?:password|secret|token)[A-Za-z0-9_-]*\s*:/mi', $profileSource) !== 1,
    'deployment profiles should not contain secret material.'
);

echo "Deployment configuration tests passed.\n";
