<?php
/**
 * Modern Manage Grades page for Instructors
 * - AJAX submission with JSON responses
 * - Accepts 0 as valid grade
 * - Inline validation and live totals
 */

require_once '../config.php';
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
    $result = processGrades($pdo, isset($_POST['grades']) ? $_POST['grades'] : []);
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

// Fetch assessments for this course
$stmt = $pdo->prepare(
    "SELECT ai.id, ai.name, ai.total_score, ac.name as criteria_name, ac.period, ac.percentage
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
    $criteria = $a['criteria_name'] ?: 'General';
    $assess = [
        'id' => (int)$a['id'],
        'name' => $a['name'],
        'max' => floatval($a['total_score'])
    ];

    if (!isset($grouped[$period])) {
        $grouped[$period] = ['criteria' => [], 'period_percentage' => 0.0, 'possible' => 0.0];
    }

    // set criteria percentage (only set once if present)
    if (!isset($grouped[$period]['criteria'][$criteria])) {
        $grouped[$period]['criteria'][$criteria] = ['assessments' => [], 'percentage' => floatval($a['percentage'])];
        // accumulate period percentage
        $grouped[$period]['period_percentage'] += floatval($a['percentage']);
    }

    $grouped[$period]['criteria'][$criteria]['assessments'][] = $assess;
    $grouped[$period]['possible'] += $assess['max'];
    $totalPossible += $assess['max'];
}

// Flatten assessments in display order (periods -> criteria -> assessments)
$displayAssessments = [];
foreach ($grouped as $periodName => $periodData) {
    foreach ($periodData['criteria'] as $criteriaName => $cdata) {
        foreach ($cdata['assessments'] as $ass) {
            $displayAssessments[] = array_merge($ass, ['period' => $periodName, 'criteria' => $criteriaName, 'criteria_percentage' => $cdata['percentage']]);
        }
    }
}

// JS-friendly metadata list
$assessmentMeta = [];
$periodMeta = []; // periodName => ['percentage' => x, 'possible' => y]
foreach ($grouped as $pname => $pdata) {
    $periodMeta[$pname] = ['percentage' => $pdata['period_percentage'], 'possible' => $pdata['possible']];
}
foreach ($displayAssessments as $a) {
    $assessmentMeta[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'max' => $a['max'], 'period' => $a['period'], 'criteria' => $a['criteria'], 'criteria_percentage' => $a['criteria_percentage']];
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
            <div class="meta small">Instructor dashboard</div>
        </header>

        <?php if ($success): ?><div class="notice success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="grades-grid">
            <div class="left">
                <form id="gradesForm" method="POST">
                    <input type="hidden" name="submit_grades" value="1">
                    <div style="overflow:auto;">
                        <table class="grades" id="gradesTable">
                            <thead>
                                <?php // Top header: periods with total percentage (colspan = number of assessments under period)
                                $periodColCounts = [];
                                foreach ($grouped as $pname => $pdata) {
                                    $count = 0;
                                    foreach ($pdata['criteria'] as $c) { $count += count($c['assessments']); }
                                    $periodColCounts[$pname] = $count;
                                }
                                ?>
                                <tr>
                                    <th rowspan="3">Student</th>
                                    <?php foreach ($grouped as $pname => $pdata): ?>
                                        <th colspan="<?php echo intval($periodColCounts[$pname]); ?>"><?php echo htmlspecialchars($pname); ?> <br><small class="small"><?php echo number_format($pdata['period_percentage'],2); ?>%</small></th>
                                    <?php endforeach; ?>
                                    <th rowspan="3">Tentative (%)</th>
                                    <th rowspan="3">Final (%)</th>
                                </tr>
                                <?php // Second header: criteria rows
                                ?>
                                <tr>
                                    <?php foreach ($grouped as $pname => $pdata):
                                        foreach ($pdata['criteria'] as $cname => $cdata):
                                            $cspan = count($cdata['assessments']); ?>
                                            <th colspan="<?php echo intval($cspan); ?>"><?php echo htmlspecialchars($cname); ?><br><small class="small"><?php echo number_format($cdata['percentage'],2); ?>%</small></th>
                                    <?php endforeach; endforeach; ?>
                                </tr>
                                <?php // Third header: individual assessments
                                ?>
                                <tr>
                                    <?php foreach ($displayAssessments as $a): ?>
                                        <th data-assessment-id="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?><br><small class="small">Max: <?php echo htmlspecialchars(number_format($a['max'],2)); ?></small></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): $enroll = $student['enrollment_id']; ?>
                                    <tr data-enrollment-id="<?php echo $enroll; ?>">
                                        <td style="min-width:220px;"><strong><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?></strong><br><small class="small"><?php echo htmlspecialchars($student['student_number']); ?></small></td>
                                        <?php foreach ($displayAssessments as $a): $aid = $a['id']; $existing = isset($grades[$enroll][$aid]) ? $grades[$enroll][$aid] : null; ?>
                                            <td>
                                                <input type="number" class="grade-input" step="0.01" min="0" max="<?php echo htmlspecialchars($a['max']); ?>"
                                                       name="grades[<?php echo $enroll; ?>][<?php echo $aid; ?>][grade]"
                                                       data-assessment-id="<?php echo (int)$aid; ?>"
                                                       data-max="<?php echo htmlspecialchars($a['max']); ?>"
                                                       data-period="<?php echo htmlspecialchars($a['period']); ?>"
                                                       value="<?php echo $existing ? htmlspecialchars($existing['grade']) : ''; ?>">
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="tentative-cell" style="text-align:right; font-weight:600;">0.00%</td>
                                        <td class="final-cell" style="text-align:right; font-weight:600;">0.00%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="controls">
                        <button type="button" id="saveBtn" class="btn primary">Save All Grades</button>
                        <button type="button" id="resetBtn" class="btn ghost">Reset</button>
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

        // Live summary recalculation
        function recalcSummary() {
            const rows = qsa('#gradesTable tbody tr');
            rows.forEach(row => {
                const enroll = row.getAttribute('data-enrollment-id');
                // compute per-period totals and track submitted possible per period
                const perPeriodTotals = {};
                const perPeriodSubmittedPossible = {};
                qsa('input.grade-input', row).forEach(inp => {
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

                // Update table cells
                const tentCell = qs('td.tentative-cell', row);
                const finalCell = qs('td.final-cell', row);
                if (tentCell) tentCell.textContent = tentative === null ? 'N/A' : tentative.toFixed(2) + '%';
                if (finalCell) finalCell.textContent = finalPercent.toFixed(2) + '%';

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
            qsa('#gradesTable tbody tr').forEach(row => {
                const enroll = row.getAttribute('data-enrollment-id');
                data[enroll] = {};
                qsa('input.grade-input', row).forEach(inp => {
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