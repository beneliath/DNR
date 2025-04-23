<?php

// Database configuration
define('DB_HOST', 'db');  // Docker service name
define('DB_NAME', 'dnr');  // From docker-compose.yml
define('DB_USER', 'dnruser');  // From docker-compose.yml
define('DB_PASSWORD', 'dnrpassword');  // From docker-compose.yml

// Application configuration
define('APP_NAME', 'DNR System');
define('APP_URL', 'http://localhost/dnr');  // Change in production
define('APP_VERSION', '1.0.0');

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour
define('SESSION_NAME', 'dnr_session');

// Security configuration
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('PASSWORD_HASH_COST', 12);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);  // Set to 0 in production

// Timezone
date_default_timezone_set('America/New_York');

// Database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'DNR\\';
    $base_dir = __DIR__ . '/../';  // Updated to point to src directory

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
}); 