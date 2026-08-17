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
    $patterns_per_term === 11,
    'each term should search every supported engagement field group.'
);
expectEngagementSearch(
    substr_count($plan['sql'], engagementSearchTermSql()) === 4,
    'the search plan should apply one complete field search per term.'
);
expectEngagementSearch(
    count($plan['patterns']) === 4 * $patterns_per_term,
    'the search plan should bind every placeholder.'
);
expectEngagementSearch(
    array_unique(array_slice($plan['patterns'], 0, $patterns_per_term)) === ['%conference%']
        && array_unique(array_slice($plan['patterns'], $patterns_per_term, $patterns_per_term)) === ['%Chicago%']
        && array_unique(array_slice($plan['patterns'], 2 * $patterns_per_term, $patterns_per_term)) === ['%Daniel%']
        && array_unique(array_slice($plan['patterns'], 3 * $patterns_per_term, $patterns_per_term)) === ['%email%'],
    'bound patterns should preserve OR terms first and required AND terms second.'
);

$quoted_only = buildEngagementSearchPlan('"north hall"');
expectEngagementSearch(
    $quoted_only['or_terms'] === []
        && $quoted_only['and_terms'] === ['north', 'hall']
        && str_contains($quoted_only['sql'], ' AND '),
    'a quoted query should require all quoted words.'
);

echo "Engagement search helper tests passed.\n";
