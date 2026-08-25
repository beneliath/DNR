<?php

require_once __DIR__ . '/../src/functions.php';

function expectOrganizationRegionFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Organization region feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$add_organization = file_get_contents($root . '/src/add_organization.php');
$edit_organization = file_get_contents($root . '/src/edit_organization.php');
$page_actions = file_get_contents($root . '/src/assets/js/page-actions.js');
$modern_styles = file_get_contents($root . '/src/assets/css/modern.css');
$us_regions = addressRegionChoices('US');
$canadian_regions = addressRegionChoices('CA');
$region_client_data = addressRegionClientData();

expectOrganizationRegionFeature(
    count($us_regions) === 51
        && $us_regions['IL']['name'] === 'Illinois'
        && count($canadian_regions) === 13
        && $canadian_regions['ON']['name'] === 'Ontario',
    'all U.S. states, the District of Columbia, and Canadian provinces and territories should have names.'
);

expectOrganizationRegionFeature(
    !array_key_exists('flag', $region_client_data['US']['choices'][0])
        && !array_key_exists('flag', $region_client_data['CA']['choices'][0])
        && !str_contains($page_actions, 'address-region-flag'),
    'state and province dropdowns should contain names without flag icons.'
);

foreach ([$add_organization, $edit_organization] as $page) {
    expectOrganizationRegionFeature(
        substr_count($page, 'data-address-region-control') === 2
            && substr_count($page, 'data-address-country=') === 2
            && str_contains($page, 'id="address-region-data"'),
        'organization create and edit pages should expose both address pairs to the shared region control.'
    );
}

expectOrganizationRegionFeature(
    str_contains($page_actions, 'function initializeAddressRegions()')
        && str_contains($page_actions, "state.textInput.placeholder = 'State/Province'")
        && str_contains($page_actions, 'initializeAddressRegions();')
        && str_contains($modern_styles, '.address-region-trigger')
        && str_contains($modern_styles, '.address-region-option'),
    'the shared client behavior should switch between region dropdowns and a State/Province text input.'
);

expectOrganizationRegionFeature(
    normalizeAddressRegion('US', 'Illinois') === 'IL'
        && normalizeAddressRegion('CA', 'Ontario') === 'ON'
        && normalizeAddressRegion('FR', 'Île-de-France') === 'Île-de-France'
        && addressRegionName('US', 'IL') === 'Illinois',
    'server normalization and display should agree with the region controls.'
);

echo "Organization region feature tests passed.\n";
