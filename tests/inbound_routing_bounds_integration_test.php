<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Inbound routing bounds tests skipped (requires disposable database).\n"; exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/inbound_email_helpers.php';
function boundsCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$addresses = array_map(fn($n) => 'person' . $n . '@example.test', range(1, 99));
$raw = "From: sender@example.test\r\nTo: " . implode(",\r\n ", $addresses) . "\r\nSubject: Test\r\n\r\nBody";
$message = parseInboundEmail($raw);
$message['gateway_address'] = 'gateway@example.test';
$message['to_addresses'] = inboundEmailJson($message['to_addresses']);
$message['cc_addresses'] = '[]';
$statements = fn() => (int) $conn->query("SHOW SESSION STATUS LIKE 'Com_stmt_execute'")->fetch_assoc()['Value'];
$before = $statements();
$route = routeInboundEmailMessage($conn, $message);
boundsCheck($statements() - $before <= 3, 'Participant routing must use at most three address queries');
boundsCheck(count($route['participants']) === 100, 'Boundary participants were dropped');
$before = $statements();
$route = routeInboundEmailMessage($conn, $message, true);
boundsCheck(!$route['automatic'] && $statements() === $before, 'Ineligible automatic mail must not query candidates');
foreach ([str_replace('Subject: Test', 'Cc: extra@example.test', $raw),
    "From: sender@example.test\r\nX-Long: " . str_repeat('a', 32768) . "\r\n\r\nBody"] as $invalid) {
    try { parseInboundEmail($invalid); throw new LogicException('Oversized header or participant list accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$duplicate = parseInboundEmail("From: sender@example.test\r\nTo: " . implode(',', array_fill(0, 150, 'same@example.test')) . "\r\n\r\nBody");
boundsCheck(count($duplicate['to_addresses']) === 1, 'Repeated address must not increase participant fanout');
echo "Inbound routing bounds tests passed.\n";
