<?php
/**
 * Admin Promotions — promote eligible students to next year and auto-enroll
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$programs = $pdo->query("SELECT id, code, name FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$msg=''; $error=''; $results=null;

if (!function_exists('promote_students')) {
function promote_students(PDO $pdo, int $program_id, int $from_year): array {
    // Active SY
    $sy = $pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$sy) throw new Exception('No active school year.');
    $syId = (int)$sy['id'];
    $maxYear = 3; // system uses 3-year programs

    // Get students in program and year
    $students = $pdo->prepare("SELECT id FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
    $students->execute([$program_id, $from_year]);
    $stuIds = array_map('intval', array_column($students->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $checked = count($stuIds);
    if ($checked === 0) return ['checked'=>0,'promoted'=>0,'failed'=>[],'next_year'=>min($from_year+1,$maxYear)];

    // Load enrollments for active SY
    $inList = implode(',', array_fill(0, count($stuIds), '?'));
    $enr = $pdo->prepare("SELECT e.id as eid, e.student_id, e.course_id FROM enrollments e WHERE e.school_year_id=? AND e.student_id IN ($inList)");
    $enr->execute(array_merge([$syId], $stuIds));
    $byStu = [];
    foreach ($enr->fetchAll(PDO::FETCH_ASSOC) as $r) { $byStu[(int)$r['student_id']][] = (int)$r['eid']; }
}

    // For each enrollment, require that all assessment_items for the course have a grade row that is not NULL
    $getCourse = $pdo->prepare("SELECT course_id FROM enrollments WHERE id=? LIMIT 1");
    $getItems  = $pdo->prepare("SELECT ai.id FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id=ac.id WHERE ac.course_id=?");
    $countGr   = $pdo->prepare("SELECT COUNT(*) FROM grades WHERE enrollment_id=? AND assessment_id IN (%s) AND grade IS NOT NULL");

    $eligible = []; $failed = [];
    foreach ($stuIds as $sid) {
        $enrs = $byStu[$sid] ?? [];
        if (!$enrs) { $failed[] = $sid; continue; }
        $okAll = true;
        foreach ($enrs as $eid) {
            $getCourse->execute([$eid]);
            $cid = (int)$getCourse->fetchColumn();
            if (!$cid) { $okAll=false; break; }
            $getItems->execute([$cid]);
            $items = array_map('intval', array_column($getItems->fetchAll(PDO::FETCH_ASSOC),'id'));
            if ($items) {
                $ph = implode(',', array_fill(0, count($items), '?'));
                $stmt = $pdo->prepare(sprintf("SELECT COUNT(*) FROM grades WHERE enrollment_id=? AND assessment_id IN (%s) AND grade IS NOT NULL", $ph));
                $stmt->execute(array_merge([$eid], $items));
                $have = (int)$stmt->fetchColumn();
                if ($have < count($items)) { $okAll=false; break; }
            }
        }
        if ($okAll) { $eligible[] = $sid; } else { $failed[] = $sid; }
    }

    // Promote eligible students (increment year_level up to maxYear)
    $promoted = 0; $nextYear = min($from_year+1, $maxYear);
    if ($eligible) {
        $ph = implode(',', array_fill(0, count($eligible), '?'));
        $upd = $pdo->prepare("UPDATE users SET year_level = LEAST(year_level+1, $maxYear) WHERE id IN ($ph)");
        $upd->execute($eligible);
        $promoted = $upd->rowCount();
    }

    return ['checked'=>$checked,'promoted'=>$promoted,'failed'=>$failed,'next_year'=>$nextYear];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['program_id'], $_POST['from_year'])) {
    $program_id = (int)$_POST['program_id'];
    $from_year = (int)$_POST['from_year'];
    if ($program_id <= 0 || $from_year <= 0) {
        $error = 'Select program and year.';
    } else {
        try {
            $results = promote_students($pdo, $program_id, $from_year);
            $msg = 'Promoted ' . (int)$results['promoted'] . ' of ' . (int)$results['checked'] . ' students to Year ' . (int)$results['next_year'] . '.';
        } catch (Exception $e) {
            $error = 'Promotion failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Promotions - Admin</title>
  <link rel="icon" type="image/jpeg" href="../public/assets/icon/logo1.jfif">
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
  <div class="admin-layout">
    <?php $active = 'promotions'; require __DIR__ . '/inc/sidebar.php'; ?>

    <main class="main-content">
      <div class="container">
        <?php $pageTitle = 'Promotions'; require __DIR__ . '/inc/header.php'; ?>

        <div class="card" style="max-width:720px;margin:0 auto;">
          <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

          <form method="POST" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="flex:1;min-width:200px;">
              <label>Program</label>
              <select name="program_id" required class="form-control">
                <option value="">-- select program --</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(($p['code']?:'') . ' — ' . $p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>From Year</label>
              <select name="from_year" required class="form-control">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
              </select>
            </div>
            <button class="btn primary" type="submit">Promote Eligible</button>
          </form>
        </div>

        <?php if ($results && !empty($results['failed'])): ?>
        <div class="card" style="max-width:720px;margin:16px auto;">
          <h3>Not Promoted (Failed/Incomplete)</h3>
          <p><?php echo count($results['failed']); ?> students not promoted.</p>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
