<?php
// birthday_system01/edit_student.php

// ==========================================
// 1. INITIALIZATION & AUTHENTICATION
// ==========================================
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Include config and helper files
require_once "config.php";
require_once "countries.php"; 

// Define variables and initialize with empty values
$id = $year_val = "";
$student_custom_id = $student_name = $student_mail_id = $student_phone = $dob = $section = $department = "";
$country_code = "+91"; 
$errors = [];
$msg = "";

// ==========================================
// 2. FETCH STUDENT DATA (GET REQUEST)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    
    // Check if ID and YEAR parameters exist
    if (isset($_GET["id"]) && !empty(trim($_GET["id"])) && isset($_GET["year"])) {
        
        $id = trim($_GET["id"]);
        $year_val = trim($_GET["year"]); 
        
        // Determine Table Name
        $table_name = (function_exists('get_student_table')) ? get_student_table($year_val) : "students_" . preg_replace('/\D/', '', $year_val);

        // Verify table exists
        $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
        if (mysqli_num_rows($check_table) == 0) {
            die("<div class='alert alert-danger text-center mt-5'>Error: Database table for Year 20$year_val does not exist.</div>");
        }

        // Fetch Student
        $sql = "SELECT * FROM `$table_name` WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $id);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if (mysqli_num_rows($result) == 1) {
                    $row = mysqli_fetch_assoc($result);
                    
                    $student_custom_id = $row['student_custom_id'];
                    $student_name = $row['student_name'];
                    $student_mail_id = $row['student_mail_id']; // Fetch existing email
                    $country_code = $row['country_code'];
                    $student_phone = $row['student_phone'];
                    $dob = $row['date_of_birth'];
                    $section = $row['section'];
                    $department = $row['department'];
                } else {
                    header("location: error.php");
                    exit();
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        header("location: error.php");
        exit();
    }
}

