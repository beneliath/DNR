<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') { echo "Audience terminal tests require a disposable database.\n"; exit; }
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_ai.php';
require_once __DIR__.'/integration_auth_helpers.php';
function audienceTerminalExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')", ['audience-terminal-'.bin2hex(random_bytes(5)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
$uid = (int)$conn->insert_id; $session = '';
$make = static function (string $mode) use ($conn, $uid): array {
    $uuid = bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
    $request = ['role'=>'editor', 'page'=>'audience.php', 'step'=>'audience-ai', 'question'=>'Synthetic terminal request',
        'history'=>[], 'topic'=>'audience-ai:'.$mode, 'request_id'=>$uuid, 'ui'=>[], '_audience_ai'=>1, 'mode'=>$mode];
    if ($mode === 'analytics') $request['analysis_scope'] = [];
    $created = false; $id = aiCoachRecordRequest($conn, $request, $uid, $created);
    audienceTerminalExpect($created && $id !== null, 'Synthetic assistant request created');
    return [$id, $request];
};
try {
    $user = fetchAuthenticationUserById($conn, $uid); $csrf = bin2hex(random_bytes(32));
    session_start(); $_SESSION = ['user_id'=>$uid, 'username'=>$user['username'], 'role'=>'editor', 'auth_version'=>(int)$user['auth_version'], 'auth_complete'=>true, '_csrf_token'=>$csrf];
    completeIntegrationTestMfaSession(); $session = session_id(); session_write_close();
    $http = static function (string $uuid, bool $cancel = false) use ($session, $csrf): array {
        $handle = curl_init('http://127.0.0.1/audience_ai_api.php'.($cancel ? '' : '?request_id='.$uuid));
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_COOKIE=>'PHPSESSID='.$session,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-CSRF-Token: '.$csrf]]);
        if ($cancel) curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['action'=>'cancel', 'request_id'=>$uuid])]);
        $raw = curl_exec($handle);
        if ($raw === false) throw new RuntimeException(curl_error($handle));
        return ['status'=>curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'data'=>json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
    };
    foreach (['analytics'=>90, 'content'=>20] as $mode=>$seconds) {
        [$id, $request] = $make($mode);
        audienceTerminalExpect($http($request['request_id'], true)['data']['saved'], 'API cancels before queue admission');
        audienceTerminalExpect(!aiCoachEnqueue($conn, $id, $request), 'Cancelled work cannot be admitted');
        $status = $http($request['request_id']);
        audienceTerminalExpect($status['status'] === 200 && $status['data']['response']['purpose'] === 'audience-ai'
            && $status['data']['response']['mode'] === $mode && $status['data']['response']['reason'] === 'cancelled', 'Cancellation restores with its assistant type');

        [$id, $request] = $make($mode); audienceTerminalExpect(aiCoachEnqueue($conn, $id, $request), 'Synthetic work admitted');
        audienceTerminalExpect((int)$conn->execute_query('SELECT TIMESTAMPDIFF(SECOND,r.created_at,j.deadline_at) FROM ai_coach_requests r JOIN ai_coach_jobs j ON j.request_id=r.id WHERE r.id=?', [$id])->fetch_row()[0] === $seconds, 'Keep the existing assistant deadline');
        $job = aiCoachClaimJob($conn); audienceTerminalExpect((int)$job['request_id'] === $id, 'Synthetic job claimed');
        audienceTerminalExpect($http($request['request_id'], true)['data']['saved'], 'API cancels running work');
        audienceTerminalExpect(aiCoachJobCancelled($conn, $job), 'Running worker observes cancellation');
        aiCoachFinishJob($conn, $job, ['purpose'=>'audience-ai', 'mode'=>$mode, 'reason'=>'', 'message'=>'Late answer'], 1);
        audienceTerminalExpect($http($request['request_id'])['data']['response']['reason'] === 'cancelled', 'A late worker result cannot overwrite cancellation');

        [$id, $request] = $make($mode); aiCoachEnqueue($conn, $id, $request);
        $conn->execute_query('UPDATE ai_coach_requests SET created_at=UTC_TIMESTAMP(6)-INTERVAL 100 SECOND WHERE id=?', [$id]);
        $conn->execute_query('UPDATE ai_coach_jobs j JOIN ai_coach_requests r ON r.id=j.request_id SET j.deadline_at=DATE_ADD(r.created_at,INTERVAL '.$seconds.' SECOND) WHERE r.id=?', [$id]);
        aiCoachExpireJobs($conn); $status = $http($request['request_id']);
        audienceTerminalExpect($status['data']['response']['purpose'] === 'audience-ai' && $status['data']['response']['mode'] === $mode
            && $status['data']['response']['reason'] === 'timeout', 'Expired work restores as an assistant timeout');
        audienceTerminalExpect((int)$conn->execute_query('SELECT duration_ms FROM ai_coach_requests WHERE id=?', [$id])->fetch_row()[0] === $seconds * 1000, 'Timeout duration reflects the actual deadline');

        foreach (['cancelled', 'timeout'] as $reason) {
            [$id, $request] = $make($mode); $legacy = json_encode(aiCoachTerminalReply('', $reason), JSON_THROW_ON_ERROR);
            $conn->execute_query("UPDATE ai_coach_requests SET outcome=?,response_json=?,completed_at=UTC_TIMESTAMP(6) WHERE id=?", [$reason === 'cancelled' ? 'cancelled' : 'unavailable', $legacy, $id]);
            $before = $conn->execute_query('SELECT response_json FROM ai_coach_requests WHERE id=?', [$id])->fetch_row()[0];
            $status = $http($request['request_id']);
            audienceTerminalExpect($status['data']['response']['purpose'] === 'audience-ai' && $status['data']['response']['mode'] === $mode
                && $status['data']['response']['reason'] === $reason, 'Legacy saved failures reopen through the authenticated API');
            audienceTerminalExpect($before === $conn->execute_query('SELECT response_json FROM ai_coach_requests WHERE id=?', [$id])->fetch_row()[0], 'Legacy recovery does not rewrite history');
            audienceTerminalExpect(audienceAiRequestStatus($conn, $uid, 'admin', $request['request_id'], $mode) === null, 'Recovery remains role scoped');
            audienceTerminalExpect(audienceAiRequestStatus($conn, $uid + 10000, 'editor', $request['request_id'], $mode) === null, 'Recovery remains owner scoped');
        }
    }
} finally {
    if ($session !== '') { session_id($session); session_start(); $_SESSION = []; session_destroy(); }
    $conn->execute_query('DELETE FROM ai_coach_requests WHERE user_id=?', [$uid]);
    $conn->execute_query('DELETE FROM users WHERE id=?', [$uid]);
}
echo "Audience cancellation, expiry, late answers, and legacy HTTP recovery passed.\n";
