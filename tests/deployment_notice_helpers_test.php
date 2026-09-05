<?php

require_once __DIR__ . '/../src/deployment_notice_helpers.php';

function expectDeploymentNotice(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'dnr-notice-');
try {
    $state = ['id' => str_repeat('a', 32), 'phase' => 'pending', 'started_at' => 1000,
        'not_before' => 1300, 'expires_at' => 2000, 'commit' => 'private-source-sha',
        'backup' => '/private/recovery'];
    file_put_contents($path, json_encode($state));
    $result = deploymentNoticeStatus($path, 1100);
    expectDeploymentNotice($result['server_now'] === 1100.0, 'Use authoritative server time.');
    expectDeploymentNotice($result['notice']['not_before'] === 1300, 'Preserve shared deadline.');
    expectDeploymentNotice(!isset($result['notice']['commit'], $result['notice']['backup']), 'Expose only public fields.');
    expectDeploymentNotice(deploymentNoticeStatus($path, 1500)['notice']['phase'] === 'pending', 'Zero is pending, not maintenance.');
    expectDeploymentNotice(deploymentNoticeStatus($path, 2000)['notice'] === null, 'Expired pending notices disappear.');
    foreach (['complete', 'cancelled', 'unknown'] as $phase) {
        file_put_contents($path, json_encode(array_replace($state, ['phase' => $phase])));
        expectDeploymentNotice(deploymentNoticeStatus($path, 1100)['notice'] === null, 'Inactive notices are hidden.');
    }
    foreach (['deploying', 'failed'] as $phase) {
        file_put_contents($path, json_encode(array_replace($state, ['phase' => $phase])));
        expectDeploymentNotice(deploymentNoticeStatus($path, 3000)['notice']['phase'] === $phase, 'Maintenance must not expire.');
    }
    foreach (['{broken', '{}', json_encode(array_replace($state, ['not_before' => 1100])),
        json_encode(array_replace($state, ['not_before' => 'bad']))] as $contents) {
        file_put_contents($path, $contents);
        expectDeploymentNotice(deploymentNoticeStatus($path, 1100)['notice'] === null, 'Malformed notices do not render.');
    }
    expectDeploymentNotice(session_status() === PHP_SESSION_NONE, 'Polling must not start/refresh a session.');
} finally {
    unlink($path);
}
expectDeploymentNotice(deploymentNoticeStatus($path, 1100)['notice'] === null, 'Absent notice is normal.');
echo "Deployment notice helper tests passed.\n";
