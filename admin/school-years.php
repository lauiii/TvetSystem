<?php
/**
 * Admin - Manage School Years
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Add school year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $year = sanitize($_POST['year'] ?? '');

    if (empty($year)) {
        $error = 'Year is required.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO school_years (year, status) VALUES (?, ?)');
            $stmt->execute([$year, 'inactive']);
            $msg = 'School year added.';
        } catch (Exception $e) {
            $error = 'Failed to add school year: ' . $e->getMessage();
        }
    }
}

// Set active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_active') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            // set all inactive then set this active
            $pdo->beginTransaction();
            $pdo->exec("UPDATE school_years SET status = 'inactive'");
            $stmt = $pdo->prepare("UPDATE school_years SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            $msg = 'Set active school year.';
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
            <div style="margin-bottom:8px;"><label>Year (e.g. 2024-2025)</label><br><input type="text" name="year" required></div>
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
                        <?php if ($y['status'] !== 'active'): ?>
                        <form method="POST" style="display:inline-block">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="id" value="<?php echo $y['id']; ?>">
                            <button class="btn">Set Active</button>
                        </form>
                        <?php endif; ?>
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
