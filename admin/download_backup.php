<?php
/**
 * Secure Backup Download Handler
 * Allows admin users to download backup files securely
 */

require_once '../config.php';
requireRole('admin');

if (!isset($_GET['file'])) {
    die('No file specified');
}

// Security: prevent directory traversal
$filename = basename($_GET['file']);
$filepath = __DIR__ . '/../backups/' . $filename;

// Verify file exists
if (!file_exists($filepath)) {
    die('File not found');
}

// Verify file is in backups directory (additional security check)
$realBackupDir = realpath(__DIR__ . '/../backups');
$realFilePath = realpath($filepath);

if (!$realFilePath || strpos($realFilePath, $realBackupDir) !== 0) {
    die('Invalid file path');
}

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Output file
readfile($filepath);
exit;
?>
