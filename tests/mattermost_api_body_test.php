<?php

declare(strict_types=1);

function expectMattermostBody(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Mattermost API body test failed: ' . $message);
}

/** Exercise the production reader over HTTP without application/database fixtures. */
function requestMattermostBody(string $address, string $body, bool $chunked): array
{
    $socket = stream_socket_client('tcp://' . $address, $code, $error, 5);
    if (!$socket) throw new RuntimeException('Unable to connect to the API body fixture.');
    stream_set_timeout($socket, 5);
    try {
        $request = "POST / HTTP/1.1\r\nHost: {$address}\r\nContent-Type: application/json\r\nConnection: close\r\n";
        if ($chunked) {
            $request .= "Transfer-Encoding: chunked\r\n\r\n";
            foreach (str_split($body, 4096) as $chunk) {
                $request .= dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
            }
            $request .= "0\r\n\r\n";
        } else {
            $request .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
        }
        for ($offset = 0; $offset < strlen($request);) {
            $written = fwrite($socket, substr($request, $offset));
            if (!$written) throw new RuntimeException('Unable to send the API body fixture request.');
            $offset += $written;
        }
        $response = stream_get_contents($socket);
        expectMattermostBody(is_string($response) && !stream_get_meta_data($socket)['timed_out'], 'HTTP response must complete');
        expectMattermostBody(preg_match('/\AHTTP\/1\.[01] ([0-9]{3})/', $response, $matches) === 1, 'HTTP status must be present');
        $separator = strpos($response, "\r\n\r\n");
        expectMattermostBody($separator !== false, 'HTTP headers must terminate');
        return [(int) $matches[1], json_decode(substr($response, $separator + 4), true, 32, JSON_THROW_ON_ERROR)];
    } finally { fclose($socket); }
}

$probe = @stream_socket_server('tcp://127.0.0.1:0', $code, $error);
if (!$probe || !function_exists('proc_open')) {
    if ($probe) fclose($probe);
    echo "Mattermost API body test skipped (loopback sockets or proc_open unavailable).\n";
    exit(0);
}
$address = stream_socket_get_name($probe, false);
fclose($probe);
$directory = sys_get_temp_dir() . '/dnr-api-body-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$server = null;
try {
    // Load the actual response/body functions; exclude bootstrap and route dispatch.
    $source = file_get_contents(__DIR__ . '/../src/api/v1/mattermost.php');
    $start = strpos($source, 'function mattermostApiRespond(');
    $end = strpos($source, 'function mattermostApiHeader(');
    expectMattermostBody($start !== false && $end !== false && $end > $start, 'production reader must be located');
    $fixture = "<?php\nfunction applicationRequestId(): string { return 'body-fixture'; }\n"
        . substr($source, $start, $end - $start)
        . "\necho json_encode(['bytes' => strlen(json_encode(mattermostApiBody(), JSON_THROW_ON_ERROR))]);\n";
    file_put_contents($directory . '/index.php', $fixture);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $directory],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']], $pipes);
    expectMattermostBody(is_resource($server), 'fixture server must start');
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $code, $error, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(20000);
    }
    expectMattermostBody($ready, 'fixture server must become ready');
    foreach ([false, true] as $chunked) {
        foreach ([32767, 32768, 32769, 524288] as $size) {
            $body = json_encode(['text' => str_repeat('x', $size - 11)], JSON_THROW_ON_ERROR);
            expectMattermostBody(strlen($body) === $size, 'fixture must have the exact requested byte count');
            [$status, $payload] = requestMattermostBody($address, $body, $chunked);
            $label = ($chunked ? 'chunked' : 'Content-Length') . ' body of ' . $size . ' bytes';
            expectMattermostBody($status === ($size <= 32768 ? 200 : 413), $label . ' must respect the 32 KiB limit');
            expectMattermostBody($size <= 32768 ? $payload['bytes'] === $size : ($payload['code'] ?? '') === 'body_too_large',
                $label . ' must preserve accepted JSON or report the size error');
        }
        foreach (['' => 200, '{}' => 200, '{' => 400, '"scalar"' => 400] as $body => $expectedStatus) {
            [$status, $payload] = requestMattermostBody($address, $body, $chunked);
            expectMattermostBody($status === $expectedStatus, 'empty, object and invalid JSON behavior must remain unchanged');
            if ($status === 400) expectMattermostBody(($payload['code'] ?? '') === 'invalid_json', 'invalid JSON must retain its error code');
        }
    }
    echo "Mattermost API body tests passed (normal and chunked boundaries, oversized bodies and JSON errors).\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
    rmdir($directory);
}
