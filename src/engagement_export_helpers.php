<?php

require_once __DIR__ . '/chron_log_helpers.php';

function addEngagementExportField(array &$fields, $label, $value, $include_empty = false) {
    $value = is_string($value) ? trim($value) : (string) $value;
    if (!$include_empty && $value === '') {
        return;
    }

    $fields[] = [
        'label' => (string) $label,
        'value' => $value,
    ];
}

function buildEngagementExport(array $engagement, array $contacts, array $presentations, array $chron_entries = []) {
    $event_title = trim((string) ($engagement['event_title'] ?? ''));
    $organization_name = trim((string) ($engagement['organization_name'] ?? ''));
    $document_title = $event_title !== ''
        ? $event_title
        : ($organization_name !== '' ? $organization_name . ' Engagement' : 'Engagement');

    $overview_fields = [];
    addEngagementExportField($overview_fields, 'Organization', $organization_name, true);
    $event_type_label = ($engagement['event_type'] ?? '') === 'other'
        && trim((string) ($engagement['event_type_other'] ?? '')) !== ''
        ? $engagement['event_type_other']
        : ($engagement['event_type'] ?? '');
    addEngagementExportField($overview_fields, 'Event Type', $event_type_label, true);

    $event_start_date = trim((string) ($engagement['event_start_date'] ?? ''));
    $event_end_date = trim((string) ($engagement['event_end_date'] ?? ''));
    $event_dates = $event_start_date;
    if ($event_end_date !== '') {
        $event_dates .= ($event_dates !== '' ? ' to ' : '') . $event_end_date;
    }
    addEngagementExportField($overview_fields, 'Event Dates', $event_dates, true);

    $status = str_replace('_', ' ', trim((string) ($engagement['confirmation_status'] ?? '')));
    addEngagementExportField($overview_fields, 'Status', $status, true);
    if (!empty($engagement['is_deleted'])) {
        addEngagementExportField($overview_fields, 'Archived', 'Yes', true);
    }

    $sections = [[
        'heading' => 'Overview',
        'entries' => [['fields' => $overview_fields]],
    ]];

    $contact_entries = [];
    foreach ($contacts as $contact) {
        $name = trim(
            (string) ($contact['contact_first_name'] ?? '') . ' ' .
            (string) ($contact['contact_last_name'] ?? '')
        );
        $role = trim((string) ($contact['contact_role'] ?? ''));
        if ($role === 'other' && !empty($contact['contact_role_other'])) {
            $role = trim((string) $contact['contact_role_other']);
        } elseif ($role !== '') {
            $role = ucfirst($role);
        }

        $fields = [];
        addEngagementExportField($fields, 'Role', $role);
        addEngagementExportField($fields, 'Email', $contact['contact_email'] ?? '');
        addEngagementExportField($fields, 'Phone', $contact['contact_phone'] ?? '');
        $contact_entries[] = [
            'title' => $name !== '' ? $name : 'Contact',
            'fields' => $fields,
        ];
    }
    if ($contact_entries) {
        $sections[] = [
            'heading' => 'Contacts',
            'entries' => $contact_entries,
        ];
    }

    $event_detail_fields = [];
    addEngagementExportField(
        $event_detail_fields,
        'Event Description',
        $engagement['event_description'] ?? ''
    );
    addEngagementExportField(
        $event_detail_fields,
        'Book Table Provided',
        !empty($engagement['book_table']) ? 'Yes' : 'No',
        true
    );
    addEngagementExportField(
        $event_detail_fields,
        'Brochures Permitted',
        !empty($engagement['brochures']) ? 'Yes' : 'No',
        true
    );
    addEngagementExportField(
        $event_detail_fields,
        'All Travel Covered',
        ucfirst((string) ($engagement['travel_covered'] ?? 'unknown')),
        true
    );
    addEngagementExportField($event_detail_fields, 'Caller', $engagement['caller_name'] ?? '');
    $sections[] = [
        'heading' => 'Event Details',
        'entries' => [['fields' => $event_detail_fields]],
    ];

    $presentation_entries = [];
    foreach ($presentations as $presentation) {
        $presentation_date_time = trim(
            (string) ($presentation['presentation_date'] ?? '') . ' ' .
            (string) ($presentation['presentation_time'] ?? '')
        );
        $fields = [];
        addEngagementExportField($fields, 'Speaker', $presentation['speaker_name'] ?? '');
        addEngagementExportField($fields, 'Date and Time', $presentation_date_time);
        if (array_key_exists('expected_attendance', $presentation)
            && $presentation['expected_attendance'] !== null
        ) {
            addEngagementExportField(
                $fields,
                'Expected Attendance',
                (string) ((int) $presentation['expected_attendance']),
                true
            );
        }
        $presentation_entries[] = [
            'title' => trim((string) ($presentation['topic_title'] ?? '')) ?: 'Presentation',
            'fields' => $fields,
        ];
    }
    if ($presentation_entries) {
        $sections[] = [
            'heading' => 'Presentations',
            'entries' => $presentation_entries,
        ];
    }

    if (!empty($engagement['compensation_type'])) {
        $compensation_fields = [];
        addEngagementExportField(
            $compensation_fields,
            'Type',
            $engagement['compensation_type'],
            true
        );
        addEngagementExportField(
            $compensation_fields,
            'Details',
            $engagement['other_compensation'] ?? ''
        );
        if (array_key_exists('travel_amount', $engagement) && $engagement['travel_amount'] !== null) {
            addEngagementExportField(
                $compensation_fields,
                'Travel Amount',
                '$' . number_format((float) $engagement['travel_amount'], 2),
                true
            );
        }
        if (array_key_exists('housing_amount', $engagement) && $engagement['housing_amount'] !== null) {
            addEngagementExportField(
                $compensation_fields,
                'Lodging Amount',
                '$' . number_format((float) $engagement['housing_amount'], 2),
                true
            );
        }
        addEngagementExportField(
            $compensation_fields,
            'Lodging Type',
            $engagement['housing_type'] ?? ''
        );
        addEngagementExportField(
            $compensation_fields,
            'Lodging Details',
            $engagement['other_housing'] ?? ''
        );
        $sections[] = [
            'heading' => 'Compensation',
            'entries' => [['fields' => $compensation_fields]],
        ];
    }

    $address_parts = [];
    foreach (['event_address_line_1', 'event_address_line_2'] as $address_field) {
        $address_part = trim((string) ($engagement[$address_field] ?? ''));
        if ($address_part !== '') {
            $address_parts[] = $address_part;
        }
    }
    $city_line = trim(implode(', ', array_filter([
        trim((string) ($engagement['event_city'] ?? '')),
        trim((string) ($engagement['event_state'] ?? '')),
    ], function ($part) {
        return $part !== '';
    })));
    $zipcode = trim((string) ($engagement['event_zipcode'] ?? ''));
    if ($zipcode !== '') {
        $city_line = trim($city_line . ' ' . $zipcode);
    }
    if ($city_line !== '') {
        $address_parts[] = $city_line;
    }
    $country = trim((string) ($engagement['event_country'] ?? ''));
    if ($country !== '') {
        $address_parts[] = $country;
    }
    if ($address_parts) {
        $sections[] = [
            'heading' => 'Location',
            'entries' => [['fields' => [[
                'label' => 'Address',
                'value' => implode("\n", $address_parts),
            ]]]],
        ];
    }

    $chron_export_entries = [];
    foreach (sortChronLogEntriesReverseChronological($chron_entries) as $chron_entry) {
        if (!empty($chron_entry['is_archived'])) {
            continue;
        }
        $entry_text = trim((string) ($chron_entry['entry_text'] ?? ''));
        if ($entry_text === '') {
            continue;
        }
        $chron_export_entries[] = [
            'title' => chronLogEntryExportTitle($chron_entry),
            'fields' => [[
                'label' => 'Entry',
                'value' => $entry_text,
            ]],
        ];
    }
    if ($chron_export_entries) {
        $sections[] = [
            'heading' => 'Chron',
            'entries' => $chron_export_entries,
        ];
    }

    return [
        'title' => $document_title,
        'sections' => $sections,
    ];
}

