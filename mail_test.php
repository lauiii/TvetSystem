<?php
// Quick mailer test script.
// Usage: http://localhost/tvetsystem/mail_test.php?to=you@example.com&name=Your+Name&subject=Test&message=Hello&debug=2

if (isset($_GET['debug'])) {
    $lvl = (int)$_GET['debug'];
    putenv('SMTP_DEBUG=' . $lvl);
}

require __DIR__ . '/config.php';
require __DIR__ . '/include/email-functions.php';

$to = isset($_GET['to']) ? trim($_GET['to']) : '';
$name = isset($_GET['name']) ? trim($_GET['name']) : 'User';
$subject = isset($_GET['subject']) ? trim($_GET['subject']) : 'Mailer Test';
$message = isset($_GET['message']) ? $_GET['message'] : 'This is a test email.';

header('Content-Type: text/plain');

if ($to === '') {
    echo "Provide ?to=recipient@example.com&name=Name&subject=...&message=...&debug=0..4\n";
    exit(0);
}

$ok = sendNotificationEmail($to, $name, $subject, nl2br($message));

echo $ok ? "OK: mail sent" : "ERROR: mail failed (check PHP error_log for details)";
