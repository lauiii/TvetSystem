<?php
/**
 * Admin - Manage Courses
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Ensure `units` column exists on courses (auto-migrate)
try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM courses");
    $courseCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('units', $courseCols)) {
        $pdo->exec("ALTER TABLE courses ADD COLUMN units INT NOT NULL DEFAULT 3 AFTER course_name");
    }
} catch (Exception $e) {
    // ignore - page will continue but units may default elsewhere
}

// Fetch programs, school years, and instructors (schema-adaptive)
// programs: choose columns that exist to avoid unknown column errors
$programCols = [];
try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM programs");
    $programCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $programCols = [];
}

$progSelect = ['id'];
if (in_array('name', $programCols)) $progSelect[] = 'name';
if (in_array('code', $programCols)) $progSelect[] = 'code';
$orderBy = in_array('name', $programCols) ? 'name' : 'id';
$programs = $pdo->query('SELECT ' . implode(',', $progSelect) . " FROM programs ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC);


// instructors: fall back to name column or concatenated first/last
$userCols = [];
try {
    $uc = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $uc->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $userCols = [];
}
$instructorNameExpr = in_array('first_name', $userCols) && in_array('last_name', $userCols)
    ? "CONCAT(first_name,' ',last_name) as name"
    : (in_array('name', $userCols) ? 'name' : "'' as name");
$instructors = $pdo->query("SELECT id, $instructorNameExpr FROM users WHERE role = 'instructor'")->fetchAll(PDO::FETCH_ASSOC);

// Handle add course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $program_id = (int)($_POST['program_id'] ?? 0);
    $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
    $course_name = sanitize($_POST['course_name'] ?? '');
    $units = (int)($_POST['units'] ?? 3);
    $year_level = (int)($_POST['year_level'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 1);
    $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;

    if (!$program_id || $course_code === '' || empty($course_name)) {
        $error = 'Program, course code and name are required.';
    } else {
        try {
            // prevent dup by (program_id, course_code)
            $chk = $pdo->prepare('SELECT id FROM courses WHERE program_id = ? AND UPPER(course_code) = ? LIMIT 1');
            $chk->execute([$program_id, $course_code]);
            if ($chk->fetch()) { throw new Exception('Course code already exists in this program.'); }
            // ensure unique index best-effort
            try { $pdo->exec('ALTER TABLE courses ADD UNIQUE KEY uniq_program_course (program_id, course_code)'); } catch (Exception $e) { /* ignore */ }

            // schema-adaptive insert without school_year_id
            $cols = ['program_id','course_code','course_name','units','year_level','semester','instructor_id'];
            $vals = [$program_id, $course_code, $course_name, $units, $year_level, $semester, $instructor_id];
            $place = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $pdo->prepare('INSERT INTO courses (' . implode(',', $cols) . ') VALUES (' . $place . ')');
            $stmt->execute($vals);
            $msg = 'Course added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add course: ' . $e->getMessage();
        }
    }
}

// Handle edit course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $program_id = (int)($_POST['program_id'] ?? 0);
    $course_code = sanitize($_POST['course_code'] ?? '');
    $course_name = sanitize($_POST['course_name'] ?? '');
    $units = (int)($_POST['units'] ?? 3);
    $year_level = (int)($_POST['year_level'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 1);
    $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;

    if ($id <= 0 || !$program_id || empty($course_code) || empty($course_name)) {
        $error = 'All fields are required.';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE courses SET program_id = ?, course_code = ?, course_name = ?, units = ?, year_level = ?, semester = ?, instructor_id = ? WHERE id = ?');
            $stmt->execute([$program_id, $course_code, $course_name, $units, $year_level, $semester, $instructor_id, $id]);
            $msg = 'Course updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update course: ' . $e->getMessage();
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM courses WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Course deleted.';
        } catch (Exception $e) {
            $error = 'Failed to delete course: ' . $e->getMessage();
        }
    }
}

