<?php
// birthday_system01/test_email_handler.php
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

// Initialize session
session_start();
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "config.php";

// PHPMailer Setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Configuration (Matched with cron_send_emails.php)
$base_url = "https://bwu-birthday-automation.page.gd/"; 
$smtp_host = 'smtp.gmail.com'; 
$smtp_user = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
$smtp_pass = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD 
$smtp_port = 587; 

$action = $_POST['action'] ?? '';

// --- ACTION: SEND TEST EMAIL ---
if ($action === 'send_email') {
    $recipient_name = trim($_POST['name'] ?? 'Test User');
    $recipient_email = trim($_POST['email'] ?? '');
    $copy_admin = isset($_POST['copy_admin']) ? (int)$_POST['copy_admin'] : 0;

    if (empty($recipient_email) || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing email address.']);
        exit;
    }

    // 1. Get Random Wish (To populate content)
    $wish_body = "<p>This is a test birthday wish message to verify the template.</p>";
    $wish_attachment = "";
    
    $sql_wish = "SELECT * FROM wishes WHERE is_deleted = 0 ORDER BY RAND() LIMIT 1";
    if ($res = mysqli_query($link, $sql_wish)) {
        if ($row = mysqli_fetch_assoc($res)) {
            $wish_body = $row['wish_message'];
            $wish_attachment = $row['photo_path'];
        }
    }
    
    // Fix Image Paths
    $wish_body = str_replace('src="uploads/', 'src="' . $base_url . 'uploads/', $wish_body);

    // 2. Send Email
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();                                            
        $mail->Host       = $smtp_host;                     
        $mail->SMTPAuth   = true;                                   
        $mail->Username   = $smtp_user;                     
        $mail->Password   = $smtp_pass;                               
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
        $mail->Port       = $smtp_port;                                    

        $mail->setFrom($smtp_user, 'Brainware University');
        
        // --- LOGIC CHANGE FOR ADMIN ONLY ---
        if ($copy_admin === 1) {
            // Send ONLY to Admin
            $mail->addAddress($smtp_user, 'Administrator');
            $greeting_name = "Administrator (Test for $recipient_name)";
            $subject_prefix = "[Admin Test] ";
        } else {
            // Send to the actual user
            $mail->addAddress($recipient_email, $recipient_name);
            $greeting_name = $recipient_name;
            $subject_prefix = "";
        }

        if (!empty($wish_attachment) && file_exists($wish_attachment)) {
            $mail->addAttachment($wish_attachment, 'Birthday_Card.jpg');
        }

        $mail->isHTML(true);                                  
        $mail->Subject = $subject_prefix . "Happy Birthday, " . $recipient_name . "! (Test)";
        
        // Define year for copyright
        $current_year = date("Y");

        // --- UPDATED TEMPLATE (MATCHING CRON_SEND_EMAILS.PHP) ---
        $email_template = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width,initial-scale=1'>
            <title>Test Birthday Email</title>
            <style>
                body { margin:0; padding:0; font-family:'Segoe UI', sans-serif; background-color:#f4f7f6; }
                .email-wrapper { width:100%; padding:40px 0; background-color:#f4f7f6; }
                .email-container { max-width:600px; margin:0 auto; background-color:#fff; border-radius:16px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1); }
                .header { background:linear-gradient(135deg,#0d47a1,#42a5f5); padding:30px; text-align:center; color:#fff; }
                
                /* UPDATED LOGO STYLE */
                .logo {
                    width: 90px;
                    height: auto;
                    background: #fff;
                    border-radius: 50%;
                    padding: 12px;
                    display: block;
                    margin: 0 auto 15px auto; /* Centers the image */
                    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
                }

                .content { padding:40px 30px; color:#333; text-align:center; }
                .greeting { font-size:24px; color:#1a237e; font-weight:700; margin-bottom:10px; }
                .wish-card { background:#fcfcfc; border:1px solid #e0e0e0; border-top:4px solid #42a5f5; border-radius:8px; padding:25px; margin:25px 0; text-align:center; font-style:italic; color:#555; }
                .footer { background:#eceff1; padding:20px; text-align:center; font-size:12px; color:#78909c; }
                .footer p { margin: 5px 0; }
            </style>
        </head>
        <body>
            <div class='email-wrapper'>
                <div class='email-container'>
                    <div class='header'>
                        <img src='{$base_url}BWU_logo.png' class='logo' alt='Brainware University' width='90'>
                        <h1 style='margin:0; font-size:26px;'>Brainware University</h1>
                        <h1 style='margin:0; font-size:26px;'>Happy Birthday!</h1>
                    </div>
                    <div class='content'>
                        <div class='greeting'>Hello, " . htmlspecialchars($greeting_name) . "! 🎉</div>
                        <p style='font-size:16px; line-height:1.5;'>This is a <strong>test email</strong> to verify the system design.</p>
                        
                        <div class='wish-card'>
                            $wish_body
                        </div>
                        
                         <p style='margin-top: 30px; font-size: 14px; color: #888;'>If you see the image above and the card styling correctly, the system is working fine.</p>
                    </div>
                    <div class='footer'>
                        <p>Brainware University</p>
                        <p>398, Ramkrishnapur Rd, Barasat, Kolkata, West Bengal 700125<p>
                        <p>Developed & powered by Tech Innavo.</p>
                        <p>&copy; $current_year All Rights Reserved.</p>
                        <p>This is a test message generated at " . date('Y-m-d H:i:s') . ".</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";

        $mail->Body = $email_template;
        $mail->AltBody = strip_tags($wish_body);

        $mail->send();

        // 3. Log to Database 
        $log_target_email = ($copy_admin === 1) ? $smtp_user : $recipient_email;
        
        $log_sql = "INSERT INTO email_logs (student_id, sent_to_email, status, sent_at) VALUES (?, ?, 'test_sent_manual', NOW())";
        if ($stmt_log = mysqli_prepare($link, $log_sql)) {
            $student_id_val = 0; // 0 for manual/test
            mysqli_stmt_bind_param($stmt_log, "is", $student_id_val, $log_target_email);
            mysqli_stmt_execute($stmt_log);
            mysqli_stmt_close($stmt_log);
        }

        echo json_encode(['success' => true, 'message' => 'Test email sent successfully!']);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Mailer Error: ' . $mail->ErrorInfo]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>