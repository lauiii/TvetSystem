<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = (int)($_SESSION['user_id'] ?? 0);
$sectionId = (int)($_GET['section_id'] ?? 0);
$period = strtolower(trim((string)($_GET['period'] ?? '')));
if (!in_array($period, ['prelim','midterm','finals'], true)) { die('Invalid period'); }
if ($sectionId <= 0) { die('Invalid section'); }

// Verify access to section
$secStmt = $pdo->prepare(
    "SELECT s.*, c.id AS course_id, c.course_code, c.course_name, c.semester, c.year_level, p.name AS program_name, s.section_code
     FROM sections s
     INNER JOIN courses c ON s.course_id = c.id
     LEFT JOIN programs p ON c.program_id = p.id
     INNER JOIN instructor_sections ins ON s.id = ins.section_id
     WHERE s.id = ? AND ins.instructor_id = ?"
);
$secStmt->execute([$sectionId, $instructorId]);
$section = $secStmt->fetch(PDO::FETCH_ASSOC);
if (!$section) { die('Access denied or section not found'); }

// Resolve instructor name
$instructorName = '';
try {
    $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('first_name', $ucols) && in_array('last_name', $ucols)) {
        $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) AS nm FROM users WHERE id = ? LIMIT 1");
        $st->execute([$instructorId]);
        $instructorName = trim((string)$st->fetchColumn());
    } elseif (in_array('name', $ucols)) {
        $st = $pdo->prepare("SELECT name AS nm FROM users WHERE id = ? LIMIT 1");
        $st->execute([$instructorId]);
        $instructorName = trim((string)$st->fetchColumn());
    }
} catch (Exception $e) { /* ignore */ }

// Fetch assessments for the requested period (scoped to this section)
$aiStmt = $pdo->prepare(
    "SELECT ai.id, ai.total_score
     FROM assessment_items ai
     INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
     WHERE ac.section_id = ? AND ac.period = ?"
);
$aiStmt->execute([$sectionId, $period]);
$assessments = $aiStmt->fetchAll(PDO::FETCH_ASSOC);
$totalPossible = 0.0;
$assessmentIds = [];
foreach ($assessments as $a) { $totalPossible += (float)$a['total_score']; $assessmentIds[] = (int)$a['id']; }

// Fetch enrolled students for this course
$studentsStmt = $pdo->prepare(
    "SELECT e.id AS enrollment_id, u.id AS student_id, u.student_id AS student_number, u.first_name, u.last_name
     FROM enrollments e
     INNER JOIN users u ON e.student_id = u.id
     WHERE e.course_id = ? AND e.status = 'enrolled'
     ORDER BY u.last_name, u.first_name"
);
$studentsStmt->execute([(int)$section['course_id']]);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Load grades for these enrollments and period's assessment items
$gradesMap = [];
if (!empty($students) && !empty($assessmentIds)) {
    $enrollIds = array_column($students, 'enrollment_id');
    $enPlace = implode(',', array_fill(0, count($enrollIds), '?'));
    $aiPlace = implode(',', array_fill(0, count($assessmentIds), '?'));
    $gStmt = $pdo->prepare(
        "SELECT enrollment_id, assessment_id, grade
         FROM grades
         WHERE enrollment_id IN ($enPlace) AND assessment_id IN ($aiPlace)"
    );
    $gStmt->execute(array_merge($enrollIds, $assessmentIds));
    foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $eid = (int)$g['enrollment_id'];
        $aid = (int)$g['assessment_id'];
        $gradesMap[$eid][$aid] = $g['grade'] !== null ? (float)$g['grade'] : null;
    }
}

