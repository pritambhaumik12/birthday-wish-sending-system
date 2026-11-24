<?php
// birthday_system01/verify_submissions.php

session_start();

// --- AUTHENTICATION CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "config.php";

// --- PRG: FLASH MESSAGE HANDLING ---
$msg = "";
$msg_type = "";
if (isset($_SESSION['verify_msg'])) {
    $msg = $_SESSION['verify_msg']['text'];
    $msg_type = $_SESSION['verify_msg']['type'];
    unset($_SESSION['verify_msg']);
}

// --- HELPER FUNCTION FOR TABLE NAME ---
if (!function_exists('get_student_table_local')) {
    function get_student_table_local($id_string) {
        // Format: BWU/DEPT/YY/ID -> Extract YY
        $parts = explode('/', $id_string);
        if (count($parts) >= 3 && is_numeric($parts[2])) {
            return "students_" . $parts[2];
        }
        return false;
    }
}

// --- HANDLE POST REQUESTS (ACTIONS) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $action = $_POST['action'] ?? '';
    
    // 0. UPDATE SUBMISSION DETAILS (EDIT FEATURE)
    if ($action == 'update_submission') {
        $sub_id = $_POST['submission_id'] ?? '';
        $u_name = trim($_POST['student_name'] ?? '');
        $u_custom_id = strtoupper(trim($_POST['student_custom_id'] ?? ''));
        $u_email = trim($_POST['student_mail_id'] ?? '');
        $u_dept = trim($_POST['department'] ?? '');
        $u_sec = trim($_POST['section'] ?? '');

        if (!empty($sub_id) && !empty($u_name) && !empty($u_custom_id)) {
            $sql_up = "UPDATE student_submissions SET student_name=?, student_custom_id=?, student_mail_id=?, department=?, section=? WHERE id=?";
            if ($stmt_up = mysqli_prepare($link, $sql_up)) {
                mysqli_stmt_bind_param($stmt_up, "sssssi", $u_name, $u_custom_id, $u_email, $u_dept, $u_sec, $sub_id);
                if (mysqli_stmt_execute($stmt_up)) {
                    $_SESSION['verify_msg'] = ['text' => "Submission details updated successfully.", 'type' => 'success'];
                } else {
                    $_SESSION['verify_msg'] = ['text' => "Error updating submission.", 'type' => 'danger'];
                }
                mysqli_stmt_close($stmt_up);
            }
        } else {
            $_SESSION['verify_msg'] = ['text' => "Cannot update: Missing required fields.", 'type' => 'warning'];
        }
        header("location: verify_submissions.php");
        exit();
    }

    // 1. TOGGLE AUTO-ACCEPT
    if ($action == 'toggle_auto_accept') {
        $val = $_POST['auto_accept_value'] ?? '0';
        $status = ($val === '1') ? '1' : '0';
        
        // Update Setting
        $sql_update = "INSERT INTO system_settings (setting_key, setting_value) VALUES ('auto_accept_verified', ?) ON DUPLICATE KEY UPDATE setting_value = ?";
        if ($stmt = mysqli_prepare($link, $sql_update)) {
            mysqli_stmt_bind_param($stmt, "ss", $status, $status);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        // Process Existing Queue if Enabled
        $processed_count = 0;
        $failed_count = 0;

        if ($status === '1') {
            $sql_auto = "SELECT * FROM student_submissions WHERE email_verified = 1";
            
            if ($res_auto = mysqli_query($link, $sql_auto)) {
                // Prepare Delete Statement (Static)
                $stmt_delete = mysqli_prepare($link, "DELETE FROM student_submissions WHERE id = ?");

                while ($data = mysqli_fetch_assoc($res_auto)) {
                    $target_table = get_student_table_local($data['student_custom_id']);
                    
                    if ($target_table) {
                        // Check if table exists
                        $chk = mysqli_query($link, "SHOW TABLES LIKE '$target_table'");
                        if (mysqli_num_rows($chk) > 0) {
                            
                            // Check Duplicate in Target Table
                            $check_sql = "SELECT id FROM `$target_table` WHERE student_custom_id = ?";
                            if ($stmt_check = mysqli_prepare($link, $check_sql)) {
                                mysqli_stmt_bind_param($stmt_check, "s", $data['student_custom_id']);
                                mysqli_stmt_execute($stmt_check);
                                mysqli_stmt_store_result($stmt_check);
                                
                                if (mysqli_stmt_num_rows($stmt_check) == 0) {
                                    // Insert into Dynamic Table
                                    $insert_sql = "INSERT INTO `$target_table` (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                    if ($stmt_insert = mysqli_prepare($link, $insert_sql)) {
                                        mysqli_stmt_bind_param($stmt_insert, "ssssssss", 
                                            $data['student_custom_id'], $data['student_name'], $data['student_mail_id'], 
                                            $data['country_code'], $data['student_phone'], $data['date_of_birth'], 
                                            $data['section'], $data['department']
                                        );
                                        if (mysqli_stmt_execute($stmt_insert)) {
                                            // Delete from submissions
                                            mysqli_stmt_bind_param($stmt_delete, "i", $data['id']);
                                            mysqli_stmt_execute($stmt_delete);
                                            $processed_count++;
                                        }
                                        mysqli_stmt_close($stmt_insert);
                                    }
                                } else {
                                    $failed_count++; // Duplicate
                                }
                                mysqli_stmt_close($stmt_check);
                            }
                        } else {
                            $failed_count++; // Table missing
                        }
                    } else {
                        $failed_count++; // Invalid ID format
                    }
                }
                mysqli_stmt_close($stmt_delete);
            }
            
            $_SESSION['verify_msg'] = ['text' => "Auto-Accept Enabled. Approved $processed_count students ($failed_count skipped/duplicates).", 'type' => 'success'];
        } else {
            $_SESSION['verify_msg'] = ['text' => "Auto-Accept Disabled. Submissions now require manual approval.", 'type' => 'info'];
        }
        
        header("location: verify_submissions.php");
        exit();
    }

    // 2. BULK / SINGLE ACTIONS (ACCEPT/REJECT)
    $ids = [];
    if (isset($_POST['submission_id']) && !empty($_POST['submission_id'])) {
        $ids[] = $_POST['submission_id'];
    } elseif (isset($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $ids = $_POST['selected_ids'];
    }

    if (!empty($ids)) {
        $success_count = 0;
        $fail_count = 0;
        
        $stmt_fetch = mysqli_prepare($link, "SELECT * FROM student_submissions WHERE id = ?");
        $stmt_delete = mysqli_prepare($link, "DELETE FROM student_submissions WHERE id = ?");

        foreach ($ids as $sub_id) {
            if ($action == 'reject') {
                mysqli_stmt_bind_param($stmt_delete, "i", $sub_id);
                if (mysqli_stmt_execute($stmt_delete)) $success_count++;
            } 
            elseif ($action == 'accept') {
                mysqli_stmt_bind_param($stmt_fetch, "i", $sub_id);
                mysqli_stmt_execute($stmt_fetch);
                $res = mysqli_stmt_get_result($stmt_fetch);
                $data = mysqli_fetch_assoc($res);

                if ($data) {
                    $target_table = get_student_table_local($data['student_custom_id']);

                    if ($target_table) {
                        // Verify Table Exists
                        $chk_table = mysqli_query($link, "SHOW TABLES LIKE '$target_table'");
                        if (mysqli_num_rows($chk_table) > 0) {
                            
                            // Check Duplicate
                            $chk_dup_sql = "SELECT id FROM `$target_table` WHERE student_custom_id = ?";
                            if ($stmt_check = mysqli_prepare($link, $chk_dup_sql)) {
                                mysqli_stmt_bind_param($stmt_check, "s", $data['student_custom_id']);
                                mysqli_stmt_execute($stmt_check);
                                mysqli_stmt_store_result($stmt_check);
                                
                                if (mysqli_stmt_num_rows($stmt_check) == 0) {
                                    // Insert
                                    $ins_sql = "INSERT INTO `$target_table` (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                    if ($stmt_ins = mysqli_prepare($link, $ins_sql)) {
                                        mysqli_stmt_bind_param($stmt_ins, "ssssssss", 
                                            $data['student_custom_id'], $data['student_name'], $data['student_mail_id'], 
                                            $data['country_code'], $data['student_phone'], $data['date_of_birth'], 
                                            $data['section'], $data['department']
                                        );
                                        if (mysqli_stmt_execute($stmt_ins)) {
                                            // Delete from submissions
                                            mysqli_stmt_bind_param($stmt_delete, "i", $sub_id);
                                            mysqli_stmt_execute($stmt_delete);
                                            $success_count++;
                                        } else {
                                            $fail_count++;
                                        }
                                        mysqli_stmt_close($stmt_ins);
                                    }
                                } else {
                                    $fail_count++; // Duplicate
                                }
                                mysqli_stmt_close($stmt_check);
                            }
                        } else {
                            $fail_count++; // Table missing
                        }
                    } else {
                        $fail_count++; // Invalid ID
                    }
                }
            }
        }
        
        if($stmt_fetch) mysqli_stmt_close($stmt_fetch);
        if($stmt_delete) mysqli_stmt_close($stmt_delete);

        if ($action == 'accept') {
            if ($fail_count == 0) 
                $_SESSION['verify_msg'] = ['text' => "Successfully approved $success_count student(s).", 'type' => 'success'];
            else 
                $_SESSION['verify_msg'] = ['text' => "Approved $success_count. $fail_count failed (Duplicate ID or Missing Batch Table).", 'type' => 'warning'];
        } elseif ($action == 'reject') {
            $_SESSION['verify_msg'] = ['text' => "Rejected and deleted $success_count submission(s).", 'type' => 'warning'];
        }
    } else {
        $_SESSION['verify_msg'] = ['text' => "No items selected.", 'type' => 'info'];
    }

    header("location: verify_submissions.php");
    exit();
}

