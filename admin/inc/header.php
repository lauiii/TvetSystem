<?php
// Shared admin header
// Expects $pageTitle to be set by the including page
if (!isset($pageTitle)) $pageTitle = '';
?>
<div class="content-header">
    <div style="display:flex;align-items:center;gap:12px;">
        <button id="sidebarToggle" class="btn menu-toggle ring" title="Toggle sidebar" aria-label="Toggle sidebar" type="button">☰</button>
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
    </div>
    <?php
        // Resolve admin display name and role for header pill
        $adminName = $_SESSION['name'] ?? '';
        $adminRole = $_SESSION['role'] ?? 'admin';
        if ($adminName === '' && isset($pdo) && isset($_SESSION['user_id'])) {
            try {
                $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('first_name',$ucols) && in_array('last_name',$ucols)) {
                    $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) AS nm FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$_SESSION['user_id']]);
                    $nm = trim((string)$st->fetchColumn());
                    if ($nm !== '') { $adminName = $nm; }
                } elseif (in_array('name',$ucols)) {
                    $st = $pdo->prepare("SELECT name AS nm FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$_SESSION['user_id']]);
                    $nm = trim((string)$st->fetchColumn());
                    if ($nm !== '') { $adminName = $nm; }
                }
            } catch (Exception $e) { /* ignore */ }
        }
        $initial = strtoupper(substr(trim($adminName !== '' ? $adminName : 'A'),0,1));
        $roleLabel = ($adminRole === 'admin') ? 'TVET HEAD' : strtoupper($adminRole);
    ?>
    <div style="display:flex;align-items:center;gap:14px;">
        <?php 
            // Notification unread count
            $unread = 0;
            if (isset($pdo) && isset($_SESSION['user_id'])) {
                try { require_once __DIR__ . '/../../include/functions.php'; $unread = get_unread_count($pdo, (int)$_SESSION['user_id']); } catch (Exception $e) { $unread = 0; }
            }
        ?>
        <a href="notifications.php" class="btn" title="Notifications" aria-label="Notifications" style="position:relative; background:#f1effa; color:#4b048f;">
            🔔
            <?php if ($unread > 0): ?>
                <span style="position:absolute; top:-6px; right:-6px; background:#e11d48; color:#fff; border-radius:999px; padding:2px 6px; font-size:11px; font-weight:700; line-height:1; "><?php echo (int)$unread; ?></span>
            <?php endif; ?>
        </a>
        <button id="themeToggleHeader" class="btn" title="Toggle theme" aria-label="Toggle theme">🌙</button>
        <div class="user-pill" style="display:flex;align-items:center;gap:10px;background:#f7f7fb;border:1px solid #eee;padding:6px 10px;border-radius:999px;">
            <div class="avatar" aria-hidden="true" style="width:34px;height:34px;border-radius:50%;background:#6a0dad;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;"><?php echo $initial; ?></div>
            <div style="line-height:1;">
                <div style="font-weight:700; font-size:14px; color:#333; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($adminName ?: 'Administrator'); ?>"><?php echo htmlspecialchars($adminName ?: 'Administrator'); ?></div>
                <div style="font-size:12px; color:#6b7280; letter-spacing:.02em;"><?php echo htmlspecialchars($roleLabel); ?></div>
            </div>
        </div>
        <a href="../logout.php" class="btn-logout" title="Logout">Logout</a>
    </div>
</div>
<script src="../assets/js/admin.js"></script>
