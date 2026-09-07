<?php

declare(strict_types=1);

require_once __DIR__ . '/short_link_helpers.php';

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

/** Find the grid with the largest QR squares while reserving space for labels. */
function presentationQrPdfGrid(int $count, float $width, float $height): array
{
    $best = ['columns' => 1, 'rows' => 1, 'qr_size' => 0.0];
    for ($columns = 1; $columns <= max(1, $count); $columns++) {
        $rows = (int) ceil(max(1, $count) / $columns);
        $cellWidth = $width / $columns;
        $cellHeight = $height / $rows;
        $padding = min(4.0, min($cellWidth, $cellHeight) * 0.06);
        $labelHeight = min(21.0, $cellHeight * 0.32);
        $qrSize = min($cellWidth - 2 * $padding, $cellHeight - 2 * $padding - $labelHeight);
        if ($qrSize > $best['qr_size']) {
            $best = ['columns' => $columns, 'rows' => $rows, 'qr_size' => $qrSize,
                'cell_width' => $cellWidth, 'cell_height' => $cellHeight,
                'padding' => $padding, 'label_height' => $labelHeight];
        }
    }
    return $best;
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
    $pdf->SetTitle('Presentation QR Codes - ' . $context['event_title']);
    $pdf->SetSubject('Labeled presentation QR codes');
    $pdf->AddPage('L', 'LETTER');
    $width = $pdf->getPageWidth() - 20;
    $pdf->SetTextColor(29, 92, 90);
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetXY(10, 9);
    $pdf->Cell($width, 5, 'PRESENTATION QR CODES', 0, 0, 'L');
    $pdf->SetTextColor(24, 32, 43);
    $pdf->SetFont('dejavusans', 'B', 17);
    $pdf->MultiCell($width, 12, (string) $context['event_title'], 0, 'L', false, 1, 10, 15, true, 0, false, true, 12, 'M', true);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(77, 88, 99);
    $scope = isset($context['presentation_id'])
        ? (trim((string) $context['topic_title']) ?: 'Untitled presentation') . ' | ' . $context['speaker_name']
        : 'All presentations';
    $scope .= ' | ' . count($links) . ' QR ' . (count($links) === 1 ? 'code' : 'codes');
    $pdf->MultiCell($width, 8, $scope, 0, 'L', false, 1, 10, 28, true, 0, false, true, 8, 'M', true);
    $pdf->SetDrawColor(190, 209, 208);
    $pdf->Line(10, 38, $width + 10, 38);

    $top = 42.0;
    $height = $pdf->getPageHeight() - $top - 13;
    if (!$links) {
        $pdf->SetXY(10, 85);
        $pdf->SetFont('dejavusans', '', 13);
        $pdf->Cell($width, 10, 'No presentation QR codes are available.', 0, 0, 'C');
    } else {
        $grid = presentationQrPdfGrid(count($links), $width, $height);
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
            $qrX = $x + ($grid['cell_width'] - $grid['qr_size']) / 2;
            $qrY = $y + $grid['padding'];
            // Embed the stored bytes, never visit the public resolver or count a scan.
            $pdf->Image('@' . $link['qr_png'], $qrX, $qrY, $grid['qr_size'], $grid['qr_size'], 'PNG', $link['qr_url']);
            $label = shortLinkLabel($link) . ($link['is_enabled'] ? '' : ' (Disabled)');
            $labelWidth = $grid['cell_width'] - 2 * $grid['padding'];
            $labelY = $qrY + $grid['qr_size'];
            $pdf->SetTextColor(24, 32, 43);
            $pdf->SetFont('dejavusans', 'B', min(12, max(5, $grid['label_height'] * 0.65)));
            $pdf->MultiCell($labelWidth, $grid['label_height'] * 0.52, $label, 0, 'C', false, 1,
                $x + $grid['padding'], $labelY, true, 0, false, true, $grid['label_height'] * 0.52, 'M', true);
            $presentationLabel = (trim((string) $link['topic_title']) ?: 'Untitled presentation') . ' | ' . $link['speaker_name'];
            $pdf->SetFont('dejavusans', '', min(8, max(4, $grid['label_height'] * 0.44)));
            $pdf->SetTextColor(77, 88, 99);
            $pdf->MultiCell($labelWidth, $grid['label_height'] * 0.48, $presentationLabel, 0, 'C', false, 1,
                $x + $grid['padding'], $labelY + $grid['label_height'] * 0.52,
                true, 0, false, true, $grid['label_height'] * 0.48, 'T', true);
        }
    }
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(77, 88, 99);
    $pdf->SetXY(10, $pdf->getPageHeight() - 10);
    $pdf->Cell($width, 5, 'Scan a code to open its labeled resource.', 0, 0, 'C');
    return $pdf->Output('presentation-qr-codes.pdf', 'S');
}