// --- FETCH DATA FOR VIEW ---

// 1. Get Auto-Accept Setting
$auto_accept_enabled = false;
$sql_sets = "SELECT setting_value FROM system_settings WHERE setting_key = 'auto_accept_verified'";
if ($res_set = mysqli_query($link, $sql_sets)) {
    if ($row_set = mysqli_fetch_assoc($res_set)) {
        $auto_accept_enabled = ($row_set['setting_value'] == '1');
    }
}

// 2. Get Filter Options
$dept_options = [];
$sec_options = [];
$year_options = [];

function getDistinct($link, $col) {
    $arr = [];
    $sql = "SELECT DISTINCT $col FROM student_submissions WHERE $col IS NOT NULL AND $col != '' ORDER BY $col";
    if ($res = mysqli_query($link, $sql)) { while ($r = mysqli_fetch_assoc($res)) $arr[] = $r[$col]; }
    return $arr;
}
$dept_options = getDistinct($link, 'department');
$sec_options = getDistinct($link, 'section');

// 2a. Get Master Sections for Edit Dropdown (To ensure valid assignment)
$master_sections = [];
$sql_ms = "SELECT DISTINCT section_name FROM sections_master ORDER BY section_name";
if ($res_ms = mysqli_query($link, $sql_ms)) {
    while ($row = mysqli_fetch_assoc($res_ms)) {
        $master_sections[] = $row['section_name'];
    }
}

