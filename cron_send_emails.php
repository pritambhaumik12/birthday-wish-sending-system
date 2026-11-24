<?php
// birthday_system01/cron_send_emails.php

// ==========================================
// 1. INITIALIZATION & SETUP
// ==========================================
date_default_timezone_set('Asia/Kolkata');

$is_cli = (php_sapi_name() == 'cli');
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$mode   = isset($_REQUEST['mode']) ? $_REQUEST['mode'] : ''; 

$should_run_batch = ($is_cli || $action == 'send_batch');

if (!$is_cli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once "config.php";

mysqli_query($link, "SET time_zone = '+05:30'");

// Helper to get table names if config functions aren't loaded (fallback)
if (!function_exists('get_student_table')) {
    function get_student_table($y) { return "students_" . preg_replace('/\D/', '', $y); }
}
if (!function_exists('get_log_table')) {
    function get_log_table($y) { return "email_logs_" . preg_replace('/\D/', '', $y); }
}

// ==========================================
// 2. LOGIC: SEND EMAILS (BATCH PROCESS)
// ==========================================
if ($should_run_batch) {
    
    $log_output = []; 
    $success_count = 0;
    $error_count = 0;

    // --- CONFIGURATION ---
    $base_url = "https://bwu-birthday-automation.page.gd/"; // Update this to your actual URL
    $smtp_host = 'smtp.gmail.com'; 
    $smtp_user = 'USE_YOUR_OWN_EMAIL@gmail.com'; // USE YOUR OWN API / EMAIL
    $smtp_pass = 'USE_YOUR_OWN_API_KEY';        // USE YOUR OWN API / APP PASSWORD 
    $smtp_port = 587; 

    function appendLog(&$logs, $msg, $is_cli_mode) {
        $logs[] = $msg;
        if ($is_cli_mode) {
            echo strip_tags($msg) . PHP_EOL;
        }
    }

    $text_output_mode = ($is_cli || $mode == 'log');
    appendLog($log_output, "Process started at " . date('H:i:s'), $text_output_mode);

    // --- FETCH ALL YEARS ---
    $years = [];
    $sql_y = "SELECT year_val FROM years_master";
    if ($res_y = mysqli_query($link, $sql_y)) {
        while ($row = mysqli_fetch_assoc($res_y)) $years[] = $row['year_val'];
    }

    // --- AGGREGATE BIRTHDAYS FROM ALL YEARS ---
    $queue = []; // Stores all students to email

    foreach ($years as $yr) {
        $stu_table = get_student_table($yr);
        $log_table = get_log_table($yr);

        // Check table exists to prevent errors
        $chk = mysqli_query($link, "SHOW TABLES LIKE '$stu_table'");
        if (mysqli_num_rows($chk) == 0) continue;

        // SQL: Fetch students having birthday TODAY who haven't been sent an email TODAY
        $sql_students = "SELECT id, student_name, student_mail_id 
                         FROM `$stu_table` 
                         WHERE MONTH(date_of_birth) = MONTH(CURDATE()) 
                         AND DAY(date_of_birth) = DAY(CURDATE()) 
                         AND is_deleted = 0
                         AND id NOT IN (
                            SELECT student_id FROM `$log_table` 
                            WHERE DATE(sent_at) = CURDATE() AND status = 'sent'
                         )";

        if ($result = mysqli_query($link, $sql_students)) {
            while ($row = mysqli_fetch_assoc($result)) {
                // Attach table info to row so we know where to log later
                $row['batch_year'] = $yr; 
                $row['log_table'] = $log_table;
                $queue[] = $row;
            }
        }
    }

    if (empty($queue)) {
        appendLog($log_output, "No pending birthdays found for today across all batches.", $text_output_mode);
    } else {
        // --- RANDOM WISH SELECTION (Global Wish Library) ---
        $wish_body = "<p>Wishing you a very Happy Birthday!</p>";
        $wish_attachment = "";
        $wish_title = "Happy Birthday";

        $sql_wish = "SELECT * FROM wishes WHERE is_deleted = 0 ORDER BY RAND() LIMIT 1";
        if ($res_w = mysqli_query($link, $sql_wish)) {
            if ($row_w = mysqli_fetch_assoc($res_w)) {
                $wish_body = $row_w['wish_message'];
                $wish_attachment = $row_w['photo_path']; 
                $wish_title = !empty($row_w['title']) ? $row_w['title'] : "Special Birthday Wish";
                appendLog($log_output, "Selected Random Wish: " . htmlspecialchars($wish_title), $text_output_mode);
            }
        }
        
        // Fix image paths
        $wish_body = str_replace('src="uploads/', 'src="' . $base_url . 'uploads/', $wish_body);

        // --- PROCESSING QUEUE ---
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtp_port;
            $mail->setFrom($smtp_user, 'Brainware University');

            foreach ($queue as $student) {
                try {
                    $mail->clearAddresses();
                    $mail->clearAttachments();
                    $mail->addAddress($student['student_mail_id'], $student['student_name']);
                    
                    if (!empty($wish_attachment) && file_exists($wish_attachment)) {
                        $mail->addAttachment($wish_attachment, 'Birthday_Card.jpg');
                    }
                    
                    $mail->isHTML(true);
                    $mail->Subject = "Happy Birthday, " . $student['student_name'] . "!";
                    $current_year = date("Y");

                    $email_template = "
                    <html>
                    <body style='margin:0; padding:0; font-family:sans-serif; background-color:#f4f7f6;'>
                        <div style='width:100%; padding:20px 0; background-color:#f4f7f6;'>
                            <div style='max-width:600px; margin:0 auto; background-color:#fff; border-radius:12px; overflow:hidden; border:1px solid #ddd; box-shadow:0 4px 10px rgba(0,0,0,0.05);'>
                                <div style='background:linear-gradient(135deg,#0d47a1,#42a5f5); padding:35px; text-align:center; color:#fff;'>
                                    <img src='{$base_url}BWU_logo.png' style='width:85px; background:#fff; border-radius:50%; padding:10px; margin-bottom:15px; box-shadow:0 4px 8px rgba(0,0,0,0.2);'>
                                    <h1 style='margin:0; font-size:28px;'>Brainware University</h1>
                                </div>
                                <div style='padding:40px 30px; text-align:center; color:#333;'>
                                    <h2 style='color:#1a237e; font-size:24px;'>Happy Birthday, {$student['student_name']}! 🎉</h2>
                                    <div style='font-size:16px; line-height:1.7; color:#555; background:#f9f9f9; padding:20px; border-radius:8px; border-left:5px solid #42a5f5;'>$wish_body</div>
                                </div>
                                <div style='background:#eceff1; padding:25px; text-align:center; font-size:12px; color:#78909c;'>
                                    <p style='margin:0; font-weight:bold;'>Brainware University</p>
                                    <p style='margin:5px 0;'>398, Ramkrishnapur Rd, Barasat, Kolkata, 700125</p>
                                    <p>Developed & powered by Tech Innavo.</p>
                                    <p style='margin:0;'>&copy; $current_year All Rights Reserved.</p>
                                    
                                </div>
                            </div>
                        </div>
                    </body>
                    </html>";

                    $mail->Body = $email_template;
                    $mail->send();
                    
                    // --- DYNAMIC LOG INSERTION ---
                    $s_id = $student['id'];
                    $s_mail = mysqli_real_escape_string($link, $student['student_mail_id']);
                    $log_table_name = $student['log_table'];
                    
                    mysqli_query($link, "INSERT INTO `$log_table_name` (student_id, sent_to_email, status, sent_at) VALUES ('$s_id', '$s_mail', 'sent', NOW())");
                    
                    appendLog($log_output, "<span class='text-success'>[SUCCESS]</span> Mail sent to: " . $student['student_name'] . " (Batch 20" . $student['batch_year'] . ")", $text_output_mode);
                    $success_count++;
                } catch (Exception $e) { 
                    appendLog($log_output, "<span class='text-danger'>[ERROR]</span> Failed for " . $student['student_name'] . ": " . $mail->ErrorInfo, $text_output_mode);
                    $error_count++; 
                }
            }
        } catch (Exception $e) {
            appendLog($log_output, "[CRITICAL] Mailer Error: " . $e->getMessage(), $text_output_mode);
        }
    }

    if ($text_output_mode) exit(0);

    // Post-Redirect-Get Pattern
    $_SESSION['email_run_logs'] = $log_output;
    $_SESSION['flash_notification'] = [
        'type' => ($success_count > 0) ? 'success' : 'info',
        'message' => "Process Finished. Sent: $success_count, Failed: $error_count"
    ];
    header("Location: cron_send_emails.php");
    exit;
}

