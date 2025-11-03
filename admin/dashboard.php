<?php
/**
 * Admin Dashboard
 * Main dashboard for administrators
 */

require_once '../config.php';
requireRole('admin');

// Fetch dashboard statistics
$stats = [];

// Helper: detect columns for users and flags
$userCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

$flagCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM flags");
    $flagCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

// Active school year
$activeSchoolYear = null;
$activeSemesterRaw = null;
$activeSemesterLabel = '';
try {
    $stmt = $pdo->query("SELECT year, semester FROM school_years WHERE status = 'active' LIMIT 1");
    $rowSY = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($rowSY) {
        $activeSchoolYear = $rowSY['year'] ?? null;
        $activeSemesterRaw = strtolower((string)($rowSY['semester'] ?? ''));
        if ($activeSemesterRaw==='1' || $activeSemesterRaw==='first' || $activeSemesterRaw==='1st') { $activeSemesterLabel='1st Semester'; }
        elseif ($activeSemesterRaw==='2' || $activeSemesterRaw==='second' || $activeSemesterRaw==='2nd') { $activeSemesterLabel='2nd Semester'; }
        elseif ($activeSemesterRaw==='3' || $activeSemesterRaw==='summer') { $activeSemesterLabel='Summer'; }
        // Fallback: infer from enrollments if empty/0
        if ($activeSemesterLabel==='') {
            try {
                $syIdInf = (int)($pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetchColumn());
                if ($syIdInf) {
                    $inf = $pdo->prepare("SELECT c.semester, COUNT(*) cnt FROM enrollments e INNER JOIN courses c ON e.course_id=c.id WHERE e.school_year_id=? AND c.semester IS NOT NULL AND c.semester>0 GROUP BY c.semester ORDER BY cnt DESC LIMIT 1");
                    $inf->execute([$syIdInf]);
                    $sv = (int)($inf->fetchColumn() ?: 0);
                    if ($sv===1) $activeSemesterLabel='1st Semester';
                    elseif ($sv===2) $activeSemesterLabel='2nd Semester';
                    elseif ($sv===3) $activeSemesterLabel='Summer';
                }
            } catch (Exception $e) { /* ignore */ }
            if ($activeSemesterLabel==='') { $activeSemesterLabel='Semester'; }
        }
    }
} catch (Exception $e) {
    $activeSchoolYear = null;
}

// Total students (all time)
$stats['students'] = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'");
    $row = $stmt->fetch();
    $stats['students'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $stats['students'] = 0;
}

// Students enrolled in active school year
$stats['active_students'] = 0;
if ($activeSchoolYear) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.student_id) as count FROM enrollments e JOIN school_years sy ON e.school_year_id = sy.id WHERE sy.status = 'active'");
        $stmt->execute();
        $row = $stmt->fetch();
        $stats['active_students'] = $row['count'] ?? 0;
    } catch (Exception $e) {
        $stats['active_students'] = 0;
    }
}

// Total instructors
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'instructor'");
    $row = $stmt->fetch();
    $stats['instructors'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $stats['instructors'] = 0;
}

// Total programs
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM programs");
    $row = $stmt->fetch();
    $stats['programs'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $stats['programs'] = 0;
}

// Total courses
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM courses");
    $row = $stmt->fetch();
    $stats['courses'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $stats['courses'] = 0;
}

// Pending flags: if flags.status exists, filter; otherwise count all flags
try {
    if (in_array('status', $flagCols)) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM flags WHERE status = 'pending'");
    } else {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM flags");
    }
    $row = $stmt->fetch();
    $stats['flags'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $stats['flags'] = 0;
}

// Fetch data for modals

// Students by year level
$studentsByYear = [];
if (in_array('year_level', $userCols)) {
    try {
        $stmt = $pdo->query("SELECT year_level, COUNT(*) as count FROM users WHERE role = 'student' GROUP BY year_level ORDER BY year_level");
        $studentsByYear = $stmt->fetchAll();
    } catch (Exception $e) {
        $studentsByYear = [];
    }
}

// Active students by program
$activeStudentsByProgram = [];
if ($activeSchoolYear) {
    try {
        $stmt = $pdo->query("SELECT p.name, COUNT(DISTINCT e.student_id) as count FROM enrollments e JOIN school_years sy ON e.school_year_id = sy.id JOIN users u ON e.student_id = u.id JOIN programs p ON u.program_id = p.id WHERE sy.status = 'active' GROUP BY p.id, p.name ORDER BY p.name");
        $activeStudentsByProgram = $stmt->fetchAll();
    } catch (Exception $e) {
        $activeStudentsByProgram = [];
    }
}

