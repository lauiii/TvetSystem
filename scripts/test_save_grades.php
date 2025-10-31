<?php
// Test script to simulate saving grades to DB for a section.
// Usage (PowerShell): php scripts\test_save_grades.php --section=5

$options = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', $arg, 2);
        $key = ltrim($parts[0], '-');
        $val = isset($parts[1]) ? $parts[1] : true;
        $options[$key] = $val;
    }
}

require_once __DIR__ . '/../config.php';

$sectionId = isset($options['section']) ? intval($options['section']) : 0;
if ($sectionId <= 0) {
    echo "Please provide a section id: php scripts\\test_save_grades.php --section=<id>\n";
    exit(1);
}

try {
    // Verify section and course
    $stmt = $pdo->prepare("SELECT s.*, c.id as course_id FROM sections s INNER JOIN courses c ON s.course_id = c.id WHERE s.id = ?");
    $stmt->execute([$sectionId]);
    $sec = $stmt->fetch();
    if (!$sec) {
        echo "Section not found: {$sectionId}\n";
        exit(1);
    }

    $courseId = $sec['course_id'];

    // Fetch first two assessments
    $stmt = $pdo->prepare("SELECT ai.id, ai.name, ai.total_score FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id WHERE ac.course_id = ? ORDER BY ac.period, ai.name LIMIT 3");
    $stmt->execute([$courseId]);
    $assessments = $stmt->fetchAll();
    if (count($assessments) == 0) {
        echo "No assessments for course {$courseId}\n";
        exit(1);
    }

    // Fetch first two enrolled students
    $stmt = $pdo->prepare("SELECT e.id as enrollment_id, u.first_name, u.last_name FROM enrollments e INNER JOIN users u ON e.student_id = u.id WHERE e.course_id = ? AND e.status = 'enrolled' ORDER BY u.last_name, u.first_name LIMIT 5");
    $stmt->execute([$courseId]);
    $students = $stmt->fetchAll();
    if (count($students) == 0) {
        echo "No students enrolled for course {$courseId}\n";
        exit(1);
    }

    // Build payload: give each student sample grades for first two assessments
    $payload = [];
    foreach ($students as $si => $s) {
        $enrollId = $s['enrollment_id'];
        $payload[$enrollId] = [];
        foreach ($assessments as $ai => $a) {
            // assign 0 for first student to test zero handling, otherwise sample scores
            $val = ($si == 0) ? 0 : (10 + 5 * $ai);
            $payload[$enrollId][$a['id']] = ['grade' => $val];
        }
    }

    // Validate assessment IDs belong to course
    $assessmentIds = array_map(function($a){ return $a['id']; }, $assessments);
    $validAssessMap = array_flip($assessmentIds);

    // Insert using transaction
    $pdo->beginTransaction();
    $insertStmt = $pdo->prepare("INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE grade = VALUES(grade), status = VALUES(status), submitted_at = NOW()");

    foreach ($payload as $enrollId => $assessList) {
        // optional: check enrollment belongs to course
        foreach ($assessList as $aid => $gdata) {
            if (!isset($validAssessMap[$aid])) {
                throw new Exception("Invalid assessment id: {$aid}");
            }
            $gradeRaw = isset($gdata['grade']) ? trim((string)$gdata['grade']) : '';
            if ($gradeRaw !== '') {
                if (!is_numeric($gradeRaw)) throw new Exception("Invalid grade value for enrollment {$enrollId}, assessment {$aid}");
                $grade = floatval($gradeRaw);
                $status = 'complete';
            } else {
                $grade = null;
                $status = 'incomplete';
            }
            $insertStmt->execute([$enrollId, $aid, $grade, $status]);
        }
    }
    $pdo->commit();

    echo "Inserted/updated grades for section {$sectionId}.\n";

    // Print current grades for the students we touched
    $enrollIds = array_keys($payload);
    $placeholders = str_repeat('?,', count($enrollIds)-1) . '?';
    $stmt = $pdo->prepare("SELECT enrollment_id, assessment_id, grade, submitted_at FROM grades WHERE enrollment_id IN ($placeholders)");
    $stmt->execute($enrollIds);
    $rows = $stmt->fetchAll();
    echo "Affected rows:\n";
    foreach ($rows as $r) {
        echo "enrollment={$r['enrollment_id']} assessment={$r['assessment_id']} grade={$r['grade']} submitted_at={$r['submitted_at']}\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    // write to log
    @file_put_contents(__DIR__ . '/../logs/grades_errors.log', date('c') . " | test_save_grades error: " . $e->getMessage() . "\n", FILE_APPEND);
    exit(1);
}