// Handle import courses (CSV/Excel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please upload a CSV or Excel file.');
        }
        @ini_set('memory_limit', '1024M');
        @set_time_limit(300);
        $tmp = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        $imported = 0; $skipped = 0;
        // cache programs by code (schema-adaptive)
        $progIdx = [];
        $progCols = [];
        try { $progCols = $pdo->query("SHOW COLUMNS FROM programs")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $progCols = []; }
        $codeCol = null;
        foreach (['code','program_code','short_code','abbr','shortname','short_name'] as $cand) {
            if (in_array($cand, $progCols, true)) { $codeCol = $cand; break; }
        }
        if (!$codeCol) { throw new Exception("Programs table is missing a code column (expected one of: code, program_code, short_code)"); }
        $progRows = $pdo->query("SELECT id, {$codeCol} AS code FROM programs")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($progRows as $pr) { if (!empty($pr['code'])) { $progIdx[strtolower($pr['code'])] = (int)$pr['id']; } }
        $ins = $pdo->prepare('INSERT INTO courses (program_id, course_code, course_name, units, year_level, semester) VALUES (?, ?, ?, ?, ?, ?)');

        if ($ext === 'csv') {
            $fh = fopen($tmp, 'r');
            if (!$fh) { throw new Exception('Failed to read uploaded file'); }
            $header = fgetcsv($fh);
            if (!$header) { throw new Exception('Empty file'); }
            $map = [];
            foreach ($header as $i => $h) { $map[strtolower(trim((string)$h))] = $i; }
            $required = ['program_code','course_code','course_name','year_level','semester'];
            foreach ($required as $r) { if (!isset($map[$r])) { throw new Exception('Missing column: '.$r); } }
            while (($row = fgetcsv($fh)) !== false) {
                $pcode = strtolower(trim((string)($row[$map['program_code']] ?? '')));
                $pid = $progIdx[$pcode] ?? null;
                if (!$pid) { $skipped++; continue; }
                $code = trim((string)($row[$map['course_code']] ?? ''));
                $name = trim((string)($row[$map['course_name']] ?? ''));
                $units = isset($map['units']) ? (int)($row[$map['units']] ?? 3) : 3;
                $year = (int)($row[$map['year_level']] ?? 0);
                $sem = (int)($row[$map['semester']] ?? 1);
                if ($code === '' || $name === '' || !$year || !$sem) { $skipped++; continue; }
                try { $ins->execute([$pid, $code, $name, $units, $year, $sem]); $imported++; } catch (Exception $ie) { $skipped++; }
            }
            fclose($fh);
        } elseif (in_array($ext, ['xlsx','xls'])) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) { require_once $autoload; }
            if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                throw new Exception('Excel import requires PhpSpreadsheet. Run: composer require phpoffice/phpspreadsheet, or upload a CSV instead.');
            }
            \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance()->setCalculationCacheEnabled(false);
            $readerType = ($ext === 'xlsx') ? 'Xlsx' : 'Xls';
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
            if (method_exists($reader, 'setReadDataOnly')) { $reader->setReadDataOnly(true); }
            if (method_exists($reader, 'setReadEmptyCells')) { $reader->setReadEmptyCells(false); }
            $spreadsheet = $reader->load($tmp);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            // Header (row 1)
            $map = [];
            for ($c=1; $c<=$highestCol; $c++) {
                $val = strtolower(trim((string)$sheet->getCellByColumnAndRow($c, 1)->getValue()));
                if ($val !== '') { $map[$val] = $c-1; } // zero-based to match CSV logic below
            }
            $required = ['program_code','course_code','course_name','year_level','semester'];
            foreach ($required as $r) { if (!isset($map[$r])) { $spreadsheet->disconnectWorksheets(); unset($spreadsheet); throw new Exception('Missing column: '.$r); } }
            // Iterate rows stream-like to reduce memory
            for ($r=2; $r<=$highestRow; $r++) {
                // check if the entire row is empty
                $rowEmpty = true;
                for ($c=1; $c<=$highestCol; $c++) {
                    if (trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue()) !== '') { $rowEmpty = false; break; }
                }
                if ($rowEmpty) { continue; }
                // Build row array using zero-based index for reuse of CSV code
                $row = [];
                for ($c=1; $c<=$highestCol; $c++) { $row[$c-1] = trim((string)$sheet->getCellByColumnAndRow($c, $r)->getValue()); }
                $pcode = strtolower(trim((string)($row[$map['program_code']] ?? '')));
                $pid = $progIdx[$pcode] ?? null;
                if (!$pid) { $skipped++; continue; }
                $code = trim((string)($row[$map['course_code']] ?? ''));
                $name = trim((string)($row[$map['course_name']] ?? ''));
                $units = isset($map['units']) ? (int)($row[$map['units']] ?? 3) : 3;
                $year = (int)($row[$map['year_level']] ?? 0);
                $sem = (int)($row[$map['semester']] ?? 1);
                if ($code === '' || $name === '' || !$year || !$sem) { $skipped++; continue; }
                try { $ins->execute([$pid, $code, $name, $units, $year, $sem]); $imported++; } catch (Exception $ie) { $skipped++; }
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } else {
            throw new Exception('Unsupported file type. Please upload .csv, .xlsx, or .xls');
        }

        $msg = "Import complete. Imported: {$imported}, Skipped: {$skipped}.";
    } catch (Exception $e) {
        $error = 'Import failed: ' . $e->getMessage();
    }
}

// Handle add section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_section') {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $raw_code = (string)($_POST['section_code'] ?? '');
    $section_code = strtoupper(trim($raw_code));
    $section_name = sanitize($_POST['section_name'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 30);
    $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $instructors = $_POST['instructors'] ?? [];

    if (!$course_id || $section_code === '') {
        $error = 'Course and section code are required.';
    } else {
        try {
            // ensure schema supports unique index
            try { $pdo->exec("ALTER TABLE sections MODIFY section_code VARCHAR(50)"); } catch (Exception $e) { /* ignore */ }
            try { $pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)"); } catch (Exception $e) { /* may already exist */ }

            $pdo->beginTransaction();
            // Conditional insert to avoid duplicates
            $stmt = $pdo->prepare("INSERT INTO sections (course_id, section_code, section_name, capacity, room_id)\n                                   SELECT ?, ?, ?, ?, ? FROM DUAL\n                                   WHERE NOT EXISTS (SELECT 1 FROM sections WHERE course_id = ? AND UPPER(TRIM(section_code)) = UPPER(TRIM(?)))");
            $stmt->execute([$course_id, $section_code, $section_name, $capacity, $room_id, $course_id, $section_code]);
            if ($stmt->rowCount() === 0) {
                // Nothing inserted (duplicate)
                $pdo->rollBack();
                $msg = 'Section already exists for this course.';
            } else {
                $section_id = $pdo->lastInsertId();
                // Insert instructors
                if (!empty($instructors)) {
                    $ist = $pdo->prepare('INSERT INTO instructor_sections (section_id, instructor_id) VALUES (?, ?)');
                    foreach ($instructors as $instructor_id) {
                        $ist->execute([$section_id, (int)$instructor_id]);
                    }
                }
                $pdo->commit();
                $msg = 'Section added successfully.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to add section: ' . $e->getMessage();
        }
    }
}

// Handle edit section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_section') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $section_code = sanitize($_POST['section_code'] ?? '');
    $section_name = sanitize($_POST['section_name'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 30);
    $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $instructors = $_POST['instructors'] ?? [];

    if ($section_id <= 0 || !$course_id || empty($section_code)) {
        $error = 'All fields are required.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE sections SET course_id = ?, section_code = ?, section_name = ?, capacity = ?, room_id = ? WHERE id = ?');
            $stmt->execute([$course_id, $section_code, $section_name, $capacity, $room_id, $section_id]);

            // Update instructors: delete existing and insert new
            $stmt = $pdo->prepare('DELETE FROM instructor_sections WHERE section_id = ?');
            $stmt->execute([$section_id]);

            if (!empty($instructors)) {
                $stmt = $pdo->prepare('INSERT INTO instructor_sections (section_id, instructor_id) VALUES (?, ?)');
                foreach ($instructors as $instructor_id) {
                    $stmt->execute([$section_id, (int)$instructor_id]);
                }
            }
            $pdo->commit();
            $msg = 'Section updated successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to update section: ' . $e->getMessage();
        }
    }
}

