<?php
/**
 * Reset class-related data to start fresh.
 *
 * What it clears (by default):
 * - attendance_records, attendance_sessions
 * - grades, assessment_items, assessment_criteria
 * - enrollments
 * - instructor_sections, sections, courses
 * Optionally: flags (use --with-flags) and rooms (use --with-rooms)
 *
 * It keeps: users, programs, school_years, notifications
 *
 * Usage (CLI):
 *   php scripts/reset_classes.php --confirm [--with-flags] [--with-rooms]
 * Usage (web):
 *   /scripts/reset_classes.php?confirm=1&with_flags=1&with_rooms=1
 */

require_once __DIR__ . '/../config.php';

// Parse confirmation/flags from CLI or GET
$argvFlags = [];
if (php_sapi_name() === 'cli' && isset($argv)) {
    foreach ($argv as $a) {
        if (strpos($a, '--') === 0) { $argvFlags[$a] = true; }
    }
}
$confirm = isset($argvFlags['--confirm']) || (isset($_GET['confirm']) && $_GET['confirm'] == '1');
$withFlags = isset($argvFlags['--with-flags']) || (isset($_GET['with_flags']) && $_GET['with_flags'] == '1');
$withRooms = isset($argvFlags['--with-rooms']) || (isset($_GET['with_rooms']) && $_GET['with_rooms'] == '1');

if (!$confirm) {
    echo "\nThis will DELETE all class-related data (attendance, grades, assessments, enrollments, sections, courses).\n";
    echo "Users/programs/school years remain. Optional: --with-flags to clear flags, --with-rooms to clear rooms.\n\n";
    echo "Dry-run only. To proceed, run: php scripts/reset_classes.php --confirm [--with-flags] [--with-rooms]\n";
    exit(0);
}

try {
    // Disable foreign key checks for safe truncation (TRUNCATE is DDL and auto-commits anyway)
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $tables = [
        'attendance_records',
        'attendance_sessions',
        'grades',
        'assessment_items',
        'assessment_criteria',
        'enrollments',
        'instructor_sections',
        'sections',
        'courses',
    ];
    if ($withFlags) { array_unshift($tables, 'flags'); }
    if ($withRooms) { $tables[] = 'rooms'; }

    foreach ($tables as $t) {
        try {
            $pdo->exec("TRUNCATE TABLE `{$t}`");
            echo "Truncated {$t}\n";
        } catch (Exception $e) {
            echo "Skip {$t}: " . $e->getMessage() . "\n";
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "\nReset complete.\n";
} catch (Exception $e) {
    // Best effort to re-enable FK checks
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Exception $ignored) {}
    echo "Reset failed: " . $e->getMessage() . "\n";
    exit(1);
}
