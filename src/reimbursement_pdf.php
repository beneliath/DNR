<?php

declare(strict_types=1);

require_once __DIR__ . '/reimbursement_helpers.php';

require_once __DIR__ . '/engagement_pdf.php';
require_once __DIR__ . '/reimbursement_pdf_receipt_helpers.php';

/** Match the shared report typography without converting labels to uppercase. */
function reimbursementPdfHeading(DnrEngagementPdf $pdf, string $label): void
{
    ensureEngagementPdfSpace($pdf, 20);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(36, 87, 214);
    $pdf->Cell(0, 7, $label, 0, 1);
    $pdf->SetDrawColor(198, 212, 244);
    $pdf->Line(18, $pdf->GetY(), $pdf->GetPageWidth() - 18, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(23, 32, 51);
}

/** Build the report and its receipt pages. Original files travel in the ZIP package. */
function renderReimbursementPdf(array $request, array $owner, array $items, array $receipts, array $setup): string
{
    $pdf = new DnrEngagementPdf('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetCreator(applicationBrandName());
    $pdf->SetAuthor($setup['organization_name'] !== '' ? $setup['organization_name'] : applicationBrandName());
    $pdf->SetTitle('Reimbursement Request ' . $request['request_hash']);
    $pdf->setEngagementTitle('Expense Report - ' . $request['request_hash']);
    $pdf->setDocumentLabel('Expense Report', false);
    $pdf->setGeneratedDate(engagementPdfDateLabel(applicationBusinessDate()));
    $pdf->setBrandLogoPath(engagementPdfBrandLogoPath());
    $pdf->SetMargins(18, 23, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $name = $escape($owner['display_name']);
    $range = $escape($request['start_date'] . ' to ' . $request['end_date']);
    $status = $escape(ucfirst($request['status']));
    $bookkeeper = trim($setup['bookkeeper_first_name'] . ' ' . $setup['bookkeeper_last_name']);
    $bookkeeperDetails = array_filter([$bookkeeper, $setup['bookkeeper_email'], formatPhoneNumberForDisplay($setup['bookkeeper_phone'])], static fn($value) => $value !== '');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->SetTextColor(102, 112, 133);
    $pdf->MultiCell(0, 6, $setup['organization_name'], 0, 'L');
    $pdf->Ln(4);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(23, 32, 51);
    $html = '<table cellpadding="7" border="0" style="background-color:#ffffff"><tr>'
        . '<td width="50%"><span style="font-size:8pt;color:#667085">Prepared For</span><br><b>' . $name . '</b></td>'
        . '<td width="50%"><span style="font-size:8pt;color:#667085">Expense Dates</span><br>' . $range . '</td></tr><tr>'
        . '<td><span style="font-size:8pt;color:#667085">Request</span><br>' . $escape($request['request_hash']) . '</td>'
        . '<td><span style="font-size:8pt;color:#667085">Status</span><br>' . $status . '</td></tr></table>';
    if ($bookkeeperDetails) $html .= '<p style="font-size:8pt;color:#667085"><b>Bookkeeper:</b> ' . $escape(implode(' · ', $bookkeeperDetails)) . '</p>';
    if ($setup['cc_email'] !== '') $html .= '<p style="font-size:8pt;color:#667085"><b>Cc:</b> ' . $escape($setup['cc_email']) . '</p>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Ln(2);
    $groups = [];
    $total = 0;
    foreach ($items as $item) {
        $key = $item['coa_number'] . "\0" . $item['coa_description'];
        if (!isset($groups[$key])) $groups[$key] = ['number' => $item['coa_number'], 'description' => $item['coa_description'], 'total' => 0];
        $groups[$key]['total'] += (int) $item['amount_cents'];
        $total += (int) $item['amount_cents'];
    }
    uasort($groups, static fn($a, $b) => [$a['number'], $a['description']] <=> [$b['number'], $b['description']]);
    $html = '<table border="0" cellpadding="6"><thead><tr style="background-color:#eaf0ff;color:#2457d6;font-weight:bold"><th width="16%">COA Number</th><th width="60%">Description</th><th width="24%" align="right">Subtotal</th></tr></thead><tbody>';
    foreach ($groups as $group) {
        $html .= '<tr style="background-color:#ffffff"><td width="16%" style="border-bottom:0.5px solid #dfe4ec">' . $escape($group['number']) . '</td><td width="60%" style="border-bottom:0.5px solid #dfe4ec">' . $escape($group['description']) . '</td><td width="24%" align="right" style="border-bottom:0.5px solid #dfe4ec">' . reimbursementMoney($group['total']) . '</td></tr>';
    }
    $html .= '<tr style="background-color:#e7f5ef;color:#137b59;font-weight:bold"><td colspan="2">Total Amount Due</td><td align="right">' . reimbursementMoney($total) . '</td></tr></tbody></table>';
    $html .= '<p style="font-size:8pt;color:#667085">Receipt filenames in Expense Detail match the files in the receipts/ folder of the package ZIP.</p>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->AddPage();
    reimbursementPdfHeading($pdf, 'Expense Detail');
    $pdf->writeHTML('<p style="font-size:8pt;color:#667085">' . $name . ' · ' . $range . '</p>', true, false, true, false, '');
    $html = '<table border="0" cellpadding="6"><thead><tr style="background-color:#eaf0ff;color:#2457d6;font-weight:bold"><th width="17%">Date</th><th width="34%">Merchant / Purpose</th><th width="33%">Account</th><th width="16%" align="right">Amount</th></tr></thead><tbody>';
    foreach ($items as $item) {
        $receiptNames = array_map('reimbursementReceiptPackageFilename', $receipts[(int) $item['expense_id']] ?? []);
        $purpose = '<b>' . $escape($item['merchant']) . '</b>' . ($item['description'] !== '' ? '<br><span style="font-size:8pt;color:#667085">' . $escape($item['description']) . '</span>' : '');
        // One outer row keeps the expense and its filename block together across page breaks.
        $html .= '<tr nobr="true"><td colspan="4" style="background-color:#ffffff;border-bottom:0.5px solid #dfe4ec"><table border="0" cellpadding="0"><tr><td width="17%">' . $escape($item['expense_date']) . '</td><td width="34%">' . $purpose . '</td><td width="33%">' . $escape($item['coa_number'] . ' · ' . $item['coa_description']) . '</td><td width="16%" align="right">' . reimbursementMoney((int) $item['amount_cents']) . '</td></tr>'
            . '<tr><td colspan="4" style="font-size:7.5pt;color:#667085"><br><b>Receipt Files - Expense #' . (int) $item['expense_id'] . ' (' . reimbursementMoney((int) $item['amount_cents']) . '):</b><br>'
            . ($receiptNames ? implode('<br>', array_map($escape, $receiptNames)) : 'No Receipt Attached') . '</td></tr></table></td></tr>';
    }
    $html .= '<tr style="font-weight:bold;background-color:#e7f5ef;color:#137b59"><td colspan="3">Total Amount Due</td><td align="right">' . reimbursementMoney($total) . '</td></tr></tbody></table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $receiptNumber = 0;
    $receiptFiles = 0;
    foreach ($items as $item) {
        foreach ($receipts[(int) $item['expense_id']] ?? [] as $receipt) {
            $receiptFiles++;
            $receiptNumber++;
            $path = persistentFilePath($receipt['storage_key']);
            if ($receipt['content_type'] === 'application/pdf') {
                try {
                    $pageCount = reimbursementPdfReceiptPageCount($pdf, $receipt);
                    if ($pageCount < 1) throw new \setasign\Fpdi\FpdiException('The PDF has no pages.');
                    for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                        $pdf->AddPage();
                        reimbursementPdfHeading($pdf, 'Receipt ' . $receiptNumber . ' - Expense #' . $item['expense_id']
                            . ' (PDF page ' . $pageNumber . ' of ' . $pageCount . ')');
                        $pdf->MultiCell(0, 6, $item['expense_date'] . '  |  ' . $item['merchant'] . '  |  ' . reimbursementMoney((int) $item['amount_cents']) . '  |  ' . $item['coa_number'] . ' ' . $item['coa_description'], 0, 'L');
                        $pdf->MultiCell(0, 5, 'Receipt File: ' . reimbursementReceiptPackageFilename($receipt), 0, 'L');
                        $template = $pdf->importPage($pageNumber);
                        $size = $pdf->getTemplateSize($template);
                        $imageTop = max(44.0, $pdf->GetY() + 4.0);
                        $scale = min(180 / $size['width'], max(1, $pdf->GetPageHeight() - 20 - $imageTop) / $size['height']);
                        $width = $size['width'] * $scale;
                        $pdf->useTemplate($template, ($pdf->GetPageWidth() - $width) / 2, $imageTop, $width);
                    }
                } catch (\setasign\Fpdi\FpdiException $error) {
                    throw new ReimbursementReceiptPdfException('Receipt ' . reimbursementReceiptPackageFilename($receipt)
                        . ' could not be included in the report. Save a new PDF copy of this receipt and replace the attachment, then try again.', 0, $error);
                }
                continue;
            }
            $pdf->AddPage();
            reimbursementPdfHeading($pdf, 'Receipt ' . $receiptNumber . ' - Expense #' . $item['expense_id']);
            $pdf->MultiCell(0, 6, $item['expense_date'] . '  |  ' . $item['merchant'] . '  |  ' . reimbursementMoney((int) $item['amount_cents']) . '  |  ' . $item['coa_number'] . ' ' . $item['coa_description'], 0, 'L');
            $pdf->MultiCell(0, 5, 'Receipt File: ' . reimbursementReceiptPackageFilename($receipt), 0, 'L');
            $imageTop = max(44.0, $pdf->GetY() + 4.0);
            $imagePath = $path;
            $temporary = null;
            try {
                $source = match($receipt['content_type']) {'image/jpeg'=>@imagecreatefromjpeg($path),'image/png'=>@imagecreatefrompng($path),'image/webp'=>@imagecreatefromwebp($path),default=>false};
                if (!$source) throw new RuntimeException('Unable to read a receipt image.');
                $ratio=min(1,1600/max(imagesx($source),imagesy($source)));
                $scaled=imagecreatetruecolor(max(1,(int)(imagesx($source)*$ratio)),max(1,(int)(imagesy($source)*$ratio)));
                imagefill($scaled,0,0,imagecolorallocate($scaled,255,255,255));
                imagecopyresampled($scaled,$source,0,0,0,0,imagesx($scaled),imagesy($scaled),imagesx($source),imagesy($source));
                unset($source);
                $temporary=tempnam(sys_get_temp_dir(),'dnr-receipt-');
                if (!$temporary || !imagejpeg($scaled,$temporary,85)) throw new RuntimeException('Unable to render a receipt image.');
                unset($scaled); $imagePath=$temporary;
                $dimensions = @getimagesize($imagePath);
                if (!$dimensions) throw new RuntimeException('Unable to read a receipt image.');
                $scale = min(180 / $dimensions[0], max(1, $pdf->GetPageHeight() - 20 - $imageTop) / $dimensions[1]);
                $width = $dimensions[0] * $scale;
                $height = $dimensions[1] * $scale;
                $pdf->Image($imagePath, ($pdf->GetPageWidth() - $width) / 2, $imageTop, $width, $height, '', '', '', false, 150);
            } finally {
                if ($temporary && is_file($temporary)) unlink($temporary);
            }
        }
    }
    if ($receiptFiles) {
        $pdf->AddPage();
        reimbursementPdfHeading($pdf, 'Receipt Files');
        $pdf->writeHTML('<p>' . $receiptFiles . ' original receipt file(s) accompany this report in the package ZIP. They are listed by expense below.</p>', true, false, true, false, '');
        $pdf->Ln(5);
        foreach ($items as $item) {
            foreach ($receipts[(int) $item['expense_id']] ?? [] as $receipt) {
                $pdf->MultiCell(0, 6, 'Expense #' . $item['expense_id'] . ' · ' . $item['merchant'] . ' · ' . reimbursementReceiptPackageFilename($receipt), 0, 'L');
            }
        }
    }
    return $pdf->Output('', 'S');
}