// Instructors with course assignments
$instructorsList = [];
try {
    $stmt = $pdo->query("SELECT u.id, u.first_name, u.last_name, u.email, COUNT(DISTINCT c.id) as course_count FROM users u LEFT JOIN courses c ON u.id = c.instructor_id WHERE u.role = 'instructor' GROUP BY u.id ORDER BY u.last_name, u.first_name");
    $instructorsList = $stmt->fetchAll();
} catch (Exception $e) {
    $instructorsList = [];
}

// Programs with student counts
$programsList = [];
try {
    $stmt = $pdo->query("SELECT p.name, p.code, COUNT(u.id) as student_count FROM programs p LEFT JOIN users u ON p.id = u.program_id AND u.role = 'student' GROUP BY p.id ORDER BY p.name");
    $programsList = $stmt->fetchAll();
} catch (Exception $e) {
    $programsList = [];
}

// Courses by year level
$coursesByYear = [];
try {
    $stmt = $pdo->query("SELECT year_level, COUNT(*) as count FROM courses GROUP BY year_level ORDER BY year_level");
    $coursesByYear = $stmt->fetchAll();
} catch (Exception $e) {
    $coursesByYear = [];
}

// Graduates: final-year students (year_level=3) who completed all assessments (no NULL grades) in active SY
$graduates = ['total_final'=>0, 'graduates'=>0, 'percent'=>0.0];
$gradsByProgram = [];
try {
    $sy = $pdo->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sy) {
        $syId = (int)$sy['id'];
        // Final-year active students with active enrollments
        $st = $pdo->query("SELECT u.id, u.program_id FROM users u WHERE u.role='student' AND u.year_level=3 AND (u.status IS NULL OR u.status='active')");
        $allFinal = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($allFinal) {
            $students = array_column($allFinal, 'id');
            $graduates['total_final'] = count($students);
            // Map programs
            foreach ($allFinal as $r) { $pid=(int)$r['program_id']; if(!isset($gradsByProgram[$pid])) $gradsByProgram[$pid] = ['final'=>0,'grads'=>0,'name'=>'']; $gradsByProgram[$pid]['final']++; }
            // Program names
            $pnames = $pdo->query("SELECT id, name FROM programs")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($gradsByProgram as $pid=>$_) { $gradsByProgram[$pid]['name'] = $pnames[$pid] ?? ('Program #'.$pid); }
            // Enrollments in active SY for these students
            $ph = implode(',', array_fill(0, count($students), '?'));
            $enr = $pdo->prepare("SELECT e.id as eid, e.student_id, e.course_id FROM enrollments e WHERE e.school_year_id=? AND e.student_id IN ($ph)");
            $enr->execute(array_merge([$syId], array_map('intval',$students)));
            $byStu = [];
            foreach ($enr->fetchAll(PDO::FETCH_ASSOC) as $r) { $byStu[(int)$r['student_id']][] = (int)$r['eid']; }
            // Helpers
            $getCid  = $pdo->prepare("SELECT course_id FROM enrollments WHERE id=? LIMIT 1");
            $getItem = $pdo->prepare("SELECT ai.id FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id=ac.id WHERE ac.course_id=?");
            foreach ($students as $sid) {
                $enrs = $byStu[$sid] ?? [];
                if (!$enrs) { continue; }
                $okAll = true;
                foreach ($enrs as $eid) {
                    $getCid->execute([$eid]);
                    $cid = (int)$getCid->fetchColumn();
                    if (!$cid) { $okAll=false; break; }
                    $getItem->execute([$cid]);
                    $items = array_column($getItem->fetchAll(PDO::FETCH_ASSOC),'id');
                    if ($items) {
                        $ph2 = implode(',', array_fill(0, count($items), '?'));
                        $q = $pdo->prepare(sprintf("SELECT COUNT(*) FROM grades WHERE enrollment_id=? AND assessment_id IN (%s) AND grade IS NOT NULL", $ph2));
                        $q->execute(array_merge([$eid], array_map('intval',$items)));
                        $have = (int)$q->fetchColumn();
                        if ($have < count($items)) { $okAll=false; break; }
                    }
                }
                if ($okAll) {
                    $graduates['graduates']++;
                    // bump program count
                    foreach ($allFinal as $r) { if ((int)$r['id']===$sid){ $pid=(int)$r['program_id']; $gradsByProgram[$pid]['grads']++; break; } }
                }
            }
            $graduates['percent'] = $graduates['total_final']>0 ? round(($graduates['graduates']/$graduates['total_final'])*100,2) : 0.0;
        }
    }
} catch (Exception $e) { /* ignore */ }

