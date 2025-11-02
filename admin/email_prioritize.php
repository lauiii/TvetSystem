<?php
/**
 * Admin endpoint: prioritize selected credential emails (set scheduled_at = NOW())
 * POST: ids[]=1&ids[]=2 or JSON {"ids":[1,2]}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$ids = [];
if (isset($_POST['ids'])) {
    $ids = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids']);
} else {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (isset($json['ids']) && is_array($json['ids'])) { $ids = $json['ids']; }
    }
}
$ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));
if (!$ids) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No ids provided']);
    exit;
}

// Ensure table exists
if (function_exists('ensureOutboxSchema')) { ensureOutboxSchema($pdo); }

// Update scheduled_at to now; keep status pending (also move failed back to pending)
$in = implode(',', array_fill(0, count($ids), '?'));
$sql = "UPDATE email_outbox SET status='pending', scheduled_at = NOW() WHERE id IN ($in)";
$stmt = $pdo->prepare($sql);
$stmt->execute($ids);

echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
