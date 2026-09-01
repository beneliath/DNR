<?php

declare(strict_types=1);

namespace Dnr\Security;

final class InboundRoutingKey
{
    private static ?string $key = null;

    public static function bytes(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $encoded = '';
        $path = getenv('DNR_INBOUND_ROUTING_KEY_FILE');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $contents = file_get_contents($path);
            $encoded = is_string($contents) ? trim($contents) : '';
        }
        if ($encoded === '') {
            $environmentValue = getenv('DNR_INBOUND_ROUTING_KEY');
            $encoded = is_string($environmentValue) ? trim($environmentValue) : '';
        }

        $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new \RuntimeException(
                'The inbound routing key must be a base64-encoded 32-byte key.'
            );
        }
        self::$key = $decoded;
        return self::$key;
    }
}
