<?php
/**
 * Add sample students for quick testing (admin only)
 * - Creates students across existing programs and year levels (1-3)
 * - Uses create_user() so student_id/password are generated and auto-enrollment runs
 * - Outputs created credentials (email, student_id, temp password)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

// Queue emails during sample generation to avoid slowing the page
putenv('EMAIL_MODE=queue');

header('Content-Type: text/html; charset=utf-8');

$perYear = isset($_GET['per_year']) ? max(1, (int)$_GET['per_year']) : 5; // default 5 per year
$years = [1,2,3];

// Ensure there is at least one program; if none, create basic ones schema-adaptively
try {
    $cols = $pdo->query("SHOW COLUMNS FROM programs")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $cols = []; }
$hasName = in_array('name', $cols, true);
$hasCode = in_array('code', $cols, true);

$programs = $pdo->query('SELECT id, ' . ($hasName?'name':'id') . ' AS name, ' . ($hasCode?'code':'id') . ' AS code FROM programs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (empty($programs)) {
    try {
        if ($hasName || $hasCode) {
            $ins = $pdo->prepare('INSERT INTO programs (' . ($hasName?'name':'') . ($hasName&&$hasCode?',':'') . ($hasCode?'code':'') . ') VALUES (' . ($hasName?'?':'') . ($hasName&&$hasCode?',':'') . ($hasCode?'?':'') . ')');
            if ($hasName && $hasCode) { $ins->execute(['Diploma in Information Technology','DIT']); }
            elseif ($hasName) { $ins->execute(['Diploma in Information Technology']); }
            elseif ($hasCode) { $ins->execute(['DIT']); }
        } else {
            // minimal insert if table has no name/code (rare)
            $pdo->exec('INSERT INTO programs () VALUES ()');
        }
    } catch (Exception $e) { /* ignore */ }
    $programs = $pdo->query('SELECT id, ' . ($hasName?'name':'id') . ' AS name, ' . ($hasCode?'code':'id') . ' AS code FROM programs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

$firstNames = ['Juan','Maria','Jose','Ana','Pedro','Luisa','Carlo','Grace','Marco','Lara','Miguel','Sofia','Diego','Elena','Rafael','Iris'];
$lastNames  = ['Dela Cruz','Santos','Reyes','Garcia','Mendoza','Gonzales','Torres','Flores','Ramos','Rivera','Martinez','Aquino','Domingo','Navarro','Castillo','Bautista'];

$created = [];
$errors = [];

$domain = 'example.edu';
$counter = time() % 100000; // simple varying seed

foreach ($programs as $p) {
    $pid = (int)$p['id'];
    foreach ($years as $yl) {
        for ($i=0; $i<$perYear; $i++) {
            $fn = $firstNames[array_rand($firstNames)];
            $ln = $lastNames[array_rand($lastNames)];
            $email = strtolower(preg_replace('/[^a-z]/','',$fn)) . '.' . strtolower(preg_replace('/[^a-z]/','',$ln)) . "+$counter@${domain}";
            $counter++;
            try {
                $res = create_user($pdo, $fn, $ln, $email, $pid, $yl, 'student');
                if ($res['success']) {
                    $created[] = [
                        'name' => "$fn $ln",
                        'email' => $email,
                        'student_id' => $res['student_id'],
                        'password' => $res['password'],
                        'program' => ($p['name'] ?? $pid),
                        'year_level' => $yl
                    ];
                } else {
                    $errors[] = $res['error'] ?? 'unknown error';
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Add Sample Students</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <style>
    .card{padding:18px;background:#fff;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,0.04);max-width:1000px;margin:20px auto}
    code{background:#f7f7fb;padding:2px 6px;border-radius:6px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
    thead th{background:#f5f2ff;color:#4b048f}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="color:#6a0dad;margin-top:0;">Sample Students Created</h2>
    <p>Total: <strong><?= count($created) ?></strong> <?= count($errors)?' • Errors: '.count($errors):'' ?></p>
    <?php if ($created): ?>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Name</th><th>Email</th><th>Student ID</th><th>Password</th><th>Program</th><th>Year</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($created as $c): ?>
              <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><code><?= htmlspecialchars($c['student_id']) ?></code></td>
                <td><code><?= htmlspecialchars($c['password']) ?></code></td>
                <td><?= htmlspecialchars($c['program']) ?></td>
                <td><?= (int)$c['year_level'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p>No students created.</p>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-error" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p style="margin-top:12px;">
      <a class="btn" href="../admin/students.php">Back to Students</a>
    </p>
  </div>
</body>
</html>
