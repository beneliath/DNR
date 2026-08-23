<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Dnr\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function applicationTimezoneName(): string
{
    $timezone_name = trim((string) (getenv('DNR_TIMEZONE') ?: 'America/Chicago'));
    try {
        new DateTimeZone($timezone_name);
        return $timezone_name;
    } catch (Throwable $exception) {
        return 'UTC';
    }
}

function applicationTimezone(): DateTimeZone
{
    return new DateTimeZone(applicationTimezoneName());
}

function applicationBusinessDate(?DateTimeImmutable $instant = null): string
{
    $instant ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $instant->setTimezone(applicationTimezone())->format('Y-m-d');
}

function applicationBusinessDateOffset(
    int $days,
    ?DateTimeImmutable $instant = null
): string {
    $business_date = new DateTimeImmutable(
        applicationBusinessDate($instant) . ' 12:00:00',
        applicationTimezone()
    );
    return $business_date->modify(sprintf('%+d days', $days))->format('Y-m-d');
}

function applicationTimestampLabel(mixed $timestamp, string $format = 'Y-m-d H:i'): string
{
    try {
        return (new DateTimeImmutable((string) $timestamp, new DateTimeZone('UTC')))
            ->setTimezone(applicationTimezone())
            ->format($format);
    } catch (Throwable $exception) {
        return (string) $timestamp;
    }
}

/** @param list<string>|null $candidate_paths */
function applicationVersion(?array $candidate_paths = null): string
{
    $candidate_paths ??= [
        dirname(__DIR__) . '/VERSION',
        '/opt/dnr/VERSION',
    ];
    foreach ($candidate_paths as $candidate_path) {
        if (!is_file($candidate_path)) {
            continue;
        }
        $version = trim((string) file_get_contents($candidate_path));
        if (preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) === 1) {
            return $version;
        }
    }
    return 'dev';
}

function applicationRequestId(): string
{
    static $request_id = null;
    if ($request_id === null) {
        $request_id = bin2hex(random_bytes(12));
    }
    return $request_id;
}

/** @param array<string, mixed> $context */
function applicationLog(string $level, string $message, array $context = []): void
{
    $record = [
        'timestamp' => gmdate('c'),
        'level' => strtolower($level),
        'message' => $message,
        'request_id' => applicationRequestId(),
    ];
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $record['method'] = (string) $_SERVER['REQUEST_METHOD'];
    }
    if (isset($_SERVER['REQUEST_URI'])) {
        $record['path'] = (string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    }
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $record[(string) $key] = $value;
        }
    }

    error_log(json_encode(
        $record,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '{"level":"error","message":"Unable to encode application log record"}');
}

/** @param array<string, mixed> $context */
function abortApplication(int $status, string $public_message, array $context = []): never
{
    $status = max(400, min(599, $status));
    applicationLog($status >= 500 ? 'error' : 'warning', $public_message, $context + [
        'status' => $status,
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $public_message . "\nRequest ID: " . applicationRequestId() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('X-Request-ID: ' . applicationRequestId());
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit($public_message . "\nRequest ID: " . applicationRequestId());
}

function registerApplicationErrorHandling(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    if (!headers_sent()) {
        header('X-Request-ID: ' . applicationRequestId());
    }

    set_exception_handler(static function (Throwable $exception): void {
        applicationLog('error', 'Unhandled application exception', [
            'exception' => get_class($exception),
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "The application encountered an unexpected error.\nRequest ID: " . applicationRequestId() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store');
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Request-ID: ' . applicationRequestId());
        }
        echo "The application encountered an unexpected error.\nRequest ID: " . applicationRequestId();
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!is_array($error)
            || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)
        ) {
            return;
        }
        applicationLog('critical', 'Fatal application error', [
            'error' => (string) $error['message'],
            'file' => (string) $error['file'],
            'line' => (int) $error['line'],
        ]);
    });
}

registerApplicationErrorHandling();
