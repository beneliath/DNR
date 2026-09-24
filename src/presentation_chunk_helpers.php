<?php
declare(strict_types=1);
require_once __DIR__ . '/presentation_slidedeck_helpers.php';

const PRESENTATION_CHUNK_BYTES = 10 * 1024 * 1024;
const PRESENTATION_CHUNK_TTL = 86400;

function presentationChunkPath(string $token): string
{
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $token)) throw new InvalidArgumentException('Invalid upload reference.');
    $path = persistentFileRoot() . '/.chunk-' . $token;
    if (is_link($path)) throw new RuntimeException('Invalid upload storage.');
    return $path;
}

/** Called by the storage worker; incomplete uploads never enter backups or public downloads. */
function cleanupPresentationChunks(): void
{
    foreach (glob(persistentFileRoot() . '/.chunk-*') ?: [] as $path) {
        if (!is_link($path) && is_file($path) && filemtime($path) < time() - PRESENTATION_CHUNK_TTL) {
            $handle = @fopen($path, 'r+b');
            if ($handle) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    clearstatcache(true, $path);
                    if (filemtime($path) < time() - PRESENTATION_CHUNK_TTL) @unlink($path);
                }
                fclose($handle);
            }
        }
    }
}

function startPresentationChunk(int $engagementId, string $row, string $name, int $size, string $assetKey = 'ppt_slidedeck'): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $maximum = $assetKey === 'speaker_notes' ? PRESENTATION_SPEAKER_NOTES_MAX_BYTES : PRESENTATION_SLIDEDECK_MAX_BYTES;
    if (!in_array($assetKey, ['ppt_slidedeck', 'speaker_notes'], true)
        || !preg_match('/\A[0-9]{1,12}\z/D', $row) || $size < 1 || $size > $maximum
        || ($assetKey === 'speaker_notes' ? $extension !== 'pdf' : !isset(PRESENTATION_SLIDEDECK_MIMES[$extension]))
        || strlen($name) > 255) {
        throw new InvalidArgumentException('Choose a PowerPoint file up to 500 MB or a PDF up to 100 MB.');
    }
    requirePresentationSlidedeckRuntime($name);
    $uploads = $_SESSION['presentation_chunks'] ?? [];
    foreach ($uploads as $token => $upload) {
        if ($upload['expires'] < time() || !is_file(presentationChunkPath($token))) unset($uploads[$token]);
    }
    // Bound reserved disk use per session, including incomplete and abandoned uploads.
    if (count($uploads) >= 10 || array_sum(array_column($uploads, 'size')) + $size > 1024 * 1024 * 1024) {
        throw new InvalidArgumentException('Too many pending uploads. Save your current uploads before adding more.');
    }
    // Allow space for staging and the verified persistent copy during final save.
    if (disk_free_space(persistentFileRoot()) < 2 * $size + 134217728) {
        throw new InvalidArgumentException('Not enough server storage for this upload. Ask an administrator to free space, then try again.');
    }
    $token = bin2hex(random_bytes(32));
    $file = fopen(presentationChunkPath($token), 'x+b');
    if (!$file) throw new RuntimeException('Unable to start upload.');
    chmod(presentationChunkPath($token), 0600);
    fclose($file);
    $uploads[$token] = ['engagement' => $engagementId, 'row' => $row, 'asset_key' => $assetKey, 'name' => $name, 'size' => $size,
        'user' => (int) $_SESSION['user_id'], 'expires' => time() + PRESENTATION_CHUNK_TTL];
    $_SESSION['presentation_chunks'] = $uploads;
    return $token;
}

function ownedPresentationChunk(string $token, int $engagementId, string $row): array
{
    $path = presentationChunkPath($token);
    $upload = $_SESSION['presentation_chunks'][$token] ?? null;
    if (!$upload || $upload['user'] !== (int) $_SESSION['user_id'] || $upload['engagement'] !== $engagementId
        || $upload['row'] !== $row || $upload['expires'] < time() || !is_file($path)) {
        throw new InvalidArgumentException('Upload expired or unavailable. Select the file again.');
    }
    return $upload;
}

/** Fixed offsets make retrying a request safe even when the response was lost. Session lock serializes writes. */
function appendPresentationChunk(string $token, int $engagementId, string $row, int $offset, string $source): int
{
    $upload = ownedPresentationChunk($token, $engagementId, $row);
    $length = filesize($source);
    if ($offset < 0 || $offset % PRESENTATION_CHUNK_BYTES !== 0 || $offset >= $upload['size']
        || $length !== min(PRESENTATION_CHUNK_BYTES, $upload['size'] - $offset)) {
        throw new InvalidArgumentException('Invalid upload chunk size or offset.');
    }
    $path = presentationChunkPath($token);
    $destination = fopen($path, 'r+b');
    $input = fopen($source, 'rb');
    if (!$destination || !$input) throw new RuntimeException('Unable to open upload.');
    try {
        if (!flock($destination, LOCK_EX)) throw new RuntimeException('Upload is busy.');
        $current = fstat($destination)['size'];
        if ($offset > $current) throw new InvalidArgumentException('Upload chunk is out of order.');
        // Retransmission overwrites the same range; a partial failed write is repaired too.
        if (fseek($destination, $offset) !== 0 || stream_copy_to_stream($input, $destination, $length) !== $length
            || !fflush($destination) || !fsync($destination)) throw new RuntimeException('Unable to store upload chunk.');
        touch($path);
        $_SESSION['presentation_chunks'][$token]['expires'] = time() + PRESENTATION_CHUNK_TTL;
        return $offset + $length;
    } finally { fclose($input); fclose($destination); }
}

function stagedPresentationAssets(array $rows, int $engagementId): array
{
    $assets = [];
    foreach ($rows as $row => $values) {
        foreach (['ppt_slidedeck' => 'ppt_upload_token', 'speaker_notes' => 'pdf_upload_token'] as $assetKey => $field) {
            $token = is_array($values) ? ($values[$field] ?? '') : '';
            if ($token === '') continue;
            if (!is_string($token)) throw new InvalidArgumentException('Invalid upload reference.');
            $upload = ownedPresentationChunk($token, $engagementId, (string) $row);
            if (($upload['asset_key'] ?? 'ppt_slidedeck') !== $assetKey) throw new InvalidArgumentException('Upload file type does not match this field.');
            $path = presentationChunkPath($token);
            clearstatcache(true, $path);
            if (filesize($path) !== $upload['size']) throw new InvalidArgumentException('File upload is incomplete. Try again.');
            // Only server-owned paths bypass is_uploaded_file; full format validation still applies.
            $asset = $assetKey === 'ppt_slidedeck'
                ? presentationSlidedeckFromPath($path, $upload['name'], false)
                : presentationSpeakerNotesFromPath($path, $upload['name'], false);
            unset($asset['data']);
            $asset['path'] = $path;
            $assets[(string) $row][$assetKey] = $asset;
        }
    }
    return $assets;
}

function discardPresentationChunk(string $token): void
{
    if (isset($_SESSION['presentation_chunks'][$token])) {
        @unlink(presentationChunkPath($token));
        unset($_SESSION['presentation_chunks'][$token]);
    }
}
