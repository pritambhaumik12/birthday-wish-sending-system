<?php
// birthday_system01/otp_handler.php
session_start();
require_once "config.php";

// Include PHPMailer (Adjust paths if your structure is different)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'Invalid request'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $email = $_POST['email'] ?? '';

    // --- SEND OTP ---
    if ($action === 'send') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
            exit;
        }

        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        
        // Store in session
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_email'] = $email;
        $_SESSION['otp_expiry'] = time() + 300; // Valid for 5 minutes
        $_SESSION['is_email_verified'] = false; // Reset verification status

        $mail = new PHPMailer(true);
        try {
            // Server settings (Copied from cron_send_emails.php)
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
            $mail->Password   = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('USE_YOUR_OWN_EMAIL@gmail.com', 'Brainware University');
            $mail->addAddress($email);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Student Verification OTP';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
                    <h2 style='color: #28a745; text-align: center;'>Email Verification</h2>
                    <p style='color: #555; text-align: center;'>Your One-Time Password (OTP) for student basic details verification is:</p>
                    <div style='background: #f8f9fa; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                        <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #333;'>$otp</span>
                    </div>
                    <p style='color: #999; font-size: 12px; text-align: center;'>This OTP is valid for 5 minutes. Do not share this with anyone.</p>
                </div>
            ";

            $mail->send();
            $response = ['status' => 'success', 'message' => 'OTP sent successfully! Check your inbox.'];
        } catch (Exception $e) {
            $response = ['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo];
        }

    } 
    // --- VERIFY OTP ---
    elseif ($action === 'verify') {
        $user_otp = $_POST['otp'] ?? '';
        
        if (!isset($_SESSION['otp']) || !isset($_SESSION['otp_email'])) {
             $response = ['status' => 'error', 'message' => 'Session expired. Please request a new OTP.'];
        } elseif (time() > $_SESSION['otp_expiry']) {
             $response = ['status' => 'error', 'message' => 'OTP has expired. Please request a new one.'];
        } elseif (strtolower(trim($email)) !== strtolower(trim($_SESSION['otp_email']))) {
             $response = ['status' => 'error', 'message' => 'Email mismatch. Please request OTP for the current email.'];
        } elseif ($user_otp == $_SESSION['otp']) {
             $_SESSION['is_email_verified'] = true;
             $response = ['status' => 'success', 'message' => 'Email verified successfully!'];
        } else {
             $response = ['status' => 'error', 'message' => 'Incorrect OTP. Please try again.'];
        }
    }
}

echo json_encode($response);
?>