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

    function mailerConfiguredOrThrow() {
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

    function configureSMTP(PHPMailer $mail) {
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
    function shouldQueueEmails(): bool {
        $mode = getenv('EMAIL_MODE');
        return $mode !== false && strtolower($mode) === 'queue';
    }

    function ensureOutboxSchema(PDO $pdo): void {
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
        } catch (Exception $e) { /* ignore */ }
    }

    function enqueueEmail(PDO $pdo, string $toEmail, string $toName, string $subject, string $html, string $text = '', ?string $attName = null, ?string $attContent = null, ?string $attMime = null): bool {
        ensureOutboxSchema($pdo);
        $sql = "INSERT INTO email_outbox (to_email, to_name, subject, body_html, body_text, attachment_name, attachment_mime, attachment_content, status) VALUES (?,?,?,?,?,?,?,?, 'pending')";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$toEmail, $toName, $subject, $html, $text, $attName, $attMime, $attContent]);
    }

    /**
     * Send student login credentials via PHPMailer
     */
    function sendStudentCredentials($email, $firstName, $studentID, $password) {
        try {
            mailerConfiguredOrThrow();
            $mail = new PHPMailer(true);
            configureSMTP($mail);

            // Recipients
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $firstName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Your College Grading System Account';

            $mail->Body = "<html><body>" .
                "<h2>Welcome to " . SITE_NAME . "</h2>" .
                "<p>Hello " . htmlspecialchars($firstName) . ",</p>" .
                "<p>Your account has been created. Credentials below:</p>" .
                "<p><strong>Student ID:</strong> " . htmlspecialchars($studentID) . "</p>" .
                "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>" .
                "<p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>" .
                "<p><a href='" . SITE_URL . "/login.php'>Login to your account</a></p>" .
                "</body></html>";

            $mail->AltBody = "Welcome to " . SITE_NAME . "\n\n" .
                "Student ID: $studentID\nEmail: $email\nPassword: $password\n\n" .
                "Please log in at: " . SITE_URL . "/login.php";

            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $email, $firstName, $mail->Subject, $mail->Body, $mail->AltBody);
            }
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send instructor credentials (temp password) via PHPMailer
     */
    function sendInstructorCredentials($email, $name, $password) {
        try {
            mailerConfiguredOrThrow();
            $mail = new PHPMailer(true);
            configureSMTP($mail);
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $subject = 'Your ' . SITE_NAME . ' Instructor Account';
            $body = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
                  . '<p>Your account password has been reset by an administrator. Use the credentials below to sign in, then change your password:</p>'
                  . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '<br>'
                  . '<strong>Temporary Password:</strong> ' . htmlspecialchars($password) . '</p>'
                  . '<p><a href="' . htmlspecialchars(SITE_URL . '/login.php') . '">Login to your account</a></p>';
            $mail->Subject = $subject;
            $mail->Body = renderEmailTemplate($subject, $body);
            $mail->AltBody = strip_tags($body);
            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $email, $name, $mail->Subject, $mail->Body, $mail->AltBody);
            }
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Instructor credentials email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send generic notification via PHPMailer
     */
    function renderEmailTemplate($title, $contentHtml) {
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
    function get_admin_email(PDO $pdo = null) {
        // Prefer session email if logged-in admin
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && !empty($_SESSION['email'])) {
            return $_SESSION['email'];
        }
        // Fallback: query active admin
        try {
            if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) { $pdo = $GLOBALS['pdo']; }
            if ($pdo instanceof PDO) {
                $st = $pdo->query("SELECT email FROM users WHERE role='admin' AND (status IS NULL OR status='active') ORDER BY id LIMIT 1");
                $em = trim((string)$st->fetchColumn());
                if ($em !== '') return $em;
            }
        } catch (Exception $e) { /* ignore */ }
        // Last resort: use SMTP_FROM
        return defined('SMTP_FROM') ? SMTP_FROM : '';
    }

    function sendNotificationEmail($email, $firstName, $subject, $message) {
        try {
            mailerConfiguredOrThrow();
            $mail = new PHPMailer(true);
            configureSMTP($mail);

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $firstName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = renderEmailTemplate($subject, $message);
            $mail->AltBody = strip_tags($message);

            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $email, $firstName, $mail->Subject, $mail->Body, $mail->AltBody);
            }
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Notification email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send a notification to the current admin.
     */
    function sendAdminNotification($subject, $messageHtml) {
        $to = get_admin_email();
        if ($to === '') return false;
        return sendNotificationEmail($to, 'Administrator', $subject, $messageHtml);
    }

    /**
     * Send a notification with attachment to current admin.
     */
    function sendAdminEmailWithAttachment($subject, $html, $attachmentName, $attachmentContent, $mime = 'text/csv') {
        try {
            mailerConfiguredOrThrow();
            $mail = new PHPMailer(true);
            configureSMTP($mail);
            $to = get_admin_email();
            if ($to === '') return false;
            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($to, 'Administrator');
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags($html);
            if (!empty($attachmentContent)) {
                $mail->addStringAttachment($attachmentContent, $attachmentName, 'base64', $mime);
            }
            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $to, 'Administrator', $mail->Subject, $mail->Body, $mail->AltBody, $attachmentName, $attachmentContent, $mime);
            }
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Admin attachment email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send HTML email with a single in-memory attachment (e.g., CSV or PDF)
     */
    function sendEmailWithAttachment($email, $firstName, $subject, $html, $attachmentName, $attachmentContent, $mime = 'text/csv') {
        try {
            mailerConfiguredOrThrow();
            $mail = new PHPMailer(true);
            configureSMTP($mail);

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $firstName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags($html);

            if (!empty($attachmentContent)) {
                $mail->addStringAttachment($attachmentContent, $attachmentName, 'base64', $mime);
            }

            if (shouldQueueEmails()) {
                return enqueueEmail($GLOBALS['pdo'], $email, $firstName, $mail->Subject, $mail->Body, $mail->AltBody, $attachmentName, $attachmentContent, $mime);
            }

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Attachment email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send flag notification to instructor
     */
    function sendFlagNotification($email, $instructorName, $studentName, $courseName, $issue) {
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
        function sendStudentCredentials($email, $firstName, $studentID, $password) {
            error_log("[DEV EMAIL] To: $email | StudentID=$studentID | Password=$password");
            return true;
        }
    }

    if (!function_exists('sendNotificationEmail')) {
        function sendNotificationEmail($email, $firstName, $subject, $message) {
            error_log("[DEV NOTIFY] To: $email | Subject: $subject");
            return true;
        }
    }

    if (!function_exists('sendFlagNotification')) {
        function sendFlagNotification($email, $instructorName, $studentName, $courseName, $issue) {
            error_log("[DEV FLAG] To: $email | Student: $studentName | Course: $courseName | Issue: $issue");
            return true;
        }
    }

}
?>