// ==========================================
// 3. UI PREVIEW (Dynamic Aggregation)
// ==========================================
$preview_students = [];
$pending_count = 0;

// Fetch Years again for View
$years = [];
$sql_y = "SELECT year_val FROM years_master ORDER BY year_val DESC";
if ($res_y = mysqli_query($link, $sql_y)) {
    while ($row = mysqli_fetch_assoc($res_y)) $years[] = $row['year_val'];
}

foreach ($years as $yr) {
    $stu_table = get_student_table($yr);
    $log_table = get_log_table($yr);

    // Safety Check
    $chk = mysqli_query($link, "SHOW TABLES LIKE '$stu_table'");
    if (mysqli_num_rows($chk) == 0) continue;

    // Check birthdays in this table
    $sql_preview = "SELECT s.id, s.student_name, s.student_mail_id, s.department, '$yr' as batch_year,
                    (SELECT COUNT(*) FROM `$log_table` l WHERE l.student_id = s.id AND DATE(l.sent_at) = CURDATE() AND l.status = 'sent') as is_sent
                    FROM `$stu_table` s
                    WHERE MONTH(s.date_of_birth) = MONTH(CURDATE()) 
                    AND DAY(s.date_of_birth) = DAY(CURDATE()) 
                    AND s.is_deleted = 0";

    if ($result_preview = mysqli_query($link, $sql_preview)) {
        while ($row = mysqli_fetch_assoc($result_preview)) {
            if ($row['is_sent'] == 0) $pending_count++;
            $preview_students[] = $row;
        }
    }
}

