<?php

declare(strict_types=1);

/** Fixed SQL only; search values never become SQL or route names. */
function globalSearchDefinitions(): array
{
    return [
        'engagements' => ['label' => 'Engagements', 'route' => 'view_engagement.php',
            'sql' => "SELECT e.id, COALESCE(NULLIF(e.event_title, ''), o.organization_name) AS title,
                CONCAT_WS(' · ', o.organization_name, e.event_start_date, e.lifecycle_status) AS detail
                FROM engagements e JOIN organizations o ON o.id = e.organization_id
                WHERE e.is_deleted = 0 AND o.is_deleted = 0
                AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', e.event_title, o.organization_name, e.event_city))) > 0"],
        'inquiries' => ['label' => 'Booking Inquiries', 'route' => 'view_inquiry.php',
            'sql' => "SELECT i.id, i.title, CONCAT_WS(' · ', o.organization_name, i.stage) AS detail
                FROM booking_inquiries i LEFT JOIN organizations o ON o.id = i.organization_id
                WHERE i.archived_at IS NULL AND COALESCE(o.is_deleted, 0) = 0
                AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', i.title, o.organization_name, i.request_summary))) > 0"],
        'organizations' => ['label' => 'Organizations', 'route' => 'view_organization.php',
            'sql' => "SELECT o.id, o.organization_name AS title, CONCAT_WS(' · ', o.physical_city, o.physical_state) AS detail
                FROM organizations o WHERE o.is_deleted = 0
                AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', o.organization_name, o.physical_city, o.email))) > 0"],
        'contacts' => ['label' => 'Contacts', 'route' => 'view_contact.php',
            'sql' => "SELECT c.id, CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) AS title,
                CONCAT_WS(' · ', o.organization_name, c.contact_email) AS detail
                FROM contacts c LEFT JOIN organizations o ON o.id = c.organization_id
                WHERE c.is_deleted = 0
                AND (LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', c.contact_first_name, c.contact_last_name,
                    c.contact_email, o.organization_name))) > 0
                    OR EXISTS (SELECT 1 FROM contact_organizations co JOIN organizations affiliation ON affiliation.id = co.organization_id
                        WHERE co.contact_id = c.id AND affiliation.is_deleted = 0
                        AND LOCATE(LOWER(?), LOWER(affiliation.organization_name)) > 0))"],
        'tasks' => ['label' => 'Tasks', 'route' => 'tasks.php',
            'sql' => "SELECT t.id, t.title, t.status, CONCAT_WS(' · ', COALESCE(e.event_title, i.title, o.organization_name, eo.organization_name), t.status, t.due_date) AS detail
                FROM follow_up_tasks t
                LEFT JOIN engagements e ON e.id = t.engagement_id
                LEFT JOIN organizations eo ON eo.id = e.organization_id
                LEFT JOIN organizations o ON o.id = t.organization_id
                LEFT JOIN contacts c ON c.id = t.contact_id
                LEFT JOIN booking_inquiries i ON i.id = t.inquiry_id
                WHERE t.is_archived = 0 AND COALESCE(e.is_deleted, 0) = 0 AND COALESCE(eo.is_deleted, 0) = 0
                    AND COALESCE(o.is_deleted, 0) = 0 AND COALESCE(c.is_deleted, 0) = 0 AND i.archived_at IS NULL
                AND LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', t.title, e.event_title, eo.organization_name,
                    o.organization_name, c.contact_first_name, c.contact_last_name, i.title))) > 0"],
    ];
}

function fetchGlobalSearchResults(mysqli $conn, string $query, string $type = '', int $page = 1): array
{
    $definitions = globalSearchDefinitions();
    $query = trim(mb_substr($query, 0, 100));
    if (mb_strlen($query) < 2) return [];
    $limit = isset($definitions[$type]) ? 20 : 5;
    $offset = isset($definitions[$type]) ? (max(1, min(1000, $page)) - 1) * $limit : 0;
    $groups = [];
    foreach ($definitions as $key => $definition) {
        if ($type !== '' && isset($definitions[$type]) && $key !== $type) continue;
        $params = array_fill(0, substr_count($definition['sql'], '?'), $query);
        $rows = $conn->execute_query($definition['sql'] . ' ORDER BY title, id LIMIT ? OFFSET ?',
            [...$params, $limit + 1, $offset])->fetch_all(MYSQLI_ASSOC);
        $groups[$key] = ['label' => $definition['label'], 'route' => $definition['route'],
            'more' => count($rows) > $limit, 'rows' => array_slice($rows, 0, $limit)];
    }
    return $groups;
}
