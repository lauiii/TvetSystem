<?php
/**
 * Automated Backup Script
 * 
 * This script can be run via cron (Linux/Mac) or Task Scheduler (Windows)
 * to automatically create backups of the database.
 * 
 * Windows Task Scheduler Example:
 * php "C:\xampp\htdocs\tvetsystem\scripts\automated_backup.php"
 * 
 * Linux Cron Example (daily at 2 AM):
 * 0 2 * * * /usr/bin/php /path/to/tvetsystem/scripts/automated_backup.php
 * 
 * Configuration:
 * - Set BACKUP_RETENTION_DAYS to automatically delete old backups
 * - Logs are written to ../logs/backup.log
 */

// Load configuration
require_once __DIR__ . '/../config.php';

// Configuration
define('BACKUP_RETENTION_DAYS', 30); // Keep backups for 30 days
define('MAX_BACKUPS_TO_KEEP', 50);   // Maximum number of backups to keep

// Create necessary directories
$backupDir = __DIR__ . '/../backups';
$logDir = __DIR__ . '/../logs';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/backup.log';

/**
 * Write to log file
 */
function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    echo $logMessage; // Also output to console
}

/**
 * Create database backup
 */
function createDatabaseBackup() {
    global $backupDir, $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    
    try {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "db_backup_{$timestamp}.sql";
        $filepath = $backupDir . '/' . $filename;
        
        // Create mysqldump command
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($DB_HOST),
            escapeshellarg($DB_USER),
            escapeshellarg($DB_PASS),
            escapeshellarg($DB_NAME),
            escapeshellarg($filepath)
        );
        
        // Execute backup
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            $size = filesize($filepath);
            $sizeFormatted = formatBytes($size);
            writeLog("SUCCESS: Database backup created: {$filename} ({$sizeFormatted})");
            return true;
        } else {
            $errorOutput = implode("\n", $output);
            writeLog("ERROR: Database backup failed. Return code: {$returnVar}. Output: {$errorOutput}");
            return false;
        }
    } catch (Exception $e) {
        writeLog("ERROR: Exception during backup: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete old backups based on retention policy
 */
function cleanupOldBackups() {
    global $backupDir;
    
    $deletedCount = 0;
    $retentionTimestamp = time() - (BACKUP_RETENTION_DAYS * 24 * 60 * 60);
    
    if (!is_dir($backupDir)) {
        return $deletedCount;
    }
    
    $files = scandir($backupDir);
    $backupFiles = [];
    
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && $file !== '.htaccess') {
            $filepath = $backupDir . '/' . $file;
            if (is_file($filepath)) {
                $backupFiles[] = [
                    'filename' => $file,
                    'filepath' => $filepath,
                    'mtime' => filemtime($filepath)
                ];
            }
        }
    }
    
    // Sort by modification time (oldest first)
    usort($backupFiles, function($a, $b) {
        return $a['mtime'] - $b['mtime'];
    });
    
    // Delete files older than retention period
    foreach ($backupFiles as $backup) {
        if ($backup['mtime'] < $retentionTimestamp) {
            if (unlink($backup['filepath'])) {
                writeLog("CLEANUP: Deleted old backup: {$backup['filename']}");
                $deletedCount++;
            }
        }
    }
    
    // If still too many backups, delete oldest ones
    $totalBackups = count($backupFiles) - $deletedCount;
    if ($totalBackups > MAX_BACKUPS_TO_KEEP) {
        $toDelete = $totalBackups - MAX_BACKUPS_TO_KEEP;
        $currentIndex = 0;
        
        foreach ($backupFiles as $backup) {
            if ($currentIndex >= $toDelete) {
                break;
            }
            
            if (file_exists($backup['filepath'])) {
                if (unlink($backup['filepath'])) {
                    writeLog("CLEANUP: Deleted excess backup: {$backup['filename']}");
                    $deletedCount++;
                    $currentIndex++;
                }
            }
        }
    }
    
    if ($deletedCount > 0) {
        writeLog("CLEANUP: Total backups deleted: {$deletedCount}");
    }
    
    return $deletedCount;
}

/**
 * Format bytes to human readable size
 */
function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Main execution
 */
writeLog("========================================");
writeLog("Starting automated backup process");
writeLog("========================================");

// Create database backup
$backupSuccess = createDatabaseBackup();

// Cleanup old backups
$deletedCount = cleanupOldBackups();

// Summary
writeLog("========================================");
if ($backupSuccess) {
    writeLog("Automated backup completed successfully");
} else {
    writeLog("Automated backup completed with errors");
}
writeLog("========================================");

// Exit with appropriate code
exit($backupSuccess ? 0 : 1);
?>
