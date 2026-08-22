<?php

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

define('APP_VERSION', '1.4.4');

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

// Preserve explicit error handling across PHP 7.4 and PHP 8.x.
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    applicationLog('error', 'Database connection failed', ['error' => $conn->connect_error]);
    if (defined('DNR_DATABASE_FAILURES_THROW') && DNR_DATABASE_FAILURES_THROW) {
        throw new RuntimeException('Database service unavailable.');
    }
    abortApplication(503, 'Database service unavailable.');
}

if (!$conn->set_charset('utf8mb4')) {
    applicationLog('error', 'Unable to set database connection charset', ['error' => $conn->error]);
}
