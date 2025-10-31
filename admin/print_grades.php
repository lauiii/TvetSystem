<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$studentId = intval($_GET['student_id'] ?? 0);
$scope = $_GET['scope'] ?? 'current'; // current | specific | all
$schoolYearId = intval($_GET['school_year_id'] ?? 0);

if ($studentId <= 0) { die('Invalid student'); }

// Load student
$stu = $pdo->prepare("SELECT id, student_id, first_name, last_name, program_id FROM users WHERE id = ?");
$stu->execute([$studentId]);
$student = $stu->fetch(PDO::FETCH_ASSOC);
if (!$student) { die('Student not found'); }

// Resolve scope
if ($scope === 'current') {
    $sy = $pdo->query("SELECT id, year, semester FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $schoolYearId = $sy['id'] ?? 0;
}

$params = [$studentId];
$where = "e.student_id = ?";
if ($scope === 'current' && $schoolYearId) {
    $where .= " AND e.school_year_id = ?"; $params[] = $schoolYearId;
} elseif ($scope === 'specific' && $schoolYearId) {
    $where .= " AND e.school_year_id = ?"; $params[] = $schoolYearId;
}

$stmt = $pdo->prepare("SELECT c.course_code, c.course_name, ac.period, g.grade, g.status, u.first_name as instr_first, u.last_name as instr_last,
    sy.year, sy.semester
    FROM grades g
    INNER JOIN assessment_items ai ON g.assessment_id = ai.id
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    INNER JOIN enrollments e ON g.enrollment_id = e.id
    INNER JOIN courses c ON e.course_id = c.id
    LEFT JOIN users u ON c.instructor_id = u.id
    LEFT JOIN school_years sy ON e.school_year_id = sy.id
    WHERE $where
    ORDER BY sy.year DESC, sy.semester DESC, c.course_code, FIELD(ac.period,'prelim','midterm','finals')");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Grades</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial; color:#222; }
        .hdr { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #9b25e7; padding-bottom:8px; margin-bottom:12px; }
        h1 { margin:0; color:#9b25e7; font-size:20px; }
        .meta { color:#555; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { padding:8px 10px; border:1px solid #e5e7eb; }
        th { background:#9b25e7; color:#fff; }
        .controls { margin-top:10px; }
        .controls a { margin-right:6px; text-decoration:none; color:#9b25e7; }
        @media print { .controls { display:none; } }
    </style>
</head>
<body>
    <div class="hdr">
        <h1>Student Grades</h1>
        <div class="meta">
            Student: <strong><?php echo htmlspecialchars(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '')); ?></strong>
            &nbsp;•&nbsp; ID: <?php echo htmlspecialchars($student['student_id'] ?? ''); ?>
        </div>
    </div>
    <div class="controls">
        <a href="?student_id=<?php echo $studentId; ?>&scope=current">Current Semester</a>
        <a href="?student_id=<?php echo $studentId; ?>&scope=all">All Records</a>
    </div>
    <table>
        <thead>
            <tr><th>Subject</th><th>Period</th><th>Grade</th><th>Instructor</th><th>Status</th><th>School Year</th></tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" style="text-align:center; color:#666;">No records found.</td></tr>
            <?php else: foreach ($rows as $r): $instr = trim(($r['instr_last'] ?? '') . ', ' . ($r['instr_first'] ?? '')); ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($r['course_code']); ?></strong><br><small><?php echo htmlspecialchars($r['course_name']); ?></small></td>
                    <td><?php echo htmlspecialchars(ucfirst($r['period'])); ?></td>
                    <td><?php echo $r['grade'] !== null ? htmlspecialchars($r['grade']) : '<em>-</em>'; ?></td>
                    <td><?php echo $instr !== ',' ? htmlspecialchars($instr) : '—'; ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($r['status'])); ?></td>
                    <td><?php echo htmlspecialchars(($r['year'] ?? '') . ' S' . ($r['semester'] ?? '')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <script>window.focus();</script>
</body>
</html>


