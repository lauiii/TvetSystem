<?php
/**
 * Add Sample Courses for Testing Auto-Enrollment
 */

require_once __DIR__ . '/../config.php';

try {
    echo "Adding sample courses for testing...\n";

    // Get programs
    $programs = $pdo->query('SELECT id, name, code FROM programs')->fetchAll(PDO::FETCH_ASSOC);

    if (empty($programs)) {
        echo "No programs found. Please add programs first.\n";
        exit;
    }

    // Sample courses for each program
    $sampleCourses = [
        1 => [ // Program 1
            ['code' => 'DIT101', 'name' => 'Introduction to IT', 'year' => 1, 'semester' => 1],
            ['code' => 'DIT102', 'name' => 'Computer Fundamentals', 'year' => 1, 'semester' => 1],
            ['code' => 'DIT103', 'name' => 'Mathematics for IT', 'year' => 1, 'semester' => 2],
            ['code' => 'DIT104', 'name' => 'Data Structures', 'year' => 1, 'semester' => 2],
            ['code' => 'DIT201', 'name' => 'Programming 1', 'year' => 2, 'semester' => 1],
            ['code' => 'DIT202', 'name' => 'Database Systems', 'year' => 2, 'semester' => 1],
            ['code' => 'DIT203', 'name' => 'Web Technologies', 'year' => 2, 'semester' => 2],
            ['code' => 'DIT204', 'name' => 'Software Engineering', 'year' => 2, 'semester' => 2],
        ],
        4 => [ // Program 4
            ['code' => 'DIST101', 'name' => 'Software Engineering', 'year' => 1, 'semester' => 1],
            ['code' => 'DIST102', 'name' => 'Web Development', 'year' => 1, 'semester' => 1],
            ['code' => 'DIST103', 'name' => 'Database Management', 'year' => 1, 'semester' => 2],
            ['code' => 'DIST104', 'name' => 'System Analysis', 'year' => 1, 'semester' => 2],
            ['code' => 'DIST201', 'name' => 'Mobile Development', 'year' => 2, 'semester' => 1],
            ['code' => 'DIST202', 'name' => 'Cloud Computing', 'year' => 2, 'semester' => 1],
            ['code' => 'DIST203', 'name' => 'Project Management', 'year' => 2, 'semester' => 2],
            ['code' => 'DIST204', 'name' => 'Advanced Programming', 'year' => 2, 'semester' => 2],
        ]
    ];

    foreach ($programs as $program) {
        $programId = $program['id'];
        if (isset($sampleCourses[$programId])) {
            foreach ($sampleCourses[$programId] as $course) {
                // Check if course already exists
                $stmt = $pdo->prepare('SELECT id FROM courses WHERE course_code = ?');
                $stmt->execute([$course['code']]);
                if (!$stmt->fetch()) {
                    // Insert course
                    $insertStmt = $pdo->prepare('INSERT INTO courses (program_id, course_code, course_name, year_level, semester) VALUES (?, ?, ?, ?, ?)');
                    $insertStmt->execute([$programId, $course['code'], $course['name'], $course['year'], $course['semester']]);
                    echo "Added course: {$course['code']} - {$course['name']} (Program: {$program['name']})\n";
                } else {
                    echo "Course already exists: {$course['code']}\n";
                }
            }
        }
    }

    echo "\nSample courses added successfully!\n";

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
?>