function renderEngagementPlainText(array $export) {
    $lines = [(string) ($export['title'] ?? 'Engagement')];

    foreach ($export['sections'] ?? [] as $section) {
        $lines[] = '';
        $lines[] = (string) ($section['heading'] ?? '');

        foreach ($section['entries'] ?? [] as $entry_index => $entry) {
            if ($entry_index > 0) {
                $lines[] = '';
            }
            if (!empty($entry['title'])) {
                $lines[] = (string) $entry['title'];
            }
            foreach ($entry['fields'] ?? [] as $field) {
                $value_lines = preg_split('/\R/u', (string) ($field['value'] ?? '')) ?: [''];
                $lines[] = (string) ($field['label'] ?? '') . ': ' . array_shift($value_lines);
                foreach ($value_lines as $value_line) {
                    $lines[] = '  ' . $value_line;
                }
            }
        }
    }

    return implode("\n", $lines) . "\n";
}

function escapeEngagementMarkdown($value, $continuation_indent = '') {
    $lines = preg_split('/\R/u', (string) $value) ?: [''];
    $markdown_characters = ['\\', '`', '*', '{', '}', '[', ']', '(', ')', '<', '>', '#', '+', '-', '.', '!', '_', '|'];
    $escaped_characters = array_map(function ($character) {
        return '\\' . $character;
    }, $markdown_characters);
    foreach ($lines as &$line) {
        $line = str_replace($markdown_characters, $escaped_characters, $line);
    }
    unset($line);

    return implode("  \n" . $continuation_indent, $lines);
}

function renderEngagementMarkdown(array $export) {
    $lines = ['# ' . escapeEngagementMarkdown($export['title'] ?? 'Engagement')];

    foreach ($export['sections'] ?? [] as $section) {
        $lines[] = '';
        $lines[] = '## ' . escapeEngagementMarkdown($section['heading'] ?? '');

        foreach ($section['entries'] ?? [] as $entry_index => $entry) {
            if ($entry_index > 0) {
                $lines[] = '';
            }
            if (!empty($entry['title'])) {
                $lines[] = '### ' . escapeEngagementMarkdown($entry['title']);
            }
            foreach ($entry['fields'] ?? [] as $field) {
                $lines[] = '- **' . escapeEngagementMarkdown($field['label'] ?? '') . ':** ' .
                    escapeEngagementMarkdown($field['value'] ?? '', '  ');
            }
        }
    }

    return implode("\n", $lines) . "\n";
}

function engagementPdfFilename(array $engagement) {
    $filename_part = trim((string) ($engagement['event_title'] ?? ''));
    if ($filename_part === '') {
        $filename_part = trim((string) ($engagement['organization_name'] ?? 'engagement'));
    }
    $filename_part = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename_part) ?: 'engagement';
    $filename_part = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $filename_part), '-'));
    if ($filename_part === '') {
        $filename_part = 'engagement';
    }

    return 'engagement-' . (int) ($engagement['id'] ?? 0) . '-' . $filename_part . '.pdf';
}
