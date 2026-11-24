<?php
// birthday_system01/departments.php

// Initialize the session
session_start();

// Check if the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "config.php";

// Define messages
$msg = "";
$msg_type = "";

// PRG Pattern: Retrieve Flash Message
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg']['text'];
    $msg_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}

// --- HANDLE POST REQUESTS ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $action = $_POST['action'] ?? '';

    // ==========================
    // 1. DEPARTMENT ACTIONS
    // ==========================
    if ($action === 'add_dept') {
        $new_dept_name = trim($_POST['dept_name']);
        $new_dept_initials = trim($_POST['dept_initials']);

        if (!empty($new_dept_name) && !empty($new_dept_initials)) {
            $sql = "INSERT INTO departments_master (dept_name, dept_initials) VALUES (?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ss", $new_dept_name, $new_dept_initials);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['flash_msg'] = ['text' => "Department created successfully!", 'type' => 'success'];
                } else {
                    $_SESSION['flash_msg'] = ['text' => "Error: Department already exists.", 'type' => 'danger'];
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    elseif ($action === 'rename_dept') {
        $id = $_POST['dept_id'];
        $old_name = trim($_POST['old_name']);
        $new_name = trim($_POST['new_name']);
        $new_initials = trim($_POST['new_initials']);

        if (!empty($new_name) && !empty($new_initials)) {
            $sql = "UPDATE departments_master SET dept_name = ?, dept_initials = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ssi", $new_name, $new_initials, $id);
                if (mysqli_stmt_execute($stmt)) {
                    // Update Linked Students in ALL Year Tables
                    // We first fetch all available years to know which tables to update
                    $years_check_sql = "SELECT year_val FROM years_master";
                    $years_res = mysqli_query($link, $years_check_sql);
                    if ($years_res) {
                        while ($yr_row = mysqli_fetch_assoc($years_res)) {
                            $table_name = "students_" . $yr_row['year_val'];
                            // Check if table exists before update
                            $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
                            if (mysqli_num_rows($check_table) > 0 && $old_name !== $new_name) {
                                $update_students = "UPDATE `$table_name` SET department = ? WHERE department = ? AND is_deleted = 0";
                                if ($stmt2 = mysqli_prepare($link, $update_students)) {
                                    mysqli_stmt_bind_param($stmt2, "ss", $new_name, $old_name);
                                    mysqli_stmt_execute($stmt2);
                                    mysqli_stmt_close($stmt2);
                                }
                            }
                        }
                    }

                    $_SESSION['flash_msg'] = ['text' => "Department updated!", 'type' => 'success'];
                } else {
                    $_SESSION['flash_msg'] = ['text' => "Error updating department.", 'type' => 'danger'];
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    elseif ($action === 'delete_dept') {
        $id = $_POST['dept_id'];
        $del_name = trim($_POST['delete_name']);
        
        if (!empty($del_name)) {
            $sql = "DELETE FROM departments_master WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $id);
                if (mysqli_stmt_execute($stmt)) {
                    // Remove department reference from all student tables
                    $years_check_sql = "SELECT year_val FROM years_master";
                    $years_res = mysqli_query($link, $years_check_sql);
                    if ($years_res) {
                        while ($yr_row = mysqli_fetch_assoc($years_res)) {
                            $table_name = "students_" . $yr_row['year_val'];
                            $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
                            if (mysqli_num_rows($check_table) > 0) {
                                $update_students = "UPDATE `$table_name` SET department = '' WHERE department = ? AND is_deleted = 0";
                                if ($stmt2 = mysqli_prepare($link, $update_students)) {
                                    mysqli_stmt_bind_param($stmt2, "s", $del_name);
                                    mysqli_stmt_execute($stmt2);
                                    mysqli_stmt_close($stmt2);
                                }
                            }
                        }
                    }
                    $_SESSION['flash_msg'] = ['text' => "Department deleted.", 'type' => 'warning'];
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    // ==========================
    // 2. YEAR ACTIONS (UPDATED FOR DYNAMIC TABLES)
    // ==========================
    elseif ($action === 'add_year') {
        $year_val = trim($_POST['year_val']);
        
        if (preg_match('/^\d{2}$/', $year_val)) {
            // Check if year exists
            $check_sql = "SELECT id FROM years_master WHERE year_val = ?";
            $exists = false;
            if ($stmt = mysqli_prepare($link, $check_sql)) {
                mysqli_stmt_bind_param($stmt, "s", $year_val);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) > 0) $exists = true;
                mysqli_stmt_close($stmt);
            }

            if (!$exists) {
                // Insert into Master
                $sql = "INSERT INTO years_master (year_val) VALUES (?)";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $year_val);
                    if (mysqli_stmt_execute($stmt)) {
                        
                        // --- DYNAMIC TABLE CREATION ---
                        $stu_table = "students_" . $year_val;
                        $log_table = "email_logs_" . $year_val;

                        // Create Student Table
                        $sql_create_stu = "CREATE TABLE IF NOT EXISTS `$stu_table` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `student_custom_id` varchar(50) NOT NULL,
                            `student_name` varchar(100) NOT NULL,
                            `student_mail_id` varchar(100) NOT NULL,
                            `country_code` varchar(10) DEFAULT NULL,
                            `student_phone` varchar(20) DEFAULT NULL,
                            `date_of_birth` date NOT NULL,
                            `section` varchar(50) DEFAULT NULL,
                            `department` varchar(100) DEFAULT NULL,
                            `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `student_custom_id` (`student_custom_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                        mysqli_query($link, $sql_create_stu);

                        // Create Email Logs Table
                        $sql_create_log = "CREATE TABLE IF NOT EXISTS `$log_table` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `student_id` int(11) DEFAULT NULL,
                            `sent_to_email` varchar(100) NOT NULL,
                            `sent_at` datetime DEFAULT current_timestamp(),
                            `status` varchar(20) DEFAULT 'sent',
                            PRIMARY KEY (`id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                        mysqli_query($link, $sql_create_log);

                        $_SESSION['flash_msg'] = ['text' => "Year '$year_val' added. Tables $stu_table & $log_table created.", 'type' => 'success'];
                    } else {
                        $_SESSION['flash_msg'] = ['text' => "Error adding year.", 'type' => 'danger'];
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $_SESSION['flash_msg'] = ['text' => "Year already exists.", 'type' => 'danger'];
            }
        } else {
            $_SESSION['flash_msg'] = ['text' => "Invalid Year. Enter 2 digits (e.g. 24).", 'type' => 'danger'];
        }
    }
    elseif ($action === 'delete_year') {
        $id = $_POST['id'];
        // Note: We currently DO NOT delete the tables (students_YY) to prevent accidental data loss.
        // We only remove the reference from the master list.
        $sql = "DELETE FROM years_master WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $_SESSION['flash_msg'] = ['text' => "Year removed from list (Tables retained).", 'type' => 'warning'];
            mysqli_stmt_close($stmt);
        }
    }

    // ==========================
    // 3. SECTION ACTIONS
    // ==========================
    elseif ($action === 'add_section') {
        $dept_id = $_POST['dept_id'];
        $year_id = $_POST['year_id'];
        $raw_sections = trim($_POST['section_name']);

        if (!empty($dept_id) && !empty($year_id) && !empty($raw_sections)) {
            
            $sections_array = explode(',', $raw_sections);
            $success_count = 0;
            $error_count = 0;

            $sql = "INSERT INTO sections_master (dept_id, year_id, section_name) VALUES (?, ?, ?)";
            
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "iis", $dept_id, $year_id, $sec_name);

                foreach ($sections_array as $val) {
                    $sec_name = trim($val);
                    if (!empty($sec_name)) {
                        if (mysqli_stmt_execute($stmt)) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    }
                }
                mysqli_stmt_close($stmt);
            }

            if ($success_count > 0 && $error_count == 0) {
                $_SESSION['flash_msg'] = ['text' => "$success_count section(s) linked successfully.", 'type' => 'success'];
            } elseif ($success_count > 0 && $error_count > 0) {
                $_SESSION['flash_msg'] = ['text' => "$success_count added, $error_count failed/duplicate.", 'type' => 'warning'];
            } else {
                $_SESSION['flash_msg'] = ['text' => "No sections added. They might already exist.", 'type' => 'danger'];
            }

        } else {
            $_SESSION['flash_msg'] = ['text' => "Please select Dept, Year and enter Section Name(s).", 'type' => 'warning'];
        }
    }
    elseif ($action === 'delete_section') {
        $id = $_POST['id'];
        $sql = "DELETE FROM sections_master WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);
            $_SESSION['flash_msg'] = ['text' => "Section deleted.", 'type' => 'warning'];
            mysqli_stmt_close($stmt);
        }
    }

    // Set active tab for redirect
    $tab = 'departments';
    if (strpos($action, 'year') !== false) $tab = 'years';
    if (strpos($action, 'section') !== false) $tab = 'sections';
    $_SESSION['active_tab'] = $tab;

    header("location: departments.php");
    exit();
}

// --- DATA FETCHING (UPDATED FOR MULTIPLE TABLES) ---

// 0. Fetch All Years first (Needed for loops)
$all_years = [];
$sql_y = "SELECT * FROM years_master ORDER BY year_val DESC";
if ($res_y = mysqli_query($link, $sql_y)) {
    while ($row = mysqli_fetch_assoc($res_y)) {
        $all_years[] = $row['year_val'];
    }
}

// 1. Departments (With Search & Total Count AGGREGATED across tables)
$dept_list = [];
$search_dept = isset($_GET['search_dept']) ? trim($_GET['search_dept']) : '';

$sql = "SELECT m.id, m.dept_name, m.dept_initials FROM departments_master m";
if (!empty($search_dept)) {
    $sql .= " WHERE m.dept_name LIKE ? OR m.dept_initials LIKE ?";
}
$sql .= " ORDER BY m.dept_name ASC";

if ($stmt = mysqli_prepare($link, $sql)) {
    if (!empty($search_dept)) {
        $param_search = "%" . $search_dept . "%";
        mysqli_stmt_bind_param($stmt, "ss", $param_search, $param_search);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Calculate total count across all year tables
        $total_students = 0;
        foreach ($all_years as $y_val) {
            $table = "students_" . $y_val;
            // Check existence to avoid error
            $chk = mysqli_query($link, "SHOW TABLES LIKE '$table'");
            if (mysqli_num_rows($chk) > 0) {
                // Count students in this year table for this dept
                $c_sql = "SELECT COUNT(*) as c FROM `$table` WHERE department = '" . mysqli_real_escape_string($link, $row['dept_name']) . "' AND is_deleted = 0";
                if ($c_res = mysqli_query($link, $c_sql)) {
                    $c_row = mysqli_fetch_assoc($c_res);
                    $total_students += $c_row['c'];
                }
            }
        }
        $row['student_count'] = $total_students;
        $dept_list[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// 2. Years (With Total Count from specific table)
$year_list_data = [];
$sql_years = "SELECT y.id, y.year_val FROM years_master y ORDER BY y.year_val DESC";
if ($result = mysqli_query($link, $sql_years)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $y_val = $row['year_val'];
        $table = "students_" . $y_val;
        $count = 0;
        
        $chk = mysqli_query($link, "SHOW TABLES LIKE '$table'");
        if (mysqli_num_rows($chk) > 0) {
            $c_sql = "SELECT COUNT(*) as c FROM `$table` WHERE is_deleted = 0";
            if ($c_res = mysqli_query($link, $c_sql)) {
                $c_row = mysqli_fetch_assoc($c_res);
                $count = $c_row['c'];
            }
        }
        $row['total_students'] = $count;
        $year_list_data[] = $row;
    }
}

// 3. Sections (With Count per Section - Query specific year table)
$sec_list = [];
$sql_secs = "SELECT s.id, s.section_name, d.dept_name, d.dept_initials, y.year_val
             FROM sections_master s
             JOIN departments_master d ON s.dept_id = d.id
             JOIN years_master y ON s.year_id = y.id
             ORDER BY d.dept_name ASC, y.year_val DESC, s.section_name ASC";

if ($result = mysqli_query($link, $sql_secs)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $y_val = $row['year_val'];
        $table = "students_" . $y_val;
        $count = 0;

        $chk = mysqli_query($link, "SHOW TABLES LIKE '$table'");
        if (mysqli_num_rows($chk) > 0) {
            $c_sql = "SELECT COUNT(*) as c FROM `$table` 
                      WHERE department = '" . mysqli_real_escape_string($link, $row['dept_name']) . "' 
                      AND section = '" . mysqli_real_escape_string($link, $row['section_name']) . "' 
                      AND is_deleted = 0";
            if ($c_res = mysqli_query($link, $c_sql)) {
                $c_row = mysqli_fetch_assoc($c_res);
                $count = $c_row['c'];
            }
        }
        $row['student_count'] = $count;
        $sec_list[] = $row;
    }
}

mysqli_close($link);

// Active Tab Logic
$active_tab = isset($_SESSION['active_tab']) ? $_SESSION['active_tab'] : 'departments';
if (!empty($search_dept)) {
    $active_tab = 'departments';
}
unset($_SESSION['active_tab']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Departments | Birthday System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
    /* =====================
       GLOBAL BACKGROUND & LOADER
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

    .spinner-container {
        position: relative; width: 80px; height: 80px;
        display: flex; align-items: center; justify-content: center;
    }

    .spinner {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        border: 8px solid #e5e7eb; border-top: 8px solid #8e24aa; /* Purple Accent */
        border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
    }

    .loader-logo { width: 45px; height: auto; z-index: 1; }

    @keyframes spin { 100% { transform: rotate(360deg); } }

    /* =====================
       GLASS CONTAINER
       ===================== */
    .wrapper {
        margin: 60px auto; padding: 45px 40px; max-width: 1200px;
        position: relative;
        background: rgba(255, 255, 255, 1.0); 
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        border-radius: 20px;
        box-shadow: 0 20px 45px rgba(0,0,0,0.35);
        border: 1px solid rgba(255, 255, 255, 0.5);
    }

    /* HEADER */
    .page-header { text-align: center; margin-bottom: 30px; }
    .page-header h2 {
        font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #1f2937;
    }
    .page-header p { color: #4b5563; font-weight: 500; }

    /* TABS Styling */
    .nav-tabs { border-bottom: none; margin-bottom: 25px; justify-content: center; gap: 10px; }
    .nav-link { 
        border-radius: 50px !important; 
        padding: 10px 25px; font-weight: 600; color: #4b5563;
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(255,255,255,0.5);
        transition: all 0.3s;
    }
    .nav-link:hover { transform: translateY(-2px); background: #fff; }
    .nav-link.active {
        background: linear-gradient(135deg, #8e24aa, #7b1fa2) !important;
        color: white !important;
        box-shadow: 0 4px 15px rgba(142, 36, 170, 0.4);
        border: none;
    }

    /* CARDS & FORMS inside tabs */
    .content-box {
        background: #ffffff;
        border-radius: 18px;
        padding: 30px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }

    .add-form {
        background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 30px;
        border: 1px solid #e9ecef;
    }
    
    /* TABLES */
    .table-responsive { overflow-x: auto; }
    .table thead th {
        background-color: #8e24aa; /* Purple header */
        color: white; border: none;
        text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;
        vertical-align: middle;
    }
    .table td { vertical-align: middle; }
    
    /* BUTTONS */
    .btn-custom {
        border-radius: 50px; padding: 10px 25px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        transition: all 0.3s;
    }
    .btn-add { background: linear-gradient(135deg, #8e24aa, #7b1fa2); color: white; border: none; }
    .btn-add:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(142,36,170,0.3); color: white; }
    
    .btn-back {
        background: white; border: 2px solid #6c757d; color: #6c757d; border-radius: 50px; padding: 8px 25px; font-weight: 600;
        text-decoration: none; display: inline-block; transition: all 0.3s;
    }
    .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }

    .btn-icon {
        width: 32px; height: 32px; border-radius: 50%; display: inline-flex;
        align-items: center; justify-content: center; border: none; color: white;
        transition: all 0.2s; margin: 0 2px;
    }
    .btn-edit { background: #ffc107; color: #212529; }
    .btn-delete { background: #dc3545; }
    .btn-icon:hover { transform: scale(1.1); }

    .initials-badge { background: #f3e5f5; color: #8e24aa; font-weight: 700; padding: 5px 10px; border-radius: 8px; }

    /* Select2 Fixes */
    .select2-container .select2-selection--single { height: calc(1.5em + .75rem + 2px) !important; }
    .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height: calc(1.5em + .75rem); }
    
    @media (max-width: 768px) {
        .wrapper { margin: 20px auto; padding: 20px; }
        .nav-link { padding: 8px 15px; font-size: 0.9rem; }
    }
    </style>
</head>
<body>
    
    <div id="loader">
        <div class="spinner-container">
            <div class="spinner"></div>
            <img src="BWU_logo.png" alt="Loading..." class="loader-logo">
        </div>
    </div>

    <div class="container">
        <div class="wrapper">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="dashboard.php" class="btn-back"><i class="fas fa-arrow-left mr-2"></i>Dashboard</a>
                <div></div>
            </div>

            <div class="page-header">
                <h2><i class="fas fa-database text-purple mr-2" style="color: #8e24aa;"></i>Master Data</h2>
                <p>Manage Departments, Admission Years, and Sections</p>
            </div>

            <?php if(!empty($msg)): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm rounded-pill px-4" role="alert">
                    <?php echo $msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-tabs" id="masterTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo ($active_tab == 'departments') ? 'active' : ''; ?>" id="dept-tab" data-toggle="tab" href="#departments" role="tab">
                        <i class="fas fa-building mr-2"></i>Departments
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($active_tab == 'years') ? 'active' : ''; ?>" id="year-tab" data-toggle="tab" href="#years" role="tab">
                        <i class="fas fa-calendar-alt mr-2"></i>Years
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo ($active_tab == 'sections') ? 'active' : ''; ?>" id="sec-tab" data-toggle="tab" href="#sections" role="tab">
                        <i class="fas fa-layer-group mr-2"></i>Sections
                    </a>
                </li>
            </ul>

            <div class="content-box">
                <div class="tab-content" id="masterTabsContent">
                    
                    <div class="tab-pane fade <?php echo ($active_tab == 'departments') ? 'show active' : ''; ?>" id="departments" role="tabpanel">
                        
                        <div class="add-form">
                            <h5 class="mb-3 text-muted">Add New Department</h5>
                            <form action="departments.php" method="POST" class="form-row align-items-end">
                                <input type="hidden" name="action" value="add_dept">
                                <div class="col-md-5 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Department Name</label>
                                    <input type="text" name="dept_name" class="form-control rounded-pill" placeholder="e.g. Computer Science" required>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Initials</label>
                                    <input type="text" name="dept_initials" class="form-control rounded-pill" placeholder="e.g. CSE" required>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <button type="submit" class="btn btn-custom btn-add btn-block"><i class="fas fa-plus mr-1"></i> Create</button>
                                </div>
                            </form>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                            <h5 class="text-dark font-weight-bold m-0">Existing Departments</h5>
                            <form action="departments.php" method="GET" class="form-inline">
                                <div class="input-group">
                                    <input type="text" name="search_dept" class="form-control rounded-pill-left" placeholder="Search keyword..." value="<?php echo htmlspecialchars($search_dept); ?>" style="border-radius: 50px 0 0 50px;">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="submit" style="border-radius: 0 50px 50px 0;"><i class="fas fa-search"></i></button>
                                    </div>
                                    <?php if(!empty($search_dept)): ?>
                                        <a href="departments.php" class="btn btn-sm btn-link ml-2 text-danger"><i class="fas fa-times"></i> Clear</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Initials</th>
                                        <th>Total (All Years)</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($dept_list)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-4">No departments found matching your search.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dept_list as $i => $d): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($d['dept_name']); ?></td>
                                            <td><span class="initials-badge"><?php echo htmlspecialchars($d['dept_initials']); ?></span></td>
                                            <td><span class="badge badge-dark"><?php echo $d['student_count']; ?></span></td>
                                            <td class="text-right">
                                                <button class="btn-icon btn-edit" onclick="editDept('<?php echo $d['id']; ?>','<?php echo htmlspecialchars($d['dept_name']); ?>','<?php echo htmlspecialchars($d['dept_initials']); ?>')"><i class="fas fa-pencil-alt"></i></button>
                                                <button class="btn-icon btn-delete" onclick="delDept('<?php echo $d['id']; ?>','<?php echo htmlspecialchars($d['dept_name']); ?>')"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo ($active_tab == 'years') ? 'show active' : ''; ?>" id="years" role="tabpanel">
                        <div class="add-form">
                            <h5 class="mb-3 text-muted">Add Admission Year</h5>
                            <form action="departments.php" method="POST" class="form-row align-items-end">
                                <input type="hidden" name="action" value="add_year">
                                <div class="col-md-8 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Year (2 Digits)</label>
                                    <input type="text" name="year_val" class="form-control rounded-pill" placeholder="e.g. 24, 25" pattern="\d{2}" title="Please enter exactly 2 digits" required maxlength="2">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <button type="submit" class="btn btn-custom btn-add btn-block"><i class="fas fa-plus mr-1"></i> Add Year</button>
                                </div>
                            </form>
                            <small class="text-muted"><i class="fas fa-info-circle mr-1"></i> Adding a year will automatically create the database tables (e.g., <code>students_24</code>).</small>
                        </div>

                        <div class="row">
                            <div class="col-md-8 mx-auto">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Year (YY)</th>
                                            <th>Total Students</th>
                                            <th class="text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($year_list_data as $y): ?>
                                        <tr>
                                            <td class="font-weight-bold">20<?php echo htmlspecialchars($y['year_val']); ?></td>
                                            <td>
                                                <span class="badge badge-info p-2">
                                                    <?php echo $y['total_students']; ?> Students
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <form action="departments.php" method="POST" style="display:inline;" onsubmit="return confirm('Delete this year reference? (Tables will NOT be deleted)');">
                                                    <input type="hidden" name="action" value="delete_year">
                                                    <input type="hidden" name="id" value="<?php echo $y['id']; ?>">
                                                    <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo ($active_tab == 'sections') ? 'show active' : ''; ?>" id="sections" role="tabpanel">
                        <div class="add-form">
                            <h5 class="mb-3 text-muted">CREATE & Link Sections to Department</h5>
                            <form action="departments.php" method="POST" class="form-row align-items-end">
                                <input type="hidden" name="action" value="add_section">
                                
                                <div class="col-md-4 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Department</label>
                                    <select name="dept_id" class="form-control select2-dept" required>
                                        <option value="">Select Dept</option>
                                        <?php foreach ($dept_list as $d): ?>
                                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Year</label>
                                    <select name="year_id" class="form-control rounded-pill" required>
                                        <option value="">Select Year</option>
                                        <?php foreach ($year_list_data as $y): ?>
                                            <option value="<?php echo $y['id']; ?>">20<?php echo htmlspecialchars($y['year_val']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-3 mb-2">
                                    <label class="font-weight-bold small text-uppercase text-muted">Section(s)</label>
                                    <input type="text" name="section_name" class="form-control rounded-pill" placeholder="e.g. A, B, C" required>
                                </div>

                                <div class="col-md-2 mb-2">
                                    <button type="submit" class="btn btn-custom btn-add btn-block"><i class="fas fa-link mr-1"></i>CREATE & Link</button>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Year</th>
                                        <th>Section</th>
                                        <th>Student Count</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sec_list as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($s['dept_name']); ?></td>
                                        <td><span class="badge badge-light border">20<?php echo htmlspecialchars($s['year_val']); ?></span></td>
                                        <td><span class="badge badge-success p-2 rounded-circle" style="width:30px; height:30px; line-height:15px;"><?php echo htmlspecialchars($s['section_name']); ?></span></td>
                                        <td>
                                            <span class="font-weight-bold text-dark">
                                                <?php echo $s['student_count']; ?>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <form action="departments.php" method="POST" style="display:inline;" onsubmit="return confirm('Unlink this section?');">
                                                <input type="hidden" name="action" value="delete_section">
                                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                <button type="submit" class="btn-icon btn-delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($sec_list)): ?>
                                        <tr><td colspan="5" class="text-center text-muted">No linked sections found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="renameModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form action="departments.php" method="POST">
                    <div class="modal-header bg-warning border-0">
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-edit mr-2"></i>Edit Department</h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="rename_dept">
                        <input type="hidden" name="dept_id" id="edit-id">
                        <input type="hidden" name="old_name" id="edit-old-name">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="new_name" id="edit-name" class="form-control rounded-pill" required>
                        </div>
                        <div class="form-group">
                            <label>Initials</label>
                            <input type="text" name="new_initials" id="edit-initials" class="form-control rounded-pill" required>
                        </div>
                        <small class="text-muted">This will update references in all Year tables.</small>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary rounded-pill" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning rounded-pill font-weight-bold px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form action="departments.php" method="POST">
                    <div class="modal-header bg-danger text-white border-0">
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Delete Department</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body text-center py-4">
                        <input type="hidden" name="action" value="delete_dept">
                        <input type="hidden" name="dept_id" id="del-id">
                        <input type="hidden" name="delete_name" id="del-name">
                        <i class="fas fa-trash-alt fa-3x text-danger mb-3 opacity-50"></i>
                        <p class="lead">Are you sure you want to delete <strong id="display-del-name"></strong>?</p>
                        <small class="text-muted">This will remove the department reference from all associated students in ALL years.</small>
                    </div>
                    <div class="modal-footer border-0 justify-content-center">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill px-4 font-weight-bold">Yes, Delete It</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        window.addEventListener('load', () => {
            const loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(() => { loader.style.display = 'none'; }, 400);
        });

        function editDept(id, name, initials) {
            $('#edit-id').val(id); $('#edit-old-name').val(name);
            $('#edit-name').val(name); $('#edit-initials').val(initials);
            $('#renameModal').modal('show');
        }
        function delDept(id, name) {
            $('#del-id').val(id); $('#del-name').val(name);
            $('#display-del-name').text(name);
            $('#deleteModal').modal('show');
        }

        $(document).ready(function() {
            $('.select2-dept').select2({
                theme: 'bootstrap4',
                width: '100%',
                placeholder: "Select Dept"
            });
        });
    </script>
</body>
</html>