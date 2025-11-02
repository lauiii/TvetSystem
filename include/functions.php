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
        // Attempt to send credentials via email if email column was used
        try {
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/email-functions.php';
                if (function_exists('sendStudentCredentials')) {
                    @sendStudentCredentials($email, $firstName, $studentID, $passwordPlain);
                }
            }
        } catch (Exception $e) { /* ignore mail failures */ }
        return ['success' => true, 'id' => $user_id, 'student_id' => $studentID, 'password' => $passwordPlain, 'enrolled' => $enrolled];
    }

    return ['success' => false, 'error' => 'DB insert failed'];
}

/**
 * Auto-enroll a student (user id) into sections of courses matching program_id and year_level
 * Uses the active school year (status='active') when inserting enrollments.
 * Now enrolls into sections with capacity checks.
 */
function ensure_unique_enrollments_schema(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN);
        $hasSy = in_array('school_year_id', $cols, true);
        $hasSection = in_array('section_id', $cols, true);

        // Add section_id column if missing (nullable)
        if (!$hasSection) {
            try {
                $pdo->exec("ALTER TABLE enrollments ADD COLUMN section_id INT NULL AFTER school_year_id");
            } catch (Exception $e) { /* ignore if fails */ }
            try { $pdo->exec("ALTER TABLE enrollments ADD INDEX idx_enr_section (section_id)"); } catch (Exception $e) { /* ignore */ }
            $hasSection = true; // best-effort
        }

        // Deduplicate: keep lowest id
        if ($hasSy) {
            $pdo->exec("DELETE e1 FROM enrollments e1 JOIN enrollments e2 ON e1.student_id=e2.student_id AND e1.course_id=e2.course_id AND e1.school_year_id=e2.school_year_id AND e1.id>e2.id");
        } else {
            $pdo->exec("DELETE e1 FROM enrollments e1 JOIN enrollments e2 ON e1.student_id=e2.student_id AND e1.course_id=e2.course_id AND e1.id>e2.id");
        }
        // Ensure unique index
        $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='enrollments' AND INDEX_NAME=?");
        $idx->execute(['uniq_enrollment_scsy']);
        $hasIdx = (int)$idx->fetchColumn() > 0;
        if (!$hasIdx) {
            if ($hasSy) {
                $pdo->exec("ALTER TABLE enrollments ADD UNIQUE KEY uniq_enrollment_scsy (student_id, course_id, school_year_id)");
            } else {
                $pdo->exec("ALTER TABLE enrollments ADD UNIQUE KEY uniq_enrollment_scsy (student_id, course_id)");
            }
        }
    } catch (Exception $e) { /* best-effort */ }
}

