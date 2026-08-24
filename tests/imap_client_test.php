<?php

declare(strict_types=1);

use Dnr\Infrastructure\ImapClient;

require_once __DIR__ . '/../vendor/autoload.php';

function expectImapClient(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "IMAP client test failed: {$message}\n");
        exit(1);
    }
}

if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    echo "IMAP client protocol test skipped (pcntl is unavailable).\n";
    exit(0);
}

$errorNumber = 0;
$errorMessage = '';
$server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($server)) {
    echo "IMAP client protocol test skipped (loopback sockets are unavailable).\n";
    exit(0);
}
$serverAddress = stream_socket_get_name($server, false);
expectImapClient(is_string($serverAddress), 'the fixture server should report its address.');
$portSeparator = strrpos($serverAddress, ':');
expectImapClient($portSeparator !== false, 'the fixture address should contain a port.');
$port = (int) substr($serverAddress, (int) $portSeparator + 1);
expectImapClient($port > 0, 'the fixture port should be valid.');

$rawMessage = implode("\r\n", [
    'From: Contact <contact@example.org>',
    'To: Staff <staff@beneliath.com>',
    'Cc: MOED <moed@beneliath.com>',
    'Subject: Fixture message',
    'Message-ID: <imap-fixture@example.org>',
    '',
    'Fixture body.',
]);

$childPid = pcntl_fork();
expectImapClient($childPid >= 0, 'the fixture server process should start.');
if ($childPid === 0) {
    $connection = @stream_socket_accept($server, 5);
    if (!is_resource($connection)) {
        exit(2);
    }
    stream_set_timeout($connection, 5);
    fwrite($connection, "* OK DNR test IMAP ready\r\n");
    $expectedCommands = ['LOGIN', 'SELECT', 'UID SEARCH', 'UID FETCH', 'UID STORE', 'LOGOUT'];
    foreach ($expectedCommands as $expectedCommand) {
        $line = fgets($connection);
        if (!is_string($line)
            || preg_match('/\A(DNR\d+)\s+(.+)\r?\n\z/', $line, $matches) !== 1
            || !str_starts_with(strtoupper($matches[2]), $expectedCommand)
        ) {
            fclose($connection);
            exit(3);
        }
        $tag = $matches[1];
        if ($expectedCommand === 'SELECT') {
            fwrite($connection, "* 1 EXISTS\r\n* OK [UIDVALIDITY 918273] UIDs valid\r\n");
        } elseif ($expectedCommand === 'UID SEARCH') {
            fwrite($connection, "* SEARCH 7 9\r\n");
        } elseif ($expectedCommand === 'UID FETCH') {
            $length = strlen($rawMessage);
            fwrite($connection, "* 1 FETCH (UID 7 RFC822.SIZE {$length} BODY[] {{$length}}\r\n");
            fwrite($connection, $rawMessage);
            fwrite($connection, ")\r\n");
        } elseif ($expectedCommand === 'LOGOUT') {
            fwrite($connection, "* BYE Logging out\r\n");
        }
        fwrite($connection, "{$tag} OK {$expectedCommand} completed\r\n");
    }
    fclose($connection);
    fclose($server);
    exit(0);
}

fclose($server);
$client = new ImapClient('127.0.0.1', $port, 'none', true, 5, 1048576);
try {
    $client->connect('fixture-user', 'fixture-password');
    expectImapClient($client->uidValidity() === 918273, 'UIDVALIDITY should be parsed.');
    expectImapClient($client->unseenUids() === [7, 9], 'unseen UIDs should be parsed and sorted.');
    expectImapClient(
        hash_equals($rawMessage, $client->fetchRawMessage(7)),
        'an IMAP literal should be returned without changing its bytes.'
    );
    $client->markSeen(7);
    $client->close();
} finally {
    $client->close();
}

$childStatus = 0;
pcntl_waitpid($childPid, $childStatus);
expectImapClient(
    pcntl_wifexited($childStatus) && pcntl_wexitstatus($childStatus) === 0,
    'the fixture should receive the expected safe IMAP command sequence.'
);

echo "IMAP client protocol tests passed.\n";
