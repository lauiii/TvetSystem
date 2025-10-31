<?php
// Shared admin header
// Expects $pageTitle to be set by the including page
if (!isset($pageTitle)) $pageTitle = '';
?>
<div class="content-header">
    <div style="display:flex;align-items:center;gap:12px;">
        <button id="sidebarToggle" class="btn menu-toggle" title="Toggle sidebar">☰</button>
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>
<script src="../assets/js/admin.js"></script>
