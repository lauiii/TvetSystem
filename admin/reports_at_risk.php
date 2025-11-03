<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$error = '';
$msg = '';

// Inputs
$threshold = isset($_GET['threshold']) ? floatval($_GET['threshold']) : 75.0;
$program_id = intval($_GET['program_id'] ?? 0);
$course_id  = intval($_GET['course_id'] ?? 0);
$export     = isset($_GET['export']);

// Active school year
$activeSY = $pdo->query("SELECT id, year, semester FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$syId = $activeSY['id'] ?? 0;

// Filters data
$programs = $pdo->query("SELECT id, name FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$courses  = $pdo->query("SELECT c.id, c.course_code, c.course_name FROM courses c ORDER BY c.course_code")->fetchAll(PDO::FETCH_ASSOC);

// Determine available user name columns
$userCols = [];
try { $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $userCols = ['id','student_id','first_name','last_name','name']; }
$nameExpr = '';
if (in_array('first_name',$userCols) && in_array('last_name',$userCols)) { $nameExpr = "u.last_name AS ln, u.first_name AS fn"; }
elseif (in_array('name',$userCols)) { $nameExpr = "u.name AS nm"; }

// Load enrollments as the driver
$params = [];
$enrSql = "SELECT e.id AS enrollment_id, e.student_id, e.course_id, u.student_id AS student_number, u.program_id, c.course_code, c.course_name
           FROM enrollments e
           INNER JOIN users u ON e.student_id = u.id
           INNER JOIN courses c ON e.course_id = c.id
           WHERE e.school_year_id = ?";
$params[] = $syId;
if ($program_id > 0) { $enrSql .= " AND u.program_id = ?"; $params[] = $program_id; }
if ($course_id > 0)  { $enrSql .= " AND e.course_id = ?"; $params[] = $course_id; }
$enrSql .= " ORDER BY u.id, c.course_code";
$enrStmt = $pdo->prepare($enrSql);
$enrStmt->execute($params);
$enrollments = $enrStmt->fetchAll(PDO::FETCH_ASSOC);

$studentIds = array_unique(array_map(fn($r)=> (int)$r['student_id'], $enrollments));
$courseIds  = array_unique(array_map(fn($r)=> (int)$r['course_id'], $enrollments));

// Map student names
$studentNames = [];
if ($studentIds) {
    if ($nameExpr === '') { $nameExprQ = "u.student_id AS sid"; }
    else { $nameExprQ = $nameExpr; }
    $inIds = implode(',', array_map('intval', $studentIds));
    $rows = $pdo->query("SELECT u.id, $nameExprQ FROM users u WHERE u.id IN ($inIds)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($r['ln']) && isset($r['fn'])) { $studentNames[(int)$r['id']] = $r['ln'].', '.$r['fn']; }
        elseif (isset($r['nm'])) { $studentNames[(int)$r['id']] = $r['nm']; }
        else { $studentNames[(int)$r['id']] = 'ID#'.($r['id']??''); }
    }
}

// Possible per course per period
$possibleMap = [];
if ($courseIds) {
    $inC = implode(',', array_map('intval', $courseIds));
    $ps = $pdo->query("SELECT ac.course_id, LOWER(ac.period) AS period, SUM(ai.total_score) AS possible
                       FROM assessment_items ai
                       INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
                       WHERE ac.course_id IN ($inC)
                       GROUP BY ac.course_id, ac.period")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ps as $p) { $possibleMap[(int)$p['course_id']][$p['period']] = (float)$p['possible']; }
}

// Grades per enrollment aggregated by period
$sumGrades = []; $submittedPossible = [];
if ($studentIds && $courseIds) {
    $inE = implode(',', array_map('intval', array_column($enrollments,'enrollment_id')));
    if ($inE !== '') {
        $gs = $pdo->query("SELECT e.id AS enrollment_id, LOWER(ac.period) AS period, SUM(g.grade) AS sum_grade, SUM(ai.total_score) AS sub_possible
                            FROM grades g
                            INNER JOIN enrollments e ON g.enrollment_id = e.id
                            INNER JOIN assessment_items ai ON g.assessment_id = ai.id
                            INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
                            WHERE e.id IN ($inE)
                            GROUP BY e.id, ac.period")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($gs as $g) {
            $eid = (int)$g['enrollment_id']; $per = $g['period'];
            if (!isset($sumGrades[$eid])) $sumGrades[$eid] = ['prelim'=>0.0,'midterm'=>0.0,'finals'=>0.0];
            if (!isset($submittedPossible[$eid])) $submittedPossible[$eid] = ['prelim'=>0.0,'midterm'=>0.0,'finals'=>0.0];
            $sumGrades[$eid][$per] = (float)$g['sum_grade'];
            $submittedPossible[$eid][$per] = (float)$g['sub_possible'];
        }
    }
}

// Build at-risk rows
$rowsOut = [];
foreach ($enrollments as $enr) {
    $eid = (int)$enr['enrollment_id']; $cid = (int)$enr['course_id']; $sid = (int)$enr['student_id'];
    $pPos = (float)($possibleMap[$cid]['prelim']  ?? 0.0);
    $mPos = (float)($possibleMap[$cid]['midterm'] ?? 0.0);
    $fPos = (float)($possibleMap[$cid]['finals']  ?? 0.0);
    $pSum = (float)($sumGrades[$eid]['prelim']  ?? 0.0);
    $mSum = (float)($sumGrades[$eid]['midterm'] ?? 0.0);
    $fSum = (float)($sumGrades[$eid]['finals']  ?? 0.0);
    $pSub = (float)($submittedPossible[$eid]['prelim']  ?? 0.0);
    $mSub = (float)($submittedPossible[$eid]['midterm'] ?? 0.0);
    $fSub = (float)($submittedPossible[$eid]['finals']  ?? 0.0);

    $pPct = ($pPos>0) ? min(99, ($pSum/$pPos)*100.0) : null; $pComplete = ($pPos>0 && $pSub >= $pPos);
    $mPct = ($mPos>0) ? min(99, ($mSum/$mPos)*100.0) : null; $mComplete = ($mPos>0 && $mSub >= $mPos);
    $fPct = ($fPos>0) ? min(99, ($fSum/$fPos)*100.0) : null; $fComplete = ($fPos>0 && $fSub >= $fPos);
    $haveAny = ($pPct !== null) || ($mPct !== null) || ($fPct !== null);
    $tent = $haveAny ? min(99, ((($pPct??0)*30 + ($mPct??0)*30 + ($fPct??0)*40) / 100.0)) : null;
    $hasBlank = !$pComplete || !$mComplete || !$fComplete;

    // Risk conditions
    $isLow = ($tent !== null && $tent < $threshold);
    $noSub = (($pSub + $mSub + $fSub) <= 0);
    $atRisk = $isLow || $hasBlank || $noSub;
    if (!$atRisk) continue;

    $rowsOut[] = [
        'student_id' => $sid,
        'student_name' => $studentNames[$sid] ?? ('ID#'.$sid),
        'course_code' => $enr['course_code'],
        'course_name' => $enr['course_name'],
        'prelim_pct'  => $pComplete && $pPct!==null ? number_format($pPct,2) : '',
        'midterm_pct' => $mComplete && $mPct!==null ? number_format($mPct,2) : '',
        'finals_pct'  => $fComplete && $fPct!==null ? number_format($fPct,2) : '',
        'tentative'   => ($tent!==null ? number_format($tent,2) : ''),
        'lee'         => ($tent!==null && !$hasBlank ? number_format(lee_from_percent($tent),2) : ''),
        'reason'      => $noSub ? 'No submissions' : ($hasBlank ? 'Incomplete' : 'Low tentative'),
    ];
}

if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="at_risk_students.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Student','Course Code','Course Name','Prelim %','Midterm %','Finals %','Tentative %','LEE','Reason']);
    foreach ($rowsOut as $r) {
        fputcsv($out, [
            $r['student_name'], $r['course_code'], $r['course_name'],
            $r['prelim_pct'], $r['midterm_pct'], $r['finals_pct'],
            $r['tentative'], $r['lee'], $r['reason']
        ]);
    }
    fclose($out);
    exit;
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>At-risk Students - Admin Reports</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
<div class="admin-layout">
  <?php $active = 'reports'; require __DIR__ . '/inc/sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <?php $pageTitle = 'At-risk Students'; require __DIR__ . '/inc/header.php'; ?>

      <div class="card">
        <h3>Filters</h3>
        <form method="GET" class="filter-form">
          <div class="filter-row">
            <div class="filter-group">
              <label>Program</label>
              <select name="program_id">
                <option value="">All Programs</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>" <?php echo $program_id==(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label>Course</label>
              <select name="course_id">
                <option value="">All Courses</option>
                <?php foreach ($courses as $c): ?>
                  <option value="<?php echo (int)$c['id']; ?>" <?php echo $course_id==(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['course_code'].' - '.$c['course_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label>Low-risk threshold (%)</label>
              <input type="number" step="0.01" min="0" max="100" name="threshold" value="<?php echo htmlspecialchars((string)$threshold); ?>">
            </div>
            <div class="filter-group">
              <button class="btn primary" type="submit">Apply</button>
              <a class="btn" href="reports_at_risk.php?<?php echo http_build_query(array_merge($_GET,['export'=>1])); ?>">Export CSV</a>
            </div>
          </div>
        </form>
      </div>

      <div style="height:16px"></div>

      <div class="card">
        <h3>Results (<?php echo count($rowsOut); ?>)</h3>
        <?php if (count($rowsOut) === 0): ?>
          <p>No at-risk students found using the current filters.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="grades-table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Course</th>
                  <th>Prelim %</th>
                  <th>Midterm %</th>
                  <th>Finals %</th>
                  <th>Tentative %</th>
                  <th>LEE</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rowsOut as $r): ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                  <td><?php echo htmlspecialchars($r['course_code'].' - '.$r['course_name']); ?></td>
                  <td><?php echo htmlspecialchars($r['prelim_pct']); ?></td>
                  <td><?php echo htmlspecialchars($r['midterm_pct']); ?></td>
                  <td><?php echo htmlspecialchars($r['finals_pct']); ?></td>
                  <td><?php echo htmlspecialchars($r['tentative']); ?></td>
                  <td><?php echo htmlspecialchars($r['lee']); ?></td>
                  <td><?php echo htmlspecialchars($r['reason']); ?></td>
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
</body>
</html>
