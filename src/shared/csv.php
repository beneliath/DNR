<?php

declare(strict_types=1);

/** Keep spreadsheet-formula prefixes inert while preserving the original cell text. */
function spreadsheetCsvText(string $value): string
{
    return preg_match('/\A(?:[\t\r\n]|[\p{Z}\p{Cc}\x{FEFF}]*[=+@＝＋－＠-])/u', $value) === 1
        ? "'" . $value
        : $value;
}
