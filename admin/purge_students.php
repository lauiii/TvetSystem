<?php
/**
 * Admin — Purge all student accounts and related data
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$stats = [
    'students' => 0,
    'enrollments' => 0,
    'grades' => 0,
    'password_resets' => 0,
];

try {
    // Count students
    $stats['students'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    // Count enrollments for students
    $stats['enrollments'] = (int)$pdo->query("SELECT COUNT(*) FROM enrollments e INNER JOIN users u ON e.student_id=u.id WHERE u.role='student'")->fetchColumn();
    // Count grades tied to those enrollments
    $stats['grades'] = (int)$pdo->query("SELECT COUNT(*) FROM grades g INNER JOIN enrollments e ON g.enrollment_id=e.id INNER JOIN users u ON e.student_id=u.id WHERE u.role='student'")->fetchColumn();
    // Count password_resets for student emails
    $stats['password_resets'] = (int)$pdo->query("SELECT COUNT(*) FROM password_resets pr INNER JOIN users u ON pr.email=u.email WHERE u.role='student'")->fetchColumn();
} catch (Exception $e) {
    // ignore
}

$done = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === 'DELETE') {
    try {
        $pdo->beginTransaction();
        // Delete grades for student enrollments
        $pdo->exec("DELETE g FROM grades g INNER JOIN enrollments e ON g.enrollment_id = e.id INNER JOIN users u ON e.student_id = u.id WHERE u.role='student'");
        // Delete enrollments for students
        $pdo->exec("DELETE e FROM enrollments e INNER JOIN users u ON e.student_id = u.id WHERE u.role='student'");
        // Delete password resets for student emails
        try { $pdo->exec("DELETE pr FROM password_resets pr INNER JOIN users u ON pr.email=u.email WHERE u.role='student'"); } catch (Exception $e) { /* table may not exist */ }
        // Finally delete users (students)
        $pdo->exec("DELETE FROM users WHERE role='student'");
        $pdo->commit();
        $done = true;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Failed to purge: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purge Students — Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <style>
        .danger-box{background:#fff5f5;border:1px solid #fecaca;border-radius:10px;padding:14px}
        .metrics{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
        .metric{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px}
        .metric strong{display:block;color:#111}
        .confirm{margin-top:12px;display:flex;gap:8px;align-items:center}
    </style>
</head>
<body>
<div class="admin-layout">
    <?php $active = 'students'; require __DIR__ . '/inc/sidebar.php'; ?>
    <main class="main-content">
        <div class="container">
            <?php $pageTitle = 'Purge Students'; require __DIR__ . '/inc/header.php'; ?>
            <div class="card" style="max-width:720px;">
                <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($done): ?><div class="alert alert-success">All student accounts and related data have been deleted.</div><?php endif; ?>
                <div class="danger-box">
                    <h3 style="margin:0 0 6px;color:#b91c1c">Danger Zone</h3>
                    <p>This will permanently delete all students and associated data:</p>
                    <div class="metrics">
                        <div class="metric"><strong><?php echo (int)$stats['students']; ?></strong> students</div>
                        <div class="metric"><strong><?php echo (int)$stats['enrollments']; ?></strong> enrollments</div>
                        <div class="metric"><strong><?php echo (int)$stats['grades']; ?></strong> grades</div>
                        <div class="metric"><strong><?php echo (int)$stats['password_resets']; ?></strong> reset tokens</div>
                    </div>
                    <form method="POST" class="confirm" onsubmit="return confirm('This will delete ALL students. Continue?');">
                        <label>Type DELETE to confirm</label>
                        <input name="confirm" required style="padding:8px;border:1px solid #e5e7eb;border-radius:6px">
                        <button class="btn danger" type="submit">Delete All Students</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
