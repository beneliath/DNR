<?php
require_once __DIR__ . '/../src/engagement_search_helpers.php';

function expectEngagementSearch($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Engagement search helper test failed: {$message}\n");
        exit(1);
    }
}

$parsed = parseEngagementSearchQuery('conference Chicago "Daniel email"');
expectEngagementSearch(
    $parsed['or_terms'] === ['conference', 'Chicago'],
    'unquoted words should be parsed as OR terms.'
);
expectEngagementSearch(
    $parsed['and_terms'] === ['Daniel', 'email'],
    'each word inside quotes should be parsed as a required AND term.'
);

$plan = buildEngagementSearchPlan('conference Chicago "Daniel email"');
$patterns_per_term = substr_count(engagementSearchTermSql(), '?');
expectEngagementSearch(
    $patterns_per_term === 5,
    'each term should search every supported engagement field group.'
);
expectEngagementSearch(
    substr_count($plan['sql'], engagementSearchTermSql()) === 2,
    'the search plan should apply one complete field search per OR/AND group.'
);
expectEngagementSearch(
    count($plan['patterns']) === 2 * $patterns_per_term,
    'the search plan should bind every placeholder.'
);
expectEngagementSearch(
    array_unique(array_slice($plan['patterns'], 0, $patterns_per_term))
            === ['conference* Chicago*']
        && array_unique(array_slice($plan['patterns'], $patterns_per_term, $patterns_per_term))
            === ['+Daniel* +email*'],
    'bound patterns should consolidate OR terms and required AND terms.'
);

$quoted_only = buildEngagementSearchPlan('"north hall"');
expectEngagementSearch(
    $quoted_only['or_terms'] === []
        && $quoted_only['and_terms'] === ['north', 'hall']
        && array_unique($quoted_only['patterns']) === ['+north* +hall*'],
    'a quoted query should require all quoted words.'
);

$bounded = buildEngagementSearchPlan(implode(' ', range(1, 30)) . str_repeat('x', 400));
expectEngagementSearch(
    count($bounded['or_terms']) + count($bounded['and_terms']) <= 8
        && strlen($bounded['search']) <= 256,
    'search input and expanded term count should be bounded.'
);

$invalid_utf8 = buildEngagementSearchPlan("valid\xFFtext");
expectEngagementSearch(
    $invalid_utf8['search'] === '' && $invalid_utf8['patterns'] === [],
    'malformed UTF-8 should be discarded without warnings or unbounded query work.'
);

echo "Engagement search helper tests passed.\n";
