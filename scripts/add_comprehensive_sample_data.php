<?php
/**
 * Comprehensive Sample Data for TVET System
 * Adds complete test dataset including programs, users, courses, sections, enrollments, and assessments
 */

require_once __DIR__ . '/../config.php';

try {
    echo "Starting comprehensive sample data setup...\n\n";

    // Get active school year
    $schoolYear = $pdo->query("SELECT id FROM school_years WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$schoolYear) {
        echo "No active school year found. Please run setup_database.php first.\n";
        exit;
    }
    $schoolYearId = $schoolYear['id'];
    echo "Using active school year ID: $schoolYearId\n\n";

    // 1. Add Programs
    echo "Adding programs...\n";
    $programs = [
        ['name' => 'Diploma in Information Technology', 'code' => 'DIT'],
        ['name' => 'Diploma in Business Administration', 'code' => 'DBA'],
        ['name' => 'Diploma in Electrical Technology', 'code' => 'DET'],
        ['name' => 'Diploma in Software Technology', 'code' => 'DST'],
        ['name' => 'Certificate in Computer Applications', 'code' => 'CCA']
    ];

    $programIds = [];
    foreach ($programs as $program) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO programs (name, code) VALUES (?, ?)");
        $stmt->execute([$program['name'], $program['code']]);
        $programId = $pdo->lastInsertId();
        if ($programId == 0) {
            // Get existing ID if INSERT IGNORE didn't insert
            $stmt = $pdo->prepare("SELECT id FROM programs WHERE code = ?");
            $stmt->execute([$program['code']]);
            $programId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
        }
        $programIds[$program['code']] = $programId;
        echo "Program: {$program['name']} (ID: $programId)\n";
    }
    echo "\n";

    // 2. Add Instructors
    echo "Adding instructors...\n";
    $instructors = [
        ['first_name' => 'Dr. Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@tvet.edu'],
        ['first_name' => 'Prof. Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan.delacruz@tvet.edu'],
        ['first_name' => 'Ms. Ana', 'last_name' => 'Garcia', 'email' => 'ana.garcia@tvet.edu'],
        ['first_name' => 'Mr. Carlos', 'last_name' => 'Reyes', 'email' => 'carlos.reyes@tvet.edu'],
        ['first_name' => 'Dr. Elena', 'last_name' => 'Rodriguez', 'email' => 'elena.rodriguez@tvet.edu']
    ];

    $instructorIds = [];
    foreach ($instructors as $index => $instructor) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, 'instructor')");
        $password = password_hash('instructor123', PASSWORD_BCRYPT);
        $stmt->execute([$instructor['first_name'], $instructor['last_name'], $instructor['email'], $password]);
        $instructorId = $pdo->lastInsertId();
        if ($instructorId == 0) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$instructor['email']]);
            $instructorId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
        }
        $instructorIds[] = $instructorId;
        echo "Instructor: {$instructor['first_name']} {$instructor['last_name']} (ID: $instructorId)\n";
    }
    echo "\n";

    // 3. Add Students
    echo "Adding students...\n";
    $students = [];
    $studentCounter = 1;

    foreach ($programIds as $programCode => $programId) {
        for ($year = 1; $year <= 2; $year++) {
            for ($i = 1; $i <= 15; $i++) { // 15 students per year per program
                $studentId = sprintf('%s%02d%03d', $programCode, $year, $i);
                $firstName = "Student{$studentCounter}";
                $lastName = "Test{$studentCounter}";
                $email = strtolower("{$firstName}.{$lastName}@student.tvet.edu");

                $stmt = $pdo->prepare("INSERT IGNORE INTO users (student_id, first_name, last_name, email, password, role, program_id, year_level) VALUES (?, ?, ?, ?, ?, 'student', ?, ?)");
                $password = password_hash('student123', PASSWORD_BCRYPT);
                $stmt->execute([$studentId, $firstName, $lastName, $email, $password, $programId, $year]);

                $studentUserId = $pdo->lastInsertId();
                if ($studentUserId == 0) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ?");
                    $stmt->execute([$studentId]);
                    $studentUserId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
                }

                $students[] = [
                    'id' => $studentUserId,
                    'student_id' => $studentId,
                    'program_id' => $programId,
                    'year_level' => $year
                ];

                if ($studentCounter % 50 == 0) {
                    echo "Added $studentCounter students...\n";
                }
                $studentCounter++;
            }
        }
    }
    echo "Total students added: " . count($students) . "\n\n";

    // 4. Add Courses
    echo "Adding courses...\n";
    $courses = [
        'DIT' => [
            ['code' => 'DIT101', 'name' => 'Introduction to IT', 'year' => 1, 'semester' => 1],
            ['code' => 'DIT102', 'name' => 'Computer Fundamentals', 'year' => 1, 'semester' => 1],
            ['code' => 'DIT103', 'name' => 'Mathematics for IT', 'year' => 1, 'semester' => 2],
            ['code' => 'DIT104', 'name' => 'Data Structures', 'year' => 1, 'semester' => 2],
            ['code' => 'DIT201', 'name' => 'Programming 1', 'year' => 2, 'semester' => 1],
            ['code' => 'DIT202', 'name' => 'Database Systems', 'year' => 2, 'semester' => 1],
            ['code' => 'DIT203', 'name' => 'Web Technologies', 'year' => 2, 'semester' => 2],
            ['code' => 'DIT204', 'name' => 'Software Engineering', 'year' => 2, 'semester' => 2],
        ],
        'DBA' => [
            ['code' => 'DBA101', 'name' => 'Principles of Management', 'year' => 1, 'semester' => 1],
            ['code' => 'DBA102', 'name' => 'Business Mathematics', 'year' => 1, 'semester' => 1],
            ['code' => 'DBA103', 'name' => 'Financial Accounting', 'year' => 1, 'semester' => 2],
            ['code' => 'DBA104', 'name' => 'Marketing Principles', 'year' => 1, 'semester' => 2],
            ['code' => 'DBA201', 'name' => 'Business Law', 'year' => 2, 'semester' => 1],
            ['code' => 'DBA202', 'name' => 'Human Resource Management', 'year' => 2, 'semester' => 1],
        ],
        'DET' => [
            ['code' => 'DET101', 'name' => 'Basic Electricity', 'year' => 1, 'semester' => 1],
            ['code' => 'DET102', 'name' => 'Electronic Components', 'year' => 1, 'semester' => 1],
            ['code' => 'DET103', 'name' => 'Circuit Analysis', 'year' => 1, 'semester' => 2],
            ['code' => 'DET104', 'name' => 'Power Systems', 'year' => 1, 'semester' => 2],
        ],
        'DST' => [
            ['code' => 'DST101', 'name' => 'Software Development', 'year' => 1, 'semester' => 1],
            ['code' => 'DST102', 'name' => 'System Analysis', 'year' => 1, 'semester' => 1],
            ['code' => 'DST103', 'name' => 'Mobile Development', 'year' => 1, 'semester' => 2],
            ['code' => 'DST104', 'name' => 'Cloud Computing', 'year' => 1, 'semester' => 2],
        ],
        'CCA' => [
            ['code' => 'CCA101', 'name' => 'Computer Basics', 'year' => 1, 'semester' => 1],
            ['code' => 'CCA102', 'name' => 'MS Office Applications', 'year' => 1, 'semester' => 1],
            ['code' => 'CCA103', 'name' => 'Internet Fundamentals', 'year' => 1, 'semester' => 2],
        ]
    ];

    $courseIds = [];
    foreach ($courses as $programCode => $programCourses) {
        $programId = $programIds[$programCode];
        foreach ($programCourses as $course) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO courses (program_id, course_code, course_name, year_level, semester, school_year_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$programId, $course['code'], $course['name'], $course['year'], $course['semester'], $schoolYearId]);
            $courseId = $pdo->lastInsertId();
            if ($courseId == 0) {
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
                $stmt->execute([$course['code']]);
                $courseId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
            }
            $courseIds[] = $courseId;
            echo "Course: {$course['code']} - {$course['name']}\n";
        }
    }
    echo "\n";

    // 5. Create Sections
    echo "Creating sections...\n";
    $sections = [];
    $sectionCounter = 0;
    foreach ($courseIds as $courseId) {
        // Create 2-3 sections per course
        $numSections = rand(2, 3);
        for ($i = 1; $i <= $numSections; $i++) {
            $sectionCode = chr(64 + $i); // A, B, C
            $capacity = rand(25, 35);

            $stmt = $pdo->prepare("INSERT IGNORE INTO sections (course_id, section_code, capacity) VALUES (?, ?, ?)");
            $stmt->execute([$courseId, $sectionCode, $capacity]);
            $sectionId = $pdo->lastInsertId();
            if ($sectionId == 0) {
                $stmt = $pdo->prepare("SELECT id FROM sections WHERE course_id = ? AND section_code = ?");
                $stmt->execute([$courseId, $sectionCode]);
                $sectionId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
            }
            $sections[] = $sectionId;
            $sectionCounter++;
        }
    }
    echo "Total sections created: $sectionCounter\n\n";

    // 6. Assign Instructors to Sections
    echo "Assigning instructors to sections...\n";
    $assignmentCount = 0;
    foreach ($sections as $sectionId) {
        // Assign 1-2 instructors per section
        $numInstructors = rand(1, 2);
        $assignedInstructors = array_rand(array_flip($instructorIds), $numInstructors);

        if (!is_array($assignedInstructors)) {
            $assignedInstructors = [$assignedInstructors];
        }

        foreach ($assignedInstructors as $instructorId) {
            $role = (rand(0, 1) == 0) ? 'primary' : 'secondary';
            $stmt = $pdo->prepare("INSERT IGNORE INTO instructor_sections (instructor_id, section_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$instructorId, $sectionId, $role]);
            $assignmentCount++;
        }
    }
    echo "Total instructor-section assignments: $assignmentCount\n\n";

    // 7. Enroll Students
    echo "Enrolling students in courses...\n";
    $enrollmentCount = 0;
    foreach ($students as $student) {
        // Get courses for student's program and year
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE program_id = ? AND year_level = ? AND school_year_id = ?");
        $stmt->execute([$student['program_id'], $student['year_level'], $schoolYearId]);
        $studentCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($studentCourses as $course) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, course_id, school_year_id) VALUES (?, ?, ?)");
            $stmt->execute([$student['id'], $course['id'], $schoolYearId]);
            $enrollmentCount++;
        }
    }
    echo "Total enrollments created: $enrollmentCount\n\n";

    // 8. Create Assessment Criteria and Items
    echo "Creating assessment criteria and items...\n";
    $assessmentCount = 0;
    foreach ($courseIds as $courseId) {
        $criteria = [
            ['name' => 'Prelim Exam', 'period' => 'prelim', 'percentage' => 20],
            ['name' => 'Midterm Exam', 'period' => 'midterm', 'percentage' => 30],
            ['name' => 'Final Exam', 'period' => 'finals', 'percentage' => 50]
        ];

        foreach ($criteria as $crit) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO assessment_criteria (course_id, name, period, percentage) VALUES (?, ?, ?, ?)");
            $stmt->execute([$courseId, $crit['name'], $crit['period'], $crit['percentage']]);
            $criteriaId = $pdo->lastInsertId();
            if ($criteriaId == 0) {
                $stmt = $pdo->prepare("SELECT id FROM assessment_criteria WHERE course_id = ? AND name = ?");
                $stmt->execute([$courseId, $crit['name']]);
                $criteriaId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
            }

            // Create assessment item
            $stmt = $pdo->prepare("INSERT IGNORE INTO assessment_items (criteria_id, name, total_score) VALUES (?, ?, 100)");
            $stmt->execute([$criteriaId, $crit['name']]);
            $assessmentCount++;
        }
    }
    echo "Total assessment items created: $assessmentCount\n\n";

    // 9. Add Sample Grades (for some students)
    echo "Adding sample grades...\n";
    $gradeCount = 0;
    $sampleStudents = array_slice($students, 0, 50); // Grade first 50 students

    foreach ($sampleStudents as $student) {
        // Get student's enrollments
        $stmt = $pdo->prepare("SELECT id, course_id FROM enrollments WHERE student_id = ?");
        $stmt->execute([$student['id']]);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($enrollments as $enrollment) {
            // Get assessment items for the course
            $stmt = $pdo->prepare("
                SELECT ai.id
                FROM assessment_items ai
                JOIN assessment_criteria ac ON ai.criteria_id = ac.id
                WHERE ac.course_id = ?
            ");
            $stmt->execute([$enrollment['course_id']]);
            $assessmentItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($assessmentItems as $item) {
                // Random grade between 70-100
                $grade = rand(70, 100);
                $status = 'complete';

                $stmt = $pdo->prepare("INSERT IGNORE INTO grades (enrollment_id, assessment_id, grade, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$enrollment['id'], $item['id'], $grade, $status]);
                $gradeCount++;
            }
        }
    }
    echo "Total sample grades added: $gradeCount\n\n";

    echo "Comprehensive sample data setup completed successfully!\n";
    echo "Summary:\n";
    echo "- Programs: " . count($programs) . "\n";
    echo "- Instructors: " . count($instructors) . "\n";
    echo "- Students: " . count($students) . "\n";
    echo "- Courses: " . count($courseIds) . "\n";
    echo "- Sections: $sectionCounter\n";
    echo "- Enrollments: $enrollmentCount\n";
    echo "- Assessment Items: $assessmentCount\n";
    echo "- Sample Grades: $gradeCount\n\n";

    echo "Login credentials:\n";
    echo "Admin: admin@college.edu / admin123\n";
    echo "Instructors: [instructor email] / instructor123\n";
    echo "Students: [student email] / student123\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
