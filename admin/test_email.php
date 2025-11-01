<?php
/**
 * Admin: Send a test email using current SMTP settings.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

$to = isset($_POST['to']) ? trim($_POST['to']) : '';
$sent = null; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid recipient email.';
    } else {
        try {
            $ok = sendNotificationEmail($to, 'Admin', 'Test Email — ' . SITE_NAME, '<p>This is a test email from ' . htmlspecialchars(SITE_NAME) . '.</p>');
            $sent = $ok;
            if (!$ok) $error = 'Send failed (see logs).';
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Test Email</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php $active = ''; require __DIR__ . '/inc/sidebar.php'; ?>
    <main class="main-content">
        <div class="container">
            <?php $pageTitle = 'Test Email'; require __DIR__ . '/inc/header.php'; ?>
            <div class="card" style="max-width:520px; margin:0 auto;">
                <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($sent): ?><div class="alert alert-success">Sent!</div><?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Recipient</label>
                        <input type="email" name="to" value="<?php echo htmlspecialchars($to ?: (function(){ require_once __DIR__.'/../include/email-functions.php'; return get_admin_email($GLOBALS['pdo'] ?? null); })()); ?>" required>
                    </div>
                    <button class="btn primary" type="submit">Send Test</button>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