function auto_enroll_student(PDO $pdo, $user_id, $program_id, $year_level, ?int $semester = null) {
    // Enforce uniqueness upfront (best-effort)
    ensure_unique_enrollments_schema($pdo);

    // Find active school year and active semester (schema-adaptive)
    $stmt = $pdo->prepare("SELECT id, 
        CASE 
            WHEN EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_years' AND COLUMN_NAME = 'active_semester') 
            THEN active_semester ELSE 1 END AS active_semester
        FROM school_years WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $sy = $row['id'] ?? null;
    $activeSemester = (int)($row['active_semester'] ?? 1);
    if (!$sy) {
        // no active school year, cannot enroll
        return ['success' => false, 'error' => 'No active school year'];
    }
    $targetSemester = $semester ?? $activeSemester;

    // Get courses for program & year (and semester)
    $coursesStmt = $pdo->prepare("SELECT id FROM courses WHERE program_id = ? AND year_level = ? AND semester = ?");
    $coursesStmt->execute([$program_id, $year_level, $targetSemester]);
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
    // If already enrolled (unique per student/course/year), skip
    try {
        $existsStmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id=? AND course_id=? AND (school_year_id IS NULL OR school_year_id = ?) LIMIT 1");
        $existsStmt->execute([$user_id, $course_id, $school_year_id]);
        if ($existsStmt->fetchColumn()) {
            return false; // already enrolled; no increment
        }
    } catch (Exception $e) { /* proceed */ }

    // Find sections for this course with available capacity
    $stmt = $pdo->prepare("SELECT s.id, s.capacity, s.enrolled_count
        FROM sections s
        WHERE s.course_id = ? AND s.status = 'active' AND s.enrolled_count < s.capacity
        ORDER BY s.enrolled_count ASC
        LIMIT 1");
    $stmt->execute([$course_id]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        // No available sections, enroll directly in course (legacy support) – avoid duplicates via WHERE NOT EXISTS
        // Include section_id if column exists
        $cols = [];
        try { $cols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $cols = []; }
        $hasSecCol = in_array('section_id', $cols, true);
        if ($hasSecCol) {
            $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id, section_id, status)
                                  SELECT ?, ?, ?, NULL, 'enrolled' FROM DUAL
                                  WHERE NOT EXISTS (
                                      SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND (school_year_id IS NULL OR school_year_id=?)
                                  )");
            $ins->execute([$user_id, $course_id, $school_year_id, $user_id, $course_id, $school_year_id]);
        } else {
            $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id, status)
                                  SELECT ?, ?, ?, 'enrolled' FROM DUAL
                                  WHERE NOT EXISTS (
                                      SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND (school_year_id IS NULL OR school_year_id=?)
                                  )");
            $ins->execute([$user_id, $course_id, $school_year_id, $user_id, $course_id, $school_year_id]);
        }
        return $ins->rowCount() > 0;
    }

    // Enroll in section and update count
    $pdo->beginTransaction();
    try {
        // Conditional insert to avoid duplicates (include section_id if available)
        $cols = [];
        try { $cols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $cols = []; }
        $hasSecCol = in_array('section_id', $cols, true);
        if ($hasSecCol) {
            $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id, section_id, status)
                                  SELECT ?, ?, ?, ?, 'enrolled' FROM DUAL
                                  WHERE NOT EXISTS (
                                      SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND (school_year_id IS NULL OR school_year_id=?)
                                  )");
            $ins->execute([$user_id, $course_id, $school_year_id, $section['id'], $user_id, $course_id, $school_year_id]);
        } else {
            $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id, status)
                                  SELECT ?, ?, ?, 'enrolled' FROM DUAL
                                  WHERE NOT EXISTS (
                                      SELECT 1 FROM enrollments WHERE student_id=? AND course_id=? AND (school_year_id IS NULL OR school_year_id=?)
                                  )");
            $ins->execute([$user_id, $course_id, $school_year_id, $user_id, $course_id, $school_year_id]);
        }

        if ($ins->rowCount() > 0) {
            // Update section enrolled count only when we actually inserted
            $updateStmt = $pdo->prepare("UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id = ?");
            $updateStmt->execute([$section['id']]);
        }

        $pdo->commit();
        return $ins->rowCount() > 0;
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


/**
 * Fetch attendance weights (percentages) per period for a section from assessment_criteria rows named 'Attendance'.
 * Returns array like ['prelim'=>float,'midterm'=>float,'finals'=>float]. Missing periods are omitted.
 */
