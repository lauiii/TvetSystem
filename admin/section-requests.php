<?php
/**
 * Section Requests - Admin approves/rejects instructor requests
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$error = '';
$msg = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $requestId = (int)($_POST['request_id'] ?? 0);
        $response = trim($_POST['admin_response'] ?? '');
        
        if (!$requestId) throw new Exception('Invalid request');
        
        // Get request details
        $req = $pdo->prepare("SELECT * FROM section_requests WHERE id = ?");
        $req->execute([$requestId]);
        $request = $req->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) throw new Exception('Request not found');
        
        if ($action === 'approve') {
            $pdo->beginTransaction();
            
            // Check if section is still available
            $check = $pdo->prepare("SELECT COUNT(*) FROM instructor_sections WHERE section_id = ?");
            $check->execute([$request['section_id']]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('This section already has an instructor assigned');
            }
            
            // Assign instructor to section
            $assign = $pdo->prepare("INSERT INTO instructor_sections (instructor_id, section_id) VALUES (?, ?)");
            $assign->execute([$request['instructor_id'], $request['section_id']]);
            
            // Update request status
            $update = $pdo->prepare("UPDATE section_requests SET status = 'approved', admin_response = ? WHERE id = ?");
            $update->execute([$response, $requestId]);
            
            $pdo->commit();
            $msg = 'Request approved and instructor assigned successfully!';
            
        } elseif ($action === 'reject') {
            $update = $pdo->prepare("UPDATE section_requests SET status = 'rejected', admin_response = ? WHERE id = ?");
            $update->execute([$response, $requestId]);
            $msg = 'Request rejected.';
        }
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Fetch all pending requests
$pendingRequests = $pdo->query("
    SELECT 
        sr.id as request_id,
        sr.instructor_id,
        sr.section_id,
        sr.request_message,
        sr.created_at,
        CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
        u.email as instructor_email,
        s.section_code,
        c.course_code,
        c.course_name,
        c.year_level,
        c.semester,
        p.code as program_code
    FROM section_requests sr
    INNER JOIN users u ON sr.instructor_id = u.id
    INNER JOIN sections s ON sr.section_id = s.id
    INNER JOIN courses c ON s.course_id = c.id
    INNER JOIN programs p ON c.program_id = p.id
    WHERE sr.status = 'pending'
    ORDER BY sr.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent processed requests
$processedRequests = $pdo->query("
    SELECT 
        sr.id as request_id,
        sr.status,
        sr.admin_response,
        sr.updated_at,
        CONCAT(u.first_name, ' ', u.last_name) as instructor_name,
        s.section_code,
        c.course_code,
        c.course_name
    FROM section_requests sr
    INNER JOIN users u ON sr.instructor_id = u.id
    INNER JOIN sections s ON sr.section_id = s.id
    INNER JOIN courses c ON s.course_id = c.id
    WHERE sr.status IN ('approved', 'rejected')
    ORDER BY sr.updated_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Section Requests - Admin</title>
  <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
  <style>
    .request-card {
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 16px;
      margin-bottom: 12px;
      background: #fff;
    }
    
    .request-info h4 {
      margin: 0 0 8px 0;
      color: #333;
      font-size: 16px;
    }
    
    .request-meta {
      font-size: 13px;
      color: #666;
    }
    
    .request-details {
      background: #f8f9fa;
      padding: 12px;
      border-radius: 4px;
      margin: 12px 0;
    }
    
    .request-details p {
      margin: 4px 0;
      font-size: 14px;
    }
    
    .request-message {
      background: #fff3cd;
      padding: 10px;
      border-radius: 4px;
      border-left: 3px solid #ffc107;
      margin: 10px 0;
      font-style: italic;
      font-size: 14px;
    }
    
    .request-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
      flex-wrap: wrap;
    }
    
    .form-inline {
      display: flex;
      gap: 8px;
      align-items: center;
      flex: 1;
      min-width: 300px;
    }
    
    .form-inline input[type="text"] {
      flex: 1;
      padding: 8px 10px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 14px;
    }
    
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 3px;
      font-size: 11px;
      font-weight: 600;
    }
    
    .badge-success {
      background: #d4edda;
      color: #155724;
    }
    
    .badge-danger {
      background: #f8d7da;
      color: #721c24;
    }
    
    .history-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid #eee;
    }
    
    .history-item:last-child {
      border-bottom: none;
    }
    
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #999;
    }
  </style>
</head>
<body>
<div class="admin-layout">
  <?php $active='section-requests'; require __DIR__.'/inc/sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <?php $pageTitle='Section Assignment Requests'; require __DIR__.'/inc/header.php'; ?>

      <?php if($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <!-- Pending Requests -->
      <div class="card">
        <h3>🔔 Pending Requests (<?= count($pendingRequests) ?>)</h3>
        
        <?php if (count($pendingRequests) > 0): ?>
          <?php foreach ($pendingRequests as $req): ?>
            <div class="request-card">
              <div class="request-header">
                <div class="request-info">
                  <h4><?= htmlspecialchars($req['instructor_name']) ?></h4>
                  <div class="request-meta">
                    📧 <?= htmlspecialchars($req['instructor_email']) ?> • 
                    📅 <?= date('M d, Y g:i A', strtotime($req['created_at'])) ?>
                  </div>
                </div>
              </div>
              
              <div class="request-details">
                <p><strong>Section:</strong> <?= htmlspecialchars($req['course_code']) ?> - <?= htmlspecialchars($req['section_code']) ?></p>
                <p><strong>Course:</strong> <?= htmlspecialchars($req['course_name']) ?></p>
                <p><strong>Program:</strong> <?= htmlspecialchars($req['program_code']) ?> | Year <?= $req['year_level'] ?> | Semester <?= $req['semester'] ?></p>
              </div>
              
              <?php if (!empty($req['request_message'])): ?>
              <div class="request-message">
                <strong>Message:</strong> <?= htmlspecialchars($req['request_message']) ?>
              </div>
              <?php endif; ?>
              
              <div class="request-actions">
                <form method="POST" class="form-inline" style="flex: 1;">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                  <input type="text" name="admin_response" placeholder="Response message (optional)">
                  <button type="submit" class="btn" onclick="return confirm('Approve this request?')">✓ Approve</button>
                </form>
                
                <form method="POST" class="form-inline">
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
                  <input type="text" name="admin_response" placeholder="Rejection reason (optional)">
                  <button type="submit" class="btn danger" onclick="return confirm('Reject this request?')">✗ Reject</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
            <h4 style="color: #666;">No Pending Requests</h4>
            <p>All requests have been processed.</p>
          </div>
        <?php endif; ?>
      </div>

      <!-- Request History -->
      <div class="card">
        <h3>📋 Recent History</h3>
        <div>
          <?php if (count($processedRequests) > 0): ?>
            <?php foreach ($processedRequests as $req): ?>
              <div class="history-item">
                <div>
                  <strong><?= htmlspecialchars($req['instructor_name']) ?></strong> 
                  → <?= htmlspecialchars($req['course_code']) ?> - <?= htmlspecialchars($req['section_code']) ?>
                  <?php if (!empty($req['admin_response'])): ?>
                    <div style="font-size: 13px; color: #666; margin-top: 4px;">
                      💬 <?= htmlspecialchars($req['admin_response']) ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div style="text-align: right;">
                  <?php if ($req['status'] === 'approved'): ?>
                    <span class="badge badge-success">Approved</span>
                  <?php else: ?>
                    <span class="badge badge-danger">Rejected</span>
                  <?php endif; ?>
                  <div style="font-size: 12px; color: #999; margin-top: 4px;">
                    <?= date('M d, Y', strtotime($req['updated_at'])) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="text-align: center; padding: 20px; color: #999;">
              No history yet.
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>
</div>
</body>
</html>
