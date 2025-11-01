<?php
/**
 * Reset ONLY course sections (and related assignments/attendance), keeping courses and enrollments.
 *
 * What it clears:
 * - attendance_records, attendance_sessions (section-linked)
 * - instructor_sections (assignments)
 * - sections (all)
 *
 * It keeps: users, programs, school_years, courses, enrollments, grades, criteria/items, notifications, flags, rooms.
 * If an optional enrollments.section_id column exists, it is set to NULL to avoid broken refs.
 *
 * Usage (CLI):
 *   php scripts/reset_sections.php --confirm
 * Usage (web):
 *   /scripts/reset_sections.php?confirm=1
 */

require_once __DIR__ . '/../config.php';

// Parse confirmation from CLI or GET
$argvFlags = [];
if (php_sapi_name() === 'cli' && isset($argv)) {
    foreach ($argv as $a) {
        if (strpos($a, '--') === 0) { $argvFlags[$a] = true; }
    }
}
$confirm = isset($argvFlags['--confirm']) || (isset($_GET['confirm']) && $_GET['confirm'] == '1');

if (!$confirm) {
    echo "\nThis will DELETE all sections and instructor assignments (and section-linked attendance).\n";
    echo "Courses, enrollments, grades, and other data will be preserved.\n\n";
    echo "Dry-run only. To proceed, run: php scripts/reset_sections.php --confirm\n";
    exit(0);
}

try {
    // If enrollments has a section_id column, null it out to avoid refs
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (in_array('section_id', $cols)) {
            $pdo->exec("UPDATE enrollments SET section_id = NULL");
            echo "Cleared enrollments.section_id\n";
        }
    } catch (Exception $e) {
        // ignore schema inspection errors
    }

    // Disable foreign key checks to allow TRUNCATE in any order
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $tables = [
        'attendance_records',
        'attendance_sessions',
        'instructor_sections',
        'sections',
    ];

    foreach ($tables as $t) {
        try {
            $pdo->exec("TRUNCATE TABLE `{$t}`");
            echo "Truncated {$t}\n";
        } catch (Exception $e) {
            // Fallback to DELETE if TRUNCATE fails (table may not exist in some installs)
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$t}`");
                $stmt->execute();
                echo "Deleted rows from {$t}\n";
            } catch (Exception $e2) {
                echo "Skip {$t}: " . $e2->getMessage() . "\n";
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "\nSection reset complete.\n";
} catch (Exception $e) {
    // Best effort to re-enable FK checks
    try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Exception $ignored) {}
    echo "Reset failed: " . $e->getMessage() . "\n";
    exit(1);
}
