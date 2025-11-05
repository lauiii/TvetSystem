<?php

/**
 * Admin: send all queued credential emails (HEAVY USE)
 * Recommended to run during off-hours. Streams through the queue in batches.
 * POST JSON/body (optional): { "batch": 25, "max_total": 5000, "rate_ms": 150 }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

if (function_exists('ensureOutboxSchema')) {
    ensureOutboxSchema($pdo);
}

$batch = 25;           // per fetch/send
$maxTotal = 5000;      // hard cap protection
$rateMs = 150;         // sleep between sends

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($j['batch'])) $batch = max(1, min(100, (int)$j['batch']));
            if (isset($j['max_total'])) $maxTotal = max(1, min(20000, (int)$j['max_total']));
            if (isset($j['rate_ms'])) $rateMs = max(0, min(2000, (int)$j['rate_ms']));
        }
    }
    if (isset($_POST['batch'])) $batch = max(1, min(100, (int)$_POST['batch']));
    if (isset($_POST['max_total'])) $maxTotal = max(1, min(20000, (int)$_POST['max_total']));
    if (isset($_POST['rate_ms'])) $rateMs = max(0, min(2000, (int)$_POST['rate_ms']));
}

function msleep_all($ms)
{
    usleep(max(0, (int)$ms) * 1000);
}

$result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];

try {
    $totalToProcess = min($maxTotal, (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending' AND subject='Your College Grading System Account' AND scheduled_at <= NOW()")->fetchColumn());

    while ($result['processed'] < $totalToProcess) {
        // fetch next batch
        $stmt = $pdo->prepare("SELECT * FROM email_outbox 
            WHERE status='pending' AND subject='Your College Grading System Account' AND scheduled_at <= NOW()
            ORDER BY scheduled_at ASC, id ASC
            LIMIT ?");
        $stmt->bindValue(1, (int)$batch, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;

        foreach ($rows as $row) {
            $result['processed']++;
            if ($result['processed'] > $totalToProcess) break 2; // safety
            try {
                $mailData = [
                    "to"      => $row['to_email'],
                    "subject" => $row['subject'],
                    "from"    => SMTP_FROM,
                    "text"    => !empty($row['body_text']) ? $row['body_text'] : null,
                    "html"    => $row['body_html'],
                ];

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, "https://honovel.deno.dev/api/mailer/send");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept: application/json",        // ✅ Required
                    "Content-Type: application/json"   // ✅ Required
                ]);

                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailData));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $pdo->prepare("
                        UPDATE email_outbox
                        SET status='sent', sent_at=NOW(), updated_at=NOW()
                        WHERE id=?
                    ")->execute([$row['id']]);
                    $result['sent']++;
                } else {
                    $pdo->prepare("
                        UPDATE email_outbox
                        SET status='failed', updated_at=NOW()
                        WHERE id=?
                    ")->execute([$row['id']]);
                    $result['failed']++;
                }
            } catch (Exception $e) {
                $attempts = (int)$row['attempts'] + 1;
                $delayMin = min(60, 1 << min(10, $attempts));
                $pdo->prepare("UPDATE email_outbox SET attempts = attempts + 1, last_error = ?, scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), updated_at=NOW(), status=CASE WHEN status='failed' THEN 'failed' ELSE status END WHERE id=?")
                    ->execute([mb_substr(($mail->ErrorInfo ?? $e->getMessage()), 0, 1000), $delayMin, $row['id']]);
                if ($attempts >= 5) {
                    $pdo->prepare("UPDATE email_outbox SET status='failed', updated_at=NOW() WHERE id=?")->execute([$row['id']]);
                }
                $result['failed']++;
                $result['errors'][] = ['id' => (int)$row['id'], 'error' => ($mail->ErrorInfo ?? $e->getMessage())];
            }
            if ($rateMs > 0) msleep_all($rateMs);
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
