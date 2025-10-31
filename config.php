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

// Site-wide constants (adjust as needed)
define('SITE_NAME', 'College Grading System');
// Update SITE_URL to match your local environment
define('SITE_URL', (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $_SERVER['HTTP_HOST'] : 'http://localhost'));

// SMTP placeholders (set real values in a local include not checked into git)
if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp.example.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
if (!defined('SMTP_USER')) define('SMTP_USER', 'user@example.com');
if (!defined('SMTP_PASS')) define('SMTP_PASS', 'password');
if (!defined('SMTP_FROM')) define('SMTP_FROM', 'no-reply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', SITE_NAME);

// Database connection settings
$DB_HOST = '127.0.0.1';
$DB_NAME = 'college_grading_system';
$DB_USER = 'root';
$DB_PASS = '';

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