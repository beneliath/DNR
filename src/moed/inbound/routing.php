<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $message
 * @return array{
 *   automatic: bool, authoritative_engagement: bool,
 *   authoritative_inquiry: bool,
 *   sender_authentication_required: bool, sender_authenticated: bool,
 *   authentication_server: ?string, sender_authentication_method: ?string,
 *   reasons: list<string>, sender: array<string, mixed>,
 *   participants: list<array<string, mixed>>,
 *   contacts: list<array{id: int, label: string}>,
 *   organizations: list<array{id: int, label: string}>,
 *   engagements: list<array<string, mixed>>,
 *   inquiries: list<array<string, mixed>>
 * }
 */
function routeInboundEmailMessage(mysqli $conn, array $message, bool $automaticOnly = false): array
{
    $senderAddress = normalizeInboundEmailAddress($message['sender_address'] ?? '');
    $gatewayAddress = normalizeInboundEmailAddress($message['gateway_address'] ?? '');
    $to = inboundEmailDecodeAddressList($message['to_addresses'] ?? '[]');
    $cc = inboundEmailDecodeAddressList($message['cc_addresses'] ?? '[]');
    $participantAddresses = array_values(array_unique(array_merge([$senderAddress], $to, $cc)));
    $participantAddresses = array_values(array_filter(
        $participantAddresses,
        static fn(string $address): bool => $address !== '' && $address !== $gatewayAddress
    ));
    if (count($participantAddresses) > INBOUND_EMAIL_MAX_PARTICIPANTS) {
        throw new InvalidArgumentException('The inbound message has too many participants.');
    }
    $senderAuthentication = inboundEmailSenderAuthentication($message);
    // The review UI can request candidate matches later. Rejected automatic mail
    // does not need participant queries while the worker holds its row lock.
    $earlyReason = '';
    if ($automaticOnly && $senderAuthentication['required'] && !$senderAuthentication['trusted']) {
        $earlyReason = (string) $senderAuthentication['reason'];
    } elseif ($automaticOnly && inboundEmailMessageEngagementMarkers($message)['ids'] === []
        && inboundEmailMessageInquiryMarkers($message)['ids'] === []) {
        $earlyReason = 'No valid Engagement or Inquiry marker was provided.';
    }
    if ($earlyReason !== '') {
        return [
            'automatic' => false, 'authoritative_engagement' => false, 'authoritative_inquiry' => false,
            'sender_authentication_required' => $senderAuthentication['required'],
            'sender_authenticated' => $senderAuthentication['trusted'],
            'authentication_server' => $senderAuthentication['authentication_server'],
            'sender_authentication_method' => $senderAuthentication['method'],
            'reasons' => [$earlyReason],
            'sender' => ['type' => 'unknown', 'id' => null, 'label' => $senderAddress],
            'participants' => [], 'contacts' => [], 'organizations' => [], 'engagements' => [], 'inquiries' => [],
        ];
    }
    $matches = inboundEmailBatchMatches($conn, array_values(array_unique(array_merge($participantAddresses, [$senderAddress]))));
    $senderUsers = $matches['users'][$senderAddress] ?? [];
    $senderContacts = $matches['contacts'][$senderAddress] ?? [];
    $senderOrganizations = $matches['organizations'][$senderAddress] ?? [];

    $sender = ['type' => 'unknown', 'id' => null, 'label' => $senderAddress];
    $reasons = [];
    if (count($senderUsers) === 1) {
        $sender = [
            'type' => 'user',
            'id' => $senderUsers[0]['id'],
            'label' => $senderUsers[0]['username'],
        ];
    } elseif (count($senderContacts) === 1) {
        $sender = [
            'type' => 'contact',
            'id' => $senderContacts[0]['id'],
            'label' => $senderContacts[0]['label'],
        ];
    } elseif (count($senderOrganizations) === 1) {
        $sender = [
            'type' => 'organization',
            'id' => $senderOrganizations[0]['id'],
            'label' => $senderOrganizations[0]['label'],
        ];
    } elseif (count($senderContacts) > 1 || count($senderOrganizations) > 1) {
        $reasons[] = 'The sender address matches more than one active record.';
    } else {
        $reasons[] = 'The sender is not a uniquely recognized active user, Contact, or Organization.';
    }
    if ($senderAuthentication['required'] && !$senderAuthentication['trusted']) {
        $reasons[] = (string) $senderAuthentication['reason'];
    }

    $engagements = [];
    $inquiries = [];
    $markers = inboundEmailMessageEngagementMarkers($message);
    $authoritativeEngagement = false;
    if ($markers['invalid'] !== []) {
        $reasons[] = 'The message contains an invalid Engagement marker. Use the exact format '
            . applicationInboundMarkerExample(123) . '.';
    }
    if (count($markers['ids']) > 1) {
        $reasons[] = 'The message contains more than one Engagement marker.';
    } elseif (count($markers['ids']) === 1) {
        $engagement = inboundEmailEngagementMatch($conn, $markers['ids'][0]);
        if ($engagement === null) {
            $reasons[] = applicationInboundMarker($markers['ids'][0])
                . ' does not identify an active Engagement.';
        } else {
            $engagements[$engagement['id']] = $engagement;
            $authoritativeEngagement = $markers['invalid'] === [];
        }
    }

    $inquiryMarkers = inboundEmailMessageInquiryMarkers($message);
    $authoritativeInquiry = false;
    if ($inquiryMarkers['invalid'] !== []) {
        $reasons[] = 'The message contains an invalid Inquiry marker.';
    }
    if (count($inquiryMarkers['ids']) > 1) {
        $reasons[] = 'The message contains more than one Inquiry marker.';
    } elseif (count($inquiryMarkers['ids']) === 1) {
        $inquiry = inboundEmailInquiryMatch($conn, $inquiryMarkers['ids'][0]);
        if ($inquiry === null) {
            $reasons[] = applicationInquiryInboundMarker($inquiryMarkers['ids'][0])
                . ' does not identify an active Inquiry.';
        } elseif ($inquiry['stage'] === 'booked') {
            $convertedEngagement = inboundEmailEngagementMatch(
                $conn,
                (int) ($inquiry['converted_engagement_id'] ?? 0)
            );
            if ($convertedEngagement === null) {
                $reasons[] = applicationInquiryInboundMarker($inquiryMarkers['ids'][0])
                    . ' identifies a Booked Inquiry whose converted Engagement is unavailable.';
            } elseif ($engagements !== []
                && !isset($engagements[$convertedEngagement['id']])
            ) {
                $reasons[] = 'The Engagement and Booked Inquiry markers identify different Engagements.';
                $authoritativeEngagement = false;
            } else {
                $engagements[$convertedEngagement['id']] = $convertedEngagement;
                $authoritativeEngagement = $inquiryMarkers['invalid'] === [];
            }
        } else {
            $inquiries[$inquiry['id']] = $inquiry;
            $authoritativeInquiry = $inquiryMarkers['invalid'] === [];
        }
    }
    if ($authoritativeEngagement && $authoritativeInquiry) {
        $reasons[] = 'The message contains both an Engagement and an Inquiry marker.';
        $authoritativeEngagement = false;
        $authoritativeInquiry = false;
    }

    $contacts = [];
    $organizations = [];
    $participants = [];
    foreach ($participantAddresses as $address) {
        $userMatches = $matches['users'][$address] ?? [];
        $contactMatches = $matches['contacts'][$address] ?? [];
        $organizationMatches = $matches['organizations'][$address] ?? [];
        $isInternalUser = count($userMatches) === 1;

        if ($isInternalUser) {
            $contactMatches = [];
            $organizationMatches = [];
        }

        foreach ($contactMatches as $contact) {
            $contacts[$contact['id']] = [
                'id' => $contact['id'],
                'label' => $contact['label'],
            ];
            if ($contact['organization_id'] !== null) {
                $organizations[$contact['organization_id']] = [
                    'id' => $contact['organization_id'],
                    'label' => $contact['organization_label'],
                ];
            }
        }
        foreach ($organizationMatches as $organization) {
            $organizations[$organization['id']] = $organization;
        }

        $distinctOrganizationIds = [];
        foreach ($contactMatches as $contact) {
            if ($contact['organization_id'] !== null) {
                $distinctOrganizationIds[$contact['organization_id']] = true;
            }
        }
        foreach ($organizationMatches as $organization) {
            $distinctOrganizationIds[$organization['id']] = true;
        }
        if (count($contactMatches) > 1) {
            $reasons[] = "{$address} matches more than one active Contact.";
        }
        if (count($organizationMatches) > 1 || count($distinctOrganizationIds) > 1) {
            $reasons[] = "{$address} identifies more than one active Organization.";
        }

        $participants[] = [
            'address' => $address,
            'internal_user' => $isInternalUser,
            'contacts' => $contactMatches,
            'organizations' => $organizationMatches,
        ];
    }

    if (in_array($sender['type'], ['contact', 'organization'], true)) {
        foreach ($engagements as $engagement) {
            $engagementOrganizationId = $engagement['organization_id'];
            if ($engagementOrganizationId > 0 && !isset($organizations[$engagementOrganizationId])) {
                $reasons[] = (string) $engagement['marker']
                    . ' belongs to an Organization not identified by the message participants.';
            }
        }
        foreach ($inquiries as $inquiry) {
            $inquiryOrganizationId = $inquiry['organization_id'];
            if ($inquiryOrganizationId > 0 && !isset($organizations[$inquiryOrganizationId])) {
                $reasons[] = (string) $inquiry['marker']
                    . ' belongs to an Organization not identified by the message participants.';
            }
        }
    }

    if (!$contacts && !$organizations && !$engagements && !$inquiries) {
        $reasons[] = 'No active Contact, Organization, Inquiry, or Engagement was matched.';
    }
    $reasons = array_values(array_unique($reasons));
    ksort($contacts);
    ksort($organizations);
    ksort($engagements);
    ksort($inquiries);

    $recognizedSender = in_array($sender['type'], ['user', 'contact', 'organization'], true);
    return [
        'automatic' => ($authoritativeEngagement || $authoritativeInquiry)
            && $recognizedSender
            && (!$senderAuthentication['required'] || $senderAuthentication['trusted']),
        'authoritative_engagement' => $authoritativeEngagement,
        'authoritative_inquiry' => $authoritativeInquiry,
        'sender_authentication_required' => $senderAuthentication['required'],
        'sender_authenticated' => $senderAuthentication['trusted'],
        'authentication_server' => $senderAuthentication['authentication_server'],
        'sender_authentication_method' => $senderAuthentication['method'],
        'reasons' => $reasons,
        'sender' => $sender,
        'participants' => $participants,
        'contacts' => array_values($contacts),
        'organizations' => array_values($organizations),
        'engagements' => array_values($engagements),
        'inquiries' => array_values($inquiries),
    ];
}
