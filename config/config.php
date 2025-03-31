<?php

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dnr_db');
define('DB_USER', 'root');  // Change in production
define('DB_PASSWORD', '');   // Change in production

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

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'DNR\\';
    $base_dir = __DIR__ . '/../src/';

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