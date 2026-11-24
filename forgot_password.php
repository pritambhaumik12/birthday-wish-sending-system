<?php
// birthday_system01/forgot_password.php

header('Content-Type: application/json');
session_start();

require_once "config.php";

// PHPMailer Dependencies
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// SMTP Configuration (Same as your test_email_handler.php)
$smtp_host = 'smtp.gmail.com'; 
$smtp_user = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
$smtp_pass = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD 
$smtp_port = 587; 

$action = $_POST['action'] ?? '';

// ==========================================
// 1. SEND OTP
// ==========================================
if ($action === 'send_otp') {
    $input = trim($_POST['input_val'] ?? '');
    
    if (empty($input)) {
        echo json_encode(['success' => false, 'message' => 'Please enter username or email.']);
        exit;
    }

    // Check if user exists by username OR email
    $sql = "SELECT id, username, email FROM users WHERE username = ? OR email = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "ss", $input, $input);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        
        if (mysqli_stmt_num_rows($stmt) == 1) {
            mysqli_stmt_bind_result($stmt, $id, $username, $email);
            mysqli_stmt_fetch($stmt);

            if (empty($email)) {
                echo json_encode(['success' => false, 'message' => 'No email address found for this user. Contact Admin.']);
                exit;
            }

            // Generate 6-digit OTP
            $otp = rand(100000, 999999);
            
            // Store in Session (Temporary Cache)
            $_SESSION['reset_otp'] = $otp;
            $_SESSION['reset_user_id'] = $id;
            $_SESSION['reset_email'] = $email; // Optional verification
            $_SESSION['reset_otp_expiry'] = time() + 300; // 5 minutes expiry

            // Send Email
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $smtp_host;
                $mail->SMTPAuth   = true;
                $mail->Username   = $smtp_user;
                $mail->Password   = $smtp_pass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $smtp_port;

                $mail->setFrom($smtp_user, 'Birthday System Admin');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset OTP';
                $mail->Body    = "
                    <h3>Password Reset Request</h3>
                    <p>Your OTP for resetting the password is: <strong>$otp</strong></p>
                    <p>This OTP is valid for 5 minutes.</p>
                ";

                $mail->send();
                echo json_encode(['success' => true, 'message' => 'OTP sent to your registered email.']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Mailer Error: ' . $mail->ErrorInfo]);
            }
        } else {
            // Security: Generic message or specific? Using specific for now as requested.
            echo json_encode(['success' => false, 'message' => 'User not found.']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
}

// ==========================================
// 2. VERIFY OTP
// ==========================================
elseif ($action === 'verify_otp') {
    $entered_otp = trim($_POST['otp_val'] ?? '');
    
    if (!isset($_SESSION['reset_otp']) || !isset($_SESSION['reset_otp_expiry'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please request OTP again.']);
        exit;
    }

    if (time() > $_SESSION['reset_otp_expiry']) {
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_user_id']);
        echo json_encode(['success' => false, 'message' => 'OTP expired.']);
        exit;
    }

    if ($entered_otp == $_SESSION['reset_otp']) {
        // OTP Verified
        $_SESSION['otp_verified'] = true; // Mark as verified so they can proceed to reset
        echo json_encode(['success' => true, 'message' => 'OTP Verified.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP.']);
    }
}

// ==========================================
// 3. RESET PASSWORD
// ==========================================
elseif ($action === 'reset_password') {
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true || !isset($_SESSION['reset_user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Verify OTP first.']);
        exit;
    }

    $new_pass = trim($_POST['new_password'] ?? '');
    
    if (empty($new_pass) || strlen($new_pass) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    // Update Password in DB (Storing as plain text to match existing index.php logic)
    // If you upgrade to hashing later, use password_hash($new_pass, PASSWORD_DEFAULT)
    $sql = "UPDATE users SET password = ? WHERE id = ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "si", $new_pass, $_SESSION['reset_user_id']);
        
        if (mysqli_stmt_execute($stmt)) {
            // Cleanup Session
            unset($_SESSION['reset_otp']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_otp_expiry']);
            unset($_SESSION['otp_verified']);
            
            echo json_encode(['success' => true, 'message' => 'Password has been reset successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Something went wrong. Try again.']);
        }
        mysqli_stmt_close($stmt);
    }
} 
else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}
?>