<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/email_helpers.php';

function expectSmtpSessionProtocol(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SMTP session protocol test failed: {$message}\n");
        exit(1);
    }
}

/** @param resource $connection */
function smtpFakeRelayWrite($connection, string $response): void
{
    if (fwrite($connection, $response . "\r\n") === false) {
        throw new RuntimeException('The fake relay could not write a response.');
    }
}

/** @param resource $connection */
function smtpFakeRelayRead($connection): string
{
    $line = fgets($connection, 8192);
    if ($line === false) {
        throw new RuntimeException('The fake relay connection closed unexpectedly.');
    }
    return rtrim($line, "\r\n");
}

/** @param resource $connection */
function smtpFakeRelayExpect($connection, string $prefix): string
{
    $line = smtpFakeRelayRead($connection);
    if (!str_starts_with($line, $prefix)) {
        throw new RuntimeException("Expected {$prefix}, received {$line}.");
    }
    return $line;
}

/** @param resource $connection */
function smtpFakeRelayHandshake($connection): void
{
    stream_set_timeout($connection, 5);
    smtpFakeRelayWrite($connection, '220 fake-relay ESMTP');
    smtpFakeRelayExpect($connection, 'EHLO ');
    smtpFakeRelayWrite($connection, '250 fake-relay');
}

/** @param resource $connection */
function smtpFakeRelayEnvelope($connection, bool $acknowledge): string
{
    smtpFakeRelayExpect($connection, 'MAIL FROM:<sender@example.test>');
    smtpFakeRelayWrite($connection, '250 sender accepted');
    smtpFakeRelayExpect($connection, 'RCPT TO:<recipient@example.test>');
    smtpFakeRelayWrite($connection, '250 recipient accepted');
    smtpFakeRelayExpect($connection, 'DATA');
    smtpFakeRelayWrite($connection, '354 send message');
    $messageLines = [];
    while (($line = smtpFakeRelayRead($connection)) !== '.') {
        $messageLines[] = $line;
    }
    if ($acknowledge) {
        smtpFakeRelayWrite($connection, '250 queued');
    }
    return implode("\r\n", $messageLines);
}

/**
 * @param resource $server
 * @return array{connections: int, messages: int, quits: int,
 *   message_data: ?string, error: ?string}
 */
function smtpFakeRelayRun($server, string $scenario): array
{
    $result = [
        'connections' => 0,
        'messages' => 0,
        'quits' => 0,
        'message_data' => null,
        'error' => null,
    ];
    try {
        $first = stream_socket_accept($server, 5);
        if (!is_resource($first)) {
            throw new RuntimeException('The fake relay did not receive its first connection.');
        }
        $result['connections']++;
        smtpFakeRelayHandshake($first);

        if ($scenario === 'reuse') {
            smtpFakeRelayEnvelope($first, true);
            $result['messages']++;
            smtpFakeRelayEnvelope($first, true);
            $result['messages']++;
            smtpFakeRelayExpect($first, 'QUIT');
            $result['quits']++;
            smtpFakeRelayWrite($first, '221 goodbye');
            fclose($first);
            return $result;
        }

        if ($scenario === 'html') {
            $result['message_data'] = smtpFakeRelayEnvelope($first, true);
            $result['messages']++;
            smtpFakeRelayExpect($first, 'QUIT');
            $result['quits']++;
            smtpFakeRelayWrite($first, '221 goodbye');
            fclose($first);
            return $result;
        }

        if ($scenario === 'pre-data-reconnect') {
            smtpFakeRelayEnvelope($first, true);
            $result['messages']++;
            // Leave an explicit shutdown response behind so the reused client
            // discovers a known pre-DATA failure on its next envelope.
            smtpFakeRelayWrite($first, '421 idle connection closed');
            fclose($first);

            $second = stream_socket_accept($server, 5);
            if (!is_resource($second)) {
                throw new RuntimeException('The fake relay did not receive the reconnect.');
            }
            $result['connections']++;
            smtpFakeRelayHandshake($second);
            smtpFakeRelayEnvelope($second, true);
            $result['messages']++;
            smtpFakeRelayExpect($second, 'QUIT');
            $result['quits']++;
            smtpFakeRelayWrite($second, '221 goodbye');
            fclose($second);
            return $result;
        }

        if ($scenario === 'post-data-failure') {
            smtpFakeRelayEnvelope($first, false);
            $result['messages']++;
            fclose($first);
            $unexpected = @stream_socket_accept($server, 2);
            if (is_resource($unexpected)) {
                $result['connections']++;
                fclose($unexpected);
            }
            return $result;
        }

        if ($scenario === 'post-data-permanent') {
            smtpFakeRelayEnvelope($first, false);
            $result['messages']++;
            smtpFakeRelayWrite($first, '554 message content rejected');
            fclose($first);
            $unexpected = @stream_socket_accept($server, 2);
            if (is_resource($unexpected)) {
                $result['connections']++;
                fclose($unexpected);
            }
            return $result;
        }

        if (in_array($scenario, ['temporary-rejection', 'permanent-rejection'], true)) {
            smtpFakeRelayExpect($first, 'MAIL FROM:<sender@example.test>');
            smtpFakeRelayWrite($first, '250 sender accepted');
            smtpFakeRelayExpect($first, 'RCPT TO:<recipient@example.test>');
            smtpFakeRelayWrite(
                $first,
                $scenario === 'temporary-rejection'
                    ? '451 temporary relay failure'
                    : '550 no such recipient'
            );
            fclose($first);
            $unexpected = @stream_socket_accept($server, 2);
            if (is_resource($unexpected)) {
                $result['connections']++;
                fclose($unexpected);
            }
            return $result;
        }

        throw new RuntimeException('Unknown fake SMTP relay scenario.');
    } catch (Throwable $exception) {
        $result['error'] = $exception->getMessage();
        return $result;
    }
}

