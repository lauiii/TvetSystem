<?php

/**
 * Admin: send selected queued credential emails immediately.
 * POST JSON: { "ids": [1,2,3], "rate_ms": 100 }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (function_exists('ensureOutboxSchema')) {
    ensureOutboxSchema($pdo);
}

$raw = file_get_contents('php://input');
$ids = [];
$rateMs = 100;
if ($raw) {
    $j = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $ids = array_values(array_filter(array_map('intval', (array)($j['ids'] ?? []))));
        $rateMs = isset($j['rate_ms']) ? max(0, min(2000, (int)$j['rate_ms'])) : $rateMs;
    }
}
if (!$ids) {
    echo json_encode(['ok' => false, 'error' => 'No ids provided']);
    exit;
}

function msleep_sel($ms)
{
    usleep(max(0, (int)$ms) * 1000);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];

try {
    // Load selected pending credential emails
    $sql = "SELECT * FROM email_outbox WHERE id IN ($placeholders) AND status='pending' AND subject='Your College Grading System Account'";
    $stmt = $pdo->prepare($sql);
    foreach ($ids as $i => $v) {
        $stmt->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $result['processed']++;
        try {
            $mailData = [
                "to"         => $row['to_email'],
                "subject"    => $row['subject'],
                "html"       => $row['body_html'],
                "text"       => $row['body_text'] ?? "",
                "fromName"   => SMTP_FROM_NAME,
                "fromEmail"  => SMTP_FROM,
            ];

            // Handle attachment if present
            if (!empty($row['attachment_content']) && !empty($row['attachment_name'])) {
                $mailData["attachments"] = [
                    [
                        "filename" => $row['attachment_name'],
                        "content" => $row['attachment_content'], // base64 expected
                        "mimeType" => $row['attachment_mime'] ?: "application/octet-stream"
                    ]
                ];
            }

            $ch = curl_init("https://honovel.deno.dev/api/mailer/send");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json",
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailData));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // ✅ Only update DB if successfully sent
            if ($httpCode === 200) {
                $pdo->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), updated_at=NOW() WHERE id=?")
                    ->execute([$row['id']]);
                $result['sent']++;
            } else {
                // ❌ Do nothing — keep row pending
                $result['failed'] = ($result['failed'] ?? 0) + 1;
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
        if ($rateMs > 0) msleep_sel($rateMs);
    }

    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'result' => $result]);
}
