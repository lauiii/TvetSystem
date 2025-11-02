<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$error=''; $msg='';

// Create deadline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    try {
        $title = trim($_POST['title'] ?? '');
        $aud = $_POST['audience'] ?? '';
        $due = trim($_POST['due_at'] ?? '');
        $rem = trim($_POST['remind_days'] ?? '7,3,1');
        if ($title === '' || !in_array($aud, ['instructor','student'], true) || $due === '') throw new Exception('Fill in all fields.');
        set_deadline($pdo, $title, $aud, $due, $rem, (int)$_SESSION['user_id']);
        $msg = 'Deadline created.';
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// Run notifier on demand
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notify_now'])) {
    try { $count = run_deadline_notifier($pdo); $msg = "Sent $count notifications."; } catch (Exception $e) { $error = $e->getMessage(); }
}

// Delete deadline
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    try { delete_deadline($pdo, (int)$_POST['delete_id']); $msg='Deadline deleted.'; } catch (Exception $e) { $error=$e->getMessage(); }
}

// List deadlines
$rows = list_deadlines($pdo);

// Form state (sticky values)
$defaultDue = date('Y-m-d\\TH:i', time() + 7*86400);
$form_title = isset($_POST['title']) ? trim($_POST['title']) : '';
$form_audience = isset($_POST['audience']) ? $_POST['audience'] : 'instructor';
$form_due = isset($_POST['due_at']) ? trim($_POST['due_at']) : $defaultDue;
$form_rem = isset($_POST['remind_days']) ? trim($_POST['remind_days']) : '7,3,1';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Deadlines - Admin</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
<div class="admin-layout">
  <?php $active = 'deadlines'; require __DIR__ . '/inc/sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <?php $pageTitle = 'Deadlines'; require __DIR__ . '/inc/header.php'; ?>
      <div class="card" style="max-width:820px;margin:0 auto;">
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:12px;">
          <h3 style="margin:0;">Create Deadline</h3>
          <small class="muted">Fields marked with * are required</small>
        </div>
        <form method="POST" style="display:grid; gap:14px;" autocomplete="off">
          <input type="hidden" name="create" value="1">

          <div class="form-row" style="display:grid;grid-template-columns:1fr;gap:14px;">
            <div class="form-group">
              <label for="title">Title *</label>
              <input id="title" type="text" name="title" required maxlength="150" placeholder="e.g. Submit Final Grades for 1st Semester" value="<?php echo htmlspecialchars($form_title, ENT_QUOTES); ?>">
              <small class="muted">Be specific so recipients know exactly what is due.</small>
            </div>
          </div>

          <div class="form-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;align-items:start;">
            <div class="form-group">
              <label>Audience *</label>
              <div class="chips" id="audienceChips">
                <label class="chip" data-value="instructor" role="button" tabindex="0" aria-pressed="<?php echo $form_audience==='instructor'?'true':'false'; ?>">
                  <input type="radio" name="audience" value="instructor" <?php echo $form_audience==='instructor'?'checked':''; ?>>
                  Instructors
                </label>
                <label class="chip" data-value="student" role="button" tabindex="0" aria-pressed="<?php echo $form_audience==='student'?'true':'false'; ?>">
                  <input type="radio" name="audience" value="student" <?php echo $form_audience==='student'?'checked':''; ?>>
                  Students
                </label>
              </div>
              <small class="muted">Who should receive this deadline and reminders.</small>
            </div>

            <div class="form-group">
              <label for="due_at">Due at *</label>
              <input id="due_at" type="datetime-local" name="due_at" required min="<?php echo date('Y-m-d\\TH:i'); ?>" value="<?php echo htmlspecialchars($form_due, ENT_QUOTES); ?>">
              <small class="muted">Set the exact cutoff date and time.</small>
            </div>
          </div>

          <div class="form-row" style="display:grid;grid-template-columns:1fr;gap:14px;">
            <div class="form-group">
              <label for="remind_days">Remind before (days) *</label>
              <input id="remind_days" type="text" name="remind_days" inputmode="numeric" pattern="^[0-9,\s-]*$" placeholder="e.g. 14, 7, 3, 1" value="<?php echo htmlspecialchars($form_rem, ENT_QUOTES); ?>">
              <small class="muted">Comma-separated days before due date to send reminders. We'll clean and sort these automatically.</small>
              <div id="remindChips" class="chips" style="margin-top:8px;"></div>
            </div>
          </div>

          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
            <button class="btn" type="reset">Reset</button>
            <button class="btn primary" type="submit">Save Deadline</button>
          </div>
        </form>
      </div>

      <div class="card" style="max-width:900px;margin:16px auto;">
        <h3>Existing Deadlines</h3>
        <div class="table-responsive">
          <table class="report-table">
            <thead><tr><th>Title</th><th>Audience</th><th>Due</th><th>Remind</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo htmlspecialchars($r['title']); ?></td>
                  <td><?php echo htmlspecialchars(ucfirst($r['audience'])); ?></td>
                  <td><?php echo htmlspecialchars($r['due_at']); ?></td>
                  <td><?php echo htmlspecialchars($r['remind_days']); ?></td>
                  <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                  <td>
                    <form method="POST" onsubmit="return confirm('Delete this deadline?')" style="display:inline;">
                      <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                      <button class="btn danger">Delete</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card" style="max-width:820px;margin:16px auto;">
        <h3>Send Reminders Now</h3>
        <form method="POST">
          <button class="btn primary" name="notify_now" value="1">Run Notifier</button>
        </form>
      </div>
    </div>
  </main>
