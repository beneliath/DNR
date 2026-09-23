<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/presentation_chunk_helpers.php';
require_once __DIR__ . '/presentation_slidedeck_fixture.php';
$root = sys_get_temp_dir() . '/dnr-chunks-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$previous = getenv('DNR_FILE_STORAGE_PATH');
putenv('DNR_FILE_STORAGE_PATH=' . $root);
$_SESSION = ['user_id' => 27];
function chunkExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function chunkReject(callable $action): void {
    try { $action(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid upload was accepted.');
}
try {
    ini_set('memory_limit', '128M');
    $source = $root . '/fixture';
    $piece = $root . '/piece';
    foreach (['pptx' => 'writeSizedTestSlidedeck', 'ppt' => 'writeSizedLegacySlidedeck', 'pdf' => static function ($path, $size) {
        $handle = fopen($path, 'wb'); fwrite($handle, "%PDF-1.4\n");
        ftruncate($handle, $size); $tail = "\nstartxref\n0\n%%EOF\n";
        fseek($handle, $size - strlen($tail)); fwrite($handle, $tail); fclose($handle); clearstatcache(true, $path);
    }] as $extension => $writer) {
        $size = ($extension === 'pdf' ? 100 : 331) * 1024 * 1024;
        $assetKey = $extension === 'pdf' ? 'speaker_notes' : 'ppt_slidedeck';
        $tokenField = $extension === 'pdf' ? 'pdf_upload_token' : 'ppt_upload_token';
        $writer($source, $size);
        $token = startPresentationChunk(2, '7', 'large.' . $extension, $size, $assetKey);
        chunkReject(fn() => ownedPresentationChunk($token, 3, '7'));
        chunkReject(fn() => ownedPresentationChunk($token, 2, '8'));
        $_SESSION['user_id'] = 28;
        chunkReject(fn() => ownedPresentationChunk($token, 2, '7'));
        $_SESSION['user_id'] = 27;
        chunkReject(fn() => stagedPresentationAssets(['7' => [$tokenField => $token]], 2));
        $input = fopen($source, 'rb');
        for ($offset = 0; $offset < $size; $offset += PRESENTATION_CHUNK_BYTES) {
            $output = fopen($piece, 'wb');
            $length = min(PRESENTATION_CHUNK_BYTES, $size - $offset);
            stream_copy_to_stream($input, $output, $length);
            fclose($output);
            clearstatcache(true, $piece);
            if ($offset === 0) chunkReject(fn() => appendPresentationChunk($token, 2, '7', PRESENTATION_CHUNK_BYTES, $piece));
            chunkExpect(appendPresentationChunk($token, 2, '7', $offset, $piece) === $offset + $length, 'Wrong acknowledged offset');
            // Simulate a lost response: replay exactly the same chunk.
            appendPresentationChunk($token, 2, '7', $offset, $piece);
        }
        fclose($input);
        $assets = stagedPresentationAssets(['7' => [$tokenField => $token]], 2);
        chunkExpect($assets['7'][$assetKey]['size'] === $size && hash_equals(hash_file('sha256', $source, true), $assets['7'][$assetKey]['sha256']), 'Reassembled file differs');
        $presentations = attachPresentationAssetChanges([['_form_key' => '7']], ['7' => []], [], $assets);
        chunkExpect($presentations[0]['asset_changes'][$assetKey]['action'] === 'replace', 'Staged deck not attached');
        chunkReject(fn() => attachPresentationAssetChanges([['_form_key' => '7']], ['7' => ['remove_' . $assetKey => '1']], [], $assets));
        $wrongField = $extension === 'pdf' ? 'ppt_upload_token' : 'pdf_upload_token';
        chunkReject(fn() => stagedPresentationAssets(['7' => [$wrongField => $token]], 2));
        discardPresentationChunk($token);
        chunkReject(fn() => ownedPresentationChunk($token, 2, '7'));
        echo ($size / 1048576) . ' MB ' . strtoupper($extension) . " uploaded in 10 MB chunks, retries verified, checksum and validation passed.\n";
    }
    chunkReject(fn() => startPresentationChunk(2, '7', 'bad.exe', 1));
    chunkReject(fn() => startPresentationChunk(2, '7', 'big.pptx', 501 * 1024 * 1024));
    chunkReject(fn() => startPresentationChunk(2, '7', 'big.pdf', 101 * 1024 * 1024, 'speaker_notes'));
    chunkReject(fn() => presentationChunkPath('../fixture'));
    $token = startPresentationChunk(2, '7', 'expired.pptx', 1);
    touch(presentationChunkPath($token), time() - PRESENTATION_CHUNK_TTL - 1);
    cleanupPresentationChunks();
    chunkExpect(!is_file(presentationChunkPath($token)), 'Expired upload was not cleaned');
    echo "Ownership, size limits, incomplete uploads, removal conflicts and expiration passed.\n";
} finally {
    foreach (glob($root . '/{*,.*}', GLOB_BRACE) ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($root);
    $previous === false ? putenv('DNR_FILE_STORAGE_PATH') : putenv('DNR_FILE_STORAGE_PATH=' . $previous);
}
