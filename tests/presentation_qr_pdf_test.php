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
        $links[] = ['link_type' => 'custom', 'custom_label' => $i === 0 ? str_repeat('Long resource label ', 13) : 'Resource ' . ($i + 1),
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
try {
    renderPresentationQrPdf($context, [['qr_png' => null, 'qr_url' => null]]);
    throw new RuntimeException('A missing QR image was silently omitted.');
} catch (RuntimeException $expected) {
    expectQrPdf($expected->getMessage() === 'QR images are awaiting setup.', 'Incomplete exports explain the missing QR images.');
}
echo "Presentation QR PDF tests passed.\n";
