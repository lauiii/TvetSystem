<?php
/**
 * API endpoint to get student profile data
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

header('Content-Type: application/json');

$studentId = (int)($_GET['id'] ?? 0);

if ($studentId <= 0) {
    echo json_encode(['error' => 'Invalid student ID']);
    exit;
}

try {
    // Get student basic info
    $stmt = $pdo->prepare("
        SELECT u.*, p.name as program_name 
        FROM users u 
        LEFT JOIN programs p ON u.program_id = p.id 
        WHERE u.id = ? AND u.role = 'student'
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['error' => 'Student not found']);
        exit;
    }
    
    // Get enrolled courses for active school year
    $stmt = $pdo->query("SELECT id FROM school_years WHERE status = 'active' LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $courses = [];
    if ($activeYear) {
        // Check if section_id column exists in enrollments table
        $colStmt = $pdo->query("SHOW COLUMNS FROM enrollments");
        $enrollCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        $hasSectionId = in_array('section_id', $enrollCols);
        
        if ($hasSectionId) {
            // Use section-based instructor assignment; aggregate multiple instructors
            $sql = "
                SELECT 
                  c.course_code,
                  c.course_name,
                  s.section_code,
                  NULLIF(GROUP_CONCAT(DISTINCT
                    TRIM(
                      COALESCE(
                        NULLIF(CONCAT(COALESCE(u.first_name,''),
                                      CASE WHEN u.first_name IS NOT NULL AND u.first_name<>'' AND u.last_name IS NOT NULL AND u.last_name<>'' THEN ' ' ELSE '' END,
                                      COALESCE(u.last_name,'')), ''),
                        NULLIF(u.email,''),
                        CONCAT('User #', u.id)
                      )
                    )
                  SEPARATOR ', '), '') AS instructor
                FROM enrollments e
                JOIN courses c ON e.course_id = c.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN instructor_sections ins ON ins.section_id = s.id
                LEFT JOIN users u ON u.id = ins.instructor_id
                WHERE e.student_id = ? AND e.school_year_id = ?
                GROUP BY c.course_code, c.course_name, s.section_code
                ORDER BY c.course_code
            ";
            $stmt = $pdo->prepare($sql);
        } else {
            // No section_id in enrollments: fallback to course's sections -> instructors (course-level aggregate)
            $sql = "
                SELECT 
                  c.course_code,
                  c.course_name,
                  '' as section_code,
                  NULLIF(GROUP_CONCAT(DISTINCT
                    TRIM(
                      COALESCE(
                        NULLIF(CONCAT(COALESCE(u.first_name,''),
                                      CASE WHEN u.first_name IS NOT NULL AND u.first_name<>'' AND u.last_name IS NOT NULL AND u.last_name<>'' THEN ' ' ELSE '' END,
                                      COALESCE(u.last_name,'')), ''),
                        NULLIF(u.name,''),
                        u.email,
                        CONCAT('User #', u.id)
                      )
                    )
                  SEPARATOR ', '), '') AS instructor
                FROM enrollments e
                JOIN courses c ON e.course_id = c.id
                LEFT JOIN sections s ON s.course_id = c.id
                LEFT JOIN instructor_sections ins ON ins.section_id = s.id
                LEFT JOIN users u ON u.id = ins.instructor_id
                WHERE e.student_id = ? AND e.school_year_id = ?
                GROUP BY c.course_code, c.course_name
                ORDER BY c.course_code
            ";
            $stmt = $pdo->prepare($sql);
        }
        $stmt->execute([$studentId, $activeYear['id']]);
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get recent grades - check if student_id exists in grades table
    $grades = [];
    try {
        $gradeColStmt = $pdo->query("SHOW COLUMNS FROM grades");
        $gradeCols = $gradeColStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('student_id', $gradeCols)) {
            $stmt = $pdo->prepare("
                SELECT g.grade, g.remarks, c.course_code, c.course_name, sy.year as school_year
                FROM grades g
                JOIN courses c ON g.course_id = c.id
                LEFT JOIN school_years sy ON g.school_year_id = sy.id
                WHERE g.student_id = ?
                ORDER BY g.id DESC
                LIMIT 10
            ");
            $stmt->execute([$studentId]);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (in_array('user_id', $gradeCols)) {
            $stmt = $pdo->prepare("
                SELECT g.grade, g.remarks, c.course_code, c.course_name, sy.year as school_year
                FROM grades g
                JOIN courses c ON g.course_id = c.id
                LEFT JOIN school_years sy ON g.school_year_id = sy.id
                WHERE g.user_id = ?
                ORDER BY g.id DESC
                LIMIT 10
            ");
            $stmt->execute([$studentId]);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // If grades table doesn't exist or has issues, just return empty array
        $grades = [];
    }
    
    // Prepare response
    $response = [
        'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
        'student_id' => $student['student_id'] ?? 'N/A',
        'email' => $student['email'] ?? 'N/A',
        'program' => $student['program_name'] ?? 'N/A',
        'year_level' => $student['year_level'] ?? 'N/A',
        'status' => ucfirst($student['status'] ?? 'active'),
        'created_at' => !empty($student['created_at']) ? date('M d, Y', strtotime($student['created_at'])) : 'N/A',
        'courses' => $courses,
        'grades' => $grades
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Failed to load profile: ' . $e->getMessage()]);
}