/**
 * @return array{
 *   connections: int,
 *   messages: int,
 *   quits: int,
 *   message_data: ?string,
 *   error: ?string,
 *   client_error: ?string,
 *   client_type: ?string,
 *   child_status: int
 * }|null
 */
function runSmtpSessionScenario(string $scenario): ?array
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($server)) {
        return null;
    }
    $address = (string) stream_socket_get_name($server, false);
    $separator = strrpos($address, ':');
    if ($separator === false) {
        fclose($server);
        throw new RuntimeException('Unable to determine the fake relay port.');
    }
    $port = (int) substr($address, $separator + 1);
    $resultPath = tempnam(sys_get_temp_dir(), 'dnr-smtp-result-');
    if ($resultPath === false) {
        fclose($server);
        throw new RuntimeException('Unable to create a fake relay result file.');
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fclose($server);
        @unlink($resultPath);
        throw new RuntimeException('Unable to fork the fake SMTP relay.');
    }
    if ($pid === 0) {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            exit(124);
        });
        pcntl_alarm(15);
        $result = smtpFakeRelayRun($server, $scenario);
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        fclose($server);
        exit($result['error'] === null ? 0 : 1);
    }

    fclose($server);
    putenv('DNR_MAIL_TRANSPORT=smtp');
    putenv('DNR_MAIL_FROM=sender@example.test');
    putenv('DNR_MAIL_FROM_NAME=DNR SMTP Test');
    putenv('DNR_SMTP_HOST=127.0.0.1');
    putenv('DNR_SMTP_PORT=' . $port);
    putenv('DNR_SMTP_ENCRYPTION=none');
    putenv('DNR_SMTP_USERNAME');
    putenv('DNR_SMTP_PASSWORD');
    putenv('DNR_SMTP_PASSWORD_FILE');

    $session = null;
    $clientError = null;
    $clientType = null;
    try {
        deliverApplicationEmailWithSession(
            $session,
            'recipient@example.test',
            'First message',
            'First message body',
            '',
            $scenario === 'html'
                ? '<!doctype html><html><body><p>Rich message body.</p></body></html>'
                : null
        );
        if (in_array($scenario, ['reuse', 'pre-data-reconnect'], true)) {
            deliverApplicationEmailWithSession(
                $session,
                'recipient@example.test',
                'Second message',
                'Second message body'
            );
            if ($session instanceof SmtpSession) {
                $session->close();
                $session->close();
            }
        }
    } catch (Throwable $exception) {
        $clientType = get_class($exception);
        $clientError = get_class($exception) . ': ' . $exception->getMessage();
    } finally {
        if ($session instanceof SmtpSession) {
            $session->close();
        }
        $session = null;
    }

    pcntl_waitpid($pid, $status);
    $encodedResult = file_get_contents($resultPath);
    @unlink($resultPath);
    $result = is_string($encodedResult) && $encodedResult !== ''
        ? json_decode($encodedResult, true, 512, JSON_THROW_ON_ERROR)
        : ['connections' => 0, 'messages' => 0, 'quits' => 0, 'error' => 'No relay result.'];
    $result['client_error'] = $clientError;
    $result['client_type'] = $clientType;
    $result['child_status'] = pcntl_wexitstatus($status);
    return $result;
}