// Handle delete section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_section') {
    $section_id = (int)($_POST['section_id'] ?? 0);
    if ($section_id > 0) {
        try {
            $pdo->beginTransaction();
            // Delete instructor assignments first
            $stmt = $pdo->prepare('DELETE FROM instructor_sections WHERE section_id = ?');
            $stmt->execute([$section_id]);
            // Delete enrollments
            $stmt = $pdo->prepare('DELETE FROM enrollments WHERE section_id = ?');
            $stmt->execute([$section_id]);
            // Delete section
            $stmt = $pdo->prepare('DELETE FROM sections WHERE id = ?');
            $stmt->execute([$section_id]);
            $pdo->commit();
            $msg = 'Section deleted.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to delete section: ' . $e->getMessage();
        }
    }
}

// Handle auto-create sections based on student count
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_sections') {
    try {
        $program_id = (int)($_POST['program_id'] ?? 0);
        $year_level = (int)($_POST['year_level'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));
        if (!$program_id || !$year_level || !$semester) { throw new Exception('Program, year level, and semester are required.'); }

        // Count active students for this program/year
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
        $st->execute([$program_id, $year_level]);
        $studentCount = (int)$st->fetchColumn();

        // Fetch courses in this bucket
        $cst = $pdo->prepare('SELECT id, course_code, course_name FROM courses WHERE program_id=? AND year_level=? AND semester=? ORDER BY course_name');
        $cst->execute([$program_id, $year_level, $semester]);
        $courseRows = $cst->fetchAll(PDO::FETCH_ASSOC);
        if (!$courseRows) { throw new Exception('No courses found for the selected filters.'); }

        // helper to generate section codes A, B, ... Z, AA, AB, ...
        $nextCode = function(array $existing, int $index) {
            $n = $index; $code = '';
            while ($n >= 0) { $code = chr(($n % 26) + 65) . $code; $n = intdiv($n,26) - 1; }
            // ensure not in existing; if exists, bump index until unique
            $i = 0; $baseIndex = $index;
            while (in_array($code, $existing, true)) { $i++; $n=$baseIndex+$i; $code=''; while ($n>=0){ $code=chr(($n%26)+65).$code; $n=intdiv($n,26)-1; } }
            return $code;
        };

        $createdTotal = 0; $touchedCourses = 0;
        $pdo->beginTransaction();
        foreach ($courseRows as $cr) {
            $course_id = (int)$cr['id'];
            // existing sections for this course
            $s = $pdo->prepare('SELECT section_code FROM sections WHERE course_id=? ORDER BY section_code');
            $s->execute([$course_id]);
            $existingCodes = array_map(function($r){ return (string)$r['section_code']; }, $s->fetchAll(PDO::FETCH_ASSOC));
            $current = count($existingCodes);
            $need = (int)ceil($studentCount / $capacity);
            $toCreate = max(0, $need - $current);
            if ($toCreate <= 0) { continue; }
            $touchedCourses++;
            for ($i=0; $i<$toCreate; $i++) {
                $code = $nextCode($existingCodes, $current + $i);
                $ins = $pdo->prepare("INSERT INTO sections (course_id, section_code, section_name, capacity, enrolled_count, status) VALUES (?, ?, ?, ?, 0, 'active')");
                $ins->execute([$course_id, $code, $code, $capacity]);
                $createdTotal++;
                $existingCodes[] = $code;
            }
        }
        $pdo->commit();
        if ($createdTotal > 0) {
            $msg = "Auto-created $createdTotal section(s) across $touchedCourses course(s).";
        } else {
            $msg = 'No new sections needed; existing capacity already sufficient.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Auto-create failed: ' . $e->getMessage();
    }
}

