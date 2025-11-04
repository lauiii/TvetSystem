<?php
/**
 * Available Sections - Instructor can browse and request sections
 */

require_once '../config.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Handle section request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_section') {
    try {
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        
        if (!$sectionId) throw new Exception('Invalid section');
        
        // Check if already requested
        $check = $pdo->prepare("SELECT id, status FROM section_requests WHERE instructor_id = ? AND section_id = ? AND status = 'pending'");
        $check->execute([$instructorId, $sectionId]);
        if ($check->fetch()) {
            throw new Exception('You already have a pending request for this section');
        }
        
        // Check if already assigned
        $assigned = $pdo->prepare("SELECT id FROM instructor_sections WHERE instructor_id = ? AND section_id = ?");
        $assigned->execute([$instructorId, $sectionId]);
        if ($assigned->fetch()) {
            throw new Exception('You are already assigned to this section');
        }
        
        // Create request
        $insert = $pdo->prepare("INSERT INTO section_requests (instructor_id, section_id, request_message, status) VALUES (?, ?, ?, 'pending')");
        $insert->execute([$instructorId, $sectionId, $message]);
        
        $msg = 'Request submitted successfully! Waiting for admin approval.';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch available sections (sections without any instructor assigned)
$availableSections = $pdo->query("
    SELECT 
        s.id as section_id,
        s.section_code,
        s.section_name,
        s.capacity,
        s.enrolled_count,
        c.id as course_id,
        c.course_code,
        c.course_name,
        c.year_level,
        c.semester,
        p.code as program_code,
        p.name as program_name,
        (SELECT COUNT(*) FROM instructor_sections WHERE section_id = s.id) as instructor_count
    FROM sections s
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    WHERE s.status = 'active'
    HAVING instructor_count = 0
    ORDER BY p.code, c.course_code, s.section_code
")->fetchAll(PDO::FETCH_ASSOC);

// Group by program then year for rendering
$grouped = [];
foreach ($availableSections as $s) {
    $pname = trim($s['program_name'] ?: $s['program_code']);
    $yr = (int)$s['year_level']; if ($yr < 1 || $yr > 4) { $yr = 1; }
    if (!isset($grouped[$pname])) { $grouped[$pname] = []; }
    if (!isset($grouped[$pname][$yr])) { $grouped[$pname][$yr] = []; }
    $grouped[$pname][$yr][] = $s;
}
ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($grouped as &$yrs) { ksort($yrs); }
unset($yrs);

// Fetch instructor's pending requests
$pendingRequests = $pdo->prepare("
    SELECT 
        sr.id as request_id,
        sr.section_id,
        sr.status,
        sr.created_at,
        s.section_code,
        c.course_code,
        c.course_name
    FROM section_requests sr
    INNER JOIN sections s ON sr.section_id = s.id
    INNER JOIN courses c ON s.course_id = c.id
    WHERE sr.instructor_id = ? AND sr.status = 'pending'
    ORDER BY sr.created_at DESC
");
$pendingRequests->execute([$instructorId]);
$requests = $pendingRequests->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Sections - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #333;
        }
        
        .header {
            background: linear-gradient(135deg, #6a0dad 0%, #9b59b6 100%);
            color: white;
            padding: 25px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .nav-links {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        .btn-primary {
            background: #6a0dad;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a0c9d;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }
        
        .card h2 {
            color: #6a0dad;
            margin-bottom: 20px;
            font-size: 22px;
        }
        
        .card h3 {
            color: #6a0dad;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .section-grid {
            display: grid;
            gap: 15px;
        }
        
        .section-item {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .section-item:hover {
            border-color: #6a0dad;
            box-shadow: 0 4px 12px rgba(106, 13, 173, 0.1);
        }
        
        .section-info h4 {
            color: #6a0dad;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .section-details {
            font-size: 14px;
            color: #666;
            margin-top: 8px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
        }
        
        .badge-info {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .badge-warning {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            color: #6a0dad;
            margin: 0;
        }
        
        .close {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
        }
        
        .close:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .request-list {
            display: grid;
            gap: 10px;
        }
        
        .request-item {
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
            }
            
            .section-item {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-links {
                flex-direction: column;
                width: 100%;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>📚 Available Sections</h1>
            <div class="nav-links">
                <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
                <a href="../logout.php" class="btn btn-secondary">Logout</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        
        <!-- Pending Requests -->
        <?php if(count($requests) > 0): ?>
        <div class="card">
            <h3>⏳ Your Pending Requests (<?= count($requests) ?>)</h3>
            <div class="request-list">
                <?php foreach($requests as $req): ?>
                <div class="request-item">
                    <div>
                        <strong><?= htmlspecialchars($req['course_code']) ?> - <?= htmlspecialchars($req['section_code']) ?></strong>
                        <div style="font-size: 13px; color: #666; margin-top: 4px;">
                            Requested: <?= date('M d, Y', strtotime($req['created_at'])) ?>
                        </div>
                    </div>
                    <span class="badge badge-warning">Pending</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Available Sections -->
        <div class="card">
            <h2>🔓 Available Sections (<?= count($availableSections) ?>)</h2>
            <div style="margin:10px 0 16px; display:flex; gap:8px; align-items:center;">
                <input type="text" id="sectionSearch" class="form-control" placeholder="Search by course, section, program, year..." style="flex:1; padding:10px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <?php if (count($availableSections) > 0): ?>
                <?php foreach ($grouped as $programName => $years): ?>
                    <div class="program-heading" style="text-align:center; font-size:1.25rem; font-weight:800; color:#4b5563; margin:18px 0 8px; border-bottom:2px solid #e5e7eb; padding-bottom:6px;">
                        <?= htmlspecialchars($programName) ?>
                    </div>
                    <?php foreach ($years as $yr => $list): ?>
                        <?php $ylbl = ((int)$yr===1?'1st':((int)$yr===2?'2nd':((int)$yr===3?'3rd':((int)$yr.'th')))); ?>
                        <div class="year-heading" style="text-align:center; font-weight:700; color:#6a0dad; margin:8px 0;">
                            <?= $ylbl ?> Year
                        </div>
                        <div class="section-grid">
                            <?php foreach ($list as $section): ?>
                                <div class="section-item" data-search="<?= htmlspecialchars(strtolower($section['course_code'].' '.$section['section_code'].' '.$section['course_name'].' '.$section['program_code'].' '.$section['program_name'].' year '.$section['year_level'].' sem '.$section['semester'])) ?>">
                                    <div class="section-info">
                                        <h4><?= htmlspecialchars($section['course_code']) ?> - <?= htmlspecialchars($section['section_code']) ?></h4>
                                        <div><?= htmlspecialchars($section['course_name']) ?></div>
                                        <div class="section-details">
                                            <span class="badge badge-info"><?= htmlspecialchars($section['program_code']) ?></span>
                                            <?php $yl2=(int)$section['year_level']; $ylbl2 = ($yl2===1?'1st':($yl2===2?'2nd':($yl2===3?'3rd':($yl2.'th')))); ?>
                                            <span class="badge badge-info"><?= $ylbl2 ?> Year</span>
                                            <span class="badge badge-info">Sem <?= (int)$section['semester'] ?></span>
                                            <span class="badge badge-info"><?= (int)$section['enrolled_count'] ?>/<?= (int)$section['capacity'] ?> students</span>
                                        </div>
                                    </div>
                                    <button class="btn btn-primary btn-small" onclick="openRequestModal(<?= (int)$section['section_id'] ?>, '<?= htmlspecialchars($section['course_code']) ?> - <?= htmlspecialchars($section['section_code']) ?>')">
                                        Request Section
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <h3>No Available Sections</h3>
                    <p>All sections currently have instructors assigned.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Request Modal -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Section Assignment</h3>
                <span class="close" onclick="closeRequestModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="request_section">
                <input type="hidden" name="section_id" id="modal_section_id">
                
                <div class="form-group">
                    <label>Section:</label>
                    <div id="modal_section_name" style="font-weight: 600; color: #6a0dad;"></div>
                </div>
                
                <div class="form-group">
                    <label>Message (Optional):</label>
                    <textarea name="message" rows="4" placeholder="Why would you like to teach this section?"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRequestModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openRequestModal(sectionId, sectionName) {
            document.getElementById('modal_section_id').value = sectionId;
            document.getElementById('modal_section_name').textContent = sectionName;
            document.getElementById('requestModal').style.display = 'block';
        }
        
        function closeRequestModal() {
            document.getElementById('requestModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('requestModal');
            if (event.target === modal) {
                closeRequestModal();
            }
        }

        // Client-side search filter
        const sectionSearch = document.getElementById('sectionSearch');
        if (sectionSearch) {
            sectionSearch.addEventListener('input', function(){
                const q = this.value.trim().toLowerCase();
                document.querySelectorAll('.section-item').forEach(el => {
                    const hay = (el.getAttribute('data-search')||'').toLowerCase();
                    el.style.display = hay.includes(q) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>