</div>
<script>
(function(){
  // Audience chips selection visual state
  const chips = document.querySelectorAll('#audienceChips .chip');
  function refreshAudienceUI(){
    chips.forEach(ch => {
      const input = ch.querySelector('input[type="radio"]');
      if(input && input.checked){ ch.classList.add('selected'); ch.setAttribute('aria-pressed','true'); }
      else { ch.classList.remove('selected'); ch.setAttribute('aria-pressed','false'); }
    });
  }
  function selectChip(ch){
    const input = ch.querySelector('input[type="radio"]');
    if(!input) return;
    input.checked = true;
    // also uncheck others explicitly for robustness
    chips.forEach(other => { if (other!==ch){ const i=other.querySelector('input[type="radio"]'); if(i) i.checked=false; } });
    refreshAudienceUI();
  }
  chips.forEach(ch => {
    ch.addEventListener('click', () => selectChip(ch));
    ch.addEventListener('keydown', (e)=>{ if(e.key==='Enter' || e.key===' '){ e.preventDefault(); selectChip(ch); } });
  });
  refreshAudienceUI();

  // Remind days chips preview + normalization
  const remindInput = document.getElementById('remind_days');
  const chipsBox = document.getElementById('remindChips');
  function normalizeDays(val){
    const nums = (val||'')
      .split(/[^0-9]+/)
      .map(s=>parseInt(s,10))
      .filter(n=>Number.isFinite(n) && n>0);
    // unique + sort desc then asc visually
    const uniq = Array.from(new Set(nums)).sort((a,b)=>a-b);
    return uniq;
  }
  function renderChips(){
    const days = normalizeDays(remindInput.value);
    chipsBox.innerHTML = '';
    days.forEach(d => {
      const span = document.createElement('span');
      span.className = 'chip';
      span.textContent = d + ' day' + (d>1?'s':'');
      chipsBox.appendChild(span);
    });
  }
  function sanitizeInput(){
    const days = normalizeDays(remindInput.value);
    remindInput.value = days.join(',');
    renderChips();
  }
  remindInput.addEventListener('blur', sanitizeInput);
  remindInput.addEventListener('input', renderChips);
  renderChips();
})();
</script>
</body>
</html>
