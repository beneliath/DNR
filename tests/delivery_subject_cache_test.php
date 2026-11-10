<?php
declare(strict_types=1);

require_once __DIR__.'/../src/application_runtime.php';

use Dnr\MessageMonkey\DeliverySubjectCache;

$directory = sys_get_temp_dir().'/dnr-subject-test-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$keyring = $directory.'/keyring.json';
file_put_contents($keyring, json_encode(['active'=>'test', 'legacy'=>'test', 'keys'=>['test'=>base64_encode(random_bytes(32))]], JSON_THROW_ON_ERROR));
putenv('DNR_APPLICATION_KEYRING_FILE='.$keyring);
$checks = 0;
$expect = static function(bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
try {
    $cache = new DeliverySubjectCache($directory.'/subjects');
    $fingerprint = hash('sha256', 'immutable delivery');
    $subject = 'Private newsletter — שלום';
    $expect($cache->get($fingerprint) === null, 'A cold cache must be a miss.');
    $cache->put($fingerprint, $subject);
    $expect((new DeliverySubjectCache($directory.'/subjects'))->get($fingerprint) === $subject, 'Subjects survive separate cache instances with Unicode intact.');
    $file = $directory.'/subjects/'.$fingerprint.'.bin';
    $payload = file_get_contents($file);
    $expect(!str_contains($payload, $subject) && str_starts_with($payload, 'dnr1:test:'), 'Cached subjects must remain encrypted at rest.');
    $expect((fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'Cache files and directories must be private.');
    $expect($cache->get(hash('sha256', 'replaced snapshot')) === null, 'A changed content fingerprint cannot reuse the old subject.');
    $cache->put($fingerprint, '');
    $expect($cache->get($fingerprint) === '', 'An empty saved subject is valid cached content.');
    file_put_contents($file, 'corrupted ciphertext');
    $expect($cache->get($fingerprint) === null, 'Corrupted cache data must fall back to the immutable snapshot.');
    file_put_contents($file, str_repeat('x', 8193));
    $expect($cache->get($fingerprint) === null, 'Cache reads must have a small size limit.');
    $unavailable = new DeliverySubjectCache($keyring.'/not-a-directory');
    $unavailable->put($fingerprint, $subject);
    $expect($unavailable->get($fingerprint) === null, 'An unavailable cache must remain a harmless miss.');
    $cache->put('../invalid', $subject);
    $expect($cache->get('../invalid') === null, 'Only content digests may identify cache files.');
    for ($i = 0; $i < 514; $i++) $cache->put(hash('sha256', 'delivery-'.$i), 'Subject '.$i);
    $expect(count(glob($directory.'/subjects/*.bin')) === 512, 'Disposable cache storage must remain bounded.');
    $expect(glob($directory.'/subjects/.subject-*') === [], 'Atomic writes must not leave temporary files.');
    echo "Delivery subject cache passed ($checks checks).\n";
} finally {
    foreach (glob($directory.'/subjects/*') ?: [] as $file) unlink($file);
    if (is_dir($directory.'/subjects')) rmdir($directory.'/subjects');
    unlink($keyring);
    rmdir($directory);
    putenv('DNR_APPLICATION_KEYRING_FILE');
}
