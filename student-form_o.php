<?php
// birthday_system01/student-form.php
session_start();
require_once "config.php";
require_once "countries.php"; 

$student_custom_id = $student_name = $student_mail_id = $date_of_birth = $section = $department = $student_phone = "";
$country_code = "+91"; 
$errors = [];
$success_msg = "";

// Helper Function to determine table name
if (!function_exists('get_target_table_name')) {
    function get_target_table_name($id) {
        $parts = explode('/', $id);
        if (count($parts) >= 3 && is_numeric($parts[2])) {
            return "students_" . $parts[2];
        }
        return false;
    }
}

// PRG: Check for Flash Messages
if (isset($_SESSION['submission_flash'])) {
    $flash = $_SESSION['submission_flash'];
    if (isset($flash['success'])) {
        $success_msg = $flash['success'];
    } else {
        $student_custom_id = $flash['inputs']['student_custom_id'] ?? '';
        $student_name = $flash['inputs']['student_name'] ?? '';
        $student_mail_id = $flash['inputs']['student_mail_id'] ?? '';
        $date_of_birth = $flash['inputs']['date_of_birth'] ?? '';
        $section = $flash['inputs']['section'] ?? '';
        $department = $flash['inputs']['department'] ?? '';
        $country_code = $flash['inputs']['country_code'] ?? '+91';
        $student_phone = $flash['inputs']['student_phone'] ?? '';
        $errors = $flash['errors'] ?? [];
    }
    unset($_SESSION['submission_flash']);
}

