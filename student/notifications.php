<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('student');

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    mark_notifications_read($pdo, $userId, $ids);
    header('Location: notifications.php');
    exit;
}

$groups = list_notifications_grouped($pdo, $userId);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Student</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
<div class="admin-layout">
  <main class="main-content" style="margin-left:0;">
    <div class="container">
      <div class="content-header">
        <h1>Notifications</h1>
        <a href="dashboard.php" class="btn">Back</a>
      </div>
      <div class="card">
        <form method="POST">
          <?php foreach ($groups as $label => $items): ?>
            <?php if (count($items) === 0) continue; ?>
            <h3 style="margin-top:10px;"><?php echo htmlspecialchars($label); ?></h3>
            <ul style="list-style:none; padding-left:0;">
              <?php foreach ($items as $n): ?>
                <li style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f0;">
                  <input type="checkbox" name="ids[]" value="<?php echo (int)$n['id']; ?>">
                  <div style="flex:1;">
                    <div style="font-weight:600; color:#6a0dad;"><?php echo htmlspecialchars($n['title'] ?? ''); ?></div>
                    <div><?php echo htmlspecialchars($n['message']); ?></div>
                    <small style="color:#666;"><?php echo htmlspecialchars($n['created_at']); ?><?php echo $n['due_at'] ? (' • Due: ' . htmlspecialchars($n['due_at'])) : ''; ?></small>
                  </div>
                  <?php if ($n['status'] === 'unread'): ?><span class="chip" style="background:#e0e7ff;color:#3730a3;border-radius:999px;padding:2px 8px;font-size:12px;">Unread</span><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
          <div style="margin-top:10px;">
            <button class="btn primary" name="mark_read" value="1">Mark selected as read</button>
          </div>
        </form>
      </div>
    </div>
  </main>
</div>
</body>
</html>
