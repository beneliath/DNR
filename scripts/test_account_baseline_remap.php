<?php
/** Date remapping for the approved Test Account snapshot, in an isolated DB only. */
declare(strict_types=1);

function demoDate(string $value): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid ISO date.');
    return $date;
}

function demoMonth(DateTimeImmutable $anchor, int $months): DateTimeImmutable {
    $first = $anchor->modify('first day of this month')->modify(sprintf('%+d months', $months));
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min((int)$anchor->format('d'), (int)$first->format('t')));
}

function demoDays(string $from, string $to): int {
    return (int)demoDate(substr($from, 0, 10))->diff(demoDate(substr($to, 0, 10)))->format('%r%a');
}

function demoShift(mixed $value, int $days): mixed {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/birthday|birth_date/i', $key)) continue;
            $value[$key] = demoShift($item, $days);
        }
    } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value)) {
        return demoDate(substr($value, 0, 10))->modify(sprintf('%+d days', $days))->format('Y-m-d').substr($value, 10);
    }
    return $value;
}

function demoEventStart(string $value, string $source, string $destination): string {
    $old = demoDate($value); $from = demoDate($source); $to = demoDate($destination);
    $months = ((int)$old->format('Y') - (int)$from->format('Y')) * 12 + (int)$old->format('m') - (int)$from->format('m');
    return demoMonth($to, $months)->modify(sprintf('%+d days', (int)$old->format('d') - (int)$from->format('d')))->format('Y-m-d');
}

