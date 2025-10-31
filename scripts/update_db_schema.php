<?php
/**
 * Update Database Schema to match sql/schema.sql
 * Adds missing columns to existing tables
 */

require_once __DIR__ . '/../config.php';

try {
    echo "Updating database schema...\n";

    // Add missing columns to users table
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) DEFAULT NULL AFTER student_id");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) DEFAULT NULL AFTER first_name");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");

    // Add missing columns to grades table
    $pdo->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS status ENUM('complete','incomplete','missing') DEFAULT 'incomplete' AFTER grade");
    $pdo->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS remarks TEXT AFTER status");
    $pdo->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL AFTER remarks");
    $pdo->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER submitted_at");

    // Add missing columns to flags table
    $pdo->exec("ALTER TABLE flags ADD COLUMN IF NOT EXISTS description TEXT AFTER issue");
    $pdo->exec("ALTER TABLE flags ADD COLUMN IF NOT EXISTS status ENUM('pending','resolved') DEFAULT 'pending' AFTER description");
    $pdo->exec("ALTER TABLE flags ADD COLUMN IF NOT EXISTS flagged_by INT NOT NULL DEFAULT 1 AFTER status");
    $pdo->exec("ALTER TABLE flags ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER flagged_by");

    // Update school_years table: remove semester, start_date, end_date; ensure year is unique
    try {
        $pdo->exec("ALTER TABLE school_years DROP COLUMN IF EXISTS semester");
        $pdo->exec("ALTER TABLE school_years DROP COLUMN IF EXISTS start_date");
        $pdo->exec("ALTER TABLE school_years DROP COLUMN IF EXISTS end_date");
        $pdo->exec("ALTER TABLE school_years ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");
        $pdo->exec("ALTER TABLE school_years ADD UNIQUE KEY IF NOT EXISTS unique_year (year)");
    } catch(Exception $e) {
        // Ignore if columns don't exist or unique key already exists
    }

    // Add missing columns to enrollments table
    $pdo->exec("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS school_year_id INT DEFAULT 1 AFTER course_id");
    $pdo->exec("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");

    // Add missing columns to courses table
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS school_year_id INT NOT NULL DEFAULT 1 AFTER semester");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS instructor_id INT NULL AFTER school_year_id");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER instructor_id");

    // Add foreign key for school_year_id in courses
    try { $pdo->exec("ALTER TABLE courses ADD CONSTRAINT fk_courses_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE"); } catch(Exception $e) {}

    // Create new tables for sections, rooms, and instructor assignments
    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_code VARCHAR(50) NOT NULL UNIQUE,
        room_name VARCHAR(100) NOT NULL,
        capacity INT NOT NULL DEFAULT 30,
        building VARCHAR(100),
        floor INT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        section_code VARCHAR(20) NOT NULL,
        section_name VARCHAR(100),
        room_id INT NULL,
        capacity INT NOT NULL DEFAULT 30,
        enrolled_count INT DEFAULT 0,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
        UNIQUE KEY unique_section_course (course_id, section_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS instructor_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        instructor_id INT NOT NULL,
        section_id INT NOT NULL,
        role ENUM('primary','secondary') DEFAULT 'secondary',
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
        UNIQUE KEY unique_instructor_section (instructor_id, section_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add enrolled_count column to sections if not exists
    $pdo->exec("ALTER TABLE sections ADD COLUMN IF NOT EXISTS enrolled_count INT DEFAULT 0 AFTER capacity");

    // Insert sample rooms
    $pdo->exec("INSERT IGNORE INTO rooms (id, room_code, room_name, capacity, building, floor) VALUES
        (1, 'LAB101', 'Computer Lab 101', 30, 'Main Building', 1),
        (2, 'LAB102', 'Computer Lab 102', 25, 'Main Building', 1),
        (3, 'ROOM201', 'Classroom 201', 40, 'Main Building', 2),
        (4, 'ROOM202', 'Classroom 202', 35, 'Main Building', 2)");

    // Create new assessment tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS assessment_criteria (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        period ENUM('prelim','midterm','finals') NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        effective_percentage DECIMAL(6,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS assessment_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        criteria_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        total_score DECIMAL(5,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (criteria_id) REFERENCES assessment_criteria(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add total_score column if not exists (for existing tables)
    $pdo->exec("ALTER TABLE assessment_items ADD COLUMN IF NOT EXISTS total_score DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER name");

    // Add effective_percentage column to assessment_criteria if missing
    try { $pdo->exec("ALTER TABLE assessment_criteria ADD COLUMN IF NOT EXISTS effective_percentage DECIMAL(6,2) NULL AFTER percentage"); } catch(Exception $e) {}

    // Backfill effective_percentage for existing rows based on period weights
    try {
        // Create a temp mapping table for weights
        $weights = [ 'prelim' => 30, 'midterm' => 30, 'finals' => 40 ];
        foreach ($weights as $key => $val) {
            $stmt = $pdo->prepare("UPDATE assessment_criteria SET effective_percentage = ROUND((percentage/100.0) * ?, 2) WHERE period = ?");
            $stmt->execute([$val, $key]);
        }
    } catch(Exception $e) {}

    // Update grades table foreign key to reference assessment_items
    try {
        $pdo->exec("ALTER TABLE grades DROP FOREIGN KEY fk_grades_assessment");
    } catch(Exception $e) {
        // Ignore if foreign key doesn't exist
    }
    try {
        $pdo->exec("ALTER TABLE grades ADD CONSTRAINT fk_grades_assessment_item FOREIGN KEY (assessment_id) REFERENCES assessment_items(id) ON DELETE CASCADE");
    } catch(Exception $e) {
        // Ignore if foreign key already exists
    }

    // Normalize and de-duplicate assessment criteria and items, then enforce uniqueness with generated columns
    echo "\nNormalizing and enforcing uniqueness on assessment criteria/items...\n";

    // 1) Remove exact duplicates by keeping the lowest id per (course_id, period, name_norm)
    try {
        // Create generated column name_norm if not exists
        try { $pdo->exec("ALTER TABLE assessment_criteria ADD COLUMN name_norm VARCHAR(100) GENERATED ALWAYS AS (LOWER(TRIM(name))) STORED"); } catch(Exception $e) {}
        // Add unique key if not exists
        try { $pdo->exec("ALTER TABLE assessment_criteria ADD UNIQUE KEY unique_criteria (course_id, period, name_norm)"); } catch(Exception $e) {}

        // Delete duplicates (keep smallest id)
        $pdo->exec("DELETE ac1 FROM assessment_criteria ac1 INNER JOIN assessment_criteria ac2 ON ac1.course_id = ac2.course_id AND ac1.period = ac2.period AND LOWER(TRIM(ac1.name)) = LOWER(TRIM(ac2.name)) AND ac1.id > ac2.id");
    } catch(Exception $e) {
        echo "Criteria normalization note: " . $e->getMessage() . "\n";
    }

    // 2) Items: generated column + unique key and cleanup
    try {
        try { $pdo->exec("ALTER TABLE assessment_items ADD COLUMN name_norm VARCHAR(100) GENERATED ALWAYS AS (LOWER(TRIM(name))) STORED"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE assessment_items ADD UNIQUE KEY unique_item (criteria_id, name_norm)"); } catch(Exception $e) {}
        $pdo->exec("DELETE ai1 FROM assessment_items ai1 INNER JOIN assessment_items ai2 ON ai1.criteria_id = ai2.criteria_id AND LOWER(TRIM(ai1.name)) = LOWER(TRIM(ai2.name)) AND ai1.id > ai2.id");
    } catch(Exception $e) {
        echo "Items normalization note: " . $e->getMessage() . "\n";
    }

    // Add missing columns to programs table
    $pdo->exec("ALTER TABLE programs ADD COLUMN IF NOT EXISTS code VARCHAR(20) UNIQUE AFTER name");
    $pdo->exec("ALTER TABLE programs ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER description");

    // Add missing columns to notifications table
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS type ENUM('info','warning','alert','success') DEFAULT 'info' AFTER message");
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");

    // Add foreign keys (ignore if they already exist)
    try { $pdo->exec("ALTER TABLE flags ADD CONSTRAINT fk_flags_flagged_by FOREIGN KEY (flagged_by) REFERENCES users(id) ON DELETE CASCADE"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE enrollments ADD CONSTRAINT fk_enrollments_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE courses ADD CONSTRAINT fk_courses_instructor FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL"); } catch(Exception $e) {}

    // Update existing data to populate first_name and last_name from name (only if name column exists)
    try {
        $pdo->exec("UPDATE users SET first_name = SUBSTRING_INDEX(name, ' ', 1), last_name = SUBSTRING(name, LOCATE(' ', name) + 1) WHERE first_name IS NULL AND last_name IS NULL AND name IS NOT NULL AND name != ''");
    } catch(Exception $e) {
        // Ignore if name column doesn't exist
    }

    echo "Database schema updated successfully!\n";

} catch(PDOException $e) {
    die("Database update failed: " . $e->getMessage() . "\n");
}
?>
