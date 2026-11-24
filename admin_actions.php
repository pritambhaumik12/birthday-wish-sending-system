<?php
// birthday_system01/admin_actions.php

// 1. Initialize
session_start();
header('Content-Type: application/json');

require_once "config.php";

// PHPMailer Dependencies
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// 2. Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$response = ['status' => 'error', 'message' => 'Invalid Request'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // --- FETCH ALL ADMINS ---
    if ($action === 'fetch_all') {
        $admins = [];
        $sql = "SELECT id, full_name, email, username, designation, status, role FROM users ORDER BY id ASC";
        if ($res = mysqli_query($link, $sql)) {
            while ($row = mysqli_fetch_assoc($res)) {
                $admins[] = $row;
            }
            $response = ['status' => 'success', 'data' => $admins];
        } else {
            $response = ['status' => 'error', 'message' => 'Database error.'];
        }
    }

    // --- STEP 1: VERIFY CURRENT PASSWORD ---
    elseif ($action === 'verify_password') {
        $input_pass = $_POST['password'] ?? '';
        $user_id = $_SESSION['id'];
        
        $sql = "SELECT password FROM users WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $db_pass);
            if (mysqli_stmt_fetch($stmt)) {
                if ($input_pass === $db_pass) { // Note: In production, use password_verify() if hashed
                    $response = ['status' => 'success', 'message' => 'Password verified.'];
                } else {
                    $response = ['status' => 'error', 'message' => 'Incorrect password.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'User not found.'];
            }
            mysqli_stmt_close($stmt);
        }
    }

    // --- STEP 2: SEND OTP TO CURRENT ADMIN ---
    elseif ($action === 'send_auth_otp') {
        $current_email = $_SESSION['email'] ?? '';
        
        if (empty($current_email)) {
            // Fallback: fetch from DB if not in session
            $uid = $_SESSION['id'];
            $q = mysqli_query($link, "SELECT email FROM users WHERE id = $uid");
            $r = mysqli_fetch_assoc($q);
            $current_email = $r['email'];
        }

        if (!filter_var($current_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Your account does not have a valid email for verification.']);
            exit;
        }

        $otp = rand(100000, 999999);
        $_SESSION['admin_auth_otp'] = $otp;
        $_SESSION['admin_auth_expiry'] = time() + 300; // 5 mins

        // Send Email
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
            $mail->Password   = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('USE_YOUR_OWN_EMAIL@gmail.com', 'BWU System Admin');
            $mail->addAddress($current_email);

            $mail->isHTML(true);
            $mail->Subject = 'Admin Action Authorization Code';
            $mail->Body    = "<h3>Admin Authorization</h3><p>Use the following OTP to authorize the creation of a new administrator:</p><h1>$otp</h1><p>Valid for 5 minutes.</p>";

            $mail->send();
            $response = ['status' => 'success', 'message' => 'OTP sent to your email (' . $current_email . ').'];
        } catch (Exception $e) {
            $response = ['status' => 'error', 'message' => 'Mailer Error: ' . $mail->ErrorInfo];
        }
    }

    // --- STEP 3: VERIFY OTP & CREATE ADMIN ---
    elseif ($action === 'create_admin') {
        $otp_input = $_POST['otp'] ?? '';
        
        // OTP Check
        if (!isset($_SESSION['admin_auth_otp']) || time() > $_SESSION['admin_auth_expiry']) {
            echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please restart.']);
            exit;
        }
        if ($otp_input != $_SESSION['admin_auth_otp']) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid OTP.']);
            exit;
        }

        // Data Retrieval
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $designation = trim($_POST['designation']);
        $role = 'admin'; // Default
        $status = 'active';

        // Duplicate Check
        $check = mysqli_query($link, "SELECT id FROM users WHERE username='$username' OR email='$email'");
        if (mysqli_num_rows($check) > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username or Email already exists.']);
            exit;
        }

        // Insert
        $sql = "INSERT INTO users (full_name, email, username, password, designation, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssss", $full_name, $email, $username, $password, $designation, $role, $status);
            if (mysqli_stmt_execute($stmt)) {
                // Clear OTP
                unset($_SESSION['admin_auth_otp']);

                // Send Credentials to NEW Admin
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
                    $mail->Password   = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('USE_YOUR_OWN_EMAIL@gmail.com', 'Brainware University Admin');
                    $mail->addAddress($email, $full_name);

                    $mail->isHTML(true);
                    $mail->Subject = 'Admin Access Credentials';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd;'>
                            <h2 style='color: #007bff;'>Welcome to Birthday Automation System</h2>
                            <p>Hello <strong>$full_name</strong>,</p>
                            <p>You have been added as an Administrator ($designation).</p>
                            <hr>
                            <p><strong>Username:</strong> $username</p>
                            <p><strong>Password:</strong> $password</p>
                            <hr>
                            <p>Please login and change your password immediately.</p>
                        </div>";

                    $mail->send();
                    $response = ['status' => 'success', 'message' => 'Admin created & credentials emailed successfully.'];
                } catch (Exception $e) {
                    $response = ['status' => 'success', 'message' => 'Admin created, but failed to email credentials.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'Database error during insertion.'];
            }
            mysqli_stmt_close($stmt);
        }
    }

    // --- UPDATE ADMIN ---
    elseif ($action === 'update_admin') {
        $id = $_POST['id'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']); // NEW
        $designation = trim($_POST['designation']);
        $status = $_POST['status'];
        $password = $_POST['password'];

        // Validate Email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
            exit;
        }

        // Check for Duplicates (excluding current ID)
        $check = mysqli_prepare($link, "SELECT id FROM users WHERE email = ? AND id != ?");
        mysqli_stmt_bind_param($check, "si", $email, $id);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        if (mysqli_stmt_num_rows($check) > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Email already exists for another user.']);
            exit;
        }
        mysqli_stmt_close($check);

        // Build Query
        $sql_part = "UPDATE users SET full_name=?, email=?, designation=?, status=?";
        $params_types = "ssss";
        $params_vals = [$full_name, $email, $designation, $status];

        if (!empty($password)) {
            $sql_part .= ", password=?";
            $params_types .= "s";
            $params_vals[] = $password;
        }

        $sql_part .= " WHERE id=?";
        $params_types .= "i";
        $params_vals[] = $id;

        if ($stmt = mysqli_prepare($link, $sql_part)) {
            mysqli_stmt_bind_param($stmt, $params_types, ...$params_vals);
            if (mysqli_stmt_execute($stmt)) {
                $response = ['status' => 'success', 'message' => 'Admin updated successfully.'];
            } else {
                $response = ['status' => 'error', 'message' => 'Failed to update.'];
            }
            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($link);
echo json_encode($response);
?>