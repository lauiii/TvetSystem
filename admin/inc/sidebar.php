<?php
// Shared admin sidebar
// Expects optional $active (string) to mark the active menu item
if (!isset($active)) $active = '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-link" style="text-align: center; padding: 10px 0;">
            <img src="../public/assets/icon/logo.svg" alt="Logo" class="brand-image" style="max-width: 60px; height: auto; margin-bottom: 10px;">
        </div>
        <h2><?php echo SITE_NAME; ?></h2>
        <?php
            $adminName = $_SESSION['name'] ?? '';
            if ($adminName === '' && isset($pdo) && isset($_SESSION['user_id'])) {
                try {
                    $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) AS nm FROM users WHERE id = ? LIMIT 1");
                    $st->execute([$_SESSION['user_id']]);
                    $nm = trim($st->fetchColumn() ?: '');
                    if ($nm !== '') { $adminName = $nm; }
                } catch (Exception $e) { /* ignore */ }
            }
        ?>
        <p><?php echo htmlspecialchars($adminName !== '' ? $adminName : 'Administrator'); ?></p>
    </div>
    <nav class="sidebar-menu">
        <a href="dashboard.php" class="menu-item <?php echo $active === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
        <a href="students.php" class="menu-item <?php echo $active === 'students' ? 'active' : '' ?>">👥 Students</a>
        <a href="bulk-enroll.php" class="menu-item <?php echo $active === 'bulk-enroll' ? 'active' : '' ?>">📤 Enrollment</a>
        <a href="instructors.php" class="menu-item <?php echo $active === 'instructors' ? 'active' : '' ?>">👨‍🏫 Instructors</a>
        <a href="programs.php" class="menu-item <?php echo $active === 'programs' ? 'active' : '' ?>">📚 Programs</a>
        <a href="courses.php" class="menu-item <?php echo $active === 'courses' ? 'active' : '' ?>">📖 Courses</a>
        <a href="school-years.php" class="menu-item <?php echo $active === 'school-years' ? 'active' : '' ?>">📅 School Years</a>
        <a href="grades.php" class="menu-item <?php echo $active === 'grades' ? 'active' : '' ?>">📝 Grades</a>
        <a href="reports.php" class="menu-item <?php echo $active === 'reports' ? 'active' : '' ?>">📊 Reports</a>
        <a href="promotions.php" class="menu-item <?php echo $active === 'promotions' ? 'active' : '' ?>">⬆️ Promotions</a>
        <a href="settings.php" class="menu-item <?php echo $active === 'settings' ? 'active' : '' ?>">⚙️ Settings</a>
    </nav>
</aside>
