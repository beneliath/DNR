<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/application_runtime.php';
use Dnr\Security\ApplicationKey;
$old = random_bytes(32); $new = random_bytes(32);
$nonce = random_bytes(24);
$legacy = base64_encode($nonce . sodium_crypto_secretbox('old secret', $nonce, $old));
$path = tempnam(sys_get_temp_dir(), 'dnr-keyring-');
file_put_contents($path, json_encode(['active' => 'new', 'legacy' => 'old', 'keys' => ['old' => base64_encode($old), 'new' => base64_encode($new)]]));
chmod($path, 0600);
putenv('DNR_APPLICATION_KEYRING_FILE=' . $path);
try {
    if (ApplicationKey::open($legacy) !== 'old secret') throw new RuntimeException('Legacy data must remain readable.');
    $sealed = ApplicationKey::seal('new secret');
    if (!str_starts_with($sealed, 'dnr1:new:') || ApplicationKey::open($sealed) !== 'new secret') throw new RuntimeException('Versioned round trip failed.');
    foreach (['dnr1:missing:' . $legacy, 'dnr1:new:' . $legacy, $sealed . 'invalid'] as $bad) {
        try { ApplicationKey::open($bad); throw new LogicException('Tampered or unknown key accepted.'); }
        catch (RuntimeException $expected) {}
    }
    $hashes = ApplicationKey::lookupHashes('recovery code');
    if (count($hashes) !== 2 || !hash_equals($hashes['old'], hash_hmac('sha256', 'recovery code', $old, true))) throw new RuntimeException('Old recovery lookup must survive rotation.');
    echo "Application keyring tests passed.\n";
} finally { unlink($path); putenv('DNR_APPLICATION_KEYRING_FILE'); }
