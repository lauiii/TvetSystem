<?php
// Check if files exist and include them with proper path
$configPath = __DIR__ . '/include/config.php';
$emailFunctionsPath = __DIR__ . '/include/email-functions.php';

// Debug file paths
echo "Looking for config at: " . $configPath . "<br>";
echo "File exists: " . (file_exists($configPath) ? 'Yes' : 'No') . "<br><br>";

// Try alternative paths if needed
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.php';
    echo "Trying alternate config path: " . $configPath . "<br>";
    echo "File exists: " . (file_exists($configPath) ? 'Yes' : 'No') . "<br><br>";
}

// Include files if they exist
if (file_exists($configPath)) {
    require_once $configPath;
} else {
    die("Cannot find config.php file. Please check your directory structure.");
}

if (file_exists($emailFunctionsPath)) {
    require_once $emailFunctionsPath;
} else {
    // Try alternative path
    $emailFunctionsPath = __DIR__ . '/email-functions.php';
    if (file_exists($emailFunctionsPath)) {
        require_once $emailFunctionsPath;
    } else {
        die("Cannot find email-functions.php file. Please check your directory structure.");
    }
}

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Email Configuration Test</h1>";

// Display current SMTP configuration (without showing password)
echo "<h2>Current Configuration:</h2>";
echo "<ul>";
echo "<li>SMTP Host: " . (defined('SMTP_HOST') ? SMTP_HOST : 'Not defined') . "</li>";
echo "<li>SMTP Port: " . (defined('SMTP_PORT') ? SMTP_PORT : 'Not defined') . "</li>";
echo "<li>SMTP User: " . (defined('SMTP_USER') ? SMTP_USER : 'Not defined') . "</li>";
echo "<li>SMTP From: " . (defined('SMTP_FROM') ? SMTP_FROM : 'Not defined') . "</li>";
echo "<li>SMTP From Name: " . (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Not defined') . "</li>";
echo "<li>SMTP Secure: " . (defined('SMTP_SECURE') ? SMTP_SECURE : 'Not defined') . "</li>";
echo "<li>SMTP Debug: " . (defined('SMTP_DEBUG') ? SMTP_DEBUG : 'Not defined') . "</li>";
echo "</ul>";

// Test sending an email
if (isset($_POST['send_test'])) {
    $to = $_POST['test_email'];
    $name = "Test User";
    $subject = "TVET System Email Test";
    $message = "<p>This is a test email from your TVET System.</p>
                <p>If you received this email, your email configuration is working correctly.</p>
                <p>Time sent: " . date('Y-m-d H:i:s') . "</p>";
    
    echo "<h2>Attempting to send test email to: " . htmlspecialchars($to) . "</h2>";
    
    // Temporarily increase debug level
    define('SMTP_DEBUG_OVERRIDE', 2);
    
    // Capture debug output
    ob_start();
    $result = sendNotificationEmail($to, $name, $subject, $message);
    $debug = ob_get_clean();
    
    if ($result) {
        echo "<div style='color: green; padding: 10px; border: 1px solid green; margin: 10px 0;'>";
        echo "<strong>Success!</strong> Email appears to have been sent successfully.";
        echo "</div>";
    } else {
        echo "<div style='color: red; padding: 10px; border: 1px solid red; margin: 10px 0;'>";
        echo "<strong>Failed!</strong> Email could not be sent. Check the debug output below.";
        echo "</div>";
    }
    
    // Display debug information
    echo "<h3>Debug Output:</h3>";
    echo "<pre style='background-color: #f5f5f5; padding: 10px; overflow: auto; max-height: 400px;'>";
    echo htmlspecialchars($debug);
    echo "</pre>";
}
?>

<h2>Send Test Email</h2>
<form method="post">
    <label for="test_email">Email address to send test to:</label><br>
    <input type="email" id="test_email" name="test_email" required style="width: 300px; margin: 10px 0;"><br>
    <button type="submit" name="send_test" style="padding: 5px 15px;">Send Test Email</button>
</form>