$year_sql = "SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(student_custom_id, '/', 3), '/', -1) as adm_year 
             FROM student_submissions 
             WHERE student_custom_id LIKE '%/%/%/%' 
             HAVING adm_year REGEXP '^[0-9]{2,4}$' 
             ORDER BY adm_year DESC";
if ($res = mysqli_query($link, $year_sql)) { 
    while ($row = mysqli_fetch_assoc($res)) $year_options[] = $row['adm_year']; 
}

// 3. Apply Filters
$search_query = trim($_GET['search'] ?? '');
$filter_dept = trim($_GET['dept'] ?? '');
$filter_year = trim($_GET['year'] ?? '');
$filter_sec = trim($_GET['sec'] ?? '');
$filter_period = trim($_GET['period'] ?? 'last_10'); 
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');

$sql_date_start = "";
if ($filter_period === '1_day') $sql_date_start = date('Y-m-d', strtotime('-1 day'));
elseif ($filter_period === '7_days') $sql_date_start = date('Y-m-d', strtotime('-7 days'));
elseif ($filter_period === '1_month') $sql_date_start = date('Y-m-d', strtotime('-1 month'));
elseif ($filter_period === '6_months') $sql_date_start = date('Y-m-d', strtotime('-6 months'));
elseif ($filter_period === 'custom') $sql_date_start = $filter_date_from;

$sql = "SELECT * FROM student_submissions WHERE 1=1";
$params = [];
$types = "";

if (!empty($search_query)) {
    $sql .= " AND (student_name LIKE ? OR student_custom_id LIKE ? OR student_mail_id LIKE ?)";
    $p = "%$search_query%"; array_push($params, $p, $p, $p); $types .= "sss";
}
if (!empty($filter_dept)) { $sql .= " AND department = ?"; $params[] = $filter_dept; $types .= "s"; }
if (!empty($filter_sec)) { $sql .= " AND section = ?"; $params[] = $filter_sec; $types .= "s"; }
if (!empty($filter_year)) { $sql .= " AND student_custom_id LIKE ?"; $params[] = "%/$filter_year/%"; $types .= "s"; }
if ($filter_period !== 'last_10' && !empty($sql_date_start)) { $sql .= " AND DATE(submission_date) >= ?"; $params[] = $sql_date_start; $types .= "s"; }
if ($filter_period === 'custom' && !empty($filter_date_to)) { $sql .= " AND DATE(submission_date) <= ?"; $params[] = $filter_date_to; $types .= "s"; }

