<?php
/**
 * Student Dashboard
 * Shows enrolled courses and grade status
 */

require_once '../config.php';
requireRole('student');

$studentId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Fetch student information
$stmt = $pdo->prepare("
    SELECT u.*, p.name as program_name, p.code as program_code
    FROM users u
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE u.id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// Handle on-demand enrollment into active semester courses
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_now'])) {
    try {
        if (empty($student['program_id']) || empty($student['year_level'])) {
            throw new Exception('Your program or year level is not set. Please contact the administrator.');
        }
        $res = auto_enroll_student($pdo, (int)$studentId, (int)$student['program_id'], (int)$student['year_level']);
        if (!($res['success'] ?? false)) {
            throw new Exception($res['error'] ?? 'Enrollment failed');
        }
        $msg = 'Enrollment completed. Enrolled in ' . (int)($res['enrolled'] ?? 0) . ' course(s) for the active semester.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($new === '' || $confirm === '') { throw new Exception('Please enter and confirm your new password.'); }
        if (strlen($new) < 8) { throw new Exception('New password must be at least 8 characters.'); }
        if ($new !== $confirm) { throw new Exception('New password and confirmation do not match.'); }
        // Detect password column
        $cols = [];
        try { $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $cols = []; }
        $pwCol = null;
        foreach (['password','passwd','pass'] as $cand) { if (in_array($cand, $cols, true)) { $pwCol = $cand; break; } }
        if (!$pwCol) { throw new Exception('Password change is not available at this time.'); }
        // Fetch current hash
        $st = $pdo->prepare("SELECT $pwCol FROM users WHERE id = ? LIMIT 1");
        $st->execute([$studentId]);
        $hash = (string)$st->fetchColumn();
        if ($hash !== '' && !password_verify($current, $hash)) { throw new Exception('Current password is incorrect.'); }
        // Update
        $newHash = hash_password($new);
        $up = $pdo->prepare("UPDATE users SET $pwCol = ? WHERE id = ?");
        $up->execute([$newHash, $studentId]);
        $msg = 'Your password has been updated.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Resolve active school year and semester (schema-adaptive: use active_semester when available; fallback to semester or 1)
$syRow = $pdo->query("\n    SELECT \n        id,\n        year,\n        CASE \n            WHEN EXISTS(\n                SELECT 1 FROM information_schema.COLUMNS \n                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_years' AND COLUMN_NAME = 'active_semester'\n            ) THEN active_semester\n            ELSE COALESCE(semester, 1)\n        END AS active_semester\n    FROM school_years\n    WHERE status='active'\n    ORDER BY id DESC\n    LIMIT 1\n")->fetch(PDO::FETCH_ASSOC);
$activeSyId = $syRow['id'] ?? null;
$activeYear = $syRow['year'] ?? null;
$activeSemester = (int)($syRow['active_semester'] ?? 1);

// Check if enrollments has school_year_id for filtering
$enrCols = [];
try { $enrCols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $enrCols = []; }
$hasSyCol = in_array('school_year_id', $enrCols, true);

// Fetch enrolled courses with grade status, restricted to active school year if available
$sql = "
    SELECT
        c.id as course_id,
        c.course_code,
        c.course_name,
        c.semester,
        c.year_level,
        e.status as enrollment_status,
        COUNT(DISTINCT CASE WHEN (g.grade IS NOT NULL OR g.status = 'complete') THEN g.id END) as graded_assessments,
        COUNT(DISTINCT ai.id) as total_assessments,
        SUM(CASE WHEN g.status = 'missing' THEN 1 ELSE 0 END) as missing_count,
        AVG(CASE WHEN g.grade IS NOT NULL THEN g.grade ELSE NULL END) as average_grade
    FROM enrollments e
    INNER JOIN courses c ON e.course_id = c.id
    LEFT JOIN assessment_criteria ac ON c.id = ac.course_id
    LEFT JOIN assessment_items ai ON ac.id = ai.criteria_id
    LEFT JOIN grades g ON e.id = g.enrollment_id AND ai.id = g.assessment_id
    WHERE e.student_id = ? AND e.status = 'enrolled'" .
    ($hasSyCol && $activeSyId ? " AND e.school_year_id = ?" : "") .
    " GROUP BY c.id, e.id
      ORDER BY (c.semester = " . (int)$activeSemester . ") DESC, c.semester, c.year_level, c.course_name";

$params = [$studentId];
if ($hasSyCol && $activeSyId) { $params[] = $activeSyId; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$enrolledCourses = $stmt->fetchAll();

// Group by semester
$bySem = [1=>[], 2=>[], 3=>[]];
foreach ($enrolledCourses as $c) { $sem = (int)($c['semester'] ?? 0); if (!$sem) $sem = 1; $bySem[$sem][] = $c; }

// Fetch notifications
$stmt = $pdo->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? AND status = 'unread' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute([$studentId]);
$notifications = $stmt->fetchAll();

// Fetch active flags
$stmt = $pdo->prepare("
    SELECT f.*, c.course_name, c.course_code
    FROM flags f
    INNER JOIN courses c ON f.course_id = c.id
    WHERE f.student_id = ? AND f.status = 'pending'
    ORDER BY f.created_at DESC
");
$stmt->execute([$studentId]);
$flags = $stmt->fetchAll();

/**
 * Get grade status for display
 */
function getGradeStatus($gradedCount, $totalCount, $missingCount) {
    if ($missingCount > 0) {
        return ['status' => 'Missing Requirements', 'class' => 'status-missing'];
    } elseif ($gradedCount < $totalCount) {
        return ['status' => 'Incomplete', 'class' => 'status-incomplete'];
    } elseif ($gradedCount > 0) {
        return ['status' => 'Completed', 'class' => 'status-complete'];
    } else {
        return ['status' => 'Not Graded', 'class' => 'status-incomplete'];
    }
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'scores') {
    header('Content-Type: application/json');
    $courseId = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
    if (!$courseId) { echo json_encode(['error' => 'course_id required']); exit; }
    $sql = "
        SELECT c.id AS course_id, c.course_name, c.course_code,
               ac.period, ai.id AS assessment_id, ai.name AS assessment_name, ai.total_score,
               g.grade, g.status
        FROM enrollments e
        INNER JOIN courses c ON e.course_id = c.id
        LEFT JOIN assessment_criteria ac ON ac.course_id = c.id
        LEFT JOIN assessment_items ai ON ai.criteria_id = ac.id
        LEFT JOIN grades g ON g.assessment_id = ai.id AND g.enrollment_id = e.id
        WHERE e.student_id = ? AND e.status = 'enrolled' AND c.id = ?" .
        ($hasSyCol && $activeSyId ? " AND e.school_year_id = ?" : "") .
        " ORDER BY ac.period, ai.name";
    $params = [$studentId, $courseId];
    if ($hasSyCol && $activeSyId) { $params[] = $activeSyId; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [ 'course' => null, 'items' => [] ];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!$out['course']) {
            $out['course'] = [
                'id' => (int)$r['course_id'],
                'name' => (string)($r['course_name'] ?? ''),
                'code' => (string)($r['course_code'] ?? ''),
            ];
        }
        if ($r['assessment_id']) {
            $out['items'][] = [
                'id' => (int)$r['assessment_id'],
                'name' => $r['assessment_name'],
                'period' => $r['period'] ?? null,
                'total_score' => $r['total_score'] !== null ? (float)$r['total_score'] : null,
                'grade' => $r['grade'] !== null ? (float)$r['grade'] : null,
                'status' => $r['status'] ?? null
            ];
        }
    }
    echo json_encode($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #333;
        }
        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; }
        .modal.show { display:block; }
        .modal-content { background:#fff; width:92%; max-width:520px; margin:8% auto; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.2); overflow:hidden; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; background:#f7f7fb; border-bottom:1px solid #eee; }
        .modal-header h3 { margin:0; color:#6a0dad; }
        .modal-body { padding:16px; }
        .modal-footer { padding:12px 16px; display:flex; justify-content:flex-end; gap:8px; border-top:1px solid #eee; background:#fafafa; }
        .close { cursor:pointer; font-size:22px; line-height:1; padding:4px 8px; border-radius:6px; }
        .close:hover { background:#eee; }
        .field { margin-bottom:12px; }
        .field label { display:block; font-size:12px; color:#666; margin-bottom:6px; }
        .field input { width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #6a0dad 0%, #9b59b6 100%);
            color: white;
            padding: 25px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .header p {
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .btn-logout {
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .profile-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #6a0dad;
        }
        
        .info-item label {
            font-size: 12px;
            color: #666;
            display: block;
            margin-bottom: 5px;
        }
        
        .info-item strong {
            font-size: 16px;
            color: #333;
        }
        
        /* Notifications */
        .notifications-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .notifications-section h2 {
            color: #6a0dad;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .notification-item {
            padding: 12px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .flag-item {
            padding: 12px;
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        /* Courses Section */
        .courses-section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .courses-section h2 {
            color: #6a0dad;
            margin-bottom: 20px;
            font-size: 22px;
        }
        
        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .course-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .course-card:hover {
            border-color: #6a0dad;
            box-shadow: 0 4px 12px rgba(106, 13, 173, 0.1);
        }
        
        .course-header {
            margin-bottom: 15px;
        }
        
        .course-code {
            font-size: 14px;
            color: #6a0dad;
            font-weight: 600;
        }
        
        .course-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-top: 5px;
        }
        
        .course-details {
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }
        
        .grade-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .status-complete {
            background: #d4edda;
            color: #155724;
        }
        
        .status-incomplete {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-missing {
            background: #f8d7da;
            color: #721c24;
        }
        
        .progress-info {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .container {
                padding: 15px;
            }
            
            .course-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div>
                <?php $ord = [1=>'1st',2=>'2nd',3=>'3rd',4=>'4th']; $yl = (int)($student['year_level'] ?? 0); ?>
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h1>
                <p>
                    <?php echo htmlspecialchars($student['program_name'] ?? ''); ?> • Year Level: <?php echo $yl>0 ? $ord[$yl] ?? $yl.'th' : 'N/A'; ?> • Active School Year: <?php echo htmlspecialchars($activeYear ?? 'N/A'); ?> • Active Semester: <?php echo ($activeSemester===1?'1st':($activeSemester===2?'2nd':'Summer')); ?>
                </p>
            </div>
            <?php require_once __DIR__.'/../include/functions.php'; $unread = get_unread_count($pdo, (int)$_SESSION['user_id']); ?>
            <a href="notifications.php" class="btn-logout" style="background:#6a0dad; margin-right:8px; position:relative;">🔔 Notifications<?php if($unread>0): ?><span style="position:absolute; top:-6px; right:-6px; background:#e11d48; color:#fff; border-radius:999px; padding:2px 6px; font-size:11px; font-weight:700; line-height:1; "><?php echo (int)$unread; ?></span><?php endif; ?></a>
            <button type="button" id="openChangePwd" class="btn-logout" style="background:#4b048f; margin-right:8px;">Change Password</button>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Main Container -->
    <div class="container">
        <!-- Profile Information -->
        <div class="profile-card">
            <div class="profile-info">
                <div class="info-item">
                    <label>Student ID</label>
                    <strong><?php echo htmlspecialchars($student['student_id']); ?></strong>
                </div>
                <div class="info-item">
                    <label>Email</label>
                    <strong><?php echo htmlspecialchars($student['email']); ?></strong>
                </div>
                <div class="info-item">
                    <label>Program</label>
                    <strong><?php echo htmlspecialchars($student['program_name'] ?? 'N/A'); ?></strong>
                </div>
                <div class="info-item">
                    <label>Year Level</label>
                    <strong><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></strong>
                </div>
            </div>
        </div>
        <?php if ($error): ?>
            <div class="notification-item" style="background:#ffe5e5;border-left-color:#e11d48;">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($msg): ?>
            <div class="notification-item" style="background:#e6f9ef;border-left-color:#16a34a;">✅ <?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        
        <!-- Notifications and Flags -->
        <?php if (count($flags) > 0 || count($notifications) > 0): ?>
            <div class="notifications-section">
                <h2>⚠️ Alerts & Notifications</h2>
                
                <?php foreach ($flags as $flag): ?>
                    <div class="flag-item">
                        <strong>⚠️ Flag in <?php echo htmlspecialchars($flag['course_name']); ?></strong>
                        <p><?php echo htmlspecialchars($flag['issue']); ?></p>
                        <?php if ($flag['description']): ?>
                            <p style="font-size: 13px; margin-top: 5px;"><?php echo htmlspecialchars($flag['description']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item">
                        <?php echo htmlspecialchars($notif['message']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Enrolled Courses -->
        <div class="courses-section">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <h2 style="margin:0;">📚 Enrolled Courses</h2>
                <form method="POST">
                    <button class="btn-logout" style="background:#6a0dad;" name="enroll_now" value="1">Enroll in Active Semester Courses</button>
                </form>
            </div>
            <?php 
                $semLabels = [1=>'1st Semester', 2=>'2nd Semester', 3=>'Summer'];
                $order = [$activeSemester, ...array_values(array_diff([1,2,3], [$activeSemester]))];
                $hasAny = count($enrolledCourses) > 0;
            ?>
            <?php if ($hasAny): ?>
                <?php foreach ($order as $sem): if (empty($bySem[$sem])) continue; ?>
                    <h3 style="margin:10px 0 6px; color:#6a0dad;">
                        <?php echo $semLabels[$sem]; ?><?php if ($sem===$activeSemester): ?> <span style="font-size:12px;color:#16a34a;border:1px solid #ade3bf;padding:2px 8px;border-radius:999px;">Active</span><?php endif; ?>
                    </h3>
                    <div class="table-responsive">
                        <table class="report-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Code</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Course</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Year Level</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Status</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #eee;">Scores</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bySem[$sem] as $course): 
                                    $status = getGradeStatus($course['graded_assessments'], $course['total_assessments'], $course['missing_count']);
                                ?>
                                    <tr>
                                        <td style="padding:10px;border-bottom:1px solid #f3f3f3; color:#6a0dad; font-weight:600;"><?php echo htmlspecialchars($course['course_code']); ?></td>
                                        <td style="padding:10px;border-bottom:1px solid #f3f3f3; font-weight:600; color:#333;">
                                            <?php echo htmlspecialchars($course['course_name']); ?>
                                        </td>
                                        <td style="padding:10px;border-bottom:1px solid #f3f3f3;">
                                            <?php $cyl=(int)($course['year_level']??0); echo $cyl>0 ? ($ord[$cyl] ?? ($cyl.'th')) : 'N/A'; ?>
                                        </td>
                                        <td style="padding:10px;border-bottom:1px solid #f3f3f3;">
                                            <span class="<?php echo $status['class']; ?>" style="display:inline-block;padding:6px 10px;border-radius:999px;">
                                                <?php echo $status['status']; ?>
                                            </span>
                                        </td>
                                        <td style="padding:10px;border-bottom:1px solid #f3f3f3;">
                                            <button type="button" class="btn-logout view-scores" style="background:#6a0dad;" data-score-course="<?php echo (int)$course['course_id']; ?>" data-score-coursename="<?php echo htmlspecialchars($course['course_name']); ?>" data-score-coursecode="<?php echo htmlspecialchars($course['course_code']); ?>">View Scores</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #666; text-align: center; padding: 40px;">
                    No courses enrolled yet. Please contact your administrator.
                </p>
            <?php endif; ?>
        </div>
        <!-- Change Password Modal -->
        <div id="changePwdModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="changePwdTitle">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="changePwdTitle">Change Password</h3>
                    <span class="close" id="closeChangePwd" aria-label="Close">&times;</span>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="field">
                            <label for="cp_current">Current Password</label>
                            <input id="cp_current" type="password" name="current_password" required>
                        </div>
                        <div class="field">
                            <label for="cp_new">New Password (min 8 chars)</label>
                            <input id="cp_new" type="password" name="new_password" required minlength="8">
                        </div>
                        <div class="field">
                            <label for="cp_confirm">Confirm New Password</label>
                            <input id="cp_confirm" type="password" name="confirm_password" required minlength="8">
                        </div>
                        <input type="hidden" name="change_password" value="1">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-logout" id="cancelChangePwd" style="background:#6b7280;">Cancel</button>
                        <button type="submit" class="btn-logout" style="background:#6a0dad;">Update Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Scores Modal -->
        <div id="scoresModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="scoresTitle">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="scoresTitle">Scores</h3>
                    <span class="close" id="scoresClose" aria-label="Close">&times;</span>
                </div>
                <div class="modal-body">
                    <div id="scoresBody" style="min-height:80px;">Loading...</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-logout" id="scoresCancel" style="background:#6b7280;">Close</button>
                </div>
            </div>
        </div>
    </div>
<script>
(function(){
  const modal = document.getElementById('changePwdModal');
  const openBtn = document.getElementById('openChangePwd');
  const closeBtn = document.getElementById('closeChangePwd');
  const cancelBtn = document.getElementById('cancelChangePwd');
  function open(){ modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); }
  function close(){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); }
  if (openBtn) openBtn.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (cancelBtn) cancelBtn.addEventListener('click', close);
  window.addEventListener('click', function(e){ if (e.target === modal) close(); });
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])): ?>
  // Re-open modal after submission to show messages
  open();
  <?php endif; ?>
})();

(function(){
  const modal = document.getElementById('scoresModal');
  const body = document.getElementById('scoresBody');
  const title = document.getElementById('scoresTitle');
  const closeBtn = document.getElementById('scoresClose');
  const cancelBtn = document.getElementById('scoresCancel');
  let interval = null;
  function open(){ modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); }
  function close(){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); if (interval) { clearInterval(interval); interval = null; } }
  function escapeHtml(s){ const d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
  async function fetchScores(cid){
    const res = await fetch('dashboard.php?ajax=scores&course_id='+encodeURIComponent(cid), {cache:'no-store'});
    return res.ok ? res.json() : { items: [] };
  }
  function render(data){
    if (!data || !Array.isArray(data.items) || data.items.length===0) { body.innerHTML = '<div style="color:#666;">No assessments yet.</div>'; return; }
    // group by period if present
    const groups = {};
    for (const it of data.items){ const p = (it.period||'').toString().toLowerCase() || 'assessments'; if (!groups[p]) groups[p]=[]; groups[p].push(it); }
    let html = '';
    for (const [p, items] of Object.entries(groups)){
      const label = p==='prelim'?'Prelim':(p==='midterm'?'Midterm':(p==='finals'?'Finals':'Assessments'));
      html += '<div style="margin:8px 0 4px;font-weight:700;color:#6a0dad;">'+escapeHtml(label)+'</div>';
      html += '<ul style="list-style:none;padding:0;margin:0 0 8px 0;">';
      for (const it of items){
        const grade = (it.grade!==null && it.grade!==undefined) ? it.grade : '-';
        const total = (it.total_score!==null && it.total_score!==undefined) ? it.total_score : '-';
        const st = it.status ? ' ('+escapeHtml(it.status)+')' : '';
        html += '<li style="padding:6px 0;border-bottom:1px solid #eee;"><strong>'+escapeHtml(it.name)+'</strong> — '+escapeHtml(grade)+' / '+escapeHtml(total)+st+'</li>';
      }
      html += '</ul>';
    }
    body.innerHTML = html;
  }
  function showScores(btn){
    const cid = btn.getAttribute('data-score-course');
    const nm = btn.getAttribute('data-score-coursename') || '';
    const cc = btn.getAttribute('data-score-coursecode') || '';
    title.textContent = 'Scores — '+(cc? (cc+' — '):'')+nm;
    body.innerHTML = 'Loading...';
    open();
    fetchScores(cid).then(render);
    interval = setInterval(()=>{ fetchScores(cid).then(render); }, 30000);
  }
  document.querySelectorAll('.view-scores').forEach(btn=>{
    btn.addEventListener('click', ()=>showScores(btn));
  });
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (cancelBtn) cancelBtn.addEventListener('click', close);
  window.addEventListener('click', function(e){ if (e.target === modal) close(); });
})();
</script>
</body>
</html>
