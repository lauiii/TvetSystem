<?php
/**
 * Admin: purge all credential emails from email_outbox
 * Deletes rows with subject 'Your College Grading System Account'.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

header('Content-Type: application/json; charset=utf-8');

try {
    if (function_exists('ensureOutboxSchema')) { ensureOutboxSchema($pdo); }
    $countStmt = $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE subject='Your College Grading System Account'");
    $toDelete = (int)$countStmt->fetchColumn();
    $pdo->exec("DELETE FROM email_outbox WHERE subject='Your College Grading System Account'");
    echo json_encode(['ok' => true, 'deleted' => $toDelete]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
