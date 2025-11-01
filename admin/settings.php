<?php
/**
 * Admin Settings — update admin email and password
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$adminId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Fetch current admin
$stmt = $pdo->prepare("SELECT first_name, last_name, email, password FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
$stmt->execute([$adminId]);
$admin = $stmt->fetch();
if (!$admin) { die('Admin not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newEmail = trim($_POST['email'] ?? '');
    $currentPass = (string)($_POST['current_password'] ?? '');
    $newPass = (string)($_POST['new_password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email.';
    } else {
        try {
            // Update email
            if ($newEmail !== $admin['email']) {
                $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $chk->execute([$newEmail, $adminId]);
                if ($chk->fetch()) {
                    throw new Exception('Email is already in use.');
                }
                $upd = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                $upd->execute([$newEmail, $adminId]);
                $_SESSION['email'] = $newEmail;
            }
            // Update password if provided
            if ($newPass !== '' || $confirmPass !== '') {
                if ($newPass !== $confirmPass) {
                    throw new Exception('New password and confirmation do not match.');
                }
                if (strlen($newPass) < 8) {
                    throw new Exception('New password must be at least 8 characters.');
                }
                if (!password_verify($currentPass, $admin['password'])) {
                    throw new Exception('Current password is incorrect.');
                }
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->execute([$hash, $adminId]);
            }
            $msg = 'Settings updated.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Settings - Admin</title>
  <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
  <div class="admin-layout">
    <?php $active = 'settings'; require __DIR__ . '/inc/sidebar.php'; ?>

    <main class="main-content">
      <div class="container">
        <?php $pageTitle = 'Settings'; require __DIR__ . '/inc/header.php'; ?>

        <div class="card" style="max-width:560px;margin:0 auto;">
          <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

          <h3>Account</h3>
          <form method="POST">
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required class="form-control">
            </div>
            <hr style="margin:16px 0;">
            <h4>Change Password</h4>
            <div class="form-group">
              <label>Current Password</label>
              <input type="password" name="current_password" class="form-control">
            </div>
            <div class="form-group">
              <label>New Password (min 8 chars)</label>
              <input type="password" name="new_password" class="form-control">
            </div>
            <div class="form-group">
              <label>Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control">
            </div>
            <button class="btn primary" type="submit">Save Changes</button>
          </form>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
