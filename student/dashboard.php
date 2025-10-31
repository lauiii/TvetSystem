<?php
/**
 * Student Dashboard
 * Shows enrolled courses and grade status
 */

require_once '../config.php';
requireRole('student');

$studentId = $_SESSION['user_id'];

// Fetch student information
$stmt = $pdo->prepare("
    SELECT u.*, p.name as program_name, p.code as program_code
    FROM users u
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE u.id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// Fetch enrolled courses with grade status
$stmt = $pdo->prepare("
    SELECT
        c.course_code,
        c.course_name,
        c.semester,
        e.status as enrollment_status,
        COUNT(DISTINCT g.id) as graded_assessments,
        COUNT(DISTINCT ai.id) as total_assessments,
        SUM(CASE WHEN g.status = 'missing' THEN 1 ELSE 0 END) as missing_count,
        AVG(CASE WHEN g.grade IS NOT NULL THEN g.grade ELSE NULL END) as average_grade
    FROM enrollments e
    INNER JOIN courses c ON e.course_id = c.id
    LEFT JOIN assessment_criteria ac ON c.id = ac.course_id
    LEFT JOIN assessment_items ai ON ac.id = ai.criteria_id
    LEFT JOIN grades g ON e.id = g.enrollment_id AND ai.id = g.assessment_id
    WHERE e.student_id = ? AND e.status = 'enrolled'
    GROUP BY c.id, e.id
    ORDER BY c.semester, c.course_name
");
$stmt->execute([$studentId]);
$enrolledCourses = $stmt->fetchAll();

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
                <h1>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h1>
                <p>Student Dashboard</p>
            </div>
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
            <h2>📚 Enrolled Courses</h2>
            
            <?php if (count($enrolledCourses) > 0): ?>
                <div class="course-grid">
                    <?php foreach ($enrolledCourses as $course): ?>
                        <?php 
                            $status = getGradeStatus(
                                $course['graded_assessments'], 
                                $course['total_assessments'], 
                                $course['missing_count']
                            );
                        ?>
                        <div class="course-card">
                            <div class="course-header">
                                <div class="course-code"><?php echo htmlspecialchars($course['course_code']); ?></div>
                                <div class="course-name"><?php echo htmlspecialchars($course['course_name']); ?></div>
                            </div>
                            
                            <div class="course-details">
                                Semester <?php echo $course['semester']; ?>
                            </div>
                            
                            <div class="grade-status <?php echo $status['class']; ?>">
                                <strong><?php echo $status['status']; ?></strong>
                            </div>
                            
                            <div class="progress-info">
                                Graded: <?php echo $course['graded_assessments']; ?>/<?php echo $course['total_assessments']; ?> assessments
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: #666; text-align: center; padding: 40px;">
                    No courses enrolled yet. Please contact your administrator.
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>