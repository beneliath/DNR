<?php

declare(strict_types=1);

/** @return list<array<string, mixed>> */
function bookingInquiryOwners(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT id, username, role FROM users
         WHERE account_status = 'active' ORDER BY username, id"
    );
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** @return array<string, mixed>|null */
function fetchBookingInquiry(mysqli $conn, int $inquiryId, bool $lock = false): ?array
{
    if ($inquiryId < 1) {
        return null;
    }
    $sql = "SELECT inquiry.*,
                   organization.organization_name,
                   organization.is_deleted AS organization_deleted,
                   TRIM(CONCAT_WS(' ', contact.contact_first_name,
                                      contact.contact_last_name)) AS contact_name,
                   contact.contact_email, contact.contact_phone,
                   contact.organization_id AS contact_organization_id,
                   contact.is_deleted AS contact_deleted,
                   owner.username AS owner_username,
                   creator.username AS creator_username,
                   stage_actor.username AS stage_changed_by_username,
                   engagement.event_title AS converted_engagement_title
            FROM booking_inquiries inquiry
            LEFT JOIN organizations organization ON organization.id = inquiry.organization_id
            LEFT JOIN contacts contact ON contact.id = inquiry.primary_contact_id
            LEFT JOIN users owner ON owner.id = inquiry.owner_user_id
            LEFT JOIN users creator ON creator.id = inquiry.created_by
            LEFT JOIN users stage_actor ON stage_actor.id = inquiry.stage_changed_by
            LEFT JOIN engagements engagement ON engagement.id = inquiry.converted_engagement_id
            WHERE inquiry.id = ?";
    if ($lock) {
        $sql = 'SELECT * FROM booking_inquiries WHERE id = ? FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the inquiry lookup.');
    }
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $inquiry = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $inquiry;
}

/** @return list<array<string, mixed>> */
function fetchBookingInquiryStageHistory(mysqli $conn, int $inquiryId): array
{
    $stmt = $conn->prepare(
        'SELECT history.*,
                COALESCE(user.username, history.changed_by_username_snapshot) AS changed_by_username
         FROM booking_inquiry_stage_history history
         LEFT JOIN users user ON user.id = history.changed_by
         WHERE history.booking_inquiry_id = ?
         ORDER BY history.changed_at DESC, history.id DESC'
    );
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}
