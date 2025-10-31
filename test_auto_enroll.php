<?php
require_once 'config.php';
require_once 'include/functions.php';

echo "Testing Auto Enrollment Feature\n";
echo "===============================\n\n";

// Get programs
$programs = $pdo->query('SELECT id, name, code FROM programs')->fetchAll(PDO::FETCH_ASSOC);
echo "Available Programs:\n";
foreach ($programs as $p) {
    echo "  {$p['id']}: {$p['code']} - {$p['name']}\n";
}

// Get active school year
$activeYear = $pdo->query("SELECT id, year, semester FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$activeYear) {
    echo "\nNo active school year found. Please set one in admin/school-years.php\n";
    exit;
}
echo "\nActive School Year: {$activeYear['year']} {$activeYear['semester']} (ID: {$activeYear['id']})\n";

// Get courses for first program, year 1
$programId = $programs[0]['id'] ?? null;
if (!$programId) {
    echo "\nNo programs found.\n";
    exit;
}

$courses = $pdo->query("SELECT id, course_code, course_name FROM courses WHERE program_id = $programId AND year_level = 1")->fetchAll(PDO::FETCH_ASSOC);
echo "\nCourses for Program {$programs[0]['code']} Year 1:\n";
foreach ($courses as $c) {
    echo "  {$c['id']}: {$c['course_code']} - {$c['course_name']}\n";
}

// Test create_user with auto-enrollment
echo "\nTesting Student Creation with Auto-Enrollment:\n";
$result = create_user($pdo, 'Test', 'Student', 'test@example.com', $programId, 1, 'student');

if ($result['success']) {
    echo "✓ User created successfully\n";
    echo "  Student ID: {$result['student_id']}\n";
    echo "  Enrolled in: {$result['enrolled']} courses\n";

    // Verify enrollments
    $enrollments = $pdo->query("SELECT e.*, c.course_code, c.course_name FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE e.student_id = {$result['id']}")->fetchAll(PDO::FETCH_ASSOC);
    echo "  Enrollment Details:\n";
    foreach ($enrollments as $e) {
        echo "    - {$e['course_code']} - {$e['course_name']} (Status: {$e['status']})\n";
    }

    // Clean up test data
    $pdo->exec("DELETE FROM enrollments WHERE student_id = {$result['id']}");
    $pdo->exec("DELETE FROM users WHERE id = {$result['id']}");
    echo "\n✓ Test data cleaned up\n";

} else {
    echo "✗ User creation failed: {$result['error']}\n";
}

echo "\nTest completed.\n";
?>
