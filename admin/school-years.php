<?php
/**
 * Admin - Manage School Years
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Ensure schema: add active_semester column if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM school_years")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('active_semester', $cols, true)) {
        $pdo->exec("ALTER TABLE school_years ADD COLUMN active_semester TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
    }
} catch (Exception $e) { /* ignore */ }

// Add school year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $year = sanitize($_POST['year'] ?? '');
    $sem = (int)($_POST['active_semester'] ?? 1);

    if (empty($year)) {
        $error = 'Year is required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO school_years (year, status, active_semester) VALUES (?, ?, ?)');
            $stmt->execute([$year, 'inactive', in_array($sem,[1,2,3],true)?$sem:1]);
            $msg = 'School year added.';
        } catch (Exception $e) {
            $error = 'Failed to add school year: ' . $e->getMessage();
        }
    }
}

// Set active and optionally update active semester
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_active') {
    $id = (int)($_POST['id'] ?? 0);
    $sem = isset($_POST['active_semester']) ? (int)$_POST['active_semester'] : null;
    if ($id > 0) {
        try {
            // set all inactive then set this active; optionally set semester
            $pdo->beginTransaction();
            $pdo->exec("UPDATE school_years SET status = 'inactive'");
            if ($sem !== null && in_array($sem,[1,2,3],true)) {
                $stmt = $pdo->prepare("UPDATE school_years SET status = 'active', active_semester = ? WHERE id = ?");
                $stmt->execute([$sem, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE school_years SET status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
            }
            $pdo->commit();
            $msg = 'Set active school year' . ($sem?" (Semester $sem)":"") . '.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to set active: ' . $e->getMessage();
        }
    }
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM school_years WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Deleted school year.';
        } catch (Exception $e) {
            $error = 'Failed to delete: ' . $e->getMessage();
        }
    }
}

// Fetch
// Dynamically check for 'semester' column before ordering by it
$schoolYearCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM school_years");
    $schoolYearCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

$orderBy = 'year DESC';
if (in_array('semester', $schoolYearCols)) {
    $orderBy .= ', semester DESC';
}
$years = $pdo->query("SELECT * FROM school_years ORDER BY $orderBy")->fetchAll();

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>School Years - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'school-years'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'School Years'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card">
        <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

        <h3>Add School Year</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row"><label>Year (e.g. 2024-2025)</label><br><input type="text" name="year" required></div>
            <div class="form-row"><label>Default Active Semester</label><br>
                <select name="active_semester" required>
                    <option value="1">1st Semester</option>
                    <option value="2">2nd Semester</option>
                    <option value="3">Summer</option>
                </select>
            </div>
            <button class="btn primary">Add School Year</button>
        </form>
    </div>

    <div style="height:20px"></div>
    <div class="card">
        <h3>Existing School Years</h3>
        <?php if (count($years) === 0): ?>
            <p>No school years found.</p>
        <?php else: ?>
            <?php foreach ($years as $y): ?>
                <div class="list-row">
                    <div>
                        <strong><?php echo htmlspecialchars($y['year']); ?></strong>
                        <div style="color:#666;font-size:13px">Status: <?php echo htmlspecialchars($y['status']); ?></div>
                    </div>
                    <div>
                        <form method="POST" style="display:inline-block">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="id" value="<?php echo $y['id']; ?>">
                            <select name="active_semester" style="margin-right:8px">
                                <?php $semCur = (int)($y['active_semester'] ?? 1); ?>
                                <option value="1" <?php echo $semCur===1?'selected':''; ?>>1st</option>
                                <option value="2" <?php echo $semCur===2?'selected':''; ?>>2nd</option>
                                <option value="3" <?php echo $semCur===3?'selected':''; ?>>Summer</option>
                            </select>
                            <button class="btn"><?php echo $y['status'] === 'active' ? 'Update Semester' : 'Set Active'; ?></button>
                        </form>
                        <form method="POST" style="display:inline-block;margin-left:8px" onsubmit="return confirm('Delete school year?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $y['id']; ?>">
                            <button class="btn" style="background:#e74c3c">Delete</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
            </div>
        </main>
    </div>
</body>
</html>
