<?php
/**
 * KhataShata - Application Constants & Paths
 */

// Application Info
define('APP_NAME', 'KhataShata');
define('APP_TAGLINE', 'Personal Business Portfolio & Financial Tracking System');
define('APP_VERSION', '1.0.0');

// Paths
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

// Determine base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$base_url = rtrim($protocol . $host . $script_dir, '/');

// Clean subpath for root
if (basename($base_url) === 'pages' || basename($base_url) === 'ajax') {
    $base_url = dirname($base_url);
}
define('BASE_URL', $base_url);

// Session Security Configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if ($protocol === 'https://') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}