if (!function_exists('pcntl_fork')) {
    echo "SMTP session protocol test skipped (pcntl is unavailable).\n";
    exit(0);
}

$reuse = runSmtpSessionScenario('reuse');
if ($reuse === null) {
    echo "SMTP session protocol test skipped (loopback sockets are unavailable).\n";
    exit(0);
}
expectSmtpSessionProtocol(
    $reuse['child_status'] === 0
        && $reuse['error'] === null
        && $reuse['client_error'] === null
        && $reuse['connections'] === 1
        && $reuse['messages'] === 2
        && $reuse['quits'] === 1,
    'a healthy session should carry two envelopes and emit only one QUIT even when close is repeated.'
);

$html = runSmtpSessionScenario('html');
$htmlMessage = is_array($html) && is_string($html['message_data'] ?? null)
    ? $html['message_data']
    : '';
expectSmtpSessionProtocol(
    $html !== null
        && $html['child_status'] === 0
        && $html['error'] === null
        && $html['client_error'] === null
        && $html['connections'] === 1
        && $html['messages'] === 1
        && $html['quits'] === 1
        && preg_match(
            '/^Content-Type: multipart\/alternative; boundary="[^"]+"\r?$/m',
            $htmlMessage
        ) === 1
        && str_contains($htmlMessage, 'Content-Type: text/plain; charset=UTF-8')
        && str_contains($htmlMessage, 'Content-Type: text/html; charset=UTF-8')
        && str_contains(
            $htmlMessage,
            base64_encode(smtpNormalizeLineEndings('First message body'))
        )
        && str_contains(
            $htmlMessage,
            rtrim(chunk_split(base64_encode(smtpNormalizeLineEndings(
                '<!doctype html><html><body><p>Rich message body.</p></body></html>'
            )), 76, "\r\n"), "\r\n")
        ),
    'an HTML delivery should reach the relay as multipart/alternative with both encoded bodies.'
);

$reconnect = runSmtpSessionScenario('pre-data-reconnect');
expectSmtpSessionProtocol(
    $reconnect !== null
        && $reconnect['child_status'] === 0
        && $reconnect['error'] === null
        && $reconnect['client_error'] === null
        && $reconnect['connections'] === 2
        && $reconnect['messages'] === 2
        && $reconnect['quits'] === 1,
    'a dead reused session should reconnect and replay once when no DATA body was transferred.'
);

$postData = runSmtpSessionScenario('post-data-failure');
expectSmtpSessionProtocol(
    $postData !== null
        && $postData['child_status'] === 0
        && $postData['error'] === null
        && $postData['client_error'] !== null
        && $postData['connections'] === 1
        && $postData['messages'] === 1
        && $postData['quits'] === 0,
    'an ambiguous failure after DATA should surface without reconnecting or replaying the message.'
);

$postDataPermanent = runSmtpSessionScenario('post-data-permanent');
expectSmtpSessionProtocol(
    $postDataPermanent !== null
        && $postDataPermanent['child_status'] === 0
        && $postDataPermanent['error'] === null
        && $postDataPermanent['client_type'] === DomainException::class
        && $postDataPermanent['connections'] === 1
        && $postDataPermanent['messages'] === 1,
    'a permanent 554 response after DATA should fail terminally without replaying the message.'
);

$temporary = runSmtpSessionScenario('temporary-rejection');
expectSmtpSessionProtocol(
    $temporary !== null
        && $temporary['child_status'] === 0
        && $temporary['error'] === null
        && $temporary['client_type'] === SmtpResponseException::class
        && $temporary['connections'] === 1
        && $temporary['messages'] === 0,
    'a 451 response should use ordinary queue backoff without an immediate reconnect.'
);

$permanent = runSmtpSessionScenario('permanent-rejection');
expectSmtpSessionProtocol(
    $permanent !== null
        && $permanent['child_status'] === 0
        && $permanent['error'] === null
        && $permanent['client_type'] === DomainException::class
        && $permanent['connections'] === 1
        && $permanent['messages'] === 0,
    'a 550 response should be classified as permanent without reconnecting.'
);

foreach ([
    'DNR_MAIL_TRANSPORT',
    'DNR_MAIL_FROM',
    'DNR_MAIL_FROM_NAME',
    'DNR_SMTP_HOST',
    'DNR_SMTP_PORT',
    'DNR_SMTP_ENCRYPTION',
] as $name) {
    putenv($name);
}

echo "SMTP session protocol tests passed.\n";