$options = getopt('', ['tables:', 'as-of:', 'source-anchor:', 'map-json:']);
// Pure date entry point also exercises the exact production functions in tests.
if (isset($options['map-json'])) {
    $input = json_decode($options['map-json'], true, 32, JSON_THROW_ON_ERROR);
    $result = [];
    foreach ($input as $case) {
        $result[] = isset($case['event'])
            ? demoEventStart($case['event'], $case['source'], $case['anchor'])
            : demoShift($case['value'], demoDays($case['source'], $case['anchor']));
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    exit;
}

require '/var/www/html/bootstrap.php';
require_once '/var/www/html/reimbursement_submission_helpers.php';
if (PHP_SAPI !== 'cli' || currentAccountKey() !== 'test-account' || accountIsPrimary()
    || getenv('DB_HOST') !== '127.0.0.1') throw new RuntimeException('Isolated Test Account database only.');
$anchor = $options['as-of'] ?? ''; $source = $options['source-anchor'] ?? '';
$days = demoDays($source, $anchor);
$tables = json_decode(file_get_contents($options['tables']), true, 32, JSON_THROW_ON_ERROR);
$eventShifts = [];
foreach ($conn->query('SELECT id,event_start_date FROM engagements')->fetch_all(MYSQLI_ASSOC) as $event) {
    $eventShifts[(int)$event['id']] = demoDays($event['event_start_date'], demoEventStart($event['event_start_date'], $source, $anchor));
}
// Inquiry next-action dates and their linked tasks use the same anchor-day shift.
$inquiryTasks = array_column($conn->query('SELECT next_action_task_id FROM booking_inquiries WHERE next_action_task_id IS NOT NULL')->fetch_all(MYSQLI_ASSOC), 'next_action_task_id');
$inquirySchedules = [];
foreach ($conn->query('SELECT id,preferred_start_date,converted_engagement_id FROM booking_inquiries')->fetch_all(MYSQLI_ASSOC) as $inquiry) {
    $inquirySchedules[(int)$inquiry['id']] = !empty($inquiry['converted_engagement_id'])
        ? $eventShifts[(int)$inquiry['converted_engagement_id']]
        : ($inquiry['preferred_start_date'] === null ? $days : demoDays($inquiry['preferred_start_date'], demoEventStart($inquiry['preferred_start_date'], $source, $anchor)));
}
$conn->begin_transaction();
try {
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z_]+$/D', $table)) throw new RuntimeException('Invalid table.');
        $columns = $conn->execute_query("SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND DATA_TYPE IN ('date','datetime','timestamp','json')", [$table])->fetch_all(MYSQLI_ASSOC);
        $keys = array_column($conn->query("SHOW KEYS FROM `$table` WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC), 'Column_name');
        if (!$columns) continue;
        if (!$keys) throw new RuntimeException('A primary key is required: '.$table);
        foreach ($conn->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC) as $row) {
            $shift = $days;
            if ($table === 'engagements') $shift = $eventShifts[(int)$row['id']];
            elseif (!empty($row['engagement_id'])) $shift = $eventShifts[(int)$row['engagement_id']] ?? $days;
            if ($table === 'follow_up_tasks' && in_array($row['id'], $inquiryTasks)) $shift = $days;
            $assign = []; $values = [];
            foreach ($columns as $column) {
                $name = $column['COLUMN_NAME'];
                if ($row[$name] === null || preg_match('/birthday|birth_date/i', $name)) continue;
                $value = $column['DATA_TYPE'] === 'json' ? json_decode($row[$name], true, 64, JSON_THROW_ON_ERROR) : $row[$name];
                $columnShift = $table === 'booking_inquiries' && in_array($name, ['preferred_start_date','preferred_end_date','alternate_start_date','alternate_end_date'], true)
                    ? $inquirySchedules[(int)$row['id']] : $shift;
                $mapped = demoShift($value, $columnShift);
                $assign[] = '`'.$name.'`=?';
                $values[] = $column['DATA_TYPE'] === 'json' ? json_encode($mapped, JSON_THROW_ON_ERROR) : $mapped;
            }
            // Explicitly retain the mapped timestamp rather than an ON UPDATE default.
            if ($assign) $conn->execute_query('UPDATE `'.$table.'` SET '.implode(',', $assign).' WHERE '.implode(' AND ', array_map(static fn($k) => '`'.$k.'`=?', $keys)), array_merge($values, array_map(static fn($k) => $row[$k], $keys)));
        }
    }
    $snapshots = 0;
    foreach ($conn->query('SELECT id,snapshot_ciphertext FROM reimbursement_submissions WHERE snapshot_ciphertext IS NOT NULL')->fetch_all(MYSQLI_ASSOC) as $row) {
        $snapshot = json_decode(\Dnr\Security\ApplicationKey::open($row['snapshot_ciphertext']), true, 64, JSON_THROW_ON_ERROR);
        if (!empty($snapshot['deliveries'])) throw new RuntimeException('Non-fixture delivery history needs review.');
        $cipher = \Dnr\Security\ApplicationKey::seal(json_encode(demoShift($snapshot, $days), JSON_THROW_ON_ERROR));
        $conn->execute_query('UPDATE reimbursement_submissions SET snapshot_ciphertext=? WHERE id=?', [$cipher, (int)$row['id']]);
        $snapshots++;
    }
    $receipts = 0;
    foreach ($conn->query('SELECT rr.*,e.expense_date,e.amount_cents,c.description AS category FROM reimbursement_receipts rr JOIN reimbursement_expenses e ON e.id=rr.expense_id JOIN reimbursement_cost_centers c ON c.id=e.cost_center_id ORDER BY rr.id')->fetch_all(MYSQLI_ASSOC) as $row) {
        if ($row['content_type'] !== 'application/pdf' || !preg_match('/^demo-receipt-(\d+)\.pdf$/D', $row['filename'], $match)) throw new RuntimeException('Unexpected baseline receipt.');
        $pdf = new TCPDF(); $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); $pdf->AddPage(); $pdf->SetFont('helvetica', '', 14);
        $pdf->MultiCell(0, 10, "TEST ACCOUNT - FICTIONAL RECEIPT\n\nDemo expense #".$match[1]."\nDate: ".$row['expense_date']."\nCategory: ".$row['category']."\nTotal: $".number_format((int)$row['amount_cents']/100, 2)."\n\nNo real purchase or payment occurred.");
        $key = storePersistentFile($conn, $pdf->Output('', 'S'), $row['filename'], 'application/pdf');
        $conn->execute_query("UPDATE reimbursement_receipts SET storage_key=?,report_key=?,report_status='ready' WHERE id=?", [$key, $key, (int)$row['id']]);
        $receipts++;
    }
    $conn->commit();
    echo json_encode(['remapped_tables'=>count($tables), 'anchor'=>$anchor, 'date_shift_days'=>$days, 'rebuilt_receipts'=>$receipts, 'remapped_snapshots'=>$snapshots]), "\n";
} catch (Throwable $error) { $conn->rollback(); throw $error; }
