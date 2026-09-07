<?php

declare(strict_types=1);

require_once __DIR__ . '/engagement_export_helpers.php';

/** A single presentation, with only the event context needed to identify its venue. */
function buildPresentationExport(array $engagement, array $presentation): array
{
    $entry = buildPresentationExportEntry($presentation);
    $event_fields = [];
    addEngagementExportField($event_fields, 'Event', $engagement['event_title'] ?? '');
    addEngagementExportField($event_fields, 'Organization', $engagement['organization_name'] ?? '');
    $sections = [[
        'heading' => 'Presentation',
        'entries' => [['fields' => $entry['fields']]],
    ]];
    if ($event_fields !== []) {
        $sections[] = ['heading' => 'Event', 'entries' => [['fields' => $event_fields]]];
    }
    $location = buildEngagementExportLocation($engagement);
    if ($location !== null) {
        $sections[] = $location;
    }

    return ['kind' => 'presentation', 'title' => $entry['title'], 'sections' => $sections];
}

function presentationPdfFilename(array $presentation): string
{
    $title = trim((string) ($presentation['topic_title'] ?? ''));
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: 'presentation';
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $slug) ?? '', '-'));
    return 'presentation-' . (int) ($presentation['id'] ?? 0) . '-' . ($slug ?: 'presentation') . '.pdf';
}
