<?php

declare(strict_types=1);

/** @return array{results: list<array<string, mixed>>, selected: ?array<string, mixed>, has_more: bool} */
function searchInquiryRelationships(
    mysqli $conn,
    string $kind,
    string $query = '',
    ?int $organizationId = null,
    ?int $selectedId = null
): array {
    if (!in_array($kind, ['organization', 'contact'], true)) {
        throw new InvalidArgumentException('Choose organizations or contacts.');
    }
    $query = trim(mb_substr($query, 0, 128));
    $terms = fulltextSearchQuery($query);
    $prefix = addcslashes($query, '%_\\') . '%';
    $params = [];
    $types = '';
    if ($kind === 'organization') {
        $columns = 'o.id, o.organization_name, o.organization_name AS label';
        $from = 'FROM organizations o';
        $where = 'o.is_deleted = 0';
        $order = 'o.organization_name, o.id';
        $search = "
            SELECT id FROM organizations WHERE is_deleted = 0 AND MATCH(organization_name) AGAINST (? IN BOOLEAN MODE)
            UNION SELECT id FROM organizations WHERE is_deleted = 0 AND organization_name LIKE ?
        ";
        $searchParams = [$terms, $prefix];
    } else {
        $columns = "c.id, c.organization_id, c.contact_first_name, c.contact_last_name, o.organization_name,
            CONCAT(c.contact_last_name, ', ', c.contact_first_name, ' · ', COALESCE(o.organization_name, 'Standalone')) AS label";
        $from = 'FROM contacts c LEFT JOIN organizations o ON o.id = c.organization_id';
        $where = 'c.is_deleted = 0 AND (o.id IS NULL OR o.is_deleted = 0)';
        if ($organizationId !== null) {
            $from .= ' INNER JOIN contact_organizations co ON co.contact_id = c.id';
            $where .= ' AND co.organization_id = ?';
            $params[] = $organizationId;
            $types .= 'i';
        }
        $order = 'c.contact_last_name, c.contact_first_name, c.id';
        $search = "
            SELECT id FROM contacts WHERE is_deleted = 0
                AND MATCH(contact_first_name, contact_last_name, contact_email) AGAINST (? IN BOOLEAN MODE)
            UNION SELECT id FROM contacts WHERE is_deleted = 0 AND contact_last_name LIKE ?
            UNION SELECT id FROM contacts WHERE is_deleted = 0 AND contact_first_name LIKE ?
        ";
        $searchParams = [$terms, $prefix, $prefix];
    }
    $selected = null;
    if ($selectedId !== null) {
        $idColumn = $kind === 'organization' ? 'o.id' : 'c.id';
        $stmt = $conn->prepare("SELECT {$columns} {$from} WHERE {$where} AND {$idColumn} = ?");
        $selectedParams = [...$params, $selectedId];
        $stmt->bind_param($types . 'i', ...$selectedParams);
        $stmt->execute();
        $selected = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($query !== '') {
        $idColumn = $kind === 'organization' ? 'o.id' : 'c.id';
        $from .= " INNER JOIN ({$search}) matches ON matches.id = {$idColumn}";
        $params = [...$searchParams, ...$params];
        $types = str_repeat('s', count($searchParams)) . $types;
    }
    $stmt = $conn->prepare("SELECT {$columns} {$from} WHERE {$where} ORDER BY {$order} LIMIT 26");
    if ($params !== []) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['results' => array_slice($rows, 0, 25), 'selected' => $selected, 'has_more' => count($rows) > 25];
}

/** Keep the saved selection available even when it is outside the first search page. */
function inquiryRelationshipFormOptions(array $search): array {
    $rows = $search['results'];
    if ($search['selected'] !== null && !in_array($search['selected']['id'], array_column($rows, 'id'), true)) {
        array_unshift($rows, $search['selected']);
    }
    return $rows;
}

/** @return array{organizations: array, contacts: array} */
function inquiryRelationshipFormData(mysqli $conn, array $values, array $returnParameters): array {
    $organizationId = \Dnr\Http\RequestInput::positiveInt($returnParameters, 'created_organization_id')
        ?? \Dnr\Http\RequestInput::positiveInt($values, 'organization_id');
    $createdContactId = \Dnr\Http\RequestInput::positiveInt($returnParameters, 'created_contact_id');
    $contactId = $createdContactId ?? \Dnr\Http\RequestInput::positiveInt($values, 'primary_contact_id');
    $contacts = searchInquiryRelationships($conn, 'contact',
        \Dnr\Http\RequestInput::string($values, 'contact_search'),
        $createdContactId !== null ? null : $organizationId, $contactId);
    if ($createdContactId !== null && $contacts['selected'] !== null) {
        $organizationId = (int) ($contacts['selected']['organization_id'] ?? 0) ?: null;
    }
    $organizations = searchInquiryRelationships($conn, 'organization',
        \Dnr\Http\RequestInput::string($values, 'organization_search'), null, $organizationId);
    return ['organizations' => inquiryRelationshipFormOptions($organizations),
        'contacts' => inquiryRelationshipFormOptions($contacts)];
}
