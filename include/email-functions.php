<?php
/**
 * Email Functions using PHPMailer
 * Handles sending emails for credentials and notifications
 * 
 * Note: Install PHPMailer via Composer: composer require phpmailer/phpmailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader (adjust path as needed). Try common paths.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Define email helpers: use PHPMailer if available, otherwise provide lightweight fallbacks.
if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {

    /**
     * Send student login credentials via PHPMailer
     */
    function sendStudentCredentials($email, $firstName, $studentID, $password) {
        try {
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

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

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send generic notification via PHPMailer
     */
    function sendNotificationEmail($email, $firstName, $subject, $message) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $firstName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = "<html><body><h2>" . htmlspecialchars($subject) . "</h2>" . $message . "</body></html>";
            $mail->AltBody = strip_tags($message);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Notification email failed: " . ($mail->ErrorInfo ?? $e->getMessage()));
            return false;
        }
    }

    /**
     * Send HTML email with a single in-memory attachment (e.g., CSV or PDF)
     */
    function sendEmailWithAttachment($email, $firstName, $subject, $html, $attachmentName, $attachmentContent, $mime = 'text/csv') {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
            $mail->addAddress($email, $firstName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = strip_tags($html);

            if (!empty($attachmentContent)) {
                $mail->addStringAttachment($attachmentContent, $attachmentName, 'base64', $mime);
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