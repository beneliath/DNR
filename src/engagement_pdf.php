<?php

require_once __DIR__ . '/follow_up_task_helpers.php';

class DnrEngagementPdf extends TCPDF {
    private $engagement_title = 'Engagement';
    private $generated_date = '';
    private $brand_logo_path = '';
    private $brand_logo_type = '';
    private $brand_logo_width = 0.0;
    private $brand_logo_height = 0.0;

    public function setEngagementTitle($title) {
        $this->engagement_title = (string) $title;
    }

    public function setGeneratedDate($generated_date) {
        $this->generated_date = (string) $generated_date;
    }

    public function setBrandLogoPath($brand_logo_path) {
        $this->brand_logo_path = '';
        $this->brand_logo_type = '';
        $this->brand_logo_width = 0.0;
        $this->brand_logo_height = 0.0;

        $brand_logo_path = (string) $brand_logo_path;
        if ($brand_logo_path === '' || !is_readable($brand_logo_path)) {
            return;
        }

        $image_info = @getimagesize($brand_logo_path);
        if (!is_array($image_info)
            || (int) ($image_info[0] ?? 0) < 1
            || (int) ($image_info[1] ?? 0) < 1
        ) {
            return;
        }

        $image_type = match ($image_info[2] ?? null) {
            IMAGETYPE_PNG => 'PNG',
            IMAGETYPE_JPEG => 'JPG',
            default => '',
        };
        if ($image_type === '') {
            return;
        }

        $maximum_width = 44.0;
        $maximum_height = 7.56;
        $scale = min(
            $maximum_width / (float) $image_info[0],
            $maximum_height / (float) $image_info[1]
        );
        $this->brand_logo_path = $brand_logo_path;
        $this->brand_logo_type = $image_type;
        $this->brand_logo_width = (float) $image_info[0] * $scale;
        $this->brand_logo_height = (float) $image_info[1] * $scale;
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
        $this->SetFillColor(246, 247, 251);
        $this->Rect(0, 0, $this->GetPageWidth(), $this->GetPageHeight(), 'F');

        $masthead_x = $this->lMargin - 2;
        $masthead_y = 5.8;
        $masthead_width = $this->GetPageWidth() - $this->lMargin - $this->rMargin + 4;
        $masthead_height = 13;
        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(223, 228, 236);
        $this->RoundedRect(
            $masthead_x,
            $masthead_y,
            $masthead_width,
            $masthead_height,
            2,
            '1111',
            'DF'
        );

        $logo_x = $this->lMargin;
        $logo_y = 8.2;
        $logo_width = 44.0;
        $logo_height = 7.56;
        $label_x = $logo_x;

        if ($this->brand_logo_path !== '') {
            $this->SetFillColor(255, 255, 255);
            $this->Rect(
                $logo_x - 1,
                $logo_y - 0.8,
                $logo_width + 2,
                $logo_height + 1.6,
                'F'
            );
            $this->Image(
                $this->brand_logo_path,
                $logo_x,
                $logo_y + (($logo_height - $this->brand_logo_height) / 2),
                $this->brand_logo_width,
                $this->brand_logo_height,
                $this->brand_logo_type,
                '',
                '',
                false,
                300
            );
            $label_x = $logo_x + $logo_width + 5;
        } else {
            $this->SetXY($logo_x, 9);
            $this->SetFont('dejavusans', 'B', 8.5);
            $this->SetTextColor(36, 87, 214);
            $brand = engagementPdfText(applicationBrandName());
            $brand_width = min(80.0, max(11.0, $this->GetStringWidth($brand) + 3.0));
            $this->Cell($brand_width, 5, $brand, 0, 0, 'L');
            $label_x = $logo_x + $brand_width;
        }

        $this->SetXY($label_x, 9.4);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(102, 112, 133);
        $this->Cell(
            $this->GetPageWidth() - $this->rMargin - $label_x,
            5,
            engagementPdfText('ENGAGEMENT BRIEF'),
            0,
            1,
            'R'
        );
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

function engagementPdfBrandLogoPath() {
    $relative_path = applicationBrandEmailLogo();
    if (preg_match('#\Aassets/[A-Za-z0-9][A-Za-z0-9._/-]*\z#D', $relative_path) !== 1
        || str_contains($relative_path, '..')
    ) {
        return '';
    }

    $logo_path = __DIR__ . '/' . $relative_path;
    return is_file($logo_path) && is_readable($logo_path) ? $logo_path : '';
}

function engagementPdfDateLabel($date_value) {
    $date_value = trim((string) $date_value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $date_value
        ? $date->format('F j, Y')
        : $date_value;
}

function engagementPdfTaskDueDetails($due_date, $business_date = null) {
    $due_state = followUpTaskDueState($due_date, $business_date);
    $date_value = trim((string) $due_date);
    if ($date_value === '') {
        return [
            'key' => 'none',
            'label' => 'No due date',
        ];
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $date_value);
    $date_label = $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $date_value
        ? $date->format('F j, Y')
        : $date_value;
    if ($due_state['key'] === 'overdue') {
        $label = 'Overdue - ' . $date_label;
    } elseif ($due_state['key'] === 'today') {
        $label = 'Due today - ' . $date_label;
    } else {
        $label = 'Due ' . $date_label;
    }

    return [
        'key' => $due_state['key'],
        'label' => $label,
    ];
}

function buildEngagementPdfTaskSection(
    array $follow_up_tasks,
    $business_date = null,
    $tasks_truncated = false
) {
    $entries = [];
    $status_labels = followUpTaskStatuses();
    $priority_labels = followUpTaskPriorities();
    $active_statuses = followUpTaskActiveStatuses();

    foreach ($follow_up_tasks as $task) {
        $status = (string) ($task['status'] ?? '');
        if (!in_array($status, $active_statuses, true)) {
            continue;
        }

        $due = engagementPdfTaskDueDetails($task['due_date'] ?? null, $business_date);
        $fields = [
            [
                'label' => 'Status',
                'value' => $status_labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
            ],
            [
                'label' => 'Due',
                'value' => $due['label'],
            ],
            [
                'label' => 'Assigned To',
                'value' => trim((string) ($task['assignee_username'] ?? '')) ?: 'Unassigned',
            ],
            [
                'label' => 'Priority',
                'value' => $priority_labels[$task['priority'] ?? 'normal'] ?? 'Normal',
            ],
        ];
        if ($status === 'waiting' && trim((string) ($task['waiting_on'] ?? '')) !== '') {
            $fields[] = [
                'label' => 'Waiting On',
                'value' => trim((string) $task['waiting_on']),
            ];
        }

        $entries[] = [
            'kind' => 'task',
            'due_state' => $due['key'],
            'title' => trim((string) ($task['title'] ?? '')) ?: 'Follow-up task',
            'fields' => $fields,
        ];
    }

    return $entries === [] ? null : [
        'heading' => 'Follow-Up Work',
        'entries' => $entries,
        'notice' => $tasks_truncated
            ? sprintf(
                'Showing the first %d active tasks in due-date order. Additional active tasks are available in MOED.',
                count($entries)
            )
            : '',
    ];
}

function engagementPdfEntryFieldValue(array $entry, $label, $default = '') {
    foreach ($entry['fields'] ?? [] as $field) {
        if (($field['label'] ?? '') === $label) {
            return engagementPdfText($field['value'] ?? '');
        }
    }
    return engagementPdfText($default);
}

function engagementPdfTaskCardPalette($due_state) {
    if ($due_state === 'overdue') {
        return [
            'fill' => [255, 232, 238],
            'border' => [241, 172, 186],
            'edge' => [217, 45, 32],
            'due_text' => [180, 35, 24],
        ];
    }
    if ($due_state === 'today') {
        return [
            'fill' => [228, 242, 255],
            'border' => [159, 204, 247],
            'edge' => [37, 99, 235],
            'due_text' => [23, 92, 211],
        ];
    }

    return [
        'fill' => [255, 255, 255],
        'border' => [223, 228, 236],
        'edge' => null,
        'due_text' => [102, 112, 133],
    ];
}

function engagementPdfTaskStatusPalette($status) {
    if ($status === 'Waiting') {
        return [
            'fill' => [255, 243, 216],
            'text' => [154, 91, 5],
        ];
    }
    if ($status === 'In progress') {
        return [
            'fill' => [239, 244, 255],
            'text' => [36, 87, 214],
        ];
    }

    return [
        'fill' => [241, 245, 249],
        'text' => [102, 112, 133],
    ];
}

function engagementPdfTaskEntryLayout(DnrEngagementPdf $pdf, array $entry) {
    $available_width = engagementPdfAvailableWidth($pdf);
    $status = engagementPdfEntryFieldValue($entry, 'Status', 'Open');
    $pdf->SetFont('dejavusans', 'B', 7.25);
    $status_width = min(38, max(20, $pdf->GetStringWidth(mb_strtoupper($status, 'UTF-8')) + 8));
    $title_width = max(45, $available_width - $status_width - 14);
    $pdf->SetFont('dejavusans', 'B', 10);
    $title_height = max(5.5, $pdf->getStringHeight(
        $title_width,
        engagementPdfText($entry['title'] ?? 'Follow-up task'),
        false,
        true,
        '',
        0
    ));

    $due = engagementPdfEntryFieldValue($entry, 'Due', 'No due date');
    $assignee = engagementPdfEntryFieldValue($entry, 'Assigned To', 'Unassigned');
    $priority = engagementPdfEntryFieldValue($entry, 'Priority', 'Normal');
    $meta = $due . '  |  Owner: ' . $assignee . '  |  ' . $priority . ' priority';
    $pdf->SetFont('dejavusans', '', 8);
    $meta_height = max(4.5, $pdf->getStringHeight(
        $available_width - 10,
        $meta,
        false,
        true,
        '',
        0
    ));

    $waiting = engagementPdfEntryFieldValue($entry, 'Waiting On');
    $waiting_text = $waiting === '' ? '' : 'Waiting on: ' . $waiting;
    $waiting_height = 0;
    if ($waiting_text !== '') {
        $pdf->SetFont('dejavusans', 'B', 8);
        $waiting_height = 1.5 + max(4.5, $pdf->getStringHeight(
            $available_width - 10,
            $waiting_text,
            false,
            true,
            '',
            0
        ));
    }

    return [
        'height' => 3.2 + max($title_height, 6.2) + 1.5 + $meta_height + $waiting_height + 3.2,
        'status' => $status,
        'status_width' => $status_width,
        'title_width' => $title_width,
        'title_height' => $title_height,
        'meta' => $meta,
        'meta_height' => $meta_height,
        'waiting_text' => $waiting_text,
    ];
}

function addEngagementPdfTaskEntry(DnrEngagementPdf $pdf, array $entry) {
    $layout = engagementPdfTaskEntryLayout($pdf, $entry);
    ensureEngagementPdfSpace($pdf, $layout['height'] + 3);

    $card_x = $pdf->GetX();
    $card_y = $pdf->GetY();
    $card_width = engagementPdfAvailableWidth($pdf);
    $palette = engagementPdfTaskCardPalette($entry['due_state'] ?? 'none');
    $pdf->SetFillColor(...$palette['fill']);
    $pdf->SetDrawColor(...$palette['border']);
    $pdf->RoundedRect($card_x, $card_y, $card_width, $layout['height'], 2, '1111', 'DF');
    if ($palette['edge'] !== null) {
        $pdf->SetFillColor(...$palette['edge']);
        $pdf->Rect($card_x, $card_y + 2, 1.4, $layout['height'] - 4, 'F');
    }

    $content_x = $card_x + 5;
    $content_y = $card_y + 3.2;
    $status_x = $card_x + $card_width - $layout['status_width'] - 4;
    $status_palette = engagementPdfTaskStatusPalette($layout['status']);
    $pdf->SetFillColor(...$status_palette['fill']);
    $pdf->RoundedRect($status_x, $content_y, $layout['status_width'], 6.2, 3.1, '1111', 'F');
    $pdf->SetXY($status_x, $content_y + 0.4);
    $pdf->SetFont('dejavusans', 'B', 7.25);
    $pdf->SetTextColor(...$status_palette['text']);
    $pdf->Cell(
        $layout['status_width'],
        5.2,
        mb_strtoupper($layout['status'], 'UTF-8'),
        0,
        0,
        'C'
    );

    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(23, 32, 51);
    $pdf->MultiCell(
        $layout['title_width'],
        5.5,
        engagementPdfText($entry['title'] ?? 'Follow-up task'),
        0,
        'L',
        false,
        1,
        $content_x,
        $content_y
    );

    $meta_y = $content_y + max($layout['title_height'], 6.2) + 1.5;
    $pdf->SetXY($content_x, $meta_y);
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(...$palette['due_text']);
    $pdf->MultiCell(
        $card_width - 10,
        4.5,
        $layout['meta'],
        0,
        'L',
        false,
        1
    );

    if ($layout['waiting_text'] !== '') {
        $pdf->SetX($content_x);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(132, 54, 0);
        $pdf->MultiCell(
            $card_width - 10,
            4.5,
            $layout['waiting_text'],
            0,
            'L',
            false,
            1
        );
    }

    $pdf->SetXY($card_x, $card_y + $layout['height'] + 3);
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
            $pdf->SetFillColor(255, 255, 255);
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
        $pdf->SetFillColor(255, 255, 255);
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
        if (($entry['kind'] ?? '') === 'task') {
            $height += engagementPdfTaskEntryLayout($pdf, $entry)['height'] + 3;
            continue;
        }
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

    $notice = trim(engagementPdfText($section['notice'] ?? ''));
    if ($notice !== '') {
        $pdf->SetFont('dejavusans', '', 8);
        $height += max(
            9,
            5 + $pdf->getStringHeight(
                engagementPdfAvailableWidth($pdf) - 10,
                $notice,
                false,
                true,
                '',
                0
            )
        );
    }

    return $height;
}

function addEngagementPdfSectionNotice(DnrEngagementPdf $pdf, $notice) {
    $notice = trim(engagementPdfText($notice));
    if ($notice === '') {
        return;
    }

    $available_width = engagementPdfAvailableWidth($pdf);
    $pdf->SetFont('dejavusans', '', 8);
    $notice_height = max(
        9,
        5 + $pdf->getStringHeight($available_width - 10, $notice, false, true, '', 0)
    );
    ensureEngagementPdfSpace($pdf, $notice_height);
    $notice_x = $pdf->GetX();
    $notice_y = $pdf->GetY();
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(223, 228, 236);
    $pdf->RoundedRect($notice_x, $notice_y, $available_width, $notice_height, 2, '1111', 'DF');
    $pdf->SetTextColor(102, 112, 133);
    $pdf->MultiCell(
        $available_width - 10,
        4,
        $notice,
        0,
        'L',
        false,
        1,
        $notice_x + 5,
        $notice_y + 2.5
    );
    $pdf->SetXY($notice_x, $notice_y + $notice_height);
}

function engagementPdfSectionMinimumStartHeight(DnrEngagementPdf $pdf, array $section) {
    $first_entry = ($section['entries'] ?? [])[0] ?? null;
    if (is_array($first_entry) && ($first_entry['kind'] ?? '') === 'task') {
        return 10.5 + engagementPdfTaskEntryLayout($pdf, $first_entry)['height'] + 3;
    }

    return 24;
}

function addEngagementPdfSection(DnrEngagementPdf $pdf, array $section) {
    $margins = $pdf->getMargins();
    $available_height = $pdf->GetPageHeight() - $margins['bottom'] - $pdf->GetY();
    $maximum_height = $pdf->GetPageHeight() - $margins['top'] - $margins['bottom'];
    $estimated_height = estimateEngagementPdfSectionHeight($pdf, $section);
    $minimum_start_height = engagementPdfSectionMinimumStartHeight($pdf, $section);
    if (($estimated_height <= $maximum_height && $estimated_height > $available_height)
        || $available_height < $minimum_start_height
    ) {
        $pdf->AddPage();
    }

    addEngagementPdfSectionHeading($pdf, $section['heading'] ?? '');

    foreach ($section['entries'] ?? [] as $entry_index => $entry) {
        if (($entry['kind'] ?? '') === 'task') {
            addEngagementPdfTaskEntry($pdf, $entry);
            continue;
        }
        if ($entry_index > 0) {
            $pdf->Ln(2);
        }
        if (!empty($entry['title'])) {
            addEngagementPdfEntryTitle($pdf, $entry['title']);
        }
        addEngagementPdfFields($pdf, $entry['fields'] ?? []);
    }

    addEngagementPdfSectionNotice($pdf, $section['notice'] ?? '');

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

function renderEngagementPdf(
    array $export,
    $generated_date = null,
    array $follow_up_tasks = [],
    $business_date = null,
    $tasks_truncated = false
) {
    $title = (string) ($export['title'] ?? 'Engagement');
    $business_date = $business_date ?: applicationBusinessDate();
    $generated_date = $generated_date ?: engagementPdfDateLabel($business_date);
    $pdf = new DnrEngagementPdf('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetTitle(engagementPdfText($title));
    $pdf->SetAuthor(applicationBrandName());
    $pdf->SetCreator(applicationBrandName());
    $pdf->setEngagementTitle($title);
    $pdf->setGeneratedDate($generated_date);
    $pdf->setBrandLogoPath(engagementPdfBrandLogoPath());
    $pdf->SetMargins(18, 23, 18);
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

    $task_section = buildEngagementPdfTaskSection(
        $follow_up_tasks,
        $business_date,
        $tasks_truncated
    );
    if ($task_section !== null) {
        addEngagementPdfSection($pdf, $task_section);
    }

    foreach ($chron_sections as $chron_section) {
        $pdf->AddPage();
        addEngagementPdfSection($pdf, $chron_section);
    }

    return $pdf->Output('', 'S');
}