function get_attendance_weights(PDO $pdo, int $section_id): array {
    $out = [];
    $stmt = $pdo->prepare("SELECT period, percentage FROM assessment_criteria WHERE section_id = ? AND LOWER(TRIM(name)) = 'attendance'");
    $stmt->execute([$section_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $per = strtolower($r['period'] ?? '');
        if (in_array($per, ['prelim','midterm','finals'], true)) {
            $out[$per] = floatval($r['percentage']);
        }
    }
    return $out;
}

?>
<?php
/**
 * LEE grading helpers
 * Map percent to LEE grade using provided bands; compute remarks from LEE average.
 */
function lee_from_percent(?float $percent) {
    if ($percent === null) return null;
    // Align with client-side logic: round to nearest whole percent before mapping
    $x = (int)round(max(0.0, min(100.0, (float)$percent)));
    if ($x >= 95) return 1.00;
    if ($x === 94) return 1.10;
    if ($x === 93) return 1.20;
    if ($x === 92) return 1.30;
    if ($x === 91) return 1.40;
    if ($x === 90) return 1.50;
    if ($x === 89) return 1.60;
    if ($x === 88) return 1.70;
    if ($x === 87) return 1.80;
    if ($x === 86) return 1.90;
    if ($x === 85) return 2.00;
    if ($x === 84) return 2.10;
    if ($x === 83) return 2.20;
    if ($x === 82) return 2.30;
    if ($x === 81) return 2.40;
    if ($x === 80) return 2.50;
    if ($x === 79) return 2.60;
    if ($x === 78) return 2.70;
    if ($x === 77) return 2.80;
    if ($x === 76) return 2.90;
    if ($x === 75) return 3.00;
    if ($x < 75) return 5.00; // Failed
    return 1.00; // Fallback (shouldn't hit)
}

function lee_remarks(?float $leeAverage) {
    if ($leeAverage === null) return ['Incomplete', 'text-secondary'];
    if ($leeAverage <= 3.0) return ['Passed', 'text-success'];
    if ($leeAverage > 3.0 && $leeAverage < 5.0) return ['Conditional', 'text-warning'];
    if ($leeAverage >= 5.0) return ['Failed', 'text-danger'];
    return ['Incomplete', 'text-secondary'];
}

/**
 * Compute overall percent for a student's course based on assessment items.
 * Returns null if no assessments or totals.
 */
function course_overall_percent(PDO $pdo, int $student_id, int $course_id): ?float {
    // Enrollment id for this student/course
    $enr = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? LIMIT 1");
    $enr->execute([$student_id, $course_id]);
    $enrollment_id = (int)$enr->fetchColumn();
    if (!$enrollment_id) return null;

    // Total possible and total earned across all items for this course
    $sql = "
        SELECT SUM(ai.total_score) AS possible,
               COUNT(ai.id) AS items
        FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        WHERE ac.course_id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$course_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $possible = (float)($row['possible'] ?? 0);
    if ($possible <= 0) return null;

    // Earned for this enrollment across items (grades for this enrollment only)
    $gstmt = $pdo->prepare("SELECT SUM(grade) AS earned FROM grades WHERE enrollment_id = ?");
    $gstmt->execute([$enrollment_id]);
    $earned = (float)($gstmt->fetchColumn() ?? 0);

    $percent = ($earned / $possible) * 100.0;
    // clamp 0-100
    if ($percent < 0) $percent = 0; if ($percent > 100) $percent = 100;
    return $percent;
}

/**
 * Determine if a student passed all courses for a given program year.
 * Rule: overall percent >= 75 in every course of that year (ignores courses with no assessments).
 */
function student_passed_year(PDO $pdo, int $student_id, int $program_id, int $year_level): bool {
    $c = $pdo->prepare("SELECT id FROM courses WHERE program_id = ? AND year_level = ?");
    $c->execute([$program_id, $year_level]);
    $courses = $c->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!$courses) return false; // no courses -> cannot pass

    foreach ($courses as $cid) {
        $pct = course_overall_percent($pdo, $student_id, (int)$cid);
        if ($pct === null) return false; // incomplete -> not passed
        if ($pct < 75.0) return false; // failed this course
    }
    return true;
}

/**
 * Promote eligible students from a year to the next within a program.
 * - Only those who pass all courses in from_year are promoted.
 * - Updates users.year_level and auto-enrolls into next year's courses for active SY.
 * Returns array with counts and details.
 */
function promote_students(PDO $pdo, int $program_id, int $from_year): array {
    $next_year = $from_year + 1;
    // Find students in this program and year
    $st = $pdo->prepare("SELECT id FROM users WHERE role='student' AND program_id = ? AND year_level = ? AND status = 'active'");
    $st->execute([$program_id, $from_year]);
    $studentIds = $st->fetchAll(PDO::FETCH_COLUMN, 0);

    $promoted = 0; $checked = 0; $failed = [];
    foreach ($studentIds as $sid) {
        $checked++;
        if (student_passed_year($pdo, (int)$sid, $program_id, $from_year)) {
            // Update year level
            $upd = $pdo->prepare("UPDATE users SET year_level = ? WHERE id = ?");
            $upd->execute([$next_year, $sid]);
            // Auto-enroll into next year's courses for active SY
            try { auto_enroll_student($pdo, (int)$sid, $program_id, $next_year); } catch (Exception $e) { /* ignore */ }
            $promoted++;
        } else {
            $failed[] = (int)$sid;
        }
    }

    return ['checked'=>$checked, 'promoted'=>$promoted, 'failed'=> $failed, 'next_year'=>$next_year];
}

