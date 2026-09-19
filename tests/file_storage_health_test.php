<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/file_storage_maintenance_helpers.php';
$root = sys_get_temp_dir() . '/dnr-storage-health-' . bin2hex(random_bytes(8));
mkdir($root, 0700); putenv('DNR_FILE_STORAGE_PATH=' . $root); putenv('DNR_REQUIRE_STORAGE_MONITOR=1');
try {
    foreach ([[], ['checked_at'=>time()-181,'writable'=>true], ['checked_at'=>time(),'writable'=>false],
        ['checked_at'=>time(),'writable'=>true,'errors'=>['fixture'=>'checksum mismatch']]] as $state) {
        file_put_contents($root . '/.integrity.json', json_encode($state));
        try { requireHealthyPersistentStorage(); throw new LogicException('An unhealthy monitor was accepted.'); }
        catch (RuntimeException $expected) {}
    }
    file_put_contents($root . '/.integrity.json', json_encode(['checked_at'=>time(),'writable'=>true,'errors'=>[]]));
    requireHealthyPersistentStorage();
    putenv('DNR_STORAGE_MIN_FREE_BYTES=' . PHP_INT_MAX);
    try { requireHealthyPersistentStorage(); throw new LogicException('Low capacity was ignored.'); }
    catch (RuntimeException $expected) {}
    echo "Storage availability, stale-monitor and low-space health tests passed.\n";
} finally { unlink($root . '/.integrity.json'); rmdir($root); }
