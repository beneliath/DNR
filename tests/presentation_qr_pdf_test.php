<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/presentation_qr_pdf.php';

function expectQrPdf(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$context = ['event_title' => 'Presentation resources <preview>'];
foreach ([0, 1, 4, 7, 8, 15, 57] as $count) {
    $links = [];
    for ($i = 0; $i < $count; $i++) {
        $url = 'https://example.com/surls/' . sprintf('%016x', $i);
        $links[] = ['id' => $i + 1, 'link_type' => 'custom', 'custom_label' => $i === 0 ? str_repeat('Long resource label ', 13) : 'Resource ' . ($i + 1),
            'is_enabled' => $i !== 1, 'topic_title' => 'A presentation with a descriptive title', 'speaker_name' => 'Speaker Name',
            'qr_url' => $url, 'qr_png' => shortLinkQr($url, 'png')];
    }
    $pdf = renderPresentationQrPdf($context, $links);
    expectQrPdf(str_starts_with($pdf, '%PDF-') && str_ends_with(rtrim($pdf), '%%EOF'), 'Valid PDF for ' . $count . ' codes.');
    expectQrPdf(preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+1\b/s', $pdf) === 1, 'Exactly one page for ' . $count . ' codes.');
    expectQrPdf(preg_match('/\/MediaBox\s*\[0(?:\.0+)?\s+0(?:\.0+)?\s+792(?:\.0+)?\s+612(?:\.0+)?\]/', $pdf) === 1, 'Landscape US Letter output.');
    expectQrPdf(preg_match_all('/\/URI\s*\([^)]*\/surls\//', $pdf) === $count, 'Every QR is clickable in the PDF.');
    if (getenv('DNR_QR_PDF_SAMPLE_DIR')) {
        file_put_contents(getenv('DNR_QR_PDF_SAMPLE_DIR') . '/qr-' . $count . '.pdf', $pdf);
    }
}
$presentationContext = [
    'presentation_id' => 71, 'event_title' => 'Community Conference',
    'topic_title' => 'Opening Presentation', 'speaker_name' => 'First Speaker',
    'presentation_date' => '2026-10-01', 'presentation_time' => '09:30:00',
    'event_address_line_1' => '123 Main Street', 'event_address_line_2' => 'Suite 4',
    'event_city' => 'Madison', 'event_state' => 'WI', 'event_zipcode' => '53703', 'event_country' => 'US',
];
$details = presentationQrPdfDetails($presentationContext);
expectQrPdf($details['Date'] === 'October 1, 2026' && $details['Time'] === '09:30 AM', 'The QR sheet uses the selected presentation date and time.');
expectQrPdf($details['Location'] === '123 Main Street, Suite 4, Madison, WI 53703, US', 'The QR sheet includes the complete venue address.');
expectQrPdf(count(array_unique(presentationQrPdfDetails([]))) === 1
    && presentationQrPdfDetails([])['Date'] === 'To be confirmed', 'Missing scheduling details are explicit without invented values.');
foreach ([[], array_slice($links, 0, 4), array_slice($links, 0, 7), $links] as $presentationLinks) {
    $pdf = renderPresentationQrPdf($presentationContext, $presentationLinks);
    expectQrPdf(preg_match('/\/Type\s*\/Pages\b.*?\/Count\s+1\b/s', $pdf) === 1, 'Presentation metadata and every code still fit on one page.');
    expectQrPdf(preg_match_all('/\/URI\s*\([^)]*\/surls\//', $pdf) === count($presentationLinks), 'Presentation metadata does not remove clickable QR codes.');
}
$availableLinks = array_slice($links, 0, 3);
expectQrPdf(selectPresentationQrPdfLinks($availableLinks, []) === $availableLinks, 'Existing PDF URLs still include all available resources.');
foreach ([[2], ['3', '1', '3']] as $ids) {
    $selected = selectPresentationQrPdfLinks($availableLinks, ['qr_selection' => '1', 'link_ids' => $ids]);
    $expectedIds = count($ids) === 1 ? [2] : [3, 1];
    expectQrPdf(array_column($selected, 'id') === $expectedIds, 'Selections preserve the user-requested order without duplicating codes.');
    $pdf = renderPresentationQrPdf($presentationContext, $selected);
    expectQrPdf(preg_match_all('/\/URI\s*\([^)]*\/surls\//', $pdf) === count($expectedIds), 'The PDF contains exactly the selected codes.');
    foreach ($availableLinks as $link) {
        expectQrPdf(str_contains($pdf, $link['qr_url']) === in_array($link['id'], $expectedIds, true), 'Unselected resources are absent from the PDF.');
    }
    $lastPosition = -1;
    foreach ($selected as $link) {
        $position = strpos($pdf, $link['qr_url']);
        expectQrPdf(is_int($position) && $position > $lastPosition, 'PDF resources follow the selected order.');
        $lastPosition = $position;
    }
}
foreach ([null, [], '1', ['bad'], ['1', 'bad'], [0], [-1], [['1']], [999], ['named' => '1']] as $ids) {
    try {
        selectPresentationQrPdfLinks($availableLinks, ['qr_selection' => '1', 'link_ids' => $ids]);
        throw new RuntimeException('Invalid or out-of-scope QR selection was accepted.');
    } catch (InvalidArgumentException $expected) {}
}
try {
    selectPresentationQrPdfLinks($availableLinks, ['qr_selection' => '1']);
    throw new RuntimeException('An explicit empty selection exported all QR codes.');
} catch (InvalidArgumentException $expected) {}
try {
    renderPresentationQrPdf($context, [['qr_png' => null, 'qr_url' => null]]);
    throw new RuntimeException('A missing QR image was silently omitted.');
} catch (RuntimeException $expected) {
    expectQrPdf($expected->getMessage() === 'QR images are awaiting setup.', 'Incomplete exports explain the missing QR images.');
}
echo "Presentation QR PDF tests passed.\n";