// Handle global update: iterate all (or one program's) course buckets and ensure sections capacity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'global_update') {
    try {
        $programFilter = (int)($_POST['program_id'] ?? 0); // 0 = all programs
        $capacity = max(1, (int)($_POST['capacity'] ?? 30));

        // Build list of buckets program_id, year_level, semester
        if ($programFilter > 0) {
            $buckStmt = $pdo->prepare('SELECT DISTINCT program_id, year_level, semester FROM courses WHERE program_id=? ORDER BY program_id, year_level, semester');
            $buckStmt->execute([$programFilter]);
        } else {
            $buckStmt = $pdo->query('SELECT DISTINCT program_id, year_level, semester FROM courses ORDER BY program_id, year_level, semester');
        }
        $buckets = $buckStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$buckets) { throw new Exception('No courses found.'); }

        // helper
        $nextCode = function(array $existing, int $index) {
            $n = $index; $code = '';
            while ($n >= 0) { $code = chr(($n % 26) + 65) . $code; $n = intdiv($n,26) - 1; }
            $i = 0; $baseIndex = $index;
            while (in_array($code, $existing, true)) { $i++; $n=$baseIndex+$i; $code=''; while ($n>=0){ $code=chr(($n%26)+65).$code; $n=intdiv($n,26)-1; } }
            return $code;
        };

        $totalCreated = 0; $bucketTouched = 0; $coursesTouched = 0;
        $pdo->beginTransaction();
        foreach ($buckets as $b) {
            $pid = (int)$b['program_id']; $yl = (int)$b['year_level']; $sem = (int)$b['semester'];
            // count students
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
            $st->execute([$pid, $yl]);
            $studentCount = (int)$st->fetchColumn();
            // courses in bucket
            $cst = $pdo->prepare('SELECT id FROM courses WHERE program_id=? AND year_level=? AND semester=?');
            $cst->execute([$pid, $yl, $sem]);
            $courseIds = $cst->fetchAll(PDO::FETCH_COLUMN);
            if (!$courseIds) continue;
            $bucketTouched++;
            foreach ($courseIds as $cid) {
                $cid = (int)$cid;
                $s = $pdo->prepare('SELECT section_code FROM sections WHERE course_id=? ORDER BY section_code');
                $s->execute([$cid]);
                $existing = array_map(function($r){ return (string)$r['section_code']; }, $s->fetchAll(PDO::FETCH_ASSOC));
                $current = count($existing);
                $need = (int)ceil($studentCount / $capacity);
                $toCreate = max(0, $need - $current);
                if ($toCreate <= 0) continue;
                $coursesTouched++;
                for ($i=0; $i<$toCreate; $i++) {
                    $code = $nextCode($existing, $current + $i);
                    $ins = $pdo->prepare("INSERT INTO sections (course_id, section_code, section_name, capacity, enrolled_count, status) VALUES (?, ?, ?, ?, 0, 'active')");
                    $ins->execute([$cid, $code, $code, $capacity]);
                    $totalCreated++;
                    $existing[] = $code;
                }
            }
        }
        $pdo->commit();
        $msg = "Global update complete: created $totalCreated section(s) across $coursesTouched course(s) in $bucketTouched bucket(s).";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Global update failed: ' . $e->getMessage();
    }
}

// Handle reset sections (dangerous): delete sections (and related mappings/enrollments) optionally filtered by program/year/semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_sections') {
    try {
        $program_id = (int)($_POST['program_id'] ?? 0);
        $year_level = (int)($_POST['year_level'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);

        // Determine section ids to delete
        $sql = 'SELECT s.id FROM sections s INNER JOIN courses c ON s.course_id = c.id WHERE 1=1';
        $params = [];
        if ($program_id) { $sql .= ' AND c.program_id = ?'; $params[] = $program_id; }
        if ($year_level) { $sql .= ' AND c.year_level = ?'; $params[] = $year_level; }
        if ($semester) { $sql .= ' AND c.semester = ?'; $params[] = $semester; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);

        if (!$ids) { $msg = 'No sections matched the selected filters.'; }
        else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->beginTransaction();
            // delete instructor mappings
            $d1 = $pdo->prepare("DELETE FROM instructor_sections WHERE section_id IN ($in)");
            $d1->execute($ids);
            // delete enrollments linked to these sections (preserve others)
            try { $d2 = $pdo->prepare("DELETE FROM enrollments WHERE section_id IN ($in)"); $d2->execute($ids); } catch (Exception $e) { /* if column missing, ignore */ }
            // delete sections
            $d3 = $pdo->prepare("DELETE FROM sections WHERE id IN ($in)");
            $d3->execute($ids);
            $pdo->commit();
            $msg = 'Reset complete. Removed ' . count($ids) . ' section(s).';
            // Best-effort: ensure unique index to avoid future dupes
            try { $pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)"); } catch (Exception $e) { /* may already exist */ }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Reset failed: ' . $e->getMessage();
    }
}

// Handle dedupe sections: collapse duplicates by (course_id, section_code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dedupe_sections') {
    try {
        // Normalize codes and Find duplicates: keep min(id)
        try { $pdo->exec("ALTER TABLE sections MODIFY section_code VARCHAR(50)"); } catch (Exception $e) { /* ignore */ }
        $dups = $pdo->query("SELECT course_id, UPPER(TRIM(section_code)) AS ncode, MIN(id) AS keep_id, GROUP_CONCAT(id) AS all_ids, COUNT(*) AS cnt FROM sections GROUP BY course_id, UPPER(TRIM(section_code)) HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
        $totalRemoved = 0; $totalUpdated = 0;
        foreach ($dups as $d) {
            $keep = (int)$d['keep_id'];
            $ids = array_map('intval', array_filter(explode(',', (string)$d['all_ids'])));
            $toDel = array_values(array_diff($ids, [$keep]));
            if (!$toDel) continue;
            $in = implode(',', array_fill(0, count($toDel), '?'));
            // move enrollments to keep
            try {
                $u = $pdo->prepare("UPDATE enrollments SET section_id = ? WHERE section_id IN ($in)");
                $u->execute(array_merge([$keep], $toDel));
                $totalUpdated += $u->rowCount();
            } catch (Exception $e) { /* ignore if column missing */ }
            // delete instructor mappings
            $d1 = $pdo->prepare("DELETE FROM instructor_sections WHERE section_id IN ($in)");
            $d1->execute($toDel);
            // delete sections
            $d2 = $pdo->prepare("DELETE FROM sections WHERE id IN ($in)");
            $d2->execute($toDel);
            $totalRemoved += count($toDel);
        }
        // unique index to prevent future duplicates
        try { $pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)"); } catch (Exception $e) { /* may already exist */ }
        $msg = "Deduped sections. Removed $totalRemoved duplicate row(s), reassigned $totalUpdated enrollment(s).";
    } catch (Exception $e) {
        $error = 'Dedupe failed: ' . $e->getMessage();
    }
}

