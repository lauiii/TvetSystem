# Backup & Restore System

This system provides comprehensive backup and restore functionality for your school management system.

## Features

- **Manual Backups**: Create database and file backups on demand via the admin interface
- **Automated Backups**: Schedule automatic database backups using Task Scheduler (Windows) or Cron (Linux/Mac)
- **Restore Functionality**: Easily restore database from backup files
- **Backup Management**: View, download, and delete existing backups
- **Security**: Backups are protected from direct web access via .htaccess

## Manual Backup (Web Interface)

1. Log in as an administrator
2. Navigate to **Backup & Restore** in the sidebar
3. Choose from three backup options:
   - **Database Backup**: Backs up all database tables and data (.sql file)
   - **Files Backup**: Backs up assets, uploads, and public files (.zip file)
   - **Full Backup**: Complete backup of both database and files

4. View all existing backups in the table below
5. Download or delete backups as needed

## Automated Backups

### Windows Task Scheduler Setup

1. Open Task Scheduler (search "Task Scheduler" in Windows)
2. Click **Create Basic Task**
3. Name: "TVET System Backup"
4. Trigger: Choose "Daily" and set your preferred time (e.g., 2:00 AM)
5. Action: "Start a program"
6. Program/script: `C:\xampp\php\php.exe`
7. Add arguments: `"C:\xampp\htdocs\tvetsystem\scripts\automated_backup.php"`
8. Click Finish

### Linux/Mac Cron Setup

1. Edit your crontab:
   ```bash
   crontab -e
   ```

2. Add this line to run daily at 2 AM:
   ```
   0 2 * * * /usr/bin/php /path/to/tvetsystem/scripts/automated_backup.php
   ```

3. Save and exit

### Backup Configuration

Edit `scripts/automated_backup.php` to customize:

- **BACKUP_RETENTION_DAYS**: Number of days to keep old backups (default: 30)
- **MAX_BACKUPS_TO_KEEP**: Maximum number of backups to store (default: 50)

## Restore Database

⚠️ **Warning**: Restoring will overwrite all current data. Always create a backup before restoring!

### Via Web Interface

1. Go to **Backup & Restore** page
2. Scroll to the **Restore Database** section
3. Upload your .sql backup file
4. Confirm the restoration

### Via Command Line

```bash
mysql -u root -p college_grading_system < backups/db_backup_2025-01-02_14-30-00.sql
```

## Backup Locations

- Manual backups: `backups/` directory
- Backup logs: `logs/backup.log`

## Requirements

- PHP with `exec()` function enabled
- MySQL/MariaDB with `mysqldump` and `mysql` CLI tools
- PHP ZipArchive extension for file backups
- Sufficient disk space for backups

## Security

- The `backups/` directory is protected via `.htaccess`
- Direct web access to backup files is denied
- Downloads are only available through the admin interface
- Only administrators can access backup functionality

## Troubleshooting

### Database backup fails

- Ensure `mysqldump` is in your system PATH
- On Windows: Add `C:\xampp\mysql\bin` to PATH environment variable
- Check that MySQL credentials in `config.php` are correct

### Files backup fails

- Verify PHP ZipArchive extension is installed: `php -m | grep zip`
- Ensure write permissions on `backups/` directory

### Automated backup not running

- Check Task Scheduler (Windows) or cron logs (Linux/Mac)
- Verify PHP path is correct in the scheduled task
- Check `logs/backup.log` for error messages

## Best Practices

1. **Regular Backups**: Schedule daily automated backups
2. **Off-site Storage**: Periodically download and store backups externally
3. **Test Restores**: Regularly test restoring backups to ensure they work
4. **Monitor Logs**: Check `logs/backup.log` for any issues
5. **Cleanup**: Old backups are automatically deleted based on retention policy

## Support

For issues or questions, check the backup logs in `logs/backup.log` for detailed error messages.
