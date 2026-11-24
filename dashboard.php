<?php
// birthday_system01/dashboard.php

// 1. Initialize Session
session_start();

// 2. Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "config.php";

// ==========================
// SYSTEM HEALTH CHECK (Updated)
// ==========================
$sys_status = 'healthy'; // healthy, warning, critical
$sys_btn_class = 'btn-success'; // Default green
$sys_icon_anim = '';

// Check 1: Database Latency
$start_time = microtime(true);
if (mysqli_ping($link)) {
    $latency = (microtime(true) - $start_time) * 1000; // ms
    if ($latency > 500) {
        $sys_status = 'warning';
        $sys_btn_class = 'btn-warning';
    }
} else {
    $sys_status = 'critical';
    $sys_btn_class = 'btn-danger';
    $sys_icon_anim = 'fa-beat';
}

// (Disk check removed as requested)

// ==========================
// UTILITIES
// ==========================

// Set Timezone
mysqli_query($link, "SET time_zone = '+05:30'");

// Dynamic Cron URL Generation
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$cron_url = $protocol . "://" . $host . $path . "/cron_send_emails.php";

// ==========================
// USER PROFILE & LOGOUT LOGIC
// ==========================

// Fetch User Details & Last Logout
$current_full_name = "";
$current_email = "";
$last_logout_time = null;

$sql_user_details = "SELECT full_name, email, last_logout FROM users WHERE id = ?";
if ($stmt = mysqli_prepare($link, $sql_user_details)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["id"]);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $fetched_name, $fetched_email, $fetched_logout);
    if (mysqli_stmt_fetch($stmt)) {
        $current_full_name = $fetched_name;
        $current_email = $fetched_email;
        $last_logout_time = $fetched_logout; 
        
        $_SESSION["full_name"] = $fetched_name;
        $_SESSION["email"] = $fetched_email;
    }
    mysqli_stmt_close($stmt);
}
$display_name = !empty($current_full_name) ? $current_full_name : $_SESSION["username"];

// Handle Profile Update (PRG Pattern)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $input_full_name = trim($_POST['full_name']);
    $input_email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION["id"];
    
    $error_msg = "";
    $success_msg = "";

    if (empty($current_password)) {
        $error_msg = "Current password is required to save changes.";
    } else {
        $sql = "SELECT password FROM users WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) == 1) {
                mysqli_stmt_bind_result($stmt, $db_password);
                mysqli_stmt_fetch($stmt);
                if ($current_password === $db_password) {
                    $update_password_query = "";
                    if (!empty($new_password)) {
                        if ($new_password !== $confirm_password) {
                            $error_msg = "New password and Confirm password do not match.";
                        } else {
                            $update_password_query = ", password = ?";
                        }
                    }

                    if (empty($error_msg)) {
                        $sql_update = "UPDATE users SET full_name = ?, email = ? " . $update_password_query . " WHERE id = ?";
                        if ($update_stmt = mysqli_prepare($link, $sql_update)) {
                            if (!empty($new_password)) {
                                mysqli_stmt_bind_param($update_stmt, "sssi", $input_full_name, $input_email, $new_password, $user_id);
                            } else {
                                mysqli_stmt_bind_param($update_stmt, "ssi", $input_full_name, $input_email, $user_id);
                            }
                            if (mysqli_stmt_execute($update_stmt)) {
                                $success_msg = "Account details updated successfully.";
                                $_SESSION["full_name"] = $input_full_name;
                                $_SESSION["email"] = $input_email;
                            } else {
                                $error_msg = "Something went wrong. Please try again.";
                            }
                            mysqli_stmt_close($update_stmt);
                        }
                    }
                } else {
                    $error_msg = "Incorrect current password.";
                }
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (!empty($error_msg)) {
        $_SESSION['flash_notification'] = ['type' => 'danger', 'message' => $error_msg];
    } elseif (!empty($success_msg)) {
        $_SESSION['flash_notification'] = ['type' => 'success', 'message' => $success_msg];
    }

    header("location: dashboard.php");
    exit;
}

// Flash Notifications
$flash_notification = null;
if (isset($_SESSION['flash_notification'])) {
    $flash_notification = $_SESSION['flash_notification'];
    unset($_SESSION['flash_notification']);
}

// Auto Download Trigger (Post-Backup)
$auto_download_file = null;
if (isset($_SESSION['auto_download'])) {
    $auto_download_file = $_SESSION['auto_download'];
    unset($_SESSION['auto_download']);
}

// ==========================================
// 3. CORE LOGIC & DATA AGGREGATION
// ==========================================

// Initialize Counters
$todays_birthdays = 0;
$emails_sent_today = 0;
$upcoming_birthdays = 0;
$total_students = 0;
$total_departments = 0;
$pending_submissions = 0;
$batch_data = [];
$dept_initials_map = [];
$master_sections = []; 
$activity_logs = []; 

// Fetch Last Backup Details
$last_backup_info = "Never";
$last_backup_timestamp = null; 
$sql_bkp = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('last_backup_time', 'last_backup_by')";
if ($res_bkp = mysqli_query($link, $sql_bkp)) {
    $bkp_data = [];
    while($row = mysqli_fetch_assoc($res_bkp)) {
        $bkp_data[$row['setting_key']] = $row['setting_value'];
    }
    if (!empty($bkp_data['last_backup_time'])) {
        $last_backup_timestamp = $bkp_data['last_backup_time'];
        $date = date("M d, Y h:i A", strtotime($bkp_data['last_backup_time']));
        $by = $bkp_data['last_backup_by'] ?? "System";
        $last_backup_info = "$date by $by";
    }
}

// Fetch Master Data (Years & Departments & Sections)
$years = [];
$sql_y = "SELECT id, year_val FROM years_master ORDER BY year_val DESC";
if ($res_y = mysqli_query($link, $sql_y)) {
    while ($row = mysqli_fetch_assoc($res_y)) $years[] = $row;
}

$departments = [];
$sql_d = "SELECT id, dept_name, dept_initials FROM departments_master";
if ($res_d = mysqli_query($link, $sql_d)) {
    $total_departments = mysqli_num_rows($res_d);
    while ($row_d = mysqli_fetch_assoc($res_d)) {
        $dept_initials_map[$row_d['dept_name']] = $row_d['dept_initials'];
        $departments[] = $row_d;
    }
}

// Fetch All Sections for JS filtering
$sql_sec = "SELECT section_name, dept_id, year_id FROM sections_master ORDER BY section_name";
if ($res_sec = mysqli_query($link, $sql_sec)) {
    while ($row_s = mysqli_fetch_assoc($res_sec)) {
        $master_sections[] = $row_s;
    }
}

// --- ACTIVITY LOGS LOGIC ---
$compare_time = $last_logout_time ? $last_logout_time : date('Y-m-d H:i:s', strtotime('-24 hours'));
$activity_heading = $last_logout_time ? "Activities since last logout (" . date("M d, H:i", strtotime($last_logout_time)) . ")" : "Activities in last 24 hours";

