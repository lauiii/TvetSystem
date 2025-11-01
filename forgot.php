<?php
/**
 * Forgot password page — request reset link
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/include/email-functions.php';

$info = '';$error='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (email), INDEX (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Check user exists and active
            $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if (!$u) {
                // do not reveal existence
                $info = 'If that email is registered, a reset link has been sent.';
            } else {
                // Create token
                $token = bin2hex(random_bytes(32)); // 64 chars
                $expires = date('Y-m-d H:i:s', time()+3600); // 1 hour
                $ins = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
                $ins->execute([$email, $token, $expires]);
                $name = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: 'User';
                $resetUrl = SITE_URL . '/reset_password.php?token=' . urlencode($token);
                $subject = 'Password Reset Request';
                $body = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
                      . '<p>We received a request to reset your password. Use the link below within 1 hour:</p>'
                      . '<p><a href="' . htmlspecialchars($resetUrl) . '">' . htmlspecialchars($resetUrl) . '</a></p>'
                      . '<p>If you did not request this, ignore this email.</p>';
                @sendNotificationEmail($email, $name, $subject, $body);
                $info = 'If that email is registered, a reset link has been sent.';
            }
        } catch (Exception $e) {
            error_log('forgot.php error: ' . $e->getMessage());
            $error = 'Unable to process your request right now.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Forgot Password — <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="icon" type="image/svg+xml" href="public/assets/icon/logo.svg">
    <style>
        body{font-family:Segoe UI,Arial;background:#f3f4f6;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:22px;max-width:420px;width:100%}
        h1{margin:0 0 10px;color:#6a0dad}
        .form-group{margin:12px 0}
        input[type=email]{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px}
        .btn{background:#6a0dad;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
        .alert{padding:10px;border-radius:8px;margin:10px 0}
        .success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
        .error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        a{color:#6a0dad}
    </style>
</head>
<body>
<div class="card">
    <h1>Forgot Password</h1>
    <p>Enter your email to receive a reset link.</p>
    <?php if ($info): ?><div class="alert success"><?php echo htmlspecialchars($info); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        <button class="btn" type="submit">Send Reset Link</button>
    </form>
    <div style="margin-top:10px"><a href="login.php">Back to Login</a></div>
</div>
</body>
</html>
