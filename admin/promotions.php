<?php
/**
 * Admin Promotions — promote eligible students to next year and auto-enroll
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$programs = $pdo->query("SELECT id, code, name FROM programs ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$msg=''; $error=''; $results=null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['program_id'], $_POST['from_year'])) {
    $program_id = (int)$_POST['program_id'];
    $from_year = (int)$_POST['from_year'];
    if ($program_id <= 0 || $from_year <= 0) {
        $error = 'Select program and year.';
    } else {
        try {
            $results = promote_students($pdo, $program_id, $from_year);
            $msg = 'Promoted ' . (int)$results['promoted'] . ' of ' . (int)$results['checked'] . ' students to Year ' . (int)$results['next_year'] . '.';
        } catch (Exception $e) {
            $error = 'Promotion failed: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Promotions - Admin</title>
  <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
  <div class="admin-layout">
    <?php $active = 'promotions'; require __DIR__ . '/inc/sidebar.php'; ?>

    <main class="main-content">
      <div class="container">
        <?php $pageTitle = 'Promotions'; require __DIR__ . '/inc/header.php'; ?>

        <div class="card" style="max-width:720px;margin:0 auto;">
          <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

          <form method="POST" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="flex:1;min-width:200px;">
              <label>Program</label>
              <select name="program_id" required class="form-control">
                <option value="">-- select program --</option>
                <?php foreach ($programs as $p): ?>
                  <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars(($p['code']?:'') . ' — ' . $p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>From Year</label>
              <select name="from_year" required class="form-control">
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
              </select>
            </div>
            <button class="btn primary" type="submit">Promote Eligible</button>
          </form>
        </div>

        <?php if ($results && !empty($results['failed'])): ?>
        <div class="card" style="max-width:720px;margin:16px auto;">
          <h3>Not Promoted (Failed/Incomplete)</h3>
          <p><?php echo count($results['failed']); ?> students not promoted.</p>
        </div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