// 1. Check for New Submissions
$sql_act_sub = "SELECT student_name, department, submission_date FROM student_submissions WHERE submission_date > ?";
if ($stmt_sub = mysqli_prepare($link, $sql_act_sub)) {
    mysqli_stmt_bind_param($stmt_sub, "s", $compare_time);
    mysqli_stmt_execute($stmt_sub);
    $res_sub = mysqli_stmt_get_result($stmt_sub);
    while($row = mysqli_fetch_assoc($res_sub)){
        $activity_logs[] = [
            'type' => 'submission',
            'icon' => 'fas fa-user-plus text-warning',
            'time' => strtotime($row['submission_date']),
            'msg' => "New form submission from <strong>" . htmlspecialchars($row['student_name']) . "</strong> (" . htmlspecialchars($row['department']) . ")",
            'link' => 'verify_submissions.php',
            'link_attr' => ''
        ];
    }
    mysqli_stmt_close($stmt_sub);
}

// 2. Check for New Wishes Created
$sql_act_wish = "SELECT title, created_at FROM wishes WHERE created_at > ?";
if ($stmt_wish = mysqli_prepare($link, $sql_act_wish)) {
    mysqli_stmt_bind_param($stmt_wish, "s", $compare_time);
    mysqli_stmt_execute($stmt_wish);
    $res_wish = mysqli_stmt_get_result($stmt_wish);
    while($row = mysqli_fetch_assoc($res_wish)){
        $activity_logs[] = [
            'type' => 'wish',
            'icon' => 'fas fa-birthday-cake text-danger',
            'time' => strtotime($row['created_at']),
            'msg' => "New birthday wish template <strong>" . htmlspecialchars($row['title']) . "</strong> was created.",
            'link' => 'wishes.php',
            'link_attr' => ''
        ];
    }
    mysqli_stmt_close($stmt_wish);
}

// 3. Check for Backups Taken
if ($last_backup_timestamp && strtotime($last_backup_timestamp) > strtotime($compare_time)) {
    $activity_logs[] = [
        'type' => 'backup',
        'icon' => 'fas fa-save text-info',
        'time' => strtotime($last_backup_timestamp),
        'msg' => "System backup performed by <strong>" . ($bkp_data['last_backup_by'] ?? 'System') . "</strong>.",
        'link' => '#',
        'link_attr' => 'data-toggle="modal" data-target="#backupModal"'
    ];
}

// 4. LOOP THROUGH EVERY YEAR TABLE
foreach ($years as $yr_row) {
    $yr = $yr_row['year_val'];
    $stu_table = (function_exists('get_student_table')) ? get_student_table($yr) : "students_" . preg_replace('/\D/', '', $yr);
    $log_table = (function_exists('get_log_table')) ? get_log_table($yr) : "email_logs_" . preg_replace('/\D/', '', $yr);

    $current_batch_count = 0;
    $batch_breakdown = [];

    // Verify student table exists
    $check = mysqli_query($link, "SHOW TABLES LIKE '$stu_table'");
    if (mysqli_num_rows($check) > 0) {
        
        // 1. Active Students & Batch Count
        $q1 = "SELECT COUNT(*) as c FROM `$stu_table` WHERE is_deleted = 0";
        if ($r1 = mysqli_query($link, $q1)) {
            $count = mysqli_fetch_assoc($r1)['c'];
            $total_students += $count;
            $current_batch_count = $count;
        }

        // 2. Birthdays Today
        $q2 = "SELECT COUNT(*) as c FROM `$stu_table` 
               WHERE MONTH(date_of_birth) = MONTH(CURDATE()) 
               AND DAY(date_of_birth) = DAY(CURDATE()) 
               AND is_deleted = 0";
        if ($r2 = mysqli_query($link, $q2)) {
            $todays_birthdays += mysqli_fetch_assoc($r2)['c'];
        }

        // 3. Upcoming (Next 15 Days)
        $q3 = "SELECT COUNT(*) as c FROM `$stu_table` 
               WHERE is_deleted = 0 AND (
                   DATE_FORMAT(date_of_birth, '%m-%d') BETWEEN DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '%m-%d') 
                   AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 15 DAY), '%m-%d')
               )";
        if ($r3 = mysqli_query($link, $q3)) {
            $upcoming_birthdays += mysqli_fetch_assoc($r3)['c'];
        }

        // 4. Batch Breakdown
        $q_breakdown = "SELECT department, section, COUNT(*) as c FROM `$stu_table` WHERE is_deleted = 0 GROUP BY department, section ORDER BY department ASC, section ASC";
        if ($r_bd = mysqli_query($link, $q_breakdown)) {
            while ($row_bd = mysqli_fetch_assoc($r_bd)) {
                $batch_breakdown[$row_bd['department']][$row_bd['section']] = $row_bd['c'];
            }
        }
    }

    $batch_data[] = [
        'year' => (strlen($yr) == 2 ? '20'.$yr : $yr),
        'count' => $current_batch_count,
        'breakdown' => $batch_breakdown
    ];

    // Verify log table exists
    $check_log = mysqli_query($link, "SHOW TABLES LIKE '$log_table'");
    if (mysqli_num_rows($check_log) > 0) {
        // Stats: Emails sent today
        $q4 = "SELECT COUNT(*) as c FROM `$log_table` WHERE status = 'sent' AND DATE(sent_at) = CURDATE()";
        if ($r4 = mysqli_query($link, $q4)) {
            $emails_sent_today += mysqli_fetch_assoc($r4)['c'];
        }

        // Activity Log: Emails sent after logout
        $q_act_email = "SELECT COUNT(*) as c, MAX(sent_at) as last_sent FROM `$log_table` WHERE sent_at > '$compare_time'";
        if ($r_act_email = mysqli_query($link, $q_act_email)) {
            $email_row = mysqli_fetch_assoc($r_act_email);
            if ($email_row['c'] > 0) {
                $activity_logs[] = [
                    'type' => 'email',
                    'icon' => 'fas fa-paper-plane text-success',
                    'time' => strtotime($email_row['last_sent']),
                    'msg' => "<strong>" . $email_row['c'] . "</strong> Automated Birthday Emails sent to Batch 20" . htmlspecialchars($yr),
                    'link' => '#automation-section',
                    'link_attr' => ''
                ];
            }
        }
    }
}

// Sort Activity Logs
usort($activity_logs, function($a, $b) {
    return $b['time'] - $a['time'];
});

