<?php
/**
 * Fixed Grade Management Page for Instructors
 * (Use this file for testing. Once verified, copy over manage-grades.php)
 */

require_once '../config.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$sectionId = intval($_GET['section_id'] ?? 0);
$success = '';
$error = '';

// verify access
$stmt = $pdo->prepare(
    "SELECT s.*, c.id as course_id, c.course_code, c.course_name, c.year_level, c.semester, p.name as program_name
    FROM sections s
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    INNER JOIN instructor_sections ins ON s.id = ins.section_id
    WHERE s.id = ? AND ins.instructor_id = ? AND s.status = 'active'"
);
$stmt->execute([$sectionId, $instructorId]);
$section = $stmt->fetch();
if (!$section) {
    header('Location: dashboard.php');
    exit;
}

// handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_grades'])) {
    try {
        $pdo->beginTransaction();
        if (!empty($_POST['grades']) && is_array($_POST['grades'])) {
            foreach ($_POST['grades'] as $enrollmentId => $assessments) {
                foreach ($assessments as $assessmentId => $gradeData) {
                    $gradeRaw = isset($gradeData['grade']) ? trim((string)$gradeData['grade']) : '';
                    if ($gradeRaw !== '') {
                        if (!is_numeric($gradeRaw)) throw new Exception("Invalid grade value for enrollment {$enrollmentId}, assessment {$assessmentId}");
                        $grade = floatval($gradeRaw);
                        $status = 'complete';
                    } else {
                        $grade = null;
                        $status = 'incomplete';
                    }

                    $stmt = $pdo->prepare("INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE grade = VALUES(grade), status = VALUES(status), submitted_at = NOW()");
                    $stmt->execute([$enrollmentId, $assessmentId, $grade, $status]);
                }
            }
        }
        $pdo->commit();
        $success = 'Grades submitted successfully (fixed)!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Failed to submit grades: ' . $e->getMessage();
    }
}

// load assessments and students
$stmt = $pdo->prepare("SELECT ai.id, ai.name, ai.total_score, ac.name as criteria_name, ac.period, ac.percentage FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id WHERE ac.course_id = ? ORDER BY ac.period, ai.name");
$stmt->execute([$section['course_id']]);
$assessments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT e.id as enrollment_id, u.id as student_id, u.student_id as student_number, u.first_name, u.last_name FROM enrollments e INNER JOIN users u ON e.student_id = u.id WHERE e.course_id = ? AND e.status = 'enrolled' ORDER BY u.last_name, u.first_name");
$stmt->execute([$section['course_id']]);
$students = $stmt->fetchAll();

// fetch existing grades
$grades = [];
if (count($students) > 0) {
    $enrollmentIds = array_column($students, 'enrollment_id');
    $placeholders = str_repeat('?,', count($enrollmentIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT enrollment_id, assessment_id, grade FROM grades WHERE enrollment_id IN ($placeholders)");
    $stmt->execute($enrollmentIds);
    foreach ($stmt->fetchAll() as $r) $grades[$r['enrollment_id']][$r['assessment_id']] = $r['grade'];
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Manage Grades (fixed)</title>
</head>
<body>
<h1>Manage Grades (fixed) - <?php echo htmlspecialchars($section['course_name']); ?></h1>
<?php if ($success): ?><div style="color:green"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if ($error): ?><div style="color:red"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<form method="post">
<table border="1" cellpadding="6">
<thead>
<tr><th>Student</th><?php foreach ($assessments as $a) echo "<th>".htmlspecialchars($a['name'])."</th>"; ?></tr>
</thead>
<tbody>
<?php foreach ($students as $s): ?>
<tr>
<td><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?><br><small><?php echo htmlspecialchars($s['student_number']); ?></small></td>
<?php foreach ($assessments as $a): $val = $grades[$s['enrollment_id']][$a['id']] ?? ''; ?>
<td><input type="number" name="grades[<?php echo $s['enrollment_id']; ?>][<?php echo $a['id']; ?>][grade]" min="0" max="<?php echo $a['total_score']; ?>" step="0.01" value="<?php echo htmlspecialchars($val); ?>"></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><button type="submit" name="submit_grades">Save All Grades</button></p>
</form>
</body>
</html>