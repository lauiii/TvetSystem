<?php
/**
 * Admin: send a small batch of queued credential emails.
 * POST JSON/body: { "limit": 10, "rate_ms": 250 }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

// Ensure outbox exists
if (function_exists('ensureOutboxSchema')) { ensureOutboxSchema($pdo); }

$limit = 10;
$rateMs = 250;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // accept JSON or form
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $limit = isset($j['limit']) ? max(1, min(50, (int)$j['limit'])) : $limit;
            $rateMs = isset($j['rate_ms']) ? max(0, min(2000, (int)$j['rate_ms'])) : $rateMs;
        }
    }
    if (isset($_POST['limit'])) $limit = max(1, min(50, (int)$_POST['limit']));
    if (isset($_POST['rate_ms'])) $rateMs = max(0, min(2000, (int)$_POST['rate_ms']));
}

function msleep($ms) { usleep(max(0, (int)$ms) * 1000); }

$result = [ 'processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => [] ];

try {
    // Fetch oldest pending credential emails
    $stmt = $pdo->prepare("SELECT * FROM email_outbox 
        WHERE status='pending' AND subject='Your College Grading System Account' AND scheduled_at <= NOW()
        ORDER BY scheduled_at ASC, id ASC
        LIMIT ?");
    $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        foreach ($rows as $row) {
            $result['processed']++;
            try {
                mailerConfiguredOrThrow();
                $mail = new PHPMailer(true);
                configureSMTP($mail);
                $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
                $mail->addAddress($row['to_email'], $row['to_name'] ?: '');
                $mail->isHTML(true);
                $mail->Subject = $row['subject'];
                $mail->Body = $row['body_html'];
                if (!empty($row['body_text'])) $mail->AltBody = $row['body_text'];
                if (!empty($row['attachment_content']) && !empty($row['attachment_name'])) {
                    $mail->addStringAttachment($row['attachment_content'], $row['attachment_name'], 'base64', $row['attachment_mime'] ?: 'application/octet-stream');
                }
                $mail->send();
                $upd = $pdo->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), updated_at=NOW() WHERE id=?");
                $upd->execute([$row['id']]);
                $result['sent']++;
            } catch (Exception $e) {
                $attempts = (int)$row['attempts'] + 1;
                $delayMin = min(60, 1 << min(10, $attempts));
                $upd = $pdo->prepare("UPDATE email_outbox SET attempts = attempts + 1, last_error = ?, scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), updated_at=NOW(), status=CASE WHEN status='failed' THEN 'failed' ELSE status END WHERE id=?");
                $upd->execute([mb_substr(($mail->ErrorInfo ?? $e->getMessage()), 0, 1000), $delayMin, $row['id']]);
                // Optionally mark as failed after N attempts
                if ($attempts >= 5) {
                    $pdo->prepare("UPDATE email_outbox SET status='failed', updated_at=NOW() WHERE id=?")->execute([$row['id']]);
                }
                $result['failed']++;
                $result['errors'][] = [ 'id' => (int)$row['id'], 'error' => ($mail->ErrorInfo ?? $e->getMessage()) ];
            }
            if ($rateMs > 0) msleep($rateMs);
        }
    }

    $remaining = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending' AND subject='Your College Grading System Account' AND scheduled_at <= NOW()")
        ->fetchColumn();
    $result['remaining'] = $remaining;
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'result' => $result]);
}
