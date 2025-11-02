<?php
/**
 * Admin Backup & Restore
 * Database and file backup/restore functionality
 */

require_once '../config.php';
requireRole('admin');

// Create backups directory if it doesn't exist
$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$message = '';
$messageType = '';

// Handle backup actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'backup_database':
                try {
                    $timestamp = date('Y-m-d_H-i-s');
                    $filename = "db_backup_{$timestamp}.sql";
                    $filepath = $backupDir . '/' . $filename;
                    
                    // Get database credentials from config
                    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
                    
                    // Create mysqldump command
                    $command = sprintf(
                        'mysqldump --host=%s --user=%s --password=%s %s > %s',
                        escapeshellarg($DB_HOST),
                        escapeshellarg($DB_USER),
                        escapeshellarg($DB_PASS),
                        escapeshellarg($DB_NAME),
                        escapeshellarg($filepath)
                    );
                    
                    // Execute backup
                    exec($command, $output, $returnVar);
                    
                    if ($returnVar === 0 && file_exists($filepath)) {
                        $message = "Database backup created successfully: {$filename}";
                        $messageType = 'success';
                    } else {
                        $message = "Database backup failed. Please check if mysqldump is available in your PATH.";
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = "Error creating database backup: " . $e->getMessage();
                    $messageType = 'error';
                }
                break;
                
            case 'restore_database':
                if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $tmpFile = $_FILES['backup_file']['tmp_name'];
                        
                        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
                        
                        $command = sprintf(
                            'mysql --host=%s --user=%s --password=%s %s < %s',
                            escapeshellarg($DB_HOST),
                            escapeshellarg($DB_USER),
                            escapeshellarg($DB_PASS),
                            escapeshellarg($DB_NAME),
                            escapeshellarg($tmpFile)
                        );
                        
                        exec($command, $output, $returnVar);
                        
                        if ($returnVar === 0) {
                            $message = "Database restored successfully!";
                            $messageType = 'success';
                        } else {
                            $message = "Database restore failed. Please check your backup file.";
                            $messageType = 'error';
                        }
                    } catch (Exception $e) {
                        $message = "Error restoring database: " . $e->getMessage();
                        $messageType = 'error';
                    }
                } else {
                    $message = "Please select a valid backup file.";
                    $messageType = 'error';
                }
                break;
                
            case 'delete_backup':
                if (isset($_POST['filename'])) {
                    $filename = basename($_POST['filename']); // Security: prevent directory traversal
                    $filepath = $backupDir . '/' . $filename;
                    
                    if (file_exists($filepath) && unlink($filepath)) {
                        $message = "Backup deleted successfully: {$filename}";
                        $messageType = 'success';
                    } else {
                        $message = "Failed to delete backup file.";
                        $messageType = 'error';
                    }
                }
                break;
        }
    }
}

// Get list of existing backups
$backups = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && $file !== '.htaccess') {
            $filepath = $backupDir . '/' . $file;
            $backups[] = [
                'filename' => $file,
                'size' => filesize($filepath),
                'date' => date('Y-m-d H:i:s', filemtime($filepath)),
                'type' => 'Database'
            ];
        }
    }
    // Sort by date descending
    usort($backups, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
}

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Restore - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
    <style>
        .backup-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .backup-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .backup-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
        }
        .backup-card:hover {
            border-color: #007bff;
            box-shadow: 0 4px 8px rgba(0,123,255,0.2);
        }
        .backup-card h3 {
            margin-top: 0;
            color: #333;
            font-size: 18px;
        }
        .backup-card p {
            color: #666;
            font-size: 14px;
            margin: 10px 0;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        .btn-warning:hover {
            background: #e0a800;
        }
        .backups-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .backups-table th,
        .backups-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .backups-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .backups-table tr:hover {
            background: #f8f9fa;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-primary {
            background: #007bff;
            color: white;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .restore-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'backup'; require __DIR__ . '/inc/sidebar.php'; ?>
        
        <main class="main-content">
            <button class="menu-toggle">☰ Menu</button>
            
            <?php $pageTitle = 'Backup & Restore'; require __DIR__ . '/inc/header.php'; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="backup-section">
                <h2>Create Database Backup</h2>
                <p>Create a backup of your database to ensure data safety.</p>
                
                <div class="backup-actions">
                    <div class="backup-card">
                        <h3>📊 Database Backup</h3>
                        <p>Backup all database tables and data</p>
                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="backup_database">
                            <button type="submit" class="btn btn-primary">Create Backup</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="backup-section restore-section">
                <h2>⚠️ Restore Database</h2>
                <p><strong>Warning:</strong> Restoring a database will overwrite all current data. Make sure you have a recent backup before proceeding.</p>
                <form method="POST" enctype="multipart/form-data" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="restore_database">
                    <input type="file" name="backup_file" accept=".sql" required style="margin-bottom: 10px;">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to restore this backup? This will overwrite all current data!')">Restore Database</button>
                </form>
            </div>
            
            <div class="backup-section">
                <h2>Available Backups</h2>
                <?php if (count($backups) > 0): ?>
                    <table class="backups-table">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $backup['type'] === 'Database' ? 'badge-primary' : 'badge-success'; ?>">
                                            <?php echo htmlspecialchars($backup['type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatBytes($backup['size']); ?></td>
                                    <td><?php echo htmlspecialchars($backup['date']); ?></td>
                                    <td>
                                        <a href="download_backup.php?file=<?php echo urlencode($backup['filename']); ?>" class="btn btn-primary" style="margin-right: 5px; padding: 5px 10px; font-size: 12px;">Download</a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;" onclick="return confirm('Are you sure you want to delete this backup?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No backups found. Create your first backup above.</p>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <script>
        // Mobile menu toggle
        const menuToggle = document.querySelector('.menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        
        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
        }
    </script>
</body>
</html>
