<?php

/**
 * Email Functions using PHPMailer
 * Handles sending emails for credentials and notifications
 * 
 * Note: Install PHPMailer via Composer: composer require phpmailer/phpmailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Load Composer's autoloader (adjust path as needed). Try common paths.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Define email helpers: use PHPMailer if available, otherwise provide lightweight fallbacks.
if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {

    function mailerConfiguredOrThrow()
    {
        $missing = [];
        if (empty(SMTP_HOST)) $missing[] = 'SMTP_HOST';
        if (empty(SMTP_PORT)) $missing[] = 'SMTP_PORT';
        if (empty(SMTP_USER)) $missing[] = 'SMTP_USER';
        if (empty(SMTP_PASS)) $missing[] = 'SMTP_PASS';
        if (empty(SMTP_FROM)) $missing[] = 'SMTP_FROM';
        if ($missing) {
            throw new Exception('Mailer not configured. Missing: ' . implode(', ', $missing));
        }
    }

    function configureSMTP(PHPMailer $mail)
    {
        $mail->isSMTP();
        // Optional: force IPv4 to avoid IPv6 DNS/connectivity issues on some Windows/XAMPP setups
        $forceIPv4 = getenv('SMTP_FORCE_IPV4');
        $host = SMTP_HOST;
        if ($forceIPv4 !== false && (int)$forceIPv4 === 1) {
            $host = gethostbyname($host);
        }
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $secure = strtolower(SMTP_SECURE);
        if ($secure === 'ssl' || $secure === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = SMTP_PORT ?: 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT ?: 587;
        }
        $mail->SMTPAutoTLS = true;
        // In the configureSMTP function, add this line before setting $mail->SMTPDebug
        $debugLevel = defined('SMTP_DEBUG_OVERRIDE') ? SMTP_DEBUG_OVERRIDE : SMTP_DEBUG;
        $mail->SMTPDebug = $debugLevel; // 0-4
        $mail->CharSet = 'UTF-8';
        // Shorter timeouts so failures don't hang the request
        $mail->Timeout = (int)(getenv('SMTP_TIMEOUT') ?: 20);
        $timelimit = getenv('SMTP_TIMELIMIT');
        if ($timelimit !== false) {
            $mail->Timelimit = (int)$timelimit;
        }
        $mail->SMTPKeepAlive = false;
        // Optional: relax peer verification if needed (set SMTP_VERIFY_PEER=0)
        $verifyPeer = getenv('SMTP_VERIFY_PEER');
        if ($verifyPeer !== false && (int)$verifyPeer === 0) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }
    }

    // Queue support
    function shouldQueueEmails(): bool
    {
        $mode = getenv('EMAIL_MODE');
        return $mode !== false && strtolower($mode) === 'queue';
    }

    function ensureOutboxSchema(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_outbox (
                id INT AUTO_INCREMENT PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) NULL,
                subject VARCHAR(255) NOT NULL,
                body_html MEDIUMTEXT NOT NULL,
                body_text TEXT NULL,
                attachment_name VARCHAR(255) NULL,
                attachment_mime VARCHAR(100) NULL,
                attachment_content LONGBLOB NULL,
                status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status_scheduled (status, scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) { /* ignore */
        }
    }

    function enqueueEmail(PDO $pdo, string $toEmail, string $toName, string $subject, string $html, string $text = '', ?string $attName = null, ?string $attContent = null, ?string $attMime = null): bool
    {
        ensureOutboxSchema($pdo);
        $sql = "INSERT INTO email_outbox (to_email, to_name, subject, body_html, body_text, attachment_name, attachment_mime, attachment_content, status) VALUES (?,?,?,?,?,?,?,?, 'pending')";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$toEmail, $toName, $subject, $html, $text, $attName, $attMime, $attContent]);
    }

    /**
     * Send student login credentials via PHPMailer
     */
    function sendStudentCredentials($email, $firstName, $studentID, $password)
    {
        try {
            $mode = getenv('EMAIL_MODE');
            if ($mode !== false && in_array(strtolower($mode), ['off', 'disabled', 'none'], true)) {
                return true; // Skip sending entirely
            }

            // Subject + Templates (unchanged)
            $subject = 'Your Student Account Has Been Created';

            $body = '<p>Hello ' . htmlspecialchars($firstName) . ',</p>'
                . '<p>Your student account has been created.</p>'
                . '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb;margin:12px 0;line-height:1.5;">'
                . '<div><strong>Username:</strong> ' . htmlspecialchars($email) . '</div>'
                . '<div><strong>Student ID:</strong> ' . htmlspecialchars($studentID) . '</div>'
                . '<div><strong>Temporary password:</strong> ' . htmlspecialchars($password) . '</div>'
                . '</div>'
                . '<p>For security, this temporary password will expire in 24 hours. Please log in and change your password immediately.</p>'
                . '<p><a href="' . htmlspecialchars(SITE_URL . '/login.php') . '" style="display:inline-block;padding:10px 14px;background:#6a0dad;color:#fff;text-decoration:none;border-radius:6px;">Login to your account</a></p>'
                . '<p style="color:#64748b;font-size:12px;">If the button does not work, copy and paste this URL into your browser: ' . htmlspecialchars(SITE_URL . '/login.php') . '</p>'
                . '<p>Thank you,<br>TVET Department — Andres Soriano Colleges of Bislig</p>';

            $html = renderEmailTemplate($subject, $body);
            $text = "Hello {$firstName},\n\n"
                . "Your student account has been created.\n\n"
                . "Username: {$email}\n"
                . "Student ID: {$studentID}\n"
                . "Temporary password: {$password}\n\n"
                . "For security, this temporary password will expire in 24 hours. Please log in at " . SITE_URL . "/login.php and change your password immediately.\n\n"
                . "Thank you,\nTVET Department — Andres Soriano Colleges of Bislig";

            // ✅ Queue mode
            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $email, $firstName, $subject, $html, $text);
            }

            // ✅ Immediate send via Deno API
            $mailData = [
                "to" => $email,
                "fromName" => SMTP_FROM_NAME,
                "fromEmail" => SMTP_FROM,
                "subject" => $subject,
                "html" => $html,
                "text" => $text,
            ];

            $ch = curl_init("https://honovel.deno.dev/api/mailer/send");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json",
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mailData));
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($status === 200);
        } catch (Exception $e) {
            error_log("Email sending failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send instructor credentials (temp password) via PHPMailer
     */
    function sendInstructorCredentials($email, $name, $password)
    {
        try {
            $mode = getenv('EMAIL_MODE');
            if ($mode !== false && in_array(strtolower($mode), ['off', 'disabled', 'none'], true)) {
                return true;
            }

            $subject = 'Your ' . SITE_NAME . ' Instructor Account';

            $bodyHtml = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
                . '<p>Your account password has been reset by an administrator. Use the credentials below to sign in, then change your password:</p>'
                . '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb;margin:12px 0;line-height:1.5;">'
                . '<div><strong>Email:</strong> ' . htmlspecialchars($email) . '</div>'
                . '<div><strong>Temporary Password:</strong> ' . htmlspecialchars($password) . '</div>'
                . '</div>'
                . '<p><a href="' . htmlspecialchars(SITE_URL . '/login.php') . '" style="display:inline-block;padding:10px 14px;background:#6a0dad;color:#fff;text-decoration:none;border-radius:6px;">Login to your account</a></p>'
                . '<p style="color:#64748b;font-size:12px;">If the button does not work, copy and paste this URL into your browser: '
                . htmlspecialchars(SITE_URL . '/login.php') . '</p>';

            $bodyText = strip_tags($bodyHtml);


            // ✅ If queue enabled → Store to DB only
            if (shouldQueueEmails()) {
                return enqueueEmail(
                    $GLOBALS['pdo'],
                    $email,
                    $name,
                    $subject,
                    $bodyHtml,
                    $bodyText
                );
            }


            // ✅ Otherwise: send via Deno Mailer API
            $payload = json_encode([
                'fromName'  => SMTP_FROM_NAME,
                'fromEmail' => SMTP_FROM,
                'to'        => $email,
                'subject'   => $subject,
                'html'      => renderEmailTemplate($subject, $bodyHtml),
                'text'      => $bodyText
            ]);

            $ch = curl_init("https://honovel.deno.dev/api/mailer/send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            curl_close($ch);

            if ($error || $status >= 300) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log("Instructor credentials email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send generic notification via PHPMailer
     */
    function renderEmailTemplate($title, $contentHtml)
    {
        $school = htmlspecialchars(SITE_NAME);
        $titleEsc = htmlspecialchars($title);
        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<style>body{font-family:system-ui,Segoe UI,Arial;color:#222;background:#f8fafc;padding:0;margin:0}'
            . '.wrap{max-width:680px;margin:20px auto;background:#fff;border:1px solid #eee;border-radius:10px;overflow:hidden;box-shadow:0 6px 16px rgba(0,0,0,0.06)}'
            . '.hdr{background:#6a0dad;color:#fff;padding:14px 18px;font-weight:700;letter-spacing:.02em}'
            . '.inner{padding:18px} h2{margin:0 0 12px;color:#6a0dad} p{margin:8px 0}</style>'
            . '</head><body><div class="wrap"><div class="hdr">' . $school . '</div>'
            . '<div class="inner"><h2>' . $titleEsc . '</h2>' . $contentHtml . '</div>'
            . '</div></body></html>';
    }

    /**
     * Resolve current admin email from DB/session.
     */
    function get_admin_email(PDO $pdo = null)
    {
        // Prefer session email if logged-in admin
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['email'])) {
            return $_SESSION['email'];
        }
        // Fallback: query active admin
        try {
            if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                $pdo = $GLOBALS['pdo'];
            }
            if ($pdo instanceof PDO) {
                $st = $pdo->query("SELECT email FROM users WHERE role='admin' AND (status IS NULL OR status='active') ORDER BY id LIMIT 1");
                $em = trim((string)$st->fetchColumn());
                if ($em !== '') return $em;
            }
        } catch (Exception $e) { /* ignore */
        }
        // Last resort: use SMTP_FROM
        return defined('SMTP_FROM') ? SMTP_FROM : '';
    }

    function sendNotificationEmail($email, $firstName, $subject, $message)
    {
        try {
            $mode = getenv('EMAIL_MODE');
            if ($mode !== false && in_array(strtolower($mode), ['off', 'disabled', 'none'], true)) {
                return true;
            }

            $bodyHtml = renderEmailTemplate($subject, $message);
            $bodyText = strip_tags($message);

            // ✅ Queue first (same behavior as before)
            if (shouldQueueEmails()) {
                return enqueueEmail(
                    $GLOBALS['pdo'],
                    $email,
                    $firstName,
                    $subject,
                    $bodyHtml,
                    $bodyText
                );
            }


            // ✅ Otherwise send via API POST
            $payload = json_encode([
                'fromName'  => SMTP_FROM_NAME,
                'fromEmail' => SMTP_FROM,
                'to'        => $email,
                'subject'   => $subject,
                'html'      => $bodyHtml,
                'text'      => $bodyText,
            ]);

            $ch = curl_init("https://honovel.deno.dev/api/mailer/send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json",
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            curl_close($ch);

            // ✅ Fail-safe — do not mark as sent on failure ✅
            if ($error || $status >= 300) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log("Notification email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send a notification to the current admin.
     */
    function sendAdminNotification($subject, $messageHtml)
    {
        $to = get_admin_email();
        if ($to === '') return false;
        return sendNotificationEmail($to, 'Administrator', $subject, $messageHtml);
    }

    /**
     * Send a notification with attachment to current admin.
     */
    function sendAdminEmailWithAttachment($subject, $html, $attachmentName, $attachmentContent, $mime = 'text/csv')
    {
        try {
            $mode = getenv('EMAIL_MODE');
            if ($mode !== false && in_array(strtolower($mode), ['off', 'disabled', 'none'], true)) {
                return true;
            }

            $to = get_admin_email();
            if ($to === '') return false;

            $bodyHtml = $html;
            $bodyText = strip_tags($html);


            // ✅ Queue first (same old logic)
            if (shouldQueueEmails()) {
                return enqueueEmail(
                    $GLOBALS['pdo'],
                    $to,
                    'Administrator',
                    $subject,
                    $bodyHtml,
                    $bodyText,
                    $attachmentName ?? null,
                    $attachmentContent ?? null,
                    $mime ?? null
                );
            }


            // ✅ Direct send through Deno API
            $payload = [
                'fromName'  => SMTP_FROM_NAME,
                'fromEmail' => SMTP_FROM,
                'to'        => $to,
                'subject'   => $subject,
                'html'      => $bodyHtml,
                'text'      => $bodyText
            ];

            // ✅ Only include attachments if exists
            if (!empty($attachmentContent)) {
                $payload['attachments'] = [[
                    'filename' => $attachmentName,
                    'content'  => $attachmentContent,
                    'mime'     => $mime ?? 'application/octet-stream'
                ]];
            }

            $ch = curl_init("https://honovel.deno.dev/api/mailer/send");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Accept: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            curl_close($ch);

            // ✅ No marking sent on fail ✅
            if ($error || $status >= 300) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            error_log("Admin attachment email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send HTML email with a single in-memory attachment (e.g., CSV or PDF)
     */
    function sendEmailWithAttachment($email, $firstName, $subject, $html, $attachmentName, $attachmentContent, $mime = 'text/csv')
    {
        try {
            $mailData = [
                "to"        => $email,
                "subject"   => $subject,
                "html"      => $html,
                "text"      => strip_tags($html),
                "fromName"  => SMTP_FROM_NAME,
                "fromEmail" => SMTP_FROM,
            ];

            // Include attachment if present
            if (!empty($attachmentContent) && !empty($attachmentName)) {
                $mailData["attachments"] = [
                    [
                        "filename" => $attachmentName,
                        "content"  => base64_encode($attachmentContent), // API safe
                        "mimeType" => $mime ?: "application/octet-stream",
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

            // Queue logic
            if (shouldQueueEmails()) {
                return enqueueEmail(
                    $GLOBALS['pdo'],
                    $email,
                    $firstName,
                    $subject,
                    $html,
                    strip_tags($html),
                    $attachmentName,
                    $attachmentContent,
                    $mime
                );
            }

            // Only return true if API returns 200
            return $httpCode === 200;
        } catch (Exception $e) {
            error_log("Attachment email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send flag notification to instructor
     */
    function sendFlagNotification($email, $instructorName, $studentName, $courseName, $issue)
    {
        $subject = "Student Flag Alert - " . $courseName;
        $message = "<p>A student has been flagged in your course:</p>" .
            "<ul>" .
            "<li><strong>Student:</strong> " . htmlspecialchars($studentName) . "</li>" .
            "<li><strong>Course:</strong> " . htmlspecialchars($courseName) . "</li>" .
            "<li><strong>Issue:</strong> " . htmlspecialchars($issue) . "</li>" .
            "</ul>" .
            "<p>Please review this matter at your earliest convenience.</p>";

        return sendNotificationEmail($email, $instructorName, $subject, $message);
    }
} else {
    // Fallback: simple logger-based implementations for development when PHPMailer is not installed
    if (!function_exists('sendStudentCredentials')) {
        function sendStudentCredentials($email, $firstName, $studentID, $password)
        {
            error_log("[DEV EMAIL] To: $email | StudentID=$studentID | Password=$password");
            return true;
        }
    }

    if (!function_exists('sendNotificationEmail')) {
        function sendNotificationEmail($email, $firstName, $subject, $message)
        {
            error_log("[DEV NOTIFY] To: $email | Subject: $subject");
            return true;
        }
    }

    if (!function_exists('sendFlagNotification')) {
        function sendFlagNotification($email, $instructorName, $studentName, $courseName, $issue)
        {
            error_log("[DEV FLAG] To: $email | Student: $studentName | Course: $courseName | Issue: $issue");
            return true;
        }
    }
}
