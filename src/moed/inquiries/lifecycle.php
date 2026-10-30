<?php

declare(strict_types=1);

function setBookingInquiryArchived(
    mysqli $conn,
    int $inquiryId,
    bool $archive,
    string $expectedVersion,
    int $userId
): void {
    $conn->begin_transaction();
    try {
        $inquiry = fetchBookingInquiry($conn, $inquiryId, true);
        if (!$inquiry) {
            throw new InvalidArgumentException('That inquiry is no longer available.');
        }
        if ($expectedVersion === '' || !hash_equals((string) $inquiry['updated_at'], $expectedVersion)) {
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before archiving or restoring.');
        }
        if ($archive && !canArchiveBookingInquiry($inquiry)) {
            throw new InvalidArgumentException('Only unarchived declined inquiries or booked inquiries converted to an engagement can be archived.');
        }
        if (!$archive && empty($inquiry['archived_at'])) {
            throw new InvalidArgumentException('That inquiry is not archived.');
        }
        $stmt = $conn->prepare($archive
            ? 'UPDATE booking_inquiries SET archived_at = UTC_TIMESTAMP(6), archived_by = ? WHERE id = ?'
            : 'UPDATE booking_inquiries SET archived_at = NULL, archived_by = NULL WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the inquiry archive update.');
        }
        if ($archive) {
            $stmt->bind_param('ii', $userId, $inquiryId);
        } else {
            $stmt->bind_param('i', $inquiryId);
        }
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