// ==========================================
// 3. PROCESS UPDATE (POST REQUEST)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get hidden identifiers
    $id = $_POST['id'];
    $year_val = $_POST['year_val'];
    $table_name = (function_exists('get_student_table')) ? get_student_table($year_val) : "students_" . preg_replace('/\D/', '', $year_val);

    // Collect Inputs
    $input_student_custom_id = strtoupper(trim($_POST["student_custom_id"]));
    $input_student_name = trim($_POST["student_name"]);
    $input_email = trim($_POST["student_mail_id"]); // New Input
    $input_dob = trim($_POST["date_of_birth"]);
    $input_section = trim($_POST["section"]);
    $input_department = trim($_POST["department"]);
    $input_country_code = $_POST["country_code"];
    $input_student_phone = trim($_POST["student_phone"]);

    // --- VALIDATION ---

    // 1. Validate ID & Batch Integrity
    if(empty($input_student_custom_id)) {
        $errors['student_custom_id'] = "Student ID cannot be empty.";
    } else {
        // Ensure Year part matches (prevent table hopping in Edit)
        if (strpos($input_student_custom_id, "/$year_val/") === false) {
            $errors['student_custom_id'] = "Batch Year (/$year_val/) cannot be changed in Edit mode.";
        } else {
            // Check uniqueness
            $sql_check = "SELECT id FROM `$table_name` WHERE student_custom_id = ? AND id != ?";
            if ($stmt = mysqli_prepare($link, $sql_check)) {
                mysqli_stmt_bind_param($stmt, "si", $input_student_custom_id, $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $errors['student_custom_id'] = "This ID is already taken.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    // 2. Validate Name
    if(empty($input_student_name)){
        $errors['student_name'] = "Please enter a name.";
    } elseif(!preg_match("/^[a-zA-Z\s]+$/", $input_student_name)){
        $errors['student_name'] = "Name can only contain letters and spaces.";
    }

    // 3. Validate Email
    if(empty($input_email)){
        $errors['student_mail_id'] = "Email is required.";
    } elseif (!filter_var($input_email, FILTER_VALIDATE_EMAIL)) {
        $errors['student_mail_id'] = "Invalid email format.";
    }

    // 4. Validate Phone
    if(empty($input_student_phone)){
        $errors['student_phone'] = "Please enter a phone number.";
    } elseif(!ctype_digit($input_student_phone)){
        $errors['student_phone'] = "Phone number must contain only digits.";
    }

    // 5. Validate Others
    if(empty($input_dob)) $errors['date_of_birth'] = "Please select a Date of Birth.";
    if(empty($input_department)) $errors['department'] = "Please select a Department.";
    if(empty($input_section)) $errors['section'] = "Please select a Section.";

    // --- UPDATE DATABASE ---
    if(empty($errors)){
        
        $sql = "UPDATE `$table_name` SET 
                student_custom_id = ?, 
                student_name = ?, 
                student_mail_id = ?, 
                country_code = ?, 
                student_phone = ?, 
                date_of_birth = ?, 
                section = ?, 
                department = ? 
                WHERE id = ?";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssssssi", 
                $input_student_custom_id, 
                $input_student_name, 
                $input_email,  // Use manual email input
                $input_country_code, 
                $input_student_phone, 
                $input_dob, 
                $input_section, 
                $input_department, 
                $id
            );

            if(mysqli_stmt_execute($stmt)){
                $_SESSION['flash_msg'] = ['text' => "Student record updated successfully.", 'type' => 'success'];
                header("location: students.php");
                exit();
            } else{
                $msg = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        // Retain values for form
        $student_custom_id = $input_student_custom_id;
        $student_name = $input_student_name;
        $student_mail_id = $input_email;
        $student_phone = $input_student_phone;
        $dob = $input_dob;
        $department = $input_department;
        $section = $input_section;
        $country_code = $input_country_code;
    }
}

// Fetch Departments
$dept_options = [];
$res = mysqli_query($link, "SELECT dept_name, dept_initials FROM departments_master ORDER BY dept_name ASC");
while($r = mysqli_fetch_assoc($res)) $dept_options[] = $r;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Student | Birthday System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="BWU_logo.png" type="image/png">
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        body {
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* LOADER */
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
            border: 8px solid #f3f3f3; border-top: 8px solid #ffc107;
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* FORM */
        .wrapper {
            width: 100%; max-width: 900px; margin: 60px auto; padding: 40px;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            border: 1px solid rgba(255,255,255,0.8);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px; border-bottom: 2px solid #f1f1f1; padding-bottom: 15px;
        }
        .page-header h2 { font-weight: 700; color: #333; text-transform: uppercase; margin: 0; }
        .section-title {
            color: #6c757d; font-size: 0.95rem; font-weight: 700;
            text-transform: uppercase; margin: 25px 0 15px;
            border-left: 4px solid #ffc107; padding-left: 10px;
        }

        .form-control { border-radius: 8px; height: auto; padding: 10px 15px; font-size: 0.95rem; }
        .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(255, 193, 7, 0.25); border-color: #ffc107; }
        .input-group-text { background-color: #f8f9fa; border-radius: 8px 0 0 8px; color: #6c757d; }

        /* Select2 */
        .select2-container .select2-selection--single { height: 46px !important; border-radius: 8px !important; padding: 8px 0; }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { padding-left: 12px; line-height: 28px !important; }
        
        .country-select-group .select2-container { width: 140px !important; }
        .country-select-group .select2-selection { 
            border-top-right-radius: 0 !important; border-bottom-right-radius: 0 !important; border-right: 0; background-color: #f8f9fa;
        }

        /* Buttons */
        .btn-submit {
            background: linear-gradient(135deg, #ffc107 0%, #ffca2c 100%);
            color: #212529; border: none; border-radius: 50px;
            padding: 12px 35px; font-weight: 700; text-transform: uppercase; 
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3); transition: all 0.3s;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4); }
        .btn-cancel {
            background: transparent; border: 2px solid #6c757d; color: #6c757d;
            border-radius: 50px; padding: 10px 30px; font-weight: 600; margin-right: 15px;
        }
        .btn-cancel:hover { background: #6c757d; color: white; text-decoration: none; }

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

    <div class="container">
        <div class="wrapper">
            
            <div class="page-header">
                <div>
                    <h2>Edit Student</h2>
                    <p class="text-muted mb-0">Update information for Batch 20<?php echo htmlspecialchars($year_val); ?></p>
                </div>
                <div class="d-none d-md-block">
                    <span class="badge badge-warning p-2 shadow-sm" style="font-size: 1rem;">
                        <i class="fas fa-edit mr-1"></i> Edit Mode
                    </span>
                </div>
            </div>

            <?php if(!empty($msg)): ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-lg"><i class="fas fa-exclamation-circle mr-2"></i> <?php echo $msg; ?></div>
            <?php endif; ?>

            <?php if(!empty($errors)): ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-lg">
                    <h6 class="font-weight-bold mb-2"><i class="fas fa-exclamation-triangle mr-2"></i> Errors:</h6>
                    <ul class="mb-0 pl-3 small">
                        <?php foreach($errors as $err): ?><li><?php echo $err; ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars(basename($_SERVER['REQUEST_URI'])); ?>" method="post">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="year_val" value="<?php echo $year_val; ?>">

                <h5 class="section-title">Identity</h5>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Student ID <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-fingerprint"></i></span></div>
                            <input type="text" name="student_custom_id" 
                                   class="form-control <?php echo isset($errors['student_custom_id']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_custom_id); ?>" 
                                   style="text-transform: uppercase;">
                        </div>
                        <small class="form-text text-muted">Format: BWU/DEPT/<?php echo $year_val; ?>/XXX</small>
                    </div>

                    <div class="form-group col-md-6">
                        <label>Full Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-user"></i></span></div>
                            <input type="text" name="student_name" 
                                   class="form-control <?php echo isset($errors['student_name']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_name); ?>">
                        </div>
                    </div>
                </div>

                <h5 class="section-title">Contact Information</h5>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Email Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                            <input type="email" name="student_mail_id" 
                                   class="form-control <?php echo isset($errors['student_mail_id']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($student_mail_id); ?>" placeholder="name@domain.com">
                        </div>
                        <small class="text-muted">You can edit the full email including domain.</small>
                    </div>

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
                                   value="<?php echo htmlspecialchars($student_phone); ?>" maxlength="15">
                        </div>
                    </div>
                </div>

                <h5 class="section-title">Academic & Personal</h5>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Date of Birth <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar-alt"></i></span></div>
                            <input type="date" name="date_of_birth" 
                                   class="form-control <?php echo isset($errors['date_of_birth']) ? 'is-invalid' : ''; ?>" 
                                   value="<?php echo htmlspecialchars($dob); ?>">
                        </div>
                    </div>

                    <div class="form-group col-md-4">
                        <label>Department <span class="text-danger">*</span></label>
                        <select name="department" id="deptSelect" class="form-control <?php echo isset($errors['department']) ? 'is-invalid' : ''; ?>">
                            <option value="">Select Department</option>
                            <?php foreach($dept_options as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['dept_name']); ?>" 
                                    <?php echo ($department == $opt['dept_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($opt['dept_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-4">
                        <label>Section <span class="text-danger">*</span></label>
                        <select name="section" id="sectionSelect" class="form-control <?php echo isset($errors['section']) ? 'is-invalid' : ''; ?>">
                            <option value="<?php echo htmlspecialchars($section); ?>" selected><?php echo htmlspecialchars($section); ?></option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end align-items-center mt-4 btn-action-group">
                    <a href="students.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-save mr-2"></i> Update Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() { loader.style.display = 'none'; }, 400);
        });

        $(document).ready(function() {
            $('.select2-country').select2({ theme: 'bootstrap4', width: '100%' });
            $('#deptSelect').select2({ theme: 'bootstrap4', width: '100%' });
            $('#sectionSelect').select2({ theme: 'bootstrap4', width: '100%' });

            $('#student_phone').on('input', function() { this.value = this.value.replace(/[^0-9]/g, ''); });
            $('input[name="student_custom_id"]').on('input', function() {
                var start = this.selectionStart, end = this.selectionEnd;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(start, end);
            });

            var currentBatchYear = "<?php echo $year_val; ?>"; 
            
            $('#deptSelect').on('change', function() {
                var deptName = $(this).val();
                var sectionSelect = $('#sectionSelect');
                if (deptName && currentBatchYear) {
                    sectionSelect.prop('disabled', true);
                    $.ajax({
                        url: 'get_sections.php',
                        type: 'POST',
                        data: { dept_name: deptName, year_val: currentBatchYear },
                        dataType: 'json',
                        success: function(data) {
                            sectionSelect.empty().append('<option value="">Select Section</option>');
                            if (data.length > 0) {
                                $.each(data, function(index, value) {
                                    sectionSelect.append('<option value="'+value+'">'+value+'</option>');
                                });
                            } else {
                                sectionSelect.append('<option value="">No sections found</option>');
                            }
                            sectionSelect.prop('disabled', false);
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>