// Fetch courses with program details and sections
$courses = $pdo->query('SELECT c.*, p.name as program_name, p.code as program_code, p.description as program_description FROM courses c LEFT JOIN programs p ON c.program_id = p.id ORDER BY p.name, c.year_level, c.course_name')->fetchAll();

// Handle dedupe courses by (program_id, course_code)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dedupe_courses') {
    try {
        $dups = $pdo->query("SELECT program_id, UPPER(course_code) as code, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids, COUNT(*) as cnt FROM courses GROUP BY program_id, UPPER(course_code) HAVING cnt > 1")->fetchAll(PDO::FETCH_ASSOC);
        $movedSections = 0; $movedEnroll = 0; $removed = 0;
        foreach ($dups as $d) {
            $keep = (int)$d['keep_id'];
            $ids = array_map('intval', array_filter(explode(',', (string)$d['all_ids'])));
            $toDel = array_values(array_diff($ids, [$keep]));
            if (!$toDel) continue;
            $in = implode(',', array_fill(0, count($toDel), '?'));
            // move sections
            $u1 = $pdo->prepare("UPDATE sections SET course_id = ? WHERE course_id IN ($in)");
            $u1->execute(array_merge([$keep], $toDel));
            $movedSections += $u1->rowCount();
            // move enrollments
            $u2 = $pdo->prepare("UPDATE enrollments SET course_id = ? WHERE course_id IN ($in)");
            try { $u2->execute(array_merge([$keep], $toDel)); $movedEnroll += $u2->rowCount(); } catch (Exception $e) { /* ignore */ }
            // delete dup courses
            $d1 = $pdo->prepare("DELETE FROM courses WHERE id IN ($in)");
            $d1->execute($toDel);
            $removed += count($toDel);
        }
        try { $pdo->exec('ALTER TABLE courses ADD UNIQUE KEY uniq_program_course (program_id, course_code)'); } catch (Exception $e) { /* ignore */ }
        $msg = "Deduped courses. Removed $removed, moved $movedSections section(s) and $movedEnroll enrollment(s).";
    } catch (Exception $e) {
        $error = 'Dedupe courses failed: ' . $e->getMessage();
    }
}

