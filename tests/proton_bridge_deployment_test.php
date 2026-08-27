<?php

declare(strict_types=1);

function expectProtonBridgeDeployment(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Proton Bridge deployment test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$dockerfile = file_get_contents($root . '/docker/proton-bridge.Dockerfile');
$entrypoint = file_get_contents($root . '/docker/proton-bridge-entrypoint.sh');
$healthcheck = file_get_contents($root . '/docker/proton-bridge-healthcheck.sh');
$bridge = file_get_contents($root . '/docker-compose.proton-bridge.yaml');
$ubuntu = file_get_contents($root . '/docker-compose.ubuntu.yaml');
$traefik = file_get_contents($root . '/deploy/traefik-moed-edge.yaml');
$genericTraefik = file_get_contents($root . '/deploy/traefik-edge.yaml');
$cli = file_get_contents($root . '/scripts/proton_bridge_cli.sh');
$linuxSecrets = file_get_contents($root . '/scripts/prepare_linux_secrets.sh');
$example = file_get_contents($root . '/.env.example');

expectProtonBridgeDeployment(
    str_contains($dockerfile, 'FROM ubuntu:24.04@sha256:')
        && str_contains($dockerfile, 'PROTON_BRIDGE_VERSION=3.25.0-1')
        && str_contains($dockerfile, '6b0318f4f425ef1a19b63e2bd589bc1036d95f073cb9ac26b42c0fc63a8bc275')
        && str_contains($dockerfile, 'sha256sum --check --strict')
        && str_contains($dockerfile, 'USER proton-bridge'),
    'the Bridge image should pin and verify the official package and run without root.'
);
expectProtonBridgeDeployment(
    str_contains($entrypoint, 'PASSWORD_STORE_DIR=')
        && str_contains($entrypoint, 'pass init "$key_fingerprint"')
        && str_contains($entrypoint, '/usr/lib/protonmail/bridge/bridge "$@"')
        && str_contains($healthcheck, '/dev/tcp/127.0.0.1/1143'),
    'headless Bridge state should use a persistent pass keychain and a local IMAP health check.'
);
expectProtonBridgeDeployment(
    str_contains($bridge, 'network_mode: "service:proton-bridge"')
        && str_contains($bridge, 'networks: !reset []')
        && str_contains($bridge, 'DNR_IMAP_HOST: 127.0.0.1')
        && str_contains($bridge, 'DNR_IMAP_VERIFY_PEER: "0"')
        && str_contains($bridge, 'proton_bridge_data:/home/proton-bridge')
        && !str_contains($bridge, 'init: true')
        && !str_contains($bridge, 'ports:'),
    'Bridge should share only the worker network namespace, use its image init, and never publish mail ports.'
);
expectProtonBridgeDeployment(
    str_contains($ubuntu, 'ports: !reset []')
        && str_contains($ubuntu, 'external: true')
        && str_contains($ubuntu, 'DNR_TRAEFIK_ENABLE:-false')
        && str_contains($ubuntu, '172.29.255.2')
        && str_contains($ubuntu, '172.29.255.3')
        && str_contains($ubuntu, '172.18.0.254')
        && str_contains($ubuntu, 'traefik.http.services.${DNR_TRAEFIK_SERVICE:-dnr}.loadbalancer.server.port')
        && str_contains($ubuntu, 'traefik.http.routers.${DNR_TRAEFIK_ROUTER:-dnr}.rule'),
    'Ubuntu ingress should use a disabled-by-default private Traefik edge and exact trusted hops.'
);
expectProtonBridgeDeployment(
    str_contains($traefik, 'ipv4_address: ${DNR_EDGE_TRAEFIK_IP:-172.29.255.2}')
        && str_contains($traefik, 'ipv4_address: ${DNR_CLOUDFLARED_PROXY_IP:-172.18.0.254}')
        && str_contains($traefik, 'external: true'),
    'the host proxy overlay should make both trusted addresses stable across container restarts.'
);
expectProtonBridgeDeployment(
    str_contains($genericTraefik, 'edge:')
        && str_contains($genericTraefik, 'name: ${DNR_EDGE_NETWORK:-dnr_edge}')
        && str_contains($genericTraefik, 'ipv4_address: ${DNR_EDGE_TRAEFIK_IP:-172.29.255.2}')
        && !str_contains($genericTraefik, 'MOED'),
    'a generic host proxy overlay should match the generalized Ubuntu edge defaults.'
);
expectProtonBridgeDeployment(
    is_executable($root . '/scripts/proton_bridge_cli.sh')
        && str_contains($cli, 'updates autoupdates disable')
        && str_contains($cli, 'run --rm --no-deps proton-bridge --cli')
        && str_contains($example, 'smtp.protonmail.ch'),
    'operators should have a documented interactive setup path and direct Proton SMTP example.'
);
expectProtonBridgeDeployment(
    is_executable($root . '/scripts/prepare_linux_secrets.sh')
        && str_contains($linuxSecrets, 'chmod 700 "$secrets_directory"')
        && str_contains($linuxSecrets, 'u:0:r--,u:33:r--,m::r--')
        && !str_contains($linuxSecrets, 'o::r'),
    'native Linux secrets should grant narrow container ACLs without becoming world-readable.'
);

echo "Proton Bridge deployment tests passed.\n";