if ($filter_period === 'last_10') $sql .= " ORDER BY submission_date DESC LIMIT 10";
else $sql .= " ORDER BY submission_date ASC";

$submissions = [];
if ($stmt = mysqli_prepare($link, $sql)) {
    if (!empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $submissions[] = $row;
    mysqli_stmt_close($stmt);
}
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Submissions | Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        /* === Global & Background === */
        body { 
            background: url('university.jpg') no-repeat center center fixed; 
            background-size: cover; 
            min-height: 100vh; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            padding-bottom: 50px; 
            color: #333;
        }

        /* === Loader Overlay === */
        #loader { 
            position: fixed; inset: 0; background: #ffffff; 
            display: flex; align-items: center; justify-content: center; 
            z-index: 9999; transition: opacity 0.5s ease; 
        }
        .spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }
        .spinner { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; 
            border: 8px solid #f3f3f3; border-top: 8px solid #f57c00; 
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2; 
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* === Wrapper & Cards === */
        .wrapper { 
            width: 100%; max-width: 1250px; margin: 30px auto; padding: 25px; 
            background-color: rgba(255, 255, 255, 0.98); 
            border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.15); 
        }

        /* === Header === */
        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            border-bottom: 2px solid #f0f0f0; margin-bottom: 25px; padding-bottom: 15px; 
            flex-wrap: wrap; gap: 15px; 
        }
        .page-header h2 { font-weight: 700; color: #343a40; margin: 0; font-size: 1.8rem; }
        .page-header h2 i { color: #f57c00; }
        
        .btn-back { 
            border-radius: 50px; padding: 8px 25px; border: 2px solid #6c757d; 
            background: transparent; color: #6c757d; font-weight: 600; transition: all 0.3s; 
        }
        .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }

        /* === Auto-Accept Setting Card === */
        .auto-accept-card {
            background: linear-gradient(to right, #ffffff, #f9f9f9);
            border-left: 5px solid #ccc;
            border-radius: 8px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center;
            transition: all 0.3s ease;
        }
        .auto-accept-card.active { border-left-color: #28a745; background: linear-gradient(to right, #e8f5e9, #fff); }
        .auto-accept-card.inactive { border-left-color: #6c757d; }
        
        /* === Filter Section === */
        .filter-section { background-color: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid #e9ecef; }
        .filter-label { font-size: 0.75rem; font-weight: 800; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; display: block; }
        .form-control { border-radius: 8px; border: 1px solid #ced4da; height: calc(1.5em + .75rem + 8px); }
        .form-control:focus { border-color: #f57c00; box-shadow: 0 0 0 0.2rem rgba(245, 124, 0, 0.25); }

        /* === Table & Actions === */
        .bulk-bar { 
            background: #fff; padding: 15px; border-radius: 10px; margin-bottom: 15px; 
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e9ecef;
        }
        
        /* === Enhanced Table Scrolling === */
        .table-container { 
            border-radius: 12px; 
            background: white; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
            border: 1px solid #e9ecef; 
            overflow-x: auto; 
            -webkit-overflow-scrolling: touch;
        }
        
        /* Custom scrollbar */
        .table-container::-webkit-scrollbar { height: 8px; }
        .table-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .table-container::-webkit-scrollbar-thumb { background: #ced4da; border-radius: 4px; }
        .table-container::-webkit-scrollbar-thumb:hover { background: #adb5bd; }

        .table { margin-bottom: 0; min-width: 800px; }
        
        .table thead th { 
            background-color: #343a40; color: white; border: none; 
            text-transform: uppercase; font-size: 0.85rem; font-weight: 600; 
            vertical-align: middle; white-space: nowrap; padding: 15px;
        }
        .table tbody td { 
            vertical-align: middle; padding: 12px 15px; color: #495057; 
            white-space: nowrap; 
        }
        .table-hover tbody tr:hover { background-color: #f1f5f9; transition: background-color 0.2s; }

        /* === Badges & Buttons === */
        .badge-dept { background-color: #e3f2fd; color: #0d47a1; font-weight: 600; padding: 5px 10px; border-radius: 6px; font-size: 0.85rem; }
        .badge-verified { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 5px 12px; border-radius: 20px; }
        .badge-unverified { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 5px 12px; border-radius: 20px; }
        
        .btn-view { 
            width: 32px; height: 32px; border-radius: 50%; display: inline-flex; 
            align-items: center; justify-content: center; border: none; 
            background-color: #17a2b8; color: white; transition: all 0.2s; 
            box-shadow: 0 2px 5px rgba(23, 162, 184, 0.3);
        }
        .btn-view:hover { transform: scale(1.1); background-color: #138496; color: white; }
        
        /* Custom Checkbox */
        .custom-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #f57c00; }

        /* Responsive Tweaks */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; gap: 10px; }
            .page-header h2 { font-size: 1.5rem; }
            .btn-back { width: 100%; text-align: center; display: block; }
            
            .auto-accept-card { flex-direction: column; align-items: flex-start; }
            .auto-accept-actions { width: 100%; justify-content: flex-start !important; margin-top: 15px; }
            
            .bulk-bar { flex-direction: column; align-items: stretch; }
            .bulk-bar > div { display: flex; justify-content: space-between; align-items: center; width: 100%; }
        }
    </style>
</head>
<body>

    <div id="loader">
        <div class="spinner-container">
            <div class="spinner"></div>
            <img src="BWU_logo.png" class="loader-logo" alt="Loading">
        </div>
    </div>

    <div class="container-fluid">
        <div class="wrapper">
            
            <div class="page-header">
                <div>
                    <h2><i class="fas fa-clipboard-check mr-2"></i>Verify Submissions</h2>
                    <p class="text-muted mb-0">Review and approve student self-registrations.</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-back" onclick="showLoader()"><i class="fas fa-arrow-left mr-2"></i>Dashboard</a>
                </div>
            </div>

            <?php if (!empty($msg)): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm rounded-lg mb-4" role="alert">
                    <div class="d-flex align-items-center">
                        <i class="fas <?php echo ($msg_type == 'success') ? 'fa-check-circle' : 'fa-info-circle'; ?> fa-lg mr-3"></i>
                        <strong><?php echo $msg; ?></strong>
                    </div>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="settingForm">
                <input type="hidden" name="action" value="toggle_auto_accept">
                <input type="hidden" name="auto_accept_value" id="autoAcceptValue" value="<?php echo $auto_accept_enabled ? '1' : '0'; ?>">
                
                <div class="auto-accept-card <?php echo $auto_accept_enabled ? 'active' : 'inactive'; ?>">
                    <div class="text-left pr-3">
                        <h5 class="font-weight-bold mb-1 <?php echo $auto_accept_enabled ? 'text-success' : 'text-secondary'; ?>">
                            <i class="fas fa-robot mr-2"></i>Auto-Accept Verified Emails
                        </h5>
                        <p class="mb-0 small text-muted">
                            <?php if ($auto_accept_enabled): ?>
                                <span class="text-success font-weight-bold">ENABLED.</span> Verified submissions are approved automatically.
                            <?php else: ?>
                                <span class="text-muted">DISABLED.</span> All submissions require manual approval.
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="d-flex align-items-center auto-accept-actions">
                        <select class="form-control mr-2 font-weight-bold shadow-sm" id="autoAcceptSelect" style="width: auto; border-color: #6c757d;">
                            <option value="0" <?php echo !$auto_accept_enabled ? 'selected' : ''; ?>>No (Disabled)</option>
                            <option value="1" <?php echo $auto_accept_enabled ? 'selected' : ''; ?>>Yes (Enabled)</option>
                        </select>
                        <button type="button" class="btn btn-primary font-weight-bold shadow-sm px-4" id="btnSaveSetting">
                            <i class="fas fa-save mr-2"></i>Save
                        </button>
                    </div>
                </div>
            </form>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="get" class="filter-section" id="filterForm">
                <div class="form-row">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <label class="filter-label">Search</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span></div>
                            <input type="text" name="search" class="form-control border-left-0" placeholder="Name, ID, Email..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <label class="filter-label">Department</label>
                        <select name="dept" class="form-control">
                            <option value="">All Departments</option>
                            <?php foreach ($dept_options as $opt) echo "<option value='".htmlspecialchars($opt)."' ".($filter_dept==$opt?'selected':'').">".htmlspecialchars($opt)."</option>"; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <label class="filter-label">Year</label>
                        <select name="year" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($year_options as $opt) echo "<option value='".htmlspecialchars($opt)."' ".($filter_year==$opt?'selected':'').">".htmlspecialchars($opt)."</option>"; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <label class="filter-label">Section</label>
                        <select name="sec" class="form-control">
                            <option value="">All</option>
                            <?php foreach ($sec_options as $opt) echo "<option value='".htmlspecialchars($opt)."' ".($filter_sec==$opt?'selected':'').">".htmlspecialchars($opt)."</option>"; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <label class="filter-label">Time Period</label>
                        <select name="period" id="periodSelect" class="form-control" onchange="toggleCustomDates()">
                            <option value="last_10" <?php echo ($filter_period == 'last_10') ? 'selected' : ''; ?>>Recent 10</option>
                            <option value="1_day" <?php echo ($filter_period == '1_day') ? 'selected' : ''; ?>>Last 24 Hours</option>
                            <option value="7_days" <?php echo ($filter_period == '7_days') ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="all" <?php echo ($filter_period == 'all') ? 'selected' : ''; ?>>Show All</option>
                            <option value="custom" <?php echo ($filter_period == 'custom') ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row" id="customDateRow" style="<?php echo ($filter_period !== 'custom') ? 'display: none;' : ''; ?>">
                    <div class="col-lg-3 offset-lg-6 col-md-6 mb-3">
                        <label class="filter-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <label class="filter-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <button type="submit" class="btn btn-info btn-block font-weight-bold shadow-sm" style="background-color: #17a2b8;" onclick="showLoader()">
                            <i class="fas fa-filter mr-1"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>

            <form id="actionForm" method="post">
                <input type="hidden" name="action" id="formAction" value="">
                
                <div class="bulk-bar">
                    <div class="d-flex align-items-center">
                        <input type="checkbox" id="selectAll" class="custom-checkbox mr-3">
                        <label for="selectAll" class="font-weight-bold text-dark mb-0 cursor-pointer user-select-none">Select All</label>
                    </div>
                    <div>
                        <span class="text-muted small mr-2 d-none d-md-inline">With selected:</span>
                        <button type="button" class="btn btn-success btn-sm font-weight-bold shadow-sm px-3 py-2" onclick="openBulkModal('accept')">
                            <i class="fas fa-check-double mr-2"></i>Approve
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm font-weight-bold ml-2 px-3 py-2" onclick="openBulkModal('reject')">
                            <i class="fas fa-times mr-2"></i>Reject
                        </button>
                    </div>
                </div>

                <div class="table-container table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="pl-4" style="width: 50px;">#</th>
                                <th>Submitted</th>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Department</th>
                                <th>Sec</th>
                                <th class="text-center">Email Verified</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <div class="d-flex flex-column align-items-center">
                                            <i class="fas fa-inbox fa-3x text-muted mb-3" style="opacity: 0.3;"></i>
                                            <h5 class="text-muted">No pending submissions found</h5>
                                            <p class="small text-muted">Try adjusting your filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $sub): 
                                    $jsonData = htmlspecialchars(json_encode($sub), ENT_QUOTES, 'UTF-8'); 
                                ?>
                                <tr id="row-<?php echo $sub['id']; ?>">
                                    <td class="pl-4 text-center">
                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $sub['id']; ?>" class="sub-checkbox custom-checkbox" data-json="<?php echo $jsonData; ?>">
                                    </td>
                                    <td class="text-muted small font-weight-bold">
                                        <i class="far fa-clock mr-1"></i><?php echo date('M d', strtotime($sub['submission_date'])); ?><br>
                                        <span class="text-secondary"><?php echo date('Y', strtotime($sub['submission_date'])); ?></span>
                                    </td>
                                    <td><span class="font-weight-bold text-dark"><?php echo htmlspecialchars($sub['student_custom_id']); ?></span></td>
                                    <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                                    <td><span class="badge-dept"><?php echo htmlspecialchars($sub['department']); ?></span></td>
                                    <td><span class="badge badge-warning text-dark"><?php echo htmlspecialchars($sub['section']); ?></span></td>
                                    <td class="text-center">
                                        <?php if (isset($sub['email_verified']) && $sub['email_verified'] == 1): ?>
                                            <span class="badge badge-verified"><i class="fas fa-check-circle mr-1"></i>Yes</span>
                                        <?php else: ?>
                                            <span class="badge badge-unverified"><i class="fas fa-times-circle mr-1"></i>No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn-view shadow-sm" onclick='openSingleModal(<?php echo $jsonData; ?>)' title="Edit & Review">
                                            <i class="fas fa-edit small"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

        </div>
    </div>

    <div class="modal fade" id="autoAcceptModal" tabindex="-1" data-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header text-white" id="modalHeaderBg">
                    <h5 class="modal-title font-weight-bold" id="modalTitle"></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-3">
                        <i id="modalIcon" class="fas fa-3x mb-3"></i>
                        <h5 class="font-weight-bold" id="modalSubTitle"></h5>
                    </div>
                    <p id="modalBodyText"></p>
                    <p class="mb-0 text-muted small" id="modalSmallText"></p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn font-weight-bold px-4" id="btnConfirmAutoAccept"></button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="bulkModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-lg">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-exclamation-triangle mr-2 text-warning"></i>Confirm Bulk Action</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <p class="lead mb-2">You are about to <strong id="bulkActionName" class="text-uppercase"></strong> <span class="badge badge-primary" id="selectedCount" style="font-size: 1rem;">0</span> submissions.</p>
                    <p class="text-muted small">The following students will be affected:</p>
                    <div class="p-3 bg-light border rounded" id="bulkPreviewContainer" style="max-height: 200px; overflow-y: auto;"></div>
                    <div class="alert alert-warning mt-3 mb-0 small"><i class="fas fa-info-circle mr-1"></i> This action cannot be undone.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary font-weight-bold px-4" id="confirmBulkBtn" onclick="submitBulkAction()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="singleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-lg">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" onsubmit="showLoader()">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Review & Edit Submission</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    
                    <div class="modal-body p-0">
                        <div class="p-4 bg-light text-center border-bottom">
                             <div class="avatar-circle bg-white text-info shadow-sm mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:70px; height:70px; border-radius:50%; font-size:2rem;">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <h5 class="text-muted mb-0">Edit Details Before Approval</h5>
                            <div id="s_ver" class="mt-2"></div>
                        </div>

                        <div class="p-4">
                            <input type="hidden" name="submission_id" id="edit_sub_id">
                            
                            <div class="form-row">
                                <div class="col-md-6 mb-3">
                                    <label class="small text-muted font-weight-bold text-uppercase">Full Name</label>
                                    <input type="text" name="student_name" id="edit_name" class="form-control font-weight-bold" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="small text-muted font-weight-bold text-uppercase">Student ID (Custom ID)</label>
                                    <input type="text" name="student_custom_id" id="edit_custom_id" class="form-control font-weight-bold" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-12 mb-3">
                                    <label class="small text-muted font-weight-bold text-uppercase">Email Address</label>
                                    <input type="email" name="student_mail_id" id="edit_email" class="form-control" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="col-md-6 mb-3">
                                    <label class="small text-muted font-weight-bold text-uppercase">Department</label>
                                    <select name="department" id="edit_dept" class="form-control" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($dept_options as $d) echo "<option value='".htmlspecialchars($d)."'>".htmlspecialchars($d)."</option>"; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="small text-muted font-weight-bold text-uppercase">Section</label>
                                    <select name="section" id="edit_sec" class="form-control" required>
                                        <option value="">Select Section</option>
                                        <?php 
                                            foreach ($master_sections as $ms) {
                                                echo "<option value='".htmlspecialchars($ms)."'>".htmlspecialchars($ms)."</option>";
                                            }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="text-right">
                                <small class="text-muted" id="edit_date_display"></small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer bg-light d-flex justify-content-between">
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this submission?');">
                            <i class="fas fa-trash-alt mr-2"></i>Reject
                        </button>
                        
                        <div class="d-flex">
                             <button type="submit" name="action" value="update_submission" class="btn btn-primary mr-2 shadow-sm">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                             <button type="submit" name="action" value="accept" class="btn btn-success shadow-sm px-4">
                                <i class="fas fa-check mr-2"></i>Approve
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="alertModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-body text-center p-4">
                    <i class="fas fa-exclamation-circle fa-3x text-warning mb-3"></i>
                    <h5>No Selection</h5>
                    <p class="text-muted small">Please select at least one item.</p>
                    <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        // --- Loader Logic ---
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() { loader.style.display = 'none'; }, 500);
        });

        // Trigger loader on pageshow to handle back button cache
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                var loader = document.getElementById('loader');
                loader.style.display = 'none';
                loader.style.opacity = '0';
            }
        });

        function showLoader() {
            var loader = document.getElementById('loader');
            loader.style.display = 'flex';
            setTimeout(function() { loader.style.opacity = '1'; }, 10);
        }

        // --- Filters ---
        function toggleCustomDates() {
            var val = document.getElementById('periodSelect').value;
            document.getElementById('customDateRow').style.display = (val === 'custom') ? 'flex' : 'none';
        }

        // --- Checkbox Logic ---
        $('#selectAll').click(function() {
            $('.sub-checkbox').prop('checked', this.checked);
        });

        // --- Modals ---
        
        // UPDATED: Populate Edit Form in Modal
        function openSingleModal(data) {
            $('#edit_sub_id').val(data.id);
            $('#edit_name').val(data.student_name);
            $('#edit_custom_id').val(data.student_custom_id);
            $('#edit_email').val(data.student_mail_id);
            $('#edit_dept').val(data.department);
            $('#edit_sec').val(data.section);
            
            $('#edit_date_display').text('Submitted on: ' + data.submission_date);
            
            if(data.email_verified == 1) {
                $('#s_ver').html('<span class="badge badge-success px-3 py-2"><i class="fas fa-check-circle mr-2"></i>Email Verified</span>');
            } else {
                $('#s_ver').html('<span class="badge badge-danger px-3 py-2"><i class="fas fa-times-circle mr-2"></i>Not Verified</span>');
            }
            
            $('#singleModal').modal('show');
        }

        function openBulkModal(action) {
            var selected = $('.sub-checkbox:checked');
            if (selected.length === 0) {
                $('#alertModal').modal('show');
                return;
            }
            
            $('#formAction').val(action);
            $('#selectedCount').text(selected.length);
            
            var btn = $('#confirmBulkBtn');
            var name = $('#bulkActionName');
            
            if (action === 'accept') {
                btn.removeClass('btn-danger').addClass('btn-success');
                name.text('APPROVE');
                name.addClass('text-success').removeClass('text-danger');
            } else {
                btn.removeClass('btn-success').addClass('btn-danger');
                name.text('REJECT');
                name.addClass('text-danger').removeClass('text-success');
            }
            
            $('#bulkPreviewContainer').empty();
            selected.each(function() {
                var d = $(this).data('json');
                $('#bulkPreviewContainer').append(
                    '<div class="d-flex justify-content-between border-bottom py-1 small">' +
                    '<span>' + d.student_name + '</span>' +
                    '<span class="text-muted">' + d.student_custom_id + '</span>' +
                    '</div>'
                );
            });
            
            $('#bulkModal').modal('show');
        }

        function submitBulkAction() {
            showLoader();
            $('#actionForm').submit();
        }

        // --- SAVE BUTTON LOGIC FOR SETTINGS ---
        $('#btnSaveSetting').click(function() {
            var selectedVal = $('#autoAcceptSelect').val();
            var currentVal = $('#autoAcceptValue').val();
            var headerBg = $('#modalHeaderBg');
            var icon = $('#modalIcon');
            var confirmBtn = $('#btnConfirmAutoAccept');
            
            if (selectedVal !== currentVal) {
                if (selectedVal === '1') {
                    headerBg.removeClass('bg-secondary').addClass('bg-warning text-dark');
                    $('#modalTitle').html('<i class="fas fa-robot mr-2"></i>Enable Auto-Accept?');
                    icon.removeClass('text-secondary fa-info-circle').addClass('text-warning fa-bolt');
                    $('#modalSubTitle').text('Warning: Automated Action');
                    $('#modalBodyText').html('Turning this ON will <strong>immediately approve</strong> all currently pending submissions that have verified emails.');
                    $('#modalSmallText').text('Future verified submissions will also be approved automatically.');
                    confirmBtn.removeClass('btn-dark').addClass('btn-success').text('Yes, Enable It');
                } else {
                    headerBg.removeClass('bg-warning text-dark').addClass('bg-secondary');
                    $('#modalTitle').html('<i class="fas fa-cog mr-2"></i>Disable Auto-Accept?');
                    icon.removeClass('text-warning fa-bolt').addClass('text-secondary fa-info-circle');
                    $('#modalSubTitle').text('Confirmation Required');
                    $('#modalBodyText').html('You are turning <strong>OFF</strong> automatic approval.');
                    $('#modalSmallText').text('All future submissions will require manual verification.');
                    confirmBtn.removeClass('btn-success').addClass('btn-dark').text('Yes, Disable It');
                }
                $('#autoAcceptModal').modal('show');
            } else {
                 alert("No changes were made to the setting.");
            }
        });

        $('#btnConfirmAutoAccept').click(function() {
            $('#autoAcceptModal').modal('hide');
            var selectedVal = $('#autoAcceptSelect').val();
            toggleAndSubmit(selectedVal === '1');
        });

        function toggleAndSubmit(turnOn) {
            showLoader();
            $('#autoAcceptValue').val(turnOn ? '1' : '0');
            $('#settingForm').submit();
        }
    </script>
</body>
</html>