// Pending Verifications
$check_sub = mysqli_query($link, "SHOW TABLES LIKE 'student_submissions'");
if (mysqli_num_rows($check_sub) > 0) {
    $q_sub = "SELECT COUNT(*) as c FROM student_submissions";
    if ($r_sub = mysqli_query($link, $q_sub)) {
        $pending_submissions = mysqli_fetch_assoc($r_sub)['c'];
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard | Birthday System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="BWU_logo.png" type="image/png">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
/* =====================
   GLOBAL & LOADER
   ===================== */
body {
    background: url('university.jpg') no-repeat center center fixed;
    background-size: cover;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
#loader {
    position: fixed; inset: 0; background: #ffffff;
    display: flex; align-items: center; justify-content: center;
    z-index: 9999; transition: opacity 0.4s ease;
}
.spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }
.spinner { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 8px solid #e5e7eb; border-top: 8px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite; z-index: 2; }
.loader-logo { width: 45px; height: auto; z-index: 1; }
@keyframes spin { 100% { transform: rotate(360deg); } }

/* =====================
   CONTAINER
   ===================== */
.wrapper {
    margin: 60px auto; padding: 45px 40px; max-width: 1200px;
    background: rgba(255, 255, 255, 0.01); backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px); border-radius: 20px;
    box-shadow: 0 20px 45px rgba(0,0,0,0.35); border: 1px solid rgba(255, 255, 255, 0.5);
    position: relative; /* Added for absolute positioning of System Status */
}
@media (max-width: 768px) {
    .wrapper { margin: 20px; padding: 30px 20px; }
    .page-header h1 { font-size: 1.8rem; }
}

/* =====================
   STATS & CARDS
   ===================== */
.page-header { text-align: center; margin-bottom: 50px; }
.page-header img { max-width: 120px; margin-bottom: 20px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1)); }
.page-header h1 { font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #1f2937; }

.dynamic-name {
    background: url('university.jpg') no-repeat center center fixed;
    background-size: cover;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    filter: invert(1) grayscale(1) contrast(9);
    display: inline-block;
    font-weight: 900;
}

.header-stats { display: flex; justify-content: center; gap: 25px; margin-top: 40px; flex-wrap: wrap; }
.stat-item { background: rgba(255, 255, 255, 0.9); padding: 25px 20px; border-radius: 20px; text-align: center; border: 1px solid #fff; box-shadow: 0 10px 25px rgba(0,0,0,0.08); flex: 1 1 180px; max-width: 220px; transition: all 0.3s; cursor: default; }
.stat-item:hover { transform: translateY(-10px) scale(1.02); background: #fff; }
.stat-icon { font-size: 2rem; margin-bottom: 15px; opacity: 0.8; }
.stat-number { font-size: 2.5rem; font-weight: 800; line-height: 1; margin-bottom: 8px; color: #333; }
.stat-label { font-size: 0.85rem; text-transform: uppercase; color: #6c757d; font-weight: 700; }

.stat-today { border-bottom: 5px solid #28a745; } .stat-today .stat-number, .stat-today .stat-icon { color: #28a745; }
.stat-emails { border-bottom: 5px solid #17a2b8; } .stat-emails .stat-number, .stat-emails .stat-icon { color: #17a2b8; }
.stat-upcoming { border-bottom: 5px solid #dc3545; } .stat-upcoming .stat-number, .stat-upcoming .stat-icon { color: #dc3545; }
.stat-active { border-bottom: 5px solid #007bff; } .stat-active .stat-number, .stat-active .stat-icon { color: #007bff; }
.stat-dept { border-bottom: 5px solid #8e24aa; } .stat-dept .stat-number, .stat-dept .stat-icon { color: #8e24aa; }

.card { border: none; border-radius: 18px; transition: all 0.35s ease; box-shadow: 0 12px 30px rgba(0,0,0,0.1); height: 100%; background: #fff; }
.card:hover { transform: translateY(-10px); box-shadow: 0 18px 40px rgba(0,0,0,0.2); }
.card-body { padding: 45px 25px; text-align: center; position: relative; overflow: hidden; }
.icon-container { font-size: 55px; margin-bottom: 22px; }

/* BATCH CARDS STYLE */
.card-batch {
    background: linear-gradient(135deg, #ffffff, #f3f4f6);
    border-left: 5px solid #6610f2;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-height: 220px;
}
.card-batch .batch-year { font-size: 1.4rem; font-weight: 800; color: #333; }
.card-batch .batch-count { font-size: 0.9rem; color: #6610f2; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.card-batch:hover { background: #fff; transform: translateY(-5px); box-shadow: 0 10px 20px rgba(102, 16, 242, 0.15); }

/* BATCH HOVER DETAILS */
.batch-details-overlay {
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(255, 255, 255, 0.98);
    z-index: 10;
    display: flex;
    flex-direction: column;
    padding: 15px;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease;
    overflow-y: auto;
    text-align: left;
}
.card-batch:hover .batch-details-overlay {
    opacity: 1;
    visibility: visible;
}
.batch-details-overlay::-webkit-scrollbar { width: 6px; }
.batch-details-overlay::-webkit-scrollbar-track { background: #f1f1f1; }
.batch-details-overlay::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
.batch-details-overlay::-webkit-scrollbar-thumb:hover { background: #6610f2; }

.dept-breakdown-item { margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
.dept-breakdown-item h6 { font-size: 0.85rem; font-weight: 700; color: #333; margin-bottom: 4px; }
.dept-breakdown-item ul { list-style: none; padding-left: 0; margin-bottom: 0; display: flex; flex-wrap: wrap; gap: 8px; }
.dept-breakdown-item li { font-size: 0.75rem; background: #eef2ff; color: #6610f2; padding: 2px 8px; border-radius: 4px; font-weight: 600; }
.btn-load-more { font-size: 0.75rem; width: 100%; padding: 5px; margin-top: 5px; border: 1px dashed #6610f2; color: #6610f2; background: none; border-radius: 4px; cursor: pointer; transition: 0.2s; }
.btn-load-more:hover { background: #6610f2; color: white; }

/* ACTIVITY LOG STYLE */
.activity-list { list-style: none; padding: 0; margin: 0; text-align: left; }
.activity-item { border-bottom: 1px solid #eee; transition: background 0.2s; }
.activity-item:last-child { border-bottom: none; }
.activity-item:hover { background: #f8f9fa; }
.activity-link { display: flex; align-items: flex-start; width: 100%; padding: 12px 15px; text-decoration: none; color: inherit; }
.activity-link:hover { text-decoration: none; color: inherit; }
.activity-icon { width: 40px; min-width: 40px; text-align: center; font-size: 1.2rem; margin-right: 15px; padding-top: 2px; }
.activity-content { flex: 1; }
.activity-msg { font-size: 0.95rem; color: #333; margin-bottom: 2px; }
.activity-time { font-size: 0.8rem; color: #888; }
.empty-log { color: #6c757d; font-style: italic; text-align: center; padding: 20px; }

/* MAIN CARDS STYLE */
.card-student { background: linear-gradient(135deg, #f8fbff, #eef4ff); border-top: 6px solid #2563eb; } .card-student .icon-container { color: #2563eb; }
.card-wishes { background: linear-gradient(135deg, #fff5f8, #ffe4ec); border-top: 6px solid #e83e8c; } .card-wishes .icon-container { color: #e83e8c; }
.card-dept { background: linear-gradient(135deg, #f3e5f5, #e1bee7); border-top: 6px solid #8e24aa; } .card-dept .icon-container { color: #8e24aa; }
.card-verify { background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-top: 6px solid #f57c00; } .card-verify .icon-container { color: #f57c00; }
.card-automation { background: linear-gradient(135deg, #e0f2f1, #b2dfdb); border-top: 6px solid #009688; } .card-automation .icon-container { color: #009688; }
.card-backup { background: linear-gradient(135deg, #e3f2fd, #bbdefb); border-top: 6px solid #1e88e5; } .card-backup .icon-container { color: #1e88e5; }
.card-activity { background: linear-gradient(135deg, #ffffff, #fdfdfd); border-top: 6px solid #343a40; }

.btn-custom { border-radius: 50px; padding: 12px 30px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; transition: all 0.3s; }
.btn-custom:hover { transform: scale(1.05); }
.btn-student { background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none; color: #fff; }
.btn-wishes { background: linear-gradient(135deg, #e83e8c, #d63384); border: none; color: #fff; }
.btn-dept { background: linear-gradient(135deg, #8e24aa, #7b1fa2); border: none; color: #fff; }
.btn-verify { background: linear-gradient(135deg, #f57c00, #ef6c00); border: none; color: #fff; }
.btn-automation { background: linear-gradient(135deg, #009688, #00796b); border: none; color: #fff; }
.btn-backup { background: linear-gradient(135deg, #1e88e5, #1565c0); border: none; color: #fff; }
.btn-logout { background: #ef4444; color: #fff; border: none; }
.btn-settings { background: #6c757d; color: #fff; border: none; }
.btn-settings:hover { background: #5a6268; color: #fff; }
.btn-admin-manage { background: #343a40; color: #fff; border: none; }
.btn-admin-manage:hover { background: #23272b; color: #fff; transform: scale(1.05); }

/* =====================
   MODALS
   ===================== */
.modal-header-settings {
    background: linear-gradient(135deg, #6c757d, #495057);
    color: white; border-radius: 0.3rem 0.3rem 0 0;
}
.input-icon-wrap { position: relative; }
.input-icon-wrap i.field-icon {
    position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6c757d;
}
.input-icon-wrap input { padding-left: 45px; height: 48px; border-radius: 12px; }

.password-section {
    background: #f8f9fa; padding: 20px; border-radius: 12px; border: 1px solid #e9ecef; margin-top: 20px;
}
.password-toggle {
    position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #6c757d;
}
.strength-meter-container { height: 4px; background: #e9ecef; margin-top: 8px; border-radius: 2px; display: none; }
.strength-meter-bar { height: 100%; width: 0%; transition: all 0.3s; }

/* Admin Modal Specifics */
.admin-list-item { border-left: 4px solid #007bff; transition: 0.2s; }
.admin-list-item:hover { background-color: #f8f9fa; }
.step-wizard { display: flex; justify-content: space-between; margin-bottom: 20px; position: relative; }
.step-wizard::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 2px; background: #ddd; z-index: 1; transform: translateY(-50%); }
.step-item { position: relative; z-index: 2; background: #fff; border: 2px solid #ddd; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #999; }
.step-item.active { border-color: #007bff; background: #007bff; color: white; }
.step-item.completed { border-color: #28a745; background: #28a745; color: white; }

/* =====================
   TOAST NOTIFICATION
   ===================== */
#toast-notification {
    visibility: hidden;
    min-width: 250px;
    background-color: #333;
    color: #fff;
    text-align: center;
    border-radius: 50px;
    padding: 16px;
    position: fixed;
    z-index: 10000;
    left: 50%;
    bottom: 30px;
    transform: translateX(-50%);
    font-size: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    opacity: 0;
    transition: opacity 0.4s ease-in-out, bottom 0.4s ease-in-out;
}
#toast-notification.show {
    visibility: visible;
    opacity: 1;
    bottom: 50px;
}
#toast-notification i { margin-right: 8px; color: #00e676; }

/* New System Status Button Specifics */
.btn-system-status {
    position: absolute;
    top: 25px;
    right: 30px;
    z-index: 100;
    font-size: 0.85rem;
    padding: 10px 20px;
    border-radius: 50px;
    color: #fff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    transition: all 0.3s;
    font-weight: 700;
    letter-spacing: 0.5px;
    border: none;
}
.btn-system-status:hover {
    color: #fff;
    text-decoration: none;
    transform: scale(1.05);
    box-shadow: 0 6px 15px rgba(0,0,0,0.3);
}
.btn-success { background: linear-gradient(45deg, #28a745, #20c997); }
.btn-warning { background: linear-gradient(45deg, #ffc107, #fd7e14); color: #333 !important; }
.btn-danger { background: linear-gradient(45deg, #dc3545, #c82333); animation: pulse-red 2s infinite; }

@keyframes pulse-red {
    0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
    100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
}

@media (max-width: 576px) {
    .btn-system-status { top: 15px; right: 15px; padding: 6px 12px; font-size: 0.75rem; }
}
</style>
</head>
<body>

<div id="loader">
    <div class="spinner-container"><div class="spinner"></div><img src="BWU_logo.png" class="loader-logo"></div>
</div>

<div id="toast-notification">
    <i class="fas fa-check-circle"></i> <span id="toast-msg">Operation successful</span>
</div>

<div class="container">
    <div class="wrapper">
        
        <a href="system_status.php" class="btn-system-status <?php echo $sys_btn_class; ?>">
            <i class="fas fa-server mr-2 <?php echo $sys_icon_anim; ?>"></i>System Status
        </a>

        <?php if ($flash_notification): ?>
            <div class="alert alert-<?php echo $flash_notification['type']; ?> alert-dismissible fade show" role="alert">
                <i class="fas <?php echo ($flash_notification['type'] == 'success') ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo $flash_notification['message']; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <img src="BWU_logo.png" alt="University Logo" class="img-fluid">
            <h1>Welcome, <span class="dynamic-name"><?php echo htmlspecialchars($display_name); ?></span></h1>
            <p style="color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.6);">What would you like to manage today?</p>
            
            <div class="header-stats">
                <div class="stat-item stat-today">
                    <div class="stat-icon"><i class="fas fa-birthday-cake"></i></div>
                    <div class="stat-number"><?php echo $todays_birthdays; ?></div>
                    <div class="stat-label">Birthdays Today</div>
                </div>
                <div class="stat-item stat-emails">
                    <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
                    <div class="stat-number"><?php echo $emails_sent_today; ?></div>
                    <div class="stat-label">Emails Sent</div>
                </div>
                <div class="stat-item stat-upcoming">
                    <div class="stat-icon"><i class="far fa-calendar-alt"></i></div>
                    <div class="stat-number"><?php echo $upcoming_birthdays; ?></div>
                    <div class="stat-label">Upcoming (15 Days)</div>
                </div>
                <div class="stat-item stat-active">
                    <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-number"><?php echo number_format($total_students); ?></div>
                    <div class="stat-label">Active Students</div>
                </div>
                <div class="stat-item stat-dept">
                    <div class="stat-icon"><i class="fas fa-building"></i></div>
                    <div class="stat-number"><?php echo $total_departments; ?></div>
                    <div class="stat-label">Departments</div>
                </div>
            </div>
        </div>
        
        <div class="mb-4">
             <div class="row">
                 <div class="col-12">
                     <div class="card card-activity shadow-sm">
                         <div class="card-body text-left p-0">
                             <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 text-dark"><i class="fas fa-history mr-2"></i><?php echo $activity_heading; ?></h5>
                                <small class="text-muted"><i class="far fa-clock"></i> Auto-updates on login</small>
                             </div>
                             <ul class="activity-list" style="max-height: 220px; overflow-y: auto;">
                                <?php if (empty($activity_logs)): ?>
                                    <li class="empty-log">No new activities recorded since your last logout.</li>
                                <?php else: ?>
                                    <?php foreach ($activity_logs as $log): ?>
                                    <li class="activity-item">
                                        <a href="<?php echo $log['link']; ?>" <?php echo $log['link_attr']; ?> class="activity-link">
                                            <div class="activity-icon">
                                                <i class="<?php echo $log['icon']; ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-msg"><?php echo $log['msg']; ?></div>
                                                <div class="activity-time"><?php echo date("M d, Y h:i A", $log['time']); ?></div>
                                            </div>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                             </ul>
                         </div>
                     </div>
                 </div>
             </div>
        </div>

        <?php if (!empty($batch_data)): ?>
        <div class="mb-5">
            <h4 class="text-dark bg-warning d-inline-block px-3 py-1 rounded shadow-sm mb-3 font-weight-bold"><i class="fas fa-layer-group mr-2"></i>Active Batches</h4>
            <div class="row">
                <?php foreach($batch_data as $index => $batch): ?>
                <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 mb-3">
                    <div class="card card-batch h-100">
                        <div class="card-body d-flex flex-column justify-content-center align-items-center p-3">
                            <div class="batch-year mb-1"><?php echo htmlspecialchars($batch['year']); ?></div>
                            <div class="batch-count">
                                <i class="fas fa-users mr-1"></i> <?php echo $batch['count']; ?> Students
                            </div>
                        </div>
                        
                        <div class="batch-details-overlay" id="overlay-<?php echo $index; ?>">
                            <div class="mb-2 text-center text-primary font-weight-bold small text-uppercase border-bottom pb-1">Batch Breakdown</div>
                            <?php 
                                $dept_counter = 0;
                                if (!empty($batch['breakdown'])):
                                    foreach($batch['breakdown'] as $dept_name => $sections): 
                                        $dept_counter++;
                                        $hidden_class = ($dept_counter > 3) ? 'd-none hidden-dept' : '';
                                        $display_dept_name = isset($dept_initials_map[$dept_name]) ? $dept_initials_map[$dept_name] : $dept_name;
                            ?>
                                <div class="dept-breakdown-item <?php echo $hidden_class; ?>">
                                    <h6><?php echo htmlspecialchars($display_dept_name); ?></h6>
                                    <ul>
                                        <?php foreach($sections as $sec_name => $count): ?>
                                            <li><?php echo $sec_name . ": " . $count; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php 
                                    endforeach;
                                    if ($dept_counter > 3):
                            ?>
                                <button type="button" class="btn-load-more" onclick="toggleDetails(this)">Load More</button>
                            <?php 
                                    endif;
                                else: 
                            ?>
                                <div class="text-muted small text-center mt-3">No active students found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <h4 class="text-dark bg-warning d-inline-block px-3 py-1 rounded shadow-sm mb-3 font-weight-bold"><i class="fas fa-th-large mr-2"></i>Main Menu</h4>
        <div class="row justify-content-center">
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card card-student">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-user-graduate"></i></div>
                        <h4>Student Management</h4>
                        <p class="text-muted">Manage student profiles and academic details.</p>
                        <a href="students.php" class="btn btn-custom btn-student btn-block mt-3"><i class="fas fa-users-cog"></i> Manage Students</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card card-wishes">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-birthday-cake"></i></div>
                        <h4>Wish Library</h4>
                        <p class="text-muted">Create and view birthday wishes with photos.</p>
                        <a href="wishes.php" class="btn btn-custom btn-wishes btn-block mt-3"><i class="fas fa-heart"></i> Go to Wishes</a>
                    </div>
                </div>
            </div>
            
             <div class="col-lg-4 col-md-6 mb-4">
                <div class="card card-verify">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-clipboard-check"></i></div>
                        <h4>Verifications</h4>
                        <p class="text-muted">Review and approve student form submissions.</p>
                        <?php if($pending_submissions > 0): ?>
                            <span class="badge badge-danger mb-3 p-2"><?php echo $pending_submissions; ?> Pending Actions</span>
                        <?php else: ?>
                            <span class="badge badge-success mb-3 p-2">All Caught Up!</span>
                        <?php endif; ?>
                        <a href="verify_submissions.php" class="btn btn-custom btn-verify btn-block mt-2"><i class="fas fa-check-double"></i> Review Now</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card card-dept">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-building"></i></div>
                        <h4>Departments</h4>
                        <p class="text-muted">View active departments and manage structure.</p>
                        <a href="departments.php" class="btn btn-custom btn-dept btn-block mt-3"><i class="fas fa-university"></i> Manage Depts</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4" id="automation-section">
                <div class="card card-automation">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-robot"></i></div>
                        <h4>Automation</h4>
                        <p class="text-muted">Manage email scheduling & background jobs.</p>
                        <div class="d-flex justify-content-center flex-wrap mt-3">
                            <button onclick="copyCronUrl()" class="btn btn-custom btn-info mr-2 mb-2"><i class="fas fa-copy"></i> Copy URL</button>
                            <a href="<?php echo htmlspecialchars($cron_url); ?>" target="_blank" class="btn btn-custom btn-automation mb-2"><i class="fas fa-external-link-alt"></i> Open Panel</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card card-verify" style="border-top: 6px solid #6c757d; background: linear-gradient(135deg, #f8f9fa, #e9ecef);">
                    <div class="card-body">
                        <div class="icon-container" style="color: #6c757d;"><i class="fas fa-sliders-h"></i></div>
                        <h4>Form Access</h4>
                        <p class="text-muted">Control registration access for Depts, Years, or Sections.</p>
                        <a href="form_controls.php" class="btn btn-custom btn-secondary btn-block mt-3"><i class="fas fa-lock-open"></i> Manage Access</a>
                    </div>
                </div>
            </div>

            <div class="col-lg-12 col-md-12 mb-4">
                <div class="card card-backup">
                    <div class="card-body">
                        <div class="icon-container"><i class="fas fa-database"></i></div>
                        <h4>System Backup</h4>
                        <p class="text-muted">Create backups of database and uploaded files.</p>
                        <div class="small text-secondary mb-2">Last Backup: <?php echo $last_backup_info; ?></div>
                        <button type="button" class="btn btn-custom btn-backup mt-2 px-5" data-toggle="modal" data-target="#backupModal">
                            <i class="fas fa-cloud-download-alt"></i> Create Backup
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <div class="text-center mt-5">
            <button type="button" class="btn btn-custom btn-settings mr-2" data-toggle="modal" data-target="#accountModal">
                <i class="fas fa-user-cog"></i> Account Settings
            </button>
            <button type="button" class="btn btn-custom btn-admin-manage mr-2" onclick="openAdminModal()">
                <i class="fas fa-user-shield"></i> Add/Manage Admins
            </button>
            <button type="button" class="btn btn-custom btn-logout" data-toggle="modal" data-target="#logoutModal">
                <i class="fas fa-sign-out-alt"></i> Sign Out
            </button>
        </div>

    </div>
</div>

<div class="modal fade" id="backupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0" style="border-radius: 15px;">
            <div class="modal-header bg-primary text-white p-4" style="border-radius: 15px 15px 0 0;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-cloud-download-alt mr-2"></i>Create System Backup</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form action="backup_action.php" method="post" onsubmit="showLoader();">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="create_backup">
                    
                    <h6 class="text-primary border-bottom pb-2 mb-3">1. Select Backup Scope</h6>
                    <div class="form-group">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scope_all" name="backup_scope" value="all" class="custom-control-input" checked onchange="toggleBackupOptions()">
                            <label class="custom-control-label font-weight-bold" for="scope_all">All System Data & Files (Recommended)</label>
                            <small class="form-text text-muted ml-4">Full database dump + All uploaded images. (Always ZIP format)</small>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scope_students" name="backup_scope" value="students" class="custom-control-input" onchange="toggleBackupOptions()">
                            <label class="custom-control-label" for="scope_students">Students Data Only</label>
                        </div>
                        
                        <div id="student_filters" class="ml-4 p-3 bg-light rounded border mb-2" style="display:none;">
                            <small class="text-uppercase font-weight-bold text-secondary mb-2 d-block">Filter Specific Batch</small>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <select class="form-control form-control-sm" name="filter_year" id="filter_year" onchange="updateSectionDropdown(); fetchStudentCount();">
                                        <option value="all">All Years</option>
                                        <?php foreach($years as $y): ?>
                                            <option value="<?php echo $y['year_val']; ?>">20<?php echo $y['year_val']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-2">
                                    <select class="form-control form-control-sm" name="filter_dept" id="filter_dept" onchange="updateSectionDropdown(); fetchStudentCount();">
                                        <option value="all">All Departments</option>
                                        <?php foreach($departments as $dp): ?>
                                            <option value="<?php echo $dp['id']; ?>"><?php echo htmlspecialchars($dp['dept_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <select class="form-control form-control-sm" name="filter_section" id="filter_section" onchange="fetchStudentCount();">
                                        <option value="all">All Sections</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-center justify-content-end">
                                    <span class="badge badge-info p-2" id="student_count_badge"><i class="fas fa-users mr-1"></i> Count: ...</span>
                                </div>
                            </div>
                        </div>

                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scope_wishes" name="backup_scope" value="wishes" class="custom-control-input" onchange="toggleBackupOptions()">
                            <label class="custom-control-label" for="scope_wishes">Wishes Data Only</label>
                        </div>
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="scope_admins" name="backup_scope" value="admins" class="custom-control-input" onchange="toggleBackupOptions()">
                            <label class="custom-control-label" for="scope_admins">Administrators Data (Full Details)</label>
                        </div>
                    </div>

                    <div id="format_section">
                        <h6 class="text-primary border-bottom pb-2 mb-3 mt-4">2. Select Format</h6>
                        <div class="form-group">
                             <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="type_sql" name="backup_type" value="sql" class="custom-control-input" checked>
                                <label class="custom-control-label" for="type_sql">SQL Dump (.sql)</label>
                            </div>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="type_csv" name="backup_type" value="csv" class="custom-control-input">
                                <label class="custom-control-label" for="type_csv">CSV Spreadsheet (.csv)</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mt-3" id="files_option_container" style="display:none;">
                         <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="include_files" name="include_files">
                            <label class="custom-control-label" for="include_files">Include Uploaded Files/Images (ZIP)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3" style="border-radius: 0 0 15px 15px;">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm"><i class="fas fa-download mr-1"></i> Start Backup</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="logoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt mr-2"></i>Confirm Sign Out</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-question-circle fa-4x text-danger mb-3" style="opacity: 0.5;"></i>
                <h5 class="text-dark">Are you sure you want to leave?</h5>
            </div>
            <div class="modal-footer bg-light justify-content-center">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-danger rounded-pill px-4">Yes, Sign Out</a>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0" style="border-radius: 15px;">
            <div class="modal-header modal-header-settings p-4">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-user-cog mr-2"></i>Account Settings</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-secondary">Full Name</label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-user field-icon"></i>
                                <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($current_full_name); ?>" placeholder="Your Full Name">
                            </div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-secondary">Email Address</label>
                            <div class="input-icon-wrap">
                                <i class="fas fa-envelope field-icon"></i>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($current_email); ?>" placeholder="name@example.com">
                            </div>
                        </div>
                    </div>

                    <div class="password-section">
                        <h6 class="text-info mb-3"><i class="fas fa-lock mr-2"></i>Security (Optional)</h6>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="small text-muted">New Password</label>
                                <div class="input-icon-wrap">
                                    <input type="password" name="new_password" id="new_password" class="form-control" placeholder="New Password" onkeyup="checkPasswordStrength(); checkMatch();">
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword('new_password', this)"></i>
                                </div>
                                <div class="strength-meter-container" id="strength-container"><div class="strength-meter-bar" id="strength-bar"></div></div>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="small text-muted">Confirm New Password</label>
                                <div class="input-icon-wrap">
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm Password" onkeyup="checkMatch()">
                                    <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password', this)"></i>
                                </div>
                                <small id="match-message" class="font-weight-bold"></small>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-danger"><i class="fas fa-key mr-1"></i> Current Password (Required)</label>
                        <div class="input-icon-wrap">
                            <input type="password" name="current_password" id="current_password" class="form-control border-danger" placeholder="Enter your current password" required>
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('current_password', this)"></i>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3" style="border-radius: 0 0 15px 15px;">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary rounded-pill px-4 shadow-sm" id="submitBtn">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="manageAdminsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content shadow-lg border-0" style="border-radius: 15px;">
            <div class="modal-header bg-dark text-white p-4">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-users-cog mr-2"></i>Manage Administrators</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <ul class="nav nav-pills mb-4 justify-content-center" id="adminTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active rounded-pill px-4" id="list-tab" data-toggle="pill" href="#list" role="tab" onclick="fetchAdmins()">Admin List</a>
                    </li>
                    <li class="nav-item ml-2">
                        <a class="nav-link rounded-pill px-4" id="add-tab" data-toggle="pill" href="#add" role="tab" onclick="resetAddForm()">Add New Admin</a>
                    </li>
                </ul>

                <div class="tab-content" id="adminTabContent">
                    <div class="tab-pane fade show active" id="list" role="tabpanel">
                        <div class="table-responsive bg-white rounded shadow-sm p-3">
                            <table class="table table-hover mb-0" id="adminsTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Designation</th>
                                        <th>Username</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="add" role="tabpanel">
                        <div class="row justify-content-center">
                            <div class="col-md-10">
                                <div class="step-wizard px-5 mx-5">
                                    <div class="step-item active" id="step1-indicator">1</div>
                                    <div class="step-item" id="step2-indicator">2</div>
                                    <div class="step-item" id="step3-indicator">3</div>
                                </div>
                                <div class="text-center mb-4 small text-muted">Details &rarr; Verification &rarr; Completion</div>

                                <div id="add-step-1">
                                    <div class="card shadow-sm border-0">
                                        <div class="card-body">
                                            <h6 class="text-primary border-bottom pb-2 mb-3">New Administrator Details</h6>
                                            <div class="row">
                                                <div class="col-md-6 form-group">
                                                    <label>Full Name</label>
                                                    <input type="text" class="form-control" id="new_admin_name">
                                                </div>
                                                <div class="col-md-6 form-group">
                                                    <label>Designation</label>
                                                    <input type="text" class="form-control" id="new_admin_designation" placeholder="e.g. IT Head">
                                                </div>
                                                <div class="col-md-6 form-group">
                                                    <label>Email (Credentials will be sent here)</label>
                                                    <input type="email" class="form-control" id="new_admin_email">
                                                </div>
                                                <div class="col-md-6 form-group">
                                                    <label>Username</label>
                                                    <input type="text" class="form-control" id="new_admin_username">
                                                </div>
                                                <div class="col-md-12 form-group">
                                                    <label>Temporary Password</label>
                                                    <div class="input-group">
                                                        <input type="text" class="form-control" id="new_admin_password">
                                                        <div class="input-group-append">
                                                            <button class="btn btn-outline-secondary" type="button" onclick="generatePass()">Generate</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <button class="btn btn-primary float-right rounded-pill px-4" onclick="goToVerification()">Next: Verify Identity</button>
                                        </div>
                                    </div>
                                </div>

                                <div id="add-step-2" style="display:none;">
                                    <div class="card shadow-sm border-0">
                                        <div class="card-body text-center p-5">
                                            <h5 class="mb-4">Security Verification</h5>
                                            <p class="text-muted">To add a new administrator, verify your identity.</p>
                                            
                                            <div id="verify-pass-section">
                                                <div class="form-group mx-auto" style="max-width: 300px;">
                                                    <input type="password" class="form-control text-center" id="sudo_password" placeholder="Your Password">
                                                </div>
                                                <button class="btn btn-warning rounded-pill px-4" onclick="verifySudoPassword()">Verify Password</button>
                                            </div>

                                            <div id="verify-otp-section" style="display:none;" class="mt-4">
                                                <div class="alert alert-info py-2">OTP sent to your email.</div>
                                                <div class="form-group mx-auto" style="max-width: 200px;">
                                                    <input type="text" class="form-control text-center text-primary font-weight-bold" id="sudo_otp" placeholder="Enter OTP" maxlength="6" style="letter-spacing: 5px; font-size: 1.2rem;">
                                                </div>
                                                <button class="btn btn-success rounded-pill px-4 shadow" onclick="finalizeAddAdmin()">Confirm & Create</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button class="btn btn-link text-muted mt-2" onclick="resetAddForm()">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editAdminModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title">Edit Administrator</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_admin_id">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" class="form-control" id="edit_admin_name">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" id="edit_admin_email">
                </div>
                <div class="form-group">
                    <label>Designation</label>
                    <input type="text" class="form-control" id="edit_admin_designation">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control" id="edit_admin_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>New Password (Optional)</label>
                    <input type="password" class="form-control" id="edit_admin_password" placeholder="Leave blank to keep current">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateAdmin()">Save Updates</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    // --- GLOBAL LOADER ---
    window.addEventListener('load', () => {
        hideLoader();
        // Check for Auto Download from Session
        <?php if ($auto_download_file): ?>
            setTimeout(function() {
                window.location.href = 'backup_action.php?download=<?php echo $auto_download_file; ?>';
            }, 1000);
        <?php endif; ?>
    });
    function showLoader() { document.getElementById('loader').style.display = 'flex'; document.getElementById('loader').style.opacity = '1'; }
    function hideLoader() { document.getElementById('loader').style.opacity = '0'; setTimeout(() => { document.getElementById('loader').style.display = 'none'; }, 400); }

    function copyCronUrl() {
        const url = "<?php echo $cron_url; ?>";
        navigator.clipboard.writeText(url).then(() => { showToast('Cron URL Copied!'); }).catch(err => { prompt("Copy manually:", url); });
    }

    function showToast(msg) {
        var x = document.getElementById("toast-notification");
        if(msg) document.getElementById('toast-msg').innerText = msg;
        x.className = "show";
        setTimeout(function(){ x.className = x.className.replace("show", ""); }, 3000);
    }
    
    // Master Data for Sections
    const sectionsMaster = <?php echo json_encode($master_sections); ?>;
    const yearsMaster = <?php echo json_encode($years); ?>; // [{id, year_val}]

    // Toggle Backup Options based on Scope
    function toggleBackupOptions() {
        const scope = document.querySelector('input[name="backup_scope"]:checked').value;
        const formatSection = document.getElementById('format_section');
        const filesContainer = document.getElementById('files_option_container');
        const studentFilters = document.getElementById('student_filters');
        
        if (scope === 'all') {
            formatSection.style.display = 'none';
            filesContainer.style.display = 'none';
            studentFilters.style.display = 'none';
        } else if (scope === 'students') {
            formatSection.style.display = 'block';
            filesContainer.style.display = 'block';
            studentFilters.style.display = 'block';
            // Trigger count fetch
            fetchStudentCount();
        } else {
            formatSection.style.display = 'block';
            filesContainer.style.display = 'block';
            studentFilters.style.display = 'none';
        }
    }

    // Filter Section Dropdown
    function updateSectionDropdown() {
        const yearVal = document.getElementById('filter_year').value;
        const deptId = document.getElementById('filter_dept').value;
        const sectionSelect = document.getElementById('filter_section');
        
        // Save current selection if exists
        const currentSelection = sectionSelect.value;
        
        // Clear options
        sectionSelect.innerHTML = '<option value="all">All Sections</option>';

        if (yearVal === 'all' || deptId === 'all') {
            const uniqueSecs = [...new Set(sectionsMaster.map(item => item.section_name))];
            uniqueSecs.sort().forEach(sec => {
                let opt = document.createElement('option');
                opt.value = sec;
                opt.text = sec;
                sectionSelect.appendChild(opt);
            });
        } else {
            // Filter Specific: Find Year ID from Year Val
            const yearObj = yearsMaster.find(y => y.year_val == yearVal);
            const yearId = yearObj ? yearObj.id : null;

            if (yearId) {
                const filtered = sectionsMaster.filter(s => s.dept_id == deptId && s.year_id == yearId);
                filtered.forEach(s => {
                    let opt = document.createElement('option');
                    opt.value = s.section_name;
                    opt.text = s.section_name;
                    sectionSelect.appendChild(opt);
                });
            }
        }
        
        sectionSelect.value = 'all'; 
    }

    // Fetch Student Count via AJAX
    function fetchStudentCount() {
        const year = document.getElementById('filter_year').value;
        const dept = document.getElementById('filter_dept').value;
        const section = document.getElementById('filter_section').value;
        const badge = document.getElementById('student_count_badge');

        badge.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Counting...';

        $.post('backup_action.php', {
            action: 'count_students',
            filter_year: year,
            filter_dept: dept,
            filter_section: section
        }, function(resp) {
            if(resp.status === 'success') {
                badge.innerHTML = `<i class="fas fa-users mr-1"></i> Count: ${resp.count}`;
            } else {
                badge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error';
            }
        }, 'json');
    }

    function toggleDetails(btn) {
        const overlay = btn.parentElement;
        const hiddenItems = overlay.querySelectorAll('.hidden-dept');
        hiddenItems.forEach(item => { item.classList.toggle('d-none'); });
        btn.innerText = (btn.innerText === "Load More") ? "Show Less" : "Load More";
    }

    function togglePassword(id, icon) {
        const input = document.getElementById(id);
        if (input.type === "password") { input.type = "text"; icon.classList.replace("fa-eye", "fa-eye-slash"); }
        else { input.type = "password"; icon.classList.replace("fa-eye-slash", "fa-eye"); }
    }

    function checkPasswordStrength() {
        const val = document.getElementById('new_password').value;
        const bar = document.getElementById('strength-bar');
        const container = document.getElementById('strength-container');
        if(val.length > 0) container.style.display = 'block'; else container.style.display = 'none';
        let strength = 0;
        if (val.length >= 6) strength += 20;
        if (val.match(/[a-z]+/)) strength += 20;
        if (val.match(/[A-Z]+/)) strength += 20;
        if (val.match(/[0-9]+/)) strength += 20;
        if (val.match(/[$@#&!]+/)) strength += 20;
        bar.style.width = strength + '%';
        bar.style.backgroundColor = strength < 40 ? '#dc3545' : (strength < 80 ? '#ffc107' : '#28a745');
    }

    function checkMatch() {
        const p1 = document.getElementById('new_password').value;
        const p2 = document.getElementById('confirm_password').value;
        const msg = document.getElementById('match-message');
        const btn = document.getElementById('submitBtn');
        if(p1.length > 0) {
            if (p1 === p2) { msg.innerHTML = '<span class="text-success"><i class="fas fa-check"></i> Match</span>'; btn.disabled = false; }
            else { msg.innerHTML = '<span class="text-danger"><i class="fas fa-times"></i> Mismatch</span>'; if(p2.length > 0) btn.disabled = true; }
        } else { msg.innerHTML = ''; btn.disabled = false; }
    }

    // --- ADMIN MANAGEMENT LOGIC ---

    function openAdminModal() {
        $('#manageAdminsModal').modal('show');
        fetchAdmins();
    }

    function fetchAdmins() {
        showLoader();
        $.post('admin_actions.php', { action: 'fetch_all' }, function(resp) {
            hideLoader();
            if(resp.status === 'success') {
                let html = '';
                resp.data.forEach(user => {
                    let badge = user.status === 'active' ? 'badge-success' : 'badge-danger';
                    html += `
                    <tr class="admin-list-item">
                        <td>${user.full_name}</td>
                        <td>${user.designation || '-'}</td>
                        <td>${user.username}</td>
                        <td><span class="badge ${badge}">${user.status}</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick='openEditAdmin(${JSON.stringify(user)})'><i class="fas fa-edit"></i></button>
                        </td>
                    </tr>`;
                });
                $('#adminsTable tbody').html(html);
            }
        });
    }

    function resetAddForm() {
        $('#add-step-1').show();
        $('#add-step-2').hide();
        $('#verify-pass-section').show();
        $('#verify-otp-section').hide();
        $('#step1-indicator').addClass('active').removeClass('completed');
        $('#step2-indicator').removeClass('active completed');
        $('#step3-indicator').removeClass('active completed');
        
        // Clear Inputs
        $('#new_admin_name, #new_admin_email, #new_admin_username, #new_admin_password, #new_admin_designation, #sudo_password, #sudo_otp').val('');
    }

    function generatePass() {
        const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%";
        let pass = "";
        for (let i = 0; i < 10; i++) pass += chars.charAt(Math.floor(Math.random() * chars.length));
        document.getElementById('new_admin_password').value = pass;
    }

    function goToVerification() {
        if(!$('#new_admin_name').val() || !$('#new_admin_email').val() || !$('#new_admin_username').val() || !$('#new_admin_password').val()) {
            alert('Please fill all fields.'); return;
        }
        $('#add-step-1').hide();
        $('#add-step-2').show();
        $('#step1-indicator').removeClass('active').addClass('completed');
        $('#step2-indicator').addClass('active');
    }

    function verifySudoPassword() {
        showLoader();
        const pass = $('#sudo_password').val();
        $.post('admin_actions.php', { action: 'verify_password', password: pass }, function(resp) {
            if(resp.status === 'success') {
                // Send OTP
                $.post('admin_actions.php', { action: 'send_auth_otp' }, function(otpResp) {
                    hideLoader();
                    if(otpResp.status === 'success') {
                        $('#verify-pass-section').slideUp();
                        $('#verify-otp-section').slideDown();
                        showToast('OTP Sent!');
                    } else {
                        alert(otpResp.message);
                    }
                });
            } else {
                hideLoader();
                alert(resp.message);
            }
        }, 'json');
    }

    function finalizeAddAdmin() {
        showLoader();
        const data = {
            action: 'create_admin',
            otp: $('#sudo_otp').val(),
            full_name: $('#new_admin_name').val(),
            designation: $('#new_admin_designation').val(),
            email: $('#new_admin_email').val(),
            username: $('#new_admin_username').val(),
            password: $('#new_admin_password').val()
        };

        $.post('admin_actions.php', data, function(resp) {
            hideLoader();
            if(resp.status === 'success') {
                $('#step2-indicator').removeClass('active').addClass('completed');
                $('#step3-indicator').addClass('completed');
                alert(resp.message);
                $('#manageAdminsModal').modal('hide');
                resetAddForm();
            } else {
                alert(resp.message);
            }
        }, 'json');
    }

    function openEditAdmin(user) {
        $('#edit_admin_id').val(user.id);
        $('#edit_admin_name').val(user.full_name);
        $('#edit_admin_email').val(user.email);
        $('#edit_admin_designation').val(user.designation);
        $('#edit_admin_status').val(user.status);
        $('#edit_admin_password').val('');
        $('#editAdminModal').modal('show');
    }

    function updateAdmin() {
        showLoader();
        const data = {
            action: 'update_admin',
            id: $('#edit_admin_id').val(),
            full_name: $('#edit_admin_name').val(),
            email: $('#edit_admin_email').val(),
            designation: $('#edit_admin_designation').val(),
            status: $('#edit_admin_status').val(),
            password: $('#edit_admin_password').val()
        };
        $.post('admin_actions.php', data, function(resp) {
            hideLoader();
            if(resp.status === 'success') {
                showToast('Updated successfully');
                $('#editAdminModal').modal('hide');
                fetchAdmins();
            } else {
                alert(resp.message);
            }
        }, 'json');
    }
</script>
</body>
</html>