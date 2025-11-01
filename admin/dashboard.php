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
try {
    $stmt = $pdo->query("SELECT year FROM school_years WHERE status = 'active' LIMIT 1");
    $activeSchoolYear = $stmt->fetchColumn();
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
                <div class="alert alert-info" style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border: 1px solid #2196f3; border-radius: 5px;">
                    <strong>Active School Year:</strong> <?php echo htmlspecialchars($activeSchoolYear); ?>
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
