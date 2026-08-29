<?php
require_once 'config/mail.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

echo "<h2>SMTP Connection Test</h2>";
echo "<p>This page will try to send a test email to your Gmail account and print the exact error message below.</p>";
echo "<div style='background: #1e1e1e; color: #00ff00; padding: 20px; font-family: monospace; white-space: pre-wrap; word-wrap: break-word;'>";
echo "Starting test...\n\n";

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    die("Error: PHPMailer class not found. Ensure the path is correct.");
}

$mail = new PHPMailer(true);

try {
    // Enable verbose debug output
    $mail->SMTPDebug = SMTP::DEBUG_SERVER; 
    
    // Server settings
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    // Recipients - sending to your own email address for the test
    $mail->setFrom(SMTP_USER, 'Test System');
    $mail->addAddress(SMTP_USER); 

    // Content
    $mail->isHTML(false);
    $mail->Subject = "WAMP SMTP Connection Test";
    $mail->Body    = "This is a test email to check SMTP connection from WAMP. If you receive this, your email configuration is working!";

    $mail->send();
    echo "\n\n✅ SUCCESS: Mail has been sent successfully! Check your inbox.";
} catch (Exception $e) {
    echo "\n\n❌ ERROR: Message could not be sent.\nMailer Error: {$mail->ErrorInfo}";
}

echo "</div>";
?>
