<?php
/**
 * Shared helper functions for the College Grading System
 * - Uses the $pdo connection provided by root `config.php` (which creates/uses the DB)
 * - All DB operations use prepared statements
 */

// Ensure session started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Simple sanitize helper for input
 */
function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Require a user role to access a page. If not authorized, redirect to login.
 * Accepts a single role string (e.g. 'admin') or an array of allowed roles.
 */
function requireRole($roles) {
    if (is_string($roles)) {
        $roles = [$roles];
    }

    if (!isLoggedIn() || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles)) {
        // Save intended URL to return after login (optional)
        if (!headers_sent()) {
            header('Location: ' . SITE_URL . '/login.php');
            exit;
        } else {
            echo '<p>Access denied. Please <a href="' . SITE_URL . '/login.php">login</a>.</p>';
            exit;
        }
    }
}

/**
 * Generate a unique student ID
 * Format: YEAR + 4 random digits + initials (example: 25-4821-JD)
 */
function generate_student_id($firstName, $lastName) {
    $year = date('y');
    $rand = random_int(1000, 9999);
    $initials = strtoupper(substr($firstName,0,1) . substr($lastName,0,1));
    return sprintf('%s-%04d-%s', $year, $rand, $initials);
}

/**
 * Generate a secure random password
 */
function generate_password($length = 10) {
    // allowed characters
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_+';
    $str = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $str .= $chars[random_int(0, $max)];
    }
    return $str;
}

/**
 * Hash password using bcrypt
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Create a user (student or instructor/admin)
 * Returns array with keys: success (bool), id (if success), student_id, password (plain)
 */
function create_user(PDO $pdo, $firstName, $lastName, $email, $program_id = null, $year_level = null, $role = 'student') {
    $firstName = sanitize($firstName);
    $lastName = sanitize($lastName);
    $email = sanitize($email);
    
    // Adapt to varying `users` table schemas. Inspect available columns and build an insert accordingly.
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM users");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Exception $e) {
        // If SHOW COLUMNS fails, fall back to assume canonical schema
        $cols = ['student_id','first_name','last_name','email','password','role','program_id','year_level','status'];
    }

    $has = function($name) use ($cols) { return in_array($name, $cols); };

    // Determine email column
    $emailCol = null;
    foreach (['email','user_email','username'] as $c) {
        if ($has($c)) { $emailCol = $c; break; }
    }

    // If we can check uniqueness, do it
    if ($emailCol) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE {$emailCol} = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Email already exists'];
        }
    }

    $studentID = generate_student_id($firstName, $lastName);
    $passwordPlain = generate_password(10);
    $passwordHash = hash_password($passwordPlain);

    // Build insert columns and values dynamically
    $insertCols = [];
    $placeholders = [];
    $values = [];

    if ($has('student_id')) { $insertCols[] = 'student_id'; $placeholders[] = '?'; $values[] = $studentID; }

    if ($has('first_name') && $has('last_name')) {
        $insertCols[] = 'first_name'; $placeholders[] = '?'; $values[] = $firstName;
        $insertCols[] = 'last_name'; $placeholders[] = '?'; $values[] = $lastName;
    } else {
        // single name column fallback
        foreach (['name','full_name','fullname','display_name'] as $nc) {
            if ($has($nc)) {
                $insertCols[] = $nc; $placeholders[] = '?'; $values[] = trim($firstName . ' ' . $lastName);
                break;
            }
        }
    }

    if ($emailCol) { $insertCols[] = $emailCol; $placeholders[] = '?'; $values[] = $email; }

    // password column
    $pwCol = null;
    foreach (['password','passwd','pass'] as $pc) { if ($has($pc)) { $pwCol = $pc; break; } }
    if ($pwCol) { $insertCols[] = $pwCol; $placeholders[] = '?'; $values[] = $passwordHash; }

    if ($has('role')) { $insertCols[] = 'role'; $placeholders[] = '?'; $values[] = $role; }
    if ($has('program_id')) { $insertCols[] = 'program_id'; $placeholders[] = '?'; $values[] = $program_id; }
    if ($has('year_level')) { $insertCols[] = 'year_level'; $placeholders[] = '?'; $values[] = $year_level; }
    if ($has('status')) { $insertCols[] = 'status'; $placeholders[] = '?'; $values[] = 'active'; }

    if (empty($insertCols)) {
        return ['success' => false, 'error' => 'No suitable columns found in users table'];
    }

    $sql = "INSERT INTO users (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $insert = $pdo->prepare($sql);
    $ok = $insert->execute($values);
    if ($ok) {
        $user_id = $pdo->lastInsertId();
        $enrolled = 0;
        if ($role === 'student' && $program_id && $year_level) {
            // Auto-enroll student into courses matching program and year level
            $enrollment = auto_enroll_student($pdo, $user_id, $program_id, $year_level);
            $enrolled = $enrollment['enrolled'] ?? 0;
        }
        return ['success' => true, 'id' => $user_id, 'student_id' => $studentID, 'password' => $passwordPlain, 'enrolled' => $enrolled];
    }

    return ['success' => false, 'error' => 'DB insert failed'];
}

/**
 * Auto-enroll a student (user id) into sections of courses matching program_id and year_level
 * Uses the active school year (status='active') when inserting enrollments.
 * Now enrolls into sections with capacity checks.
 */
