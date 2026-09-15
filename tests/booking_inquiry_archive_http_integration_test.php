<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Inquiry archive HTTP tests skipped (requires disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/booking_inquiry_helpers.php';
require_once $sourceDirectory . '/dashboard_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';

function expectInquiryArchive(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inquiryArchiveHttp(string $path, string $sessionId, ?array $post = null): array
{
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessionId, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $response = curl_exec($curl);
    if (!is_string($response)) {
        throw new RuntimeException(curl_error($curl));
    }
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
}

function inquiryArchiveFields(string $html, int $inquiryId, string $action, string $idName = 'inquiry_id'): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[input[@name="action" and @value="' . $action
        . '"] and input[@name="' . $idName . '" and @value="' . $inquiryId . '"]]')->item(0);
    expectInquiryArchive($form instanceof DOMElement, 'Inquiry has an ' . $action . ' form');
    $fields = [];
    foreach ($xpath->query('.//input', $form) as $input) {
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    return $fields;
}

$suffix = bin2hex(random_bytes(5));
$users = $sessions = $inquiries = [];
$organizationId = $engagementId = 0;
try {
    foreach (['editor', 'admin', 'reviewer'] as $role) {
        $conn->execute_query('INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            ['inquiry-archive-' . $role . '-' . $suffix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
        $id = $users[$role] = (int) $conn->insert_id;
        $user = $conn->query("SELECT * FROM users WHERE id = {$id}")->fetch_assoc();
        session_start();
        $_SESSION = ['user_id' => $id, 'username' => $user['username'], 'role' => $role,
            'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        completeIntegrationTestMfaSession();
        $sessions[$role] = [session_id(), $_SESSION['_csrf_token']];
        session_write_close();
        session_id('');
    }
    [$editor, $csrf] = $sessions['editor'];
    $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Inquiry archive ' . $suffix]);
    $organizationId = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type)
        VALUES (?, 'Converted archive fixture', '2099-09-10', '2099-09-12', 'conference')", [$organizationId]);
    $engagementId = (int) $conn->insert_id;
    foreach (['new', 'declined', 'booked'] as $stage) {
        $conn->execute_query("INSERT INTO booking_inquiries
            (title, stage, decline_reason, converted_at, converted_engagement_id, owner_user_id)
            VALUES (?, ?, ?, ?, ?, ?)", ['Archive ' . $stage . ' ' . $suffix, $stage,
                $stage === 'declined' ? 'Host declined' : null,
                $stage === 'booked' ? gmdate('Y-m-d H:i:s') : null,
                $stage === 'booked' ? $engagementId : null, $users['editor']]);
        $inquiries[$stage] = (int) $conn->insert_id;
    }
    $countBefore = fetchDashboardOpenBookingInquiryCount($conn, '2000-01-01', '2100-01-01');
    foreach (['declined', 'booked'] as $stage) {
        $id = $inquiries[$stage];
        $path = 'view_inquiry.php?id=' . $id;
        foreach (['editor', 'admin', 'reviewer'] as $role) {
            $page = inquiryArchiveHttp($path, $sessions[$role][0]);
            expectInquiryArchive($page['status'] === 200
                && str_contains($page['body'], 'value="archive"') === ($role !== 'reviewer'), 'Archive controls respect roles');
        }
        $board = inquiryArchiveHttp('inquiries.php?view=' . $stage . '&owner=me', $editor);
        $fields = inquiryArchiveFields($board['body'], $id, 'archive');
        expectInquiryArchive(inquiryArchiveHttp('inquiries.php', $editor,
            array_replace($fields, ['csrf_token' => 'invalid']))['status'] === 400, 'Archive requires CSRF');
        expectInquiryArchive(inquiryArchiveHttp('inquiries.php', $sessions['reviewer'][0],
            array_replace($fields, ['csrf_token' => $sessions['reviewer'][1]]))['status'] === 403, 'Reviewer cannot archive');
        $result = inquiryArchiveHttp('inquiries.php', $editor, $fields);
        expectInquiryArchive($result['status'] === 302 && str_contains($result['headers'], 'owner=me'), 'Archive keeps board filters');
        $archived = fetchBookingInquiry($conn, $id);
        expectInquiryArchive(!empty($archived['archived_at']), 'Archive persists');
        foreach (['active', 'booked', 'declined', 'all'] as $view) {
            foreach (['', '&export=csv'] as $export) {
                $page = inquiryArchiveHttp('inquiries.php?view=' . $view . $export, $editor);
                expectInquiryArchive($page['status'] === 200 && !str_contains($page['body'], $archived['title']),
                    'Archived inquiry leaves pipeline view/export ' . $view);
            }
        }
        $archivePage = inquiryArchiveHttp('inquiries.php?view=archived&owner=me&q=' . $suffix, $editor);
        expectInquiryArchive($archivePage['status'] === 200 && str_contains($archivePage['body'], $archived['title']), 'Archive is searchable');
        $csv = inquiryArchiveHttp('inquiries.php?view=archived&export=csv', $editor);
        expectInquiryArchive(str_contains($csv['body'], $archived['title']) && str_contains($csv['headers'], 'archived-inquiries-'), 'Archive can be exported');
        $detail = inquiryArchiveHttp($path, $editor);
        expectInquiryArchive($detail['status'] === 200 && str_contains($detail['body'], 'Archived Inquiry')
            && !str_contains($detail['body'], 'value="change_stage"')
            && !str_contains($detail['body'], 'value="add_chron"'), 'Archived detail is readable without editing controls');
        expectInquiryArchive(inquiryArchiveHttp('edit_inquiry.php?id=' . $id, $editor)['status'] === 302, 'Archived edit route redirects');
        $restore = inquiryArchiveFields($detail['body'], $id, 'restore', 'id');
        expectInquiryArchive(inquiryArchiveHttp('view_inquiry.php', $editor,
            array_replace($restore, ['csrf_token' => 'invalid']))['status'] === 400, 'Detail restore requires CSRF');
        expectInquiryArchive(inquiryArchiveHttp('view_inquiry.php', $sessions['reviewer'][0],
            array_replace($restore, ['csrf_token' => $sessions['reviewer'][1]]))['status'] === 403, 'Reviewer cannot restore');
        inquiryArchiveHttp('view_inquiry.php', $editor, array_replace($restore, ['inquiry_version' => $fields['inquiry_version']]));
        expectInquiryArchive(!empty(fetchBookingInquiry($conn, $id)['archived_at']), 'Stale restore cannot overwrite archive');
        inquiryArchiveHttp('view_inquiry.php', $editor, array_replace($restore, ['action' => 'add_chron', 'chron_entry' => 'Blocked edit']));
        expectInquiryArchive((int) $conn->query("SELECT COUNT(*) AS total FROM booking_inquiry_chron_entries WHERE booking_inquiry_id = {$id}")->fetch_assoc()['total'] === 0, 'Archived Chron rejects direct edits');
        if ($stage === 'booked') {
            expectInquiryArchive(fetchDashboardOpenBookingInquiryCount($conn, '2000-01-01', '2100-01-01') === $countBefore - 1, 'Archived bookings leave dashboard count');
        }
        inquiryArchiveHttp('view_inquiry.php', $editor, $restore);
        $restored = fetchBookingInquiry($conn, $id);
        expectInquiryArchive($restored['archived_at'] === null && $restored['stage'] === $stage, 'Restore preserves outcome');
        $fields = inquiryArchiveFields(inquiryArchiveHttp($path, $editor)['body'], $id, 'archive', 'id');
        inquiryArchiveHttp('view_inquiry.php', $editor, $fields);
        expectInquiryArchive(!empty(fetchBookingInquiry($conn, $id)['archived_at']), 'Detail archive persists');
        $restore = inquiryArchiveFields(inquiryArchiveHttp('inquiries.php?view=archived', $editor)['body'], $id, 'restore');
        inquiryArchiveHttp('inquiries.php', $editor, $restore);
        expectInquiryArchive(fetchBookingInquiry($conn, $id)['archived_at'] === null, 'Board restore persists');
    }
    $active = fetchBookingInquiry($conn, $inquiries['new']);
    inquiryArchiveHttp('inquiries.php', $editor, ['csrf_token' => $csrf, 'action' => 'archive',
        'inquiry_id' => $active['id'], 'inquiry_version' => $active['updated_at']]);
    expectInquiryArchive(fetchBookingInquiry($conn, (int) $active['id'])['archived_at'] === null, 'Active inquiries reject direct archive requests');
    expectInquiryArchive(fetchDashboardOpenBookingInquiryCount($conn, '2000-01-01', '2100-01-01') === $countBefore, 'Restored bookings return to dashboard count');

    // Deleting the engagement clears its foreign key, but preserves conversion history.
    $bookedId = $inquiries['booked'];
    $convertedAt = fetchBookingInquiry($conn, $bookedId)['converted_at'];
    $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    $engagementId = 0;
    $unlinked = fetchBookingInquiry($conn, $bookedId);
    expectInquiryArchive($unlinked['converted_engagement_id'] === null && $unlinked['converted_at'] === $convertedAt,
        'Deleting an engagement preserves the recorded conversion');
    $detail = inquiryArchiveHttp('view_inquiry.php?id=' . $bookedId, $editor);
    $archive = inquiryArchiveFields($detail['body'], $bookedId, 'archive', 'id');
    inquiryArchiveFields(inquiryArchiveHttp('inquiries.php?view=booked', $editor)['body'], $bookedId, 'archive');
    inquiryArchiveHttp('view_inquiry.php', $editor, $archive);
    $archived = fetchBookingInquiry($conn, $bookedId);
    expectInquiryArchive(!empty($archived['archived_at']) && $archived['stage'] === 'booked'
        && $archived['converted_at'] === $convertedAt && $archived['converted_engagement_id'] === null,
        'Converted inquiries can be archived after their engagement is deleted');
    $restore = inquiryArchiveFields(inquiryArchiveHttp('inquiries.php?view=archived', $editor)['body'], $bookedId, 'restore');
    inquiryArchiveHttp('inquiries.php', $editor, $restore);
    expectInquiryArchive(fetchBookingInquiry($conn, $bookedId)['archived_at'] === null,
        'Converted inquiries with deleted engagements can be restored');
} finally {
    foreach ($inquiries as $id) {
        $conn->query("DELETE FROM booking_inquiries WHERE id = {$id}");
    }
    if ($engagementId > 0) {
        $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
    foreach ($users as $id) {
        $conn->query("DELETE FROM users WHERE id = {$id}");
    }
    foreach ($sessions as [$id]) {
        session_id($id);
        session_start();
        session_destroy();
    }
}
echo "Inquiry archive HTTP integration tests passed.\n";
