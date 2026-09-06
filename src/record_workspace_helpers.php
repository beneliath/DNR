<?php

declare(strict_types=1);

/** Only return to an application screen, never an external URL or action endpoint. */
function safeRecordReturnUrl(mixed $value, string $fallback): string
{
    if (!is_string($value) || strlen($value) > 6000 || preg_match('/[\\\\\x00-\x20\x7f]/', $value)) {
        return $fallback;
    }
    $parts = parse_url($value);
    $allowed = [
        'dashboard.php', 'engagements.php', 'contacts.php', 'organizations.php', 'inquiries.php',
        'tasks.php', 'map.php', 'view_calendar.php', 'inbound_mail.php',
        'view_engagement.php', 'edit_engagement.php', 'view_contact.php', 'edit_contact.php',
        'view_organization.php', 'edit_organization.php', 'view_inquiry.php',
        'add_inquiry.php', 'edit_inquiry.php', 'convert_inquiry.php', 'add_contact.php',
    ];
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])
        || !in_array($parts['path'] ?? '', $allowed, true)
    ) {
        return $fallback;
    }
    return $value;
}

/** Name the actual destination when arriving from a related record or filtered list. */
function recordReturnLabel(string $url): string
{
    return match (parse_url($url, PHP_URL_PATH)) {
        'dashboard.php' => 'Dashboard', 'engagements.php' => 'Engagements',
        'organizations.php' => 'Organizations', 'contacts.php' => 'Contacts',
        'map.php' => 'Map', 'view_calendar.php' => 'Calendar',
        'inquiries.php' => 'Booking Pipeline', 'tasks.php' => 'Work Queue',
        'inbound_mail.php' => 'Inbox', 'view_organization.php' => 'Organization',
        'view_contact.php' => 'Contact', 'view_engagement.php' => 'Engagement',
        'view_inquiry.php' => 'Inquiry', default => 'Previous Page',
    };
}

/** Preserve the existing query and fragment while changing explicit query fields. */
function recordUrlWithQuery(string $url, array $changes): string
{
    $parts = parse_url($url);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($query[$key]);
        else $query[$key] = $value;
    }
    return ($parts['path'] ?? '') . ($query ? '?' . http_build_query($query) : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

function recordCurrentUrl(string $fallback): string
{
    return safeRecordReturnUrl(basename($_SERVER['PHP_SELF'] ?? '')
        . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), $fallback);
}

/** A short cursor trail supports Previous without unstable row offsets. */
function recordCursorTrail(mixed $value): array
{
    if (!is_string($value) || strlen($value) > 5000) return [];
    $decoded = json_decode(base64_decode($value, true) ?: '', true);
    if (!is_array($decoded) || count($decoded) > 40) return [];
    foreach ($decoded as $cursor) {
        if (!is_string($cursor) || strlen($cursor) > 1024) return [];
    }
    return array_values($decoded);
}

function recordEncodedCursorTrail(array $trail): string
{
    return $trail ? base64_encode((string) json_encode(array_slice($trail, -40))) : '';
}

/** A note POST touches only activity; lock the parent to preserve archive rules. */
function handleRecordAddNote(mysqli $conn, string $entityType, int $entityId, string $returnUrl): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'add_note') return '';
    requireValidCsrfToken();
    if (!in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $table = match ($entityType) {
        'organization' => 'organizations', 'contact' => 'contacts', 'engagement' => 'engagements',
        default => throw new InvalidArgumentException('Unknown record type.'),
    };
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $entityId);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$record || !empty($record['is_deleted'])) {
            throw new InvalidArgumentException('Restore this record before adding a Chron Log Entry.');
        }
        if ($entityType === 'contact' && !empty($record['organization_id'])) {
            requireActiveOrganization($conn, (int) $record['organization_id'], true);
        }
        insertEntityChronLogEntry($conn, $entityType, $entityId,
            $_POST['new_chron_entry'] ?? null, (int) $_SESSION['user_id'], (string) $_SESSION['username']);
        $conn->commit();
        $_SESSION['record_note_message'] = 'Chron Log Entry added.';
        header('Location: ' . explode('#', $returnUrl)[0] . '#chron-log');
        exit();
    } catch (Throwable $exception) {
        $conn->rollback();
        return $exception instanceof InvalidArgumentException
            ? $exception->getMessage() : 'Unable to save the Chron Log Entry. Please try again.';
    }
}
