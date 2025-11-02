<?php
/**
 * Admin bulk enrollment: upload CSV to create student accounts and auto-enroll them
 * Expects CSV columns: First Name, Last Name, Email, Program, Year Level
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/email-functions.php';

$enrollmentResults = [];
$errors = [];
$success = '';
$error = '';

// Ensure import_jobs table exists (fallback if schema updater not run)
function ensure_import_jobs_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS import_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(50) NOT NULL DEFAULT 'student_import',
            file_path VARCHAR(500) NOT NULL,
            status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
            total_rows INT NULL,
            processed_rows INT NOT NULL DEFAULT 0,
            last_line INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }
}

// Fetch programs for manual add form
$programs = $pdo->query('SELECT id, name FROM programs ORDER BY name')->fetchAll();

// CSV processing (now queues a background job)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please upload a valid CSV file.';
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $origName = basename($_FILES['csv_file']['name']);
        $destDir = __DIR__ . '/../backups/imports';
        if (!is_dir($destDir)) { @mkdir($destDir, 0777, true); }
        $destPath = $destDir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $origName);
        if (!move_uploaded_file($tmp, $destPath)) {
            $error = 'Failed to move uploaded file.';
        } else {
            // Count rows (optional, best-effort)
            $total = null;
            if (($h = fopen($destPath, 'r')) !== false) {
                $cnt = 0; while (($r = fgetcsv($h, 1000, ',')) !== false) { $cnt++; }
                fclose($h);
                $total = max(0, $cnt - 1); // minus header
            }
            ensure_import_jobs_table($pdo);
            try {
                $stmt = $pdo->prepare("INSERT INTO import_jobs (job_type, file_path, status, total_rows) VALUES ('student_import', ?, 'pending', ?)");
                $stmt->execute([$destPath, $total]);
            } catch (PDOException $ex) {
                // If table was missing and creation was delayed, try once more
                ensure_import_jobs_table($pdo);
                $stmt = $pdo->prepare("INSERT INTO import_jobs (job_type, file_path, status, total_rows) VALUES ('student_import', ?, 'pending', ?)");
                $stmt->execute([$destPath, $total]);
            }
            $jobId = $pdo->lastInsertId();
            $success = "Import queued as Job #{$jobId}. You can leave this page; processing will continue in the background.";
        }
    }
}

// Manual add handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual'])) {
    try {
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $programId = (int)($_POST['program_id'] ?? 0);
        $yearLevel = (int)($_POST['year_level'] ?? 0);

        if (empty($firstName) || empty($lastName) || empty($email) || !$programId || !$yearLevel) {
            throw new Exception('All fields are required for manual enrollment.');
        }

        $res = create_user($pdo, $firstName, $lastName, $email, $programId, $yearLevel, 'student');
        if (!$res['success']) throw new Exception($res['error'] ?? 'Failed to create user');

        // Auto-enroll is now handled in create_user function
        $enrolled = $res['enrolled'] ?? 0;

        if (function_exists('sendStudentCredentials')) {
            sendStudentCredentials($email, $firstName, $res['student_id'], $res['password']);
        }

        $success = "Student enrolled successfully! Student ID: {$res['student_id']} - Enrolled in {$enrolled} courses";
        $enrollmentResults[] = ['success' => $success];

    } catch (Exception $e) {
        $error = 'Manual enrollment failed: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Enroll - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'bulk-enroll'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Enroll Students'; require __DIR__ . '/inc/header.php'; ?>
                <div class="card">
                    <p>Download the template, fill in rows, then upload the CSV. The system will create student accounts, auto-enroll them into courses matching program & year, and attempt to send credentials by email.</p>

                    <p>
                        <a class="btn" href="download-template.php?raw=1">Download CSV Template</a>
                    </p>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="csv_file">CSV File</label>
                            <div class="modern-file-upload">
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" onchange="updateFileName(this)">
                                <label for="csv_file" class="file-upload-label">
                                    <span class="file-icon">📄</span>
                                    <span class="file-text" id="file-name">Choose CSV file</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <button class="btn primary">Upload and Process</button>
                        </div>
                    </form>
                    
                    <script>
                    function updateFileName(input) {
                        const fileName = input.files[0]?.name || 'Choose CSV file';
                        document.getElementById('file-name').textContent = fileName;
                    }
                    </script>

                    <?php foreach ($enrollmentResults as $r): ?>
                        <?php if (isset($r['success'])): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($r['success']); ?></div>
                        <?php else: ?>
                            <div class="alert alert-error"><?php echo htmlspecialchars($r['error']); ?></div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                </div>

                <div class="card" style="margin-top:16px">
                    <h3>Manual Add Student</h3>
                    <form method="POST">
                        <input type="hidden" name="add_manual" value="1">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Program</label>
                            <select name="program_id" required>
                                <option value="">-- choose program --</option>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Year Level</label>
                            <select name="year_level" required>
                                <option value="">-- choose year level --</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                            </select>
                        </div>
                        <div class="form-group"><button class="btn primary">Enroll Student</button></div>
                    </form>
                </div>

                <div class="card" style="margin-top:16px">
                    <h3>Credential Emails Queue (real-time)</h3>
                    <div class="form-group" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <label>Status</label>
                        <select id="eq-status">
                            <option value="active">Pending + Failed</option>
                            <option value="pending">Pending only</option>
                            <option value="failed">Failed only</option>
                            <option value="sent">Sent (recent)</option>
                        </select>
                        <label>Order</label>
                        <select id="eq-order">
                            <option value="priority">Priority</option>
                            <option value="time">Oldest first</option>
                        </select>
                        <label>Search</label>
                        <input type="text" id="eq-search" placeholder="email or error..." style="min-width:220px">
                        <button class="btn" id="eq-refresh">Refresh</button>
                        <button class="btn" id="eq-send">Send Next 10</button>
                        <label style="display:flex;gap:6px;align-items:center;">
                            <input type="checkbox" id="eq-auto"> Auto-send
                        </label>
                        <button class="btn primary" id="eq-prioritize" disabled>Prioritize Selected</button>
                        <span id="eq-counts" style="margin-left:auto;font-size:12px;color:#555"></span>
                    </div>
                    <div class="table-responsive">
                        <table id="eq-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="eq-all"></th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Attempts</th>
                                    <th>Scheduled</th>
                                    <th>Last error</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <small>Listing emails with subject "Your College Grading System Account".</small>
                </div>

            </div>
        </main>
    </div>
<script>
(function(){
  const tbody = document.querySelector('#eq-table tbody');
  const refreshBtn = document.getElementById('eq-refresh');
  const prioritizeBtn = document.getElementById('eq-prioritize');
  const allCb = document.getElementById('eq-all');
  const selStatus = document.getElementById('eq-status');
  const selOrder = document.getElementById('eq-order');
  const countsEl = document.getElementById('eq-counts');
  const searchInput = document.getElementById('eq-search');
  const sendBtn = document.getElementById('eq-send');
  const autoCb = document.getElementById('eq-auto');
  let timer = null;
  let autoTimer = null;
  let debounceT = null;
  function fmt(s){ return s==null?'':String(s); }
  function rowHtml(r){
    const err = r.last_error ? r.last_error.substring(0,120) : '';
    return `<tr>
      <td><input type="checkbox" class="eq-cb" value="${r.id}"></td>
      <td>${r.to_email}</td>
      <td>${r.status}</td>
      <td>${r.attempts}</td>
      <td>${r.scheduled_at ?? ''}</td>
      <td title="${fmt(r.last_error)}">${err}</td>
    </tr>`;
  }
  function selectedIds(){
    return Array.from(document.querySelectorAll('.eq-cb:checked')).map(cb=>parseInt(cb.value)).filter(Boolean);
  }
  function updateButtons(){ prioritizeBtn.disabled = selectedIds().length===0; }
  function load(){
    const q = encodeURIComponent(searchInput.value || '');
    const url = `email_queue_data.php?status=${encodeURIComponent(selStatus.value)}&order=${encodeURIComponent(selOrder.value)}&limit=50&q=${q}`;
    fetch(url, {credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if(!j.ok) return;
      tbody.innerHTML = j.data.map(rowHtml).join('');
      countsEl.textContent = `Pending: ${j.counts.pending} • Failed: ${j.counts.failed} • Sent: ${j.counts.sent}`;
      allCb.checked = false; updateButtons();
    }).catch(()=>{});
  }
  refreshBtn.addEventListener('click', load);
  selStatus.addEventListener('change', load);
  selOrder.addEventListener('change', load);
  searchInput.addEventListener('input', function(){
    clearTimeout(debounceT);
    debounceT = setTimeout(load, 300);
  });
  function sendBatch(limit=10){
    sendBtn.disabled = true;
    fetch('email_send_batch.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({limit, rate_ms: 250})})
      .then(r=>r.json()).then(j=>{ load(); }).finally(()=>{ sendBtn.disabled = false; });
  }
  sendBtn.addEventListener('click', ()=>sendBatch(10));
  autoCb.addEventListener('change', function(){
    if (autoCb.checked){
      autoTimer = setInterval(()=>sendBatch(3), 5000);
    } else {
      clearInterval(autoTimer);
    }
  });
  allCb.addEventListener('change', function(){
    document.querySelectorAll('.eq-cb').forEach(cb=>cb.checked = allCb.checked);
    updateButtons();
  });
  tbody.addEventListener('change', function(e){ if(e.target.classList.contains('eq-cb')) updateButtons(); });
  prioritizeBtn.addEventListener('click', function(){
    const ids = selectedIds(); if(!ids.length) return;
    fetch('email_prioritize.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ids})})
      .then(r=>r.json()).then(()=>load());
  });
  function start(){ load(); timer = setInterval(load, 15000); }
  document.addEventListener('visibilitychange', function(){ if(document.hidden){ clearInterval(timer); clearInterval(autoTimer); } else { start(); if (autoCb.checked) { autoTimer = setInterval(()=>sendBatch(3), 5000); } } });
  start();
})();
</script>
</body>
</html>
