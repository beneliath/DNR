<?php
/** Add manual, delay-only flows for checking the localhost action columns. */
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
$store = new AudienceStore($db, 1, (int) $actor['id'], 'Local action-layout samples');
$samples = [
    'Sample · Layout · Active journey' => 'running',
    'Sample · Layout · Draft welcome journey' => 'draft',
    'Sample · Layout · Paused community follow-up' => 'paused',
    'Sample · Layout · Active journey with a longer title to check available space' => 'running',
    'Sample · Layout · Archived welcome journey' => 'archived',
    'Sample · Layout · Archived community follow-up' => 'archived',
];

$result = $store->transaction(function () use ($store, $samples): array {
    $result = [];
    foreach ($samples as $name => $state) {
        $existing = $store->one('SELECT id,state,deleted_at FROM marketing_flows WHERE audience_id=? AND name=? LIMIT 1', [$store->audienceId, $name]);
        if ($existing) {
            $result[] = ['id' => (int) $existing['id'], 'name' => $name, 'state' => $existing['deleted_at'] ? 'archived' : $existing['state'], 'created' => false];
            continue;
        }
        $document = audienceFlowDefault();
        $document['start'] = 'sample_delay';
        $document['nodes'] = [['id' => 'sample_delay', 'type' => 'delay', 'label' => 'Local layout sample: delay only; no email or data changes', 'seconds' => 3600, 'next' => '']];
        $id = $store->saveFlow($name, $document);
        if (in_array($state, ['running', 'paused'], true)) {
            $store->flowOperation($id, 1, 'activate');
            if ($state === 'paused') $store->flowOperation($id, (int) $store->flow($id)['version'], 'pause');
        } elseif ($state === 'archived') {
            $store->flowOperation($id, 1, 'archive');
        }
        $result[] = ['id' => $id, 'name' => $name, 'state' => $state, 'created' => true];
    }
    return $result;
});

echo json_encode(['flows' => $result, 'automatic_entry' => false, 'email_steps' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
