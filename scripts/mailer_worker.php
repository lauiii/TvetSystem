<?php

/**
 * Mailer Worker: delivers queued emails from email_outbox
 * Usage (CLI): php scripts/mailer_worker.php
 * Optional env:
 *   - MAILER_BATCH: number of emails per run (default 50)
 *   - MAILER_RATE_MS: delay between sends in milliseconds (default 250)
 *   - MAILER_MAX_ATTEMPTS: max retries (default 5)
 *   - LOOP: if set to 1, keep looping until queue is empty
 */

if (PHP_SAPI !== 'cli') {
    die("Run from CLI\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';

use PHPMailer\PHPMailer\PHPMailer;

// Ensure we don't re-enqueue inside worker
putenv('EMAIL_MODE=send');

$BATCH = (int)(getenv('MAILER_BATCH') ?: 50);
$RATE_MS = (int)(getenv('MAILER_RATE_MS') ?: 250);
$MAX_ATTEMPTS = (int)(getenv('MAILER_MAX_ATTEMPTS') ?: 5);
$LOOP = (int)(getenv('LOOP') ?: 0) === 1;

function msleep($ms)
{
    usleep(max(0, (int)$ms) * 1000);
}

function fetch_batch(PDO $pdo, int $limit, int $maxAttempts): array
{
    $stmt = $pdo->prepare("SELECT * FROM email_outbox WHERE status='pending' AND scheduled_at <= NOW() AND attempts < ? ORDER BY scheduled_at ASC, id ASC LIMIT ?");
    $stmt->execute([$maxAttempts, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mark_sent(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("UPDATE email_outbox SET status='sent', sent_at=NOW(), updated_at=NOW() WHERE id=?");
    $stmt->execute([$id]);
}

function mark_failed(PDO $pdo, int $id, string $error, int $attempts): void
{
    // Exponential backoff in minutes
    $delayMin = min(60, 1 << min(10, $attempts)); // cap at 60 minutes
    $stmt = $pdo->prepare("UPDATE email_outbox SET attempts = attempts + 1, last_error = ?, scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), updated_at=NOW() WHERE id=?");
    $stmt->execute([mb_substr($error, 0, 1000), $delayMin, $id]);
}

$processedTotal = 0;

do {
    $batch = fetch_batch($pdo, $BATCH, $MAX_ATTEMPTS);
    if (!$batch) {
        echo "No pending emails.\n";
        break;
    }

    foreach ($batch as $row) {
        $processedTotal++;
        try {
            $mailData = [
                "to"        => $row['to_email'],
                "subject"   => $row['subject'],
                "html"      => $row['body_html'],
                "text"      => $row['body_text'] ?? '',
                "fromName"  => SMTP_FROM_NAME,
                "fromEmail" => SMTP_FROM,
            ];

            // Include attachment if present
            if (!empty($row['attachment_content']) && !empty($row['attachment_name'])) {
                $mailData["attachments"] = [
                    [
                        "filename" => $row['attachment_name'],
                        "content"  => base64_encode($row['attachment_content']), // API safe
                        "mimeType" => $row['attachment_mime'] ?: "application/octet-stream",
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

            // Only mark as sent if the API returned 200
            if ($httpCode === 200) {
                mark_sent($pdo, (int)$row['id']);
                echo "Sent ID {$row['id']} to {$row['to_email']}\n";
            } else {
                echo "Failed to send ID {$row['id']} to {$row['to_email']} (HTTP $httpCode)\n";
            }
        } catch (Exception $e) {
            $attempts = (int)$row['attempts'] + 1;
            mark_failed($pdo, (int)$row['id'], $mail->ErrorInfo ?: $e->getMessage(), $attempts);
            echo "Failed ID {$row['id']} ({$row['to_email']}): " . ($mail->ErrorInfo ?: $e->getMessage()) . "\n";
        }
        msleep($RATE_MS);
    }
} while ($LOOP);

echo "Processed $processedTotal emails this run.\n";
