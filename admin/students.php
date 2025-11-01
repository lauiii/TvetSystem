<?php
/**
 * Admin - Students list
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

// basic search
$q = trim($_GET['q'] ?? '');

// school years for filtering
$schoolYears = $pdo->query("SELECT id, year, status FROM school_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeSY = null; foreach ($schoolYears as $sy) { if (($sy['status'] ?? '') === 'active') { $activeSY = $sy; break; } }
$syId = isset($_GET['sy_id']) ? (int)$_GET['sy_id'] : (int)($activeSY['id'] ?? 0);

// Determine available user columns to build a safe SELECT and WHERE
$userCols = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM users");
    $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // if SHOW COLUMNS fails, fall back to assume common columns
    $userCols = ['id','student_id','first_name','last_name','name','email','year_level','program_id','status','created_at'];
}

$selectParts = [];
$selectParts[] = "u.id";
$selectParts[] = "COALESCE(u.student_id, '') AS student_id";
if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
    $selectParts[] = "u.first_name";
    $selectParts[] = "u.last_name";
} elseif (in_array('name', $userCols)) {
    $selectParts[] = "u.name AS full_name";
}
if (in_array('email', $userCols)) $selectParts[] = "u.email";
if (in_array('year_level', $userCols)) $selectParts[] = "u.year_level";
if (in_array('program_id', $userCols)) $selectParts[] = "u.program_id";
if (in_array('status', $userCols)) $selectParts[] = "u.status";
if (in_array('created_at', $userCols)) $selectParts[] = "u.created_at";
$selectParts[] = "p.name AS program_name";

$select = implode(', ', $selectParts);

$sql = "SELECT $select FROM users u 
LEFT JOIN programs p ON u.program_id = p.id
LEFT JOIN enrollments e ON e.student_id = u.id AND e.school_year_id = :syid
WHERE u.role = 'student'";

$params = [':syid' => $syId];
if ($q !== '') {
    // Build filter conditions depending on which columns exist
    $whereParts = [];
    if (in_array('student_id', $userCols)) $whereParts[] = "u.student_id LIKE :q";
    if (in_array('email', $userCols)) $whereParts[] = "u.email LIKE :q";
    if (in_array('first_name', $userCols)) $whereParts[] = "u.first_name LIKE :q";
    if (in_array('last_name', $userCols)) $whereParts[] = "u.last_name LIKE :q";
    if (in_array('name', $userCols)) $whereParts[] = "u.name LIKE :q";
    if (!empty($whereParts)) {
        $sql .= ' AND (' . implode(' OR ', $whereParts) . ')';
        $params[':q'] = "%$q%";
    }
}

$sql .= " GROUP BY u.id ORDER BY u.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AJAX response for real-time search
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($students);
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Students - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'students'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Students'; require __DIR__ . '/inc/header.php'; ?>
                <div class="card">
<div class="toolbar" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:20px;">
    <form method="GET" style="display:flex; gap:8px; align-items:end; flex:1;">
        <div>
            <label style="display:block; font-size:12px; color:#555;">School Year</label>
            <select name="sy_id" onchange="this.form.submit()">
                <?php foreach ($schoolYears as $sy): ?>
                    <option value="<?php echo (int)$sy['id']; ?>" <?php echo ((int)$sy['id'] === (int)$syId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sy['year'] . (($sy['status'] ?? '')==='active' ? ' (Active)' : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;">
            <label style="display:block; font-size:12px; color:#555;">Search</label>
            <div style="display:flex;">
                <input class="search-input" name="q" placeholder="Search by name, email or student ID" value="<?php echo htmlspecialchars($q); ?>" style="flex:1;">
                <button class="btn primary" style="margin-left:8px;">Search</button>
            </div>
        </div>
    </form>
    <a class="btn" href="resend_passwords.php" style="text-decoration:none;">Resend Passwords</a>
</div>

                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Program</th>
                                    <th>Year</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="studentsBody">
                                <?php if (count($students) === 0): ?>
                                    <tr><td colspan="6" style="text-align:center;color:#999">No students found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $s): ?>
                                        <?php
                                            $sid = $s['student_id'] ?? 'N/A';
                                            if (!empty($s['first_name']) || !empty($s['last_name'])) {
                                                $name = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: ($s['full_name'] ?? 'N/A');
                                            } else {
                                                $name = $s['full_name'] ?? ($s['email'] ?? 'N/A');
                                            }
                                            $program = $s['program_name'] ?? 'N/A';
                                            $email = $s['email'] ?? 'N/A';
                                            $year = $s['year_level'] ?? 'N/A';
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sid); ?></td>
                                            <td><?php echo htmlspecialchars($name); ?></td>
                                            <td><?php echo htmlspecialchars($email); ?></td>
                                            <td><?php echo htmlspecialchars($program); ?></td>
                                            <td><?php echo htmlspecialchars($year); ?></td>
                                            <td><button class="btn btn-sm" style="padding:6px 12px;font-size:13px;" onclick="viewProfile(<?php echo $s['id']; ?>)">View Profile</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
</div>
        </main>
    </div>
    
    <script>
    (function(){
      const qInput = document.querySelector('input[name="q"]');
      const sySel = document.querySelector('select[name="sy_id"]');
      const tbody = document.getElementById('studentsBody');

      function debounce(fn, ms){ let t; return function(){ clearTimeout(t); t = setTimeout(fn, ms); }; }

      function render(rows){
        tbody.innerHTML = '';
        if (!rows || rows.length === 0){
          tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#999">No students found</td></tr>';
          return;
        }
        rows.forEach(function(s){
          const sid = s.student_id || 'N/A';
          const name = (s.first_name || s.last_name) ? ((s.last_name||'') + ', ' + (s.first_name||'')) : (s.full_name || s.email || 'N/A');
          const program = s.program_name || 'N/A';
          const email = s.email || 'N/A';
          const year = s.year_level != null ? s.year_level : 'N/A';
          const tr = document.createElement('tr');
          tr.innerHTML = `
            <td>${escapeHtml(sid)}</td>
            <td>${escapeHtml(name)}</td>
            <td>${escapeHtml(email)}</td>
            <td>${escapeHtml(program)}</td>
            <td>${escapeHtml(String(year))}</td>
            <td><button class="btn btn-sm" style="padding:6px 12px;font-size:13px;" onclick="viewProfile(${s.id})">View Profile</button></td>
          `;
          tbody.appendChild(tr);
        });
      }

      function escapeHtml(str){ return String(str).replace(/[&<>\"]/g, function(s){ return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"})[s]; }); }

      function load(){
        const url = `students.php?ajax=1&sy_id=${encodeURIComponent(sySel.value)}&q=${encodeURIComponent(qInput.value||'')}`;
        fetch(url, {headers: {'X-Requested-With':'XMLHttpRequest'}})
          .then(r => r.json())
          .then(render)
          .catch(()=>{ /* ignore */ });
      }

      qInput && qInput.addEventListener('input', debounce(load, 250));
      sySel && sySel.addEventListener('change', load);
      // initial load to reflect current query
      document.addEventListener('DOMContentLoaded', load);
    })();
    </script>

    <!-- Student Profile Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content" style="max-width:900px;">
            <span class="modal-close" onclick="closeProfileModal()">&times;</span>
            <h2 id="profileName" style="color:#6a0dad;margin-bottom:20px;">Student Profile</h2>
            
            <div id="profileLoading" style="text-align:center;padding:40px;">
                <p>Loading...</p>
            </div>
            
            <div id="profileContent" style="display:none;">
                <!-- Basic Info Card -->
                <div class="profile-card">
                    <h3>Basic Information</h3>
                    <div class="profile-grid">
                        <div class="profile-item"><strong>Student ID:</strong> <span id="pStudentId"></span></div>
                        <div class="profile-item"><strong>Email:</strong> <span id="pEmail"></span></div>
                        <div class="profile-item"><strong>Program:</strong> <span id="pProgram"></span></div>
                        <div class="profile-item"><strong>Year Level:</strong> <span id="pYearLevel"></span></div>
                        <div class="profile-item"><strong>Status:</strong> <span id="pStatus"></span></div>
                        <div class="profile-item"><strong>Enrolled:</strong> <span id="pEnrolled"></span></div>
                    </div>
                </div>
                
                <!-- Enrolled Courses -->
                <div class="profile-card">
                    <h3>Enrolled Courses (Current School Year)</h3>
                    <div class="table-responsive">
                        <table class="profile-table">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Section</th>
                                    <th>Instructor</th>
                                </tr>
                            </thead>
                            <tbody id="pCourses">
                                <tr><td colspan="4" style="text-align:center;color:#999;">No courses</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Grades -->
                <div class="profile-card">
                    <h3>Recent Grades</h3>
                    <div class="table-responsive">
                        <table class="profile-table">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Grade</th>
                                    <th>Remarks</th>
                                    <th>School Year</th>
                                </tr>
                            </thead>
                            <tbody id="pGrades">
                                <tr><td colspan="4" style="text-align:center;color:#999;">No grades recorded</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: none;
        }

        .report-table th {
            background: #6a0dad;
            color: white;
            font-weight: 600;
            border-bottom: 2px solid #5a0a9d;
        }

        .report-table tr:hover {
            background: #f8f9fa;
        }

        @media (max-width: 768px) {
            .table-responsive {
                font-size: 14px;
            }

            .report-table th,
            .report-table td {
                padding: 8px;
            }
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: var(--card, #fff);
            margin: 3% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 900px;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
        }
        
        .modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 20px;
        }
        
        .modal-close:hover {
            color: #000;
        }
        
        .profile-card {
            background: var(--bg-secondary, #f8f9fa);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .profile-card h3 {
            color: var(--violet, #6a0dad);
            margin: 0 0 15px 0;
            font-size: 18px;
        }
        
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .profile-item {
            padding: 10px;
            background: var(--card, #fff);
            border-radius: 6px;
        }
        
        .profile-item strong {
            color: var(--text, #333);
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .profile-item span {
            color: var(--text-light, #666);
            font-size: 15px;
        }
        
        .profile-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card, #fff);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .profile-table th {
            background: var(--violet, #6a0dad);
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .profile-table td {
            padding: 12px;
            color: var(--text, #333);
        }
        
        .profile-table tbody tr:nth-child(even) {
            background: var(--bg-secondary, #f8f9fa);
        }
    </style>
    
    <script>
        function viewProfile(studentId) {
            const modal = document.getElementById('profileModal');
            const loading = document.getElementById('profileLoading');
            const content = document.getElementById('profileContent');
            
            modal.style.display = 'block';
            loading.style.display = 'block';
            content.style.display = 'none';
            
            // Fetch student data
            fetch('get_student_profile.php?id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        closeProfileModal();
                        return;
                    }
                    
                    // Populate basic info
                    document.getElementById('profileName').textContent = data.name;
                    document.getElementById('pStudentId').textContent = data.student_id;
                    document.getElementById('pEmail').textContent = data.email;
                    document.getElementById('pProgram').textContent = data.program;
                    document.getElementById('pYearLevel').textContent = data.year_level;
                    document.getElementById('pStatus').textContent = data.status;
                    document.getElementById('pEnrolled').textContent = data.created_at;
                    
                    // Populate courses
                    const coursesBody = document.getElementById('pCourses');
                    if (data.courses && data.courses.length > 0) {
                        coursesBody.innerHTML = data.courses.map(c => `
                            <tr>
                                <td>${c.course_code}</td>
                                <td>${c.course_name}</td>
                                <td>${c.section_code || 'N/A'}</td>
                                <td>${c.instructor || 'N/A'}</td>
                            </tr>
                        `).join('');
                    } else {
                        coursesBody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;">No enrolled courses</td></tr>';
                    }
                    
                    // Populate grades
                    const gradesBody = document.getElementById('pGrades');
                    if (data.grades && data.grades.length > 0) {
                        gradesBody.innerHTML = data.grades.map(g => `
                            <tr>
                                <td>${g.course_name}</td>
                                <td>${g.grade}</td>
                                <td>${g.remarks || '-'}</td>
                                <td>${g.school_year || 'N/A'}</td>
                            </tr>
                        `).join('');
                    } else {
                        gradesBody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#999;">No grades recorded</td></tr>';
                    }
                    
                    loading.style.display = 'none';
                    content.style.display = 'block';
                })
                .catch(error => {
                    alert('Error loading profile: ' + error);
                    closeProfileModal();
                });
        }
        
        function closeProfileModal() {
            document.getElementById('profileModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('profileModal');
            if (event.target === modal) {
                closeProfileModal();
            }
        }
    </script>
</body>
</html>