// Pending flags list
$flagsList = [];
try {
    if (in_array('status', $flagCols)) {
        $stmt = $pdo->query("SELECT f.*, u.first_name, u.last_name, u.student_id FROM flags f LEFT JOIN users u ON f.student_id = u.id WHERE f.status = 'pending' ORDER BY f.id DESC LIMIT 20");
    } else {
        $stmt = $pdo->query("SELECT f.*, u.first_name, u.last_name, u.student_id FROM flags f LEFT JOIN users u ON f.student_id = u.id ORDER BY f.id DESC LIMIT 20");
    }
    $flagsList = $stmt->fetchAll();
} catch (Exception $e) {
    $flagsList = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'dashboard'; require __DIR__ . '/inc/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Mobile Menu Toggle -->
            <button class="menu-toggle">☰ Menu</button>
            
            <!-- Header -->
            <?php $pageTitle = 'Dashboard'; require __DIR__ . '/inc/header.php'; ?>
            
            <!-- Active School Year Banner -->
            <?php if ($activeSchoolYear): ?>
                <div class="alert alert-info" style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border: 1px solid #2196f3; border-radius: 5px; display:flex; align-items:center; gap:12px; justify-content:space-between;">
                    <div>
                        <strong><?php echo htmlspecialchars($activeSchoolYear); ?></strong>
                        <?php if ($activeSemesterLabel!==''): ?>
                            <span>• <?php echo htmlspecialchars($activeSemesterLabel); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn" onclick="location.reload()" style="background:#2196f3;color:#fff;">Refresh</button>
                </div>

            <!-- Graduates Modal -->
            <div id="graduatesModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('graduatesModal')">&times;</span>
                    <h2>Graduates (Active School Year)</h2>
                    <p>Total final-year students: <?php echo (int)$graduates['total_final']; ?> • Graduated: <?php echo (int)$graduates['graduates']; ?> (<?php echo $graduates['percent']; ?>%)</p>
                    <table class="modal-table">
                        <thead><tr><th>Program</th><th>Final-Year</th><th>Graduated</th><th>%</th></tr></thead>
                        <tbody>
                            <?php if (!empty($gradsByProgram)): foreach ($gradsByProgram as $pg): $pct = $pg['final']>0 ? round(($pg['grads']/$pg['final'])*100,2) : 0; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pg['name']); ?></td>
                                    <td><?php echo (int)$pg['final']; ?></td>
                                    <td><?php echo (int)$pg['grads']; ?></td>
                                    <td><?php echo $pct; ?>%</td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="4" style="text-align:center;color:#999;">No data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom: 20px; padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px;">
                    <strong>No Active School Year:</strong> Please set an active school year in the School Years section.
                </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card clickable" onclick="openModal('studentsModal')">
                    <h3>Total Students</h3>
                    <div class="number"><?php echo $stats['students']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click for breakdown</small>
                </div>
                <?php if ($activeSchoolYear): ?>
                <div class="stat-card clickable" onclick="openModal('activeStudentsModal')">
                    <h3>Active Students (<?php echo htmlspecialchars($activeSchoolYear); ?>)</h3>
                    <div class="number"><?php echo $stats['active_students']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click for breakdown</small>
                </div>
                <?php endif; ?>
                <div class="stat-card clickable" onclick="openModal('instructorsModal')">
                    <h3>Total Instructors</h3>
                    <div class="number"><?php echo $stats['instructors']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click for details</small>
                </div>
                <div class="stat-card clickable" onclick="openModal('programsModal')">
                    <h3>Programs</h3>
                    <div class="number"><?php echo $stats['programs']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click for details</small>
                </div>
                <div class="stat-card clickable" onclick="openModal('coursesModal')">
                    <h3>Courses</h3>
                    <div class="number"><?php echo $stats['courses']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click for breakdown</small>
                </div>
                <div class="stat-card clickable" onclick="openModal('flagsModal')">
                    <h3>Pending Flags</h3>
                    <div class="number"><?php echo $stats['flags']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Click to view</small>
                </div>
                <div class="stat-card clickable" onclick="openModal('graduatesModal')">
                    <h3>Graduates (Active SY)</h3>
                    <div class="number"><?php echo (int)$graduates['graduates']; ?></div>
                    <small style="color:#999;font-size:12px;margin-top:8px;display:block;">Final-year: <?php echo (int)$graduates['total_final']; ?> • <?php echo $graduates['percent']; ?>%</small>
                </div>
            </div>
            
            <!-- Modals -->
            
            <!-- Total Students Modal -->
            <div id="studentsModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('studentsModal')">&times;</span>
                    <h2>Students by Year Level</h2>
                    <table class="modal-table">
                        <thead><tr><th>Year Level</th><th>Enrolled</th></tr></thead>
                        <tbody>
                            <?php if (count($studentsByYear) > 0): ?>
                                <?php foreach ($studentsByYear as $row): ?>
                                    <?php
                                        $yearLevel = (int)$row['year_level'];
                                        $yearText = '';
                                        if ($yearLevel == 1) $yearText = '1st Year';
                                        elseif ($yearLevel == 2) $yearText = '2nd Year';
                                        elseif ($yearLevel == 3) $yearText = '3rd Year';
                                        else $yearText = $yearLevel . 'th Year';
                                    ?>
                                    <tr><td><?php echo htmlspecialchars($yearText); ?></td><td><?php echo $row['count']; ?></td></tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" style="text-align:center;color:#999;">No data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Active Students Modal -->
            <div id="activeStudentsModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('activeStudentsModal')">&times;</span>
                    <h2>Active Students by Program</h2>
                    <table class="modal-table">
                        <thead><tr><th>Program</th><th>Students</th></tr></thead>
                        <tbody>
                            <?php if (count($activeStudentsByProgram) > 0): ?>
                                <?php foreach ($activeStudentsByProgram as $row): ?>
                                    <tr><td><?php echo htmlspecialchars($row['name']); ?></td><td><?php echo $row['count']; ?></td></tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" style="text-align:center;color:#999;">No data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Instructors Modal -->
            <div id="instructorsModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('instructorsModal')">&times;</span>
                    <h2>Instructors</h2>
                    <table class="modal-table">
                        <thead><tr><th>Name</th><th>Email</th><th>Courses</th></tr></thead>
                        <tbody>
                            <?php if (count($instructorsList) > 0): ?>
                                <?php foreach ($instructorsList as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                                        <td><?php echo $row['course_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align:center;color:#999;">No instructors found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Programs Modal -->
            <div id="programsModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('programsModal')">&times;</span>
                    <h2>Programs</h2>
                    <table class="modal-table">
                        <thead><tr><th>Program</th><th>Code</th><th>Students</th></tr></thead>
                        <tbody>
                            <?php if (count($programsList) > 0): ?>
                                <?php foreach ($programsList as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['code']); ?></td>
                                        <td><?php echo $row['student_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align:center;color:#999;">No programs found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Courses Modal -->
            <div id="coursesModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('coursesModal')">&times;</span>
                    <h2>Courses by Year Level</h2>
                    <table class="modal-table">
                        <thead><tr><th>Year Level</th><th>Courses</th></tr></thead>
                        <tbody>
                            <?php if (count($coursesByYear) > 0): ?>
                                <?php foreach ($coursesByYear as $row): ?>
                                    <?php
                                        $yearLevel = (int)$row['year_level'];
                                        $yearText = '';
                                        if ($yearLevel == 1) $yearText = '1st Year';
                                        elseif ($yearLevel == 2) $yearText = '2nd Year';
                                        elseif ($yearLevel == 3) $yearText = '3rd Year';
                                        else $yearText = $yearLevel . 'th Year';
                                    ?>
                                    <tr><td><?php echo htmlspecialchars($yearText); ?></td><td><?php echo $row['count']; ?></td></tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" style="text-align:center;color:#999;">No courses found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Flags Modal -->
            <div id="flagsModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal('flagsModal')">&times;</span>
                    <h2>Pending Flags</h2>
                    <table class="modal-table">
                        <thead><tr><th>Student</th><th>Reason</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php if (count($flagsList) > 0): ?>
                                <?php foreach ($flagsList as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(($row['student_id'] ?? '') . ' - ' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></td>
                                        <td><?php echo htmlspecialchars($row['reason'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['created_at'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align:center;color:#999;">No pending flags</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </main>
    </div>
    
    <style>
        .clickable { cursor: pointer; transition: transform 0.2s; }
        .clickable:hover { transform: translateY(-5px); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: var(--card, #fff); margin: 5% auto; padding: 30px; border-radius: 10px; width: 80%; max-width: 700px; position: relative; }
        .modal-close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .modal-close:hover { color: #000; }
        .modal-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .modal-table th, .modal-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        .modal-table th { background: var(--violet, #6a0dad); color: white; }
        .modal h2 { color: var(--violet, #6a0dad); margin-bottom: 10px; }
    </style>
    
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
