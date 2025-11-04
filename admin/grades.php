<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
// AJAX: student grades modal content
if (isset($_GET['ajax']) && isset($_GET['action']) && $_GET['action'] === 'student_grades') {
    $sid = intval($_GET['student_id'] ?? 0);
    if ($sid <= 0) { echo '<div style="padding:16px; color:#b91c1c;">Invalid student.</div>'; exit; }

    // Active semester/year
    $activeSY = $pdo->query("SELECT id, year, semester FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $syId = $activeSY['id'] ?? 0;

    // Pull graded rows for this student (with item max for completeness calc) — all courses
    $stmt = $pdo->prepare("SELECT c.id as course_id, c.course_code, c.course_name, ac.period, ai.total_score, g.grade
        FROM grades g
        INNER JOIN assessment_items ai ON g.assessment_id = ai.id
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN enrollments e ON g.enrollment_id = e.id
        INNER JOIN courses c ON e.course_id = c.id
        WHERE e.student_id = ? AND e.school_year_id = ?
        ORDER BY c.course_code, FIELD(ac.period,'prelim','midterm','finals')");
    $stmt->execute([$sid, $syId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // All enrolled courses for this student (ensure rows even without grades)
    $cstmt = $pdo->prepare("SELECT c.id AS course_id, c.course_code, c.course_name, c.semester
        FROM enrollments e
        INNER JOIN courses c ON e.course_id = c.id
        WHERE e.student_id = ? AND e.school_year_id = ?
        ORDER BY c.course_code");
    $cstmt->execute([$sid, $syId]);
    $enrolledCourses = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    // Courses with a submission this school year (or null sy for legacy)
    $subStmt = $pdo->prepare("SELECT course_id FROM course_grade_submissions WHERE (school_year_id = ? OR school_year_id IS NULL)");
    $subStmt->execute([$syId]);
    $submittedCourses = array_map('intval', $subStmt->fetchAll(PDO::FETCH_COLUMN));
    $isSubmitted = array_flip($submittedCourses);
    // Fetch total possible per course per period for completeness
    $ps = $pdo->prepare("SELECT c.id as course_id, ac.period, SUM(ai.total_score) as possible
        FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE c.id IN (
            SELECT e.course_id FROM enrollments e WHERE e.student_id = ? AND e.school_year_id = ?
        )
        GROUP BY c.id, ac.period");
    $ps->execute([$sid, $syId]);
    $possibleMap = [];
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) { $possibleMap[$p['course_id']][strtolower($p['period'])] = floatval($p['possible']); }

    // Aggregate by course
    $sumGrades = []; $submittedPossible = [];
    foreach ($rows as $r) {
        $cid = (int)$r['course_id']; $per = strtolower($r['period']);
        if (!isset($sumGrades[$cid])) { $sumGrades[$cid] = ['prelim'=>0.0,'midterm'=>0.0,'finals'=>0.0]; $submittedPossible[$cid] = ['prelim'=>0.0,'midterm'=>0.0,'finals'=>0.0]; }
        if ($r['grade'] !== null) {
            $sumGrades[$cid][$per] += floatval($r['grade']);
            $submittedPossible[$cid][$per] += floatval($r['total_score']);
        }
    }

    // Student info (include year & program)
    $stu = $pdo->prepare("SELECT u.student_id, u.first_name, u.last_name, u.year_level, p.name as program_name
                          FROM users u
                          LEFT JOIN programs p ON u.program_id = p.id
                          WHERE u.id = ? LIMIT 1");
    $stu->execute([$sid]);
    $s = $stu->fetch(PDO::FETCH_ASSOC);
    $fullname = $s ? ($s['last_name'] . ', ' . $s['first_name']) : 'Student';

    // Build final-per-course rows (include all enrolled courses)
    $finals = [];
    foreach ($enrolledCourses as $cinfo) {
        $cid = (int)$cinfo['course_id'];
        $periods = $possibleMap[$cid] ?? [];
        $pPos = $periods['prelim'] ?? 0.0; $mPos = $periods['midterm'] ?? 0.0; $fPos = $periods['finals'] ?? 0.0;
        $pSum = $sumGrades[$cid]['prelim'] ?? 0.0; $mSum = $sumGrades[$cid]['midterm'] ?? 0.0; $fSum = $sumGrades[$cid]['finals'] ?? 0.0;
        $pSub = $submittedPossible[$cid]['prelim'] ?? 0.0; $mSub = $submittedPossible[$cid]['midterm'] ?? 0.0; $fSub = $submittedPossible[$cid]['finals'] ?? 0.0;

        $pPct = ($pPos>0) ? min(99, ($pSum/$pPos)*100.0) : null; $pComplete = ($pPos>0 && $pSub >= $pPos);
        $mPct = ($mPos>0) ? min(99, ($mSum/$mPos)*100.0) : null; $mComplete = ($mPos>0 && $mSub >= $mPos);
        $fPct = ($fPos>0) ? min(99, ($fSum/$fPos)*100.0) : null; $fComplete = ($fPos>0 && $fSub >= $fPos);
        $submitted = isset($isSubmitted[$cid]);
        $haveAny = ($pPct !== null) || ($mPct !== null) || ($fPct !== null);
        $tent = $haveAny ? min(99, ((($pPct??0)*30 + ($mPct??0)*30 + ($fPct??0)*40) / 100.0)) : null;
        $hasBlank = !$pComplete || !$mComplete || !$fComplete;
        // If not submitted, force blank grade display
        if (!$submitted) { $tent = null; $hasBlank = true; }
        // Remarks and status
        $remarks = '';
        if ($submitted && $tent !== null && !$hasBlank) { $remarks = ($tent >= 75) ? 'Passed' : 'Failed'; }
        $hasPeriods = ($pPos>0) || ($mPos>0) || ($fPos>0);
        if (!$submitted) { $status='Not Submitted'; $statusCls=''; }
        elseif (!$hasPeriods || ($pSub+$mSub+$fSub) <= 0 || $hasBlank) { $status='Incomplete'; $statusCls='text-secondary'; }
        else { $status='Complete'; $statusCls='text-success'; }
        $finals[] = [
            'course_id'=>$cid,
            'code'=>$cinfo['course_code'], 'name'=>$cinfo['course_name'],
            'grade'=>$tent, 'remarks'=>$remarks, 'status'=>$status, 'status_cls'=>$statusCls
        ];
    }

    if (empty($finals)) { echo '<div style="padding:16px; color:#666;">No grades found for the active term.</div>'; exit; }

    // Modal header
    $semRaw = strtolower((string)($activeSY['semester'] ?? ''));
    $semLabel = '';
    if ($semRaw === '1' || $semRaw === 'first' || $semRaw === '1st') { $semLabel = '1st Semester'; }
    elseif ($semRaw === '2' || $semRaw === 'second' || $semRaw === '2nd') { $semLabel = '2nd Semester'; }
    elseif ($semRaw === '3' || $semRaw === 'summer' ) { $semLabel = 'Summer'; }
    // Fallback: infer from courses if active semester not set
    if ($semLabel === '') {
        $sems = [];
        foreach ($enrolledCourses as $cinfoTmp) { $sv = (int)($cinfoTmp['semester'] ?? 0); if ($sv>0 && !in_array($sv,$sems,true)) $sems[] = $sv; }
        if (count($sems) === 1) {
            $semLabel = ($sems[0]===1 ? '1st Semester' : ($sems[0]===2 ? '2nd Semester' : ($sems[0]===3 ? 'Summer' : '')));
        } elseif (count($sems) > 1) {
            $semLabel = 'All Semesters';
        } else {
            $semLabel = 'Semester';
        }
    }

    $yearNum = intval($s['year_level'] ?? 0);
    $yrLabel = $yearNum ? ($yearNum===1 ? '1st Year' : ($yearNum===2 ? '2nd Year' : ($yearNum===3 ? '3rd Year' : ($yearNum===4 ? '4th Year' : ('Year ' . $yearNum))))) : '';

    echo '<div style="padding:6px 0 12px; font-family:system-ui,Segoe UI,Arial;">';
    echo '<div style="font-size:20px; font-weight:700; margin-bottom:6px;">Student Info</div>';
    echo '<div style="margin:4px 0;"><strong>Name:</strong> ' . htmlspecialchars($fullname) . '</div>';
    echo '<div style="margin:4px 0;"><strong>Student #:</strong> ' . htmlspecialchars($s['student_id'] ?? '') . '</div>';
    echo '<div style="margin:4px 0;"><strong>Year Level:</strong> ' . htmlspecialchars($yrLabel) . '</div>';
    echo '<div style="margin:4px 0;"><strong>Program:</strong> ' . htmlspecialchars($s['program_name'] ?? '') . '</div>';
    echo '<div style="margin:4px 0;"><strong>Semester:</strong> ' . htmlspecialchars($semLabel) . '</div>';
    echo '<div style="margin:4px 0;"><strong>School Year:</strong> ' . htmlspecialchars($activeSY['year'] ?? '') . '</div>';
    echo '</div>';

    // Build table
    echo '<table class="grades-table" style="width:100%; border-collapse:collapse;">';
    echo '<thead><tr>';
    echo '<th style="text-align:left;">Course Code</th>';
    echo '<th style="text-align:left;">Description</th>';
    echo '<th style="text-align:center;">Units</th>';
    echo '<th style="text-align:center;">Status</th>';
    echo '<th style="text-align:center;">Remarks</th>';
    echo '<th style="text-align:center;">Final Rating</th>';
    echo '</tr></thead><tbody>';

    $sumLee = 0.0; $countLee = 0; $defaultUnits = 3;
    foreach ($finals as $fr) {
        $lee = ($fr['grade']===null) ? null : lee_from_percent($fr['grade']);
        if ($lee !== null) { $sumLee += $lee; $countLee++; }
        $cls = ($fr['remarks']==='Passed') ? 'text-success' : (($fr['remarks']==='Failed') ? 'text-danger' : '');
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($fr['code']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($fr['name']) . '</td>';
        echo '<td style="text-align:center;">' . $defaultUnits . '</td>';
        $stCls = $fr['status']!=='' ? htmlspecialchars($fr['status_cls']) : '';
        echo '<td style="text-align:center;" class="' . $stCls . '">' . htmlspecialchars($fr['status']) . '</td>';
        echo '<td class="' . $cls . '" style="text-align:center;">' . htmlspecialchars($fr['remarks']) . '</td>';
        echo '<td style="text-align:center;">' . ($lee===null ? '' : htmlspecialchars(number_format($lee,2))) . '</td>';
        echo '</tr>';
    }
    $avgLee = $countLee ? ($sumLee / $countLee) : null;
    echo '<tr>';
    echo '<td colspan="5" style="text-align:right; font-weight:700;">Average Final Rating</td>';
    echo '<td style="text-align:center; font-weight:700;">' . ($avgLee===null ? '' : htmlspecialchars(number_format($avgLee,2))) . '</td>';
    echo '</tr>';
    echo '</tbody></table>';
    exit;
}
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
$student_id = intval($_GET['student_id'] ?? 0); // legacy support if passed
$student_q  = trim((string)($_GET['student_q'] ?? ''));
$year_level = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? intval($_GET['year_level']) : null;
$program_id = intval($_GET['program_id'] ?? 0);

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
$hasYearCol = in_array('year_level', $userCols);
$studentOrder = (in_array('last_name', $userCols) && in_array('first_name', $userCols)) ? 'last_name, first_name' : 'id';
// Only needed if we still expose a dropdown; replaced by search bar below
$students = [];

// Programs filter options
$programs = $pdo->query("SELECT id, name FROM programs ORDER BY name")->fetchAll();

// Build per-student per-course averages (current active school year if available)
$studentNameExpr = '';
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $studentNameExpr = "u_student.first_name as student_first, u_student.last_name as student_last";
} elseif (in_array('name', $userCols)) {
    $studentNameExpr = "u_student.name as student_name";
}

// get active school year id (optional)
$activeSY = $pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$activeSyId = $activeSY['id'] ?? null;

$query = "
    SELECT
        u_student.id as student_id,
        u_student.student_id as student_number,
        $studentNameExpr
        , c.id as course_id,
        c.course_code,
        c.course_name,
        c.program_id,
        p.name as program_name" . ($hasYearCol ? ", u_student.year_level as year_level" : "") . ",
        ROUND(AVG(g.grade),2) as average_raw
    FROM enrollments e
    INNER JOIN users u_student ON e.student_id = u_student.id
    INNER JOIN courses c ON e.course_id = c.id
    LEFT JOIN programs p ON c.program_id = p.id
    LEFT JOIN grades g ON g.enrollment_id = e.id
    WHERE 1=1
";

$params = [];
if ($activeSyId) { $query .= " AND e.school_year_id = ?"; $params[] = $activeSyId; }
if ($course_id > 0) { $query .= " AND c.id = ?"; $params[] = $course_id; }
if ($student_id > 0) { $query .= " AND u_student.id = ?"; $params[] = $student_id; }
// Student search by ID or name
if ($student_q !== '') {
    $like = '%' . $student_q . '%';
    $conds = [];
    $query .= " AND (";
    $conds[] = "u_student.student_id LIKE ?"; $params[] = $like;
    if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
        $conds[] = "CONCAT(u_student.last_name,' ',u_student.first_name) LIKE ?"; $params[] = $like;
        $conds[] = "CONCAT(u_student.first_name,' ',u_student.last_name) LIKE ?"; $params[] = $like;
    }
    if (in_array('name', $userCols)) { $conds[] = "u_student.name LIKE ?"; $params[] = $like; }
    $query .= implode(' OR ', $conds) . ")";
}
// Year level filter (if column exists)
if ($year_level !== null && in_array('year_level', $userCols)) { $query .= " AND u_student.year_level = ?"; $params[] = $year_level; }
if ($program_id > 0) { $query .= " AND c.program_id = ?"; $params[] = $program_id; }

$query .= " GROUP BY u_student.id, c.id ORDER BY " . (in_array('last_name', $userCols) ? "u_student.last_name, u_student.first_name" : "u_student.id") . ", c.course_code";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$studentCourseAverages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group rows by program, then by year (1..3), maintaining unique students per (program, year)
$programGroups = [];
foreach ($studentCourseAverages as $r) {
    $pid = isset($r['program_id']) ? (int)$r['program_id'] : 0;
    $pname = $r['program_name'] ?? 'Unassigned Program';
    if (!isset($programGroups[$pid])) {
        $programGroups[$pid] = ['name' => $pname, 'years' => [1=>[],2=>[],3=>[]]];
    }
    $year = $hasYearCol ? (int)($r['year_level'] ?? 0) : 0;
    if ($year < 1 || $year > 3) { $year = 1; }
    $sid = (int)$r['student_id'];
    if (!isset($programGroups[$pid]['years'][$year][$sid])) {
        $programGroups[$pid]['years'][$year][$sid] = ['row' => $r];
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Grades Overview - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
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
                                <label>Student (search)</label>
                                <input type="text" name="student_q" placeholder="Search by name or ID" value="<?php echo htmlspecialchars($student_q); ?>" />
                            </div>
                            <div class="filter-group">
                                <label>Program:</label>
                                <select name="program_id">
                                    <option value="">All Programs</option>
                                    <?php foreach ($programs as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $program_id == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Year Level</label>
                                <select name="year_level">
                                    <option value="">All Years</option>
                                    <?php for ($yl=1; $yl<=4; $yl++): ?>
                                        <option value="<?php echo $yl; ?>" <?php echo ($year_level===$yl)?'selected':''; ?>><?php echo $yl; ?><?php echo ($yl===1?'st':($yl===2?'nd':($yl===3?'rd':'th'))); ?> Year</option>
                                    <?php endfor; ?>
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
                    <h3>Grade Records</h3>
                    <?php if (count($studentCourseAverages) === 0): ?>
                        <p>No grade summaries found matching the criteria.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <?php foreach ($programGroups as $pg): ?>
                                <div class="program-heading">
                                    <?php echo htmlspecialchars($pg['name']); ?>
                                </div>
                                <?php foreach ([1=>'1st Year',2=>'2nd Year',3=>'3rd Year'] as $yrNum => $yrLabel): ?>
                                    <?php $studentsByYear = $pg['years'][$yrNum] ?? []; if (empty($studentsByYear)) continue; ?>
                                    <div class="year-heading"><?php echo $yrLabel; ?></div>
                                    <table class="grades-table">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($studentsByYear as $sid => $info): $row = $info['row']; ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                        <?php
                                                        if (isset($row['student_first']) && isset($row['student_last'])) {
                                                            echo htmlspecialchars($row['student_last'] . ', ' . $row['student_first']);
                                                        } elseif (isset($row['student_name'])) {
                                                            echo htmlspecialchars($row['student_name']);
                                                        } else {
                                                            echo htmlspecialchars($row['student_number']);
                                                        }
                                                        ?>
                                                        </strong>
                                                    </td>
                                                    <td class="actions">
                                                        <button type="button" class="btn btn-modern btn-view text-decoration-none" data-student-id="<?php echo (int)$row['student_id']; ?>" onclick="openGradesModal(<?php echo (int)$row['student_id']; ?>)">
                                                            <span class="icon" aria-hidden="true">👁️</span>
                                                            <span>View Grades</span>
                                                        </button>
                                                        <a class="btn btn-modern btn-print text-decoration-none" href="print_grades_pdf.php?student_id=<?php echo (int)$row['student_id']; ?>" target="_blank" rel="noopener">
                                                            <span class="icon" aria-hidden="true">🖨️</span>
                                                            <span>Print Grades</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <div style="height:12px"></div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- View Grades Modal -->
    <div id="gradesModal" style="display:none; position:fixed; inset:0; height:100vh; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
        <div role="dialog" aria-modal="true" aria-labelledby="gradesModalTitle" tabindex="-1" style="background:#fff; width: min(96vw, 980px); max-height: calc(100vh - 40px); overflow:auto; -webkit-overflow-scrolling:touch; border-radius:12px; padding:18px; border:1px solid #e5e7eb; box-shadow:0 10px 30px rgba(0,0,0,0.18); margin:0 auto;">
            <h3 id="gradesModalTitle" style="margin-bottom:10px; color:#9b25e7;">Student Grades</h3>
            <div id="gradesModalBody" class="table-responsive">
                <div style="padding:16px; color:#666;">Loading...</div>
            </div>
            <div style="margin-top:12px; text-align:right;">
                <button onclick="closeGradesModal()" class="btn btn-violet" style="background-color:#9b25e7; color:#fff; border:none; border-radius:8px; padding:6px 14px;">Close</button>
            </div>
        </div>
    </div>

    <style>
        /* Modernize action buttons */
        .actions .btn { margin-right:8px; }
        .btn-modern{ border-radius:999px; padding:6px 14px; font-weight:600; border:1px solid transparent; box-shadow:0 1px 2px rgba(16,24,40,.05); transition:transform .15s ease, box-shadow .15s ease; }
        .btn-modern:hover{ transform:translateY(-1px); box-shadow:0 6px 12px rgba(16,24,40,.10); }
        .btn-view{ background:#6a0dad; color:#fff; }
        .btn-view:hover{ background:#570ea8; }
        .btn-print{ background:#16a34a; color:#fff; }
        .btn-print:hover{ background:#12853c; }
        .btn .icon{ display:inline-flex; align-items:center; margin-right:8px; }
        /* Remove underline on button links */
        a.btn, a.btn:hover, a.btn:focus{ text-decoration:none !important; }

        .grades-table thead th { background:#9b25e7; color:#fff; }
        .grades-table, .grades-table th, .grades-table td { border-collapse:collapse; border:1px solid #e5e7eb; }
        .text-success { color:#28a745; }
        .text-warning { color:#ffc107; }
        .text-danger { color:#dc3545; }
        .text-secondary { color:#6c757d; }
    </style>

    <script>
        async function openGradesModal(studentId) {
            const modal = document.getElementById('gradesModal');
            const body = document.getElementById('gradesModalBody');
            body.innerHTML = '<div style="padding:16px; color:#666;">Loading...</div>';
            modal.style.display = 'flex';
            // Lock background scroll and focus dialog for accessibility
            const prevOverflow = document.body.style.overflow;
            document.body.dataset.prevOverflow = prevOverflow;
            document.body.style.overflow = 'hidden';
            queueMicrotask(()=>{
              const dlg = modal.querySelector('[role="dialog"]');
              if (dlg) try { dlg.focus(); } catch(e) {}
            });
            try {
                const resp = await fetch('grades.php?action=student_grades&ajax=1&student_id=' + encodeURIComponent(studentId));
                const html = await resp.text();
                body.innerHTML = html;
            } catch (e) {
                body.innerHTML = '<div style="padding:16px; color:#b91c1c;">Failed to load grades.</div>';
            }
        }
        function closeGradesModal(){
            const modal = document.getElementById('gradesModal');
            modal.style.display = 'none';
            // Restore background scroll
            const prev = document.body.dataset.prevOverflow || '';
            document.body.style.overflow = prev;
        }
        window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeGradesModal(); });
        document.getElementById('gradesModal').addEventListener('click', (e)=>{ if (e.target.id==='gradesModal') closeGradesModal(); });
    </script>

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

        /* Big, centered year headings for Grade Records */
        .year-heading {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 800;
            color: #6a0dad;
            margin: 16px 0 10px;
            padding: 6px 0;
            border-top: 2px solid #e5e7eb;
            border-bottom: 2px solid #e5e7eb;
            letter-spacing: 0.3px;
        }

        /* Big, centered program headings for Grade Records */
        .program-heading {
            text-align: center;
            font-size: 1.75rem;
            font-weight: 900;
            color: #4b5563;
            margin: 22px 0 12px;
            padding: 8px 0 4px;
            border-bottom: 3px solid #e5e7eb;
            letter-spacing: 0.4px;
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
            #gradesModal > [role="dialog"] { width: 100%; max-height: calc(100vh - 24px); padding: 14px; border-radius:10px; }
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

