<?php
/**
 * Reset password page — set a new password
 */
require_once __DIR__ . '/config.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$error=''; $info='';
$email = '';

if ($token === '') {
    $error = 'Invalid reset link.';
} else {
    try {
        // Lookup token
        $stmt = $pdo->prepare("SELECT email, expires_at FROM password_resets WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            $error = 'Invalid or expired reset link.';
        } else {
            if (strtotime($row['expires_at']) < time()) {
                $error = 'Reset link has expired.';
            } else {
                $email = $row['email'];
            }
        }
    } catch (Exception $e) {
        $error = 'Unable to validate reset link.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    if (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at >= NOW() LIMIT 1");
            $stmt->execute([$token]);
            $row = $stmt->fetch();
            if (!$row) { $error = 'Invalid or expired reset link.'; }
            else {
                $email = $row['email'];
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE email = ? LIMIT 1");
                $upd->execute([$hash, $email]);
                // Cleanup token(s)
                $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
                $info = 'Your password has been updated. You can now log in.';
            }
        } catch (Exception $e) {
            $error = 'Failed to reset password.';
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reset Password — <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <style>
        body{font-family:Segoe UI,Arial;background:#f3f4f6;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.08);padding:22px;max-width:420px;width:100%}
        h1{margin:0 0 10px;color:#6a0dad}
        .form-group{margin:12px 0}
        input[type=password]{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px}
        .btn{background:#6a0dad;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}
        .alert{padding:10px;border-radius:8px;margin:10px 0}
        .success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
        .error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        a{color:#6a0dad}
    </style>
</head>
<body>
<div class="card">
    <h1>Reset Password</h1>
    <?php if ($info): ?><div class="alert success"><?php echo htmlspecialchars($info); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!$info): ?>
    <form method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="password" required>
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="password2" required>
        </div>
        <button class="btn" type="submit">Update Password</button>
    </form>
    <div style="margin-top:10px"><a href="login.php">Back to Login</a></div>
    <?php endif; ?>
</div>
</body>
</html>
