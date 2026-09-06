<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/pagination_helpers.php';

function expectPaginationPreference(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Pagination preference test failed: {$message}\n");
        exit(1);
    }
}

$_COOKIE = [];
expectPaginationPreference(paginationPageSizePreference('contacts', null) === 20,
    'New visitors use the page default.');
expectPaginationPreference(!isset($_COOKIE['dnr_rows_per_page_contacts']),
    'Visiting a page without a selection does not save the initial default.');
expectPaginationPreference(paginationPageSizePreference('contacts', '50') === 50,
    'Selecting a supported size applies it immediately.');
expectPaginationPreference(paginationPageSizePreference('organizations', null) === 20,
    'A contact preference does not change organizations.');
expectPaginationPreference(paginationPageSizePreference('organizations', '100') === 100,
    'Organizations can have a different preference.');
expectPaginationPreference(paginationPageSizePreference('contacts', null) === 50
    && paginationPageSizePreference('organizations', null) === 100,
    'Returning without a page-size parameter restores each page separately.');

foreach ([[], 'invalid', 0, -1, 500] as $invalid) {
    expectPaginationPreference(paginationPageSizePreference('contacts', $invalid) === 50,
        'Invalid requests cannot overwrite a remembered setting.');
}
expectPaginationPreference(paginationPageSizePreference('contacts', 20) === 20
    && paginationPageSizePreference('contacts', null) === 20,
    'Explicitly choosing the default replaces the previous selection.');
expectPaginationPreference(paginationPageSizePreference('view_organization_chron', 50) === 50
    && paginationPageSizePreference('view_organization_events', null) === 20
    && paginationPageSizePreference('edit_organization_chron', null) === 20,
    'Separate lists and page types have independent preferences.');
expectPaginationPreference(paginationPageSizePreference('map', null) === 20
    && paginationPageSizePreference('map', 100) === 100
    && paginationPageSizePreference('map', null, 20, [20, 50]) === 20,
    'Stored preferences respect the current route size limit.');
$_COOKIE['dnr_rows_per_page_map'] = '500';
expectPaginationPreference(paginationPageSizePreference('map', null) === 20,
    'The retired 500-row map preference falls back to 20.');

$_COOKIE['dnr_rows_per_page_users'] = ['malformed'];
expectPaginationPreference(paginationPageSizePreference('users', null) === 20,
    'Malformed cookies fall back safely.');
$_COOKIE['dnr_rows_per_page_users'] = '99999';
expectPaginationPreference(paginationPageSizePreference('users', null) === 20,
    'Cookies cannot request unsupported row limits.');

echo "Pagination preference tests passed.\n";
