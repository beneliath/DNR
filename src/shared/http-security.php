<?php

declare(strict_types=1);

use Dnr\Http\ClientAddress;

function requestUsesHttps(?array $server = null) {
    $server = $server ?? $_SERVER;
    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }

    $remote_address = filter_var(
        trim((string) ($server['REMOTE_ADDR'] ?? '')),
        FILTER_VALIDATE_IP
    );
    if (!$remote_address || !isTrustedProxyAddress($remote_address)) {
        return false;
    }

    $forwarded_proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded_proto === 'https';
}

function applicationRequiresHttps() {
    return filter_var(getenv('DNR_REQUIRE_HTTPS') ?: '0', FILTER_VALIDATE_BOOL);
}

function contentSecurityPolicyNonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

function sendApplicationSecurityHeaders() {
    if (headers_sent()) {
        return;
    }

    $nonce = contentSecurityPolicyNonce();
    $page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $map_page = in_array($page, ['map.php', 'map_pin.php'], true);
    $inline_styles = $map_page || $page === 'reimbursement_submit.php';
    $style_source = $inline_styles ? "'self' 'unsafe-inline'" : "'self'";
    // Audience email previews use nonce-authorized CSS in script-disabled preview frames.
    if (in_array($page, ['audience.php', 'audience_campaign_public.php'], true)) $style_source .= " 'nonce-{$nonce}'";
    $style_attribute_source = $inline_styles ? "'unsafe-inline'" : "'none'";
    $map_tile_source = deploymentConfig()->tileCspSource();
    $font_source = in_array($page, ['audience.php', 'audience_campaign_public.php'], true) ? "'self' data:" : "'self'";
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; script-src-attr 'none'; style-src {$style_source}; style-src-attr {$style_attribute_source}; img-src 'self' data: blob: {$map_tile_source}; font-src {$font_source}; connect-src 'self' {$map_tile_source}; worker-src 'self' blob:; child-src blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

function requestIpAddress($server = null) {
    $server = is_array($server) ? $server : $_SERVER;
    return ClientAddress::resolve(
        $server,
        trustedProxyNetworks(),
        trustedCloudflareProxyNetworks()
    );
}

function trustedProxyNetworks() {
    $configured_proxies = getenv('DNR_TRUSTED_PROXY_IPS');
    return is_string($configured_proxies) && trim($configured_proxies) !== ''
        ? $configured_proxies
        : '192.168.65.1';
}

function trustedCloudflareProxyNetworks() {
    $configured_proxies = getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
    return is_string($configured_proxies) && trim($configured_proxies) !== ''
        ? $configured_proxies
        : '172.18.0.0/24';
}

function isTrustedProxyAddress($ip_address) {
    return isAddressInTrustedNetworks($ip_address, trustedProxyNetworks());
}

function isTrustedCloudflareProxyAddress($ip_address) {
    return isAddressInTrustedNetworks($ip_address, trustedCloudflareProxyNetworks());
}

function dockerGatewayAddress($route_contents = null) {
    if ($route_contents !== null && !is_string($route_contents)) {
        return null;
    }

    return ClientAddress::dockerGatewayAddress($route_contents);
}

function isAddressInTrustedNetworks($ip_address, $configured_networks, $docker_gateway = null) {
    return ClientAddress::isAddressInTrustedNetworks(
        (string) $ip_address,
        (string) $configured_networks,
        is_string($docker_gateway) ? $docker_gateway : null
    );
}

function ipAddressMatchesNetwork($ip_address, $trusted_network) {
    return ClientAddress::isAddressInTrustedNetworks(
        (string) $ip_address,
        (string) $trusted_network
    );
}
