<?php

declare(strict_types=1);

/**
 * Read a secret without echoing it when attached to a terminal. Redirected
 * input is already non-echoing and remains supported for automation.
 */
function readHiddenCliValue(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    if (!$interactive) {
        $value = fgets(STDIN);
        return is_string($value) ? rtrim($value, "\r\n") : '';
    }

    if (!function_exists('shell_exec')) {
        throw new RuntimeException(
            'Secure terminal input is unavailable; refusing to read a visible secret.'
        );
    }

    $echo_status = shell_exec('stty -echo 2>/dev/null; printf "%s" "$?"');
    if (trim((string) $echo_status) !== '0') {
        throw new RuntimeException(
            'Unable to disable terminal echo; refusing to read a visible secret.'
        );
    }

    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    return is_string($value) ? rtrim($value, "\r\n") : '';
}
