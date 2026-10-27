<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("MessageMonkey workspace performance skipped (disposable database required).\n");
}
ob_start();
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once __DIR__.'/integration_auth_helpers.php';
$db = applicationDatabaseConnection();
$suffix = bin2hex(random_bytes(6));
$username = 'mm-performance-'.$suffix;
$password = bin2hex(random_bytes(20));
$db->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'admin')", [$username,password_hash($password,PASSWORD_DEFAULT)]);
$cookie = tempnam(sys_get_temp_dir(), 'mm-performance-');
$client = static function(string $path, ?array $post = null) use ($cookie): array {
    $curl = curl_init('http://127.0.0.1/'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size),'ms'=>curl_getinfo($curl,CURLINFO_TOTAL_TIME)*1000];
};
try {
    $login = $client('login.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $login['body'], $match);
    if (empty($match[1])) throw new RuntimeException('Missing login CSRF');
    $login = $client('login.php', ['csrf_token'=>html_entity_decode($match[1],ENT_QUOTES),'username'=>$username,'password'=>$password]);
    $login = finishIntegrationTestEnrollment($client,$login);
    if ($login['status'] !== 302) throw new RuntimeException('Performance fixture login failed');
    // These rows are only in the runner's disposable database. They are never
    // subscribed, queued for delivery, or inserted in another account.
    for ($batch = 0; $batch < 50; $batch++) {
        $params = [];
        for ($i = 0; $i < 100; $i++) {
            $params[] = 'mm-perf-'.$suffix.'-'.($batch*100+$i).'@example.test';
            $params[] = 'Performance '.$suffix;
        }
        $db->execute_query("INSERT INTO marketing_contacts(audience_id,email,first_name,notes,custom_json) VALUES ".implode(',',array_fill(0,100,"(1,?,?,'',JSON_OBJECT())")), $params);
    }
    $views = ['home','contacts','tags','groups','fields','segments','settings','campaigns','campaign-manager','templates','content','brand','delivery','automations','flow-templates','forms','landing-pages','surveys','preferences','inbox','analytics','assistant','manual','import','jobs'];
    $timings = []; $checks = 0;
    foreach ($views as $view) {
        $samples = []; $bytes = 0;
        for ($iteration = 0; $iteration < 6; $iteration++) {
            $response = $client('audience.php?'.http_build_query(['view'=>$view,'per_page'=>100]));
            if ($response['status'] !== 200 || preg_match('/(?:Warning|Fatal error|Deprecated):/', $response['body'])) {
                throw new RuntimeException($view.' failed during performance sampling: HTTP '.$response['status']);
            }
            if (!str_contains($response['body'],'audience-app')) throw new RuntimeException($view.' did not render the authenticated workspace');
            $checks++;
            if ($iteration > 0) $samples[] = $response['ms']; // One warmup per route.
            $bytes = max($bytes,strlen($response['body']));
            if ($view === 'contacts') {
                $dom = new DOMDocument(); @$dom->loadHTML($response['body']);
                if ((new DOMXPath($dom))->query('//*[@data-person-select]')->length !== 100) throw new RuntimeException('Contact result page is not bounded to 100 records');
                $checks++;
            }
        }
        sort($samples);
        $timings[$view] = ['samples'=>count($samples),'median_ms'=>round($samples[2],2),'max_ms'=>round(max($samples),2),'html_bytes'=>$bytes];
    }
    // Latencies are evidence, not flaky CI thresholds on shared development hosts.
    echo 'MessageMonkey workspace timing (5000 added contacts, one warmup + five requests per view): '.json_encode($timings,JSON_THROW_ON_ERROR)."\n";
    echo "MessageMonkey workspace performance: $checks HTTP checks passed across ".count($views)." views.\n";
} finally {
    if (is_file($cookie)) unlink($cookie);
}
ob_end_flush();
