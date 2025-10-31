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

// Recent enrollments: build SELECT depending on available user columns
$selectCols = [];
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $selectCols[] = 'u.first_name';
    $selectCols[] = 'u.last_name';
} elseif (in_array('name', $userCols)) {
    $selectCols[] = 'u.name';
}
if (in_array('student_id', $userCols)) $selectCols[] = 'u.student_id';
if (in_array('year_level', $userCols)) $selectCols[] = 'u.year_level';
// choose a date column for ordering
$orderCol = in_array('created_at', $userCols) ? 'u.created_at' : (in_array('id', $userCols) ? 'u.id' : null);

$select = implode(', ', $selectCols);
if (empty($select)) {
    // fallback
    $select = 'u.student_id';
}

$sql = "SELECT $select, p.name as program_name FROM users u LEFT JOIN programs p ON u.program_id = p.id WHERE u.role = 'student'";
if ($orderCol) {
    $sql .= " ORDER BY $orderCol DESC";
}
$sql .= " LIMIT 5";

try {
    $stmt = $pdo->query($sql);
    $recentStudents = $stmt->fetchAll();
} catch (Exception $e) {
    $recentStudents = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'dashboard'; require __DIR__ . '/inc/sidebar.php'; ?>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Mobile Menu Toggle -->
            <button class="menu-toggle" onclick="toggleSidebar()">☰ Menu</button>
            
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
                <div class="stat-card">
                    <h3>Total Students</h3>
                    <div class="number"><?php echo $stats['students']; ?></div>
                </div>
                <?php if ($activeSchoolYear): ?>
                <div class="stat-card">
                    <h3>Active Students (<?php echo htmlspecialchars($activeSchoolYear); ?>)</h3>
                    <div class="number"><?php echo $stats['active_students']; ?></div>
                </div>
                <?php endif; ?>
                <div class="stat-card">
                    <h3>Total Instructors</h3>
                    <div class="number"><?php echo $stats['instructors']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Programs</h3>
                    <div class="number"><?php echo $stats['programs']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Courses</h3>
                    <div class="number"><?php echo $stats['courses']; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Pending Flags</h3>
                    <div class="number"><?php echo $stats['flags']; ?></div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="activity-card">
                <h2>Recently Enrolled Students</h2>
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Year Level</th>
                            <th>Date Enrolled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recentStudents) > 0): ?>
                            <?php foreach ($recentStudents as $student): ?>
                                <?php
                                    // Safely build display values depending on which columns were selected
                                    $sid = isset($student['student_id']) ? $student['student_id'] : ($student['student_id'] ?? 'N/A');
                                    if (isset($student['first_name']) || isset($student['last_name'])) {
                                        $name = trim((isset($student['first_name']) ? $student['first_name'] : '') . ' ' . (isset($student['last_name']) ? $student['last_name'] : '')) ?: 'N/A';
                                    } elseif (isset($student['name'])) {
                                        $name = $student['name'];
                                    } else {
                                        $name = 'N/A';
                                    }
                                    $program = $student['program_name'] ?? 'N/A';
                                    $year = $student['year_level'] ?? 'N/A';
                                    $dateEnrolled = 'N/A';
                                    if (!empty($student['created_at'])) {
                                        $ts = strtotime($student['created_at']);
                                        if ($ts !== false) $dateEnrolled = date('M d, Y', $ts);
                                    } elseif (!empty($student['id'])) {
                                        // if created_at missing, use id as proxy (no date)
                                        $dateEnrolled = '—';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sid); ?></td>
                                    <td><?php echo htmlspecialchars($name); ?></td>
                                    <td><?php echo htmlspecialchars($program); ?></td>
                                    <td><?php echo htmlspecialchars($year); ?></td>
                                    <td><?php echo htmlspecialchars($dateEnrolled); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999;">No recent enrollments</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
        }
    </script>
</body>
</html>
