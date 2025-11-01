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
    $course_code = sanitize($_POST['course_code'] ?? '');
    $course_name = sanitize($_POST['course_name'] ?? '');
    $units = (int)($_POST['units'] ?? 3);
    $year_level = (int)($_POST['year_level'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 1);
    $instructor_id = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;

    if (!$program_id || empty($course_code) || empty($course_name)) {
        $error = 'Program, course code and name are required.';
    } else {
        try {
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
        // cache programs by code
        $progIdx = [];
        $progRows = $pdo->query("SELECT id, code FROM programs")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($progRows as $pr) { $progIdx[strtolower($pr['code'])] = (int)$pr['id']; }
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
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp);
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
    $section_code = sanitize($_POST['section_code'] ?? '');
    $section_name = sanitize($_POST['section_name'] ?? '');
    $capacity = (int)($_POST['capacity'] ?? 30);
    $room_id = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
    $instructors = $_POST['instructors'] ?? [];

    if (!$course_id || empty($section_code)) {
        $error = 'Course and section code are required.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO sections (course_id, section_code, section_name, capacity, room_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$course_id, $section_code, $section_name, $capacity, $room_id]);
            $section_id = $pdo->lastInsertId();

            // Insert instructors
            if (!empty($instructors)) {
                $stmt = $pdo->prepare('INSERT INTO instructor_sections (section_id, instructor_id) VALUES (?, ?)');
                foreach ($instructors as $instructor_id) {
                    $stmt->execute([$section_id, (int)$instructor_id]);
                }
            }
            $pdo->commit();
            $msg = 'Section added successfully.';
        } catch (Exception $e) {
            $pdo->rollBack();
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

// Fetch courses with program details and sections
$courses = $pdo->query('SELECT c.*, p.name as program_name, p.code as program_code, p.description as program_description FROM courses c LEFT JOIN programs p ON c.program_id = p.id ORDER BY p.name, c.year_level, c.course_name')->fetchAll();

// Fetch sections with room and instructor details
$sections = $pdo->query('
    SELECT s.*, c.course_name, c.course_code, r.room_name, r.room_code,
           GROUP_CONCAT(CONCAT(u.first_name, " ", u.last_name) SEPARATOR ", ") as instructors,
           (SELECT COUNT(*) FROM enrollments WHERE section_id = s.id) as enrolled_count
    FROM sections s
    LEFT JOIN courses c ON s.course_id = c.id
    LEFT JOIN rooms r ON s.room_id = r.id
    LEFT JOIN instructor_sections ins ON s.id = ins.section_id
    LEFT JOIN users u ON ins.instructor_id = u.id
    GROUP BY s.id
    ORDER BY c.course_name, s.section_code
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

    <!-- Import Courses -->
    <div class="card import-card" style="max-width: 100%; margin: 20px auto; border-radius: 12px;">
        <h3>Import Courses</h3>
        <p class="import-desc">Upload a CSV/Excel file with columns: <code>program_code</code>, <code>course_code</code>, <code>course_name</code>, <code>units</code>, <code>year_level</code>, <code>semester</code>.</p>
        <form method="POST" enctype="multipart/form-data" class="import-form" style="display:flex; gap:16px; align-items:end; flex-wrap:wrap;">
            <input type="hidden" name="action" value="import">
            <div class="form-group" style="min-width:320px;">
                <label>File (.csv, .xlsx, .xls)</label>
                <input type="file" name="csv_file" accept=".csv,.xlsx,.xls" required>
                <div class="small" style="margin-top:8px; display:flex; gap:12px; flex-wrap:wrap;">
                    <a class="template-link" href="../assets/templates/courses_import_template.csv" download>Download template (CSV)</a>
                    <a class="template-link" href="download_courses_template_excel.php">Download template (Excel)</a>
                </div>
            </div>
            <button type="submit" class="btn primary">Import</button>
        </form>
        <div class="small" style="margin-top:10px; color:#444;">
            Tip: For Excel, keep headers exact. Units/year/semester should be numbers.
        </div>
    </div>

    <!-- Sections Management -->
    <div class="card" style="max-width: 100%; margin: 20px auto; border-radius: 10px;">
        <h3>Course Sections</h3>
        <p>Manage sections for courses, assign rooms, set capacities, and assign multiple instructors.</p>

        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Section</th>
                        <th>Room</th>
                        <th>Capacity</th>
                        <th>Enrolled</th>
                        <th>Instructors</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['course_code'] . ' - ' . $s['course_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['section_code']); ?></td>
                            <td><?php echo htmlspecialchars($s['room_code'] ?? 'No Room'); ?></td>
                            <td><?php echo htmlspecialchars($s['capacity']); ?></td>
                            <td><?php echo htmlspecialchars($s['enrolled_count']); ?>/<?php echo htmlspecialchars($s['capacity']); ?></td>
                            <td><?php echo htmlspecialchars($s['instructors'] ?? 'None'); ?></td>
                            <td>
                                <button class="btn" onclick="editSection(<?php echo $s['id']; ?>)">Edit</button>
                                <button class="btn danger" onclick="deleteSection(<?php echo $s['id']; ?>)">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

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
        <th>Units</th>
        <th>Actions</th>
    </tr>
</thead>
<tbody>
    <?php foreach ($semesterCourses as $c): ?>
        <tr>
            <td><?php echo htmlspecialchars($c['course_code']); ?></td>
            <td><?php echo htmlspecialchars($c['course_name']); ?></td>
            <td><?php echo htmlspecialchars($c['units'] ?? ''); ?></td>
            <td style="display: flex; align-items: center; padding: 8px;">
<button class="btn" onclick="editCourse(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['program_id']); ?>', '<?php echo htmlspecialchars($c['course_code']); ?>', '<?php echo htmlspecialchars($c['course_name']); ?>', '<?php echo htmlspecialchars($c['units'] ?? '3'); ?>', '<?php echo htmlspecialchars($c['year_level']); ?>', '<?php echo htmlspecialchars($c['semester']); ?>', '<?php echo htmlspecialchars($c['instructor_id'] ?? ''); ?>')">Edit</button>
                <button class="btn secondary" onclick="addSection(<?php echo $c['id']; ?>)">Add Section</button>
                <form method="POST" style="margin-left: 8px;" onsubmit="return confirm('Are you sure you want to delete this course?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                    <button type="submit" class="btn danger">Delete</button>
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

    <!-- Section Modal -->
    <div id="sectionModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="sectionModalTitle">Add Section</h3>
            <form method="POST">
                <input type="hidden" name="action" id="sectionAction" value="add_section">
                <input type="hidden" name="section_id" id="sectionId" value="">
                <input type="hidden" name="course_id" id="sectionCourseId" value="">
                <div class="form-group">
                    <label>Section Code</label>
                    <input type="text" name="section_code" id="sectionCode" required placeholder="e.g., A, B, 01">
                </div>
                <div class="form-group">
                    <label>Section Name (optional)</label>
                    <input type="text" name="section_name" id="sectionName" placeholder="e.g., Morning Section">
                </div>
                <div class="form-group">
                    <label>Capacity</label>
                    <input type="number" name="capacity" id="sectionCapacity" required min="1" value="30">
                </div>
                <div class="form-group">
                    <label>Room (optional)</label>
                    <select name="room_id" id="sectionRoomId">
                        <option value="">-- no room --</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['room_code'] . ' - ' . $r['room_name'] . ' (Cap: ' . $r['capacity'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Instructors (multiple allowed)</label>
                    <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 8px; border-radius: 4px;">
                        <?php foreach ($instructors as $i): ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="instructors[]" value="<?php echo $i['id']; ?>">
                                <?php echo htmlspecialchars($i['name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn primary">Save Section</button>
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

        function addSection(courseId) {
            document.getElementById('sectionCourseId').value = courseId;
            document.getElementById('sectionModalTitle').textContent = 'Add Section';
            document.getElementById('sectionAction').value = 'add_section';
            document.getElementById('sectionId').value = '';
            document.getElementById('sectionCode').value = '';
            document.getElementById('sectionName').value = '';
            document.getElementById('sectionCapacity').value = '30';
            document.getElementById('sectionRoomId').value = '';
            // Clear instructor checkboxes
            document.querySelectorAll('input[name="instructors[]"]').forEach(cb => cb.checked = false);
            document.getElementById('sectionModal').style.display = 'block';
        }

        function editSection(sectionId) {
            // Fetch section data via AJAX or use existing data
            // For now, we'll assume we need to fetch it
            fetch('get_section.php?id=' + sectionId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('sectionModalTitle').textContent = 'Edit Section';
                    document.getElementById('sectionAction').value = 'edit_section';
                    document.getElementById('sectionId').value = data.id;
                    document.getElementById('sectionCourseId').value = data.course_id;
                    document.getElementById('sectionCode').value = data.section_code;
                    document.getElementById('sectionName').value = data.section_name || '';
                    document.getElementById('sectionCapacity').value = data.capacity;
                    document.getElementById('sectionRoomId').value = data.room_id || '';
                    // Set instructor checkboxes
                    document.querySelectorAll('input[name="instructors[]"]').forEach(cb => {
                        cb.checked = data.instructors.includes(parseInt(cb.value));
                    });
                    document.getElementById('sectionModal').style.display = 'block';
                })
                .catch(error => alert('Error loading section data'));
        }

        function deleteSection(sectionId) {
            if (confirm('Are you sure you want to delete this section?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_section">
                    <input type="hidden" name="section_id" value="${sectionId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('sectionModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
            if (event.target == document.getElementById('sectionModal')) {
                closeModal();
            }
        }
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
        }
        .import-card h3 { color: #6a0dad; margin: 0 0 8px; }
        .import-desc { margin: 6px 0 14px; color: #333; }
        .import-form input[type="file"] { background: #fff; border: 1px dashed #b985e6; padding: 10px; border-radius: 8px; }
        .template-link { color: #6a0dad; text-decoration: none; border-bottom: 1px dashed #b985e6; }
        .template-link:hover { color: #4b048f; border-bottom-color: #6a0dad; }
    </style>
</body>
</html>