// Compute per-student period percent, LEE, and remarks
$rows = [];
foreach ($students as $st) {
    $eid = (int)$st['enrollment_id'];
    $earned = 0.0; $seenPossible = 0.0; $complete = true;
    foreach ($assessments as $a) {
        $aid = (int)$a['id'];
        $max = (float)$a['total_score'];
        $g = $gradesMap[$eid][$aid] ?? null;
        if ($g !== null) { $earned += (float)$g; $seenPossible += $max; }
        else { $complete = false; }
    }
    if ($totalPossible <= 0) {
        $percent = null; $complete = false;
    } else {
        $percent = ($seenPossible >= $totalPossible && $complete) ? min(99, ($earned / $totalPossible) * 100.0) : null;
    }
    $lee = ($percent === null) ? null : lee_from_percent($percent);
    // Remarks: Incomplete if not complete; otherwise Passed/Failed by percent
    if ($percent === null) { $remarks = 'Incomplete'; $cls = 'text-secondary'; }
    else {
        if ($percent >= 75.0) { $remarks = 'Passed'; $cls = 'text-success'; }
        else { $remarks = 'Failed'; $cls = 'text-danger'; }
    }
    $rows[] = [
        'student' => trim(($st['last_name'] ?? '') . ', ' . ($st['first_name'] ?? '')),
        'student_number' => (string)($st['student_number'] ?? ''),
        'lee' => $lee,
        'remarks' => $remarks,
        'cls' => $cls
    ];
}

// Header labels
$periodLabel = ucfirst($period);
$courseLabel = trim(($section['course_code'] ?? '') . ' — ' . ($section['course_name'] ?? ''));
$sectionCode = (string)($section['section_code'] ?? '');

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print <?php echo htmlspecialchars($periodLabel); ?> Grades — <?php echo htmlspecialchars($courseLabel); ?></title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial; color:#222; }
        .school-name { text-align:center; font-weight:800; font-size:22px; letter-spacing:0.04em; margin-top:4px; }
        .school-address { text-align:center; color:#555; font-size:13px; margin-top:2px; }
        .title { text-align:center; font-weight:700; letter-spacing:0.20em; text-transform:uppercase; margin:16px 0 18px; color:#333; }
        .meta { margin:10px 0 14px; }
        .meta div { margin:3px 0; }
        table { width:100%; border-collapse:collapse; }
        th { background:#9b25e7; color:#fff; }
        table, th, td { border:1px solid #e5e7eb; }
        th, td { padding:8px 10px; }
        .text-success { color:#28a745; }
        .text-danger { color:#dc3545; }
        .text-secondary { color:#6c757d; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="school-name">ANDRES SORIANO COLLEGES OF BISLIG</div>
    <div class="school-address">Mangagoy, Bislig City</div>
    <div class="title"><?php echo htmlspecialchars($periodLabel); ?> Grades</div>

    <div class="meta">
        <div><strong>Course:</strong> <?php echo htmlspecialchars($courseLabel); ?></div>
        <div><strong>Section:</strong> <?php echo htmlspecialchars($sectionCode); ?></div>
        <div><strong>Instructor:</strong> <?php echo htmlspecialchars($instructorName ?: ''); ?></div>
    </div>

    <?php if ($totalPossible <= 0): ?>
        <div style="padding:12px; color:#666;">No assessments defined for this period.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left;">Student</th>
                    <th style="text-align:center;">Student #</th>
                    <th style="text-align:center;">Grade</th>
                    <th style="text-align:center;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['student']); ?></td>
                        <td style="text-align:center;">&nbsp;<?php echo htmlspecialchars($r['student_number']); ?></td>
                        <td style="text-align:center;">&nbsp;<?php echo $r['lee']===null ? '' : htmlspecialchars(number_format($r['lee'],2)); ?></td>
                        <td style="text-align:center;" class="<?php echo $r['cls']; ?>"><?php echo htmlspecialchars($r['remarks']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="no-print" style="margin-top:10px;">
        <button onclick="window.print()" style="padding:6px 12px; background:#9b25e7; color:#fff; border:none; border-radius:6px;">Print</button>
    </div>
</body>
</html>
