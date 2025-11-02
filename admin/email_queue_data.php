<?php
/**
 * Admin JSON endpoint: list credential email queue in real time
 * Query params: status=pending|failed|all, limit=100, order=priority|time
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

header('Content-Type: application/json; charset=utf-8');

// Ensure table exists
if (function_exists('ensureOutboxSchema')) {
    ensureOutboxSchema($pdo);
}

$status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'active';
// 'active' => pending or failed
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$order = isset($_GET['order']) ? strtolower($_GET['order']) : 'priority';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$where = [];
$params = [];
// Only credential emails
$where[] = "subject = 'Your College Grading System Account'";
if ($status === 'pending') {
    $where[] = "status = 'pending'";
} elseif ($status === 'failed') {
    $where[] = "status = 'failed'";
} elseif ($status === 'sent') {
    $where[] = "status = 'sent'";
} else { // active (pending or failed)
    $where[] = "status IN ('pending','failed')";
}

if ($q !== '') {
    $where[] = "(to_email LIKE ? OR to_name LIKE ? OR last_error LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$orderBy = "";
if ($order === 'time') {
    $orderBy = "ORDER BY scheduled_at ASC, id ASC";
} else { // priority: failed first, then oldest pending, then most attempts
    $orderBy = "ORDER BY (status='failed') DESC, scheduled_at ASC, attempts DESC, id ASC";
}

$sql = "SELECT id, to_email, to_name, status, attempts, last_error, scheduled_at, sent_at
        FROM email_outbox
        WHERE " . implode(' AND ', $where) . "
        $orderBy
        LIMIT $limit";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Also include counts (global, unfiltered)
    $counts = [
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject='Your College Grading System Account' AND status='pending'")->fetchColumn(),
        'failed'  => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject='Your College Grading System Account' AND status='failed'")->fetchColumn(),
        'sent'    => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject='Your College Grading System Account' AND status='sent'")->fetchColumn(),
    ];
    echo json_encode(['ok' => true, 'data' => $rows, 'counts' => $counts]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
