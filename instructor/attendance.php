<?php
/**
 * Instructor Attendance Page
 * - Create/Open session (per section, per date)
 * - Mark statuses for enrolled students
 * - Bulk actions and CSV export
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$sectionId = (int)($_GET['section_id'] ?? 0);
$today = date('Y-m-d');
$error = '';
$msg = '';

// Verify instructor has access to this section
$secStmt = $pdo->prepare(
    "SELECT s.*, c.id AS course_id, c.course_code, c.course_name, p.name AS program_name, s.section_code
     FROM sections s
     INNER JOIN courses c ON s.course_id = c.id
     INNER JOIN programs p ON c.program_id = p.id
     INNER JOIN instructor_sections ins ON ins.section_id = s.id
     WHERE s.id = ? AND ins.instructor_id = ? AND s.status = 'active'"
);
$secStmt->execute([$sectionId, $instructorId]);
$section = $secStmt->fetch();
if (!$section) {
    header('Location: dashboard.php');
    exit;
}

// Get or create session for a given date
$sessionDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) { $sessionDate = $today; }

// Handle create/open session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_session') {
    $sessionDate = $_POST['session_date'] ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) { $sessionDate = $today; }
    try {
        // Prefer reusing existing session id (even if closed) to avoid creating new rows
        $chk = $pdo->prepare("SELECT id, status FROM attendance_sessions WHERE section_id = ? AND session_date = ? LIMIT 1");
        $chk->execute([$sectionId, $sessionDate]);
        $exists = $chk->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            if (($exists['status'] ?? 'open') === 'closed') {
                $pdo->prepare("UPDATE attendance_sessions SET status = 'open' WHERE id = ?")->execute([$exists['id']]);
                $msg = 'Session reopened for ' . htmlspecialchars($sessionDate);
            } else {
                $msg = 'Session is already open for ' . htmlspecialchars($sessionDate);
            }
        } else {
            // Fall back to insert; if unique key exists it will enforce one per date
            $stmt = $pdo->prepare("INSERT INTO attendance_sessions (section_id, session_date) VALUES (?, ?)");
            $stmt->execute([$sectionId, $sessionDate]);
            $msg = 'Session ready for ' . htmlspecialchars($sessionDate);
        }
    } catch (Exception $e) {
        $error = 'Failed to open session: ' . $e->getMessage();
    }
}

// Locate current session (if exists)
$sesStmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE section_id = ? AND session_date = ? LIMIT 1");
$sesStmt->execute([$sectionId, $sessionDate]);
$sessionRow = $sesStmt->fetch();
$sessionId = $sessionRow['id'] ?? null;

// Export CSV
if (isset($_GET['export']) && $_GET['export'] == '1' && $sessionId) {
    // Fetch students + records
    $st = $pdo->prepare(
        "SELECT u.student_id AS student_number, u.last_name, u.first_name, ar.status, ar.notes
         FROM enrollments e
         INNER JOIN users u ON u.id = e.student_id
         LEFT JOIN attendance_records ar ON ar.enrollment_id = e.id AND ar.session_id = ?
         WHERE e.course_id = ? AND e.status = 'enrolled'
         ORDER BY u.last_name, u.first_name"
    );
    $st->execute([$sessionId, $section['course_id']]);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $section['section_code'] . '_' . $sessionDate . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student Number', 'Last Name', 'First Name', 'Status', 'Notes']);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['student_number'],
            $r['last_name'],
            $r['first_name'],
            $r['status'] ?? '',
            $r['notes'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Save attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    if (!$sessionId) {
        $error = 'Open a session first.';
    } else {
        $records = $_POST['att'] ?? [];
        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO attendance_records (session_id, enrollment_id, status, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), marked_at = NOW()");

            foreach ($records as $enrollId => $data) {
                $enrollId = (int)$enrollId;
                $status = $data['status'] ?? 'present';
                if (!in_array($status, ['present','absent','late','excused'], true)) { $status = 'present'; }
                $notes = trim($data['notes'] ?? '');
                $ins->execute([$sessionId, $enrollId, $status, $notes]);
            }
            $pdo->commit();
            $msg = 'Attendance saved.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to save: ' . $e->getMessage();
        }
    }
}

// Close session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close_session' && $sessionId) {
    $pdo->prepare("UPDATE attendance_sessions SET status = 'closed' WHERE id = ?")->execute([$sessionId]);
    $msg = 'Session closed.';
    // refresh in-memory row
    $sesStmt->execute([$sectionId, $sessionDate]);
    $sessionRow = $sesStmt->fetch();
}

// Fetch enrolled students
$studentsStmt = $pdo->prepare(
    "SELECT e.id AS enrollment_id, u.student_id AS student_number, u.first_name, u.last_name
     FROM enrollments e
     INNER JOIN users u ON u.id = e.student_id
     WHERE e.course_id = ? AND e.status = 'enrolled'
     ORDER BY u.last_name, u.first_name"
);
$studentsStmt->execute([$section['course_id']]);
$students = $studentsStmt->fetchAll();

// Fetch existing attendance for this session
$existing = [];
if ($sessionId && count($students) > 0) {
    $enrollIds = array_column($students, 'enrollment_id');
    $placeholders = implode(',', array_fill(0, count($enrollIds), '?'));
    $q = $pdo->prepare("SELECT enrollment_id, status, notes FROM attendance_records WHERE session_id = ? AND enrollment_id IN ($placeholders)");
    $q->execute(array_merge([$sessionId], $enrollIds));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[(int)$r['enrollment_id']] = $r;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Attendance - <?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?></title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;background:#f5f6fa;color:#333;margin:0}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    h1{color:#6a0dad}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:14px 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{background:#6a0dad;color:#fff;border:none;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
    .btn.secondary{background:#3498db}
    .btn.warning{background:#e67e22}
    .btn.gray{background:#6b7280}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #e5e7eb;padding:8px}
    th{background:#fafafa;text-align:left}
    select,input[type="text"]{padding:6px;border:1px solid #ddd;border-radius:6px}
    .ok{color:#155724;background:#d4edda;border:1px solid #c3e6cb;padding:8px;border-radius:6px}
    .err{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;padding:8px;border-radius:6px}
  </style>
  <script>
    function bulkSet(val){
      document.querySelectorAll('select.att-status').forEach(function(sel){ sel.value = val; });
    }
  </script>
</head>
<body>
  <div class="wrap">
    <h1>Attendance — <?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['section_name']); ?></h1>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

    <div class="card">
      <form method="get" class="row" action="">
        <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">
        <label>Date: <input type="date" name="date" value="<?php echo htmlspecialchars($sessionDate); ?>"></label>
        <button class="btn gray" type="submit">Go</button>
        <?php if ($sessionId): ?>
          <a class="btn secondary" href="?section_id=<?php echo (int)$sectionId; ?>&date=<?php echo htmlspecialchars($sessionDate); ?>&export=1">Export CSV</a>
        <?php endif; ?>
      </form>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="session_date" value="<?php echo htmlspecialchars($sessionDate); ?>">
        <button class="btn" name="action" value="open_session" type="submit">Start Class</button>
        <?php if ($sessionId && ($sessionRow['status'] ?? 'open') === 'open'): ?>
          <button class="btn warning" name="action" value="close_session" type="submit">End Class</button>
        <?php endif; ?>
      </form>
      <div style="margin-top:6px;color:#555">
        <?php if ($sessionId): ?>
          Class no. <?php echo (int)$sessionId; ?> • Status: <strong><?php echo htmlspecialchars($sessionRow['status']); ?></strong> • Started: <?php echo htmlspecialchars($sessionRow['started_at']); ?>
        <?php else: ?>
          No class started for this date.
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="row" style="margin-bottom:8px">
        <button class="btn gray" type="button" onclick="bulkSet('present')">Mark All Present</button>
        <button class="btn gray" type="button" onclick="bulkSet('absent')">Mark All Absent</button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="save_attendance">
        <table>
          <thead>
            <tr>
              <th style="width:34%">Student</th>
              <th style="width:16%">Status</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($students as $st): $eid = (int)$st['enrollment_id'];
            $row = $existing[$eid] ?? null; $sel = $row['status'] ?? ''; $note = $row['notes'] ?? ''; ?>
            <tr>
              <td><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?><br><small><?php echo htmlspecialchars($st['student_number']); ?></small></td>
              <td>
                <select class="att-status" name="att[<?php echo $eid; ?>][status]">
                  <option value="present" <?php echo $sel==='present'?'selected':''; ?>>Present</option>
                  <option value="absent" <?php echo $sel==='absent'?'selected':''; ?>>Absent</option>
                  <option value="late" <?php echo $sel==='late'?'selected':''; ?>>Late</option>
                  <option value="excused" <?php echo $sel==='excused'?'selected':''; ?>>Excused</option>
                </select>
              </td>
              <td>
                <input type="text" name="att[<?php echo $eid; ?>][notes]" value="<?php echo htmlspecialchars($note); ?>" placeholder="Optional notes">
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:10px">
          <button class="btn" type="submit">Save Attendance</button>
        </div>
      </form>
    </div>

    <p><a class="btn gray" href="dashboard.php">Back to Dashboard</a></p>
  </div>
</body>
</html>
