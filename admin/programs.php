<?php
/**
 * Admin - Manage Programs
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Detect programs table columns so we can adapt to schema variations
$programCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM programs");
    $programCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // ignore
}

// Handle add program
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $name = sanitize($_POST['name'] ?? '');
    $code = strtoupper(sanitize($_POST['code'] ?? ''));
    $description = sanitize($_POST['description'] ?? '');

    // Build insert dynamically based on available columns
    $insertCols = [];
    $placeholders = [];
    $values = [];

    if (in_array('name', $programCols)) {
        $insertCols[] = 'name'; $placeholders[] = '?'; $values[] = $name;
    }
    if (in_array('code', $programCols)) {
        $insertCols[] = 'code'; $placeholders[] = '?'; $values[] = $code;
    }
    if (in_array('description', $programCols)) {
        $insertCols[] = 'description'; $placeholders[] = '?'; $values[] = $description;
    }

    // Validation: require name; require code only if column exists
    if (empty($name) || (in_array('code', $programCols) && empty($code))) {
        $error = 'Name' . (in_array('code', $programCols) ? ' and code' : '') . ' are required.';
    } else {
        try {
            if (count($insertCols) === 0) throw new Exception('No writable columns found in programs table.');
            $sql = 'INSERT INTO programs (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
            $msg = 'Program added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add program: ' . $e->getMessage();
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM programs WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Program deleted.';
        } catch (Exception $e) {
            $error = 'Failed to delete program: ' . $e->getMessage();
        }
    }
}

// Fetch programs
$programs = [];
try {
    $stmt = $pdo->query('SELECT * FROM programs ORDER BY name');
    $programs = $stmt->fetchAll();
} catch (Exception $e) {
    $error = $error ?: 'Failed to load programs: ' . $e->getMessage();
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Programs - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'programs'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Programs'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card">
                <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

                <h3>Add Program</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <?php if (in_array('name', $programCols)): ?>
                        <div class="form-row"><label>Program Name</label><br><input type="text" name="name" required placeholder="Diploma in Information Technology"></div>
                    <?php endif; ?>
                    <?php if (in_array('code', $programCols)): ?>
                        <div class="form-row"><label>Program Code (e.g. DIT)</label><br><input type="text" name="code" required placeholder="DIT"></div>
                    <?php endif; ?>
                    <button class="btn primary">Add Program</button>
                </form>
            </div>

            <div style="height:20px"></div>
            <div class="card">
                <h3>Existing Programs</h3>
                <?php if (count($programs) === 0): ?>
                    <p>No programs found.</p>
                <?php else: ?>
                    <?php foreach ($programs as $p): ?>
                        <div class="list-row">
                            <div>
                                <?php
                                    // Prefer showing a human name if available, otherwise use the code as title.
                                    $title = '';
                                    if (!empty($p['name'])) {
                                        $title = $p['name'];
                                    } elseif (!empty($p['code'])) {
                                        $title = $p['code'];
                                    }
                                ?>
                                <strong><?php echo htmlspecialchars($title); ?></strong>
                                <?php if (!empty($p['name']) && !empty($p['code'])): ?>
                                    <span style="color:#666">(<?php echo htmlspecialchars($p['code']); ?>)</span>
                                <?php endif; ?>
                                <?php if (!empty($p['description'])): ?>
                                    <div style="color:#666;font-size:13px"><?php echo htmlspecialchars($p['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <form method="POST" style="display:inline-block" onsubmit="return confirm('Delete program? This will delete related courses.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                    <button class="btn" style="background:#e74c3c">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>
