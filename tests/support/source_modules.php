<?php

declare(strict_types=1);

/**
 * Read a compatibility entry point and its explicitly loaded implementation
 * modules. Source-level contract checks must inspect the code that the entry
 * point actually loads, without executing it or assuming a single large file.
 */
function readModuleSource(string $path): string
{
    $visited = [];
    $read = static function (string $path) use (&$read, &$visited): string {
        $resolved = realpath($path);
        if ($resolved === false) throw new RuntimeException('Missing source file: ' . $path);
        if (isset($visited[$resolved])) return '';
        $visited[$resolved] = true;
        $source = file_get_contents($resolved);
        if ($source === false) throw new RuntimeException('Unable to read source file: ' . $path);
        preg_match_all("~^require_once __DIR__ \\. '/((?:shared|moed|message_monkey)/[^']+\\.php)';~m", $source, $matches);
        foreach ($matches[1] as $module) {
            $source .= "\n" . $read(dirname($resolved) . '/' . $module);
        }
        if (in_array(pathinfo($resolved, PATHINFO_EXTENSION), ['js', 'mjs'], true)) {
            preg_match_all('~^import .*? from [\'"](\\.{1,2}/[^\'"]+\\.mjs)[\'"];~m', $source, $imports);
            foreach ($imports[1] as $module) {
                $source .= "\n" . $read(dirname($resolved) . '/' . $module);
            }
        }
        return $source;
    };
    return $read($path);
}
