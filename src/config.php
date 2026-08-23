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

function applicationDatabaseConnection(): mysqli
{
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $host = (string) (getenv('DB_HOST') ?: 'db');
    $user = (string) (getenv('MYSQL_USER') ?: 'dnruser');
    $password = configurationSecret('MYSQL_PASSWORD');
    $database = (string) (getenv('MYSQL_DATABASE') ?: 'dnr');
    if ($password === '') {
        if (defined('DNR_DATABASE_FAILURES_THROW') && DNR_DATABASE_FAILURES_THROW) {
            throw new RuntimeException('Database credentials are not configured.');
        }
        abortApplication(503, 'Database credentials are not configured.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $connection = new mysqli($host, $user, $password, $database);
        $connection->set_charset('utf8mb4');
        return $connection;
    } catch (mysqli_sql_exception $exception) {
        applicationLog('error', 'Database connection failed', ['error' => $exception->getMessage()]);
        if (defined('DNR_DATABASE_FAILURES_THROW') && DNR_DATABASE_FAILURES_THROW) {
            throw new RuntimeException('Database service unavailable.', 0, $exception);
        }
        abortApplication(503, 'Database service unavailable.');
    }
}

$conn = applicationDatabaseConnection();