function auto_enroll_student(PDO $pdo, $user_id, $program_id, $year_level) {
    // Find active school year
    $stmt = $pdo->prepare("SELECT id FROM school_years WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $sy = $stmt->fetchColumn();
    if (!$sy) {
        // no active school year, cannot enroll
        return ['success' => false, 'error' => 'No active school year'];
    }

    // Get courses for program & year
    $coursesStmt = $pdo->prepare("SELECT id FROM courses WHERE program_id = ? AND year_level = ?");
    $coursesStmt->execute([$program_id, $year_level]);
    $courses = $coursesStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$courses) {
        return ['success' => true, 'enrolled' => 0];
    }

    $enrolled = 0;
    foreach ($courses as $course_id) {
        // Try to enroll in a section with available capacity
        $sectionEnrolled = enroll_student_in_section($pdo, $user_id, $course_id, $sy);
        if ($sectionEnrolled) {
            $enrolled++;
        }
    }

    return ['success' => true, 'enrolled' => $enrolled];
}

/**
 * Enroll a student into an available section of a course with capacity check
 */
function enroll_student_in_section(PDO $pdo, $user_id, $course_id, $school_year_id) {
    // Find sections for this course with available capacity
    $stmt = $pdo->prepare("
        SELECT s.id, s.capacity, s.enrolled_count
        FROM sections s
        WHERE s.course_id = ? AND s.status = 'active' AND s.enrolled_count < s.capacity
        ORDER BY s.enrolled_count ASC
        LIMIT 1
    ");
    $stmt->execute([$course_id]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        // No available sections, enroll directly in course (legacy support)
        $ins = $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, course_id, school_year_id, status) VALUES (?, ?, ?, 'enrolled')");
        $ins->execute([$user_id, $course_id, $school_year_id]);
        return $ins->rowCount() > 0;
    }

    // Enroll in section and update count
    $pdo->beginTransaction();
    try {
        // Insert enrollment
        $ins = $pdo->prepare("INSERT IGNORE INTO enrollments (student_id, course_id, school_year_id, status) VALUES (?, ?, ?, 'enrolled')");
        $ins->execute([$user_id, $course_id, $school_year_id]);

        if ($ins->rowCount() > 0) {
            // Update section enrolled count
            $updateStmt = $pdo->prepare("UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id = ?");
            $updateStmt->execute([$section['id']]);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Helper: get program id by name or code
 */
function find_program_id(PDO $pdo, $programInput) {
    $programInput = trim($programInput);
    // Defensive/resilient lookup logic:
    // 1) If input is numeric, try matching id
    // 2) If `code` column exists, try exact code (case-insensitive)
    // 3) Try exact name (case-insensitive)
    // 4) Try partial name match (LIKE, case-insensitive)
    if ($programInput === '') return null;

    // 1) numeric id
    if (ctype_digit($programInput)) {
        $stmt = $pdo->prepare("SELECT id FROM programs WHERE id = ? LIMIT 1");
        $stmt->execute([$programInput]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    // Determine if `code` column exists
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM programs");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Exception $e) {
        $cols = [];
    }
    $hasCode = in_array('code', $cols);

    $inputLower = mb_strtolower($programInput, 'UTF-8');

    // 2) try exact code match (case-insensitive)
    if ($hasCode) {
        $stmt = $pdo->prepare("SELECT id FROM programs WHERE LOWER(code) = ? LIMIT 1");
        $stmt->execute([$inputLower]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    // 3) try exact name match (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM programs WHERE LOWER(name) = ? LIMIT 1");
    $stmt->execute([$inputLower]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    // 4) partial/LIKE name match (case-insensitive)
    $likeParam = '%' . $inputLower . '%';
    $stmt = $pdo->prepare("SELECT id FROM programs WHERE LOWER(name) LIKE ? LIMIT 1");
    $stmt->execute([$likeParam]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    // Not found
    return null;
}

?>
<?php
/**
 * LEE grading helpers
 * Map percent to LEE grade using provided bands; compute remarks from LEE average.
 */
function lee_from_percent(?float $percent) {
    if ($percent === null) return null;
    $p = floatval($percent);
    if ($p >= 95) return 1.0;
    if ($p == 94) return 1.1;
    if ($p == 93) return 1.2;
    if ($p == 92) return 1.3;
    if ($p == 91) return 1.4;
    if ($p == 90) return 1.5;
    if ($p == 89) return 1.6;
    if ($p == 88) return 1.7;
    if ($p == 87) return 1.8;
    if ($p == 86) return 1.9;
    if ($p == 85) return 2.0;
    if ($p == 84) return 2.1;
    if ($p == 83) return 2.2;
    if ($p == 82) return 2.3;
    if ($p == 81) return 2.4;
    if ($p == 80) return 2.5;
    if ($p == 79) return 2.6;
    if ($p == 78) return 2.7;
    if ($p == 77) return 2.8;
    if ($p == 76) return 2.9;
    if ($p == 75) return 3.0;
    if ($p < 75) return 5.0; // Failed
    return 1.0; // default for >100 guard (treat as top)
}

function lee_remarks(?float $leeAverage) {
    if ($leeAverage === null) return ['Incomplete', 'text-secondary'];
    if ($leeAverage <= 3.0) return ['Passed', 'text-success'];
    if ($leeAverage > 3.0 && $leeAverage < 5.0) return ['Conditional', 'text-warning'];
    if ($leeAverage >= 5.0) return ['Failed', 'text-danger'];
    return ['Incomplete', 'text-secondary'];
}