// Handle mass enroll students into sections for selected bucket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mass_enroll') {
    try {
        $program_id = (int)($_POST['program_id'] ?? 0);
        $year_level = (int)($_POST['year_level'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);
        if (!$program_id || !$year_level || !$semester) throw new Exception('Program, year level, and semester are required.');

        // Active school year id
        $row = $pdo->query("SELECT id FROM school_years WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $sy = (int)($row['id'] ?? 0);
        if (!$sy) throw new Exception('No active school year.');

        // Whether enrollments has section_id and ensure unique key to prevent dupes
        $enCols = [];
        try { $enCols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $enCols = []; }
        $hasSectionId = in_array('section_id', $enCols, true);
        if (function_exists('ensure_unique_enrollments_schema')) { ensure_unique_enrollments_schema($pdo); }

        // Students in bucket
        $students = $pdo->prepare("SELECT id FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active') ORDER BY id");
        $students->execute([$program_id, $year_level]);
        $stuIds = $students->fetchAll(PDO::FETCH_COLUMN);
        if (!$stuIds) throw new Exception('No students in selected program/year.');

        // Courses in bucket
        $cst = $pdo->prepare('SELECT id FROM courses WHERE program_id=? AND year_level=? AND semester=? ORDER BY id');
        $cst->execute([$program_id, $year_level, $semester]);
        $courseIds = $cst->fetchAll(PDO::FETCH_COLUMN);
        if (!$courseIds) throw new Exception('No courses found.');

        // Build sections availability per course (local counters)
        $availability = [];
        foreach ($courseIds as $cid) {
            $secRows = $pdo->prepare('SELECT id, capacity FROM sections WHERE course_id=? AND status=\'active\' ORDER BY section_code');
            $secRows->execute([$cid]);
            $secs = $secRows->fetchAll(PDO::FETCH_ASSOC);
            $availability[$cid] = [];
            foreach ($secs as $s) {
                // compute current occupancy for active school year
                if ($hasSectionId) {
                    $occStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=? AND section_id=? AND school_year_id=?');
                    $occStmt->execute([$cid, (int)$s['id'], $sy]);
                    $occ = (int)$occStmt->fetchColumn();
                } else {
                    $occStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id=? AND school_year_id=?');
                    $occStmt->execute([$cid, $sy]);
                    $occ = (int)$occStmt->fetchColumn();
                }
                $availability[$cid][] = [ 'id' => (int)$s['id'], 'cap' => (int)$s['capacity'], 'occ' => $occ ];
            }
        }

        // Helper: pick a section with available slot (least occupied)
        $pickSection = function(array &$list) {
            usort($list, function($a,$b){
                $avA = $a['cap'] - $a['occ'];
                $avB = $b['cap'] - $b['occ'];
                if ($avA === $avB) return $a['occ'] <=> $b['occ'];
                return $avB <=> $avA; // most available first
            });
            foreach ($list as &$s) {
                if ($s['occ'] < $s['cap']) { $s['occ']++; return $s['id']; }
            }
            return null;
        };

        // Enroll all (upsert; attach section to existing unsectioned enrollments)
        $inserted = 0; $skipped = 0; $noSection = 0; $updated = 0;
        foreach ($stuIds as $uid) {
            foreach ($courseIds as $cid) {
                // existing enrollment?
                $ex = $pdo->prepare('SELECT id, section_id FROM enrollments WHERE student_id=? AND course_id=? AND school_year_id=? LIMIT 1');
                $ex->execute([(int)$uid, (int)$cid, $sy]);
                $rowEx = $ex->fetch(PDO::FETCH_ASSOC);

                $secId = null;
                if (!empty($availability[$cid])) { $secId = $pickSection($availability[$cid]); }
                if ($secId === null) { $noSection++; continue; }

                if ($rowEx) {
                    if ($hasSectionId && empty($rowEx['section_id'])) {
                        try {
                            $u = $pdo->prepare('UPDATE enrollments SET section_id=?, status=\'enrolled\' WHERE id=? AND section_id IS NULL');
                            $u->execute([(int)$secId, (int)$rowEx['id']]);
                            if ($u->rowCount() > 0) {
                                $upd = $pdo->prepare('UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id = ?');
                                $upd->execute([(int)$secId]);
                                $updated++;
                            } else { $skipped++; }
                        } catch (Exception $e) {
                            // fallback when column doesn't exist
                            $skipped++;
                        }
                    } else {
                        $skipped++; // already enrolled with section or no section column
                    }
                    continue;
                }
                // Insert new
                $didInsert = false;
                if ($hasSectionId) {
                    try {
                        $ins = $pdo->prepare('INSERT INTO enrollments (student_id, course_id, school_year_id, section_id, status) VALUES (?, ?, ?, ?, \'enrolled\')');
                        $ins->execute([(int)$uid, (int)$cid, $sy, (int)$secId]);
                        $didInsert = $ins->rowCount() > 0;
                    } catch (Exception $e) {
                        // fallback if section_id column doesn't exist
                        $hasSectionId = false;
                    }
                }
                if (!$hasSectionId) {
                    $ins = $pdo->prepare('INSERT INTO enrollments (student_id, course_id, school_year_id, status) VALUES (?, ?, ?, \'enrolled\')');
                    $ins->execute([(int)$uid, (int)$cid, $sy]);
                    $didInsert = $ins->rowCount() > 0;
                }
                if ($didInsert) {
                    if ($hasSectionId) {
                        $upd = $pdo->prepare('UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id = ?');
                        $upd->execute([(int)$secId]);
                    }
                    $inserted++;
                }
            }
        }
$msg = "Auto-enroll complete. Added $inserted enrollment(s)" . ($updated ? ", updated $updated existing" : "") . ($skipped ? ", skipped $skipped" : "") . ($noSection ? ", no-section $noSection" : "") . '.';
    } catch (Exception $e) {
        $error = 'Auto-enroll failed: ' . $e->getMessage();
    }
}

// Fetch sections with room and instructor details
$sections = $pdo->query('
    SELECT s.*, c.course_name, c.course_code, r.room_name, r.room_code,
           p.code AS program_code, p.name AS program_name,
           GROUP_CONCAT(CONCAT(u.first_name, " ", u.last_name) SEPARATOR ", ") as instructors,
           (SELECT COUNT(*) FROM enrollments WHERE section_id = s.id) as enrolled_count
    FROM sections s
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN programs p ON c.program_id = p.id
    LEFT JOIN rooms r ON s.room_id = r.id
    LEFT JOIN instructor_sections ins ON s.id = ins.section_id
    LEFT JOIN users u ON ins.instructor_id = u.id
    GROUP BY s.id
    ORDER BY p.code, c.course_name, s.section_code
')->fetchAll();

// Fetch rooms for dropdowns
$rooms = $pdo->query("SELECT id, room_code, room_name, capacity FROM rooms WHERE status = 'active' ORDER BY room_code")->fetchAll();

// Group courses by program, then year, then semester
$groupedCourses = [];
foreach ($courses as $c) {
    $program = $c['program_name'] ?? 'Unassigned';
    $year = $c['year_level'];
    $semester = $c['semester'];
    $groupedCourses[$program][$year][$semester][] = $c;
}

// Sort semesters within each year (1st, 2nd, Summer)
foreach ($groupedCourses as &$programCourses) {
    foreach ($programCourses as &$yearCourses) {
        ksort($yearCourses);
    }
}

// Define ordinal arrays for display
$yearOrdinals = ['First', 'Second', 'Third', 'Fourth'];
$semesterOrdinals = ['1st', '2nd', 'Summer'];

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Courses - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'courses'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Courses'; require __DIR__ . '/inc/header.php'; ?>

    <div class="card" style="max-width: 600px; margin: 0 auto; border-radius: 10px;">
                    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

<h3>Add Course</h3>
<form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <label>Program</label>
        <select name="program_id" required>
            <option value="">-- choose program --</option>
            <?php foreach ($programs as $p): ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['code'] . ' - ' . $p['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Course Code</label>
        <input type="text" name="course_code" required placeholder="e.g., CS101">
    </div>
    <div class="form-group">
        <label>Course Name</label>
        <input type="text" name="course_name" required placeholder="e.g., Introduction to Computer Science">
    </div>
    <div class="form-group">
        <label>Units</label>
        <input type="number" name="units" min="0" step="1" value="3" required>
    </div>
    <div class="filter-row">
        <div class="filter-group">
            <label>Year Level</label>
            <select name="year_level" required>
                <option value="">-- choose year level --</option>
                <option value="1">1st</option>
                <option value="2">2nd</option>
                <option value="3">3rd</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Semester</label>
            <select name="semester" required>
                <option value="">-- choose semester --</option>
                <option value="1">1st</option>
                <option value="2">2nd</option>
                <option value="3">Summer</option>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label>Instructor (optional)</label>
        <select name="instructor_id">
            <option value="">-- none --</option>
            <?php foreach ($instructors as $i): ?>
                <option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn primary">Add Course</button>
</form>
                </div>

    <div style="height:20px"></div>
    <h3 style="text-align: center; margin-bottom: 20px;">Courses</h3>

    <!-- Filter Form -->
    <div style="max-width: 600px; margin: 0 auto; margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e0e0e0;">
        <h3 style="margin-top: 0; margin-bottom: 15px; color: #6a0dad;">Filter Courses</h3>
        <form method="GET" style="display: flex; gap: 16px; align-items: end; flex-wrap: wrap;">
            <div class="filter-group" style="flex: 1; min-width: 150px;">
                <label>Program</label>
                <select name="filter_program">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['filter_program']) && $_GET['filter_program'] == (string)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['code'] . ' - ' . $p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group" style="flex: 1; min-width: 150px;">
                <label>Year Level</label>
                <select name="filter_year">
                    <option value="">All Years</option>
                    <option value="1" <?php echo (isset($_GET['filter_year']) && $_GET['filter_year'] == '1') ? 'selected' : ''; ?>>1st Year</option>
                    <option value="2" <?php echo (isset($_GET['filter_year']) && $_GET['filter_year'] == '2') ? 'selected' : ''; ?>>2nd Year</option>
                    <option value="3" <?php echo (isset($_GET['filter_year']) && $_GET['filter_year'] == '3') ? 'selected' : ''; ?>>3rd Year</option>
                    <option value="4" <?php echo (isset($_GET['filter_year']) && $_GET['filter_year'] == '4') ? 'selected' : ''; ?>>4th Year</option>
                </select>
            </div>
            <div class="filter-group" style="flex: 1; min-width: 150px;">
                <label>Semester</label>
                <select name="filter_semester">
                    <option value="">All Semesters</option>
                    <option value="1" <?php echo (isset($_GET['filter_semester']) && $_GET['filter_semester'] == '1') ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2" <?php echo (isset($_GET['filter_semester']) && $_GET['filter_semester'] == '2') ? 'selected' : ''; ?>>2nd Semester</option>
                    <option value="3" <?php echo (isset($_GET['filter_semester']) && $_GET['filter_semester'] == '3') ? 'selected' : ''; ?>>Summer</option>
                </select>
            </div>
            <button type="submit" class="btn primary">Filter</button>
            <a href="courses.php" class="btn secondary">Clear Filters</a>
        </form>
    </div>

    <?php
    // Apply filters
    $filteredCourses = $courses;
    if (!empty($_GET['filter_program'])) {
        $filteredCourses = array_filter($filteredCourses, function($c) {
            return $c['program_id'] == $_GET['filter_program'];
        });
    }
    if (!empty($_GET['filter_year'])) {
        $filteredCourses = array_filter($filteredCourses, function($c) {
            return $c['year_level'] == $_GET['filter_year'];
        });
    }
    if (!empty($_GET['filter_semester'])) {
        $filteredCourses = array_filter($filteredCourses, function($c) {
            return $c['semester'] == $_GET['filter_semester'];
        });
    }

    // Group filtered courses
    $groupedCourses = [];
    foreach ($filteredCourses as $c) {
        $program = $c['program_name'] ?? 'Unassigned';
        $year = $c['year_level'];
        $semester = $c['semester'];
        $groupedCourses[$program][$year][$semester][] = $c;
    }

    // Sort semesters within each year (1st, 2nd, Summer)
    foreach ($groupedCourses as &$programCourses) {
        foreach ($programCourses as &$yearCourses) {
            ksort($yearCourses);
        }
    }
    ?>




    <?php if (count($filteredCourses) === 0): ?>
        <div class="card" style="width: 100%; margin: 0 auto;">
            <p>No courses found.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groupedCourses as $program => $programCourses): ?>
            <div class="card" style="width: 100%; margin: 0 auto; margin-bottom: 20px;">
                <h3 style="margin-top:20px; margin-bottom:10px; color:#6a0dad; font-size:24px; text-align:center;"><?php echo htmlspecialchars($program); ?></h3>
                <?php if (!empty($programCourses[0]['program_description'])): ?>
                    <p style="text-align:center; color:#333; font-style:italic; font-size:20px; margin-bottom:20px; font-weight:600; line-height:1.4;"><?php echo htmlspecialchars($programCourses[0]['program_description']); ?></p>
                <?php endif; ?>
                <?php foreach ($programCourses as $year => $yearCourses): ?>
                    <?php foreach ($yearCourses as $semester => $semesterCourses): ?>
                        <h4 style="margin-top:15px; margin-bottom:5px; <?php echo $semester == 1 ? 'color:#6a0dad;' : 'color:#333;'; ?> font-size:20px; text-align:center;"><?php echo htmlspecialchars($yearOrdinals[$year - 1] ?? $year); ?> Year - <?php echo htmlspecialchars($semesterOrdinals[$semester - 1] ?? $semester); ?> Semester</h4>
                        <div class="table-responsive">
                            <table class="report-table">
<thead>
    <tr>
        <th>Course Code</th>
        <th>Course Name</th>
        <th class="text-right">Units</th>
        <th>Actions</th>
    </tr>
</thead>
<tbody>
    <?php foreach ($semesterCourses as $c): ?>
        <tr>
            <td><?php echo htmlspecialchars($c['course_code']); ?></td>
            <td><?php echo htmlspecialchars($c['course_name']); ?></td>
            <td class="text-right"><span class="badge badge-secondary"><?php echo htmlspecialchars($c['units'] ?? ''); ?></span></td>
            <td class="text-nowrap">
<button class="btn sm" onclick="editCourse(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['program_id']); ?>', '<?php echo htmlspecialchars($c['course_code']); ?>', '<?php echo htmlspecialchars($c['course_name']); ?>', '<?php echo htmlspecialchars($c['units'] ?? '3'); ?>', '<?php echo htmlspecialchars($c['year_level']); ?>', '<?php echo htmlspecialchars($c['semester']); ?>', '<?php echo htmlspecialchars($c['instructor_id'] ?? ''); ?>')">Edit</button>
                <form method="POST" style="display:inline-block; margin-left: 8px;" onsubmit="return confirm('Are you sure you want to delete this course?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <button type="submit" class="btn sm danger">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Edit Course</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" id="editProgramId" required>
                        <option value="">-- choose program --</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['code'] . ' - ' . $p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Course Code</label>
                    <input type="text" name="course_code" id="editCourseCode" required placeholder="e.g., CS101">
                </div>
<div class="form-group">
    <label>Course Name</label>
    <input type="text" name="course_name" id="editCourseName" required placeholder="e.g., Introduction to Computer Science">
</div>
<div class="form-group">
    <label>Units</label>
    <input type="number" name="units" id="editUnits" min="0" step="1" value="3" required>
</div>
<div class="filter-row">
    <div class="filter-group">
        <label>Year Level</label>
        <select name="year_level" id="editYearLevel" required>
            <option value="">-- choose year level --</option>
            <option value="1">1st</option>
            <option value="2">2nd</option>
            <option value="3">3rd</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Semester</label>
        <select name="semester" id="editSemester" required>
            <option value="">-- choose semester --</option>
            <option value="1">1st</option>
            <option value="2">2nd</option>
            <option value="3">Summer</option>
        </select>
    </div>
</div>
<div class="form-group">
    <label>Instructor (optional)</label>
    <select name="instructor_id" id="editInstructorId">
        <option value="">-- none --</option>
        <?php foreach ($instructors as $i): ?>
            <option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['name']); ?></option>
        <?php endforeach; ?>
    </select>
