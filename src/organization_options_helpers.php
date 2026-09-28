<?php

declare(strict_types=1);
require_once __DIR__ . '/inquiry_relationship_helpers.php';

/** Keep selected affiliations alongside one bounded page of searchable options. */
function boundedOrganizationOptions(mysqli $conn, array $selected, string $query = ''): array
{
    $ids = [];
    foreach (array_slice($selected, 0, 202) as $value) {
        if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) $ids[(int) $value] = (int) $value;
    }
    $rows = searchInquiryRelationships($conn, 'organization', $query)['results'];
    $options = [];
    foreach ($rows as $row) $options[$row['id']] = $row + ['is_deleted' => 0];
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $chosen = $conn->execute_query("SELECT id, organization_name, is_deleted FROM organizations WHERE id IN ($marks)", array_values($ids));
        foreach ($chosen->fetch_all(MYSQLI_ASSOC) as $row) $options[$row['id']] = $row;
    }
    return array_values($options);
}

/** Keep selected contacts while limiting the directory shown in each form row. */
function boundedContactOptions(mysqli $conn, array $selected, string $query = ''): array
{
    $ids = [];
    foreach (array_slice($selected, 0, 20) as $value) {
        if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) $ids[(int) $value] = (int) $value;
    }
    $rows = searchInquiryRelationships($conn, 'contact', $query)['results'];
    $options = [];
    foreach ($rows as $row) $options[(int) $row['id']] = $row;
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $chosen = $conn->execute_query(
            "SELECT c.id, c.contact_first_name, c.contact_last_name, c.contact_email,
                    o.organization_name
             FROM contacts c LEFT JOIN organizations o ON o.id = c.organization_id
             WHERE c.id IN ($marks) AND c.is_deleted = 0",
            array_values($ids)
        );
        foreach ($chosen->fetch_all(MYSQLI_ASSOC) as $row) $options[(int) $row['id']] = $row;
    }
    return array_values($options);
}
