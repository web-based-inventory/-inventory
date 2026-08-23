<?php
// config/mail.php

// Required for PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Load PHPMailer classes directly (manual install, no Composer required)
require_once __DIR__ . '/../lib/phpmailer/Exception.php';
require_once __DIR__ . '/../lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../lib/phpmailer/SMTP.php';

// SMTP Configuration Constants
// IMPORTANT: Use a Gmail App Password, NOT your regular Gmail password.
// To generate an App Password, go to Google Account -> Security -> 2-Step Verification -> App passwords
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-gmail@gmail.com'); // Replace with your Gmail address
define('SMTP_PASS', 'your-16-digit-app-password'); // Replace with your Gmail App Password

/**
 * Sends a password reset email using PHPMailer and Gmail SMTP.
 *
 * @param string $toEmail   The recipient's email address
 * @param string $resetLink The generated reset link
 * @param string $shopName  The name of the shop (for email subject and body)
 * @return bool True on success, False on failure (error is logged)
 */
function sendResetEmail($toEmail, $resetLink, $shopName) {
    // If autoloader isn't found, we can't send email
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer class not found. Ensure 'composer require phpmailer/phpmailer' was run.");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_USER, $shopName . ' System');
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(false); // Using plain text for simplicity and reliability, as in the original
        $mail->Subject = "Password Reset Request";
        
        $body = "You have requested a password reset for your " . $shopName . " account.\n\n";
        $body .= "Please click the following link to reset your password:\n";
        $body .= $resetLink . "\n\n";
        $body .= "This link will expire in 30 minutes.\n";
        $body .= "If you did not request this, please ignore this email.";
        
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log the server-side error
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
