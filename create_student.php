<?php
// birthday_system01/create_student.php

// 1. Initialize session
session_start();

// 2. Security Check
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// 3. Include config and helper files
require_once "config.php";
require_once "countries.php"; // Contains $country_list array

// 4. Initialize Variables
$student_custom_id = $student_name = $date_of_birth = $section = $department = $student_phone = "";
$email_type = "university"; // Default
$personal_email = "";
$country_code = "+91"; 
$errors = [];

// 5. PRG: Check for Session Errors/Inputs from a previous failed POST
if (isset($_SESSION['create_flash'])) {
    $flash = $_SESSION['create_flash'];
    
    // Restore Inputs
    $student_custom_id = $flash['inputs']['student_custom_id'];
    $student_name = $flash['inputs']['student_name'];
    $date_of_birth = $flash['inputs']['date_of_birth'];
    $section = $flash['inputs']['section'];
    $department = $flash['inputs']['department'];
    $country_code = $flash['inputs']['country_code'];
    $student_phone = $flash['inputs']['student_phone'];
    $email_type = $flash['inputs']['email_type'] ?? 'university';
    $personal_email = $flash['inputs']['personal_email'] ?? '';
    
    // Restore Errors
    $errors = $flash['errors'];
    
    // Clear Flash Data
    unset($_SESSION['create_flash']);
}

// 6. Fetch Departments for Dropdown
$dept_options = [];
$dept_sql = "SELECT dept_name, dept_initials FROM departments_master ORDER BY dept_name ASC";
if($result = mysqli_query($link, $dept_sql)){
    while($row = mysqli_fetch_assoc($result)){
        $dept_options[] = $row;
    }
}

