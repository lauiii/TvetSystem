<?php
/**
 * Grade Manage v2 (grademanage.php)
 * - Uses course_id for assessment_criteria
 * - Save Locally via AJAX (accepts 0)
 * - Send to Admin (Final Result only) with CSV + notify
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('instructor');

$instructorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$sectionId    = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$success = '';
$error   = '';

// Flash messages from previous POST
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (!empty($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Access check and fetch section + course
$stmt = $pdo->prepare(
    "SELECT s.*, c.id AS course_id, c.course_code, c.course_name, c.year_level, c.semester, p.name AS program_name, s.section_code
     FROM sections s
     INNER JOIN courses c ON s.course_id = c.id
     INNER JOIN programs p ON c.program_id = p.id
     INNER JOIN instructor_sections ins ON s.id = ins.section_id
     WHERE s.id = ? AND ins.instructor_id = ? AND s.status='active'"
);
$stmt->execute([$sectionId, $instructorId]);
$section = $stmt->fetch();
if (!$section) { header('Location: dashboard.php'); exit; }

// Active school year + semester label
$activeSY = $pdo->query("SELECT year, semester FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$activeYearLabel = $activeSY['year'] ?? '';
$semRaw = strtolower((string)($activeSY['semester'] ?? ''));
$activeSemLabel = '';
if ($semRaw==='1' || $semRaw==='first' || $semRaw==='1st') { $activeSemLabel = '1st Semester'; }
elseif ($semRaw==='2' || $semRaw==='second' || $semRaw==='2nd') { $activeSemLabel = '2nd Semester'; }
elseif ($semRaw==='3' || $semRaw==='summer') { $activeSemLabel = 'Summer'; }

// Resolve names (best-effort)
$instructorName = '';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('first_name',$cols) && in_array('last_name',$cols)) {
        $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM users WHERE id=? LIMIT 1");
        $st->execute([$instructorId]);
        $instructorName = trim((string)$st->fetchColumn());
    } elseif (in_array('name',$cols)) {
        $st = $pdo->prepare("SELECT name FROM users WHERE id=? LIMIT 1");
        $st->execute([$instructorId]);
        $instructorName = trim((string)$st->fetchColumn());
    }
} catch (Exception $e) {}

// TVET head name (best-effort)
$tvetHeadName = 'TVET HEAD';
try {
    $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $nameExpr = '';
    if (in_array('first_name', $ucols) && in_array('last_name', $ucols)) { $nameExpr = "CONCAT(first_name,' ',last_name) as nm"; }
    elseif (in_array('name', $ucols)) { $nameExpr = "name as nm"; }
    if ($nameExpr !== '') {
        $st = $pdo->query("SELECT $nameExpr FROM users WHERE role='admin' AND (status IS NULL OR status='active') ORDER BY id LIMIT 1");
        $nm = trim((string)($st->fetchColumn() ?: ''));
        if ($nm !== '') $tvetHeadName = strtoupper($nm);
    }
} catch (Exception $e) {}

// AJAX save (grades_json)
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest') || isset($_POST['ajax']);
if ($isAjax && $_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['grades_json'])) {
    $payload = json_decode($_POST['grades_json'], true);
    $res = processGrades($pdo, $payload, $section);
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Save via normal form submit
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_grades']) && isset($_POST['grades']) && is_array($_POST['grades'])) {
    $res = processGrades($pdo, $_POST['grades'], $section);
    if ($res['success']) { $_SESSION['flash_success'] = 'Grades submitted successfully!'; }
    else { $_SESSION['flash_error'] = 'Failed to submit grades: '.$res['message']; }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Data loads
// Assessments scoped to this section (to avoid showing course-level leftovers)
$stmt = $pdo->prepare(
    "SELECT ai.id, ai.name, ai.total_score, ac.id AS criteria_id, ac.name AS criteria_name, ac.period, ac.percentage
     FROM assessment_items ai
     INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
     WHERE ac.section_id = ?
     ORDER BY FIELD(ac.period,'prelim','midterm','finals'), ac.id, ai.id"
);
$stmt->execute([$sectionId]);
$assessments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Students by course
$stmt = $pdo->prepare(
    "SELECT e.id AS enrollment_id, u.id AS student_id, u.student_id AS student_number, u.first_name, u.last_name, u.email
     FROM enrollments e INNER JOIN users u ON e.student_id=u.id
     WHERE e.course_id=? AND e.status='enrolled'
     ORDER BY u.last_name, u.first_name"
);
$stmt->execute([$section['course_id']]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Existing grades
$grades = [];
if ($students) {
    $ids = array_column($students,'enrollment_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT enrollment_id, assessment_id, grade FROM grades WHERE enrollment_id IN ($ph)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grades[$r['enrollment_id']][$r['assessment_id']] = $r;
    }
}

// Group assessments (by period and criteria) and build assessment index for JS
$grouped = [];
$assessIndex = [];
foreach ($assessments as $a) {
    $period = $a['period'] ?: 'Unspecified';
    $cid = (int)$a['criteria_id'];
    if (!isset($grouped[$period])) $grouped[$period] = ['criteria'=>[], 'period_percentage'=>0.0, 'possible'=>0.0];
    if (!isset($grouped[$period]['criteria'][$cid])) {
        $grouped[$period]['criteria'][$cid] = ['name'=>$a['criteria_name'], 'percentage'=>(float)$a['percentage'], 'assessments'=>[], 'possible'=>0.0];
        $grouped[$period]['period_percentage'] += (float)$a['percentage'];
    }
    $grouped[$period]['criteria'][$cid]['assessments'][] = ['id'=>(int)$a['id'], 'name'=>$a['name'], 'max'=>(float)$a['total_score']];
    $grouped[$period]['criteria'][$cid]['possible'] += (float)$a['total_score'];
    $grouped[$period]['possible'] += (float)$a['total_score'];
    $assessIndex[(int)$a['id']] = ['period'=>$period, 'criteria_id'=>$cid];
}
foreach (['prelim','midterm','finals'] as $p) { if (!isset($grouped[$p])) $grouped[$p] = ['criteria'=>[], 'period_percentage'=>0.0, 'possible'=>0.0]; }

// Period meta
$periodMeta = [];
foreach ($grouped as $pname=>$pdata) { $periodMeta[$pname] = ['percentage'=>$pdata['period_percentage'], 'possible'=>$pdata['possible']]; }

// Send to Admin (Final Result)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_to_admin'])) {
    // Build summary HTML and CSV
    $sumHtml = '<h3>Grades Summary — ' . htmlspecialchars(($section['course_code']??'').' — '.($section['course_name']??'')) . ' (Section ' . htmlspecialchars($section['section_code']??'') . ')</h3>';
    foreach (['prelim','midterm','finals'] as $per) {
        $sumHtml .= '<h4 style="margin:12px 0 6px;">'.strtoupper($per).'</h4>';
        $sumHtml .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $sumHtml .= '<tr><th align="left">Student</th><th align="left">Percent</th></tr>';
        foreach ($students as $stRow) {
            $possible = (float)($periodMeta[$per]['possible'] ?? 0.0);
            $total = 0.0;
            if ($possible > 0) {
                foreach ($assessments as $aRow) {
                    if ($aRow['period'] !== $per) continue;
                    $aid = (int)$aRow['id'];
                    $enr = (int)$stRow['enrollment_id'];
                    if (isset($grades[$enr][$aid]) && $grades[$enr][$aid]['grade'] !== null) {
                        $total += (float)$grades[$enr][$aid]['grade'];
                    }
                }
            }
            $pctVal = $possible>0 ? min(99, ($total/$possible)*100.0) : null;
            $pct = ($pctVal===null?'':number_format($pctVal,2).'%');
            $sumHtml .= '<tr><td>'.htmlspecialchars($stRow['last_name'].', '.$stRow['first_name']).'</td><td>'.$pct.'</td></tr>';
        }
        $sumHtml .= '</table>';
    }
    $csv = "Student,Prelim,Midterm,Finals,Tentative,Grade,Remarks\r\n";
    foreach ($students as $stRow) {
        $enr = (int)$stRow['enrollment_id'];
        $calc = function($per) use ($periodMeta, $assessments, $grades, $enr){
            $possible = (float)($periodMeta[$per]['possible'] ?? 0.0);
            if ($possible <= 0) return null;
            $total = 0.0; $seen=0.0; $hasAll=true;
            foreach ($assessments as $ar) {
                if ($ar['period'] !== $per) continue;
                $aid = (int)$ar['id'];
                if (isset($grades[$enr][$aid]) && $grades[$enr][$aid]['grade']!==null) { $total += (float)$grades[$enr][$aid]['grade']; $seen += (float)$ar['total_score']; } else { $hasAll=false; }
            }
            if (!$hasAll || $seen < $possible) return null; // incomplete
            return min(99, ($total/$possible)*100.0);
        };
        $p=$calc('prelim'); $m=$calc('midterm'); $f=$calc('finals');
        $wPre=30; $wMid=30; $wFin=40; $den=$wPre+$wMid+$wFin;
        $haveAny = ($p!==null)||($m!==null)||($f!==null);
        $tent = $haveAny ? min(99, ((($p??0)*$wPre + ($m??0)*$wMid + ($f??0)*$wFin)/$den)) : null;
        $leeFinal = $tent===null ? null : lee_from_percent($tent);
        $hasBlank = ($p===null)||($m===null)||($f===null);
        $remarks = 'Incomplete';
        if ($tent !== null) { $remarks = ($tent >= 75) ? 'Passed' : ($hasBlank ? 'Incomplete' : 'Failed'); }
        $csv .= '"'.str_replace('"','""',$stRow['last_name'].', '.$stRow['first_name']).'",'
              . ($p===null?'':number_format($p,2)).','
              . ($m===null?'':number_format($m,2)).','
              . ($f===null?'':number_format($f,2)).','
              . ($tent===null?'':number_format($tent,2)).','
              . ($leeFinal===null?'':number_format($leeFinal,2)).','
              . $remarks . "\r\n";
    }
    $ok = sendEmailWithAttachment('ascbtvet@gmail.com', 'Admin', 'Section Grades — '.($section['course_code']??''), $sumHtml, 'grades.csv', $csv, 'text/csv');

    // Record submission for this course and active school year so admin views are gated
    $subRecorded = false;
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS course_grade_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            school_year_id INT NULL,
            instructor_id INT NULL,
            section_id INT NULL,
            submitted_at DATETIME NOT NULL,
            UNIQUE KEY uniq_course_sy (course_id, school_year_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Resolve active school year id (best-effort)
        $syRow = $pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $syId = isset($syRow['id']) ? (int)$syRow['id'] : null;

        $insSub = $pdo->prepare("INSERT INTO course_grade_submissions (course_id, school_year_id, instructor_id, section_id, submitted_at)
                                  VALUES (?,?,?,?,NOW())
                                  ON DUPLICATE KEY UPDATE instructor_id=VALUES(instructor_id), section_id=VALUES(section_id), submitted_at=VALUES(submitted_at)");
        $insSub->execute([ (int)$section['course_id'], $syId, (int)$instructorId, (int)$sectionId ]);
        $subRecorded = true;
    } catch (Exception $e) {
        // no-op: submission record is best-effort
        try { @file_put_contents(__DIR__.'/../logs/grades_errors.log', date('c')." | submission_record_failed: ".$e->getMessage()."\n", FILE_APPEND); } catch (Exception $ie) {}
    }
    // Success criteria: submission recorded; email is best-effort
    if ($subRecorded) {
        $_SESSION['flash_success'] = 'Grades submitted to admin.' . ($ok ? '' : ' (Email notification failed)');
    } else {
        $_SESSION['flash_error'] = 'Failed to submit grades to admin.' . ($ok ? '' : ' (Email also failed)');
    }

    // Best-effort admin notify (separate informational email + in-app)
    try {
        $course = ($section['course_code'] ?? '') . ' — ' . ($section['course_name'] ?? '');
        $sec = $section['section_code'] ?? '';
        $instrName = isset($_SESSION['name']) && $_SESSION['name'] ? $_SESSION['name'] : ($instructorName ?: 'Unknown');
        $semVal = isset($section['semester']) ? (int)$section['semester'] : null;
        $semName = ($semVal === 1 ? 'First' : ($semVal === 2 ? 'Second' : ($semVal === 3 ? 'Summer' : '')));
        $subject = 'Grades Submitted — ' . $course . ' (Section ' . $sec . ($semName!=='' ? (', Sem ' . $semName) : '') . ')';
        $body = '<p>Grades have been submitted.</p>'
              . '<p><strong>Course:</strong> ' . htmlspecialchars($course) . '<br>'
              . '<strong>Section:</strong> ' . htmlspecialchars($sec) . '<br>'
              . ($semName !== '' ? ('<strong>Semester:</strong> ' . htmlspecialchars($semName) . '<br>') : '')
              . '<strong>Instructor:</strong> ' . htmlspecialchars($instrName) . '<br>'
              . '<strong>When:</strong> ' . date('Y-m-d H:i:s') . '</p>'
              . '<p>You can review in the system.</p>';
        @sendNotificationEmail('ascbtvet@gmail.com', 'Admin', $subject, $body);
        try {
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
            if ($admins) {
                $title = 'Grades Submitted by ' . $instrName;
                $msg = 'Instructor ' . $instrName . ' submitted grades for ' . $course . ' (Section ' . $sec . ($semName!=='' ? (', Sem ' . $semName) : '') . ').';
                notify_users($pdo, array_map('intval',$admins), $title, $msg, 'info', null, null);
            }
        } catch (Exception $x) {}
    } catch (Exception $e) {}

    try { @file_put_contents(__DIR__.'/../logs/grades_errors.log', date('c')." | send_to_admin ok=".($ok?'1':'0')."\n", FILE_APPEND); } catch (Exception $e) {}
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

function processGrades(PDO $pdo, $gradesPayload, $section) {
    try {
        $pdo->beginTransaction();
        // Valid sets
        $st = $pdo->prepare("SELECT id FROM assessment_items WHERE criteria_id IN (SELECT id FROM assessment_criteria WHERE section_id=?)");
        $st->execute([ (int)($section['id'] ?? 0) ]);
        $validAssess = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC),'id'));
        $st2 = $pdo->prepare("SELECT id FROM enrollments WHERE course_id=? AND status='enrolled'");
        $st2->execute([(int)$section['course_id']]);
        $validEnroll = array_flip(array_column($st2->fetchAll(PDO::FETCH_ASSOC),'id'));

        if ($gradesPayload && is_array($gradesPayload)) {
            $ins = $pdo->prepare("INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at)
                                  VALUES (?,?,?,?,NOW())
                                  ON DUPLICATE KEY UPDATE grade=VALUES(grade), status=VALUES(status), submitted_at=NOW()");
            $invalid = [];
            foreach ($gradesPayload as $enrollmentId => $asses) {
                if (!isset($validEnroll[$enrollmentId])) { $invalid[]="enr:$enrollmentId"; continue; }
                foreach ($asses as $assessmentId => $g) {
                    if (!isset($validAssess[$assessmentId])) { $invalid[]="asm:$assessmentId"; continue; }
                    $raw = isset($g['grade']) ? trim((string)$g['grade']) : '';
                    if ($raw !== '') {
                        if (!is_numeric($raw)) throw new Exception("Invalid grade for enrollment $enrollmentId, assessment $assessmentId");
                        $grade = (float)$raw; $status='complete';
                    } else { $grade = null; $status='incomplete'; }
                    $ins->execute([$enrollmentId, $assessmentId, $grade, $status]);
                }
            }
            if ($invalid) throw new Exception('Invalid IDs in payload: '.implode(', ', array_unique($invalid)));
        }
        $pdo->commit();
        return ['success'=>true, 'message'=>'Grades saved.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        try { @file_put_contents(__DIR__.'/../logs/grades_errors.log', date('c')." | save_failed: ".$e->getMessage()."\n", FILE_APPEND); } catch (Exception $ie) {}
        return ['success'=>false, 'message'=>$e->getMessage()];
    }
}

// Page
$periodKeys = array_keys($grouped);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <title>Manage Grades — <?php echo htmlspecialchars($section['course_name']); ?> (<?php echo htmlspecialchars($section['section_code']); ?>)</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/manage-grades.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
<div class="page">
    <header class="page-header">
        <div>
            <h1>Manage Grades</h1>
            <div class="meta"><?php echo htmlspecialchars($section['course_code'].' — '.$section['course_name']); ?> · Section <?php echo htmlspecialchars($section['section_code']); ?> · Program <?php echo htmlspecialchars($section['program_name']); ?><?php if($activeYearLabel!==''){ echo ' · SY '.htmlspecialchars($activeYearLabel); } ?><?php if($activeSemLabel!==''){ echo ' · '.htmlspecialchars($activeSemLabel); } ?></div>
        </div>
        <div class="meta small" style="display:flex; gap:8px; align-items:center;">
            <a href="dashboard.php" class="btn btn-primary">🏠 Dashboard</a>
            <a href="assessments_alt.php?section_id=<?php echo (int)$sectionId; ?>" class="btn btn-secondary">🧮 Assessments</a>
        </div>
    </header>

    <?php if ($success): ?><div class="notice success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="grades-grid">
        <div class="left">
            <form id="gradesForm" method="POST" onsubmit="return validateAll();">
                <div id="periodTabs" style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
                    <?php foreach ($periodKeys as $i=>$pkey): ?>
                        <button type="button" class="btn period-tab <?php echo $i===0?'primary':'ghost'; ?>" data-show-period="<?php echo htmlspecialchars($pkey); ?>"><?php echo htmlspecialchars(ucfirst($pkey)); ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="btn period-tab ghost" data-show-period="final-result">Final Result</button>
                </div>

                <?php foreach ($grouped as $pname=>$pdata):
                    $periodAssessments = [];
                    foreach ($pdata['criteria'] as $cid=>$cdata) {
                        foreach ($cdata['assessments'] as $ass) { $periodAssessments[] = $ass + ['period'=>$pname]; }
                    }
                ?>
                <div class="period-table-wrap<?php echo ($pname===$periodKeys[0]?' active':''); ?>" data-period="<?php echo htmlspecialchars($pname); ?>" style="<?php echo ($pname===$periodKeys[0]?'':'display:none;'); ?> overflow:auto;">
                    <table class="grades grades-period-table" id="gradesTable-<?php echo htmlspecialchars($pname); ?>">
                        <thead>
                        <tr>
                            <th rowspan="3">Student</th>
                            <th colspan="<?php echo count($periodAssessments)+1; ?>"><?php echo htmlspecialchars(ucfirst($pname)); ?> <br><small class="small"><?php echo number_format($pdata['period_percentage'],2); ?>%</small></th>
                            <th rowspan="3">Grade</th>
                            <th rowspan="3">Remarks</th>
                        </tr>
                        <tr>
                            <?php foreach ($pdata['criteria'] as $cid=>$cdata): $cspan=count($cdata['assessments']); ?>
                                <th colspan="<?php echo (int)$cspan; ?>"><?php echo htmlspecialchars($cdata['name']); ?><br><small class="small"><?php echo number_format($cdata['percentage'],2); ?>%</small></th>
                            <?php endforeach; ?>
                            <th>Total (%)</th>
                        </tr>
                        <tr>
                            <?php foreach ($periodAssessments as $a): ?>
                                <th data-assessment-id="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?><br><small class="small">Max: <?php echo htmlspecialchars(number_format($a['max'],2)); ?></small></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $student): $enroll=(int)$student['enrollment_id']; ?>
                            <tr data-enrollment-id="<?php echo $enroll; ?>">
                                <td style="min-width:220px;"><strong><?php echo htmlspecialchars($student['last_name'].', '.$student['first_name']); ?></strong><br><small class="small"><?php echo htmlspecialchars($student['student_number']); ?></small></td>
                                <?php foreach ($periodAssessments as $a): $aid=(int)$a['id']; $existing = $grades[$enroll][$aid] ?? null; ?>
                                    <td>
                                        <input type="number" class="grade-input" step="0.01" min="0" max="<?php echo htmlspecialchars($a['max']); ?>"
                                               name="grades[<?php echo $enroll; ?>][<?php echo $aid; ?>][grade]"
                                               data-assessment-id="<?php echo (int)$aid; ?>"
                                               data-max="<?php echo htmlspecialchars($a['max']); ?>"
                                               data-period="<?php echo htmlspecialchars($pname); ?>"
                                               value="<?php echo $existing ? htmlspecialchars($existing['grade']) : ''; ?>">
                                    </td>
                                <?php endforeach; ?>
                                <td class="period-total-cell" data-period="<?php echo htmlspecialchars($pname); ?>" style="text-align:right; font-weight:600;">N/A</td>
                                <td class="lee-cell" style="text-align:right; font-weight:600;">-</td>
                                <td class="remarks-cell" style="text-align:right; font-weight:600;">-</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>

                <div class="period-table-wrap" data-period="final-result" style="display:none; overflow:auto;">
                    <table class="grades grades-period-table" id="gradesTable-final">
                        <thead>
                        <tr>
                            <th>Student</th>
                            <th>Prelim (%)</th>
                            <th>Midterm (%)</th>
                            <th>Finals (%)</th>
                            <th>Tentative (%)</th>
                            <th>Grade</th>
                            <th>Remarks</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $student): $enroll=(int)$student['enrollment_id']; ?>
                            <?php
                            $calc = function($per) use ($periodMeta, $assessments, $grades, $enroll){
                                $possible = (float)($periodMeta[$per]['possible'] ?? 0.0);
                                if ($possible <= 0) return null;
                                $total=0.0; $hasAll=true; $seen=0.0;
                                foreach ($assessments as $ar) {
                                    if ($ar['period'] !== $per) continue;
                                    $aid=(int)$ar['id'];
                                    if (isset($grades[$enroll][$aid]) && $grades[$enroll][$aid]['grade']!==null) { $total += (float)$grades[$enroll][$aid]['grade']; $seen += (float)$ar['total_score']; } else { $hasAll=false; }
                                }
                                if (!$hasAll || $seen < $possible) return null;
                                $val = ($total / $possible) * 100.0;
                                return min(99, $val);
                            };
                            $p = $calc('prelim');
                            $m = $calc('midterm');
                            $f = $calc('finals');
                            $wPre=30.0; $wMid=30.0; $wFin=40.0; $den=$wPre+$wMid+$wFin;
                            $haveAny = ($p!==null)||($m!==null)||($f!==null);
                            $cum = $haveAny ? min(99, ((($p??0)*$wPre + ($m??0)*$wMid + ($f??0)*$wFin)/$den)) : null;
                            $remark='Incomplete'; $cls='text-secondary';
                            $hasBlank = ($p===null)||($m===null)||($f===null);
                            if ($cum !== null) {
                                if ($cum >= 75.0) { $remark='Passed'; $cls='text-success'; }
                                else { $remark = $hasBlank ? 'Incomplete' : 'Failed'; $cls = $hasBlank ? 'text-secondary' : 'text-danger'; }
                            }
                            $leeFinal = $cum===null ? null : lee_from_percent($cum);
                            ?>
                            <tr data-enrollment-id="<?php echo $enroll; ?>">
                                <td style="min-width:220px;"><strong><?php echo htmlspecialchars($student['last_name'].', '.$student['first_name']); ?></strong><br><small class="small"><?php echo htmlspecialchars($student['student_number']); ?></small></td>
                                <td class="prelim-cell"><?php echo $p===null?'':number_format($p,2); ?></td>
                                <td class="midterm-cell"><?php echo $m===null?'':number_format($m,2); ?></td>
                                <td class="finals-cell"><?php echo $f===null?'':number_format($f,2); ?></td>
                                <td class="tentative-cell"><?php echo $cum===null?'':number_format($cum,2); ?></td>
                                <td class="lee-cell"><?php echo $leeFinal===null?'—':number_format($leeFinal,2); ?></td>
                                <td class="remarks-cell <?php echo $cls; ?>"><?php echo $remark; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="controls">
                    <button type="submit" name="submit_grades" value="1" id="saveBtn" class="btn primary">Save All Grades</button>
                    <button type="button" id="resetBtn" class="btn ghost">Reset</button>
                    <button type="button" class="btn ghost" onclick="window.print()">Print</button>
                    <button type="button" id="sendToAdminBtn" class="btn btn-secondary">Send to Admin</button>
                </div>
                <div id="ajaxMessage" style="margin-top:12px; display:none;"></div>
            </form>
        </div>
    </div>
</div>

<script>
function qs(s,c=document){return c.querySelector(s)}
function qsa(s,c=document){return Array.from(c.querySelectorAll(s))}
const periodMeta = <?php echo json_encode($periodMeta); ?>;
const assessIndex = <?php echo json_encode($assessIndex); ?>;
const groupedMeta = <?php echo json_encode($grouped); ?>;

function showPeriod(p){
  qsa('.period-table-wrap').forEach(div=>{div.style.display = (div.getAttribute('data-period')===p)?'':'none'; div.classList.toggle('active', div.getAttribute('data-period')===p)})
  qsa('.period-tab').forEach(btn=>{if(btn.getAttribute('data-show-period')===p){btn.classList.remove('ghost');btn.classList.add('primary')}else{btn.classList.add('ghost');btn.classList.remove('primary')}})
  const sendBtn = qs('#sendToAdminBtn'); if (sendBtn) sendBtn.style.display = (p==='final-result')?'':'none';
}

document.addEventListener('DOMContentLoaded', ()=>{
  const tabs = qs('#periodTabs'); if (tabs){ tabs.addEventListener('click', e=>{ const b=e.target.closest('.period-tab'); if(!b)return; const p=(b.getAttribute('data-show-period')||'').toLowerCase(); if(p) showPeriod(p); }); }
  // default: first tab active; hide sendToAdmin
  const sendBtn = qs('#sendToAdminBtn'); if (sendBtn) sendBtn.style.display='none';
  recalcAll();
});

function validateAll(){
  let ok=true; qsa('input.grade-input').forEach(inp=>{ inp.style.outline='none'; const v=inp.value.trim(); if(v==='')return; if(isNaN(v)){ok=false; inp.style.outline='2px solid #ffb4b4';} else { const max=parseFloat(inp.getAttribute('data-max')); if(!isNaN(max) && parseFloat(v)>max){ok=false; inp.style.outline='2px solid #ffb4b4'; } } }); return ok;
}
function gatherGrades(){ const data={}; qsa('table.grades-period-table tbody tr').forEach(row=>{ const enr=row.getAttribute('data-enrollment-id'); const inputs=qsa('input.grade-input',row); if(!enr||inputs.length===0) return; data[enr]={}; inputs.forEach(inp=>{const aid=inp.getAttribute('data-assessment-id'); data[enr][aid]={grade:inp.value.trim()};}); }); return data; }
function showMessage(msg, ok=true){ const el=qs('#ajaxMessage'); el.style.display='block'; el.textContent=msg; el.className = ok ? 'notice success' : 'notice error'; setTimeout(()=> el.style.display='none', 4000); }

function leeFromPercent(p){ if(p==null||isNaN(p)) return null; const x=Math.round(p); if(x>=95) return 1.00; if(x===94) return 1.10; if(x===93) return 1.20; if(x===92) return 1.30; if(x===91) return 1.40; if(x===90) return 1.50; if(x===89) return 1.60; if(x===88) return 1.70; if(x===87) return 1.80; if(x===86) return 1.90; if(x===85) return 2.00; if(x===84) return 2.10; if(x===83) return 2.20; if(x===82) return 2.30; if(x===81) return 2.40; if(x===80) return 2.50; if(x===79) return 2.60; if(x===78) return 2.70; if(x===77) return 2.80; if(x===76) return 2.90; if(x===75) return 3.00; if(x<75) return 5.00; return 1.00; }
function recalcAll(){
  // 1) Update per-period rows: percent, LEE, and remarks per row
  const periodRows = qsa(".grades-period-table tbody tr[data-enrollment-id]");
  periodRows.forEach(row=>{
    const wrap = row.closest('.period-table-wrap');
    const period = wrap ? (wrap.getAttribute('data-period')||'').toLowerCase() : '';
    if (!period || period==='final-result') return;
    const inputs = qsa('input.grade-input', row);
    // Aggregate by criteria for weighted computation
    const criteriaTotals = {}; const criteriaSeen = {}; const criteriaPossible = {};
    const gm = groupedMeta[period] ? groupedMeta[period].criteria : {};
    Object.keys(gm||{}).forEach(cid=>{ criteriaTotals[cid]=0.0; criteriaSeen[cid]=0.0; criteriaPossible[cid]=parseFloat((gm[cid]||{}).possible)||0.0; });
    inputs.forEach(inp=>{
      const aid = inp.getAttribute('data-assessment-id');
      const map = assessIndex[parseInt(aid||'0')]; if(!map) return;
      const cid = String(map.criteria_id||''); if(!cid) return;
      const v = inp.value.trim();
      const max = parseFloat(inp.getAttribute('data-max'))||0.0;
      if (v!=='' && !isNaN(v)) { criteriaTotals[cid] += parseFloat(v); criteriaSeen[cid] += max; }
    });
    // Weighted period percent = sum_over_criteria( (tot/possible)*criteriaPercentage )
    let pct = null; let complete = true; let accum = 0.0;
    Object.keys(criteriaTotals).forEach(cid=>{
      const poss = criteriaPossible[cid]||0.0;
      const cfg = (gm[cid]||{});
      const cPct = poss>0 ? (Math.min(99,(criteriaTotals[cid]/poss)*100.0)) : null;
      const weight = parseFloat(cfg.percentage||0);
      if (cPct==null) { complete=false; return; }
      if ((criteriaSeen[cid]||0) < poss) { complete=false; }
      accum += (cPct * (weight/100.0));
    });
    if (!isNaN(accum)) pct = accum;
    // If period config not 100% exactly, treat as incomplete
    const perCfg = (groupedMeta[period]||{}).period_percentage||0.0;
    if (Math.abs(perCfg - 100.0) > 0.001) complete = false;
    // Update cells in this row
    const pctCell = row.querySelector(`td.period-total-cell[data-period="${period}"]`);
    if (pctCell) pctCell.textContent = pct==null ? '' : pct.toFixed(2)+'%';

    const leeCell = row.querySelector('td.lee-cell');
    const remCell = row.querySelector('td.remarks-cell');
    let lee = null, remText='Incomplete', remCls='text-secondary';
    if (pct!=null && complete) {
      lee = leeFromPercent(pct);
      if (lee<=3.0) { remText='Passed'; remCls='text-success'; }
      else if (lee>3.0 && lee<5.0) { remText='Conditional'; remCls='text-warning'; }
      else { remText='Failed'; remCls='text-danger'; }
    }
    if (leeCell) leeCell.textContent = lee==null ? '—' : lee.toFixed(2);
    if (remCell) { remCell.textContent = remText; remCell.className = 'remarks-cell '+remCls; }
  });

  // 2) Aggregate per student across periods for Final Result table
  const finalTable = qs('#gradesTable-final tbody');
  const perStudent = {};
  qsa(".grades-period-table tbody tr[data-enrollment-id]").forEach(row=>{
    const enr = row.getAttribute('data-enrollment-id'); if(!enr) return;
    if(!perStudent[enr]) perStudent[enr] = { totals:{}, };
    const inputs = qsa('input.grade-input', row);
    // Compute weighted period score per period
    const perTotals = {};
    inputs.forEach(inp=>{
      const period = (inp.getAttribute('data-period')||'').toLowerCase();
      const aid = parseInt(inp.getAttribute('data-assessment-id')||'0');
      const map = assessIndex[aid]; if(!map) return;
      const cid = String(map.criteria_id||'');
      const v = inp.value.trim(); const num = (v===''||isNaN(v)) ? null : parseFloat(v);
      const max = parseFloat(inp.getAttribute('data-max'))||0.0;
      if(!perTotals[period]) perTotals[period] = {};
      if(!perTotals[period][cid]) perTotals[period][cid] = {sum:0.0, seen:0.0};
      if(num!==null){ perTotals[period][cid].sum += num; perTotals[period][cid].seen += max; }
    });
    Object.keys(perTotals).forEach(p=>{
      let accum = 0.0; let complete = true;
      const gm = (groupedMeta[p]||{}).criteria||{};
      Object.keys(gm).forEach(cid=>{
        const poss = parseFloat((gm[cid]||{}).possible)||0.0;
        const weight = parseFloat((gm[cid]||{}).percentage)||0.0;
        const seen = (perTotals[p][cid]||{}).seen||0.0;
        const sum  = (perTotals[p][cid]||{}).sum||0.0;
        if (poss>0){ const cPct = seen>=poss ? Math.min(99,(sum/poss)*100.0) : null; if (cPct==null) complete=false; else accum += cPct*(weight/100.0); }
      });
      perStudent[enr].totals[p] = isNaN(accum)? null : accum;
      // Enforce 100% config
      const perCfg = (groupedMeta[p]||{}).period_percentage||0.0;
      if (Math.abs(perCfg-100.0)>0.001) perStudent[enr].totals[p] = null;
    });
  });

  // Update rows
  Object.keys(perStudent).forEach(enr=>{
    const totals = perStudent[enr].totals;
    const p = totals['prelim']!=null ? Math.min(99, totals['prelim']) : null;
    const m = totals['midterm']!=null ? Math.min(99, totals['midterm']) : null;
    const f = totals['finals']!=null ? Math.min(99, totals['finals']) : null;

    // Update period table inline total cells if present
    qsa(`tr[data-enrollment-id="${enr}"] td.period-total-cell[data-period="prelim"]`).forEach(td=>{ td.textContent = (p==null?'':p.toFixed(2)+'%'); });
    qsa(`tr[data-enrollment-id="${enr}"] td.period-total-cell[data-period="midterm"]`).forEach(td=>{ td.textContent = (m==null?'':m.toFixed(2)+'%'); });
    qsa(`tr[data-enrollment-id="${enr}"] td.period-total-cell[data-period="finals"]`).forEach(td=>{ td.textContent = (f==null?'':f.toFixed(2)+'%'); });
    // Final result updates with Conditional logic
    const wPre=30.0, wMid=30.0, wFin=40.0, den=wPre+wMid+wFin;
    const achieved = (p||0)*(wPre/100.0) + (m||0)*(wMid/100.0) + (f||0)*(wFin/100.0);
    const remW = (p==null? wPre:0) + (m==null? wMid:0) + (f==null? wFin:0);
    const target = 75.0; // target percentage overall
    let cum = null, lee = null, remark='Incomplete', cls='text-secondary';
    if (remW <= 0) {
      // Nothing remaining -> final
      cum = Math.min(99, achieved); // already weighted sum
      if (cum >= target) { remark='Passed'; cls='text-success'; lee = leeFromPercent(cum); }
      else { remark='Failed'; cls='text-danger'; lee = 5.00; }
    } else {
      // Still remaining periods
      const needed = (target - achieved) / (remW/100.0); // needed average percent across remaining periods
      if (needed <= 0) {
        remark='Passed'; cls='text-success'; // already safe regardless of remaining
        cum = Math.min(99, achieved);
        lee = leeFromPercent(cum);
      } else if (needed > 100) {
        remark = 'Will Fail (needs > 100% in remaining)'; cls='text-danger';
        cum = Math.min(99, achieved);
        // no LEE yet
      } else {
        remark = 'Conditional — needs ≥ ' + needed.toFixed(2) + '% avg on remaining'; cls='text-warning';
        cum = Math.min(99, achieved);
        // no LEE yet
      }
    }
    const fr = finalTable ? qs(`tr[data-enrollment-id="${enr}"]`, finalTable) : null;
    if(fr){
      const pc = qs('.prelim-cell', fr); if(pc) pc.textContent = p==null?'':p.toFixed(2);
      const mc = qs('.midterm-cell', fr); if(mc) mc.textContent = m==null?'':m.toFixed(2);
      const fc = qs('.finals-cell', fr); if(fc) fc.textContent = f==null?'':f.toFixed(2);
      const tc = qs('.tentative-cell', fr); if(tc) tc.textContent = cum==null?'':cum.toFixed(2);
      const lc = qs('.lee-cell', fr); if(lc) lc.textContent = (lee==null?'—':lee.toFixed(2));
      const rc = qs('.remarks-cell', fr); if(rc){ rc.textContent = remark; rc.className = 'remarks-cell '+cls; }
    }
  });
}

document.addEventListener('input', e=>{ if(e.target.matches('input.grade-input')) { recalcAll(); } });
// Pressing Enter in a grade input should behave like clicking Save (include submit_grades)
document.addEventListener('keydown', e=>{
  if(e.target && e.target.matches('input.grade-input') && (e.key==='Enter' || e.code==='NumpadEnter')){
    e.preventDefault();
    const form = qs('#gradesForm');
    const saveBtn = qs('#saveBtn');
    if (form) {
      if (form.requestSubmit) {
        form.requestSubmit(saveBtn || undefined);
      } else {
        // Fallback for very old browsers
        if (!qs('input[name="submit_grades"]', form)) {
          const hid = document.createElement('input');
          hid.type='hidden'; hid.name='submit_grades'; hid.value='1';
          form.appendChild(hid);
        }
        form.submit();
      }
    }
  }
});
qs('#resetBtn').addEventListener('click', ()=>{ qs('#gradesForm').reset(); });

// Send to Admin guarded submit
qs('#sendToAdminBtn').addEventListener('click', ()=>{
  if(!confirm('Send Final Result to admin now?')) return;
  const f = qs('#gradesForm');
  // Ensure we only send to admin; create a temporary hidden input
  const h = document.createElement('input'); h.type='hidden'; h.name='send_to_admin'; h.value='1'; f.appendChild(h);
  f.submit();
});
</script>
</body>
</html>
