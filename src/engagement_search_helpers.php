<?php

function parseEngagementSearchQuery($search) {
    $or_terms = [];
    $and_terms = [];
    preg_match_all('/"[^"]*"|\S+/u', trim((string) $search), $matches);

    foreach ($matches[0] ?? [] as $matched_term) {
        $is_quoted = strlen($matched_term) >= 2
            && $matched_term[0] === '"'
            && substr($matched_term, -1) === '"';
        if ($is_quoted) {
            $quoted_content = trim(substr($matched_term, 1, -1));
            $quoted_terms = preg_split('/\s+/u', $quoted_content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($quoted_terms ?: [] as $quoted_term) {
                $and_terms[] = $quoted_term;
            }
            continue;
        }

        $unquoted_term = trim($matched_term, '"');
        if ($unquoted_term !== '') {
            $or_terms[] = $unquoted_term;
        }
    }

    return [
        'or_terms' => array_values(array_unique($or_terms)),
        'and_terms' => array_values(array_unique($and_terms)),
    ];
}

function engagementSearchTermSql() {
    return "(
        e.event_title LIKE ?
        OR o.organization_name LIKE ?
        OR EXISTS (
            SELECT 1
            FROM contacts c
            WHERE c.organization_id = e.organization_id
              AND c.is_deleted = 0
              AND (
                  c.contact_first_name LIKE ?
                  OR c.contact_last_name LIKE ?
                  OR CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) LIKE ?
                  OR c.contact_email LIKE ?
                  OR c.contact_phone LIKE ?
                  OR c.contact_role LIKE ?
                  OR c.contact_role_other LIKE ?
              )
        )
        OR EXISTS (
            SELECT 1
            FROM engagement_chron_entries ce
            LEFT JOIN users chron_creator ON chron_creator.id = ce.created_by
            WHERE ce.engagement_id = e.id
              AND ce.is_archived = 0
              AND (
                  ce.entry_text LIKE ?
                  OR COALESCE(chron_creator.username, ce.created_by_username_snapshot) LIKE ?
              )
        )
    )";
}

function buildEngagementSearchPlan($search) {
    $parsed_search = parseEngagementSearchQuery($search);
    $groups = [];
    $patterns = [];
    $term_condition = engagementSearchTermSql();
    $patterns_per_term = substr_count($term_condition, '?');

    if ($parsed_search['or_terms']) {
        $groups[] = '(' . implode(
            ' OR ',
            array_fill(0, count($parsed_search['or_terms']), $term_condition)
        ) . ')';
        foreach ($parsed_search['or_terms'] as $term) {
            for ($index = 0; $index < $patterns_per_term; $index++) {
                $patterns[] = '%' . $term . '%';
            }
        }
    }

    if ($parsed_search['and_terms']) {
        $groups[] = '(' . implode(
            ' AND ',
            array_fill(0, count($parsed_search['and_terms']), $term_condition)
        ) . ')';
        foreach ($parsed_search['and_terms'] as $term) {
            for ($index = 0; $index < $patterns_per_term; $index++) {
                $patterns[] = '%' . $term . '%';
            }
        }
    }

    return [
        'sql' => implode(' AND ', $groups),
        'patterns' => $patterns,
        'or_terms' => $parsed_search['or_terms'],
        'and_terms' => $parsed_search['and_terms'],
    ];
}