$flash = $_SESSION['flash_notification'] ?? null;
$logs = $_SESSION['email_run_logs'] ?? null;
unset($_SESSION['flash_notification'], $_SESSION['email_run_logs']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Manager | Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: url('university.jpg') no-repeat center center fixed; background-size: cover; font-family: 'Segoe UI', sans-serif; min-height: 100vh; }
        
        #loader { position: fixed; inset: 0; background: #ffffff; display: flex; align-items: center; justify-content: center; z-index: 9999; }
        .spinner-container { position: relative; width: 90px; height: 90px; display: flex; align-items: center; justify-content: center; }
        .spinner { position: absolute; width: 100%; height: 100%; border: 8px solid #e9ecef; border-top: 8px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite; }
        .loader-logo { width: 50px; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        .wrapper { margin: 30px auto; padding: 30px; background: rgba(255, 255, 255, 0.98); border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.3); max-width: 900px; }
        .console-log { background: #1e1e1e; color: #00ff00; font-family: 'Courier New', monospace; padding: 15px; border-radius: 10px; max-height: 250px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #333; }
        .badge-status { font-size: 0.8rem; padding: 5px 12px; border-radius: 20px; }
        .badge-batch { font-size: 0.75rem; background-color: #6c757d; color: white; border-radius: 4px; padding: 2px 6px; }
    </style>
</head>
<body>

<div id="loader">
    <div class="spinner-container">
        <div class="spinner"></div>
        <img src="BWU_logo.png" class="loader-logo">
    </div>
</div>

<div class="container">
    <div class="wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
            <div>
                <h2 class="text-primary font-weight-bold mb-0">Email Manager</h2>
                <p class="text-muted small mb-0">Library-based Random Wish System</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill shadow-sm">Dashboard</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show shadow-sm">
                <i class="fas fa-info-circle mr-2"></i> <?php echo $flash['message']; ?>
                <button type="button" class="close" data-dismiss="alert">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($logs): ?>
            <div class="mb-4">
                <h6 class="font-weight-bold text-dark"><i class="fas fa-terminal mr-2"></i>System Execution Logs:</h6>
                <div class="console-log">
                    <?php foreach($logs as $log): ?>
                        <div><span class="text-muted">[<?php echo date('H:i:s'); ?>]</span> <?php echo $log; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center mb-5 p-4 bg-light rounded-lg shadow-sm border">
            <h4 class="font-weight-bold text-secondary"><?php echo date('l, F j, Y'); ?></h4>
            <?php if ($pending_count > 0): ?>
                <div class="py-2">
                    <i class="fas fa-paper-plane fa-3x text-primary mb-3"></i>
                    <h5 class="text-primary"><?php echo $pending_count; ?> Email(s) Pending</h5>
                    <p class="text-muted small">The system will automatically process these using a random wish from your library.</p>
                </div>
                <form action="cron_send_emails.php" method="post" id="autoProcessForm">
                    <input type="hidden" name="action" value="send_batch">
                    <button type="submit" id="sendBtn" class="btn btn-primary btn-lg px-5 rounded-pill shadow">Initialize Delivery</button>
                </form>
            <?php else: ?>
                <div class="text-success h5 mt-3"><i class="fas fa-check-double mr-2"></i>All scheduled wishes for today have been dispatched.</div>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 15px;">
            <div class="card-header bg-white font-weight-bold text-primary">
                <i class="fas fa-users mr-2"></i> Today's Birthdays (All Batches)
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>Student Name</th><th>Department</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($preview_students as $s): ?>
                        <tr>
                            <td class="font-weight-bold">
                                <?php echo htmlspecialchars($s['student_name']); ?> 
                                <span class="badge-batch ml-2">Batch 20<?php echo $s['batch_year']; ?></span>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($s['student_mail_id']); ?></small>
                            </td>
                            <td><span class="badge badge-info px-2"><?php echo htmlspecialchars($s['department']); ?></span></td>
                            <td>
                                <?php echo ($s['is_sent'] > 0) 
                                    ? '<span class="badge badge-success badge-status"><i class="fas fa-check mr-1"></i>Sent</span>' 
                                    : '<span class="badge badge-warning badge-status"><i class="far fa-clock mr-1"></i>Pending</span>'; ?>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($preview_students)) echo "<tr><td colspan='3' class='text-center p-5 text-muted'>No birthdays detected for today's date.</td></tr>"; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    window.addEventListener('load', () => {
        const loader = document.getElementById('loader');
        const autoForm = document.getElementById('autoProcessForm');

        // Page Loaded: Hide loader
        setTimeout(() => {
            loader.style.opacity = '0';
            setTimeout(() => { 
                loader.style.display = 'none'; 
                
                // If there are pending emails, show loader and submit immediately
                if (autoForm) {
                    setTimeout(() => {
                        loader.style.display = 'flex';
                        loader.style.opacity = '1';
                        autoForm.submit();
                    }, 1000); // Small delay to let user see the count
                }
            }, 500);
        }, 600);
    });
</script>
</body>
</html>
<?php mysqli_close($link); ?>