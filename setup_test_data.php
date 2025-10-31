<?php
require_once 'config.php';

try {
    // Get active school year
    $schoolYear = $pdo->query("SELECT id FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$schoolYear) {
        echo "No active school year found.\n";
        exit;
    }
    $schoolYearId = $schoolYear['id'];

    // Get a course
    $course = $pdo->query("SELECT id, course_code FROM courses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        echo "No courses found.\n";
        exit;
    }
    $courseId = $course['id'];

    // Create a section
    $stmt = $pdo->prepare("INSERT INTO sections (course_id, section_code, capacity) VALUES (?, 'A', 30)");
    $stmt->execute([$courseId]);
    $sectionId = $pdo->lastInsertId();
    echo "Created section ID: $sectionId for course {$course['course_code']}\n";

    // Get instructor
    $instructor = $pdo->query("SELECT id FROM users WHERE role = 'instructor' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$instructor) {
        echo "No instructor found.\n";
        exit;
    }
    $instructorId = $instructor['id'];

    // Assign instructor to section
    $stmt = $pdo->prepare("INSERT INTO instructor_sections (instructor_id, section_id) VALUES (?, ?)");
    $stmt->execute([$instructorId, $sectionId]);
    echo "Assigned instructor to section.\n";

    // Create some students
    $students = [];
    for ($i = 1; $i <= 3; $i++) {
        $stmt = $pdo->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?, 'student')");
        $studentId = 'STU' . str_pad($i, 3, '0', STR_PAD_LEFT);
        $email = "student$i@test.com";
        $password = password_hash('test123', PASSWORD_BCRYPT);
        $stmt->execute([$studentId, "Student$i", "Test", $email, $password]);
        $studentUserId = $pdo->lastInsertId();
        $students[] = $studentUserId;
        echo "Created student: $studentId\n";
    }

    // Enroll students in course
    foreach ($students as $studentId) {
        $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id) VALUES (?, ?, ?)");
        $stmt->execute([$studentId, $courseId, $schoolYearId]);
        echo "Enrolled student $studentId in course.\n";
    }

    // Create assessment criteria
    $criteria = [
        ['name' => 'Prelim Exam', 'period' => 'prelim', 'percentage' => 20],
        ['name' => 'Midterm Exam', 'period' => 'midterm', 'percentage' => 30],
        ['name' => 'Final Exam', 'period' => 'finals', 'percentage' => 50]
    ];

    $criteriaIds = [];
    foreach ($criteria as $crit) {
        $stmt = $pdo->prepare("INSERT INTO assessment_criteria (course_id, name, period, percentage) VALUES (?, ?, ?, ?)");
        $stmt->execute([$courseId, $crit['name'], $crit['period'], $crit['percentage']]);
        $criteriaIds[] = $pdo->lastInsertId();
    }

    // Create assessment items
    foreach ($criteriaIds as $criteriaId) {
        $stmt = $pdo->prepare("INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?, 'Exam', 100)");
        $stmt->execute([$criteriaId]);
    }

    echo "Test data setup complete. Section ID: $sectionId\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
