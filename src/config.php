<?php

function configurationSecret($name, $default = '') {
    $file_path = trim((string) (getenv($name . '_FILE') ?: ''));
    if ($file_path !== '') {
        $secret = @file_get_contents($file_path);
        if ($secret === false) {
            error_log("Unable to read configured secret file for {$name}.");
            return '';
        }
        return trim($secret);
    }
    $value = getenv($name);
    return $value === false ? $default : trim((string) $value);
}

define('APP_VERSION', '1.1.1');

// Use environment variables and secret files without committed credentials.
$DB_HOST = getenv('DB_HOST') ? getenv('DB_HOST') : 'db';
$DB_USER = getenv('MYSQL_USER') ? getenv('MYSQL_USER') : 'dnruser';
$DB_PASS = configurationSecret('MYSQL_PASSWORD');
$DB_NAME = getenv('MYSQL_DATABASE') ? getenv('MYSQL_DATABASE') : 'dnr';

if ($DB_PASS === '') {
    error_log('MYSQL_PASSWORD or MYSQL_PASSWORD_FILE must be configured.');
    http_response_code(503);
    die('Database credentials are not configured.');
}

// Preserve explicit error handling across PHP 7.4 and PHP 8.x.
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    die("Database service unavailable.");
}

if (!$conn->set_charset('utf8mb4')) {
    error_log("Unable to set database connection charset: " . $conn->error);
}
