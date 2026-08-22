<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function configurationSecret($name, $default = '') {
    $file_path = trim((string) (getenv($name . '_FILE') ?: ''));
    if ($file_path !== '') {
        $secret = @file_get_contents($file_path);
        if ($secret === false) {
            applicationLog('error', 'Unable to read configured secret file', ['secret' => $name]);
            return '';
        }
        return trim($secret);
    }
    $value = getenv($name);
    return $value === false ? $default : trim((string) $value);
}

define('APP_VERSION', applicationVersion());

// Use environment variables and secret files without committed credentials.
$DB_HOST = getenv('DB_HOST') ? getenv('DB_HOST') : 'db';
$DB_USER = getenv('MYSQL_USER') ? getenv('MYSQL_USER') : 'dnruser';
$DB_PASS = configurationSecret('MYSQL_PASSWORD');
$DB_NAME = getenv('MYSQL_DATABASE') ? getenv('MYSQL_DATABASE') : 'dnr';

if ($DB_PASS === '') {
    if (defined('DNR_DATABASE_FAILURES_THROW') && DNR_DATABASE_FAILURES_THROW) {
        throw new RuntimeException('Database credentials are not configured.');
    }
    abortApplication(503, 'Database credentials are not configured.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    applicationLog('error', 'Database connection failed', ['error' => $exception->getMessage()]);
    if (defined('DNR_DATABASE_FAILURES_THROW') && DNR_DATABASE_FAILURES_THROW) {
        throw new RuntimeException('Database service unavailable.', 0, $exception);
    }
    abortApplication(503, 'Database service unavailable.');
}
