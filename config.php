<?php
/**
 * Application configuration and PDO connection
 * This file is intended to be required by runtime pages.
 * For initial database creation use `setup_database.php` or import `sql/schema.sql`.
 */

// Start session early
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allow local overrides (do not commit config.local.php)
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// Site-wide constants (adjust as needed)
if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'ASCB TVET');
}
// Update SITE_URL to match your local environment
if (!defined('SITE_URL')) {
    define('SITE_URL', isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST']) : 'http://localhost');
}

// SMTP configuration (PHPMailer). Defaults geared for Gmail.
// For production, move secrets to env vars or a non-committed local include.
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
if (!defined('SMTP_FROM')) define('SMTP_FROM', getenv('SMTP_FROM') ?: (getenv('SMTP_USER') ?: ''));
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', defined('SITE_NAME') ? SITE_NAME : 'Web App');
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls'); // tls or ssl
if (!defined('SMTP_DEBUG')) define('SMTP_DEBUG', (int)(getenv('SMTP_DEBUG') ?: 0)); // 0,1,2,3,4

// Database connection settings
$DB_HOST = isset($DB_HOST) ? $DB_HOST : '127.0.0.1';
$DB_NAME = isset($DB_NAME) ? $DB_NAME : 'college_grading_system';
$DB_USER = isset($DB_USER) ? $DB_USER : 'root';
$DB_PASS = isset($DB_PASS) ? $DB_PASS : '';

try {
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Friendly error for development. In production, log this and show generic error.
    die("Database connection failed: " . $e->getMessage());
}

// Utility: include shared helpers (sanitize, auth helpers, auto-enroll)
require_once __DIR__ . '/include/functions.php';

?>
