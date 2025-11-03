<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$studentId = intval($_GET['student_id'] ?? 0);
if ($studentId <= 0) { die('Invalid student'); }

// Active semester/year
$activeSY = $pdo->query("SELECT id, year, semester FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$syId = $activeSY['id'] ?? 0;

// Student info (include year & program)
$stu = $pdo->prepare("SELECT u.student_id, u.first_name, u.last_name, u.year_level, p.name as program_name
                      FROM users u
                      LEFT JOIN programs p ON u.program_id = p.id
                      WHERE u.id = ? LIMIT 1");
$stu->execute([$studentId]);
$s = $stu->fetch(PDO::FETCH_ASSOC);
$fullname = $s ? ($s['last_name'] . ', ' . $s['first_name']) : 'Student';

// Pull graded rows for this student (with item max for completeness calc)
$stmt = $pdo->prepare("SELECT c.id as course_id, c.course_code, c.course_name, c.units, ac.period, ai.total_score, g.grade
    FROM grades g
    INNER JOIN assessment_items ai ON g.assessment_id = ai.id
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    INNER JOIN enrollments e ON g.enrollment_id = e.id
    INNER JOIN courses c ON e.course_id = c.id
    WHERE e.student_id = ? AND e.school_year_id = ?
    ORDER BY c.course_code, FIELD(ac.period,'prelim','midterm','finals')");
$stmt->execute([$studentId, $syId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All enrolled courses for this student (ensure rows even without grades)
$cstmt = $pdo->prepare("SELECT c.id AS course_id, c.course_code, c.course_name, c.units, c.semester
    FROM enrollments e
    INNER JOIN courses c ON e.course_id = c.id
    WHERE e.student_id = ? AND e.school_year_id = ?
    ORDER BY c.course_code");
$cstmt->execute([$studentId, $syId]);
$enrolledCourses = $cstmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total possible per course per period for completeness
$ps = $pdo->prepare("SELECT c.id as course_id, ac.period, SUM(ai.total_score) as possible
    FROM assessment_items ai
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    INNER JOIN courses c ON ac.course_id = c.id
    WHERE c.id IN (SELECT e.course_id FROM enrollments e WHERE e.student_id = ? AND e.school_year_id = ?)
    GROUP BY c.id, ac.period");
$ps->execute([$studentId, $syId]);
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

// Build final-per-course rows (include all enrolled courses)
$finals = [];
foreach ($enrolledCourses as $c) {
    $cid = (int)$c['course_id'];
    $periods = $possibleMap[$cid] ?? [];
    $pPos = $periods['prelim'] ?? 0.0; $mPos = $periods['midterm'] ?? 0.0; $fPos = $periods['finals'] ?? 0.0;
    $pSum = $sumGrades[$cid]['prelim'] ?? 0.0; $mSum = $sumGrades[$cid]['midterm'] ?? 0.0; $fSum = $sumGrades[$cid]['finals'] ?? 0.0;
    $pSub = $submittedPossible[$cid]['prelim'] ?? 0.0; $mSub = $submittedPossible[$cid]['midterm'] ?? 0.0; $fSub = $submittedPossible[$cid]['finals'] ?? 0.0;

    $pPct = ($pPos>0) ? min(99, ($pSum/$pPos)*100.0) : null; $pComplete = ($pPos>0 && $pSub >= $pPos);
    $mPct = ($mPos>0) ? min(99, ($mSum/$mPos)*100.0) : null; $mComplete = ($mPos>0 && $mSub >= $mPos);
    $fPct = ($fPos>0) ? min(99, ($fSum/$fPos)*100.0) : null; $fComplete = ($fPos>0 && $fSub >= $fPos);
    $haveAny = ($pPct !== null) || ($mPct !== null) || ($fPct !== null);
    $tent = $haveAny ? min(99, ((($pPct??0)*30 + ($mPct??0)*30 + ($fPct??0)*40) / 100.0)) : null;
    $hasBlank = !$pComplete || !$mComplete || !$fComplete;
    // Remarks: blank if incomplete or has blanks; only show Passed/Failed when complete
    $remarks = '';
    if ($tent !== null && !$hasBlank) { $remarks = ($tent >= 75) ? 'Passed' : 'Failed'; }
    $finals[] = ['code'=>$c['course_code'], 'name'=>$c['course_name'], 'units'=> (int)($c['units'] ?? 3), 'grade'=>$tent, 'remarks'=>$remarks];
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Grades</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial; color:#222; }
        .hdr { border-bottom:2px solid #9b25e7; padding-bottom:8px; margin-bottom:12px; }
        h1 { margin:0 0 8px 0; color:#9b25e7; font-size:20px; }
        .school-name { text-align:center; font-weight:800; font-size:22px; letter-spacing:0.04em; margin-top:4px; }
        .school-address { text-align:center; color:#555; font-size:13px; margin-top:2px; }
        .grading-title { text-align:center; font-weight:700; letter-spacing:0.35em; text-transform:uppercase; margin:18px 0 24px; color:#333; }
        .meta div { margin:4px 0; }
        .grades-table { width:100%; border-collapse:collapse; }
        .grades-table th { background:#9b25e7; color:#fff; }
        .grades-table, .grades-table th, .grades-table td { border:1px solid #e5e7eb; }
        .text-success { color:#28a745; }
        .text-danger { color:#dc3545; }
        .text-secondary { color:#6c757d; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="school-name">ANDRES SORIANO COLLEGES OF BISLIG</div>
    <div class="school-address">Mangagoy, Bislig City</div>
    <div class="grading-title">G R A D I N G - E V A L U A T I O N</div>
    <?php $semRaw = strtolower((string)($activeSY['semester'] ?? '')); $semLabel = ''; if ($semRaw==='1'||$semRaw==='first'||$semRaw==='1st'){ $semLabel='1st Semester'; } elseif ($semRaw==='2'||$semRaw==='second'||$semRaw==='2nd'){ $semLabel='2nd Semester'; } elseif ($semRaw==='3'||$semRaw==='summer'){ $semLabel='Summer'; } if ($semLabel===''){ $sems=[]; foreach(($enrolledCourses??[]) as $cinfoTmp){ $sv=(int)($cinfoTmp['semester']??0); if($sv>0 && !in_array($sv,$sems,true)) $sems[]=$sv; } if(count($sems)===1){ $semLabel=($sems[0]===1?'1st Semester':($sems[0]===2?'2nd Semester':($sems[0]===3?'Summer':''))); } elseif(count($sems)>1){ $semLabel='All Semesters'; } else { $semLabel='Semester'; } } $yearNum = intval($s['year_level'] ?? 0); $yrLabel = $yearNum ? ($yearNum===1?'1st Year':($yearNum===2?'2nd Year':($yearNum===3?'3rd Year':($yearNum===4?'4th Year':'Year '.$yearNum)))) : ''; ?>
    <div class="hdr">
        <h1>Student Grades</h1>
        <div class="meta">
            <div><strong>Name:</strong> <?php echo htmlspecialchars($fullname); ?></div>
            <div><strong>Student #:</strong> <?php echo htmlspecialchars($s['student_id'] ?? ''); ?></div>
            <div><strong>Year Level:</strong> <?php echo htmlspecialchars($yrLabel); ?></div>
            <div><strong>Program:</strong> <?php echo htmlspecialchars($s['program_name'] ?? ''); ?></div>
            <div><strong>Semester:</strong> <?php echo htmlspecialchars($semLabel); ?></div>
            <div><strong>School Year:</strong> <?php echo htmlspecialchars($activeSY['year'] ?? ''); ?></div>
        </div>
    </div>

    <?php if (empty($finals)): ?>
        <div style="padding:16px; color:#666;">No grades found for the active term.</div>
    <?php else: ?>
        <table class="grades-table">
            <thead>
                <tr>
                    <th style="text-align:left;">Course Code</th>
                    <th style="text-align:left;">Description</th>
                    <th style="text-align:center;">Units</th>
                    <th style="text-align:center;">Remarks</th>
                    <th style="text-align:center;">Final Rating</th>
                </tr>
            </thead>
            <tbody>
<?php $sumLee = 0.0; $countLee = 0; foreach ($finals as $fr): $lee = ($fr['grade']===null) ? null : lee_from_percent($fr['grade']); if ($lee !== null) { $sumLee += $lee; $countLee++; } $cls = ($fr['remarks']==='Passed') ? 'text-success' : (($fr['remarks']==='Failed') ? 'text-danger' : 'text-secondary'); $units = (int)($fr['units'] ?? 3); ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($fr['code']); ?></strong></td>
                        <td><?php echo htmlspecialchars($fr['name']); ?></td>
<td style=\"text-align:center;\"><?php echo $units; ?></td>
                        <td class="<?php echo $cls; ?>" style="text-align:center;"><?php echo htmlspecialchars($fr['remarks']); ?></td>
                        <td style="text-align:center;"><?php echo $lee===null ? '' : htmlspecialchars(number_format($lee,2)); ?></td>
                    </tr>
                <?php endforeach; $avgLee = $countLee ? ($sumLee / $countLee) : null; ?>
                <tr>
                    <td colspan="4" style="text-align:right; font-weight:700;">Average Final Rating</td>
                    <td style="text-align:center; font-weight:700;"><?php echo $avgLee===null ? '' : htmlspecialchars(number_format($avgLee,2)); ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
        // Signature block: TVET Head (only for grading evaluations)
        $tvetHead = 'TVET HEAD';
        try {
            $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $nameExpr = '';
            if (in_array('first_name', $ucols) && in_array('last_name', $ucols)) {
                $nameExpr = "CONCAT(first_name,' ',last_name) as nm";
            } elseif (in_array('name', $ucols)) {
                $nameExpr = "name as nm";
            }
            if ($nameExpr !== '') {
                $st = $pdo->query("SELECT $nameExpr FROM users WHERE role='admin' AND (status IS NULL OR status='active') ORDER BY id LIMIT 1");
                $nm = trim((string)($st->fetchColumn() ?: ''));
                if ($nm !== '') $tvetHead = strtoupper($nm);
            }
        } catch (Exception $e) { /* ignore */ }
    ?>

    <div style="margin-top:40px; display:flex; justify-content:space-around; gap:40px;">
        <div style="text-align:center; min-width:240px;">
            <div style="border-top:1px solid #000; padding-top:6px; font-weight:700; letter-spacing:.02em;"><?php echo htmlspecialchars($tvetHead); ?></div>
            <div style="font-size:12px; color:#555;">TVET HEAD</div>
        </div>
    </div>

    <div class="no-print" style="margin-top:10px;">
        <button onclick="window.print()" style="padding:6px 12px; background:#9b25e7; color:#fff; border:none; border-radius:6px;">Print</button>
    </div>
</body>
</html>


