<?php

declare(strict_types=1);

namespace Dnr\Http;

final class ClientAddress
{
    /**
     * Resolve the original client by walking the proxy chain from the nearest
     * hop outward. Values to the left of the first untrusted address are
     * client-controlled and must not influence audit or throttling decisions.
     *
     * @param array<string, mixed> $server
     */
    public static function resolve(
        array $server,
        string $trustedProxies,
        string $trustedCloudflareProxies,
        ?string $dockerGateway = null
    ): ?string {
        $remoteAddress = self::validatedAddress($server['REMOTE_ADDR'] ?? null);
        if ($remoteAddress === null) {
            return null;
        }
        if (!self::isAddressInTrustedNetworks($remoteAddress, $trustedProxies, $dockerGateway)) {
            return $remoteAddress;
        }

        $forwardedAddresses = array_values(array_filter(array_map(
            static fn (string $value): ?string => self::validatedAddress(trim($value)),
            explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))
        )));
        foreach (array_reverse($forwardedAddresses) as $forwardedAddress) {
            if (self::isAddressInTrustedNetworks(
                $forwardedAddress,
                $trustedCloudflareProxies,
                $dockerGateway
            )) {
                $cloudflareAddress = self::validatedAddress($server['HTTP_CF_CONNECTING_IP'] ?? null);
                $cloudflareRay = trim((string) ($server['HTTP_CF_RAY'] ?? ''));
                if ($cloudflareAddress !== null
                    && preg_match('/^[0-9a-f]{16,32}(?:-[a-z]{3})?$/i', $cloudflareRay) === 1
                ) {
                    return $cloudflareAddress;
                }
                continue;
            }
            if (self::isAddressInTrustedNetworks($forwardedAddress, $trustedProxies, $dockerGateway)) {
                continue;
            }

            return $forwardedAddress;
        }

        return $remoteAddress;
    }

    public static function isAddressInTrustedNetworks(
        string $ipAddress,
        string $configuredNetworks,
        ?string $dockerGateway = null
    ): bool {
        if (self::validatedAddress($ipAddress) === null) {
            return false;
        }

        $trustedNetworks = array_filter(array_map('trim', explode(',', $configuredNetworks)));
        foreach ($trustedNetworks as $trustedNetwork) {
            if ($trustedNetwork === 'docker-gateway') {
                $resolvedGateway = $dockerGateway ?? self::dockerGatewayAddress();
                if ($resolvedGateway !== null && $ipAddress === $resolvedGateway) {
                    return true;
                }
                continue;
            }
            if (self::matchesNetwork($ipAddress, $trustedNetwork)) {
                return true;
            }
        }

        return false;
    }

    public static function dockerGatewayAddress(?string $routeContents = null): ?string
    {
        if ($routeContents === null) {
            $contents = @file_get_contents('/proc/net/route');
            $routeContents = is_string($contents) ? $contents : null;
        }
        if ($routeContents === null || trim($routeContents) === '') {
            return null;
        }

        foreach (preg_split('/\R/', trim($routeContents)) ?: [] as $routeLine) {
            $fields = preg_split('/\s+/', trim($routeLine)) ?: [];
            if (count($fields) < 4
                || $fields[1] !== '00000000'
                || preg_match('/\A[0-9A-Fa-f]{8}\z/', $fields[2]) !== 1
                || preg_match('/\A[0-9A-Fa-f]{4}\z/', $fields[3]) !== 1
                || (((int) hexdec($fields[3])) & 0x2) === 0
            ) {
                continue;
            }

            $packedGateway = @hex2bin($fields[2]);
            if ($packedGateway === false || strlen($packedGateway) !== 4) {
                continue;
            }
            $gatewayAddress = @inet_ntop(strrev($packedGateway));
            if (is_string($gatewayAddress)
                && filter_var($gatewayAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ) {
                return $gatewayAddress;
            }
        }

        return null;
    }

    private static function matchesNetwork(string $ipAddress, string $trustedNetwork): bool
    {
        if (!str_contains($trustedNetwork, '/')) {
            return $ipAddress === $trustedNetwork;
        }

        [$networkAddress, $prefixLength] = array_map('trim', explode('/', $trustedNetwork, 2));
        if (!ctype_digit($prefixLength)) {
            return false;
        }

        $packedAddress = @inet_pton($ipAddress);
        $packedNetwork = @inet_pton($networkAddress);
        if ($packedAddress === false
            || $packedNetwork === false
            || strlen($packedAddress) !== strlen($packedNetwork)
        ) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $maximumPrefixLength = strlen($packedAddress) * 8;
        if ($prefixLength < 0 || $prefixLength > $maximumPrefixLength) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if (substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    private static function validatedAddress(mixed $value): ?string
    {
        $address = trim((string) $value);
        return filter_var($address, FILTER_VALIDATE_IP) ? substr($address, 0, 45) : null;
    }
}
