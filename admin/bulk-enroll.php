<?php
/**
 * Admin bulk enrollment: upload CSV to create student accounts and auto-enroll them
 * Expects CSV columns: First Name, Last Name, Email, Program, Year Level
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/email-functions.php';

$enrollmentResults = [];
$errors = [];
$success = '';
$error = '';

// Fetch programs for manual add form
$programs = $pdo->query('SELECT id, name FROM programs ORDER BY name')->fetchAll();

// CSV processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a valid CSV file.';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($file, 'r')) !== false) {
            $rowNum = 0;
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $rowNum++;
                // Skip header
                if ($rowNum === 1) continue;

                // Expect at least 5 columns
                if (count($row) < 5) {
                    $enrollmentResults[] = ['error' => "Row $rowNum: missing columns"];
                    continue;
                }

                list($firstName, $lastName, $email, $programInput, $yearLevel) = array_map('trim', $row);
                if (empty($firstName) || empty($lastName) || empty($email) || empty($programInput) || empty($yearLevel)) {
                    $enrollmentResults[] = ['error' => "Row $rowNum: one or more required fields are empty"];
                    continue;
                }

                // Find program id
                $program_id = find_program_id($pdo, $programInput);
                if (!$program_id) {
                    $enrollmentResults[] = ['error' => "Row $rowNum: Program not found ($programInput)"];
                    continue;
                }

                // Create user
                $res = create_user($pdo, $firstName, $lastName, $email, $program_id, (int)$yearLevel, 'student');
                if (!$res['success']) {
                    $enrollmentResults[] = ['error' => "Row $rowNum: Could not create user ({$res['error']})"];
                    continue;
                }

                $user_id = $res['id'];
                // Auto-enroll is now handled in create_user function
                $enrolled = $res['enrolled'] ?? 0;

                // Send credentials via email (email-functions.php provides sendStudentCredentials)
                $sent = false;
                if (function_exists('sendStudentCredentials')) {
                    $sent = sendStudentCredentials($email, $firstName, $res['student_id'], $res['password']);
                }

                $enrollmentResults[] = [
                    'success' => "Row $rowNum: Created {$firstName} {$lastName} (ID: {$res['student_id']}) - Enrolled: {$enrolled}, Email: " . ($sent ? 'sent' : 'not sent')
                ];
            }
            fclose($handle);
            $success = "CSV processing complete. " . count($enrollmentResults) . " rows processed.";
        } else {
            $error = 'Unable to open uploaded file.';
        }
    }
}

// Manual add handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual'])) {
    try {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $programId = (int)($_POST['program_id'] ?? 0);
        $yearLevel = (int)($_POST['year_level'] ?? 0);

        if (empty($firstName) || empty($lastName) || empty($email) || !$programId || !$yearLevel) {
            throw new Exception('All fields are required for manual enrollment.');
        }

        $res = create_user($pdo, $firstName, $lastName, $email, $programId, $yearLevel, 'student');
        if (!$res['success']) throw new Exception($res['error'] ?? 'Failed to create user');

        // Auto-enroll is now handled in create_user function
        $enrolled = $res['enrolled'] ?? 0;

        if (function_exists('sendStudentCredentials')) {
            sendStudentCredentials($email, $firstName, $res['student_id'], $res['password']);
        }

        $success = "Student enrolled successfully! Student ID: {$res['student_id']} - Enrolled in {$enrolled} courses";
        $enrollmentResults[] = ['success' => $success];

    } catch (Exception $e) {
        $error = 'Manual enrollment failed: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enroll - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'bulk-enroll'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Enroll Students'; require __DIR__ . '/inc/header.php'; ?>
                <div class="card">
                    <p>Download the template, fill in rows, then upload the CSV. The system will create student accounts, auto-enroll them into courses matching program & year, and attempt to send credentials by email.</p>

                    <p>
                        <a class="btn" href="download-template.php?raw=1">Download CSV Template</a>
                    </p>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="csv_file">CSV File</label>
                            <div class="modern-file-upload">
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" onchange="updateFileName(this)">
                                <label for="csv_file" class="file-upload-label">
                                    <span class="file-icon">📄</span>
                                    <span class="file-text" id="file-name">Choose CSV file</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <button class="btn primary">Upload and Process</button>
                        </div>
                    </form>
                    
                    <script>
                    function updateFileName(input) {
                        const fileName = input.files[0]?.name || 'Choose CSV file';
                        document.getElementById('file-name').textContent = fileName;
                    }
                    </script>

                    <?php foreach ($enrollmentResults as $r): ?>
                        <?php if (isset($r['success'])): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($r['success']); ?></div>
                        <?php else: ?>
                            <div class="alert alert-error"><?php echo htmlspecialchars($r['error']); ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                </div>

                <div class="card" style="margin-top:16px">
                    <h3>Manual Add Student</h3>
                    <form method="POST">
                        <input type="hidden" name="add_manual" value="1">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Program</label>
                            <select name="program_id" required>
                                <option value="">-- choose program --</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year Level</label>
                            <select name="year_level" required>
                                <option value="">-- choose year level --</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                            </select>
                        </div>
                        <div class="form-group"><button class="btn primary">Enroll Student</button></div>
                    </form>
                </div>

            </div>
        </main>
    </div>
</body>
</html>
