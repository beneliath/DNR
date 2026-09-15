<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/booking_inquiry_helpers.php';

foreach (bookingInquiryStages() as $stage => $label) {
    foreach ([null, 42] as $engagementId) {
        $inquiry = ['stage' => $stage, 'converted_engagement_id' => $engagementId,
            'converted_at' => $stage === 'booked' ? '2026-09-15 12:00:00' : null,
            'archived_at' => null];
        if (canArchiveBookingInquiry($inquiry) !== in_array($stage, ['declined', 'booked'], true)) {
            throw new RuntimeException('Archive eligibility must follow outcome and conversion history: ' . $label);
        }
        $inquiry['archived_at'] = '2026-09-15 13:00:00';
        if (canArchiveBookingInquiry($inquiry)) {
            throw new RuntimeException('An archived inquiry cannot be archived again.');
        }
    }
}
if (canArchiveBookingInquiry(['stage' => 'booked', 'converted_at' => null, 'converted_engagement_id' => 42])) {
    throw new RuntimeException('A booked inquiry must have a recorded conversion.');
}

echo "Inquiry archive helper tests passed.\n";