// 7. HANDLE POST REQUEST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Collect Inputs
    $input_student_custom_id = strtoupper(trim($_POST["student_custom_id"]));
    $input_student_name = trim($_POST["student_name"]);
    $input_date_of_birth = trim($_POST["date_of_birth"]);
    $input_section = trim($_POST["section"]);
    $input_department = trim($_POST["department"]);
    $input_country_code = $_POST["country_code"];
    $input_student_phone = trim($_POST["student_phone"]);
    $input_email_type = $_POST["email_type"] ?? 'university';
    $input_personal_email = trim($_POST["personal_email"] ?? '');

    // --- DYNAMIC TABLE LOGIC ---
    $target_year = "";
    $target_table = "";
    
    // Parse Year from ID (Format: BWU/DEPT/YY/ID)
    $id_parts = explode('/', $input_student_custom_id);
    if (count($id_parts) >= 3 && is_numeric($id_parts[2])) {
        $target_year = $id_parts[2];
        $target_table = "students_" . $target_year;
    }

    // Validate ID & Year Existence
    if(empty($input_student_custom_id)){
        $errors['student_custom_id'] = "Please enter a Student ID.";
    } elseif (empty($target_table)) {
        $errors['student_custom_id'] = "Invalid ID format. Could not detect Year (e.g. BWU/CSE/24/...).";
    } else {
        // Check if the Year Table Exists
        $check_table = mysqli_query($link, "SHOW TABLES LIKE '$target_table'");
        if(mysqli_num_rows($check_table) == 0) {
            $errors['student_custom_id'] = "Admission Year '20$target_year' is not created in Master Data yet.";
        } else {
            // Check uniqueness in the SPECIFIC TABLE
            $sql = "SELECT id FROM `$target_table` WHERE student_custom_id = ?";
            if($stmt = mysqli_prepare($link, $sql)){
                mysqli_stmt_bind_param($stmt, "s", $input_student_custom_id);
                if(mysqli_stmt_execute($stmt)){
                    mysqli_stmt_store_result($stmt);
                    if(mysqli_stmt_num_rows($stmt) > 0){
                        $errors['student_custom_id'] = "This Student ID is already registered in the 20$target_year batch.";
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    // Validate Name
    if(empty($input_student_name)){
        $errors['student_name'] = "Please enter the student's name.";
    }

    // Validate Phone
    if(empty($input_student_phone)){
        $errors['student_phone'] = "Please enter a phone number.";
    } elseif(!ctype_digit($input_student_phone)){
        $errors['student_phone'] = "Phone number must contain only digits.";
    } elseif(strlen($input_student_phone) < 7 || strlen($input_student_phone) > 15) {
        $errors['student_phone'] = "Phone number must be between 7 and 15 digits.";
    }

    // Validate Email Logic
    $student_mail_id = "";
    if ($input_email_type === 'personal') {
        if (empty($input_personal_email)) {
            $errors['personal_email'] = "Please enter the personal email address.";
        } elseif (!filter_var($input_personal_email, FILTER_VALIDATE_EMAIL)) {
            $errors['personal_email'] = "Invalid email format.";
        } else {
            $student_mail_id = $input_personal_email;
        }
    } else {
        // University Email (Auto-generated server side to ensure consistency)
        if (!empty($input_student_custom_id)) {
            $clean_id = strtolower(str_replace('/', '', $input_student_custom_id));
            $student_mail_id = $clean_id . "@brainwareuniversity.ac.in";
        }
    }

    // Validate DOB
    if(empty($input_date_of_birth)){
        $errors['date_of_birth'] = "Please select the date of birth.";
    }

    // Validate Department & Section
    if(empty($input_department)) $errors['department'] = "Please select a department.";
    if(empty($input_section)) $errors['section'] = "Please select a section.";

    // Check for errors
    if(empty($errors)){
        
        // Insert into DYNAMIC TABLE
        $sql = "INSERT INTO `$target_table` (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssssss", 
                $input_student_custom_id, 
                $input_student_name, 
                $student_mail_id, 
                $input_country_code, 
                $input_student_phone, 
                $input_date_of_birth, 
                $input_section, 
                $input_department
            );

            if(mysqli_stmt_execute($stmt)){
                // Success: Redirect to students page
                $_SESSION['flash_msg'] = ['text' => "Student added to 20$target_year batch successfully.", 'type' => 'success'];
                header("location: students.php");
                exit();
            } else{
                $errors['db_error'] = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } 

    // If we are here, there were errors. Store in Session (PRG)
    if (!empty($errors)) {
        $_SESSION['create_flash'] = [
            'inputs' => [
                'student_custom_id' => $input_student_custom_id,
                'student_name' => $input_student_name,
                'date_of_birth' => $input_date_of_birth,
                'section' => $input_section,
                'department' => $input_department,
                'country_code' => $input_country_code,
                'student_phone' => $input_student_phone,
                'email_type' => $input_email_type,
                'personal_email' => $input_personal_email
            ],
            'errors' => $errors
        ];
        
        // Redirect back to create page
        header("location: create_student.php");
        exit();
    }
    
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Student | Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet">

    <style>
        /* =====================
           GLOBAL STYLES
           ===================== */
        body {
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* =====================
           LOADER
           ===================== */
        #loader {
            position: fixed;
            inset: 0;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.4s ease;
        }

        .spinner-container {
            position: relative;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .spinner {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 8px solid #f3f3f3;
            border-top: 8px solid #007bff; /* Blue for Creation */
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 2;
        }

        .loader-logo {
            width: 45px;
            height: auto;
            z-index: 1;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        /* =====================
           FORM CONTAINER
           ===================== */
        .wrapper {
            width: 100%;
            max-width: 850px;
            margin: 60px auto;
            padding: 40px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.8);
            position: relative;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #f1f1f1;
            padding-bottom: 15px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
        }

        .section-title {
            color: #6c757d;
            font-size: 0.95rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 15px;
            margin-top: 10px;
            letter-spacing: 0.5px;
            border-left: 4px solid #007bff;
            padding-left: 10px;
        }

        label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .form-control {
            border-radius: 8px;
            height: auto;
            padding: 10px 15px;
            font-size: 0.95rem;
            border: 1px solid #ced4da;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
            border-color: #007bff;
        }

        .input-group-text {
            background-color: #f8f9fa;
            border-radius: 8px 0 0 8px;
            border-color: #ced4da;
            color: #6c757d;
        }

        /* Select2 Customization */
        .select2-container .select2-selection--single {
            height: 46px !important;
            border: 1px solid #ced4da !important;
            border-radius: 8px !important;
            padding: 8px 0;
        }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            padding-left: 12px;
            line-height: 28px !important;
        }
        .country-select-group .select2-container { width: 140px !important; }
        .country-select-group .select2-selection { 
            border-top-right-radius: 0 !important; 
            border-bottom-right-radius: 0 !important; 
            border-right: 0; 
            background-color: #f8f9fa;
        }

        /* Radio Toggle Styles */
        .btn-group-toggle .btn {
            border-radius: 20px;
            margin-right: 5px;
            font-size: 0.8rem;
            border: 1px solid #ced4da;
            padding: 5px 15px;
        }
        .btn-group-toggle .btn.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
            box-shadow: none;
        }

        /* Buttons */
        .btn-submit {
            background: linear-gradient(135deg, #007bff 0%, #0062cc 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 35px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
            background: linear-gradient(135deg, #0062cc 0%, #0056b3 100%);
            color: white;
        }

        .btn-cancel {
            background: transparent;
            border: 2px solid #6c757d;
            color: #6c757d;
            border-radius: 50px;
            padding: 10px 30px;
            font-weight: 600;
            margin-right: 15px;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            background: #6c757d;
            color: #fff;
            text-decoration: none;
        }

        .invalid-feedback { font-size: 0.85rem; }

        /* Toast */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 10000; }

        @media (max-width: 768px) {
            .wrapper { padding: 25px; margin: 30px auto; }
            .btn-action-group { flex-direction: column-reverse; gap: 10px; }
            .btn-submit, .btn-cancel { width: 100%; margin: 0; text-align: center; }
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

    <div class="toast-container">
        <div id="uniqueIdToast" class="toast hide" role="alert" data-delay="3000">
            <div class="toast-header bg-success text-white">
                <strong class="mr-auto"><i class="fas fa-check-circle mr-2"></i>Available</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">&times;</button>
            </div>
            <div class="toast-body">Student ID is unique and available.</div>
        </div>
    </div>

    <div class="container">
        <div class="wrapper">
            
            <div class="page-header">
                <div>
                    <h2>Create Student</h2>
                    <p class="text-muted mb-0">Add a new student record to the system.</p>
                </div>
                <div class="d-none d-md-block">
                    <i class="fas fa-user-plus fa-3x text-primary" style="opacity: 0.3;"></i>
                </div>
            </div>

            <?php if(!empty($errors)): ?>
                <div class="alert alert-danger shadow-sm mb-4 border-0 rounded-lg">
                    <h6 class="font-weight-bold mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> Please correct the errors below:</h6>
                    <ul class="mb-0 pl-3 small">
                        <?php foreach($errors as $err): ?>
                            <li><?php echo $err; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div id="existing-student-alert" class="alert alert-warning shadow-sm border-0 rounded-lg" style="display:none;">
                <h5 class="text-danger font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Student Already Exists!</h5>
                <hr>
                <div class="row">
                    <div class="col-6"><strong>Name:</strong> <span id="exist-name"></span></div>
                    <div class="col-6"><strong>ID:</strong> <span id="exist-id"></span></div>
                </div>
            </div>

            <form action="<?php echo htmlspecialchars(basename($_SERVER['REQUEST_URI'])); ?>" method="post" id="createForm">
                
                <h5 class="section-title">Personal Information</h5>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Student ID <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-fingerprint"></i></span>
                            </div>
                            <input type="text" name="student_custom_id" id="student_id_input" 
                                   class="form-control <?php echo isset($errors['student_custom_id']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_custom_id); ?>" 
                                   placeholder="e.g. BWU/CSE/24/001" autocomplete="off" style="text-transform: uppercase;">
                            <div class="invalid-feedback"><?php echo $errors['student_custom_id'] ?? ''; ?></div>
                        </div>
                        <small class="form-text text-muted">Year table (e.g. students_24) is selected based on this ID.</small>
                        <small id="checking-status" class="text-primary font-weight-bold mt-1" style="display:none; font-size: 0.8rem;">
                            <i class="fas fa-spinner fa-spin mr-1"></i> Checking availability...
                        </small>
                    </div>

                    <div class="form-group col-md-6">
                        <label>Full Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                            </div>
                            <input type="text" name="student_name" 
                                   class="form-control <?php echo isset($errors['student_name']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_name); ?>"
                                   placeholder="e.g. John Doe">
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
                                        <option value="<?php echo $code; ?>" <?php echo ($country_code == $code) ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="text" name="student_phone" id="student_phone" 
                                   class="form-control <?php echo isset($errors['student_phone']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_phone); ?>" maxlength="15"
                                   placeholder="9876543210">
                            <div class="invalid-feedback"><?php echo $errors['student_phone'] ?? ''; ?></div>
                        </div>
                    </div>

                    <div class="form-group col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="mb-0">Email Address <span class="text-danger">*</span></label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <label class="btn btn-sm btn-outline-secondary <?php echo ($email_type == 'university') ? 'active' : ''; ?>" onclick="toggleEmailType('university')">
                                    <input type="radio" name="email_type" value="university" <?php echo ($email_type == 'university') ? 'checked' : ''; ?>> University
                                </label>
                                <label class="btn btn-sm btn-outline-secondary <?php echo ($email_type == 'personal') ? 'active' : ''; ?>" onclick="toggleEmailType('personal')">
                                    <input type="radio" name="email_type" value="personal" <?php echo ($email_type == 'personal') ? 'checked' : ''; ?>> Personal
                                </label>
                            </div>
                        </div>
                        
                        <div id="email_uni_container" class="input-group" style="<?php echo ($email_type == 'university') ? '' : 'display:none;'; ?>">
                            <input type="text" id="email_prefix" class="form-control bg-light" readonly placeholder="Type ID to generate">
                            <div class="input-group-append">
                                <span class="input-group-text">@brainwareuniversity.ac.in</span>
                            </div>
                        </div>

                        <div id="email_personal_container" style="<?php echo ($email_type == 'personal') ? '' : 'display:none;'; ?>">
                             <input type="email" name="personal_email" id="personal_email_input"
                                   class="form-control <?php echo isset($errors['personal_email']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($personal_email); ?>"
                                   placeholder="e.g. personal@example.com">
                            <div class="invalid-feedback"><?php echo $errors['personal_email'] ?? ''; ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Date of Birth <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                            </div>
                            <input type="date" name="date_of_birth" 
                                   class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($date_of_birth); ?>">
                            <div class="invalid-feedback"><?php echo $errors['date_of_birth'] ?? ''; ?></div>
                        </div>
                    </div>
                </div>

                <h5 class="section-title mt-4">Academic Details</h5>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Department <span class="text-danger">*</span></label>
                        <select name="department" id="deptSelect" class="form-control <?php echo isset($errors['department']) ? 'is-invalid' : ''; ?>">
                            <option value="">Select Department</option>
                            <?php foreach($dept_options as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['dept_name']); ?>" 
                                    data-initials="<?php echo htmlspecialchars($opt['dept_initials']); ?>"
                                    <?php echo ($department == $opt['dept_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($opt['dept_name']) . " (" . htmlspecialchars($opt['dept_initials']) . ")"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback"><?php echo $errors['department'] ?? ''; ?></div>
                    </div>

                    <div class="form-group col-md-6">
                        <label>Section / Group <span class="text-danger">*</span></label>
                        <select name="section" id="sectionSelect" class="form-control <?php echo isset($errors['section']) ? 'is-invalid' : ''; ?>">
                            <option value="">Select Section</option>
                            </select>
                        <small class="form-text text-muted">Automatically loaded based on Department and ID (Year).</small>
                        <div class="invalid-feedback"><?php echo $errors['section'] ?? ''; ?></div>
                    </div>
                </div>

                <div class="d-flex justify-content-end align-items-center mt-4 btn-action-group">
                    <a href="students.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" id="submitBtn" class="btn btn-submit">
                        <i class="fas fa-save mr-2"></i> Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // Toggle Email Fields
        function toggleEmailType(type) {
            if(type === 'university') {
                $('#email_uni_container').show();
                $('#email_personal_container').hide();
                // Trigger ID update to refresh the prefix
                $('#student_id_input').trigger('input');
            } else {
                $('#email_uni_container').hide();
                $('#email_personal_container').show();
                $('#personal_email_input').focus();
            }
        }

        // Loader Logic
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() {
                loader.style.display = 'none';
            }, 400);
        });

        $(document).ready(function() {
            // 1. Initialize Select2
            $('.select2-country').select2({ theme: 'bootstrap4', width: '100%' });
            $('#deptSelect').select2({ theme: 'bootstrap4', width: '100%', placeholder: "Select Department" });
            $('#sectionSelect').select2({ theme: 'bootstrap4', width: '100%', placeholder: "Select Section" });

            // 2. Client-side Phone numeric validation
            $('#student_phone').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                var phoneVal = $(this).val();
                if(phoneVal.length > 0 && (phoneVal.length < 7 || phoneVal.length > 15)) {
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            // 3. Dynamic Logic Variables
            var idInput = $('#student_id_input');
            var emailPrefixInput = $('#email_prefix');
            var deptSelect = $('#deptSelect');
            var sectionSelect = $('#sectionSelect');
            var submitBtn = $('#submitBtn');
            var currentSection = "<?php echo $section; ?>";

            // 4. Update Email Prefix & Trigger Department/Section Auto-selection
            idInput.on('input', function() {
                // FORCE UPPERCASE LOGIC
                var start = this.selectionStart;
                var end = this.selectionEnd;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(start, end);

                var val = $(this).val();
                var parts = val.split('/');

                // Strip slashes and lowercase for email
                emailPrefixInput.val(val.replace(/\//g, '').toLowerCase());

                // --- AUTO SELECT DEPARTMENT LOGIC ---
                // Format: BWU / INITIALS / YEAR / ID
                if (parts.length >= 2) {
                    var inputInitials = parts[1].trim().toUpperCase();
                    
                    if (inputInitials.length > 0) {
                        $('#deptSelect option').each(function() {
                            var optInitials = $(this).data('initials');
                            if (optInitials && optInitials.toString().toUpperCase() === inputInitials) {
                                if (deptSelect.val() !== $(this).val()) {
                                    deptSelect.val($(this).val()).trigger('change');
                                }
                                return false; 
                            }
                        });
                    }
                }

                // If ID format looks sufficient, try load sections
                if (parts.length >= 3) {
                    loadSections(null);
                }
            });

            // 5. Check ID Uniqueness (Note: check_student_id.php needs to be updated too)
            idInput.on('change', function() {
                var val = $(this).val();
                if(val.length > 0) {
                    $('#checking-status').show();
                    
                    $.ajax({
                        url: 'check_student_id.php', 
                        type: 'POST', 
                        data: {student_id: val}, 
                        dataType: 'json',
                        success: function(resp) {
                            $('#checking-status').hide();
                            if(resp.exists) {
                                idInput.addClass('is-invalid');
                                $('#existing-student-alert').slideDown();
                                $('#exist-name').text(resp.data.student_name);
                                $('#exist-id').text(resp.data.student_custom_id);
                                submitBtn.prop('disabled', true); 
                            } else {
                                idInput.removeClass('is-invalid').addClass('is-valid');
                                $('#existing-student-alert').slideUp();
                                $('#uniqueIdToast').toast('show');
                                submitBtn.prop('disabled', false); 
                            }
                        },
                        error: function() {
                            $('#checking-status').hide();
                            // If table doesn't exist yet, this might error out or return false
                            // We allow frontend to proceed, backend will catch if table missing.
                        }
                    });
                }
            });

            // 6. Section Loading AJAX
            function loadSections(preselectedSection) {
                var studentId = idInput.val().trim();
                var deptName = deptSelect.val();
                
                var parts = studentId.split('/');
                var yearVal = (parts.length >= 3) ? parts[2] : '';

                if (deptName && yearVal && yearVal.length === 2) {
                    sectionSelect.prop('disabled', true);
                    
                    $.ajax({
                        url: 'get_sections.php',
                        type: 'POST',
                        data: { dept_name: deptName, year_val: yearVal },
                        dataType: 'json',
                        success: function(data) {
                            sectionSelect.empty().append('<option value="">Select Section</option>');
                            
                            if (data.length > 0) {
                                $.each(data, function(index, value) {
                                    var isSelected = (value === preselectedSection) ? 'selected' : '';
                                    sectionSelect.append('<option value="'+value+'" '+isSelected+'>'+value+'</option>');
                                });
                            } else {
                                sectionSelect.append('<option value="">No sections found</option>');
                            }
                            sectionSelect.prop('disabled', false);
                            // If coming back from error with a value
                            if(preselectedSection) sectionSelect.val(preselectedSection).trigger('change');
                        },
                        error: function() { 
                            sectionSelect.html('<option>Error loading</option>');
                            sectionSelect.prop('disabled', false);
                        }
                    });
                }
            }

            // Trigger on Department change
            deptSelect.on('change', function() { 
                loadSections(null); 
            });

            // Initial Section Load (if returning with error)
            if(currentSection !== "") {
                loadSections(currentSection);
            }
            // Trigger Email update on page load if ID exists
            if(idInput.val() !== "") {
                idInput.trigger('input'); 
            }
        });
    </script>
</body>
</html>