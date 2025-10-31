<?php
/**
 * Modern Manage Grades page for Instructors
 * - AJAX submission with JSON responses
 * - Accepts 0 as valid grade
 * - Inline validation and live totals
 */

require_once '../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$sectionId = intval(isset($_GET['section_id']) ? $_GET['section_id'] : 0);
$success = '';
$error = '';

// Verify instructor access
$stmt = $pdo->prepare(
    "SELECT s.*, c.id as course_id, c.course_code, c.course_name, c.year_level, c.semester, p.name as program_name, s.section_code
    FROM sections s
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    INNER JOIN instructor_sections ins ON s.id = ins.section_id
    WHERE s.id = ? AND ins.instructor_id = ? AND s.status = 'active'"
);
$stmt->execute([$sectionId, $instructorId]);
$section = $stmt->fetch();

if (!$section) {
    header('Location: dashboard.php');
    exit;
}

// Auto-provision assessment structure for missing periods so instructors don't have to re-create items
try {
    // Verify which periods already exist for this course
    $pstmt = $pdo->prepare("SELECT DISTINCT period FROM assessment_criteria WHERE course_id = ?");
    $pstmt->execute([$section['course_id']]);
    $existingPeriods = array_map('strtolower', array_column($pstmt->fetchAll(PDO::FETCH_ASSOC), 'period'));
    $allPeriods = ['prelim','midterm','finals'];
    $missing = array_values(array_diff($allPeriods, $existingPeriods));

    if (!empty($missing)) {
        // Choose a source period to clone from: prefer 'prelim', else any existing
        $sourcePeriod = in_array('prelim', $existingPeriods, true) ? 'prelim' : (count($existingPeriods) ? $existingPeriods[0] : null);
        if ($sourcePeriod !== null) {
            $pdo->beginTransaction();
            // Fetch criteria of source period
            $srcCritStmt = $pdo->prepare("SELECT id, name, percentage FROM assessment_criteria WHERE course_id = ? AND period = ? ORDER BY id");
            $srcCritStmt->execute([$section['course_id'], $sourcePeriod]);
            $sourceCriteria = $srcCritStmt->fetchAll(PDO::FETCH_ASSOC);
            // Prepare item fetch
            $srcItemStmt = $pdo->prepare("SELECT name, total_score FROM assessment_items WHERE criteria_id = ? ORDER BY id");

            foreach ($missing as $tgtPeriod) {
                // Skip if invalid target
                if (!in_array($tgtPeriod, $allPeriods, true)) continue;
                foreach ($sourceCriteria as $crit) {
                    // Insert cloned criteria for target period
                    $insCrit = $pdo->prepare("INSERT INTO assessment_criteria (course_id, name, period, percentage) VALUES (?, ?, ?, ?)");
                    $insCrit->execute([$section['course_id'], $crit['name'], $tgtPeriod, $crit['percentage']]);
                    $newCritId = (int)$pdo->lastInsertId();
                    // Clone items under this criteria
                    $srcItemStmt->execute([$crit['id']]);
                    foreach ($srcItemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                        $insItem = $pdo->prepare("INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?, ?, ?)");
                        $insItem->execute([$newCritId, $item['name'], $item['total_score']]);
                    }
                }
            }
            $pdo->commit();
            // Refresh page to reflect newly provisioned periods
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
} catch (Exception $e) {
    // best-effort; do not block page if provisioning fails
}

// Helper to process grades and return array with success/error
function processGrades($pdo, $gradesPayload)
{
    try {
        $pdo->beginTransaction();
        // Validate assessment IDs and enrollment IDs belong to this course before inserting
        // Fetch valid assessment ids for the course
        $validAssessmentStmt = $pdo->prepare("SELECT id FROM assessment_items WHERE criteria_id IN (SELECT id FROM assessment_criteria WHERE course_id = ?)");
        // Use global $section to get course_id
        global $section;
        $validAssessmentStmt->execute([$section['course_id']]);
        $validAssessments = array_column($validAssessmentStmt->fetchAll(), 'id');
        $validAssessmentsMap = array_flip($validAssessments);

        // Fetch valid enrollment ids for this course
        $validEnrollStmt = $pdo->prepare("SELECT id FROM enrollments WHERE course_id = ? AND status = 'enrolled'");
        $validEnrollStmt->execute([$section['course_id']]);
        $validEnrolls = array_column($validEnrollStmt->fetchAll(), 'id');
        $validEnrollsMap = array_flip($validEnrolls);

        if (!empty($gradesPayload) && is_array($gradesPayload)) {
            $insertStmt = $pdo->prepare(
                "INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    grade = VALUES(grade),
                    status = VALUES(status),
                    submitted_at = NOW()"
            );

            $invalid = [];
            foreach ($gradesPayload as $enrollmentId => $assessments) {
                // ensure enrollment belongs to course
                if (!isset($validEnrollsMap[$enrollmentId])) {
                    $invalid[] = "enrollment:{$enrollmentId}";
                    continue;
                }

                foreach ($assessments as $assessmentId => $gradeData) {
                    if (!isset($validAssessmentsMap[$assessmentId])) {
                        $invalid[] = "assessment:{$assessmentId}";
                        continue;
                    }

                    $gradeRaw = isset($gradeData['grade']) ? trim((string)$gradeData['grade']) : '';
                    if ($gradeRaw !== '') {
                        if (!is_numeric($gradeRaw)) {
                            throw new Exception("Invalid grade for enrollment {$enrollmentId}, assessment {$assessmentId}");
                        }
                        $grade = floatval($gradeRaw);
                        $status = 'complete';
                    } else {
                        $grade = null;
                        $status = 'incomplete';
                    }

                    $insertStmt->execute([$enrollmentId, $assessmentId, $grade, $status]);
                }
            }

            if (!empty($invalid)) {
                throw new Exception('Invalid IDs in payload: ' . implode(', ', array_unique($invalid)));
            }
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'Grades saved.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        // Log the failure for debugging
        $logMsg = date('c') . " | Failed to process grades: " . $e->getMessage() . "\nPayload: " . json_encode($gradesPayload) . "\n";
        @file_put_contents(__DIR__ . '/../logs/grades_errors.log', $logMsg, FILE_APPEND);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Detect AJAX (fetch) requests
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_grades'])) {
    // Accept both classic form (grades[]) and AJAX (grades_json) payloads
    $payload = [];
    if ($isAjax && !empty($_POST['grades_json'])) {
        $decoded = json_decode($_POST['grades_json'], true);
        if (is_array($decoded)) { $payload = $decoded; }
    } elseif (isset($_POST['grades']) && is_array($_POST['grades'])) {
        $payload = $_POST['grades'];
    }
    $result = processGrades($pdo, $payload);
    if ($isAjax) {
        // attach assessment averages to AJAX response for client refresh
        // compute updated averages
        $avgStmt = $pdo->prepare("SELECT assessment_id, AVG(grade) as avg_grade FROM grades WHERE assessment_id IN (SELECT id FROM assessment_items WHERE criteria_id IN (SELECT id FROM assessment_criteria WHERE course_id = ?)) GROUP BY assessment_id");
        $avgStmt->execute([$section['course_id']]);
        $avgs = [];
        foreach ($avgStmt->fetchAll() as $r) { $avgs[$r['assessment_id']] = $r['avg_grade'] !== null ? floatval($r['avg_grade']) : null; }
        $result['averages'] = $avgs;
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    } else {
        if ($result['success']) {
            $success = 'Grades submitted successfully!';
        } else {
            $error = 'Failed to submit grades: ' . $result['message'];
        }
    }
}

// Send grades summary to admin via email
// moved send_to_admin to later after data fetches

// Fetch assessments for this course
$stmt = $pdo->prepare(
    "SELECT ai.id, ai.name, ai.total_score, ac.id as criteria_id, ac.name as criteria_name, ac.period, ac.percentage
    FROM assessment_items ai
    INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
    WHERE ac.course_id = ?
    ORDER BY ac.period, ai.name"
);
$stmt->execute([$section['course_id']]);
$assessments = $stmt->fetchAll();

// Fetch enrolled students
$stmt = $pdo->prepare(
    "SELECT e.id as enrollment_id, u.id as student_id, u.student_id as student_number, u.first_name, u.last_name, u.email
    FROM enrollments e
    INNER JOIN users u ON e.student_id = u.id
    WHERE e.course_id = ? AND e.status = 'enrolled'
    ORDER BY u.last_name, u.first_name"
);
$stmt->execute([$section['course_id']]);
$students = $stmt->fetchAll();

// Fetch existing grades
$grades = [];
if (count($students) > 0) {
    $enrollmentIds = array_column($students, 'enrollment_id');
    $placeholders = str_repeat('?,', count($enrollmentIds) - 1) . '?';

    $stmt = $pdo->prepare("SELECT enrollment_id, assessment_id, grade FROM grades WHERE enrollment_id IN ($placeholders)");
    $stmt->execute($enrollmentIds);

    foreach ($stmt->fetchAll() as $row) {
        $grades[$row['enrollment_id']][$row['assessment_id']] = $row;
    }
}

// Compute per-assessment averages for summary (average of non-null grades)
$assessmentAverages = [];
if (!empty($grades)) {
    $counts = [];
    foreach ($grades as $enrollId => $alist) {
        foreach ($alist as $aid => $g) {
            if (!isset($assessmentAverages[$aid])) { $assessmentAverages[$aid] = 0.0; $counts[$aid] = 0; }
            if ($g['grade'] !== null) { $assessmentAverages[$aid] += floatval($g['grade']); $counts[$aid]++; }
        }
    }
    foreach ($assessmentAverages as $aid => $sum) {
        $assessmentAverages[$aid] = $counts[$aid] > 0 ? ($sum / $counts[$aid]) : null;
    }
}

// Prepare JS-friendly structures
$assessmentMeta = [];
$totalPossible = 0.0;
// Group assessments by period -> criteria -> assessments for header rendering
$grouped = []; // ['Prelim'=>['criteria'=>['Exam'=>['assessments'=>[...] , 'percentage'=>x]], 'period_percentage'=>sum]]
$totalPossible = 0.0;
foreach ($assessments as $a) {
    $period = $a['period'] ?: 'Unspecified';
    $criteriaId = isset($a['criteria_id']) ? (int)$a['criteria_id'] : 0;
    $criteriaName = $a['criteria_name'] ?: 'General';
    $assess = [
        'id' => (int)$a['id'],
        'name' => $a['name'],
        'max' => floatval($a['total_score'])
    ];

    if (!isset($grouped[$period])) {
        $grouped[$period] = ['criteria' => [], 'period_percentage' => 0.0, 'possible' => 0.0];
    }

    // set criteria bucket keyed by unique criteria id to avoid merging criteria with same name
    if (!isset($grouped[$period]['criteria'][$criteriaId])) {
        $grouped[$period]['criteria'][$criteriaId] = [
            'name' => $criteriaName,
            'assessments' => [],
            'percentage' => floatval($a['percentage']),
            'possible' => 0.0
        ];
        // accumulate period percentage once per distinct criteria
        $grouped[$period]['period_percentage'] += floatval($a['percentage']);
    }

    $grouped[$period]['criteria'][$criteriaId]['assessments'][] = $assess;
    $grouped[$period]['criteria'][$criteriaId]['possible'] += $assess['max'];
    $grouped[$period]['possible'] += $assess['max'];
    $totalPossible += $assess['max'];
}

// Flatten assessments in display order (periods -> criteria -> assessments)
$displayAssessments = [];
foreach ($grouped as $periodName => $periodData) {
    foreach ($periodData['criteria'] as $criteriaId => $cdata) {
        foreach ($cdata['assessments'] as $ass) {
            $displayAssessments[] = array_merge($ass, [
                'period' => $periodName,
                'criteria' => $cdata['name'],
                'criteria_id' => (int)$criteriaId,
                'criteria_percentage' => $cdata['percentage']
            ]);
        }
    }
}

// JS-friendly metadata list
$assessmentMeta = [];
$periodMeta = []; // periodName => ['percentage' => x, 'possible' => y]
// Ensure all three periods exist even if no assessments yet
foreach (['prelim','midterm','finals'] as $pRequired) {
    if (!isset($grouped[$pRequired])) {
        $grouped[$pRequired] = ['criteria' => [], 'period_percentage' => 0.0, 'possible' => 0.0];
    }
}
foreach ($grouped as $pname => $pdata) {
    $periodMeta[$pname] = ['percentage' => $pdata['period_percentage'], 'possible' => $pdata['possible']];
}
foreach ($displayAssessments as $a) {
    $assessmentMeta[] = [
        'id' => (int)$a['id'],
        'name' => $a['name'],
        'max' => $a['max'],
        'period' => $a['period'],
        'criteria' => $a['criteria'],
        'criteria_id' => isset($a['criteria_id']) ? (int)$a['criteria_id'] : null,
        'criteria_percentage' => $a['criteria_percentage']
    ];
}

// Debug: log assessment availability for this section/course
try {
    $dbg = date('c') . " | [manage-grades] section_id=" . ($section['id'] ?? 'n/a') . ", course_id=" . ($section['course_id'] ?? 'n/a') . ", assessments=" . count($assessments) . ", displayAssessments=" . count($displayAssessments) . "\n";
    @file_put_contents(__DIR__ . '/../logs/grades_errors.log', $dbg, FILE_APPEND);
} catch (Exception $e) { /* ignore */ }

$hasAssessments = count($displayAssessments) > 0;

// Send grades summary to admin via email (now placed after data fetches)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_to_admin'])) {
    require_once __DIR__ . '/../include/email-functions.php';
    $sumHtml = '<h3>Grades Summary — ' . htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']) . ' (Section ' . htmlspecialchars($section['section_code']) . ')</h3>';
    foreach (['prelim','midterm','finals'] as $per) {
        $sumHtml .= '<h4 style="margin:12px 0 6px;">' . strtoupper($per) . '</h4>';
        $sumHtml .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">';
        $sumHtml .= '<tr><th align="left">Student</th><th align="left">Percent</th></tr>';
        foreach ($students as $st) {
            $possible = isset($periodMeta[$per]['possible']) ? floatval($periodMeta[$per]['possible']) : 0.0;
            $total = 0.0;
            if ($possible > 0) {
                foreach ($assessments as $aRow) {
                    if ($aRow['period'] !== $per) continue;
                    $aid = (int)$aRow['id'];
                    if (isset($grades[$st['enrollment_id']][$aid]) && $grades[$st['enrollment_id']][$aid]['grade'] !== null) {
                        $total += floatval($grades[$st['enrollment_id']][$aid]['grade']);
                    }
                }
            }
            $pctVal = ($possible > 0) ? min(99, (($total / $possible) * 100.0)) : null;
            $pct = ($pctVal === null) ? '' : number_format($pctVal, 2) . '%';
            $sumHtml .= '<tr><td>' . htmlspecialchars($st['last_name'] . ', ' . $st['first_name']) . '</td><td>' . $pct . '</td></tr>';
        }
        $sumHtml .= '</table>';
    }
    // Build CSV (Final Result layout)
    $csv = "Student,Prelim,Midterm,Finals,Tentative,Grade,Remarks\r\n";
    foreach ($students as $student) {
        $enroll = $student['enrollment_id'];
        // re-use final section logic by computing per-period
        $calc = function($per) use ($periodMeta, $assessments, $grades, $enroll){
            $possible = isset($periodMeta[$per]['possible']) ? floatval($periodMeta[$per]['possible']) : 0.0;
            if ($possible <= 0) return null;
            $total = 0.0; $hasAll=true; $seen=0.0;
            foreach ($assessments as $ar) {
                if ($ar['period'] !== $per) continue;
                $aid=(int)$ar['id'];
                if (isset($grades[$enroll][$aid]) && $grades[$enroll][$aid]['grade'] !== null) { $total += floatval($grades[$enroll][$aid]['grade']); $seen += floatval($ar['total_score']); } else { $hasAll=false; }
            }
            if (!$hasAll || $seen < $possible) return null;
            return min(99, ($total / $possible) * 100.0);
        };
        $p=$calc('prelim'); $m=$calc('midterm'); $f=$calc('finals');
        $wPre=30; $wMid=30; $wFin=40; $den=$wPre+$wMid+$wFin;
        $haveAny = ($p!==null)||($m!==null)||($f!==null);
        $tent = $haveAny ? min(99, ((($p??0)*$wPre + ($m??0)*$wMid + ($f??0)*$wFin)/$den)) : null;
        $leeFinal = $tent===null ? null : lee_from_percent($tent);
        $hasBlank = ($p===null)||($m===null)||($f===null);
        $remarks = 'Incomplete';
        if ($tent !== null) { $remarks = ($tent >= 75) ? 'Passed' : ($hasBlank ? 'Incomplete' : 'Failed'); }
        $csv .= '"' . str_replace('"','""',$student['last_name'] . ', ' . $student['first_name']) . '",' .
                ($p===null?'':number_format($p,2)) . ',' .
                ($m===null?'':number_format($m,2)) . ',' .
                ($f===null?'':number_format($f,2)) . ',' .
                ($tent===null?'':number_format($tent,2)) . ',' .
                ($leeFinal===null?'':number_format($leeFinal,2)) . ',' .
                $remarks . "\r\n";
    }

    // Send email with CSV attachment
    $ok = sendEmailWithAttachment('ascbtvet@gmail.com', 'Admin', 'Section Grades — ' . ($section['course_code'] ?? ''), $sumHtml, 'grades.csv', $csv, 'text/csv');
    $success = $ok ? 'Grades sent to admin.' : '';
    if (!$ok) { $error = 'Failed to send grades to admin.'; }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Grades — <?php echo htmlspecialchars($section['course_name']); ?> (<?php echo htmlspecialchars($section['section_code']); ?>)</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/manage-grades.css">
</head>
<body>
    <div class="page">
        <header class="page-header">
            <div>
                <h1>Manage Grades</h1>
                <div class="meta"><?php echo htmlspecialchars($section['course_code'] . ' — ' . $section['course_name']); ?> &middot; Section <?php echo htmlspecialchars($section['section_code']); ?></div>
            </div>
            <div class="meta small" style="display:flex; gap:8px; align-items:center;">
                <a href="dashboard.php" class="btn btn-primary" title="Go to Dashboard">🏠 Manage Grades</a>
                <a href="assessments.php?course_id=<?php echo (int)$section['course_id']; ?>" class="btn btn-secondary" title="Manage Assessment">🧮 Assessments</a>
            </div>
        </header>

        <?php if ($success): ?><div class="notice success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="grades-grid">
            <div class="left">
                <form id="gradesForm" method="POST">
                    <input type="hidden" name="submit_grades" value="1">
                    <div>
                        <?php // Period tabs
                        $periodKeys = array_keys($grouped);
                        ?>
                        <div id="periodTabs" style="display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap;">
                            <?php foreach ($periodKeys as $i => $pkey): ?>
                                <button type="button" class="btn period-tab <?php echo $i===0 ? 'primary' : 'ghost'; ?>" data-show-period="<?php echo htmlspecialchars($pkey); ?>"><?php echo htmlspecialchars(ucfirst($pkey)); ?></button>
                            <?php endforeach; ?>
                            <button type="button" class="btn period-tab ghost" data-show-period="final-result">Final Result</button>
                        </div>

                        <?php // Render one table per period ?>
                        <?php foreach ($grouped as $pname => $pdata): ?>
                            <?php
                                // flatten assessments for this period only
                                $periodAssessments = [];
                                foreach ($pdata['criteria'] as $cid => $cdata) {
                                    foreach ($cdata['assessments'] as $ass) {
                                        $periodAssessments[] = array_merge($ass, ['period' => $pname]);
                                    }
                                }
                            ?>
                            <div class="period-table-wrap" data-period="<?php echo htmlspecialchars($pname); ?>" style="<?php echo ($pname === $periodKeys[0] ? '' : 'display:none;'); ?> overflow:auto;">
                                <table class="grades grades-period-table" id="gradesTable-<?php echo htmlspecialchars($pname); ?>">
                                    <thead>
                                        <tr>
                                            <th rowspan="3">Student</th>
                                            <th colspan="<?php echo count($periodAssessments) + 1; ?>"><?php echo htmlspecialchars(ucfirst($pname)); ?> <br><small class="small"><?php echo number_format($pdata['period_percentage'],2); ?>%</small></th>
                                            <th rowspan="3">Grade</th>
                                            <th rowspan="3">Remarks</th>
                                        </tr>
                                        <tr>
                                            <?php foreach ($pdata['criteria'] as $cid => $cdata): $cspan = count($cdata['assessments']); ?>
                                                <th colspan="<?php echo intval($cspan); ?>"><?php echo htmlspecialchars($cdata['name']); ?><br><small class="small"><?php echo number_format($cdata['percentage'],2); ?>%</small></th>
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
                                        <?php if (!$hasAssessments): ?>
                                            <tr>
                                                <td colspan="3" style="color:#666;">No assessments found for this course.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($students as $student): $enroll = $student['enrollment_id']; ?>
                                            <tr data-enrollment-id="<?php echo $enroll; ?>">
                                                <td style="min-width:220px;"><strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong><br><small class="small"><?php echo htmlspecialchars($student['student_number']); ?></small></td>
                                                <?php foreach ($periodAssessments as $a): $aid = $a['id']; $existing = isset($grades[$enroll][$aid]) ? $grades[$enroll][$aid] : null; ?>
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

                        <?php // Final Result summary table ?>
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
                                    <?php foreach ($students as $student): $enroll=$student['enrollment_id']; ?>
                                        <?php
                                            $calc = function($per) use ($periodMeta, $assessments, $grades, $enroll){
                                                $possible = isset($periodMeta[$per]['possible']) ? floatval($periodMeta[$per]['possible']) : 0.0;
                                                if ($possible <= 0) return null;
                                                $total = 0.0; $hasAll=true; $seen=0.0;
                                                foreach ($assessments as $ar) {
                                                    if ($ar['period'] !== $per) continue;
                                                    $aid=(int)$ar['id'];
                                                    if (isset($grades[$enroll][$aid]) && $grades[$enroll][$aid]['grade'] !== null) { $total += floatval($grades[$enroll][$aid]['grade']); $seen += floatval($ar['total_score']); } else { $hasAll=false; }
                                                }
                                                if (!$hasAll || $seen < $possible) return null; // incomplete
                                                $val = ($total / $possible) * 100.0;
                                                return min(99, $val);
                                            };
                                            $p = $calc('prelim');
                                            $m = $calc('midterm');
                                            $f = $calc('finals');
                                            // Compute Tentative as weighted across all periods (Prelim 30, Midterm 30, Finals 40)
                                            $wPre = 30.0; $wMid = 30.0; $wFin = 40.0; $den = ($wPre+$wMid+$wFin);
                                            $haveAny = ($p !== null) || ($m !== null) || ($f !== null);
                                            $cum = $haveAny ? min(99, ((($p ?? 0.0)*$wPre + ($m ?? 0.0)*$wMid + ($f ?? 0.0)*$wFin) / $den)) : null;
                                            $remark = 'Incomplete'; $cls='text-secondary';
                                            $hasBlank = ($p === null) || ($m === null) || ($f === null);
                                            if ($cum !== null) {
                                                if ($cum >= 75.0) { $remark='Passed'; $cls='text-success'; }
                                                else { $remark = $hasBlank ? 'Incomplete' : 'Failed'; $cls = $hasBlank ? 'text-secondary' : 'text-danger'; }
                                            }
                                            $leeFinal = $cum === null ? null : lee_from_percent($cum);
                                        ?>
                                        <tr>
                                            <td style="min-width:220px;"><strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong><br><small class="small"><?php echo htmlspecialchars($student['student_number']); ?></small></td>
                                            <td><?php echo $p===null ? '' : number_format($p,2) ; ?></td>
                                            <td><?php echo $m===null ? '' : number_format($m,2) ; ?></td>
                                            <td><?php echo $f===null ? '' : number_format($f,2) ; ?></td>
                                            <td><?php echo $cum===null ? '' : number_format($cum,2) ; ?></td>
                                            <td><?php echo $leeFinal===null ? '—' : number_format($leeFinal,2); ?></td>
                                            <td class="<?php echo $cls; ?>"><?php echo $remark; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="controls">
                        <button type="button" id="saveBtn" class="btn primary">Save All Grades</button>
                        <button type="button" id="resetBtn" class="btn ghost">Reset</button>
                        <button type="button" id="printBtn" class="btn ghost" onclick="window.print()">Print</button>
                        <button type="submit" name="send_to_admin" value="1" class="btn btn-secondary">Send to Admin</button>
                    </div>
                    <!-- Moved ajaxMessage here after removing the summary sidebar -->
                    <div id="ajaxMessage" style="margin-top:12px; display:none;"></div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Page data from server
    const assessments = <?php echo json_encode($assessmentMeta); ?>;
    const periodMeta = <?php echo json_encode($periodMeta); ?>;

        // Helpers
        function qs(sel, ctx=document) { return ctx.querySelector(sel); }
        function qsa(sel, ctx=document) { return Array.from(ctx.querySelectorAll(sel)); }

        // Period tab toggle
        function showPeriod(p){
            // Show the requested period and hide the others
            qsa('.period-table-wrap').forEach(div => {
                div.style.display = (div.getAttribute('data-period') === p) ? '' : 'none';
            });
            qsa('.period-tab').forEach(btn=>{
                if(btn.getAttribute('data-show-period')===p){ btn.classList.remove('ghost'); btn.classList.add('primary'); }
                else { btn.classList.add('ghost'); btn.classList.remove('primary'); }
            });
        }

        // Delegate clicks on tabs
        document.addEventListener('DOMContentLoaded', function(){
            const tabs = document.getElementById('periodTabs');
            if (!tabs) return;
            tabs.addEventListener('click', function(e){
                const btn = e.target.closest('.period-tab');
                if (!btn) return;
                const p = (btn.getAttribute('data-show-period') || '').toLowerCase();
                if (p) showPeriod(p);
            });
        });

        // Live summary recalculation
        function recalcSummary() {
            const rows = qsa('table.grades-period-table tbody tr');
            rows.forEach(row => {
                const enroll = row.getAttribute('data-enrollment-id');
                const inputs = qsa('input.grade-input', row);
                if (!enroll || inputs.length === 0) { return; } // skip non-student rows (e.g., final result table)
                // compute per-period totals and track submitted possible per period
                const perPeriodTotals = {};
                const perPeriodSubmittedPossible = {};
                inputs.forEach(inp => {
                    const v = inp.value.trim();
                    const period = inp.getAttribute('data-period') || 'Unspecified';
                    const max = parseFloat(inp.getAttribute('data-max')) || 0.0;
                    if (!perPeriodTotals[period]) perPeriodTotals[period] = 0.0;
                    if (!perPeriodSubmittedPossible[period]) perPeriodSubmittedPossible[period] = 0.0;
                    if (v !== '' && !isNaN(v)) {
                        perPeriodTotals[period] += parseFloat(v);
                        perPeriodSubmittedPossible[period] += max;
                    }
                });

                // Tentative: treat blanks as N/A, compute weighted percent only across periods where the student has submitted something
                let tentativeNumerator = 0.0;
                let tentativeWeightSum = 0.0;
                for (const pName in periodMeta) {
                    const meta = periodMeta[pName];
                    const submittedPossible = perPeriodSubmittedPossible[pName] || 0.0;
                    const studentTotal = perPeriodTotals[pName] || 0.0;
                    const periodWeight = parseFloat(meta.percentage) || 0.0;
                    if (submittedPossible > 0) {
                        const periodPercent = (studentTotal / submittedPossible) * 100.0; // percent for that period based on submitted items
                        tentativeNumerator += (periodPercent * periodWeight);
                        tentativeWeightSum += periodWeight;
                    }
                }
                let tentative = null;
                if (tentativeWeightSum > 0) {
                    tentative = (tentativeNumerator / tentativeWeightSum);
                }

                // Final: treat blanks as zero (conservative). Use full period possible from periodMeta
                let finalPercent = 0.0;
                for (const pName in periodMeta) {
                    const meta = periodMeta[pName];
                    const periodPossible = parseFloat(meta.possible) || 0.0;
                    const studentTotal = perPeriodTotals[pName] || 0.0; // blanks counted as 0
                    const periodWeight = parseFloat(meta.percentage) || 0.0;
                    if (periodPossible > 0) {
                        const portion = (studentTotal / periodPossible) * periodWeight;
                        finalPercent += portion;
                    }
                }

                // Per-period percent totals (0-100) using full possible per period
                const perPeriodPercents = {};
                const perPeriodCompleteness = {};
                ['prelim','midterm','finals'].forEach(p => {
                    const meta = periodMeta[p] || { possible: 0 };
                    const possible = parseFloat(meta.possible) || 0.0;
                    const total = perPeriodTotals[p] || 0.0;
                    const submitted = perPeriodSubmittedPossible[p] || 0.0;
                    const pct = possible > 0 ? Math.min(99, (total / possible) * 100.0) : null;
                    perPeriodPercents[p] = pct;
                    perPeriodCompleteness[p] = possible > 0 ? (submitted >= possible) : false;
                });

                // LEE mapping and remarks
                function leeFromPercent(p) {
                    if (p === null || isNaN(p)) return null;
                    const x = Math.round(p);
                    if (x >= 95) return 1.00;
                    if (x === 94) return 1.10;
                    if (x === 93) return 1.20;
                    if (x === 92) return 1.30;
                    if (x === 91) return 1.40;
                    if (x === 90) return 1.50;
                    if (x === 89) return 1.60;
                    if (x === 88) return 1.70;
                    if (x === 87) return 1.80;
                    if (x === 86) return 1.90;
                    if (x === 85) return 2.00;
                    if (x === 84) return 2.10;
                    if (x === 83) return 2.20;
                    if (x === 82) return 2.30;
                    if (x === 81) return 2.40;
                    if (x === 80) return 2.50;
                    if (x === 79) return 2.60;
                    if (x === 78) return 2.70;
                    if (x === 77) return 2.80;
                    if (x === 76) return 2.90;
                    if (x === 75) return 3.00;
                    if (x < 75) return 5.00;
                    return 1.00;
                }
                function leeRemarks(lee) {
                    if (lee === null) return { text: 'Incomplete', cls: 'text-secondary' };
                    if (lee <= 3.0) return { text: 'Passed', cls: 'text-success' };
                    if (lee > 3.0 && lee < 5.0) return { text: 'Conditional', cls: 'text-warning' };
                    if (lee >= 5.0) return { text: 'Failed', cls: 'text-danger' };
                    return { text: 'Incomplete', cls: 'text-secondary' };
                }
                // Determine current table's period and compute LEE/remarks for that period only
                let currentPeriod = null;
                const wrap = row.closest('.period-table-wrap');
                if (wrap) currentPeriod = (wrap.getAttribute('data-period') || '').toLowerCase();
                const periodPct = currentPeriod ? perPeriodPercents[currentPeriod] : null;
                const isComplete = currentPeriod ? perPeriodCompleteness[currentPeriod] : false;
                let lee = null; let rem = { text: 'Incomplete', cls: 'text-secondary' };
                if (periodPct === null || !isComplete) {
                    rem = { text: 'Incomplete', cls: 'text-secondary' };
                } else {
                    lee = leeFromPercent(periodPct);
                    rem = leeRemarks(lee);
                }

                // Update table cells
                const tentCell = qs('td.tentative-cell', row);
                const finalCell = qs('td.final-cell', row);
                if (tentCell) tentCell.textContent = tentative === null ? 'N/A' : tentative.toFixed(2) + '%';
                if (finalCell) finalCell.textContent = finalPercent.toFixed(2) + '%';

                // Update per-period inline total cells
                const prelimCell = qs('td.period-total-cell[data-period="prelim"]', row);
                const midtermCell = qs('td.period-total-cell[data-period="midterm"]', row);
                const finalsCell = qs('td.period-total-cell[data-period="finals"]', row);
                const avgCell = qs('td.avg-total-cell', row);
                const leeCell = qs('td.lee-cell', row);
                const remarksCell = qs('td.remarks-cell', row);
                if (prelimCell) prelimCell.textContent = perPeriodPercents['prelim'] == null ? '' : perPeriodPercents['prelim'].toFixed(2) + '%';
                if (midtermCell) midtermCell.textContent = perPeriodPercents['midterm'] == null ? '' : perPeriodPercents['midterm'].toFixed(2) + '%';
                if (finalsCell) finalsCell.textContent = perPeriodPercents['finals'] == null ? '' : perPeriodPercents['finals'].toFixed(2) + '%';
                if (leeCell) leeCell.textContent = lee == null ? '—' : lee.toFixed(2);
                if (remarksCell) { remarksCell.textContent = rem.text; remarksCell.className = 'remarks-cell ' + rem.cls; }

                // No summary sidebar — totals are only shown in the tentative/final columns
            });
        }

        // Validate inputs before sending
        function validateAll() {
            let ok = true;
            qsa('input.grade-input').forEach(inp => {
                inp.style.outline = 'none';
                const v = inp.value.trim();
                if (v === '') return; // allow blank -> incomplete
                if (isNaN(v)) {
                    ok = false; inp.style.outline = '2px solid #ffb4b4';
                } else {
                    const max = parseFloat(inp.getAttribute('data-max'));
                    if (!isNaN(max) && parseFloat(v) > max) { ok = false; inp.style.outline = '2px solid #ffb4b4'; }
                }
            });
            return ok;
        }

        // Gather form data into a JS object matching the server shape
        function gatherGrades() {
            const data = {};
            qsa('table.grades-period-table tbody tr').forEach(row => {
                const enroll = row.getAttribute('data-enrollment-id');
                const inputs = qsa('input.grade-input', row);
                if (!enroll || inputs.length === 0) { return; }
                data[enroll] = {};
                inputs.forEach(inp => {
                    const aid = inp.getAttribute('data-assessment-id');
                    data[enroll][aid] = { grade: inp.value.trim() };
                });
            });
            return data;
        }

        // Show AJAX messages
        function showMessage(msg, ok=true) {
            const el = qs('#ajaxMessage');
            el.style.display = 'block';
            el.textContent = msg;
            el.className = ok ? 'notice success' : 'notice error';
            setTimeout(()=> el.style.display = 'none', 4000);
        }

        // Events
        document.addEventListener('input', (e) => { if (e.target.matches('input.grade-input')) recalcSummary(); });

        qs('#resetBtn').addEventListener('click', () => { document.getElementById('gradesForm').reset(); recalcSummary(); });

        qs('#saveBtn').addEventListener('click', async () => {
            if (!validateAll()) { showMessage('Please fix invalid grade entries (red).', false); return; }

            const grades = gatherGrades();
            const form = new FormData();
            form.append('submit_grades', '1');
            form.append('ajax', '1');
            form.append('grades_json', JSON.stringify(grades));

            try {
                const resp = await fetch(location.href, { method:'POST', headers:{ 'X-Requested-With':'XMLHttpRequest' }, body: form });
                const data = await resp.json();
                if (data.success) {
                    showMessage(data.message || 'Saved', true);
                } else {
                    showMessage(data.message || 'Save failed', false);
                }
            } catch (err) {
                showMessage('Network or server error when saving.', false);
                console.error(err);
            }
        });

        // On page load, recalc summary
        recalcSummary();
    </script>

</body>
</html>

<?php
// Server-side: If AJAX request and grades_json is present, decode and process
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['grades_json'])) {
        $payload = json_decode($_POST['grades_json'], true);
        $result = processGrades($pdo, $payload);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

?>