<?php
/**
 * Instructor - Manage Flags
 * Instructors can add, view, resolve, and delete flags for their own courses/students
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Detect available name columns in users table
try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Exception $e) {
    $userCols = ['id','student_id','first_name','last_name'];
}
$hasFirst = in_array('first_name', $userCols);
$hasLast = in_array('last_name', $userCols);
$hasName = in_array('name', $userCols);

// Verify course belongs to instructor
function course_owned_by_instructor(PDO $pdo, $course_id, $instructorId) {
    $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE id = ? AND instructor_id = ? LIMIT 1');
    $stmt->execute([$course_id, $instructorId]);
    return (bool)$stmt->fetchColumn();
}

// Verify flag belongs to instructor (via course)
function flag_owned_by_instructor(PDO $pdo, $flag_id, $instructorId) {
    $stmt = $pdo->prepare('SELECT 1 FROM flags f INNER JOIN courses c ON f.course_id = c.id WHERE f.id = ? AND c.instructor_id = ? LIMIT 1');
    $stmt->execute([$flag_id, $instructorId]);
    return (bool)$stmt->fetchColumn();
}

// Add flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $issue = sanitize($_POST['issue'] ?? '');
    $description = sanitize($_POST['description'] ?? '');

    if (!$student_id || !$course_id || empty($issue)) {
        $error = 'Student, course, and issue are required.';
    } elseif (!course_owned_by_instructor($pdo, $course_id, $instructorId)) {
        $error = 'You are not assigned to this course.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO flags (student_id, course_id, issue, description, flagged_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$student_id, $course_id, $issue, $description, $instructorId]);
        $msg = 'Flag added successfully.';
    }
}

// Resolve flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id && flag_owned_by_instructor($pdo, $id, $instructorId)) {
        $stmt = $pdo->prepare('UPDATE flags SET status = ? WHERE id = ?');
        $stmt->execute(['resolved', $id]);
        $msg = 'Flag resolved.';
    }
}

// Delete flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id && flag_owned_by_instructor($pdo, $id, $instructorId)) {
        $stmt = $pdo->prepare('DELETE FROM flags WHERE id = ?');
        $stmt->execute([$id]);
        $msg = 'Flag deleted.';
    }
}

// Fetch instructor courses
$courses = $pdo->prepare('SELECT id, course_code, course_name FROM courses WHERE instructor_id = ? ORDER BY course_code');
$courses->execute([$instructorId]);
$courses = $courses->fetchAll();

// Fetch students enrolled in instructor courses (current active school year if available)
$activeSY = $pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$syId = $activeSY['id'] ?? null;

$nameSelect = '';
if ($hasFirst && $hasLast) { $nameSelect = ', u.first_name, u.last_name'; }
elseif ($hasName) { $nameSelect = ', u.name'; }
$studentsSql = "SELECT DISTINCT u.id, u.student_id" . $nameSelect . "
    FROM users u
    INNER JOIN enrollments e ON u.id = e.student_id
    INNER JOIN courses c ON e.course_id = c.id
    " . ($syId ? "WHERE e.school_year_id = ? AND c.instructor_id = ?" : "WHERE c.instructor_id = ?") . "
    ORDER BY " . (($hasLast && $hasFirst) ? "u.last_name, u.first_name" : "u.id") . "";
$studentsStmt = $pdo->prepare($studentsSql);
$studentsStmt->execute($syId ? [$syId, $instructorId] : [$instructorId]);
$students = $studentsStmt->fetchAll();

// Fetch flags for instructor courses
$flagNameSelect = '';
if ($hasFirst && $hasLast) { $flagNameSelect = ', u_student.first_name as sf, u_student.last_name as sl'; }
if ($hasName) { $flagNameSelect .= ', u_student.name as sn'; }
$flagsStmt = $pdo->prepare("SELECT f.*, u_student.student_id as student_number" . $flagNameSelect . ",
    c.course_code, c.course_name
    FROM flags f
    INNER JOIN users u_student ON f.student_id = u_student.id
    INNER JOIN courses c ON f.course_id = c.id
    WHERE c.instructor_id = ?
    ORDER BY f.status ASC, f.created_at DESC");
$flagsStmt->execute([$instructorId]);
$flags = $flagsStmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Flags</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;background:#f5f6fa;color:#333;margin:0}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    h1{color:#6a0dad}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:14px 0}
    .form-row{margin:8px 0}
    .btn{background:#6a0dad;color:#fff;border:none;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
    .flag-item{border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:10px 0;display:flex;justify-content:space-between;align-items:center}
    .flag-item.resolved{opacity:.8}
    .flag-info .course{margin-left:8px;color:#6a0dad}
    .flag-actions form{display:inline}
    select,input,textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px}
    .ok{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:8px;border-radius:6px}
    .err{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:8px;border-radius:6px}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>My Flags</h1>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="card">
      <h3>Add New Flag</h3>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
          <label>Student</label>
          <select name="student_id" required>
            <option value="">-- Select Student --</option>
            <?php foreach ($students as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>">
                <?php
                  $nm = '';
                  if (isset($s['first_name']) && isset($s['last_name'])) { $nm = $s['last_name'] . ', ' . $s['first_name']; }
                  elseif (isset($s['name'])) { $nm = $s['name']; }
                  echo htmlspecialchars($nm . ' (' . $s['student_id'] . ')');
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>Course</label>
          <select name="course_id" required>
            <option value="">-- Select Course --</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>Issue</label>
          <input type="text" name="issue" required>
        </div>
        <div class="form-row">
          <label>Description</label>
          <textarea name="description" rows="3"></textarea>
        </div>
        <button class="btn" type="submit">Add Flag</button>
      </form>
    </div>

    <div class="card">
      <h3>Existing Flags (<?php echo count($flags); ?>)</h3>
      <?php if (!count($flags)): ?>
        <p>No flags found.</p>
      <?php else: foreach ($flags as $flag): ?>
        <div class="flag-item <?php echo $flag['status']==='resolved' ? 'resolved' : 'pending'; ?>">
          <div class="flag-info">
            <strong>
              <?php
                $nm = '';
                if (!empty($flag['sl']) && !empty($flag['sf'])) { $nm = $flag['sl'] . ', ' . $flag['sf']; }
                elseif (!empty($flag['sn'])) { $nm = $flag['sn']; }
                echo htmlspecialchars($nm);
              ?>
            </strong>
            <span class="student-id">(<?php echo htmlspecialchars($flag['student_number']); ?>)</span>
            <span class="course"><?php echo htmlspecialchars($flag['course_code'] . ' - ' . $flag['course_name']); ?></span>
            <div style="color:#555;margin-top:6px">Issue: <strong><?php echo htmlspecialchars($flag['issue']); ?></strong> — <?php echo htmlspecialchars($flag['description']); ?></div>
          </div>
          <div class="flag-actions">
            <span style="margin-right:8px;">Status: <strong><?php echo htmlspecialchars(ucfirst($flag['status'])); ?></strong></span>
            <?php if ($flag['status'] === 'pending'): ?>
              <form method="POST"><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?php echo (int)$flag['id']; ?>"><button class="btn" style="background:#28a745">Resolve</button></form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Delete this flag?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$flag['id']; ?>"><button class="btn" style="background:#dc3545">Delete</button></form>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</body>
</html>
