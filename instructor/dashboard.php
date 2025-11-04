<?php
/**
 * Instructor Dashboard
 * Shows assigned courses and grade management
 */

require_once __DIR__ . '/../config.php';
requireRole('instructor');

// Handle leave section request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='leave_section') {
    $instructorId = (int)($_SESSION['user_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
    if ($instructorId && $sectionId) {
        try {
            // Verify mapping exists
            $chk = $pdo->prepare('SELECT 1 FROM instructor_sections WHERE instructor_id=? AND section_id=?');
            $chk->execute([$instructorId, $sectionId]);
            if ($chk->fetch()) {
                $del = $pdo->prepare('DELETE FROM instructor_sections WHERE instructor_id=? AND section_id=?');
                $del->execute([$instructorId, $sectionId]);
                $_SESSION['flash_success'] = 'You have been unassigned from the section.';
            } else {
                $_SESSION['flash_error'] = 'You are not assigned to this section.';
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Failed to leave section.';
        }
    } else {
        $_SESSION['flash_error'] = 'Invalid request.';
    }
    header('Location: '.$_SERVER['REQUEST_URI']);
    exit;
}

$instructorId = $_SESSION['user_id'];

// Fetch instructor's assigned sections
$stmt = $pdo->prepare("
    SELECT
        s.id as section_id,
        s.section_code,
        s.section_name,
        s.capacity,
        s.enrolled_count,
        c.id as course_id,
        c.course_code,
        c.course_name,
        c.year_level,
        c.semester,
        p.name as program_name,
        COUNT(DISTINCT e.id) as enrolled_students
    FROM instructor_sections ins
    INNER JOIN sections s ON ins.section_id = s.id
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    LEFT JOIN enrollments e ON c.id = e.course_id AND e.status = 'enrolled'
    WHERE ins.instructor_id = ? AND s.status = 'active'
    GROUP BY s.id
    ORDER BY c.semester, c.course_name, s.section_code
");
$stmt->execute([$instructorId]);
$sections = $stmt->fetchAll();

// Fetch pending flags
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM flags f
    INNER JOIN courses c ON f.course_id = c.id
    WHERE c.instructor_id = ? AND f.status = 'pending'
");
$stmt->execute([$instructorId]);
$pendingFlags = $stmt->fetch()['count'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(106, 13, 173, 0.2);
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #6a0dad;
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
        
        .course-list {
            display: grid;
            gap: 15px;
        }
        
        .course-item {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .course-item:hover {
            border-color: #6a0dad;
            box-shadow: 0 4px 12px rgba(106, 13, 173, 0.1);
        }
        
        .course-info h3 {
            color: #333;
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .course-code {
            color: #6a0dad;
            font-weight: 600;
            font-size: 14px;
        }
        
        .course-details {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
        }
        
        .course-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn-primary {
            background: #6a0dad;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a0c9d;
        }
        
        .btn-secondary {
            background: #3498db;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #2980b9;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .course-item {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .course-actions {
                width: 100%;
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div>
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h1>
                <p>Instructor Dashboard</p>
            </div>
            <?php require_once __DIR__.'/../include/functions.php'; $unread = get_unread_count($pdo, (int)$_SESSION['user_id']); ?>
            <a href="available-sections.php" class="btn-logout" style="background:#6a0dad; margin-right:8px;">📚 Browse Sections</a>
            <a href="notifications.php" class="btn-logout" style="background:#6a0dad; margin-right:8px; position:relative;">🔔 Notifications<?php if($unread>0): ?><span style="position:absolute; top:-6px; right:-6px; background:#e11d48; color:#fff; border-radius:999px; padding:2px 6px; font-size:11px; font-weight:700; line-height:1; "><?php echo (int)$unread; ?></span><?php endif; ?></a>
            <a href="../logout.php" class="btn-logout">Logout</a>
        </div>
    </div>
    
    <!-- Main Container -->
    <div class="container">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Assigned Courses</h3>
                <div class="number"><?php echo count($sections); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Students</h3>
                <div class="number">
                    <?php
                        $totalStudents = array_sum(array_column($sections, 'enrolled_students'));
                        echo $totalStudents;
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Pending Flags</h3>
                <div class="number"><?php echo $pendingFlags; ?></div>
                <div style="margin-top:10px">
                    <a class="btn btn-secondary" href="flags.php">Manage Flags</a>
                    <a class="btn btn-secondary" href="attendance-all.php" style="margin-left:6px;">All Classes</a>
                </div>
            </div>
        </div>
        
        <!-- Sections Section -->
        <div class="courses-section">
            <h2>📚 My Courses</h2>

            <?php if (count($sections) > 0): ?>
                <div class="course-list">
                    <?php foreach ($sections as $section): ?>
                        <div class="course-item">
                            <div class="course-info">
                                <div class="course-code"><?php echo htmlspecialchars($section['course_code']); ?> - <?php echo htmlspecialchars($section['section_code']); ?></div>
                                <h3><?php echo htmlspecialchars($section['course_name']); ?></h3>
                                <div class="course-details">
                                    <?php echo htmlspecialchars($section['program_name']); ?> •
                                    Year <?php echo (int)$section['year_level']; ?> •
                                    <?php $semI=(int)$section['semester']; $semLbl=$semI===1?'1st Semester':($semI===2?'2nd Semester':($semI===3?'Summer':'Semester')); echo $semLbl; ?> •
                                    <?php echo $section['enrolled_count']; ?>/<?php echo $section['capacity']; ?> students
                                </div>
                            </div>
                            <div class="course-actions">
                                <a href="manage-grades.php?section_id=<?php echo $section['section_id']; ?>" class="btn btn-primary">
                                    Manage Grades
                                </a>
                                <a href="assessments_alt.php?course_id=<?php echo $section['course_id']; ?>" class="btn btn-secondary">
                                    Assessments
                                </a>
                                <a href="schedules.php?section_id=<?php echo $section['section_id']; ?>" class="btn btn-secondary">
                                    Schedule
                                </a>
                                <a href="attendance.php?section_id=<?php echo $section['section_id']; ?>" class="btn btn-secondary">
                                    Attendance
                                </a>
                                <form method="POST" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('Are you sure you want to leave this section? You can be re-assigned later by an admin.');">
                                    <input type="hidden" name="action" value="leave_section">
                                    <input type="hidden" name="section_id" value="<?php echo (int)$section['section_id']; ?>">
                                    <button type="submit" class="btn btn-danger">Leave Section</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📚</div>
                    <h3>No Sections Assigned</h3>
                    <p>You don't have any sections assigned yet. Please contact your administrator.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>