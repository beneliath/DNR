<?php

declare(strict_types=1);

require_once __DIR__ . '/short_link_helpers.php';
require_once __DIR__ . '/engagement_export_helpers.php';
require_once __DIR__ . '/engagement_view_helpers.php';

/** Use the same current-speaker resources shown in the Presentations tab. */
function fetchPresentationQrPdfLinks(mysqli $conn, int $engagementId, ?int $presentationId = null): array
{
    $stmt = $conn->prepare("SELECT l.id, l.code, l.link_type, l.custom_label, l.is_enabled,
        p.id AS presentation_id, p.topic_title, s.name AS speaker_name,
        q.encoded_url AS qr_url, q.png AS qr_png
        FROM presentations p
        JOIN speakers s ON s.id = p.speaker_id
        JOIN short_links l ON l.presentation_id = p.id AND l.speaker_id = p.speaker_id
        LEFT JOIN short_link_qr_images q ON q.link_id = l.id
        WHERE p.engagement_id = ? AND p.is_archived = 0 AND (? IS NULL OR p.id = ?)
        AND (l.link_type <> 'notes' OR EXISTS(SELECT 1 FROM presentation_notes n
            WHERE n.presentation_id = p.id AND n.speaker_id = p.speaker_id AND n.pdf IS NOT NULL))
        ORDER BY p.presentation_date, p.presentation_time, p.id, l.id");
    $stmt->bind_param('iii', $engagementId, $presentationId, $presentationId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Favor large QR squares and balanced rows while reserving space for labels. */
function presentationQrPdfGrid(int $count, float $width, float $height, bool $includePresentationLabels = true): array
{
    $best = ['columns' => 1, 'rows' => 1, 'qr_size' => 0.0];
    $bestScore = 0.0;
    for ($columns = 1; $columns <= max(1, $count); $columns++) {
        $rows = (int) ceil(max(1, $count) / $columns);
        $cellWidth = $width / $columns;
        $cellHeight = $height / $rows;
        $padding = min(4.0, min($cellWidth, $cellHeight) * 0.06);
        $labelHeight = $includePresentationLabels
            ? min(21.0, $cellHeight * 0.32)
            : min(10.0, $cellHeight * 0.18);
        $qrSize = min($cellWidth - 2 * $padding, $cellHeight - 2 * $padding - $labelHeight);
        // Avoid sparse grids when a more balanced layout offers comparable QR sizes.
        $score = $qrSize * sqrt(max(1, $count) / ($columns * $rows));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['columns' => $columns, 'rows' => $rows, 'qr_size' => $qrSize,
                'cell_width' => $cellWidth, 'cell_height' => $cellHeight,
                'padding' => $padding, 'label_height' => $labelHeight];
        }
    }
    return $best;
}

/** Use the presentation schedule and the event's shared venue, without guessing missing values. */
function presentationQrPdfDetails(array $context): array
{
    $date = engagementViewDate($context['presentation_date'] ?? null);
    $time = formatPresentationTime($context['presentation_time'] ?? '');
    $location = buildEngagementExportLocation($context);
    return [
        'Date' => $date !== null ? $date->format('F j, Y') : 'To be confirmed',
        'Time' => $time !== '' ? $time : 'To be confirmed',
        'Location' => $location !== null
            ? str_replace("\n", ', ', $location['entries'][0]['fields'][0]['value'])
            : 'To be confirmed',
    ];
}

/** Generate exactly one landscape US Letter page using the saved QR images. */
function renderPresentationQrPdf(array $context, array $links, ?TCPDF $pdf = null): string
{
    $pdf ??= new TCPDF('L', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->SetCreator(applicationBrandName());
    $pdf->SetAuthor(applicationBrandName());
    $isPresentation = isset($context['presentation_id']);
    $eventTitle = trim((string) ($context['event_title'] ?? '')) ?: 'Event';
    $title = $isPresentation
        ? (trim((string) ($context['topic_title'] ?? '')) ?: 'Untitled presentation')
        : $eventTitle;
    $pdf->SetTitle('Presentation QR Codes - ' . $title);
    $pdf->SetSubject('Labeled presentation QR codes');
    $pdf->AddPage('L', 'LETTER');
    $width = $pdf->getPageWidth() - 20;

    // Match modern.css light theme: app background, white cards, blue primary, navy text, muted slate, and soft rounded borders.
    $pdf->SetFillColor(246, 247, 251);
    $pdf->Rect(0, 0, $pdf->getPageWidth(), $pdf->getPageHeight(), 'F');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(223, 228, 236);
    $pdf->SetLineWidth(0.25);
    $headerHeight = 33.0;
    $pdf->RoundedRect(10, 9, $width, $headerHeight, 3, '1111', 'DF');
    $pdf->SetFillColor(36, 87, 214);
    $pdf->RoundedRect(10, 9, 1.5, $headerHeight, 0.7, '1111', 'F');
    $titleWidth = $isPresentation ? $width * 0.59 : $width - 12;
    $pdf->SetTextColor(36, 87, 214);
    $pdf->SetFont('dejavusans', 'B', 7);
    $pdf->SetXY(16, 12);
    $pdf->Cell($titleWidth, 4, 'PRESENTATION RESOURCES  |  ' . count($links) . ' QR ' . (count($links) === 1 ? 'CODE' : 'CODES'), 0, 0, 'L');
    $pdf->SetTextColor(23, 32, 51);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->MultiCell($titleWidth, 12, $title, 0, 'L', false, 1, 16, 17, true, 0, false, true, 12, 'M', true);
    $scope = $isPresentation
        ? $eventTitle . '  |  ' . (string) ($context['speaker_name'] ?? '')
        : 'All presentations';
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(102, 112, 133);
    $pdf->MultiCell($titleWidth, 8, $scope, 0, 'L', false, 1, 16, 30, true, 0, false, true, 8, 'M', true);

    if ($isPresentation) {
        $detailX = 16 + $titleWidth + 6;
        $detailWidth = $width + 4 - $detailX;
        $pdf->SetDrawColor(223, 228, 236);
        $pdf->Line($detailX - 3, 13, $detailX - 3, 38);
        $details = presentationQrPdfDetails($context);
        foreach (['Date', 'Time', 'Location'] as $label) {
            $fieldX = $detailX + ($label === 'Time' ? $detailWidth * 0.57 : 0);
            $fieldY = $label === 'Location' ? 25 : 12;
            $fieldWidth = $label === 'Location' ? $detailWidth
                : $detailWidth * ($label === 'Date' ? 0.57 : 0.43);
            $pdf->SetFont('dejavusans', 'B', 6.5);
            $pdf->SetTextColor(102, 112, 133);
            $pdf->SetXY($fieldX, $fieldY);
            $pdf->Cell($fieldWidth, 4, strtoupper($label), 0, 0, 'L');
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(23, 32, 51);
            $pdf->MultiCell($fieldWidth - 2, 9, $details[$label], 0, 'L', false, 1,
                $fieldX, $fieldY + 4, true, 0, false, true, 9, 'T', true);
        }
    }

    $top = 9 + $headerHeight + 4;
    $height = $pdf->getPageHeight() - $top - 18;
    if (!$links) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(223, 228, 236);
        $pdf->RoundedRect(10, $top, $width, $height, 3, '1111', 'DF');
        $pdf->SetXY(10, $top + $height / 2 - 5);
        $pdf->SetTextColor(102, 112, 133);
        $pdf->SetFont('dejavusans', '', 13);
        $pdf->Cell($width, 10, 'No presentation QR codes are available.', 0, 0, 'C');
    } else {
        $grid = presentationQrPdfGrid(count($links), $width, $height, !$isPresentation);
        foreach (array_values($links) as $index => $link) {
            if (!is_string($link['qr_png']) || $link['qr_png'] === '' || !is_string($link['qr_url']) || $link['qr_url'] === '') {
                throw new RuntimeException('QR images are awaiting setup.');
            }
            $column = $index % $grid['columns'];
            $row = intdiv($index, $grid['columns']);
            // Center an incomplete final row without changing any QR sizes.
            $rowCount = min($grid['columns'], count($links) - $row * $grid['columns']);
            $x = 10 + ($width - $rowCount * $grid['cell_width']) / 2 + $column * $grid['cell_width'];
            $y = $top + $row * $grid['cell_height'];
            $gap = min(2.0, $grid['padding'] / 2);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(223, 228, 236);
            $pdf->RoundedRect($x + $gap / 2, $y + $gap / 2,
                $grid['cell_width'] - $gap, $grid['cell_height'] - $gap,
                min(3.0, $grid['padding']), '1111', 'DF');
            $qrX = $x + ($grid['cell_width'] - $grid['qr_size']) / 2;
            $qrY = $y + $grid['padding'];
            // Keep the stored black/white image and its quiet zone unchanged.
            // Embed bytes without visiting the public resolver or counting a scan.
            $pdf->Image('@' . $link['qr_png'], $qrX, $qrY, $grid['qr_size'], $grid['qr_size'], 'PNG', $link['qr_url']);
            $label = shortLinkLabel($link) . ($link['is_enabled'] ? '' : ' (Disabled)');
            $labelWidth = $grid['cell_width'] - 2 * $grid['padding'];
            $labelY = $qrY + $grid['qr_size'];
            $pdf->SetTextColor(...($link['is_enabled'] ? [36, 87, 214] : [154, 91, 5]));
            $labelHeight = $grid['label_height'] * ($isPresentation ? 1.0 : 0.52);
            $pdf->SetFont('dejavusans', 'B', min(12, max(5, $labelHeight * 1.2)));
            $pdf->MultiCell($labelWidth, $labelHeight, $label, 0, 'C', false, 1,
                $x + $grid['padding'], $labelY, true, 0, false, true, $labelHeight, 'M', true);
            if ($isPresentation) continue;
            $presentationLabel = (trim((string) $link['topic_title']) ?: 'Untitled presentation') . ' | ' . $link['speaker_name'];
            $pdf->SetFont('dejavusans', '', min(8, max(4, $grid['label_height'] * 0.44)));
            $pdf->SetTextColor(102, 112, 133);
            $pdf->MultiCell($labelWidth, $grid['label_height'] * 0.48, $presentationLabel, 0, 'C', false, 1,
                $x + $grid['padding'], $labelY + $grid['label_height'] * 0.52,
                true, 0, false, true, $grid['label_height'] * 0.48, 'T', true);
        }
    }
    $footerY = $pdf->getPageHeight() - 13;
    $pdf->SetDrawColor(223, 228, 236);
    $pdf->Line(10, $footerY - 2, $width + 10, $footerY - 2);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(102, 112, 133);
    $pdf->SetXY(10, $footerY);
    $pdf->Cell($width, 6, 'Scan a code or select it in this PDF to open its resource.', 0, 0, 'C');
    return $pdf->Output('presentation-qr-codes.pdf', 'S');
}