// =============================
// Notifications helpers (schema-adaptive)
// =============================
/** Determine available columns in notifications table */
function _notif_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    try { $cols = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Exception $e) { $cols = ['id','user_id','message','type','status','created_at']; }
    return $cols;
}

/**
 * Create notifications for specific users. Works whether optional columns exist or not.
 */
function notify_users(PDO $pdo, array $userIds, string $title, string $message, string $type='info', ?string $dueAt=null, ?int $deadlineId=null): int {
    if (empty($userIds)) return 0;
    $cols = _notif_cols($pdo);
    $hasTitle = in_array('title',$cols,true);
    $hasDue   = in_array('due_at',$cols,true);
    $hasDead  = in_array('deadline_id',$cols,true);

    // Build INSERT dynamically
    $fields = ['user_id'];
    $placeholders = ['?'];
    $paramsOrder = [];

    if ($hasTitle) { $fields[]='title'; $placeholders[]='?'; $paramsOrder[]='title'; }
    $fields[]='message'; $placeholders[]='?'; $paramsOrder[]='message';
    $fields[]='type';    $placeholders[]='?'; $paramsOrder[]='type';
    if ($hasDue)  { $fields[]='due_at'; $placeholders[]='?'; $paramsOrder[]='due_at'; }
    if ($hasDead) { $fields[]='deadline_id'; $placeholders[]='?'; $paramsOrder[]='deadline_id'; }
    $fields[]='status'; $placeholders[]="'unread'"; // constant
    $fields[]='created_at'; $placeholders[]='NOW()';

    $sql = 'INSERT INTO notifications (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
    $ins = $pdo->prepare($sql);

    $cnt = 0;
    foreach ($userIds as $uid) {
        $params = [$uid];
        foreach ($paramsOrder as $k) {
            if ($k==='title') $params[] = $title;
            elseif ($k==='message') $params[] = $hasTitle ? $message : ( ($title ? ($title.': ') : '') . $message );
            elseif ($k==='type') $params[] = $type;
            elseif ($k==='due_at') $params[] = $dueAt;
            elseif ($k==='deadline_id') $params[] = $deadlineId;
        }
        try { $ins->execute($params); $cnt++; } catch (Exception $e) { /* ignore */ }
    }
    return $cnt;
}

/** Unread count for a user */
function get_unread_count(PDO $pdo, int $userId): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status='unread'");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/** List notifications grouped into time buckets */
function list_notifications_grouped(PDO $pdo, int $userId): array {
    $cols = _notif_cols($pdo);
    $select = 'id, message, type, status, created_at';
    if (in_array('title',$cols,true)) { $select = 'id, title, message, type, status, created_at'; }
    if (in_array('due_at',$cols,true))  { $select .= ', due_at'; } else { $select .= ', NULL AS due_at'; }

    $st = $pdo->prepare("SELECT $select FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $today = (new DateTime('now'))->format('Y-m-d');
    $yesterday = (new DateTime('yesterday'))->format('Y-m-d');
    $startOfWeek = (new DateTime('monday this week'))->format('Y-m-d');

    $out = ['Today'=>[], 'Yesterday'=>[], 'This Week'=>[], 'Earlier'=>[]];
    foreach ($rows as $r) {
        if (!isset($r['title'])) $r['title'] = '';
        if (!isset($r['due_at'])) $r['due_at'] = null;
        $d = substr((string)$r['created_at'], 0, 10);
        if ($d === $today) $out['Today'][] = $r;
        elseif ($d === $yesterday) $out['Yesterday'][] = $r;
        elseif ($d >= $startOfWeek) $out['This Week'][] = $r;
        else $out['Earlier'][] = $r;
    }
    return $out;
}

/** Mark notifications read for a user */
function mark_notifications_read(PDO $pdo, int $userId, array $ids): int {
    if (empty($ids)) return 0;
    $place = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids; array_unshift($params, $userId);
    $st = $pdo->prepare("UPDATE notifications SET status='read' WHERE user_id = ? AND id IN ($place)");
    $st->execute($params);
    return $st->rowCount();
}

/** Mark ALL unread notifications read for a user */
function mark_all_notifications_read(PDO $pdo, int $userId): int {
    $st = $pdo->prepare("UPDATE notifications SET status='read' WHERE user_id = ? AND status='unread'");
    $st->execute([$userId]);
    return $st->rowCount();
}

// =============================
// Deadlines helpers (schema-adaptive)
// =============================
function ensure_deadlines_schema(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deadlines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            audience ENUM('instructor','student') NOT NULL,
            due_at DATETIME NOT NULL,
            remind_days VARCHAR(100) DEFAULT '7,3,1',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_due (due_at),
            INDEX idx_audience (audience)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }
}

