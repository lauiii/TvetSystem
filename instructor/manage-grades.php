<?php
/**
 * Grade Management Page for Instructors
 * Submit and manage student grades
 */

require_once '../config.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$sectionId = intval($_GET['section_id'] ?? 0);
$success = '';
$error = '';

// Verify instructor has access to this section
$stmt = $pdo->prepare("
    SELECT s.*, c.id as course_id, c.course_code, c.course_name, c.year_level, c.semester, p.name as program_name
    FROM sections s
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    INNER JOIN instructor_sections ins ON s.id = ins.section_id
    WHERE s.id = ? AND ins.instructor_id = ? AND s.status = 'active'
");
$stmt->execute([$sectionId, $instructorId]);
$section = $stmt->fetch();

if (!$section) {
    header('Location: dashboard.php');
    exit;
}

// Handle grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_grades'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['grades'] as $enrollmentId => $assessments) {
            foreach ($assessments as $assessmentId => $gradeData) {
                $grade = !empty($gradeData['grade']) ? floatval($gradeData['grade']) : null;
                $status = !empty($gradeData['grade']) ? 'complete' : 'incomplete';

                // Insert or update grade
                $stmt = $pdo->prepare("
                    INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at)
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        grade = VALUES(grade),
                        status = VALUES(status),
                        submitted_at = NOW()
                ");
                $stmt->execute([$enrollmentId, $assessmentId, $grade, $status]);
            }
        }
        
        $pdo->commit();
        $success = 'Grades submitted successfully!';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Failed to submit grades: ' . $e->getMessage();
    }
}

// Fetch assessments for this course
$stmt = $pdo->prepare("
    SELECT ai.id, ai.name, ai.total_score, ac.name as criteria_name, ac.period, ac.percentage
    FROM assessment_items ai
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    WHERE ac.course_id = ?
    ORDER BY ac.period, ac.name, ai.name
");
$stmt->execute([$section['course_id']]);
$assessments = $stmt->fetchAll();

// Group assessments by criteria for table headers
$grouped_assessments = [];
foreach ($assessments as $assessment) {
    $criteria_name = $assessment['criteria_name'];
    $grouped_assessments[$criteria_name][] = $assessment;
}

// Fetch enrolled students with their grades for this section
$stmt = $pdo->prepare("
    SELECT
        e.id as enrollment_id,
        u.id as student_id,
        u.student_id as student_number,
        u.first_name,
        u.last_name,
        u.email
    FROM enrollments e
    INNER JOIN users u ON e.student_id = u.id
    WHERE e.course_id = ? AND e.status = 'enrolled'
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([$section['course_id']]);
$students = $stmt->fetchAll();

// Fetch existing grades
$grades = [];
if (count($students) > 0) {
    $enrollmentIds = array_column($students, 'enrollment_id');
    $placeholders = str_repeat('?,', count($enrollmentIds) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT enrollment_id, assessment_id, grade, status
        FROM grades
        WHERE enrollment_id IN ($placeholders)
    ");
    $stmt->execute($enrollmentIds);

    foreach ($stmt->fetchAll() as $row) {
        $grades[$row['enrollment_id']][$row['assessment_id']] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Grades - <?php echo htmlspecialchars($section['course_name']); ?> (<?php echo htmlspecialchars($section['section_code']); ?>)</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .page-header h1 {
            color: #6a0dad;
            margin-bottom: 10px;
        }
        
        .back-link {
            color: #6a0dad;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .grades-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .grades-table th {
            background: #6a0dad;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .grades-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .grades-table tr:hover {
            background: #f8f9fa;
        }
        
        .student-info {
            white-space: nowrap;
        }
        
        .grade-input {
            width: 80px;
            padding: 6px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .grade-input:focus {
            outline: none;
            border-color: #6a0dad;
        }
        
        .status-select {
            padding: 6px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .status-select:focus {
            outline: none;
            border-color: #6a0dad;
        }
        
        .remarks-input {
            width: 100%;
            padding: 6px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
            resize: vertical;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #6a0dad;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a0c9d;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 15px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <div class="page-header">
            <h1><?php echo htmlspecialchars($section['course_name']); ?> (<?php echo htmlspecialchars($section['section_code']); ?>)</h1>
            <p>
                <?php echo htmlspecialchars($section['course_code']); ?> •
                <?php echo htmlspecialchars($section['program_name']); ?> •
                Year <?php echo $section['year_level']; ?> •
                Semester <?php echo $section['semester']; ?> •
                <?php echo $section['enrolled_count']; ?>/<?php echo $section['capacity']; ?> students
            </p>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="grades-card">
            <div class="actions-bar">
                <h2 style="color: #6a0dad;">Grade Management</h2>
                <div>
                    <a href="assessments.php?course_id=<?php echo $section['course_id']; ?>" class="btn btn-secondary">
                        Manage Assessments
                    </a>
                </div>
            </div>
            
            <?php if (count($students) > 0 && count($assessments) > 0): ?>
                <form method="POST">
                    <div class="table-wrapper">
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <?php foreach ($grouped_assessments as $criteria_name => $criteria_assessments): ?>
                                        <?php foreach ($criteria_assessments as $index => $assessment): ?>
                                            <?php if ($index === 0): ?>
                                                <th colspan="<?php echo count($criteria_assessments); ?>" style="text-align: center; background: #5a0c9d;">
                                                    <?php echo htmlspecialchars($criteria_name); ?> (<?php echo $assessment['percentage']; ?>%)
                                                </th>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th></th>
                                    <?php foreach ($assessments as $assessment): ?>
                                        <th style="background: #7b1fa2;">
                                            <?php echo htmlspecialchars($assessment['name']); ?>
                                            <br>
                                            <small style="font-weight: normal; opacity: 0.8;">
                                                Max: <?php echo $assessment['total_score']; ?>
                                            </small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td class="student-info">
                                            <?php echo htmlspecialchars($student['student_number']); ?>
                                        </td>
                                        <td class="student-info">
                                            <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                        </td>
                                        <?php foreach ($assessments as $assessment): ?>
                                            <?php
                                                $enrollmentId = $student['enrollment_id'];
                                                $assessmentId = $assessment['id'];
                                                $existingGrade = $grades[$enrollmentId][$assessmentId] ?? null;
                                            ?>
                                            <td>
                                                <input
                                                    type="number"
                                                    name="grades[<?php echo $enrollmentId; ?>][<?php echo $assessmentId; ?>][grade]"
                                                    class="grade-input"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    value="<?php echo $existingGrade ? htmlspecialchars($existingGrade['grade']) : ''; ?>"
                                                    placeholder="0-100"
                                                >
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 30px; text-align: right;">
                        <button type="submit" name="submit_grades" class="btn btn-primary">
                            💾 Save All Grades
                        </button>
                    </div>
                </form>
            <?php elseif (count($assessments) === 0): ?>
                <div class="empty-state">
                    <h3>No Assessments Created</h3>
                    <p>Please create assessments first before entering grades.</p>
                    <a href="assessments.php?course_id=<?php echo $section['course_id']; ?>" class="btn btn-primary" style="margin-top: 20px;">
                        Create Assessments
                    </a>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No Students Enrolled</h3>
                    <p>There are no students enrolled in this course yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>