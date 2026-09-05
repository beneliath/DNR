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
    $preparing = array_replace($state, ['phase' => 'preparing', 'not_before' => null, 'countdown_started_at' => null]);
    file_put_contents($path, json_encode($preparing));
    expectDeploymentNotice(deploymentNoticeStatus($path, 1800)['notice']['phase'] === 'preparing', 'Preparation has no running deadline.');
    expectDeploymentNotice(deploymentNoticeStatus($path, 2000)['notice'] === null, 'Abandoned preparation expires.');
    $ready = array_replace($state, ['countdown_started_at' => 1800, 'not_before' => 2100, 'expires_at' => 3000,
        'countdown_commit' => 'private-countdown-sha']);
    file_put_contents($path, json_encode($ready));
    expectDeploymentNotice(deploymentNoticeStatus($path, 1900)['notice']['not_before'] === 2100, 'Countdown starts after preparation.');
    expectDeploymentNotice(!isset(deploymentNoticeStatus($path, 1900)['notice']['countdown_commit']), 'Countdown commit remains private.');
    file_put_contents($path, json_encode(array_replace($ready, ['not_before' => 2099])));
    expectDeploymentNotice(deploymentNoticeStatus($path, 1900)['notice'] === null, 'Reject a shortened window after long preparation.');
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
