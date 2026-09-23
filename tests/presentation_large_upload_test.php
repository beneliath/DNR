<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/presentation_slidedeck_helpers.php';
require_once __DIR__ . '/presentation_slidedeck_fixture.php';
ini_set('memory_limit', '128M');
$path = tempnam(sys_get_temp_dir(), 'large-deck-');
try {
    foreach (['pptx' => 'writeSizedTestSlidedeck', 'ppt' => 'writeSizedLegacySlidedeck'] as $extension => $writer) {
        $writer($path, 500 * 1024 * 1024);
        $asset = presentationSlidedeckFromPath($path, 'large.' . $extension, false);
        if ($asset['size'] !== 500 * 1024 * 1024 || isset($asset['data']) || $asset['path'] !== $path
            || !hash_equals(hash_file('sha256', $path, true), $asset['sha256'])) throw new RuntimeException('Large upload metadata mismatch.');
        $file = fopen($path, 'ab'); fwrite($file, "\0"); fclose($file); clearstatcache(true, $path);
        try { presentationSlidedeckFromPath($path, 'large.' . $extension, false); throw new RuntimeException('500 MB + 1 accepted.'); }
        catch (InvalidArgumentException $expected) { if (!str_contains($expected->getMessage(), '500 MB')) throw $expected; }
        echo '500 MB ' . strtoupper($extension) . ' accepted below 128 MB PHP memory; one byte over rejected.', "\n";
    }
} finally { @unlink($path); }
