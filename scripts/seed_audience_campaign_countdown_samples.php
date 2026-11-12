<?php
/** Add fictional scheduled and previously scheduled/sent campaigns to localhost:8080. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('DNR_LOCAL_SAMPLE_DATA') !== 'localhost:8080') {
    exit("Explicit localhost sample-data opt-in required.\n");
}
if (!is_file('/usr/local/etc/php/conf.d/zz-dnr-development.ini')) {
    throw new RuntimeException('Development bind-mounted runtime required.');
}
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_store.php';
if (currentAccountKey() !== 'shalom-in-messiah') {
    throw new RuntimeException('Only the local primary account may be seeded.');
}

$db = applicationDatabaseConnection();
$actor = $db->query("SELECT id FROM users WHERE role='admin' AND account_status='active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$actor) throw new RuntimeException('An active local administrator is required.');
$store = new AudienceStore($db, 1, (int)$actor['id'], 'Fictional localhost countdown samples');
$dataset = 'localhost-campaign-countdown-v1';
$samples = [
    ['Sample · Countdown · Community newsletter', '+2 days 15 hours 36 minutes', 'scheduled'],
    ['Sample · Countdown · Event reminder', '+5 hours 30 minutes', 'scheduled'],
    ['Sample · Countdown · Volunteer update', '+20 minutes', 'scheduled'],
    ['Sample · Countdown · Scheduled and sent newsletter', '-1 day', 'sent'],
];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$zone = new DateTimeZone('America/Chicago');
$json = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR);

$result = $store->transaction(function () use ($store, $dataset, $samples, $now, $zone, $json): array {
    $result = [];
    foreach ($samples as [$name, $offset, $status]) {
        $existing = $store->one('SELECT id,draft_json FROM marketing_campaigns WHERE audience_id=? AND name=? AND deleted_at IS NULL LIMIT 1 FOR UPDATE',
            [$store->audienceId, $name]);
        if ($existing && (audienceJson($existing['draft_json'])['sample_dataset'] ?? '') !== $dataset) {
            throw new RuntimeException('An unrelated campaign already uses a countdown sample name.');
        }
        $created = !$existing;
        if ($existing) {
            $campaignId = (int)$existing['id'];
        } else {
            $due = $now->modify($offset);
            $due = $due->setTime((int)$due->format('H'), (int)$due->format('i'), 0);
            $dueAt = $due->format('Y-m-d H:i:s');
            $sentAt = $status === 'sent' ? $due->modify('+45 seconds')->format('Y-m-d H:i:s') : null;
            $createdAt = ($status === 'sent' ? $due->modify('-2 days') : $now)->format('Y-m-d H:i:s');
            $updatedAt = $sentAt ?? $createdAt;
            $draft = [
                'sample_dataset'=>$dataset,
                'subject'=>$name,
                'preview_text'=>'Fictional local campaign for previewing scheduled countdowns and sent status.',
                'from_name'=>'Sample Community',
                'from_email'=>'countdown-preview@example.invalid',
                'target'=>'all',
                'recipients_saved'=>false,
                'text'=>'Fictional localhost preview only. No email recipients have been queued.',
                'design'=>audienceDesignStarters()['newsletter']['design'],
                'tracking'=>audienceCampaignDefaultTracking(),
                'send_time'=>[
                    'mode'=>'schedule', 'delivery'=>'specific', 'timezone'=>$zone->getName(),
                    'local'=>$due->setTimezone($zone)->format('Y-m-d H:i'), 'utc'=>$dueAt,
                ],
            ];
            $store->write("INSERT INTO marketing_campaigns(audience_id,name,campaign_type,status,draft_json,actor_label,created_at,updated_at,sent_at) VALUES (?,?,'regular',?,?,?,?,?,?)",
                [$store->audienceId, $name, $status, $json($draft), $store->actorLabel, $createdAt, $updatedAt, $sentAt]);
            $campaignId = (int)$store->db->insert_id;
            $assetKey = bin2hex(random_bytes(32));
            // An already-resolved empty audience prevents deferred recipient expansion.
            $snapshot = [
                'sample'=>true, 'sample_dataset'=>$dataset, 'recipients_resolved'=>true,
                'draft'=>$draft, 'type'=>'regular', 'base'=>'https://example.invalid',
                'identity'=>['LIST:ADDRESS'=>'Fictional local preview address'],
                'asset_key'=>$assetKey, 'tracking_key'=>bin2hex(random_bytes(32)),
            ];
            $store->write('INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label,created_at,updated_at,completed_at) VALUES (?,?,1,?,?,?,?,?,0,?,?,?,?,?)',
                [$store->audienceId, $campaignId, $status === 'sent' ? 'completed' : 'scheduled', $dueAt,
                    $zone->getName(), \Dnr\Security\ApplicationKey::seal($json($snapshot)), $assetKey,
                    $store->actorId, $store->actorLabel, $createdAt, $updatedAt, $sentAt]);
        }
        $row = $store->one('SELECT c.id,c.name,c.status,c.sent_at,d.id delivery_id,d.state delivery_state,d.due_at,d.completed_at,d.snapshot_ciphertext,
            (SELECT COUNT(*) FROM marketing_campaign_recipients r WHERE r.audience_id=d.audience_id AND r.delivery_id=d.id) recipient_rows
            FROM marketing_campaigns c JOIN marketing_campaign_deliveries d ON d.audience_id=c.audience_id AND d.campaign_id=c.id AND d.confirmed_version=1
            WHERE c.audience_id=? AND c.id=?', [$store->audienceId, $campaignId]);
        if (!$row || (int)$row['recipient_rows'] !== 0) throw new RuntimeException('Countdown samples must have no delivery recipients.');
        $snapshot = json_decode(\Dnr\Security\ApplicationKey::open($row['snapshot_ciphertext']), true, 64, JSON_THROW_ON_ERROR);
        if (($snapshot['sample_dataset'] ?? '') !== $dataset || ($snapshot['recipients_resolved'] ?? false) !== true) {
            throw new RuntimeException('Countdown sample must retain its resolved empty audience.');
        }
        unset($row['snapshot_ciphertext']);
        $row['scheduled_chicago'] = (new DateTimeImmutable($row['due_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('D, M j, Y g:i A T');
        $row['created'] = $created;
        $result[] = $row;
    }
    return $result;
});

echo json_encode(['account'=>currentAccountKey(), 'campaigns'=>$result, 'external_messages_sent'=>0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