</div>
<button type="submit" class="btn primary">Update Course</button>
            </form>
        </div>
    </div>


    <script>
function editCourse(id, programId, courseCode, courseName, units, yearLevel, semester, instructorId) {
    document.getElementById('editId').value = id;
    document.getElementById('editProgramId').value = programId;
    document.getElementById('editCourseCode').value = courseCode;
    document.getElementById('editCourseName').value = courseName;
    document.getElementById('editUnits').value = units || 3;
    document.getElementById('editYearLevel').value = yearLevel;
    document.getElementById('editSemester').value = semester;
    document.getElementById('editInstructorId').value = instructorId || '';
    document.getElementById('editModal').style.display = 'block';
}

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }
        // Modern import UI behavior
        (function(){
            const input = document.getElementById('importFile');
            const drop = document.getElementById('fileDrop');
            const nameEl = document.getElementById('fileName');
            const btn = document.getElementById('importBtn');
            const form = document.getElementById('importForm');
            const prog = document.getElementById('importProgress');
            if (!input) return;
            const fmtSize = b => (b>1024*1024)? (b/1024/1024).toFixed(1)+' MB' : (b/1024).toFixed(0)+' KB';
            const update = () => {
                const f = input.files && input.files[0];
                if (f){
                    nameEl.textContent = `${f.name} • ${fmtSize(f.size)}`;
                } else {
                    nameEl.textContent = '.csv, .xlsx, .xls';
                }
            };
            input.addEventListener('change', update);
            ;['dragover','dragenter'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault(); drop.style.borderColor = 'var(--accent)';}));
            ;['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{drop.style.borderColor = '#e0e0e0';}));
            drop.addEventListener('drop', e=>{ e.preventDefault(); if (e.dataTransfer && e.dataTransfer.files.length){ input.files = e.dataTransfer.files; update(); }});
            form.addEventListener('submit', ()=>{ btn.textContent='Importing...'; btn.setAttribute('disabled','disabled'); prog.style.display='block'; });
            update();
        })();
    </script>

    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .filter-row {
            display: flex;
            gap: 16px;
        }

        .filter-group {
            flex: 1;
        }
        .import-card {
            background: linear-gradient(180deg, #faf5ff 0%, #ffffff 60%);
            border: 1px solid #eadcff;
            box-shadow: 0 6px 16px rgba(107, 33, 168, 0.08);
            padding: 18px 20px;
            text-align: center;
        }
        .import-card h3 { color: #6a0dad; margin: 0 0 8px; }
        .import-desc { margin: 6px 0 14px; color: #333; }
        .import-form input[type="file"] { background: #fff; border: 1px dashed #b985e6; padding: 10px; border-radius: 8px; }
        .template-link { color: #6a0dad; text-decoration: none; border-bottom: 1px dashed #b985e6; }
        .template-link:hover { color: #4b048f; border-bottom-color: #6a0dad; }
    </style>
</body>
</html>
