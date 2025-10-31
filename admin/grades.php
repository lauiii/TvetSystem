<?php
/**
 * Admin - View and Manage All Grades System-wide
 * Overview of all grades, filtering by course, student, instructor
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Filters
$course_id = intval($_GET['course_id'] ?? 0);
$student_id = intval($_GET['student_id'] ?? 0);
$instructor_id = intval($_GET['instructor_id'] ?? 0);

// Fetch filter options
$courses = $pdo->query("SELECT c.id, c.course_code, c.course_name, p.name as program_name FROM courses c LEFT JOIN programs p ON c.program_id = p.id ORDER BY c.course_code")->fetchAll();
$userCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $userCols = ['id','student_id','first_name','last_name','name','email','role'];
}

$studentSelect = ['id', 'student_id'];
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $studentSelect[] = 'first_name';
    $studentSelect[] = 'last_name';
} elseif (in_array('name', $userCols)) {
    $studentSelect[] = 'name';
}
$studentOrder = (in_array('last_name', $userCols) && in_array('first_name', $userCols)) ? 'last_name, first_name' : 'id';
$students = $pdo->query("SELECT " . implode(',', $studentSelect) . " FROM users WHERE role = 'student' ORDER BY $studentOrder")->fetchAll();

$instructorSelect = ['id'];
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $instructorSelect[] = 'first_name';
    $instructorSelect[] = 'last_name';
} elseif (in_array('name', $userCols)) {
    $instructorSelect[] = 'name';
}
$instructorOrder = (in_array('last_name', $userCols) && in_array('first_name', $userCols)) ? 'last_name, first_name' : 'id';
$instructors = $pdo->query("SELECT " . implode(',', $instructorSelect) . " FROM users WHERE role = 'instructor' ORDER BY $instructorOrder")->fetchAll();

// Build query for grades - adapt to available user columns
$studentNameExpr = '';
$instructorNameExpr = '';
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $studentNameExpr = "u_student.first_name as student_first, u_student.last_name as student_last";
    $instructorNameExpr = "u_instructor.first_name as instructor_first, u_instructor.last_name as instructor_last";
} elseif (in_array('name', $userCols)) {
    $studentNameExpr = "u_student.name as student_name";
    $instructorNameExpr = "u_instructor.name as instructor_name";
}

$query = "
    SELECT
        g.id as grade_id,
        g.grade,
        g.status,
        g.remarks,
        g.submitted_at,
        ai.id as assessment_id,
        ai.name as assessment_name,
        ac.percentage,
        ac.period as assessment_type,
        c.id as course_id,
        c.course_code,
        c.course_name,
        u_student.id as student_id,
        u_student.student_id as student_number,
        $studentNameExpr,
        u_instructor.id as instructor_id,
        $instructorNameExpr,
        p.name as program_name,
        sy.year as school_year,
        sy.semester
    FROM grades g
    INNER JOIN assessment_items ai ON g.assessment_id = ai.id
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    INNER JOIN courses c ON ac.course_id = c.id
    INNER JOIN enrollments e ON g.enrollment_id = e.id
    INNER JOIN users u_student ON e.student_id = u_student.id
    LEFT JOIN users u_instructor ON c.instructor_id = u_instructor.id
    LEFT JOIN programs p ON c.program_id = p.id
    LEFT JOIN school_years sy ON e.school_year_id = sy.id
    WHERE 1=1
";

$params = [];

if ($course_id > 0) {
    $query .= " AND c.id = ?";
    $params[] = $course_id;
}

if ($student_id > 0) {
    $query .= " AND u_student.id = ?";
    $params[] = $student_id;
}

if ($instructor_id > 0) {
    $query .= " AND c.instructor_id = ?";
    $params[] = $instructor_id;
}

$query .= " ORDER BY " . (in_array('last_name', $userCols) ? "u_student.last_name, u_student.first_name" : "u_student.id") . ", c.course_code, ai.name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Grades Overview - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'grades'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Grades Overview'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card">
                    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

                    <h3>Filter Grades</h3>
                    <form method="GET" class="filter-form">
                        <div class="filter-row">
                            <div class="filter-group">
                                <label>Course:</label>
                                <select name="course_id">
                                    <option value="">All Courses</option>
                                    <?php foreach ($courses as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $course_id == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Student:</label>
                                <select name="student_id">
                                    <option value="">All Students</option>
                                    <?php foreach ($students as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $student_id == $s['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            if (isset($s['first_name']) && isset($s['last_name'])) {
                                                echo htmlspecialchars($s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['student_id'] . ')');
                                            } elseif (isset($s['name'])) {
                                                echo htmlspecialchars($s['name'] . ' (' . $s['student_id'] . ')');
                                            } else {
                                                echo htmlspecialchars($s['student_id']);
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Instructor:</label>
                                <select name="instructor_id">
                                    <option value="">All Instructors</option>
                                    <?php foreach ($instructors as $i): ?>
                                        <option value="<?php echo $i['id']; ?>" <?php echo $instructor_id == $i['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            if (isset($i['first_name']) && isset($i['last_name'])) {
                                                echo htmlspecialchars($i['last_name'] . ', ' . $i['first_name']);
                                            } elseif (isset($i['name'])) {
                                                echo htmlspecialchars($i['name']);
                                            } else {
                                                echo htmlspecialchars('Instructor ' . $i['id']);
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <button class="btn primary" type="submit">Filter</button>
                                <a href="grades.php" class="btn">Clear</a>
                            </div>
                        </div>
                    </form>
                </div>

                <div style="height:20px"></div>
                <div class="card">
                    <h3>Grade Records (<?php echo count($grades); ?>)</h3>
                    <?php if (count($grades) === 0): ?>
                        <p>No grades found matching the criteria.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Course</th>
                                        <th>Assessment</th>
                                        <th>Grade</th>
                                        <th>Status</th>
                                        <th>Instructor</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grades as $grade): ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                <?php
                                                if (isset($grade['student_first']) && isset($grade['student_last'])) {
                                                    echo htmlspecialchars($grade['student_last'] . ', ' . $grade['student_first']);
                                                } elseif (isset($grade['student_name'])) {
                                                    echo htmlspecialchars($grade['student_name']);
                                                } else {
                                                    echo htmlspecialchars($grade['student_number']);
                                                }
                                                ?>
                                                </strong><br>
                                                <small><?php echo htmlspecialchars($grade['student_number']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($grade['course_code']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($grade['course_name']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($grade['assessment_name']); ?><br>
                                                <small><?php echo htmlspecialchars($grade['assessment_type'] . ' (' . $grade['percentage'] . '%)'); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($grade['grade'] !== null): ?>
                                                    <span class="grade-value"><?php echo htmlspecialchars($grade['grade']); ?>%</span>
                                                <?php else: ?>
                                                    <span class="no-grade">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-<?php echo $grade['status']; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($grade['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                if (isset($grade['instructor_first']) && isset($grade['instructor_last'])) {
                                                    echo htmlspecialchars($grade['instructor_last'] . ', ' . $grade['instructor_first']);
                                                } elseif (isset($grade['instructor_name'])) {
                                                    echo htmlspecialchars($grade['instructor_name']);
                                                } else {
                                                    echo '<em>Unassigned</em>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($grade['submitted_at']): ?>
                                                    <?php echo date('M d, Y H:i', strtotime($grade['submitted_at'])); ?>
                                                <?php else: ?>
                                                    <em>Not submitted</em>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <style>
        .filter-form {
            margin-top: 15px;
        }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .grades-table th,
        .grades-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .grades-table th {
            background: #6a0dad;
            color: white;
            font-weight: 600;
        }

        .grades-table tr:hover {
            background: #f8f9fa;
        }

        .grade-value {
            font-weight: bold;
            color: #28a745;
        }

        .no-grade {
            color: #999;
        }

        .status-complete {
            color: #28a745;
            font-weight: bold;
        }

        .status-incomplete {
            color: #ffc107;
        }

        .status-missing {
            color: #dc3545;
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                min-width: auto;
            }
        }
    </style>
</body>
</html>
