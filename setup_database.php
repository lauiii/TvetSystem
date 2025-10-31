<?php
/**
 * College Grading System - Database Setup
 * Creates all necessary tables for the system
 * Run this file once to initialize the database
 */

// Database configuration
$host = 'localhost';
$dbname = 'college_grading_system';
$username = 'root';
$password = '';

try {
    // Create connection
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE $dbname");
    
    // Users table - stores all system users (admin, instructor, student)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(50) UNIQUE,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'instructor', 'student') NOT NULL,
        program_id INT NULL,
        year_level INT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(email),
        INDEX(student_id),
        INDEX(role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Programs table - stores academic programs
    $pdo->exec("CREATE TABLE IF NOT EXISTS programs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        code VARCHAR(20) UNIQUE NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Courses table - stores course information with program and year level
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_id INT NOT NULL,
        course_code VARCHAR(50) NOT NULL,
        course_name VARCHAR(200) NOT NULL,
        year_level INT NOT NULL,
        semester INT NOT NULL,
        instructor_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
        FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX(program_id, year_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Enrollments table - tracks student course enrollments
    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        course_id INT NOT NULL,
        school_year_id INT NOT NULL,
        status ENUM('enrolled', 'dropped', 'completed') DEFAULT 'enrolled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        UNIQUE KEY unique_enrollment (student_id, course_id, school_year_id),
        INDEX(student_id),
        INDEX(course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Assessments table - stores assessment types for courses
    $pdo->exec("CREATE TABLE IF NOT EXISTS assessments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        type ENUM('exam', 'quiz', 'assignment', 'project', 'other') NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        INDEX(course_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Grades table - stores student grades for assessments
    $pdo->exec("CREATE TABLE IF NOT EXISTS grades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT NOT NULL,
        assessment_id INT NOT NULL,
        grade DECIMAL(5,2),
        status ENUM('complete', 'incomplete', 'missing') DEFAULT 'incomplete',
        remarks TEXT,
        submitted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
        FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_grade (enrollment_id, assessment_id),
        INDEX(enrollment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // School years table - manages academic years
    $pdo->exec("CREATE TABLE IF NOT EXISTS school_years (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year VARCHAR(20) NOT NULL,
        semester INT NOT NULL,
        status ENUM('active', 'inactive') DEFAULT 'inactive',
        start_date DATE,
        end_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_year_sem (year, semester)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Notifications table - stores system notifications
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info', 'warning', 'alert', 'success') DEFAULT 'info',
        status ENUM('unread', 'read') DEFAULT 'unread',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX(user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Flags table - tracks issues for students
    $pdo->exec("CREATE TABLE IF NOT EXISTS flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        course_id INT NOT NULL,
        issue VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('pending', 'resolved') DEFAULT 'pending',
        flagged_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (flagged_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX(student_id),
        INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Insert default admin account (password: admin123)
    $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->exec("INSERT IGNORE INTO users (student_id, first_name, last_name, email, password, role) 
                VALUES ('ADMIN001', 'System', 'Administrator', 'admin@college.edu', '$adminPassword', 'admin')");
    
    // Insert default active school year
    $pdo->exec("INSERT IGNORE INTO school_years (year, semester, status, start_date, end_date) 
                VALUES ('2024-2025', 1, 'active', '2024-08-01', '2024-12-31')");
    
    echo "Database setup completed successfully!\n";
    echo "Default admin credentials:\n";
    echo "Email: admin@college.edu\n";
    echo "Password: admin123\n";
    echo "\nPlease change the admin password after first login.\n";
    
} catch(PDOException $e) {
    die("Database setup failed: " . $e->getMessage() . "\n");
}
?>