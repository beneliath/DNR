<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Booking inquiry integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('Q', 32)));
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('I', 32)));
putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM=0');
putenv('DNR_MAIL_TRANSPORT=smtp');
putenv('DNR_INBOUND_ADDRESS=replies@example.test');

require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/follow_up_task_helpers.php';
require_once $sourceDirectory . '/engagement_contact_helpers.php';
require_once $sourceDirectory . '/map_helpers.php';
require_once $sourceDirectory . '/inbound_email_helpers.php';
require_once $sourceDirectory . '/booking_inquiry_helpers.php';

function expectBookingInquiryIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Booking inquiry integration test failed: {$message}");
    }
}

$suffix = bin2hex(random_bytes(5));
$userId = 0;
$organizationId = 0;
$contactId = 0;
$inboundEmailId = 0;
$replyInboundEmailId = 0;
$postConversionReplyInboundEmailId = 0;
$inquiryId = 0;
$taskId = 0;
$messageId = 0;
$engagementId = 0;

try {
    $invalidPreferredRangeRejected = false;
    $invalidAlternateRangeRejected = false;
    try {
        bookingInquiryDateRange('2099-10-12', '2099-10-11', 'Preferred date range');
    } catch (InvalidArgumentException $exception) {
        $invalidPreferredRangeRejected = true;
    }
    try {
        bookingInquiryDateRange('2099-11-12', '2099-11-11', 'Alternate date range');
    } catch (InvalidArgumentException $exception) {
        $invalidAlternateRangeRejected = true;
    }
    expectBookingInquiryIntegration(
        $invalidPreferredRangeRejected && $invalidAlternateRangeRejected,
        'preferred and alternate end dates must not precede their selected start dates.'
    );

    $username = 'inquiry-test-' . $suffix;
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $user = $conn->prepare(
        "INSERT INTO users (username, password, role, account_status)
         VALUES (?, ?, 'editor', 'active')"
    );
    $user->bind_param('ss', $username, $password);
    $user->execute();
    $userId = (int) $conn->insert_id;
    $user->close();

    $organizationName = 'Inquiry Test Organization ' . $suffix;
    $organization = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organization->bind_param('s', $organizationName);
    $organization->execute();
    $organizationId = (int) $conn->insert_id;
    $organization->close();

    $contactEmail = 'inquiry-' . $suffix . '@example.test';
    $contact = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (?, 'Taylor', 'Host', 'admin', ?, 0)"
    );
    $contact->bind_param('is', $organizationId, $contactEmail);
    $contact->execute();
    $contactId = (int) $conn->insert_id;
    $contact->close();

    $transportKey = 'inquiry-source-' . $suffix;
    $deduplicationHash = random_bytes(32);
    $gatewayAddress = 'replies@example.test';
    $toAddresses = json_encode([$gatewayAddress], JSON_THROW_ON_ERROR);
    $sourceSubject = 'Source inquiry ' . $suffix;
    $sourceReceivedAt = '2026-08-01 12:00:00';
    $sourceBody = 'Source email body for the booking inquiry.';
    $rawHeaders = 'Message-ID: <' . $suffix . '@example.test>';
    $source = $conn->prepare(
        "INSERT INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, gateway_address,
             sender_name, sender_address, to_addresses, cc_addresses,
             subject, received_at, body_text, attachment_names, raw_headers, status)
         VALUES ('file', ?, ?, ?, 'Taylor Host', ?, ?, '[]', ?, ?, ?, '[]', ?, 'review')"
    );
    $source->bind_param(
        'sssssssss',
        $transportKey,
        $deduplicationHash,
        $gatewayAddress,
        $contactEmail,
        $toAddresses,
        $sourceSubject,
        $sourceReceivedAt,
        $sourceBody,
        $rawHeaders
    );
    $source->execute();
    $inboundEmailId = (int) $conn->insert_id;
    $source->close();
    $sourceMessage = $conn->query(
        "SELECT * FROM inbound_email_messages WHERE id = {$inboundEmailId}"
    )->fetch_assoc();
    $sourceChron = formatInboundEmailChronEntry($sourceMessage);

    $input = [
        'title' => 'Inquiry Pipeline Test ' . $suffix,
        'organization_id' => (string) $organizationId,
        'primary_contact_id' => (string) $contactId,
        'request_summary' => 'A request that should remain available after booking.',
        'event_type' => 'conference',
        'preferred_start_date' => '2099-10-10',
        'preferred_end_date' => '2099-10-12',
        'source' => 'email',
        'source_detail' => $contactEmail,
        'owner_user_id' => (string) $userId,
        'priority' => 'high',
        'next_action' => 'Confirm the proposed date range',
        'next_action_due_date' => '2099-09-01',
    ];
    $normalized = normalizeBookingInquiryInput($conn, $input);
    $inquiryId = createBookingInquiry(
        $conn,
        $normalized,
        $userId,
        $username,
        $inboundEmailId,
        $sourceChron
    );
    $inquiry = fetchBookingInquiry($conn, $inquiryId);
    $history = fetchBookingInquiryStageHistory($conn, $inquiryId);
    $processedSource = $conn->query(
        "SELECT status, processed_by FROM inbound_email_messages WHERE id = {$inboundEmailId}"
    )->fetch_assoc();
    $sourceChronCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM booking_inquiry_chron_entries
         WHERE booking_inquiry_id = {$inquiryId}
           AND inbound_email_message_id = {$inboundEmailId}
           AND created_at = '{$sourceReceivedAt}'"
    )->fetch_assoc()['total'];
    expectBookingInquiryIntegration(
        $inquiry !== null
            && $inquiry['stage'] === 'new'
            && (int) $inquiry['organization_id'] === $organizationId
            && (int) $inquiry['primary_contact_id'] === $contactId
            && count($history) === 1
            && $history[0]['to_stage'] === 'new'
            && $processedSource !== null
            && $processedSource['status'] === 'processed'
            && (int) $processedSource['processed_by'] === $userId
            && $sourceChronCount === 1,
        'creation should retain qualification details, preserve source mail, and clear its review item.'
    );

    $emptyStageRejected = false;
    try {
        changeBookingInquiryStage(
            $conn,
            $inquiryId,
            '',
            null,
            (string) $inquiry['updated_at'],
            $userId,
            $username
        );
    } catch (InvalidArgumentException $exception) {
        $emptyStageRejected = str_contains($exception->getMessage(), 'valid inquiry stage');
    }
    expectBookingInquiryIntegration(
        $emptyStageRejected,
        'an empty stage selection must not move an Inquiry to a default stage.'
    );

    changeBookingInquiryStage(
        $conn,
        $inquiryId,
        'qualified',
        'Required details were confirmed.',
        (string) $inquiry['updated_at'],
        $userId,
        $username
    );
    $inquiry = fetchBookingInquiry($conn, $inquiryId);
    expectBookingInquiryIntegration(
        $inquiry !== null && $inquiry['stage'] === 'qualified',
        'an active inquiry should move stages with optimistic concurrency.'
    );
    $organizationDependencies = \Dnr\Service\ArchiveService::organizationActiveDependencyCounts(
        $conn,
        $organizationId
    );
    expectBookingInquiryIntegration(
        $organizationDependencies !== null
            && $organizationDependencies['inquiries'] === 1
            && \Dnr\Service\ArchiveService::contactActiveInquiryCount($conn, $contactId) === 1
            && \Dnr\Service\ArchiveService::setArchived(
                $conn,
                'contact',
                $contactId,
                true
            ) === false,
        'active Inquiry relationships should prevent dependent Contacts from being archived.'
    );

    $replyTransportKey = 'inquiry-reply-' . $suffix;
    $replyDeduplicationHash = random_bytes(32);
    $replySubject = 'Re: proposal ' . applicationInquiryInboundMarker($inquiryId);
    $replyReceivedAt = '2026-08-02 12:00:00';
    $replyBody = 'The proposed dates work for us.';
    $replyRawHeaders = 'Message-ID: <reply-' . $suffix . '@example.test>';
    $reply = $conn->prepare(
        "INSERT INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, gateway_address,
             sender_name, sender_address, to_addresses, cc_addresses,
             subject, received_at, body_text, attachment_names, raw_headers, status)
         VALUES ('file', ?, ?, ?, 'Taylor Host', ?, ?, '[]', ?, ?, ?, '[]', ?, 'pending')"
    );
    $reply->bind_param(
        'sssssssss',
        $replyTransportKey,
        $replyDeduplicationHash,
        $gatewayAddress,
        $contactEmail,
        $toAddresses,
        $replySubject,
        $replyReceivedAt,
        $replyBody,
        $replyRawHeaders
    );
    $reply->execute();
    $replyInboundEmailId = (int) $conn->insert_id;
    $reply->close();
    $replyResult = processInboundEmailMessage($conn, $replyInboundEmailId);
    $routedReplyCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM booking_inquiry_chron_entries
         WHERE booking_inquiry_id = {$inquiryId}
           AND inbound_email_message_id = {$replyInboundEmailId}"
    )->fetch_assoc()['total'];
    expectBookingInquiryIntegration(
        $replyResult === 'processed' && $routedReplyCount === 1,
        'a reply with the signed Inquiry marker should route back to the Inquiry Chron.'
    );

    $taskTitle = 'Send proposal ' . $suffix;
    $task = $conn->prepare(
        "INSERT INTO follow_up_tasks
            (title, status, priority, subject_type, inquiry_id, assigned_to, created_by)
         VALUES (?, 'open', 'high', 'inquiry', ?, ?, ?)"
    );
    $task->bind_param('siii', $taskTitle, $inquiryId, $userId, $userId);
    $task->execute();
    $taskId = (int) $conn->insert_id;
    $task->close();
    $inquirySearchTerm = '+pipeline*';
    $inquiryTaskSearch = $conn->prepare(
        'SELECT task.id
         FROM follow_up_tasks task
         INNER JOIN booking_inquiries inquiry ON inquiry.id = task.inquiry_id
         WHERE task.id = ?
           AND MATCH(
               inquiry.title, inquiry.request_summary, inquiry.source_detail,
               inquiry.event_city, inquiry.event_state
           ) AGAINST (? IN BOOLEAN MODE)'
    );
    $inquiryTaskSearch->bind_param('is', $taskId, $inquirySearchTerm);
    $inquiryTaskSearch->execute();
    $inquiryTaskSearchMatch = $inquiryTaskSearch->get_result()->fetch_assoc();
    $inquiryTaskSearch->close();
    expectBookingInquiryIntegration(
        $inquiryTaskSearchMatch !== null && (int) $inquiryTaskSearchMatch['id'] === $taskId,
        'Work Queue search should find a task through its linked Inquiry details.'
    );

    $engagementMarkerRejected = false;
    try {
        normalizeBookingInquiryEmailSubject(
            'Wrong record type ' . applicationInboundMarker(42),
            $inquiryId
        );
    } catch (InvalidArgumentException $exception) {
        $engagementMarkerRejected = str_contains(
            $exception->getMessage(),
            'Engagement routing marker'
        );
    }
    expectBookingInquiryIntegration(
        $engagementMarkerRejected,
        'Inquiry email should reject an Engagement marker that would make reply routing ambiguous.'
    );

    $messageId = queueBookingInquiryEmail(
        $conn,
        $inquiry,
        'proposal_follow_up',
        'Proposal follow-up',
        'Hello, this is the inquiry follow-up.',
        $userId,
        $username
    );
    $message = $conn->query(
        "SELECT engagement_id, booking_inquiry_id
         FROM engagement_email_messages WHERE id = {$messageId}"
    )->fetch_assoc();
    $delivery = $conn->query(
        "SELECT status, recipient_email
         FROM engagement_email_deliveries WHERE message_id = {$messageId}"
    )->fetch_assoc();
    $inquiryChronCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM booking_inquiry_chron_entries
         WHERE outbound_email_message_id = {$messageId}"
    )->fetch_assoc()['total'];
    expectBookingInquiryIntegration(
        $message !== null
            && $message['engagement_id'] === null
            && (int) $message['booking_inquiry_id'] === $inquiryId
            && $delivery !== null
            && $delivery['status'] === 'pending'
            && $delivery['recipient_email'] === $contactEmail
            && $inquiryChronCount === 1,
        'outbound inquiry email should use the isolated queue and create linked Chron history.'
    );
    $outboundMessage = fetchEngagementEmailMessage($conn, $messageId);
    expectBookingInquiryIntegration(
        $outboundMessage !== null
            && (int) $outboundMessage['booking_inquiry_id'] === $inquiryId
            && $outboundMessage['engagement_id'] === null
            && $outboundMessage['inquiry_stage'] === 'qualified',
        'the shared outbound-message view should load Inquiry correspondence.'
    );
    $conn->query(
        "UPDATE engagement_email_deliveries
         SET status = 'failed', last_error = 'Simulated delivery failure'
         WHERE message_id = {$messageId}"
    );
    $retriedDeliveries = retryFailedEngagementEmailDeliveries($conn, $messageId);
    $retryStatus = $conn->query(
        "SELECT status FROM engagement_email_deliveries WHERE message_id = {$messageId}"
    )->fetch_assoc();
    expectBookingInquiryIntegration(
        $retriedDeliveries === 1 && $retryStatus !== null && $retryStatus['status'] === 'retry',
        'failed Inquiry correspondence should be recoverable while the Inquiry is active.'
    );

    $inquiry = fetchBookingInquiry($conn, $inquiryId);
    $conversion = convertBookingInquiry(
        $conn,
        $inquiryId,
        (string) $inquiry['updated_at'],
        true,
        [$taskId],
        $userId,
        $username
    );
    $engagementId = $conversion['engagement_id'];
    $booked = fetchBookingInquiry($conn, $inquiryId);
    $engagement = $conn->query(
        "SELECT organization_id, event_title, confirmation_status,
                caller_user_id, event_start_date, event_end_date
         FROM engagements WHERE id = {$engagementId}"
    )->fetch_assoc();
    $movedTask = $conn->query(
        "SELECT subject_type, engagement_id, inquiry_id
         FROM follow_up_tasks WHERE id = {$taskId}"
    )->fetch_assoc();
    $primaryHostCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM engagement_contacts
         WHERE engagement_id = {$engagementId}
           AND contact_id = {$contactId} AND contact_role = 'primary_host'"
    )->fetch_assoc()['total'];
    $sourceChronCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM engagement_chron_entries
         WHERE engagement_id = {$engagementId}
           AND entry_text LIKE 'BOOKED FROM INQUIRY #%'
    ")->fetch_assoc()['total'];
    expectBookingInquiryIntegration(
        $booked !== null
            && $booked['stage'] === 'booked'
            && (int) $booked['converted_engagement_id'] === $engagementId
            && $engagement !== null
            && (int) $engagement['organization_id'] === $organizationId
            && $engagement['confirmation_status'] === 'work_in_progress'
            && (int) $engagement['caller_user_id'] === $userId
            && $movedTask !== null
            && $movedTask['subject_type'] === 'engagement'
            && (int) $movedTask['engagement_id'] === $engagementId
            && $movedTask['inquiry_id'] === null
            && $conversion['moved_task_count'] === 1
            && $conversion['checklist_count'] > 0
            && $primaryHostCount === 1
            && $sourceChronCount === 1,
        'conversion should atomically create the engagement, move selected work, and preserve provenance.'
    );
    $conn->query(
        "UPDATE engagement_email_deliveries
         SET status = 'failed', last_error = 'Failure after booking'
         WHERE message_id = {$messageId}"
    );
    $bookedRetryRejected = false;
    try {
        retryFailedEngagementEmailDeliveries($conn, $messageId);
    } catch (InvalidArgumentException $exception) {
        $bookedRetryRejected = str_contains($exception->getMessage(), 'Inquiry is active');
    }
    expectBookingInquiryIntegration(
        $bookedRetryRejected,
        'a failed message should not be re-sent from the read-only Booked Inquiry.'
    );

    $postConversionTransportKey = 'booked-inquiry-reply-' . $suffix;
    $postConversionDeduplicationHash = random_bytes(32);
    $postConversionSubject = 'Re: booked event ' . applicationInquiryInboundMarker($inquiryId);
    $postConversionReceivedAt = '2026-08-03 12:00:00';
    $postConversionBody = 'Here are the final arrival details.';
    $postConversionRawHeaders = 'Message-ID: <booked-reply-' . $suffix . '@example.test>';
    $postConversionReply = $conn->prepare(
        "INSERT INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, gateway_address,
             sender_name, sender_address, to_addresses, cc_addresses,
             subject, received_at, body_text, attachment_names, raw_headers, status)
         VALUES ('file', ?, ?, ?, 'Taylor Host', ?, ?, '[]', ?, ?, ?, '[]', ?, 'pending')"
    );
    $postConversionReply->bind_param(
        'sssssssss',
        $postConversionTransportKey,
        $postConversionDeduplicationHash,
        $gatewayAddress,
        $contactEmail,
        $toAddresses,
        $postConversionSubject,
        $postConversionReceivedAt,
        $postConversionBody,
        $postConversionRawHeaders
    );
    $postConversionReply->execute();
    $postConversionReplyInboundEmailId = (int) $conn->insert_id;
    $postConversionReply->close();
    $postConversionReplyResult = processInboundEmailMessage(
        $conn,
        $postConversionReplyInboundEmailId
    );
    $convertedEngagementReplyCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM engagement_chron_entries
         WHERE engagement_id = {$engagementId}
           AND inbound_email_message_id = {$postConversionReplyInboundEmailId}"
    )->fetch_assoc()['total'];
    $bookedInquiryReplyCount = (int) $conn->query(
        "SELECT COUNT(*) AS total FROM booking_inquiry_chron_entries
         WHERE booking_inquiry_id = {$inquiryId}
           AND inbound_email_message_id = {$postConversionReplyInboundEmailId}"
    )->fetch_assoc()['total'];
    expectBookingInquiryIntegration(
        $postConversionReplyResult === 'processed'
            && $convertedEngagementReplyCount === 1
            && $bookedInquiryReplyCount === 0,
        'replies using a Booked Inquiry marker should continue into its converted Engagement Chron.'
    );

    $readOnlyRejected = false;
    try {
        updateBookingInquiry(
            $conn,
            $inquiryId,
            $normalized,
            (string) $booked['updated_at']
        );
    } catch (InvalidArgumentException $exception) {
        $readOnlyRejected = str_contains($exception->getMessage(), 'read-only');
    }
    expectBookingInquiryIntegration(
        $readOnlyRejected,
        'the booked inquiry should remain a read-only source record.'
    );

    $newTaskRejected = false;
    $conn->begin_transaction();
    try {
        normalizeFollowUpTaskInput($conn, [
            'title' => 'Late inquiry task',
            'status' => 'open',
            'priority' => 'normal',
            'subject' => 'inquiry:' . $inquiryId,
        ]);
    } catch (InvalidArgumentException $exception) {
        $newTaskRejected = str_contains($exception->getMessage(), 'active record');
    } finally {
        $conn->rollback();
    }
    expectBookingInquiryIntegration(
        $newTaskRejected,
        'new work should not attach to an inquiry after it is booked.'
    );
} finally {
    if ($messageId > 0) {
        $conn->query(
            "DELETE FROM organization_chron_entries
             WHERE outbound_email_message_id = {$messageId}"
        );
        $conn->query(
            "DELETE FROM contact_chron_entries
             WHERE outbound_email_message_id = {$messageId}"
        );
    }
    if ($replyInboundEmailId > 0) {
        $conn->query(
            "DELETE FROM organization_chron_entries
             WHERE inbound_email_message_id = {$replyInboundEmailId}"
        );
        $conn->query(
            "DELETE FROM contact_chron_entries
             WHERE inbound_email_message_id = {$replyInboundEmailId}"
        );
    }
    if ($postConversionReplyInboundEmailId > 0) {
        $conn->query(
            "DELETE FROM organization_chron_entries
             WHERE inbound_email_message_id = {$postConversionReplyInboundEmailId}"
        );
        $conn->query(
            "DELETE FROM contact_chron_entries
             WHERE inbound_email_message_id = {$postConversionReplyInboundEmailId}"
        );
    }
    if ($inquiryId > 0) {
        $conn->query("DELETE FROM booking_inquiries WHERE id = {$inquiryId}");
    }
    if ($engagementId > 0) {
        $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    }
    if ($inboundEmailId > 0) {
        $conn->query("DELETE FROM inbound_email_messages WHERE id = {$inboundEmailId}");
    }
    if ($replyInboundEmailId > 0) {
        $conn->query("DELETE FROM inbound_email_messages WHERE id = {$replyInboundEmailId}");
    }
    if ($postConversionReplyInboundEmailId > 0) {
        $conn->query(
            "DELETE FROM inbound_email_messages WHERE id = {$postConversionReplyInboundEmailId}"
        );
    }
    if ($contactId > 0) {
        $conn->query("DELETE FROM contacts WHERE id = {$contactId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
    if ($userId > 0) {
        $conn->query("DELETE FROM users WHERE id = {$userId}");
    }
    putenv('DNR_2FA_ENCRYPTION_KEY');
    putenv('DNR_INBOUND_ROUTING_KEY');
    putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM');
    putenv('DNR_MAIL_TRANSPORT');
    putenv('DNR_INBOUND_ADDRESS');
}

echo "Booking inquiry integration tests passed.\n";
