<?php
/**
 * Admin — Resend Passwords to Students
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

$success=''; $error='';
$q = trim($_GET['q'] ?? '');

// Detect columns
$userCols = [];
try { $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { $userCols = []; }
$has = function($c) use ($userCols) { return in_array($c, $userCols); };

// Email column
$emailCol = null; foreach (['email','user_email','username'] as $c) { if ($has($c)) { $emailCol = $c; break; } }
if (!$emailCol) { $emailCol = 'email'; }

// Name columns
$firstCol = $has('first_name') ? 'first_name' : null; $lastCol = $has('last_name') ? 'last_name' : null; $nameCol = $has('name') ? 'name' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';
    $ids = [];
    if ($mode === 'single') {
        $ids = [ (int)($_POST['user_id'] ?? 0) ];
    } elseif ($mode === 'bulk') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
    }
    $ids = array_values(array_filter(array_unique($ids)));

    if (empty($ids)) {
        $error = 'No users selected.';
    } else {
        $okCount = 0; $fail = 0;
        foreach ($ids as $uid) {
            try {
                $stmt = $pdo->prepare("SELECT id, student_id, $emailCol AS email" .
                    ($firstCol? ", $firstCol AS first_name" : '') .
                    ($lastCol? ", $lastCol AS last_name" : '') .
                    ($nameCol? ", $nameCol AS full_name" : '') .
                    " FROM users WHERE id = ? AND role = 'student' AND status = 'active' LIMIT 1");
                $stmt->execute([$uid]);
                $u = $stmt->fetch();
                if (!$u || empty($u['email'])) { $fail++; continue; }
                $fname = '';
                if (!empty($u['first_name']) || !empty($u['last_name'])) { $fname = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); }
                if (!$fname) { $fname = $u['full_name'] ?? 'Student'; }
                // Generate new temp password and update
                $newPass = generate_password(10);
                $hash = hash_password($newPass);
                $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $upd->execute([$hash, $uid]);
                // Send email
                $sent = sendStudentCredentials($u['email'], $fname, $u['student_id'] ?? '', $newPass);
                if ($sent) { $okCount++; } else { $fail++; }
            } catch (Exception $e) { $fail++; }
        }
        $success = "Passwords resent: {$okCount}. Failed: {$fail}.";
    }
}

// Build listing
$where = "WHERE role='student'";
$params = [];
if ($q !== '') {
    $parts = [];
    if ($has('student_id')) $parts[] = "student_id LIKE :q";
    if ($has('email')) $parts[] = "email LIKE :q";
    if ($has('first_name')) $parts[] = "first_name LIKE :q";
    if ($has('last_name')) $parts[] = "last_name LIKE :q";
    if ($has('name')) $parts[] = "name LIKE :q";
    if ($parts) { $where .= ' AND (' . implode(' OR ', $parts) . ')'; $params[':q'] = "%$q%"; }
}
$sql = "SELECT id, student_id, $emailCol AS email" .
       ($firstCol? ", $firstCol AS first_name" : '') .
       ($lastCol? ", $lastCol AS last_name" : '') .
       ($nameCol? ", $nameCol AS full_name" : '') .
       " FROM users $where ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Resend Passwords — Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
<div class="admin-layout">
    <?php $active = 'students'; require __DIR__ . '/inc/sidebar.php'; ?>
    <main class="main-content">
        <div class="container">
            <?php $pageTitle = 'Resend Passwords'; require __DIR__ . '/inc/header.php'; ?>
            <div class="card">
                <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <div class="resend-head" style="margin-bottom:10px;">
                    <div class="title" style="font-weight:700; color:#6a0dad;">Resend Passwords</div>
                    <div class="sub" style="color:#555; font-size:13px;">Generate a new temporary password and email it to selected students.</div>
                </div>
                <form method="GET" class="resend-search" style="display:flex; gap:12px; align-items:end; margin-bottom:12px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:260px;">
                        <label style="display:block; font-size:12px; color:#555;">Search</label>
                        <input name="q" class="search-input" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search by name, email, student ID">
                    </div>
                    <button class="btn primary" type="submit">Search</button>
                </form>
                <div class="resend-toolbar" style="display:flex; justify-content:space-between; align-items:center; gap:10px; background:#f8f5ff; border:1px solid #eadcff; padding:10px 12px; border-radius:8px; margin-bottom:10px;">
                    <div><span class="pill" id="selCount" style="display:inline-block; background:#6a0dad; color:#fff; border-radius:999px; padding:4px 10px; font-weight:600;">0 selected</span></div>
                    <div style="display:flex; gap:8px;">
                        <button class="btn secondary" type="button" onclick="toggleAll(true)">Select All</button>
                        <button class="btn secondary" type="button" onclick="toggleAll(false)">Clear</button>
                        <button class="btn primary" type="button" onclick="submitBulk()" id="bulkBtn" disabled>Resend Selected</button>
                    </div>
                </div>
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="mode" value="bulk">
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="masterCb"></th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <?php $name = (!empty($r['first_name'])||!empty($r['last_name'])) ? trim(($r['first_name']??'').' '.($r['last_name']??'')) : ($r['full_name']??''); $hasEmail = !empty($r['email']); ?>
                                    <tr>
                                        <td><input type="checkbox" class="sel" name="ids[]" value="<?php echo (int)$r['id']; ?>" <?php echo $hasEmail ? '' : 'disabled'; ?>></td>
                                        <td><?php echo htmlspecialchars($r['student_id'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($name ?: ''); ?></td>
                                        <td><?php echo $hasEmail ? htmlspecialchars($r['email']) : '<span class="tag danger">No email</span>'; ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="mode" value="single">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="btn" type="submit" <?php echo $hasEmail ? '' : 'disabled'; ?>>Resend</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">
                        <button class="btn" type="button" onclick="toggleAll(false)">Clear</button>
                        <button class="btn primary" type="button" onclick="submitBulk()" id="bulkBtnBottom" disabled>Resend Selected</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<style>
.tag { display:inline-block; font-size:12px; padding:2px 8px; border-radius:999px; background:#eef2ff; color:#3730a3; }
.tag.danger { background:#fee2e2; color:#991b1b; }
.report-table tr.selected { background:#faf5ff; }
</style>
<script>
function updateSel() {
  const boxes = Array.from(document.querySelectorAll('.sel'));
  const count = boxes.filter(cb => cb.checked).length;
  const selCount = document.getElementById('selCount');
  if (selCount) selCount.textContent = count + ' selected';
  const disable = count === 0;
  ['bulkBtn','bulkBtnBottom'].forEach(id => { const b = document.getElementById(id); if (b) b.disabled = disable; });
  // highlight rows
  boxes.forEach(cb => { const tr = cb.closest('tr'); if (tr) tr.classList.toggle('selected', cb.checked); });
  const master = document.getElementById('masterCb'); if (master) master.checked = count>0 && count===boxes.filter(cb=>!cb.disabled).length;
}
function toggleAll(state){ document.querySelectorAll('.sel:not(:disabled)').forEach(cb=>cb.checked = !!state); updateSel(); }
function submitBulk(){ const count = Array.from(document.querySelectorAll('.sel')).filter(cb=>cb.checked).length; if (count===0) return; if (!confirm('Resend passwords to ' + count + ' student(s)?')) return; document.getElementById('bulkForm').submit(); }
document.addEventListener('change', e => { if (e.target && (e.target.classList && e.target.classList.contains('sel'))) updateSel(); if (e.target && e.target.id==='masterCb'){ toggleAll(e.target.checked); }});
window.addEventListener('load', updateSel);
</script>
</body>
</html>