function set_deadline(PDO $pdo, string $title, string $audience, string $dueAt, string $remindDays='7,3,1', ?int $createdBy=null): int {
    ensure_deadlines_schema($pdo);
    if (!in_array($audience, ['instructor','student'], true)) {
        throw new InvalidArgumentException('audience must be instructor or student');
    }
    if ($createdBy === null && isset($_SESSION['user_id'])) $createdBy = (int)$_SESSION['user_id'];
    $st = $pdo->prepare("INSERT INTO deadlines (title, audience, due_at, remind_days, created_by) VALUES (?, ?, ?, ?, ?)");
    $st->execute([$title, $audience, $dueAt, $remindDays, (int)$createdBy]);
    return (int)$pdo->lastInsertId();
}

function list_deadlines(PDO $pdo, ?string $audience=null): array {
    ensure_deadlines_schema($pdo);
    if ($audience && in_array($audience, ['instructor','student'], true)) {
        $st = $pdo->prepare("SELECT * FROM deadlines WHERE audience = ? ORDER BY due_at DESC");
        $st->execute([$audience]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return $pdo->query("SELECT * FROM deadlines ORDER BY due_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function delete_deadline(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("DELETE FROM deadlines WHERE id = ?");
    return $st->execute([$id]);
}

/**
 * Run notifier to create notifications for deadlines.
 * Returns number of notifications created.
 */
function run_deadline_notifier(PDO $pdo): int {
    ensure_deadlines_schema($pdo);
    $now = new DateTime('now');
    $deadlines = $pdo->query("SELECT * FROM deadlines")->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($deadlines as $d) {
        $due = new DateTime($d['due_at']);
        $diffDays = (int)$now->diff($due)->format('%r%a');
        $rem = array_filter(array_map('intval', explode(',', (string)$d['remind_days'])));
        $should = false; $badge='info';
        if ($diffDays === 0) { $should=true; $badge='alert'; }
        elseif ($diffDays < 0) { $should=true; $badge='warning'; }
        elseif (in_array($diffDays, $rem, true)) { $should=true; $badge='success'; }
        if (!$should) continue;

        // recipients by role
        $role = $d['audience'] === 'instructor' ? 'instructor' : 'student';
        $st = $pdo->prepare("SELECT id FROM users WHERE role = ? AND status='active'");
        $st->execute([$role]);
        $userIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));
        if (empty($userIds)) continue;

        // Avoid duplicates per user per day for same deadline
        $cols = _notif_cols($pdo);
        $hasDead = in_array('deadline_id',$cols,true);
        $checkSql = $hasDead ? "SELECT 1 FROM notifications WHERE user_id=? AND deadline_id=? AND DATE(created_at)=CURDATE() LIMIT 1"
                             : "SELECT 1 FROM notifications WHERE user_id=? AND message LIKE ? AND DATE(created_at)=CURDATE() LIMIT 1";
        $check = $pdo->prepare($checkSql);

        foreach ($userIds as $uid) {
            if ($hasDead) {
                $check->execute([$uid, (int)$d['id']]);
                if ($check->fetch()) continue;
                $total += notify_users($pdo, [$uid], $d['title'], ($diffDays<0?'Deadline overdue':($diffDays===0?'Deadline due today':'Due in '.$diffDays.' day(s)')), $badge, $d['due_at'], (int)$d['id']);
            } else {
                $needle = '%'. $d['title'] .'%';
                $check->execute([$uid, $needle]);
                if ($check->fetch()) continue;
                $total += notify_users($pdo, [$uid], $d['title'], ($diffDays<0?'Deadline overdue':($diffDays===0?'Deadline due today':'Due in '.$diffDays.' day(s)')), $badge, $d['due_at'], null);
            }
        }
    }
    return $total;
}
