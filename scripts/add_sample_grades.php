<?php
/**
 * Add sample grades for existing students/enrollments.
 *
 * CLI usage examples (PowerShell):
 *   php scripts\\add_sample_grades.php               # fill missing grades across all courses (active SY)
 *   php scripts\\add_sample_grades.php --all         # overwrite existing grades too
 *   php scripts\\add_sample_grades.php --course=12   # limit to a specific course id
 *   php scripts\\add_sample_grades.php --section=5   # limit by section's course (section used to resolve course)
 *   php scripts\\add_sample_grades.php --min=70 --max=95
 *   php scripts\\add_sample_grades.php --limit=200   # limit number of enrollments processed
 */

require_once __DIR__ . '/../config.php';

// Parse simple --key=value or --flag CLI options
$options = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', $arg, 2);
        $key = ltrim($parts[0], '-');
        $val = isset($parts[1]) ? $parts[1] : true;
        $options[$key] = $val;
    }
}

$overwriteAll   = isset($options['all']);           // if true, overwrite existing grades too
$courseFilter   = isset($options['course'])  ? (int)$options['course']  : 0;
$sectionFilter  = isset($options['section']) ? (int)$options['section'] : 0;
$minGrade       = isset($options['min'])     ? max(0, (int)$options['min']) : 70;
$maxGrade       = isset($options['max'])     ? max($minGrade, (int)$options['max']) : 100;
$limitEnroll    = isset($options['limit'])   ? max(1, (int)$options['limit']) : 0; // 0 = no limit

try {
    echo "Preparing to add sample grades...\n";

    // Resolve course by section if provided
    if ($sectionFilter > 0 && $courseFilter === 0) {
        $st = $pdo->prepare('SELECT course_id FROM sections WHERE id = ?');
        $st->execute([$sectionFilter]);
        $courseFilter = (int)($st->fetchColumn() ?: 0);
        if ($courseFilter <= 0) {
            echo "Section not found or has no course: {$sectionFilter}\n";
            exit(1);
        }
        echo "Resolved section {$sectionFilter} to course {$courseFilter}.\n";
    }

    // Determine active school year (optional scope)
    $activeSy = $pdo->query("SELECT id FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $activeSyId = $activeSy['id'] ?? null;
    if ($activeSyId) {
        echo "Active School Year ID: {$activeSyId}\n";
    } else {
        echo "Warning: No active school year found — processing all enrollments.\n";
    }

    // Check if assessment_criteria has section_id column (schema may vary across installs)
    $hasSectionId = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM assessment_criteria")->fetchAll(PDO::FETCH_COLUMN);
        $hasSectionId = in_array('section_id', $cols, true);
    } catch (Exception $e) { /* ignore */ }

    // Gather enrollments to process
    $sql = "SELECT e.id AS enrollment_id, e.course_id
            FROM enrollments e
            WHERE e.status = 'enrolled'";
    $params = [];
    if ($activeSyId) { $sql .= " AND e.school_year_id = ?"; $params[] = $activeSyId; }
    if ($courseFilter > 0) { $sql .= " AND e.course_id = ?"; $params[] = $courseFilter; }
    $sql .= " ORDER BY e.id";
    if ($limitEnroll > 0) { $sql .= " LIMIT " . (int)$limitEnroll; }

    $enrollments = $pdo->prepare($sql);
    $enrollments->execute($params);
    $enrs = $enrollments->fetchAll(PDO::FETCH_ASSOC);

    if (empty($enrs)) {
        echo "No enrollments found for the given filters.\n";
        exit(0);
    }

    echo "Enrollments to process: " . count($enrs) . "\n";

    // Pre-prepare statements
    $itemStmtCourseOnly = $pdo->prepare(
        "SELECT ai.id, ai.total_score
         FROM assessment_items ai
         JOIN assessment_criteria ac ON ai.criteria_id = ac.id
         WHERE ac.course_id = ?"
    );

    // When section_id exists, include items attached to any section of the course as well
    $itemStmtWithSections = $hasSectionId ? $pdo->prepare(
        "SELECT DISTINCT ai.id, ai.total_score
           FROM assessment_items ai
           JOIN assessment_criteria ac ON ai.criteria_id = ac.id
          WHERE ac.course_id = ?
          UNION
         SELECT DISTINCT ai2.id, ai2.total_score
           FROM assessment_items ai2
           JOIN assessment_criteria ac2 ON ai2.criteria_id = ac2.id
           JOIN sections s ON ac2.section_id = s.id
          WHERE s.course_id = ?"
    ) : null;

    // Optional: when not overwriting, fetch existing grade map for quick skip
    $existingMap = [];
    if (!$overwriteAll) {
        $ids = array_column($enrs, 'enrollment_id');
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT enrollment_id, assessment_id FROM grades WHERE enrollment_id IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $existingMap[$r['enrollment_id']][$r['assessment_id']] = true;
            }
        }
    }

    $insertStmt = $pdo->prepare(
        $overwriteAll
            ? "INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at)
                 VALUES (?, ?, ?, 'complete', NOW())
               ON DUPLICATE KEY UPDATE grade = VALUES(grade), status = 'complete', submitted_at = NOW()"
            : "INSERT IGNORE INTO grades (enrollment_id, assessment_id, grade, status, submitted_at)
                 VALUES (?, ?, ?, 'complete', NOW())"
    );

    $totalInserts = 0; $coursesTouched = 0; $enrollTouched = 0;

    foreach ($enrs as $en) {
        $enrollId = (int)$en['enrollment_id'];
        $courseId = (int)$en['course_id'];

        // Fetch assessment items for this course (course-scoped plus any section-scoped items if applicable)
        $items = [];
        if ($hasSectionId) {
            $itemStmtWithSections->execute([$courseId, $courseId]);
            $items = $itemStmtWithSections->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $itemStmtCourseOnly->execute([$courseId]);
            $items = $itemStmtCourseOnly->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($items)) { continue; }
        $coursesTouched++;
        $enrollTouched++;

        foreach ($items as $it) {
            $aid = (int)$it['id'];
            if (!$overwriteAll && isset($existingMap[$enrollId][$aid])) { continue; }

            $max = (float)$it['total_score'];
            $hi = min($maxGrade, (int)round($max)); // don't exceed item max
            $lo = min($minGrade, $hi);
            $grade = $hi > 0 ? rand($lo, $hi) : 0;

            $insertStmt->execute([$enrollId, $aid, $grade]);
            $totalInserts++;
        }
    }

    echo "Done. Records inserted/updated: {$totalInserts}\n";
    echo "Enrollments touched: {$enrollTouched}\n";
    echo "Courses touched (approx): {$coursesTouched}\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
