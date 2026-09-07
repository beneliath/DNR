<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/presentation_export_helpers.php';
require_once __DIR__ . '/../src/engagement_pdf.php';

function expectPresentationExport(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$engagement = [
    'event_title' => 'Community Conference', 'organization_name' => 'Example Organization',
    'event_address_line_1' => '123 Main Street', 'event_city' => 'Madison',
    'event_state' => 'WI', 'event_zipcode' => '53703', 'event_country' => 'US',
    'other_compensation' => 'Private compensation details', 'caller_name' => 'Private caller',
];
$presentations = [
    ['id' => 71, 'topic_title' => 'Opening *Session*', 'speaker_name' => 'First Speaker',
        'presentation_date' => '2026-10-01', 'presentation_time' => '09:30:00',
        'duration_minutes' => 45, 'expected_attendance' => 100, 'actual_attendance' => 0],
    ['id' => 72, 'topic_title' => 'Closing Session', 'speaker_name' => 'Second Speaker',
        'presentation_date' => '2026-10-02', 'presentation_time' => '15:00:00',
        'duration_minutes' => 30, 'expected_attendance' => null, 'actual_attendance' => null],
];
foreach ($presentations as $index => $presentation) {
    $export = buildPresentationExport($engagement, $presentation);
    $text = renderEngagementPlainText($export);
    $markdown = renderEngagementMarkdown($export);
    expectPresentationExport(str_starts_with($text, $presentation['topic_title'] . "\n"), 'The presentation is the document title.');
    foreach ([$text, $markdown] as $value) {
        expectPresentationExport(str_contains($value, $presentation['speaker_name']), 'The selected speaker is included.');
        expectPresentationExport(!str_contains($value, $presentations[1 - $index]['speaker_name']), 'Another presentation must not be included.');
        expectPresentationExport(str_contains($value, 'Community Conference') && str_contains($value, '123 Main Street'), 'Event and venue context is retained.');
        expectPresentationExport(!str_contains($value, 'Private'), 'Event-only internal details are not included.');
    }
    expectPresentationExport(str_starts_with(presentationPdfFilename($presentation), 'presentation-' . $presentation['id'] . '-'), 'The filename identifies the selected presentation.');
    $pdf = renderEngagementPdf($export, 'September 7, 2026');
    expectPresentationExport(str_starts_with($pdf, '%PDF-') && str_ends_with(rtrim($pdf), '%%EOF'), 'The presentation brief is a valid PDF.');
}
$text = renderEngagementPlainText(buildPresentationExport($engagement, $presentations[0]));
expectPresentationExport(str_contains($text, 'Duration: 45 minutes') && str_contains($text, 'Actual Attendance: 0'), 'Explicit duration and zero attendance survive export.');
expectPresentationExport(str_contains(renderEngagementMarkdown(buildPresentationExport($engagement, $presentations[0])), 'Opening \\*Session\\*'), 'Markdown special characters are escaped.');
$unknown = ['id' => 73, 'topic_title' => '', 'duration_minutes' => null, 'expected_attendance' => null, 'actual_attendance' => null];
$text = renderEngagementPlainText(buildPresentationExport([], $unknown));
expectPresentationExport(str_starts_with($text, "Presentation\n") && !str_contains($text, 'Duration:') && !str_contains($text, 'Attendance:'), 'Unknown values stay blank, with a useful title fallback.');

// Render the actual controls to verify independent payloads, URLs, and HTML safety.
$engagement['event_title'] = '</script><script>alert("unsafe")</script>';
$dom = new DOMDocument();
ob_start();
foreach ($presentations as $presentation) {
    include __DIR__ . '/../src/templates/presentation_export_actions.php';
}
$html = ob_get_clean();
@$dom->loadHTML('<html><body>' . $html . '</body></html>');
$xpath = new DOMXPath($dom);
$groups = $xpath->query('//*[@data-presentation-export]');
expectPresentationExport($groups->length === 2, 'Each presentation has its own controls.');
foreach ($groups as $index => $group) {
    $data = $xpath->query('.//script[@type="application/json"]', $group)->item(0);
    $payload = json_decode($data->textContent, true, 512, JSON_THROW_ON_ERROR);
    expectPresentationExport(str_contains($payload['text'], $presentations[$index]['speaker_name'])
        && !str_contains($payload['text'], $presentations[1 - $index]['speaker_name']), 'Rendered copy data stays scoped to its presentation.');
    $links = $xpath->query('.//a', $group);
    foreach ($links as $link) {
        expectPresentationExport(str_ends_with($link->getAttribute('href'), 'presentation_id=' . $presentations[$index]['id']), 'Both PDFs request the selected presentation.');
    }
    expectPresentationExport($xpath->query('parent::details[not(@open)]/summary', $group)->item(0)->textContent === 'Export Presentation', 'Exports are in a closed dropdown.');
}
expectPresentationExport($xpath->query('//script')->length === 2, 'Embedded user content cannot create executable script tags.');
echo "Presentation export helper tests passed.\n";
