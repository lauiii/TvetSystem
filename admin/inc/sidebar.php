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
        
        <!-- User Management -->
        <div class="menu-group">
            <button class="menu-item" type="button" aria-expanded="<?php echo in_array($active, ['students','bulk-enroll','instructors']) ? 'true' : 'false' ?>" aria-controls="submenu-users" id="btn-users" style="cursor:pointer; width:100%; text-align:left; background:none; border:0; box-shadow:none; -webkit-appearance:none; appearance:none;">👥 User Management ▾</button>
            <div id="submenu-users" class="menu-sub <?php echo in_array($active, ['students','bulk-enroll','instructors']) ? 'open' : '' ?>" style="display: <?php echo in_array($active, ['students','bulk-enroll','instructors']) ? 'block' : 'none' ?>; padding-left:10px;">
                <a href="students.php" class="menu-item <?php echo $active === 'students' ? 'active' : '' ?>">• Students</a>
                <a href="bulk-enroll.php" class="menu-item <?php echo $active === 'bulk-enroll' ? 'active' : '' ?>">• Enrollment</a>
                <a href="instructors.php" class="menu-item <?php echo $active === 'instructors' ? 'active' : '' ?>">• Instructors</a>
            </div>
        </div>
        
        <!-- Academic Management -->
        <div class="menu-group">
            <button class="menu-item" type="button" aria-expanded="<?php echo in_array($active, ['programs','courses','sections','section-requests']) ? 'true' : 'false' ?>" aria-controls="submenu-academic" id="btn-academic" style="cursor:pointer; width:100%; text-align:left; background:none; border:0; box-shadow:none; -webkit-appearance:none; appearance:none;">📚 Academic Management ▾</button>
            <div id="submenu-academic" class="menu-sub <?php echo in_array($active, ['programs','courses','sections','section-requests']) ? 'open' : '' ?>" style="display: <?php echo in_array($active, ['programs','courses','sections','section-requests']) ? 'block' : 'none' ?>; padding-left:10px;">
                <a href="programs.php" class="menu-item <?php echo $active === 'programs' ? 'active' : '' ?>">• Programs</a>
                <a href="courses.php" class="menu-item <?php echo $active === 'courses' ? 'active' : '' ?>">• Courses</a>
                <a href="sections.php" class="menu-item <?php echo $active === 'sections' ? 'active' : '' ?>">• Course Sections</a>
                <a href="section-requests.php" class="menu-item <?php echo $active === 'section-requests' ? 'active' : '' ?>">• Section Requests<?php
                // Show badge for pending requests
                try {
                    $pending = $pdo->query("SELECT COUNT(*) FROM section_requests WHERE status = 'pending'")->fetchColumn();
                    if ($pending > 0) echo ' <span style="background:#e11d48;color:#fff;border-radius:999px;padding:2px 6px;font-size:10px;font-weight:700;margin-left:4px;">' . $pending . '</span>';
                } catch (Exception $e) { /* ignore */ }
                ?></a>
            </div>
        </div>
        
        <!-- Grades & Records -->
        <div class="menu-group">
            <button class="menu-item" type="button" aria-expanded="<?php echo in_array($active, ['grades','reports','promotions']) ? 'true' : 'false' ?>" aria-controls="submenu-grades" id="btn-grades" style="cursor:pointer; width:100%; text-align:left; background:none; border:0; box-shadow:none; -webkit-appearance:none; appearance:none;">📝 Grades & Records ▾</button>
            <div id="submenu-grades" class="menu-sub <?php echo in_array($active, ['grades','reports','promotions']) ? 'open' : '' ?>" style="display: <?php echo in_array($active, ['grades','reports','promotions']) ? 'block' : 'none' ?>; padding-left:10px;">
                <a href="grades.php" class="menu-item <?php echo $active === 'grades' ? 'active' : '' ?>">• Grades</a>
                <a href="reports.php" class="menu-item <?php echo $active === 'reports' ? 'active' : '' ?>">• Reports</a>
                <a href="promotions.php" class="menu-item <?php echo $active === 'promotions' ? 'active' : '' ?>">• Promotions</a>
            </div>
        </div>
        
        <!-- System -->
        <div class="menu-group">
            <button class="menu-item" type="button" aria-expanded="<?php echo in_array($active, ['school-years','deadlines','settings']) ? 'true' : 'false' ?>" aria-controls="submenu-system" id="btn-system" style="cursor:pointer; width:100%; text-align:left; background:none; border:0; box-shadow:none; -webkit-appearance:none; appearance:none;">⚙️ System ▾</button>
            <div id="submenu-system" class="menu-sub <?php echo in_array($active, ['school-years','deadlines','settings']) ? 'open' : '' ?>" style="display: <?php echo in_array($active, ['school-years','deadlines','settings']) ? 'block' : 'none' ?>; padding-left:10px;">
                <a href="school-years.php" class="menu-item <?php echo $active === 'school-years' ? 'active' : '' ?>">• School Years</a>
                <a href="deadlines.php" class="menu-item <?php echo $active === 'deadlines' ? 'active' : '' ?>">• Deadlines</a>
                <a href="settings.php" class="menu-item <?php echo $active === 'settings' ? 'active' : '' ?>">• Settings</a>
                <!-- <a href="backup.php" class="menu-item <?php echo $active === 'backup' ? 'active' : '' ?>">• Backup & Restore</a> -->
            </div>
        </div>
    </nav>
    <script>
      (function(){
        // Handle all dropdown menus
        const dropdowns = [
          {btn: 'btn-users', sub: 'submenu-users'},
          {btn: 'btn-academic', sub: 'submenu-academic'},
          {btn: 'btn-grades', sub: 'submenu-grades'},
          {btn: 'btn-system', sub: 'submenu-system'}
        ];
        
        dropdowns.forEach(function(dropdown) {
          const btn = document.getElementById(dropdown.btn);
          const sub = document.getElementById(dropdown.sub);
          
          if (btn && sub) {
            btn.addEventListener('click', function(){
              const open = sub.style.display === 'block';
              sub.style.display = open ? 'none' : 'block';
              btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            });
            btn.addEventListener('keydown', function(e){ 
              if(e.key==='Enter' || e.key===' '){ 
                e.preventDefault(); 
                btn.click(); 
              }
            });
          }
        });
      })();
    </script>
</aside>
