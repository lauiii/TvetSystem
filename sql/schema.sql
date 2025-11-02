-- College Grading System SQL Schema
-- Run this file to create required tables (or import into phpMyAdmin)
-- Uses utf8mb4 charset

CREATE DATABASE IF NOT EXISTS `college_grading_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `college_grading_system`;

-- users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` VARCHAR(50) UNIQUE,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','instructor','student') NOT NULL,
  `program_id` INT NULL,
  `year_level` INT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(email),
  INDEX(student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- programs
CREATE TABLE IF NOT EXISTS `programs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20) UNIQUE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- school_years
CREATE TABLE IF NOT EXISTS `school_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` VARCHAR(20) NOT NULL UNIQUE,
  `status` ENUM('active','inactive') DEFAULT 'inactive',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- courses
CREATE TABLE IF NOT EXISTS `courses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `program_id` INT NOT NULL,
  `course_code` VARCHAR(50) NOT NULL,
  `course_name` VARCHAR(200) NOT NULL,
  `year_level` INT NOT NULL,
  `semester` INT NOT NULL,
  `school_year_id` INT NOT NULL,
  `instructor_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`program_id`) REFERENCES programs(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`school_year_id`) REFERENCES school_years(`id`) ON DELETE CASCADE,
  INDEX(program_id, year_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- rooms
CREATE TABLE IF NOT EXISTS `rooms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_code` VARCHAR(50) NOT NULL UNIQUE,
  `room_name` VARCHAR(100) NOT NULL,
  `capacity` INT NOT NULL DEFAULT 30,
  `building` VARCHAR(100),
  `floor` INT,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sections
CREATE TABLE IF NOT EXISTS `sections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `section_code` VARCHAR(20) NOT NULL,
  `section_name` VARCHAR(100),
  `room_id` INT NULL,
  `capacity` INT NOT NULL DEFAULT 30,
  `enrolled_count` INT DEFAULT 0,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES courses(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id`) REFERENCES rooms(`id`) ON DELETE SET NULL,
  UNIQUE KEY unique_section_course (`course_id`, `section_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- instructor_sections (many-to-many relationship)
CREATE TABLE IF NOT EXISTS `instructor_sections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `instructor_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `role` ENUM('primary','secondary') DEFAULT 'secondary',
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`instructor_id`) REFERENCES users(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`section_id`) REFERENCES sections(`id`) ON DELETE CASCADE,
  UNIQUE KEY unique_instructor_section (`instructor_id`, `section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- enrollments
CREATE TABLE IF NOT EXISTS `enrollments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `course_id` INT NOT NULL,
  `school_year_id` INT NOT NULL,
  `status` ENUM('enrolled','dropped','completed') DEFAULT 'enrolled',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES users(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES courses(`id`) ON DELETE CASCADE,
  UNIQUE KEY unique_enrollment (`student_id`,`course_id`,`school_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- assessment_criteria
CREATE TABLE IF NOT EXISTS `assessment_criteria` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `period` ENUM('prelim','midterm','finals') NOT NULL,
  `percentage` DECIMAL(5,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES courses(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- assessment_items
CREATE TABLE IF NOT EXISTS `assessment_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `criteria_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `total_score` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`criteria_id`) REFERENCES assessment_criteria(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- grades
CREATE TABLE IF NOT EXISTS `grades` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `enrollment_id` INT NOT NULL,
  `assessment_id` INT NOT NULL,
  `grade` DECIMAL(5,2),
  `status` ENUM('complete','incomplete','missing') DEFAULT 'incomplete',
  `remarks` TEXT,
  `submitted_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`enrollment_id`) REFERENCES enrollments(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assessment_id`) REFERENCES assessment_items(`id`) ON DELETE CASCADE,
  UNIQUE KEY unique_grade (`enrollment_id`,`assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- school_years
CREATE TABLE IF NOT EXISTS `school_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `year` VARCHAR(20) NOT NULL UNIQUE,
  `status` ENUM('active','inactive') DEFAULT 'inactive',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','warning','alert','success') DEFAULT 'info',
  `status` ENUM('unread','read') DEFAULT 'unread',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES users(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- flags
CREATE TABLE IF NOT EXISTS `flags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `course_id` INT NOT NULL,
  `issue` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `status` ENUM('pending','resolved') DEFAULT 'pending',
  `flagged_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES users(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`course_id`) REFERENCES courses(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- attendance_sessions
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT NOT NULL,
  `session_date` DATE NOT NULL,
  `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('open','closed') DEFAULT 'open',
  `notes` TEXT NULL,
  UNIQUE KEY `unique_section_date` (`section_id`,`session_date`),
  KEY `idx_section` (`section_id`),
  CONSTRAINT `fk_attendance_section` FOREIGN KEY (`section_id`) REFERENCES `sections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- attendance_records
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` INT NOT NULL,
  `enrollment_id` INT NOT NULL,
  `status` ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `notes` VARCHAR(255) NULL,
  `marked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_session_enrollment` (`session_id`,`enrollment_id`),
  KEY `idx_session` (`session_id`),
  KEY `idx_enrollment` (`enrollment_id`),
  CONSTRAINT `fk_attendance_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- email_outbox (queued emails)
CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `to_email` VARCHAR(255) NOT NULL,
  `to_name` VARCHAR(255) NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `body_text` TEXT NULL,
  `attachment_name` VARCHAR(255) NULL,
  `attachment_mime` VARCHAR(100) NULL,
  `attachment_content` LONGBLOB NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` INT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_status_scheduled` (`status`, `scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- import_jobs (background CSV imports)
CREATE TABLE IF NOT EXISTS `import_jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `job_type` VARCHAR(50) NOT NULL DEFAULT 'student_import',
  `file_path` VARCHAR(500) NOT NULL,
  `status` ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
  `total_rows` INT NULL,
  `processed_rows` INT NOT NULL DEFAULT 0,
  `last_line` INT NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

