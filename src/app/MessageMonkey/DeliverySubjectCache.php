<?php
declare(strict_types=1);

namespace Dnr\MessageMonkey;

use Dnr\Security\ApplicationKey;

/** Disposable encrypted projections of immutable delivery content, never report totals. */
final class DeliverySubjectCache
{
    private readonly string $directory;
    private const MAX_ENTRIES = 512;
    private const MAX_BYTES = 8192;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir().'/dnr-delivery-subjects-v1';
    }

    public function get(string $fingerprint): ?string
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint)) return null;
        $payload = @file_get_contents($this->directory.'/'.$fingerprint.'.bin', false, null, 0, self::MAX_BYTES + 1);
        if ($payload === false || strlen($payload) > self::MAX_BYTES) return null;
        try {
            $subject = json_decode(ApplicationKey::open($payload), true, 2, JSON_THROW_ON_ERROR);
            return is_string($subject) ? $subject : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function put(string $fingerprint, string $subject): void
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) || strlen($subject) > 4096) return;
        try {
            $payload = ApplicationKey::seal(json_encode($subject, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return;
        }
        if (strlen($payload) > self::MAX_BYTES) return;
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) return;
        $temporary = @tempnam($this->directory, '.subject-');
        if ($temporary === false) return;
        if (@file_put_contents($temporary, $payload) !== strlen($payload)
            || !@rename($temporary, $this->directory.'/'.$fingerprint.'.bin')) {
            @unlink($temporary);
            return;
        }
        $entries = glob($this->directory.'/*.bin') ?: [];
        if (count($entries) <= self::MAX_ENTRIES) return;
        usort($entries, static fn(string $a, string $b): int => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));
        foreach (array_slice($entries, 0, count($entries) - self::MAX_ENTRIES) as $entry) @unlink($entry);
    }
}
