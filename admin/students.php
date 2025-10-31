<?php
/**
 * Admin - Students list
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

// basic search
$q = trim($_GET['q'] ?? '');

// school years for filtering
$schoolYears = $pdo->query("SELECT id, year, status FROM school_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeSY = null; foreach ($schoolYears as $sy) { if (($sy['status'] ?? '') === 'active') { $activeSY = $sy; break; } }
$syId = isset($_GET['sy_id']) ? (int)$_GET['sy_id'] : (int)($activeSY['id'] ?? 0);

// Determine available user columns to build a safe SELECT and WHERE
$userCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // if SHOW COLUMNS fails, fall back to assume common columns
    $userCols = ['id','student_id','first_name','last_name','name','email','year_level','program_id','status','created_at'];
}

$selectParts = [];
$selectParts[] = "u.id";
$selectParts[] = "COALESCE(u.student_id, '') AS student_id";
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $selectParts[] = "u.first_name";
    $selectParts[] = "u.last_name";
} elseif (in_array('name', $userCols)) {
    $selectParts[] = "u.name AS full_name";
}
if (in_array('email', $userCols)) $selectParts[] = "u.email";
if (in_array('year_level', $userCols)) $selectParts[] = "u.year_level";
if (in_array('program_id', $userCols)) $selectParts[] = "u.program_id";
if (in_array('status', $userCols)) $selectParts[] = "u.status";
if (in_array('created_at', $userCols)) $selectParts[] = "u.created_at";
$selectParts[] = "p.name AS program_name";

$select = implode(', ', $selectParts);

$sql = "SELECT $select FROM users u 
LEFT JOIN programs p ON u.program_id = p.id
INNER JOIN enrollments e ON e.student_id = u.id AND e.school_year_id = :syid";

$params = [':syid' => $syId];
if ($q !== '') {
    // Build WHERE parts depending on which columns exist
    $whereParts = [];
    if (in_array('student_id', $userCols)) $whereParts[] = "u.student_id LIKE :q";
    if (in_array('email', $userCols)) $whereParts[] = "u.email LIKE :q";
    if (in_array('first_name', $userCols)) $whereParts[] = "u.first_name LIKE :q";
    if (in_array('last_name', $userCols)) $whereParts[] = "u.last_name LIKE :q";
    if (in_array('name', $userCols)) $whereParts[] = "u.name LIKE :q";
    if (!empty($whereParts)) {
        $sql .= ' AND (' . implode(' OR ', $whereParts) . ')';
        $params[':q'] = "%$q%";
    }
}

$sql .= " WHERE u.role = 'student' ORDER BY u.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Students - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'students'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Students'; require __DIR__ . '/inc/header.php'; ?>
                <div class="card">
<div class="toolbar" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
    <form method="GET" style="display:flex; gap:8px; align-items:end; flex:1;">
        <div>
            <label style="display:block; font-size:12px; color:#555;">School Year</label>
            <select name="sy_id" onchange="this.form.submit()">
                <?php foreach ($schoolYears as $sy): ?>
                    <option value="<?php echo (int)$sy['id']; ?>" <?php echo ((int)$sy['id'] === (int)$syId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sy['year'] . (($sy['status'] ?? '')==='active' ? ' (Active)' : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;">
            <label style="display:block; font-size:12px; color:#555;">Search</label>
            <div style="display:flex;">
                <input class="search-input" name="q" placeholder="Search by name, email or student ID" value="<?php echo htmlspecialchars($q); ?>" style="flex:1;">
                <button class="btn primary" style="margin-left:8px;">Search</button>
            </div>
        </div>
    </form>
    <a class="btn" href="resend_passwords.php">Resend Passwords</a>
    <a class="btn" href="download-template.php?raw=1">Download Template</a>
</div>

                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($students) === 0): ?>
                                    <tr><td colspan="6" style="text-align:center;color:#999">No students found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $s): ?>
                                        <?php
                                            $sid = $s['student_id'] ?? 'N/A';
                                            if (!empty($s['first_name']) || !empty($s['last_name'])) {
                                                $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: ($s['full_name'] ?? 'N/A');
                                            } else {
                                                $name = $s['full_name'] ?? ($s['email'] ?? 'N/A');
                                            }
                                            $program = $s['program_name'] ?? 'N/A';
                                            $email = $s['email'] ?? 'N/A';
                                            $year = $s['year_level'] ?? 'N/A';
                                            $status = $s['status'] ?? 'N/A';
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sid); ?></td>
                                            <td><?php echo htmlspecialchars($name); ?></td>
                                            <td><?php echo htmlspecialchars($email); ?></td>
                                            <td><?php echo htmlspecialchars($program); ?></td>
                                            <td><?php echo htmlspecialchars($year); ?></td>
                                            <td><?php echo htmlspecialchars($status); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <style>
        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .report-table th {
            background: #6a0dad;
            color: white;
            font-weight: 600;
        }

        .report-table tr:hover {
            background: #f8f9fa;
        }

        @media (max-width: 768px) {
            .table-responsive {
                font-size: 14px;
            }

            .report-table th,
            .report-table td {
                padding: 8px;
            }
        }
    </style>
</body>
</html>
