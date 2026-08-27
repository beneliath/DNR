<?php

class DnrEngagementPdf extends TCPDF {
    private $engagement_title = 'Engagement';
    private $generated_date = '';

    public function setEngagementTitle($title) {
        $this->engagement_title = (string) $title;
    }

    public function setGeneratedDate($generated_date) {
        $this->generated_date = (string) $generated_date;
    }

    private function footerTitle($maximum_width) {
        $title = engagementPdfText($this->engagement_title);
        if ($this->GetStringWidth($title) <= $maximum_width) {
            return $title;
        }

        $suffix = '...';
        while ($title !== '' && $this->GetStringWidth(rtrim($title) . $suffix) > $maximum_width) {
            $title = mb_substr($title, 0, -1, 'UTF-8');
        }

        return rtrim($title) . $suffix;
    }

    public function Header() {
        $this->SetY(9);
        $this->SetFont('dejavusans', 'B', 8.5);
        $this->SetTextColor(36, 87, 214);
        $brand = engagementPdfText(applicationBrandName());
        $brandWidth = min(80.0, max(11.0, $this->GetStringWidth($brand) + 3.0));
        $this->Cell($brandWidth, 5, $brand, 0, 0, 'L');
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(102, 112, 133);
        $this->Cell(0, 5, engagementPdfText('ENGAGEMENT BRIEF'), 0, 1, 'L');
        $this->SetDrawColor(223, 228, 236);
        $line_y = $this->GetY() + 1;
        $this->Line($this->lMargin, $line_y, $this->w - $this->rMargin, $line_y);
    }

    public function Footer() {
        $this->SetY(-13);
        $this->SetDrawColor(210, 216, 224);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->Ln(2);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 108, 118);
        $available_width = $this->w - $this->lMargin - $this->rMargin;
        $date_width = 68;
        $page_width = 24;
        $title_width = $available_width - $date_width - $page_width;
        $this->Cell(
            $date_width,
            5,
            engagementPdfText('Generated: ' . $this->generated_date),
            0,
            0,
            'L'
        );
        $this->Cell(
            $title_width,
            5,
            $this->footerTitle($title_width - 4),
            0,
            0,
            'C'
        );
        $this->Cell(
            $page_width,
            5,
            engagementPdfText(
                'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages()
            ),
            0,
            0,
            'R'
        );
    }
}

function engagementPdfText($value) {
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
}

