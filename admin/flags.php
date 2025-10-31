<?php
/**
 * Admin - Manage Student Flags
 * View, resolve, and add flags for students
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Handle add flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $issue = sanitize($_POST['issue'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if (!$student_id || !$course_id || empty($issue)) {
        $error = 'Student, course, and issue are required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO flags (student_id, course_id, issue, description, flagged_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$student_id, $course_id, $issue, $description, $_SESSION['user_id']]);
            $msg = 'Flag added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add flag: ' . $e->getMessage();
        }
    }
}

// Handle resolve flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE flags SET status = ? WHERE id = ?');
            $stmt->execute(['resolved', $id]);
            $msg = 'Flag resolved.';
        } catch (Exception $e) {
            $error = 'Failed to resolve flag: ' . $e->getMessage();
        }
    }
}

// Handle delete flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM flags WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Flag deleted.';
        } catch (Exception $e) {
            $error = 'Failed to delete flag: ' . $e->getMessage();
        }
    }
}

// Fetch flags with related data - adapt to available user columns
$userCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $userCols = ['id','student_id','first_name','last_name','name','email','role'];
}

$studentNameExpr = '';
$flaggerNameExpr = '';
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $studentNameExpr = "u_student.first_name as student_first, u_student.last_name as student_last";
    $flaggerNameExpr = "u_flagger.first_name as flagger_first, u_flagger.last_name as flagger_last";
} elseif (in_array('name', $userCols)) {
    $studentNameExpr = "u_student.name as student_name";
    $flaggerNameExpr = "u_flagger.name as flagger_name";
}

$flags = $pdo->query("
    SELECT
        f.*,
        $studentNameExpr,
        u_student.student_id as student_number,
        c.course_code,
        c.course_name,
        $flaggerNameExpr
    FROM flags f
    INNER JOIN users u_student ON f.student_id = u_student.id
    INNER JOIN courses c ON f.course_id = c.id
    LEFT JOIN users u_flagger ON f.flagged_by = u_flagger.id
    ORDER BY f.status ASC, f.created_at DESC
")->fetchAll();

// Fetch students and courses for add form - adapt to available user columns
$studentSelect = ['id', 'student_id'];
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $studentSelect[] = 'first_name';
    $studentSelect[] = 'last_name';
} elseif (in_array('name', $userCols)) {
    $studentSelect[] = 'name';
}
$studentOrder = (in_array('last_name', $userCols) && in_array('first_name', $userCols)) ? 'last_name, first_name' : 'id';
$students = $pdo->query("SELECT " . implode(',', $studentSelect) . " FROM users WHERE role = 'student' ORDER BY $studentOrder")->fetchAll();
$courses = $pdo->query("SELECT c.id, c.course_code, c.course_name, p.name as program_name FROM courses c LEFT JOIN programs p ON c.program_id = p.id ORDER BY c.course_code")->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Flags - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'flags'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Student Flags'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card">
                    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

                    <h3>Add New Flag</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="form-row">
                            <label>Student</label><br>
                            <select name="student_id" required>
                                <option value="">-- Select Student --</option>
                                <?php foreach ($students as $s): ?>
                                    <option value="<?php echo $s['id']; ?>">
                                        <?php
                                        $studentName = '';
                                        if (isset($s['first_name']) && isset($s['last_name'])) {
                                            $studentName = $s['last_name'] . ', ' . $s['first_name'];
                                        } elseif (isset($s['name'])) {
                                            $studentName = $s['name'];
                                        }
                                        echo htmlspecialchars($studentName . ' (' . $s['student_id'] . ')');
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label>Course</label><br>
                            <select name="course_id" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label>Issue</label><br>
                            <input type="text" name="issue" required placeholder="e.g., Academic dishonesty, Attendance issues">
                        </div>
                        <div class="form-row">
                            <label>Description</label><br>
                            <textarea name="description" rows="3" placeholder="Detailed description of the issue..."></textarea>
                        </div>
                        <button class="btn primary">Add Flag</button>
                    </form>
                </div>

                <div style="height:20px"></div>
                <div class="card">
                    <h3>Existing Flags (<?php echo count($flags); ?>)</h3>
                    <?php if (count($flags) === 0): ?>
                        <p>No flags found.</p>
                    <?php else: ?>
                        <?php foreach ($flags as $flag): ?>
                            <div class="flag-item <?php echo $flag['status'] === 'resolved' ? 'resolved' : 'pending'; ?>">
                                <div class="flag-header">
                                    <div class="flag-info">
                                        <strong><?php
                                        $studentName = '';
                                        if (isset($flag['student_first']) && isset($flag['student_last'])) {
                                            $studentName = $flag['student_last'] . ', ' . $flag['student_first'];
                                        } elseif (isset($flag['student_name'])) {
                                            $studentName = $flag['student_name'];
                                        }
                                        echo htmlspecialchars($studentName);
                                        ?></strong>
                                        <span class="student-id">(<?php echo htmlspecialchars($flag['student_number']); ?>)</span>
                                        <span class="course"><?php echo htmlspecialchars($flag['course_code'] . ' - ' . $flag['course_name']); ?></span>
                                    </div>
                                    <div class="flag-actions">
                                        <span class="flag-status status-<?php echo $flag['status']; ?>">
                                            <?php echo htmlspecialchars(ucfirst($flag['status'])); ?>
                                        </span>
                                        <?php if ($flag['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline-block;margin-left:8px">
                                                <input type="hidden" name="action" value="resolve">
                                                <input type="hidden" name="id" value="<?php echo $flag['id']; ?>">
                                                <button class="btn small">Resolve</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline-block;margin-left:8px" onsubmit="return confirm('Delete this flag?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $flag['id']; ?>">
                                            <button class="btn small danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="flag-details">
                                    <div class="flag-issue">
                                        <strong>Issue:</strong> <?php echo htmlspecialchars($flag['issue']); ?>
                                    </div>
                                    <?php if (!empty($flag['description'])): ?>
                                        <div class="flag-description">
                                            <strong>Description:</strong> <?php echo htmlspecialchars($flag['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flag-meta">
                                        <small>
                                            Flagged by: <?php
                                            $flaggerName = '';
                                            if (isset($flag['flagger_first']) && isset($flag['flagger_last'])) {
                                                $flaggerName = $flag['flagger_last'] . ', ' . $flag['flagger_first'];
                                            } elseif (isset($flag['flagger_name'])) {
                                                $flaggerName = $flag['flagger_name'];
                                            }
                                            echo htmlspecialchars($flaggerName);
                                            ?> |
                                            Created: <?php echo date('M d, Y H:i', strtotime($flag['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <style>
        .form-row {
            margin-bottom: 15px;
        }

        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-row select,
        .form-row input,
        .form-row textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .flag-item {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            background: white;
        }

        .flag-item.pending {
            border-left: 4px solid #ffc107;
        }

        .flag-item.resolved {
            border-left: 4px solid #28a745;
            opacity: 0.7;
        }

        .flag-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .flag-info strong {
            font-size: 16px;
            color: #333;
        }

        .student-id {
            color: #666;
            margin-left: 8px;
        }

        .course {
            display: block;
            color: #6a0dad;
            font-weight: 500;
            margin-top: 2px;
        }

        .flag-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-resolved {
            background: #d4edda;
            color: #155724;
        }

        .flag-details {
            font-size: 14px;
        }

        .flag-issue,
        .flag-description {
            margin-bottom: 8px;
        }

        .flag-meta {
            color: #666;
            border-top: 1px solid #f0f0f0;
            padding-top: 8px;
            margin-top: 8px;
        }

        .btn.small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn.danger {
            background: #e74c3c;
            color: white;
        }

        .btn.danger:hover {
            background: #c0392b;
        }

        @media (max-width: 768px) {
            .flag-header {
                flex-direction: column;
                gap: 10px;
            }

            .flag-actions {
                align-self: flex-end;
            }
        }
    </style>
</body>
</html>
