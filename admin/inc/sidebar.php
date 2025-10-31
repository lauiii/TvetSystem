<?php
// Shared admin sidebar
// Expects optional $active (string) to mark the active menu item
if (!isset($active)) $active = '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2><?php echo SITE_NAME; ?></h2>
        <p>Administrator</p>
    </div>
    <nav class="sidebar-menu">
        <a href="dashboard.php" class="menu-item <?php echo $active === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
        <a href="students.php" class="menu-item <?php echo $active === 'students' ? 'active' : '' ?>">👥 Students</a>
        <a href="bulk-enroll.php" class="menu-item <?php echo $active === 'bulk-enroll' ? 'active' : '' ?>">📤 Bulk Enrollment</a>
        <a href="instructors.php" class="menu-item <?php echo $active === 'instructors' ? 'active' : '' ?>">👨‍🏫 Instructors</a>
        <a href="programs.php" class="menu-item <?php echo $active === 'programs' ? 'active' : '' ?>">📚 Programs</a>
        <a href="courses.php" class="menu-item <?php echo $active === 'courses' ? 'active' : '' ?>">📖 Courses</a>
        <a href="school-years.php" class="menu-item <?php echo $active === 'school-years' ? 'active' : '' ?>">📅 School Years</a>
        <a href="grades.php" class="menu-item <?php echo $active === 'grades' ? 'active' : '' ?>">📝 Grades</a>
        <a href="reports.php" class="menu-item <?php echo $active === 'reports' ? 'active' : '' ?>">📊 Reports</a>
    </nav>
</aside>
