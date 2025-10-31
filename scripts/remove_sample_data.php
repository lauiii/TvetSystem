<?php
/**
 * Remove All Sample Data from TVET System
 * Clears all test data while preserving essential setup (admin user, school year)
 */

require_once __DIR__ . '/../config.php';

try {
    echo "Starting sample data removal...\n\n";

    // Delete in reverse order of dependencies to respect foreign keys

    // 1. Delete grades
    $stmt = $pdo->prepare("DELETE FROM grades");
    $stmt->execute();
    $gradesDeleted = $stmt->rowCount();
    echo "Deleted $gradesDeleted grades\n";

    // 2. Delete enrollments
    $stmt = $pdo->prepare("DELETE FROM enrollments");
    $stmt->execute();
    $enrollmentsDeleted = $stmt->rowCount();
    echo "Deleted $enrollmentsDeleted enrollments\n";

    // 3. Delete instructor_sections
    $stmt = $pdo->prepare("DELETE FROM instructor_sections");
    $stmt->execute();
    $assignmentsDeleted = $stmt->rowCount();
    echo "Deleted $assignmentsDeleted instructor-section assignments\n";

    // 4. Delete sections
    $stmt = $pdo->prepare("DELETE FROM sections");
    $stmt->execute();
    $sectionsDeleted = $stmt->rowCount();
    echo "Deleted $sectionsDeleted sections\n";

    // 5. Delete assessment_items
    $stmt = $pdo->prepare("DELETE FROM assessment_items");
    $stmt->execute();
    $itemsDeleted = $stmt->rowCount();
    echo "Deleted $itemsDeleted assessment items\n";

    // 6. Delete assessment_criteria
    $stmt = $pdo->prepare("DELETE FROM assessment_criteria");
    $stmt->execute();
    $criteriaDeleted = $stmt->rowCount();
    echo "Deleted $criteriaDeleted assessment criteria\n";

    // 7. Delete courses
    $stmt = $pdo->prepare("DELETE FROM courses");
    $stmt->execute();
    $coursesDeleted = $stmt->rowCount();
    echo "Deleted $coursesDeleted courses\n";

    // 8. Delete users (except admin)
    $stmt = $pdo->prepare("DELETE FROM users WHERE role != 'admin'");
    $stmt->execute();
    $usersDeleted = $stmt->rowCount();
    echo "Deleted $usersDeleted users (preserved admin)\n";

    // 9. Delete programs
    $stmt = $pdo->prepare("DELETE FROM programs");
    $stmt->execute();
    $programsDeleted = $stmt->rowCount();
    echo "Deleted $programsDeleted programs\n";

    // 10. Delete flags
    $stmt = $pdo->prepare("DELETE FROM flags");
    $stmt->execute();
    $flagsDeleted = $stmt->rowCount();
    echo "Deleted $flagsDeleted flags\n";

    // 11. Delete notifications
    $stmt = $pdo->prepare("DELETE FROM notifications");
    $stmt->execute();
    $notificationsDeleted = $stmt->rowCount();
    echo "Deleted $notificationsDeleted notifications\n";

    // 12. Delete rooms
    $stmt = $pdo->prepare("DELETE FROM rooms");
    $stmt->execute();
    $roomsDeleted = $stmt->rowCount();
    echo "Deleted $roomsDeleted rooms\n";

    echo "\nSample data removal completed successfully!\n";
    echo "Summary of deletions:\n";
    echo "- Grades: $gradesDeleted\n";
    echo "- Enrollments: $enrollmentsDeleted\n";
    echo "- Instructor-Section Assignments: $assignmentsDeleted\n";
    echo "- Sections: $sectionsDeleted\n";
    echo "- Assessment Items: $itemsDeleted\n";
    echo "- Assessment Criteria: $criteriaDeleted\n";
    echo "- Courses: $coursesDeleted\n";
    echo "- Users: $usersDeleted\n";
    echo "- Programs: $programsDeleted\n";
    echo "- Flags: $flagsDeleted\n";
    echo "- Notifications: $notificationsDeleted\n";
    echo "- Rooms: $roomsDeleted\n\n";

    echo "Preserved data:\n";
    echo "- Admin user (admin@college.edu)\n";
    echo "- Active school year\n";
    echo "- Database schema and tables\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
