<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/native_backup_stream_helpers.php';
function transformTestBackup(string $input, bool $encrypt, string $password = 'test-native-secret-with-enough-entropy'): string {
    $source = fopen('php://temp', 'w+b'); $target = fopen('php://temp', 'w+b');
    try { fwrite($source, $input); rewind($source); transformNativeBackupStream($source, $target, $password, $encrypt); rewind($target); return stream_get_contents($target); }
    finally { fclose($source); fclose($target); }
}
foreach ([1, 65536, 131077] as $size) {
    $plain = random_bytes($size); $encrypted = transformTestBackup($plain, true);
    if (transformTestBackup($encrypted, false) !== $plain) throw new RuntimeException('Stream round trip failed.');
}
$changed = $encrypted; $changed[strlen($changed) - 1] = chr(ord($changed[strlen($changed) - 1]) ^ 1);
foreach ([$changed, substr($encrypted, 0, -1), $encrypted . 'extra', substr($encrypted, 0, 30)] as $invalid) {
    try { transformTestBackup($invalid, false); throw new LogicException('Unauthenticated backup accepted.'); }
    catch (RuntimeException $expected) {}
}
try { transformTestBackup($encrypted, false, 'wrong-password-but-long-enough'); throw new LogicException('Wrong password accepted.'); }
catch (RuntimeException $expected) {}
echo "Native backup stream authentication tests passed.\n";