function engagementPdfDisplayValue($label, $value) {
    $label = trim((string) $label);
    $value = trim(engagementPdfText($value));
    if ($value === '') {
        return 'Not specified';
    }

    if ($label === 'Event Dates') {
        $formatted_dates = [];
        foreach (preg_split('/\s+to\s+/i', $value) ?: [] as $date_value) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date_value));
            $date_errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable
                && ($date_errors === false
                    || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
            ) {
                $formatted_dates[] = $date->format('F j, Y');
            } else {
                $formatted_dates[] = trim($date_value);
            }
        }
        return implode(' - ', $formatted_dates);
    }

    if ($label === 'Date and Time') {
        foreach (['!Y-m-d h:i A', '!Y-m-d H:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $date_errors = DateTimeImmutable::getLastErrors();
            if ($date instanceof DateTimeImmutable
                && ($date_errors === false
                    || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
            ) {
                return $date->format('F j, Y \a\t g:i A');
            }
        }
    }

    if (in_array($label, ['Event Type', 'Status', 'Confirmation', 'Lifecycle'], true)) {
        return ucwords(str_replace('_', ' ', $value));
    }

    return $value;
}

function engagementPdfAvailableWidth(DnrEngagementPdf $pdf) {
    $margins = $pdf->getMargins();
    return $pdf->GetPageWidth() - $margins['left'] - $margins['right'];
}

function ensureEngagementPdfSpace(DnrEngagementPdf $pdf, $required_height) {
    $margins = $pdf->getMargins();
    $available_height = $pdf->GetPageHeight() - $margins['bottom'] - $pdf->GetY();
    if ($available_height < $required_height) {
        $pdf->AddPage();
    }
}

function engagementPdfCardHeight(DnrEngagementPdf $pdf, array $field, $width) {
    $value = engagementPdfDisplayValue($field['label'] ?? '', $field['value'] ?? '');
    $pdf->SetFont('dejavusans', '', 9.5);
    return max(16, 9 + $pdf->getStringHeight(max(10, $width - 7), $value, false, true, '', 0));
}

function addEngagementPdfSummaryGrid(DnrEngagementPdf $pdf, array $fields) {
    if ($fields === []) {
        return;
    }

    $available_width = engagementPdfAvailableWidth($pdf);
    $column_gap = 4;
    $column_width = ($available_width - $column_gap) / 2;
    foreach (array_chunk($fields, 2) as $row) {
        $row_height = 16;
        foreach ($row as $field) {
            $row_height = max($row_height, engagementPdfCardHeight($pdf, $field, $column_width));
        }
        ensureEngagementPdfSpace($pdf, $row_height + 3);

        $row_x = $pdf->GetX();
        $row_y = $pdf->GetY();
        foreach ($row as $column => $field) {
            $card_x = $row_x + ($column * ($column_width + $column_gap));
            $pdf->SetFillColor(246, 248, 252);
            $pdf->SetDrawColor(223, 228, 236);
            $pdf->RoundedRect($card_x, $row_y, $column_width, $row_height, 2, '1111', 'DF');

            $label = mb_strtoupper(engagementPdfText($field['label'] ?? ''), 'UTF-8');
            $value = engagementPdfDisplayValue($field['label'] ?? '', $field['value'] ?? '');
            $pdf->SetFont('dejavusans', 'B', 7.25);
            $pdf->SetTextColor(102, 112, 133);
            $pdf->MultiCell(
                $column_width - 7,
                3.8,
                $label,
                0,
                'L',
                false,
                0,
                $card_x + 3.5,
                $row_y + 2.5
            );
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->SetTextColor(23, 32, 51);
            $pdf->MultiCell(
                $column_width - 7,
                4.8,
                $value,
                0,
                'L',
                false,
                0,
                $card_x + 3.5,
                $row_y + 7
            );
        }
        $pdf->SetXY($row_x, $row_y + $row_height + 3);
    }
}

function engagementPdfFieldIsNarrative($label, $value) {
    $label = trim((string) $label);
    $value = engagementPdfText($value);
    return in_array($label, ['Event Description', 'Address', 'Entry', 'Notes'], true)
        || str_contains($value, "\n")
        || mb_strlen($value, 'UTF-8') > 180;
}

function addEngagementPdfNarrativeField(DnrEngagementPdf $pdf, array $field) {
    $label = mb_strtoupper(engagementPdfText($field['label'] ?? ''), 'UTF-8');
    $value = engagementPdfDisplayValue($field['label'] ?? '', $field['value'] ?? '');
    $available_width = engagementPdfAvailableWidth($pdf);
    $pdf->SetFont('dejavusans', '', 9.5);
    $value_height = $pdf->getStringHeight($available_width - 8, $value, false, true, '', 0);
    $card_height = 11 + $value_height;
    $margins = $pdf->getMargins();
    $maximum_card_height = $pdf->GetPageHeight() - $margins['top'] - $margins['bottom'] - 4;

    if ($card_height <= $maximum_card_height) {
        ensureEngagementPdfSpace($pdf, $card_height + 3);
        $card_x = $pdf->GetX();
        $card_y = $pdf->GetY();
        $pdf->SetFillColor(246, 248, 252);
        $pdf->SetDrawColor(223, 228, 236);
        $pdf->RoundedRect($card_x, $card_y, $available_width, $card_height, 2, '1111', 'DF');
        $pdf->SetFont('dejavusans', 'B', 7.25);
        $pdf->SetTextColor(102, 112, 133);
        $pdf->MultiCell(
            $available_width - 8,
            3.8,
            $label,
            0,
            'L',
            false,
            1,
            $card_x + 4,
            $card_y + 2.5
        );
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->SetTextColor(54, 60, 68);
        $pdf->MultiCell(
            $available_width - 8,
            5,
            $value,
            0,
            'L',
            false,
            1,
            $card_x + 4,
            $card_y + 7
        );
        $pdf->SetXY($card_x, $card_y + $card_height + 3);
        return;
    }

    ensureEngagementPdfSpace($pdf, 20);
    $pdf->SetFont('dejavusans', 'B', 7.25);
    $pdf->SetTextColor(102, 112, 133);
    $pdf->MultiCell(0, 4, $label, 0, 'L', false, 1);
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->SetTextColor(54, 60, 68);
    $pdf->MultiCell(0, 5, $value, 0, 'L', false, 1);
    $pdf->Ln(3);
}

function addEngagementPdfFields(DnrEngagementPdf $pdf, array $fields) {
    $summary_fields = [];
    foreach ($fields as $field) {
        if (engagementPdfFieldIsNarrative($field['label'] ?? '', $field['value'] ?? '')) {
            addEngagementPdfSummaryGrid($pdf, $summary_fields);
            $summary_fields = [];
            addEngagementPdfNarrativeField($pdf, $field);
            continue;
        }
        $summary_fields[] = $field;
    }
    addEngagementPdfSummaryGrid($pdf, $summary_fields);
}

function addEngagementPdfEntryTitle(DnrEngagementPdf $pdf, $title) {
    ensureEngagementPdfSpace($pdf, 12);
    $title = engagementPdfText($title);
    $title_x = $pdf->GetX();
    $title_y = $pdf->GetY();
    $pdf->SetFillColor(36, 87, 214);
    $pdf->Rect($title_x, $title_y + 0.5, 1.2, 5.5, 'F');
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(23, 32, 51);
    $pdf->MultiCell(0, 5.5, $title, 0, 'L', false, 1, $title_x + 4, $title_y);
    $pdf->Ln(1.5);
}

function addEngagementPdfSectionHeading(DnrEngagementPdf $pdf, $heading) {
    ensureEngagementPdfSpace($pdf, 18);
    $heading = mb_strtoupper(engagementPdfText($heading), 'UTF-8');
    $heading_x = $pdf->GetX();
    $heading_y = $pdf->GetY();
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->SetTextColor(36, 87, 214);
    $pdf->Cell(0, 5, $heading, 0, 1, 'L');
    $line_start = min(
        $heading_x + engagementPdfAvailableWidth($pdf) - 8,
        $heading_x + $pdf->GetStringWidth($heading) + 5
    );
    $pdf->SetDrawColor(198, 212, 244);
    $pdf->Line(
        $line_start,
        $heading_y + 2.8,
        $heading_x + engagementPdfAvailableWidth($pdf),
        $heading_y + 2.8
    );
    $pdf->Ln(2.5);
}

function estimateEngagementPdfSummaryGridHeight(DnrEngagementPdf $pdf, array $fields) {
    $height = 0;
    $available_width = engagementPdfAvailableWidth($pdf);
    $column_width = ($available_width - 4) / 2;
    foreach (array_chunk($fields, 2) as $row) {
        $row_height = 16;
        foreach ($row as $field) {
            $row_height = max($row_height, engagementPdfCardHeight($pdf, $field, $column_width));
        }
        $height += $row_height + 3;
    }

    return $height;
}

function estimateEngagementPdfFieldsHeight(DnrEngagementPdf $pdf, array $fields) {
    $height = 0;
    $summary_fields = [];
    foreach ($fields as $field) {
        if (!engagementPdfFieldIsNarrative($field['label'] ?? '', $field['value'] ?? '')) {
            $summary_fields[] = $field;
            continue;
        }

        $height += estimateEngagementPdfSummaryGridHeight($pdf, $summary_fields);
        $summary_fields = [];
        $value = engagementPdfDisplayValue($field['label'] ?? '', $field['value'] ?? '');
        $pdf->SetFont('dejavusans', '', 9.5);
        $height += 14 + $pdf->getStringHeight(
            engagementPdfAvailableWidth($pdf) - 8,
            $value,
            false,
            true,
            '',
            0
        );
    }

    return $height + estimateEngagementPdfSummaryGridHeight($pdf, $summary_fields);
}

function estimateEngagementPdfSectionHeight(DnrEngagementPdf $pdf, array $section) {
    $height = 10.5;
    foreach ($section['entries'] ?? [] as $entry_index => $entry) {
        if ($entry_index > 0) {
            $height += 2;
        }
        if (!empty($entry['title'])) {
            $pdf->SetFont('dejavusans', 'B', 10);
            $height += 1.5 + $pdf->getStringHeight(
                engagementPdfAvailableWidth($pdf) - 4,
                engagementPdfText($entry['title']),
                false,
                true,
                '',
                0
            );
        }
        $height += estimateEngagementPdfFieldsHeight($pdf, $entry['fields'] ?? []);
    }

    return $height;
}

function addEngagementPdfSection(DnrEngagementPdf $pdf, array $section) {
    $margins = $pdf->getMargins();
    $available_height = $pdf->GetPageHeight() - $margins['bottom'] - $pdf->GetY();
    $maximum_height = $pdf->GetPageHeight() - $margins['top'] - $margins['bottom'];
    $estimated_height = estimateEngagementPdfSectionHeight($pdf, $section);
    if (($estimated_height <= $maximum_height && $estimated_height > $available_height)
        || $available_height < 24
    ) {
        $pdf->AddPage();
    }

    addEngagementPdfSectionHeading($pdf, $section['heading'] ?? '');

    foreach ($section['entries'] ?? [] as $entry_index => $entry) {
        if ($entry_index > 0) {
            $pdf->Ln(2);
        }
        if (!empty($entry['title'])) {
            addEngagementPdfEntryTitle($pdf, $entry['title']);
        }
        addEngagementPdfFields($pdf, $entry['fields'] ?? []);
    }

    $pdf->Ln(3);
}

function orderEngagementPdfSections(array $sections) {
    $section_priorities = [
        'Overview' => 10,
        'Location' => 20,
        'Event Details' => 30,
        'Contacts' => 40,
        'Presentations' => 50,
        'Compensation' => 60,
    ];
    $ordered_sections = [];
    foreach ($sections as $index => $section) {
        if (($section['heading'] ?? '') === 'Chron') {
            continue;
        }
        $ordered_sections[] = [
            'index' => $index,
            'priority' => $section_priorities[$section['heading'] ?? ''] ?? 70,
            'section' => $section,
        ];
    }
    usort($ordered_sections, function ($left, $right) {
        return [$left['priority'], $left['index']] <=> [$right['priority'], $right['index']];
    });

    return array_column($ordered_sections, 'section');
}

function renderEngagementPdf(array $export, $generated_date = null) {
    $title = (string) ($export['title'] ?? 'Engagement');
    $generated_date = $generated_date ?: date('F j, Y');
    $pdf = new DnrEngagementPdf('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetTitle(engagementPdfText($title));
    $pdf->SetAuthor(applicationBrandName());
    $pdf->SetCreator(applicationBrandName());
    $pdf->setEngagementTitle($title);
    $pdf->setGeneratedDate($generated_date);
    $pdf->SetMargins(18, 21, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pdf->SetFont('dejavusans', 'B', 7.5);
    $pdf->SetTextColor(102, 112, 133);
    $pdf->Cell(0, 4, engagementPdfText('ENGAGEMENT'), 0, 1, 'L');
    $pdf->SetFont('dejavusans', 'B', 20);
    $pdf->SetTextColor(23, 32, 51);
    $pdf->MultiCell(0, 8.5, engagementPdfText($title), 0, 'L', false, 1);
    $pdf->Ln(5);

    $chron_sections = [];
    foreach ($export['sections'] ?? [] as $section) {
        if (($section['heading'] ?? '') === 'Chron') {
            $chron_sections[] = $section;
        }
    }
    foreach (orderEngagementPdfSections($export['sections'] ?? []) as $section) {
        addEngagementPdfSection($pdf, $section);
    }

    foreach ($chron_sections as $chron_section) {
        $pdf->AddPage();
        addEngagementPdfSection($pdf, $chron_section);
    }

    return $pdf->Output('', 'S');
}
