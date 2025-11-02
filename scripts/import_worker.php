<?php
/**
 * Import Worker: processes queued CSV student imports from import_jobs table
 * Usage: php scripts/import_worker.php
 * Env:
 *   - BATCH_ROWS: rows per transaction (default 200)
 *   - LOOP=1 to keep running until queue empty
 */
if (PHP_SAPI !== 'cli') { die("Run from CLI\n"); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/email-functions.php';

// Ensure emails are queued during import
putenv('EMAIL_MODE=queue');

$BATCH = (int)(getenv('BATCH_ROWS') ?: 200);
$LOOP = (int)(getenv('LOOP') ?: 0) === 1;

function claim_job(PDO $pdo): ?array {
    // Pick oldest pending job
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT * FROM import_jobs WHERE status='pending' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $upd = $pdo->prepare("UPDATE import_jobs SET status='running', started_at=NOW(), updated_at=NOW() WHERE id=?");
            $upd->execute([$job['id']]);
            $pdo->commit();
            return $job;
        }
        $pdo->commit();
        return null;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_progress(PDO $pdo, int $jobId, int $processed, int $lastLine): void {
    $stmt = $pdo->prepare("UPDATE import_jobs SET processed_rows = ?, last_line = ?, updated_at=NOW() WHERE id = ?");
    $stmt->execute([$processed, $lastLine, $jobId]);
}

function complete_job(PDO $pdo, int $jobId): void {
    $stmt = $pdo->prepare("UPDATE import_jobs SET status='completed', finished_at=NOW(), updated_at=NOW() WHERE id=?");
    $stmt->execute([$jobId]);
}

function fail_job(PDO $pdo, int $jobId, string $err): void {
    $stmt = $pdo->prepare("UPDATE import_jobs SET status='failed', last_error=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([mb_substr($err, 0, 2000), $jobId]);
}

function process_job(PDO $pdo, array $job, int $batch): void {
    $jobId = (int)$job['id'];
    $file = $job['file_path'];
    if (!is_file($file)) {
        fail_job($pdo, $jobId, 'CSV not found: ' . $file);
        return;
    }

    $fh = fopen($file, 'r');
    if (!$fh) { fail_job($pdo, $jobId, 'Cannot open CSV'); return; }

    $line = 0;
    $processed = (int)$job['processed_rows'];
    $lastLine = (int)$job['last_line'];

    // Skip lines up to lastLine
    while ($line < $lastLine && ($r = fgetcsv($fh, 1000, ',')) !== false) { $line++; }

    // If starting fresh, skip header
    if ($line === 0) { fgetcsv($fh, 1000, ','); $line = 1; }

    $rowsThisTxn = 0;
    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh, 1000, ',')) !== false) {
            $line++;
            $rowsThisTxn++;
            $cols = array_map('trim', $row);
            if (count($cols) < 5) { continue; }
            list($firstName, $lastName, $email, $programInput, $yearLevel) = $cols;
            if ($firstName === '' || $lastName === '' || $email === '' || $programInput === '' || $yearLevel === '') { continue; }

            $program_id = find_program_id($pdo, $programInput);
            if (!$program_id) { continue; }

            $res = create_user($pdo, $firstName, $lastName, $email, (int)$program_id, (int)$yearLevel, 'student');
            // create_user handles auto-enroll; emails are queued via EMAIL_MODE=queue

            if ($rowsThisTxn >= $batch) {
                $pdo->commit();
                $processed += $rowsThisTxn;
                update_progress($pdo, $jobId, $processed, $line);
                $rowsThisTxn = 0;
                $pdo->beginTransaction();
            }
        }
        $pdo->commit();
        $processed += $rowsThisTxn;
        update_progress($pdo, $jobId, $processed, $line);
        complete_job($pdo, $jobId);
    } catch (Exception $e) {
        $pdo->rollBack();
        fail_job($pdo, $jobId, $e->getMessage());
    } finally {
        fclose($fh);
    }
}

$processedJobs = 0;

do {
    $job = claim_job($pdo);
    if (!$job) { echo "No pending import jobs.\n"; break; }
    echo "Processing Job #{$job['id']}...\n";
    process_job($pdo, $job, $BATCH);
    $processedJobs++;
} while ($LOOP);

echo "Done. Jobs processed: $processedJobs\n";
