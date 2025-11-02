<?php
/**
 * Check email_outbox status (counts and sample pending rows)
 * Usage: php scripts/check_outbox.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
if (!function_exists('ensureOutboxSchema')) {
    echo "email-functions not loaded properly.\n";
    exit(1);
}
ensureOutboxSchema($pdo);

$counts = [
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending'")->fetchColumn(),
    'failed'  => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='failed'")->fetchColumn(),
    'sent'    => (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='sent'")->fetchColumn(),
];

echo "Email Outbox Counts:\n";
echo "  Pending: {$counts['pending']}\n";
echo "  Failed:  {$counts['failed']}\n";
echo "  Sent:    {$counts['sent']}\n\n";

$stmt = $pdo->query("SELECT id, to_email, subject, status, attempts, DATE_FORMAT(scheduled_at, '%Y-%m-%d %H:%i:%s') AS scheduled_at FROM email_outbox WHERE status='pending' ORDER BY scheduled_at ASC, id ASC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "First 10 pending:\n";
if (!$rows) {
    echo "  (none)\n";
} else {
    foreach ($rows as $r) {
        echo sprintf("  #%d  %-35s  attempts=%d  scheduled=%s\n", $r['id'], $r['to_email'], $r['attempts'], $r['scheduled_at']);
    }
}
