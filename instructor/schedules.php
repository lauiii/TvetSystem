<?php
require_once __DIR__ . '/../config.php';
requireRole('instructor');

$instructorId = (int)($_SESSION['user_id'] ?? 0);
$sectionId    = (int)($_GET['section_id'] ?? 0);
$err = '';
$msg = '';

// Ensure instructor owns the section
if ($sectionId > 0) {
    $chk = $pdo->prepare("SELECT s.id, s.section_code, c.id AS course_id, c.course_code, c.course_name
                           FROM instructor_sections ins
                           INNER JOIN sections s ON s.id = ins.section_id
                           INNER JOIN courses c ON s.course_id = c.id
                           WHERE ins.instructor_id = ? AND ins.section_id = ? AND s.status='active' LIMIT 1");
    $chk->execute([$instructorId, $sectionId]);
    $section = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$section) { header('Location: dashboard.php'); exit; }
} else {
    // If no section provided, try first assigned section
    $chk = $pdo->prepare("SELECT s.id, s.section_code, c.id AS course_id, c.course_code, c.course_name
                           FROM instructor_sections ins
                           INNER JOIN sections s ON s.id = ins.section_id
                           INNER JOIN courses c ON s.course_id = c.id
                           WHERE ins.instructor_id = ? AND s.status='active' ORDER BY s.id LIMIT 1");
    $chk->execute([$instructorId]);
    $section = $chk->fetch(PDO::FETCH_ASSOC);
    if ($section) { header('Location: schedules.php?section_id='.(int)$section['id']); exit; }
    $err = 'No assigned sections found.';
}

// Create schedules table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS instructor_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        instructor_id INT NOT NULL,
        section_id INT NOT NULL,
        course_id INT NOT NULL,
        day_of_week TINYINT NOT NULL, -- 1=Mon .. 7=Sun
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        room VARCHAR(100) DEFAULT NULL,
        notes VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_instructor (instructor_id),
        INDEX idx_section (section_id),
        INDEX idx_course (course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* ignore */ }

// Helper overlap check
function has_overlap(PDO $pdo, int $instructorId, int $sectionId, int $day, string $start, string $end, int $ignoreId = 0): bool {
    $sql = "SELECT COUNT(*) FROM instructor_schedules
            WHERE (instructor_id = ? OR section_id = ?) AND day_of_week = ?
              AND NOT (end_time <= ? OR start_time >= ?)" . ($ignoreId>0?" AND id<>$ignoreId":"");
    $st = $pdo->prepare($sql);
    $st->execute([$instructorId, $sectionId, $day, $start, $end]);
    return ((int)$st->fetchColumn()) > 0;
}

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD']==='POST' && empty($err) && !empty($section)) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        try {
            $day   = (int)($_POST['day_of_week'] ?? 0);
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end   = trim((string)($_POST['end_time'] ?? ''));
            $room  = trim((string)($_POST['room'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($day<1 || $day>7) throw new Exception('Select a valid day.');
            if ($start==='' || $end==='') throw new Exception('Start and End time required.');
            if (strtotime($end) <= strtotime($start)) throw new Exception('End time must be after Start time.');
            $ignoreId = ($action==='update') ? (int)($_POST['id'] ?? 0) : 0;
            if (has_overlap($pdo, $instructorId, (int)$section['id'], $day, $start, $end, $ignoreId)) {
                throw new Exception('Schedule overlaps with an existing one.');
            }
            if ($action==='create') {
                $ins = $pdo->prepare("INSERT INTO instructor_schedules (instructor_id, section_id, course_id, day_of_week, start_time, end_time, room, notes)
                                      VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([$instructorId, (int)$section['id'], (int)$section['course_id'], $day, $start, $end, ($room?:null), ($notes?:null)]);
                $msg = 'Schedule added.';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $up = $pdo->prepare("UPDATE instructor_schedules SET day_of_week=?, start_time=?, end_time=?, room=?, notes=? WHERE id=? AND instructor_id=? AND section_id=?");
                $up->execute([$day, $start, $end, ($room?:null), ($notes?:null), $id, $instructorId, (int)$section['id']]);
                $msg = 'Schedule updated.';
            }
        } catch (Exception $e) { $err = $e->getMessage(); }
    } elseif ($action==='delete') {
        $id = (int)($_POST['id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM instructor_schedules WHERE id=? AND instructor_id=? AND section_id=?");
        $del->execute([$id, $instructorId, (int)$section['id']]);
        $msg = 'Schedule removed.';
    }
}

// Fetch schedules for this section
$schedules = [];
if (!empty($section)) {
    $st = $pdo->prepare("SELECT * FROM instructor_schedules WHERE instructor_id=? AND section_id=? ORDER BY day_of_week, start_time");
    $st->execute([$instructorId, (int)$section['id']]);
    $schedules = $st->fetchAll(PDO::FETCH_ASSOC);
}

$days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Schedules - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
  <style>
    body{font-family:'Segoe UI',Tahoma,Verdana,Arial,sans-serif;background:#f5f6fa;color:#333}
    .page{max-width:1100px;margin:0 auto;padding:24px}
    .header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
    .meta{color:#666}
    .card{background:#fff;border:1px solid #eee;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);padding:16px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer}
    .btn.primary{background:#6a0dad;color:#fff;border-color:#6a0dad}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #eee;text-align:left}
    th{background:#6a0dad;color:#fff}
    .row-actions{display:flex;gap:6px}
    @media(max-width:768px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="page">
  <div class="header">
    <div>
      <h1>My Schedules</h1>
      <?php if (!empty($section)): ?>
      <div class="meta">Section <?php echo htmlspecialchars($section['section_code']); ?> • <?php echo htmlspecialchars($section['course_code'].' — '.$section['course_name']); ?></div>
      <?php endif; ?>
    </div>
    <div class="meta"><a href="dashboard.php" class="btn">Back</a></div>
  </div>

  <?php if ($err): ?><div class="card" style="border-left:4px solid #e11d48;color:#991b1b;margin-bottom:12px;"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="card" style="border-left:4px solid #16a34a;color:#065f46;margin-bottom:12px;"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

  <?php if (!empty($section)): ?>
  <div class="card" style="margin-bottom:16px">
    <form method="POST" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label>Day</label>
        <select name="day_of_week" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
          <option value="">Select</option>
          <?php foreach ($days as $k=>$v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Start Time</label>
        <input type="time" name="start_time" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
      </div>
      <div>
        <label>End Time</label>
        <input type="time" name="end_time" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
      </div>
      <div>
        <label>Room (optional)</label>
        <input type="text" name="room" placeholder="e.g. Rm 204" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
      </div>
      <div style="grid-column:1/-1">
        <label>Notes (optional)</label>
        <input type="text" name="notes" placeholder="e.g. Lecture/Lab" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px">
      </div>
      <div style="grid-column:1/-1;text-align:right"><button class="btn primary" type="submit">Add Schedule</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Current Schedules</h3>
    <?php if (!$schedules): ?>
      <p style="color:#666">No schedules yet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Day</th><th>Time</th><th>Room</th><th>Notes</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($schedules as $sc): ?>
        <tr>
          <td><?php echo htmlspecialchars($days[(int)$sc['day_of_week']] ?? $sc['day_of_week']); ?></td>
          <td><?php echo htmlspecialchars(substr($sc['start_time'],0,5) . ' - ' . substr($sc['end_time'],0,5)); ?></td>
          <td><?php echo htmlspecialchars($sc['room'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($sc['notes'] ?? ''); ?></td>
          <td class="row-actions">
            <form method="POST" onsubmit="return confirm('Delete this schedule?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)$sc['id']; ?>">
              <button class="btn" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
