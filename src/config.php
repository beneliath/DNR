<?php

// Use environment variables with defaults
$DB_HOST = getenv('DB_HOST') ? getenv('DB_HOST') : 'db';
$DB_USER = getenv('MYSQL_USER') ? getenv('MYSQL_USER') : 'dnruser';
$DB_PASS = getenv('MYSQL_PASSWORD') ? getenv('MYSQL_PASSWORD') : 'dnrpassword';
$DB_NAME = getenv('MYSQL_DATABASE') ? getenv('MYSQL_DATABASE') : 'dnr';

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