// Fetch Departments
$dept_options = [];
$dept_sql = "SELECT dept_name, dept_initials FROM departments_master ORDER BY dept_name ASC";
if($result = mysqli_query($link, $dept_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $dept_options[] = $row;
    }
}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_student_custom_id = strtoupper(trim($_POST["student_custom_id"]));
    $input_student_name = trim($_POST["student_name"]);
    $input_student_mail_id = trim($_POST["student_mail_id"]);
    $input_date_of_birth = trim($_POST["date_of_birth"]);
    $input_section = trim($_POST["section"]);
    $input_department = trim($_POST["department"]);
    $input_country_code = $_POST["country_code"];
    $input_student_phone = trim($_POST["student_phone"]);
    
    // Check if user chose to skip verification
    $skip_verification = isset($_POST['skip_verification']);
    $email_verified_status = 0; // Default to unverified

    $target_table = get_target_table_name($input_student_custom_id);

    // --- 1. BASIC VALIDATION ---
    if(empty($input_student_custom_id)){
        $errors['student_custom_id'] = "Please enter your Student ID.";
    } elseif ($target_table === false) {
        $errors['student_custom_id'] = "Invalid Student ID format. Cannot detect Year.";
    } else {
        // CHECK 0: Does the Year Table exist?
        $check_table_sql = "SHOW TABLES LIKE '$target_table'";
        $table_exists = mysqli_query($link, $check_table_sql);
        
        if(mysqli_num_rows($table_exists) == 0) {
            $errors['student_custom_id'] = "Registration for this Batch Year is not active in the system.";
        } else {
            // CHECK 1: Is it already an active student?
            $sql = "SELECT id FROM `$target_table` WHERE student_custom_id = ?";
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "s", $input_student_custom_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) > 0){
                    $errors['student_custom_id'] = "This Student ID is already registered.";
                }
                mysqli_stmt_close($stmt);
            }
        }
        
        // CHECK 2: Is it already pending?
        if(empty($errors['student_custom_id'])) {
            $sql_pending = "SELECT id FROM student_submissions WHERE student_custom_id = ?";
            if($stmt = mysqli_prepare($link, $sql_pending)){
                mysqli_stmt_bind_param($stmt, "s", $input_student_custom_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) > 0){
                    $errors['student_custom_id'] = "Application for this ID is already pending approval.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    if(empty($input_student_name)) $errors['student_name'] = "Please enter your full name.";
    
    if(empty($input_student_mail_id)) $errors['student_mail_id'] = "Please enter an email.";
    elseif(!filter_var($input_student_mail_id, FILTER_VALIDATE_EMAIL)) $errors['student_mail_id'] = "Invalid email format.";

    if(empty($input_student_phone)) $errors['student_phone'] = "Please enter a phone number.";
    elseif(!ctype_digit($input_student_phone)) $errors['student_phone'] = "Digits only.";
    elseif(strlen($input_student_phone) < 7 || strlen($input_student_phone) > 15) $errors['student_phone'] = "Invalid length.";

    // --- DATE OF BIRTH VALIDATION ---
    if(empty($input_date_of_birth)) {
        $errors['date_of_birth'] = "Required.";
    } elseif ($input_date_of_birth > date('Y-m-d')) {
        $errors['date_of_birth'] = "Date of birth cannot be in the future.";
    }

    if(empty($input_department)) $errors['department'] = "Required.";
    if(empty($input_section)) $errors['section'] = "Required.";

    // --- 2. ACCESS CONTROL & EMAIL VERIFICATION CHECK ---
    if(empty($errors)) {
        
        // Handle Verification Logic
        if ($skip_verification) {
            $email_verified_status = 0; // User skipped
        } else {
            // Check if Email was verified via OTP
            if(!isset($_SESSION['is_email_verified']) || $_SESSION['is_email_verified'] !== true || $_SESSION['otp_email'] !== $input_student_mail_id) {
                $errors['student_mail_id'] = "Email Verification Required. Verify or check 'Submit without verification'.";
            } else {
                $email_verified_status = 1; // User verified
            }
        }
        
        // Check Registration Status (Access Control)
        $parts = explode('/', $input_student_custom_id);
        $year_val = (count($parts) >= 3) ? $parts[2] : '';

        $check_sql = "SELECT s.id 
                      FROM sections_master s
                      JOIN departments_master d ON s.dept_id = d.id
                      JOIN years_master y ON s.year_id = y.id
                      WHERE d.dept_name = ? 
                        AND y.year_val = ? 
                        AND s.section_name = ? 
                        AND s.reg_open = 1";
        
        if($stmt_chk = mysqli_prepare($link, $check_sql)){
            mysqli_stmt_bind_param($stmt_chk, "sss", $input_department, $year_val, $input_section);
            mysqli_stmt_execute($stmt_chk);
            mysqli_stmt_store_result($stmt_chk);
            
            if(mysqli_stmt_num_rows($stmt_chk) == 0){
                $errors['section'] = "Registration is closed for this Dept/Year/Section.";
            }
            mysqli_stmt_close($stmt_chk);
        }
    }

    // --- 3. FINAL INSERTION ---
    if(empty($errors)){
        // 1. Insert into Submissions Table
        // IMPORTANT: We insert $email_verified_status (0 or 1)
        $sql = "INSERT INTO student_submissions (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssssssi", 
                $input_student_custom_id, $input_student_name, $input_student_mail_id, 
                $input_country_code, $input_student_phone, $input_date_of_birth, 
                $input_section, $input_department, $email_verified_status
            );

            if(mysqli_stmt_execute($stmt)){
                $new_submission_id = mysqli_insert_id($link);
                $auto_approved = false;
                
                // --- AUTO ACCEPT CHECK (Only if Verified) ---
                if ($email_verified_status == 1) {
                    $set_sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'auto_accept_verified'";
                    if($set_res = mysqli_query($link, $set_sql)){
                        $set_row = mysqli_fetch_assoc($set_res);
                        
                        if($set_row && $set_row['setting_value'] == '1') {
                            // Move to DYNAMIC YEAR Table
                            $move_sql = "INSERT INTO `$target_table` (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                            
                            if($stmt_move = mysqli_prepare($link, $move_sql)) {
                                mysqli_stmt_bind_param($stmt_move, "ssssssss", 
                                    $input_student_custom_id, $input_student_name, $input_student_mail_id, 
                                    $input_country_code, $input_student_phone, $input_date_of_birth, 
                                    $input_section, $input_department
                                );
                                if(mysqli_stmt_execute($stmt_move)){
                                    // Delete from submissions
                                    mysqli_query($link, "DELETE FROM student_submissions WHERE id = $new_submission_id");
                                    $auto_approved = true;
                                }
                                mysqli_stmt_close($stmt_move);
                            }
                        }
                    }
                }

                // Clear OTP session data
                unset($_SESSION['otp'], $_SESSION['otp_email'], $_SESSION['otp_expiry'], $_SESSION['is_email_verified']);
                
                if ($auto_approved) {
                    $final_msg = "Details submitted and APPROVED automatically!";
                } elseif ($email_verified_status == 0) {
                    $final_msg = "Submitted without verification. Pending Admin review.";
                } else {
                    $final_msg = "Details submitted successfully! Waiting for Admin verification.";
                }

                $_SESSION['submission_flash'] = ['success' => $final_msg];
            } else{
                $errors['db_error'] = "Something went wrong. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    } 

    if (!empty($errors)) {
        $_SESSION['submission_flash'] = [
            'inputs' => [
                'student_custom_id' => $input_student_custom_id,
                'student_name' => $input_student_name,
                'student_mail_id' => $input_student_mail_id,
                'date_of_birth' => $input_date_of_birth,
                'section' => $input_section,
                'department' => $input_department,
                'country_code' => $input_country_code,
                'student_phone' => $input_student_phone
            ],
            'errors' => $errors
        ];
    }
    
    header("location: student-form.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Basic Details - Brainware University</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet">

    <style>
        body {
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
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
            border: 8px solid #f3f3f3; border-top: 8px solid #28a745; 
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        .wrapper {
            width: 100%; max-width: 800px; margin: 60px auto; padding: 40px;
            background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(10px);
            border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.8); position: relative;
        }
        .header-logo { display: block; margin: 0 auto 20px; width: 80px; }
        .page-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #f1f1f1; padding-bottom: 20px; }
        .page-header h2 { font-weight: 800; color: #1a1a1a; text-transform: uppercase; letter-spacing: 1px; }

        .section-title {
            color: #28a745; font-size: 0.95rem; font-weight: 700; text-transform: uppercase;
            margin-bottom: 15px; margin-top: 10px; border-left: 4px solid #28a745; padding-left: 10px;
        }
        label { font-weight: 600; color: #495057; font-size: 0.9rem; }
        .form-control { border-radius: 8px; height: auto; padding: 10px 15px; font-size: 0.95rem; border: 1px solid #ced4da; }
        .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25); border-color: #28a745; }
        .input-group-text { background-color: #f8f9fa; border-radius: 8px 0 0 8px; border-color: #ced4da; }

        .select2-container .select2-selection--single { height: 46px !important; border: 1px solid #ced4da !important; border-radius: 8px !important; }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { padding-left: 12px; line-height: 32px !important; }
        .country-select-group .select2-container { width: 140px !important; }

        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white; border: none; border-radius: 50px; padding: 12px 35px;
            font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3); transition: all 0.3s; width: 100%;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); color: white; }
        .btn-submit:disabled { background: #6c757d; cursor: not-allowed; box-shadow: none; transform: none; }

        .otp-box { background: #fdfdfd; border: 1px dashed #28a745; padding: 15px; border-radius: 8px; margin-top: 10px; display: none; }
        .verified-badge { color: #28a745; font-weight: bold; display: none; margin-left: 10px; align-self: center; }

        /* Custom Checkbox for skipping */
        .skip-verification-box {
            background: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 8px; margin-top: 10px;
        }
        .custom-control-label { cursor: pointer; color: #856404; font-size: 0.9rem; font-weight: 600; }

        @media (max-width: 768px) { .wrapper { padding: 25px; margin: 30px auto; } }
    </style>
</head>
<body>

    <div id="loader"><div class="spinner-container"><div class="spinner"></div><img src="BWU_logo.png" class="loader-logo"></div></div>

    <div class="container">
        <div class="wrapper">
            <img src="BWU_logo.png" alt="Logo" class="header-logo">
            <div class="page-header">
                <h2>Student Basic Details Confirmation</h2>
                <p class="text-muted mb-0">Please enter your details. They will be verified by an admin.</p>
            </div>

            <?php if(!empty($success_msg)): ?>
                <div class="alert alert-success shadow-sm border-0 rounded-lg text-center py-4">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h4 class="alert-heading">Received!</h4>
                    <p class="mb-0"><?php echo $success_msg; ?></p>
                    <hr>
                    <a href="student-form.php" class="btn btn-outline-success btn-sm rounded-pill px-4">Submit Another</a>
                </div>
            <?php else: ?>
                <?php if(!empty($errors)): ?>
                    <div class="alert alert-danger shadow-sm mb-4 border-0 rounded-lg">
                        <ul class="mb-0 pl-3 small">
                            <?php foreach($errors as $err): ?><li><?php echo $err; ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars(basename($_SERVER['REQUEST_URI'])); ?>" method="post" id="createForm">
                    
                    <h5 class="section-title">Personal Information</h5>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Student ID <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-fingerprint"></i></span></div>
                                <input type="text" name="student_custom_id" id="student_id_input" class="form-control <?php echo isset($errors['student_custom_id']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($student_custom_id); ?>" placeholder="e.g. BWU/CSE/24/001" autocomplete="off" required style="text-transform: uppercase;">
                                <div class="invalid-feedback"><?php echo $errors['student_custom_id'] ?? ''; ?></div>
                            </div>
                            
                            <small id="checking-status" class="text-primary font-weight-bold mt-1" style="display:none; font-size: 0.8rem;">
                                <i class="fas fa-spinner fa-spin mr-1"></i> Checking...
                            </small>
                            <small id="id-error-msg" class="text-danger font-weight-bold mt-1" style="display:none; font-size: 0.8rem;">
                                <i class="fas fa-times-circle mr-1"></i> Already registered or pending.
                            </small>
                            <small id="reg-closed-msg" class="text-danger font-weight-bold mt-1" style="display:none; font-size: 0.8rem;">
                                <i class="fas fa-lock mr-1"></i> Registration is closed for this Department/Batch.
                            </small>
                        </div>

                        <div class="form-group col-md-6">
                            <label>Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-user"></i></span></div>
                                <input type="text" name="student_name" class="form-control <?php echo isset($errors['student_name']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($student_name); ?>" placeholder="e.g. John Doe" required>
                                <div class="invalid-feedback"><?php echo $errors['student_name'] ?? ''; ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Phone Number <span class="text-danger">*</span></label>
                            <div class="input-group country-select-group">
                                <div class="input-group-prepend">
                                    <select name="country_code" class="form-control select2-country">
                                        <?php foreach($country_list as $code => $label): ?>
                                            <option value="<?php echo $code; ?>" <?php echo ($country_code == $code) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="text" name="student_phone" id="student_phone" class="form-control <?php echo isset($errors['student_phone']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($student_phone); ?>" maxlength="15" placeholder="9876543210" required>
                                <div class="invalid-feedback"><?php echo $errors['student_phone'] ?? ''; ?></div>
                            </div>
                        </div>

                        <div class="form-group col-md-6">
                            <label>Email Address <span class="badge badge-secondary ml-1">Auto-Generated</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                                <input type="email" name="student_mail_id" id="student_mail_id" class="form-control <?php echo isset($errors['student_mail_id']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($student_mail_id); ?>" placeholder="Auto-generated email" readonly required style="background-color: #e9ecef;">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-success" type="button" id="btnSendOtp" disabled>Verify</button>
                                </div>
                                <div class="verified-badge" id="verifiedBadge"><i class="fas fa-check-circle"></i> Verified</div>
                                <div class="invalid-feedback"><?php echo $errors['student_mail_id'] ?? ''; ?></div>
                            </div>
                            
                            <div class="skip-verification-box">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="skipOtpCheck" name="skip_verification" value="1">
                                    <label class="custom-control-label" for="skipOtpCheck">Unable to verify email? Submit without verification.</label>
                                </div>
                            </div>

                            <div class="otp-box" id="otpSection">
                                <label class="small text-muted mb-1">Enter the 6-digit OTP sent to your email:</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="otpInput" class="form-control" placeholder="123456" maxlength="6">
                                    <div class="input-group-append">
                                        <button class="btn btn-success" type="button" id="btnVerifyOtp">Confirm OTP</button>
                                    </div>
                                </div>
                                <small id="otp-msg" class="text-danger mt-1 d-block"></small>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Date of Birth <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar-alt"></i></span></div>
                                <input type="date" name="date_of_birth" class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($date_of_birth); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                <div class="invalid-feedback"><?php echo $errors['date_of_birth'] ?? ''; ?></div>
                            </div>
                        </div>
                    </div>

                    <h5 class="section-title mt-4">Academic Details</h5>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Department <span class="text-danger">*</span></label>
                            <select name="department" id="deptSelect" class="form-control <?php echo isset($errors['department']) ? 'is-invalid' : ''; ?>" required>
                                <option value="">Select Department</option>
                                <?php foreach($dept_options as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt['dept_name']); ?>" data-initials="<?php echo htmlspecialchars($opt['dept_initials']); ?>" <?php echo ($department == $opt['dept_name']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($opt['dept_name']) . " (" . htmlspecialchars($opt['dept_initials']) . ")"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback"><?php echo $errors['department'] ?? ''; ?></div>
                        </div>

                        <div class="form-group col-md-6">
                            <label>Section / Group <span class="text-danger">*</span></label>
                            <select name="section" id="sectionSelect" class="form-control <?php echo isset($errors['section']) ? 'is-invalid' : ''; ?>" required>
                                <option value="">Select Section</option>
                            </select>
                            <div class="invalid-feedback"><?php echo $errors['section'] ?? ''; ?></div>
                        </div>
                    </div>

                    <div class="mt-5 mb-3">
                        <button type="submit" id="submitBtn" class="btn btn-submit btn-lg" disabled>
                            <i class="fas fa-paper-plane mr-2"></i> Submit for Verification
                        </button>
                        <small class="text-center d-block text-muted mt-2" id="submit-hint">Please verify your email or choose to skip to enable submission.</small>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Confirm Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Please review your details before final submission.</p>
                    <table class="table table-sm table-borderless">
                        <tr><td class="font-weight-bold text-secondary">Student ID:</td><td id="conf_id" class="font-weight-bold"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">Name:</td><td id="conf_name"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">Phone:</td><td id="conf_phone"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">Email:</td><td id="conf_email"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">DOB:</td><td id="conf_dob"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">Department:</td><td id="conf_dept"></td></tr>
                        <tr><td class="font-weight-bold text-secondary">Section:</td><td id="conf_sec"></td></tr>
                        <tr id="conf_skip_row" style="display:none;"><td colspan="2" class="text-warning font-weight-bold small pt-3"><i class="fas fa-exclamation-triangle"></i> Email not verified (Skipped)</td></tr>
                    </table>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Edit</button>
                    <button type="button" class="btn btn-success rounded-pill px-4" id="finalSubmitBtn">Confirm & Submit</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        function showLoader() { $('#loader').css({display:'flex', opacity:1}); }
        function hideLoader() { $('#loader').css({opacity:0}); setTimeout(()=>$('#loader').hide(),400); }

        window.addEventListener('load', function(){ hideLoader(); });

        $(document).ready(function(){
            $('.select2-country').select2({ theme: 'bootstrap4', width: '100%' });
            $('#deptSelect').select2({ theme: 'bootstrap4', width: '100%', placeholder: "Select Department" });
            $('#sectionSelect').select2({ theme: 'bootstrap4', width: '100%', placeholder: "Select Section" });

            $('#student_phone').on('input', function(){
                this.value = this.value.replace(/[^0-9]/g,'');
                var v = $(this).val();
                if(v.length > 0 && (v.length < 7 || v.length > 15)) $(this).addClass('is-invalid');
                else $(this).removeClass('is-invalid');
            });

            var idInput = $('#student_id_input');
            var emailInput = $('#student_mail_id');
            var deptSelect = $('#deptSelect');
            var sectionSelect = $('#sectionSelect');
            var submitBtn = $('#submitBtn');
            var btnSendOtp = $('#btnSendOtp');
            var skipCheck = $('#skipOtpCheck');
            var isEmailVerified = false;
            var currentSection = "<?php echo $section; ?>";

            function resetVerification() {
                isEmailVerified = false;
                $('#verifiedBadge').hide();
                // If skipping is enabled, button is enabled. Else disabled.
                updateSubmitButton();
                
                $('#otpSection').hide();
                $('#otpInput').val('');
                
                // If skipping, send OTP is disabled. Else enabled.
                if(skipCheck.is(':checked')) {
                    btnSendOtp.prop('disabled', true);
                } else {
                    btnSendOtp.prop('disabled', idInput.hasClass('is-invalid')); // Disable if ID error
                }
            }

            function updateSubmitButton() {
                if (isEmailVerified || skipCheck.is(':checked')) {
                    submitBtn.prop('disabled', false);
                    $('#submit-hint').hide();
                } else {
                    submitBtn.prop('disabled', true);
                    $('#submit-hint').show();
                }
            }

            // Toggle Skip Logic
            skipCheck.change(function() {
                if ($(this).is(':checked')) {
                    // Skipping Enabled: Disable OTP inputs, Enable Submit
                    btnSendOtp.prop('disabled', true);
                    $('#otpSection').slideUp();
                    $('#verifiedBadge').hide();
                } else {
                    // Skipping Disabled: Re-enable OTP if verified or fresh
                    if (isEmailVerified) $('#verifiedBadge').show();
                    else btnSendOtp.prop('disabled', false);
                }
                updateSubmitButton();
            });

            idInput.on('input', function(){
                // resetVerification() handles enabling/disabling
                isEmailVerified = false; 
                $('#verifiedBadge').hide();
                
                var p = this.selectionStart;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(p, p);

                var val = $(this).val();
                var parts = val.split('/');
                
                if(val.length > 0) emailInput.val(val.replace(/\//g,'').toLowerCase() + "@brainwareuniversity.ac.in");
                else emailInput.val('');

                if(parts.length >= 2){
                    var init = parts[1].trim().toUpperCase();
                    if(init.length > 0){
                        $('#deptSelect option').each(function(){
                            if($(this).data('initials') && $(this).data('initials').toString().toUpperCase() === init){
                                if(deptSelect.val() !== $(this).val()) deptSelect.val($(this).val()).trigger('change');
                                return false;
                            }
                        });
                    }
                }
                if(parts.length >= 3) loadSections(null);
                
                updateSubmitButton();
            });

            idInput.on('change', function(){
                var val = $(this).val();
                if(val.length > 0){
                    $('#checking-status').show(); 
                    $('#id-error-msg').hide();
                    $('#reg-closed-msg').hide();
                    
                    $.ajax({
                        url: 'check_student_id.php', 
                        type: 'POST', 
                        data: {student_id: val}, 
                        dataType: 'json',
                        success: function(resp){
                            $('#checking-status').hide();
                            if(resp.exists){
                                idInput.addClass('is-invalid'); 
                                $('#id-error-msg').slideDown(); 
                                btnSendOtp.prop('disabled', true);
                            } 
                            else if(resp.registration_closed) {
                                idInput.addClass('is-invalid');
                                $('#reg-closed-msg').slideDown();
                                btnSendOtp.prop('disabled', true);
                            } 
                            else {
                                idInput.removeClass('is-invalid').addClass('is-valid'); 
                                $('#id-error-msg').slideUp(); 
                                $('#reg-closed-msg').slideUp();
                                if(!skipCheck.is(':checked')) btnSendOtp.prop('disabled', false);
                            }
                        }
                    });
                }
            });

            btnSendOtp.click(function() {
                var email = emailInput.val();
                if (!email) { alert("Email not generated yet."); return; }

                showLoader();
                $.post('otp_handler.php', { action: 'send', email: email }, function(resp){
                    hideLoader();
                    if(resp.status === 'success') {
                        $('#otpSection').slideDown();
                        $('#otp-msg').removeClass('text-danger').addClass('text-success').text(resp.message);
                        btnSendOtp.text("Resend OTP");
                    } else {
                        alert(resp.message);
                    }
                }, 'json').fail(function(){ hideLoader(); alert('Server connection failed.'); });
            });

            $('#btnVerifyOtp').click(function() {
                var otp = $('#otpInput').val();
                var email = emailInput.val();
                if(otp.length !== 6) { alert("Please enter 6 digit OTP."); return; }

                showLoader();
                $.post('otp_handler.php', { action: 'verify', otp: otp, email: email }, function(resp){
                    hideLoader();
                    if(resp.status === 'success') {
                        isEmailVerified = true;
                        $('#otpSection').slideUp();
                        btnSendOtp.hide();
                        $('#verifiedBadge').css('display','flex');
                        skipCheck.prop('checked', false); // Uncheck skip if verified
                        updateSubmitButton();
                    } else {
                        $('#otp-msg').removeClass('text-success').addClass('text-danger').text(resp.message);
                    }
                }, 'json');
            });

            function loadSections(pre){
                var id = idInput.val().trim();
                var dept = deptSelect.val();
                var parts = id.split('/');
                var year = (parts.length >= 3) ? parts[2] : '';

                if(dept && year && year.length === 2){
                    sectionSelect.prop('disabled', true);
                    $.ajax({
                        url: 'get_sections.php', 
                        type: 'POST', 
                        data: {dept_name: dept, year_val: year}, 
                        dataType: 'json',
                        success: function(data){
                            sectionSelect.empty().append('<option value="">Select Section</option>');
                            if(data.length > 0){
                                $.each(data, function(i, v){ 
                                    sectionSelect.append('<option value="'+v+'" '+(v===pre?'selected':'')+'>'+v+'</option>'); 
                                });
                            } else { 
                                sectionSelect.append('<option value="">No sections found</option>'); 
                            }
                            sectionSelect.prop('disabled', false).trigger('change');
                        }
                    });
                }
            }

            deptSelect.on('change', function(){ loadSections(null); });
            if(currentSection !== "") loadSections(currentSection);

            $('#createForm').on('submit', function(e) {
                // If not verified AND not skipping -> Block
                if(!isEmailVerified && !skipCheck.is(':checked')) {
                    e.preventDefault();
                    alert("Please verify your email or check the box to skip verification.");
                    return;
                }

                if (!$(this).data('confirmed')) {
                    if (this.checkValidity() === false) { e.stopPropagation(); return; }
                    e.preventDefault();
                    
                    $('#conf_id').text($('#student_id_input').val());
                    $('#conf_name').text($('input[name="student_name"]').val());
                    $('#conf_phone').text($('.select2-country').val() + " " + $('#student_phone').val());
                    $('#conf_email').text($('#student_mail_id').val());
                    $('#conf_dob').text($('input[name="date_of_birth"]').val());
                    $('#conf_dept').text($('#deptSelect option:selected').text());
                    $('#conf_sec').text($('#sectionSelect').val());

                    if (skipCheck.is(':checked') && !isEmailVerified) {
                        $('#conf_skip_row').show();
                    } else {
                        $('#conf_skip_row').hide();
                    }

                    $('#confirmModal').modal('show');
                }
            });

            $('#finalSubmitBtn').click(function() {
                $('#createForm').data('confirmed', true);
                $('#createForm').submit();
            });
        });
    </script>
</body>
</html>