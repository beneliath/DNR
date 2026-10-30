<?php

declare(strict_types=1);

/** @return array<string, string> */
function bookingInquiryStages(): array
{
    return [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'awaiting_details' => 'Awaiting Details',
        'proposal_sent' => 'Proposal Sent',
        'booked' => 'Booked',
        'declined' => 'Declined',
    ];
}

/** @return list<string> */
function bookingInquiryActiveStages(): array
{
    return ['new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent'];
}

/** @return array<string, string> */
function bookingInquirySources(): array
{
    return [
        'email' => 'Email',
        'phone' => 'Phone',
        'website' => 'Website',
        'referral' => 'Referral',
        'mattermost' => 'Mattermost',
        'other' => 'Other',
    ];
}

/** @return array<string, string> */
function bookingInquirySelectableSources(): array
{
    $sources = bookingInquirySources();
    unset($sources['mattermost']);
    return $sources;
}

/** @return array<string, string> */
function bookingInquiryPriorities(): array
{
    return [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function canManageBookingInquiries(string $role): bool
{
    return in_array($role, ['admin', 'editor'], true);
}

/** @param array<string, mixed> $inquiry */
function canArchiveBookingInquiry(array $inquiry): bool
{
    // Conversion remains recorded after deleting an engagement clears its link.
    return empty($inquiry['archived_at'])
        && ($inquiry['stage'] === 'declined'
            || ($inquiry['stage'] === 'booked'
                && !empty($inquiry['converted_at'])));
}
