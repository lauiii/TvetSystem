<?php
/**
 * Instructor — All Classes (Attendance Tracker)
 * Lists attendance sessions across all sections the instructor teaches
 * Filters: date range, section, status; CSV export
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Defaults: current month
$firstOfMonth = date('Y-m-01');
$today = date('Y-m-d');
$start = $_GET['start'] ?? $firstOfMonth;
$end = $_GET['end'] ?? $today;
$filterSection = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $firstOfMonth;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = $today;

// Build sections list depending on selected course (if any) or instructor's assignments
if ($filterCourse > 0) {
    $secStmt = $pdo->prepare("SELECT s.id AS section_id, s.section_code, s.section_name, c.id AS course_id, c.course_code, c.course_name FROM sections s INNER JOIN courses c ON s.course_id = c.id WHERE c.id = ? AND s.status='active' ORDER BY s.section_code");
    $secStmt->execute([$filterCourse]);
} else {
    $secStmt = $pdo->prepare("SELECT s.id AS section_id, s.section_code, s.section_name, c.id AS course_id, c.course_code, c.course_name FROM instructor_sections ins INNER JOIN sections s ON ins.section_id = s.id INNER JOIN courses c ON s.course_id = c.id WHERE ins.instructor_id = ? AND s.status='active' ORDER BY c.course_code, s.section_code");
    $secStmt->execute([$instructorId]);
}
$sections = $secStmt->fetchAll();

// Fetch courses for filter (all courses)
$coursesStmt = $pdo->query("SELECT id, course_code, course_name FROM courses ORDER BY course_code");
$courses = $coursesStmt->fetchAll();

// Build query
$params = [$start, $end];
$where = "sess.session_date BETWEEN ? AND ?";
if ($filterCourse > 0) { $where .= " AND c.id = ?"; $params[] = $filterCourse; }
if ($filterSection > 0) { $where .= " AND s.id = ?"; $params[] = $filterSection; }

$sql = "
SELECT
  sess.id AS session_id,
  sess.session_date,
  s.id AS section_id,
  s.section_code,
  s.section_name,
  s.start_time,
  s.end_time,
  c.id AS course_id,
  c.course_code,
  c.course_name,
  COALESCE(s.enrolled_count, 0) AS enrolled_count,
  SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
  SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
  SUM(CASE WHEN ar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
  SUM(CASE WHEN ar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
FROM sections s
JOIN courses c ON s.course_id = c.id
JOIN attendance_sessions sess ON sess.section_id = s.id
LEFT JOIN attendance_records ar ON ar.session_id = sess.id
WHERE {$where}
GROUP BY sess.id
ORDER BY sess.session_date DESC, c.course_code, s.section_code
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

// CSV export
if (isset($_GET['export']) && $_GET['export'] == '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="all_classes_'. $start . '_to_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Section','Course Code','Course Name','Scheduled Time','Students Enrolled','Present','Absent','Late','Excused']);
    foreach ($sessions as $r) {
        $sched = '';
        if (!empty($r['start_time']) || !empty($r['end_time'])) {
            $sched = ($r['start_time'] ? substr($r['start_time'],0,5) : '') . ' - ' . ($r['end_time'] ? substr($r['end_time'],0,5) : '');
        }
        fputcsv($out, [
            $r['session_date'],
            $r['section_code'],
            $r['course_code'],
            $r['course_name'],
            $sched,
            (int)$r['enrolled_count'],
            (int)$r['present_count'],
            (int)$r['absent_count'],
            (int)$r['late_count'],
            (int)$r['excused_count']
        ]);
    }
    fclose($out);
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
  <title>All Classes (Attendance Tracker)</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;background:#f5f6fa;color:#333;margin:0}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
    h1{color:#6a0dad}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:14px 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{background:#6a0dad;color:#fff;border:none;border-radius:8px;padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-block}
    .btn.secondary{background:#3498db}
    select,input{padding:6px;border:1px solid #ddd;border-radius:6px}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #e5e7eb;padding:8px}
    th{background:#fafafa;text-align:left}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>All Classes — Attendance Tracker</h1>

    <div class="card">
      <form method="get" class="row" action="">
        <label>Start: <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>"></label>
        <label>End: <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>"></label>
        <label>Course:
          <select name="course_id">
            <option value="0">All</option>
            <?php foreach ($courses as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo ($filterCourse===(int)$c['id']?'selected':''); ?>>
                <?php echo htmlspecialchars($c['course_code'] . ' — ' . $c['course_name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Section:
          <select name="section_id">
            <option value="0">All</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?php echo (int)$s['section_id']; ?>" <?php echo ($filterSection===(int)$s['section_id']?'selected':''); ?>>
                <?php echo htmlspecialchars($s['course_code'] . ' - ' . $s['section_code']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn" type="submit">Filter</button>
        <a class="btn secondary" href="?start=<?php echo urlencode($start); ?>&end=<?php echo urlencode($end); ?>&course_id=<?php echo (int)$filterCourse; ?>&section_id=<?php echo (int)$filterSection; ?>&export=1">Export CSV</a>
      </form>
      <?php if ($filterSection > 0) { 
            $att = get_attendance_weights($pdo, (int)$filterSection);
            if (!empty($att)) {
                $parts = [];
                foreach (['prelim','midterm','finals'] as $p) { if (isset($att[$p])) { $parts[] = ucfirst($p).': '.number_format($att[$p],0).'%'; } }
                if (!empty($parts)) { echo '<div class="muted" style="margin-top:8px">Attendance Weight — ' . htmlspecialchars(implode(' • ', $parts)) . '</div>'; }
            }
        } ?>
    </div>

    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Section</th>
            <th>Course Code</th>
            <th>Course Name</th>
            <th>Scheduled Time</th>
            <th class="muted">Students Enrolled</th>
            <th>Present</th>
            <th>Absent</th>
            <th>Late</th>
            <th>Excused</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$sessions): ?>
            <tr><td colspan="10" class="muted">No sessions found for the selected filters.</td></tr>
          <?php else: foreach ($sessions as $r): ?>
            <?php $sched = (!empty($r['start_time']) || !empty($r['end_time'])) ? ((isset($r['start_time'])?substr($r['start_time'],0,5):'') . ' - ' . (isset($r['end_time'])?substr($r['end_time'],0,5):'')) : ''; ?>
            <tr>
              <td><?php echo htmlspecialchars($r['session_date']); ?></td>
              <td><?php echo htmlspecialchars($r['section_code']); ?></td>
              <td><?php echo htmlspecialchars($r['course_code']); ?></td>
              <td><?php echo htmlspecialchars($r['course_name']); ?></td>
              <td><?php echo htmlspecialchars($sched); ?></td>
              <td class="muted"><?php echo (int)$r['enrolled_count']; ?></td>
              <td><?php echo (int)$r['present_count']; ?></td>
              <td><?php echo (int)$r['absent_count']; ?></td>
              <td><?php echo (int)$r['late_count']; ?></td>
              <td><?php echo (int)$r['excused_count']; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <p><a class="btn" href="dashboard.php">Back to Dashboard</a></p>
  </div>
</body>
</html>
