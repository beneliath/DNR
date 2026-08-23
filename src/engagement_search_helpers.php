<?php

declare(strict_types=1);

function parseEngagementSearchQuery($search) {
    $search = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $search) ?? '';
    $search = trim(substr($search, 0, 256));
    $or_terms = [];
    $and_terms = [];
    preg_match_all('/"[^"]*"|\S+/u', trim((string) $search), $matches);

    foreach ($matches[0] as $matched_term) {
        if (count($or_terms) + count($and_terms) >= 8) {
            break;
        }
        $is_quoted = strlen($matched_term) >= 2
            && $matched_term[0] === '"'
            && substr($matched_term, -1) === '"';
        if ($is_quoted) {
            $quoted_content = trim(substr($matched_term, 1, -1));
            $quoted_terms = preg_split('/\s+/u', $quoted_content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($quoted_terms ?: [] as $quoted_term) {
                if (count($or_terms) + count($and_terms) >= 8) {
                    break;
                }
                if (strlen($quoted_term) >= 3) {
                    $and_terms[] = substr($quoted_term, 0, 64);
                }
            }
            continue;
        }

        $unquoted_term = trim($matched_term, '"');
        if (strlen($unquoted_term) >= 3) {
            $or_terms[] = substr($unquoted_term, 0, 64);
        }
    }

    return [
        'or_terms' => array_values(array_unique($or_terms)),
        'and_terms' => array_values(array_unique($and_terms)),
    ];
}

function engagementSearchTermSql() {
    return "(
        MATCH(
            e.event_title, e.event_description, e.engagement_notes,
            e.caller_name, e.cancellation_reason
        )
            AGAINST (? IN BOOLEAN MODE)
        OR EXISTS (
            SELECT 1 FROM users caller
            WHERE caller.id = e.caller_user_id
              AND caller.username LIKE CONCAT('%', REPLACE(?, '*', ''), '%')
        )
        OR MATCH(
            o.organization_name, o.notes, o.affiliation, o.distinctives,
            o.email, o.phone, o.physical_city, o.physical_state,
            o.mailing_city, o.mailing_state
        ) AGAINST (? IN BOOLEAN MODE)
        OR EXISTS (
            SELECT 1
            FROM contacts c
            WHERE c.organization_id = e.organization_id
              AND c.is_deleted = 0
              AND MATCH(
                  c.contact_first_name, c.contact_last_name, c.contact_email,
                  c.contact_phone, c.contact_role_other, c.contact_notes
              ) AGAINST (? IN BOOLEAN MODE)
        )
        OR EXISTS (
            SELECT 1
            FROM engagement_chron_entries ce
            WHERE ce.engagement_id = e.id
              AND ce.is_archived = 0
              AND MATCH(ce.entry_text, ce.created_by_username_snapshot)
                  AGAINST (? IN BOOLEAN MODE)
        )
    )";
}

function buildEngagementSearchPlan($search) {
    $parsed_search = parseEngagementSearchQuery($search);
    $cleaned_search = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $search) ?? '';
    $normalized_search = trim(substr($cleaned_search, 0, 256));
    $groups = [];
    $patterns = [];
    $term_condition = engagementSearchTermSql();
    $patterns_per_term = substr_count($term_condition, '?');

    if ($parsed_search['or_terms']) {
        $groups[] = $term_condition;
        $or_query = implode(' ', array_map(
            'engagementFulltextTerm',
            $parsed_search['or_terms']
        ));
        for ($index = 0; $index < $patterns_per_term; $index++) {
            $patterns[] = $or_query;
        }
    }

    if ($parsed_search['and_terms']) {
        $groups[] = $term_condition;
        $and_query = implode(' ', array_map(
            static fn($term) => '+' . engagementFulltextTerm($term),
            $parsed_search['and_terms']
        ));
        for ($index = 0; $index < $patterns_per_term; $index++) {
            $patterns[] = $and_query;
        }
    }

    return [
        'search' => $normalized_search,
        'sql' => implode(' AND ', $groups),
        'patterns' => $patterns,
        'or_terms' => $parsed_search['or_terms'],
        'and_terms' => $parsed_search['and_terms'],
    ];
}

function engagementFulltextTerm($term) {
    $term = preg_replace('/[+\-><()~*"@]+/u', ' ', (string) $term) ?? '';
    $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
    return $term === '' ? '""' : $term . '*';
}
