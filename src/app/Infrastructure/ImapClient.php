<?php

declare(strict_types=1);

namespace Dnr\Infrastructure;

use RuntimeException;

final class ImapClient
{
    /** @var resource|null */
    private $stream = null;
    private int $tagCounter = 0;
    private int $uidValidity = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security = 'starttls',
        private readonly bool $verifyPeer = true,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maximumMessageBytes = 10485760
    ) {
        if ($host === '' || preg_match('/\A[A-Za-z0-9._:-]+\z/', $host) !== 1) {
            throw new RuntimeException('The IMAP host is invalid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The IMAP port is invalid.');
        }
        if (!in_array($security, ['none', 'starttls', 'tls'], true)) {
            throw new RuntimeException('The IMAP security mode is invalid.');
        }
    }

    public function connect(string $username, string $password, string $mailbox = 'INBOX'): void
    {
        if ($this->stream !== null) {
            return;
        }
        if ($username === '' || $password === '') {
            throw new RuntimeException('The IMAP username and password are required.');
        }
        $sslOptions = [
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'peer_name' => $this->host,
        ];
        $context = stream_context_create(['ssl' => $sslOptions]);
        $remote = ($this->security === 'tls' ? 'tls://' : 'tcp://')
            . $this->host . ':' . $this->port;
        $stream = @stream_socket_client(
            $remote,
            $errorNumber,
            $errorMessage,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to connect to the configured IMAP service.');
        }
        stream_set_timeout($stream, $this->timeoutSeconds);
        $this->stream = $stream;
        try {
            $greeting = $this->readLine();
            if (preg_match('/\A\* (OK|PREAUTH)\b/i', $greeting) !== 1) {
                throw new RuntimeException('The IMAP service did not provide a valid greeting.');
            }
            if ($this->security === 'starttls') {
                $this->command('STARTTLS');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (!stream_socket_enable_crypto($this->stream, true, $cryptoMethod)) {
                    throw new RuntimeException('Unable to establish IMAP transport encryption.');
                }
            }
            if (stripos($greeting, '* PREAUTH') !== 0) {
                $this->command(
                    'LOGIN ' . $this->quote($username) . ' ' . $this->quote($password)
                );
            }
            $response = $this->command('SELECT ' . $this->quote($mailbox));
            foreach ($response['lines'] as $line) {
                if (preg_match('/\[UIDVALIDITY\s+(\d+)\]/i', $line, $match) === 1) {
                    $this->uidValidity = (int) $match[1];
                    break;
                }
            }
            if ($this->uidValidity < 1) {
                throw new RuntimeException('The IMAP mailbox did not report UIDVALIDITY.');
            }
        } catch (\Throwable $exception) {
            $this->close();
            throw $exception;
        }
    }

    public function uidValidity(): int
    {
        if ($this->uidValidity < 1) {
            throw new RuntimeException('The IMAP mailbox is not selected.');
        }
        return $this->uidValidity;
    }

    /** @return list<int> */
    public function unseenUids(int $limit = 25): array
    {
        $limit = max(1, min(250, $limit));
        $response = $this->command('UID SEARCH UNSEEN');
        $uids = [];
        foreach ($response['lines'] as $line) {
            if (preg_match('/\A\* SEARCH(?:\s+(.*))?\r?\n?\z/i', $line, $match) !== 1) {
                continue;
            }
            $values = preg_split('/\s+/', trim((string) ($match[1] ?? ''))) ?: [];
            foreach ($values as $value) {
                if (ctype_digit($value) && (int) $value > 0) {
                    $uids[(int) $value] = true;
                }
            }
        }
        $uids = array_keys($uids);
        sort($uids, SORT_NUMERIC);
        return array_slice($uids, 0, $limit);
    }

    public function fetchRawMessage(int $uid): string
    {
        if ($uid < 1) {
            throw new RuntimeException('The IMAP UID is invalid.');
        }
        $response = $this->command(
            'UID FETCH ' . $uid . ' (UID RFC822.SIZE BODY.PEEK[])',
            true
        );
        if (count($response['literals']) !== 1) {
            throw new RuntimeException('The IMAP service returned an unexpected message payload.');
        }
        return $response['literals'][0];
    }

    public function markSeen(int $uid): void
    {
        if ($uid < 1) {
            throw new RuntimeException('The IMAP UID is invalid.');
        }
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)');
    }

    public function close(): void
    {
        if (!is_resource($this->stream)) {
            $this->stream = null;
            $this->uidValidity = 0;
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (\Throwable $exception) {
            // The connection may already be unavailable; closing it is enough.
        }
        fclose($this->stream);
        $this->stream = null;
        $this->uidValidity = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new RuntimeException('An IMAP command value contains invalid characters.');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @return array{lines: list<string>, literals: list<string>}
     */
    private function command(string $command, bool $allowLiteral = false): array
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('The IMAP connection is not open.');
        }
        if (preg_match('/[\r\n\x00]/', $command) === 1) {
            throw new RuntimeException('An IMAP command contains invalid characters.');
        }
        $tag = 'DNR' . str_pad((string) (++$this->tagCounter), 5, '0', STR_PAD_LEFT);
        $this->writeAll($tag . ' ' . $command . "\r\n");

        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            $lines[] = $line;
            if (preg_match('/\{(\d+)\}\r?\n\z/', $line, $literalMatch) === 1) {
                if (!$allowLiteral) {
                    throw new RuntimeException('The IMAP service returned an unexpected literal.');
                }
                $literalLength = (int) $literalMatch[1];
                if ($literalLength < 0 || $literalLength > $this->maximumMessageBytes) {
                    throw new RuntimeException('The IMAP message exceeds the configured size limit.');
                }
                $literals[] = $this->readExact($literalLength);
            }
            if (stripos($line, $tag . ' ') !== 0) {
                continue;
            }
            if (preg_match('/\A' . preg_quote($tag, '/') . '\s+OK\b/i', $line) !== 1) {
                throw new RuntimeException('The IMAP service rejected a mailbox operation.');
            }
            return ['lines' => $lines, 'literals' => $literals];
        }
    }

    private function readLine(): string
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('The IMAP connection is not open.');
        }
        $line = fgets($this->stream, 1048577);
        if ($line === false) {
            $metadata = stream_get_meta_data($this->stream);
            throw new RuntimeException(!empty($metadata['timed_out'])
                ? 'The IMAP service timed out.'
                : 'The IMAP service closed the connection unexpectedly.');
        }
        if (strlen($line) > 1048576) {
            throw new RuntimeException('The IMAP service returned an oversized response line.');
        }
        return $line;
    }

    private function readExact(int $length): string
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('The IMAP connection is not open.');
        }
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, min(65536, $length - strlen($buffer)));
            if ($chunk === false || $chunk === '') {
                $metadata = stream_get_meta_data($this->stream);
                throw new RuntimeException(!empty($metadata['timed_out'])
                    ? 'The IMAP message download timed out.'
                    : 'The IMAP message download ended unexpectedly.');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private function writeAll(string $data): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('The IMAP connection is not open.');
        }
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                $metadata = stream_get_meta_data($this->stream);
                throw new RuntimeException(!empty($metadata['timed_out'])
                    ? 'The IMAP service timed out while receiving a command.'
                    : 'Unable to write to the IMAP service.');
            }
            $offset += $written;
        }
    }
}
