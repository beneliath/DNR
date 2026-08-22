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
        $this->SetY(10);
        $this->SetFont('dejavusans', 'B', 9);
        $this->SetTextColor(31, 87, 231);
        $this->Cell(0, 5, engagementPdfText('DNR Engagement'), 0, 1, 'L');
        $this->SetDrawColor(210, 216, 224);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->Ln(5);
    }

    public function Footer() {
        $this->SetY(-13);
        $this->SetDrawColor(210, 216, 224);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->Ln(2);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 108, 118);
        $available_width = $this->w - $this->lMargin - $this->rMargin;
        $date_width = 55;
        $page_width = 25;
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

function estimateEngagementPdfSectionHeight(array $section) {
    $height = 13;
    foreach ($section['entries'] ?? [] as $entry_index => $entry) {
        if ($entry_index > 0) {
            $height += 1;
        }
        if (!empty($entry['title'])) {
            $height += max(1, (int) ceil(mb_strlen(engagementPdfText($entry['title']), 'UTF-8') / 80)) * 5.5;
        }
        foreach ($entry['fields'] ?? [] as $field) {
            $text = (string) ($field['label'] ?? '') . ': ' . (string) ($field['value'] ?? '');
            $line_count = 0;
            foreach (explode("\n", engagementPdfText($text)) as $line) {
                $line_count += max(1, (int) ceil(mb_strlen($line, 'UTF-8') / 85));
            }
            $height += $line_count * 5;
        }
    }

    return $height;
}

function addEngagementPdfSection(DnrEngagementPdf $pdf, array $section) {
    $available_height = $pdf->GetPageHeight() - 18 - $pdf->GetY();
    $estimated_height = estimateEngagementPdfSectionHeight($section);
    if (($estimated_height <= 235 && $estimated_height > $available_height) || $available_height < 24) {
        $pdf->AddPage();
    }

    $pdf->SetFillColor(31, 87, 231);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(0, 7, engagementPdfText($section['heading'] ?? ''), 0, 1, 'L', true);
    $pdf->Ln(2);

    foreach ($section['entries'] ?? [] as $entry_index => $entry) {
        if ($pdf->GetY() > 258) {
            $pdf->AddPage();
        }
        if ($entry_index > 0) {
            $pdf->Ln(1);
        }
        if (!empty($entry['title'])) {
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->MultiCell(0, 5.5, engagementPdfText($entry['title']));
        }

        foreach ($entry['fields'] ?? [] as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            $value = (string) ($field['value'] ?? '');
            if (($section['heading'] ?? '') === 'Chron' && in_array($label, ['Entry', 'Notes'], true)) {
                $pdf->SetFont('dejavusans', '', 9.5);
                $pdf->SetTextColor(54, 60, 68);
                $pdf->MultiCell(0, 5, engagementPdfText($value));
                continue;
            }
            $pdf->SetFont('dejavusans', 'B', 9.5);
            $pdf->SetTextColor(54, 60, 68);
            $pdf->Write(5, engagementPdfText($label . ': '));
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->MultiCell(0, 5, engagementPdfText($value));
        }
    }

    $pdf->Ln(4);
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
    $pdf->SetAuthor('DNR');
    $pdf->SetCreator('DNR');
    $pdf->setEngagementTitle($title);
    $pdf->setGeneratedDate($generated_date);
    $pdf->SetMargins(18, 18, 18);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pdf->SetFont('dejavusans', 'B', 18);
    $pdf->SetTextColor(24, 29, 35);
    $pdf->MultiCell(0, 8, engagementPdfText($title));
    $pdf->Ln(4);

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
