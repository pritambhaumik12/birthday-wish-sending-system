<?php
// birthday_system01/index.php

session_start();

// Redirect if already logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

require_once "config.php";

// Define variables and initialize with empty values or values from session (PRG)
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Check for flash data from a previous failed POST request
if (isset($_SESSION['login_flash'])) {
    $username = $_SESSION['login_flash']['username'] ?? "";
    $username_err = $_SESSION['login_flash']['username_err'] ?? "";
    $password_err = $_SESSION['login_flash']['password_err'] ?? "";
    $login_err = $_SESSION['login_flash']['login_err'] ?? "";
    
    // Clear the flash data so errors don't persist on refresh
    unset($_SESSION['login_flash']);
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $input_username = trim($_POST["username"]);
    $input_password = trim($_POST["password"]);
    
    // Validate inputs
    if (empty($input_username)) {
        $username_err = "Please enter username.";
    } else {
        $username = $input_username;
    }

    if (empty($input_password)) {
        $password_err = "Please enter your password.";
    } else {
        $password = $input_password;
    }

    // Validate credentials if no format errors
    if (empty($username_err) && empty($password_err)) {

        $sql = "SELECT id, username, password, role FROM users WHERE username = ?";

        if ($stmt = mysqli_prepare($link, $sql)) {

            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;

            if (mysqli_stmt_execute($stmt)) {

                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $db_username, $hashed_password, $role);
                    if (mysqli_stmt_fetch($stmt)) {
                        // Plain text password check (as per existing logic)
                        if ($password == $hashed_password) {
                            // Password is correct, start a new session
                            session_regenerate_id(true); // Security practice
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $db_username;
                            $_SESSION["role"] = $role;

                            // Redirect to dashboard (PRG - Success)
                            header("location: dashboard.php");
                            exit;
                        } else {
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            } else {
                $login_err = "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // If we reached here, there was an error.
    // Store errors and input in session (Flash Data)
    $_SESSION['login_flash'] = [
        'username' => $username,
        'username_err' => $username_err,
        'password_err' => $password_err,
        'login_err' => $login_err
    ];
    
    // Redirect back to index (PRG - Failure)
    // This prevents form resubmission dialogs on refresh
    header("location: index.php");
    exit;
}
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | Birthday System</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="BWU_logo.png" type="image/png">

<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<style>
/* =====================
   GLOBAL BACKGROUND
   ===================== */
body {
    background: url('university.jpg') no-repeat center center fixed;
    background-size: cover;
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

/* =====================
   LOADER WITH LOGO
   ===================== */
#loader {
    position: fixed;
    inset: 0;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    transition: opacity 0.5s ease;
}

/* Container to center items */
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
    border: 8px solid #e5e7eb;
    border-top: 8px solid #2563eb;
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
   LOGIN CARD (SOLID)
   ===================== */
.wrapper {
    width: 100%;
    max-width: 420px;
    background: #ffffff;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 20px 45px rgba(0,0,0,0.35);
    border: 1px solid #e5e7eb;
}

/* HEADER */
.login-header {
    background: linear-gradient(135deg, #1e3c72, #2a5298);
    padding: 35px 25px;
    text-align: center;
}
.logo-img {
    width: 110px;
    margin-bottom: 12px;
}
.login-header h2 {
    color: #fff;
    font-weight: 700;
    letter-spacing: 1px;
}
.login-header p {
    color: rgba(255,255,255,0.85);
    font-size: 0.9rem;
    margin-bottom: 0;
}

/* FORM BODY */
.wrapper form {
    padding: 35px 30px 40px;
}

/* INPUTS */
label {
    font-weight: 600;
    color: #374151;
    margin-left: 8px;
    font-size: 0.9rem;
}
.form-control {
    border-radius: 12px;
    padding: 14px 18px;
    background: #f9fafb;
    border: 1px solid #d1d5db;
    transition: all 0.3s;
}
.form-control:focus {
    background: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
}

/* BUTTON */
.btn-login {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    border: none;
    border-radius: 14px;
    padding: 14px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    box-shadow: 0 8px 25px rgba(37,99,235,0.4);
    transition: all 0.3s;
}
.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(37,99,235,0.55);
}

/* ERRORS */
.alert-danger {
    margin: 20px;
    border-radius: 10px;
    font-size: 0.9rem;
}
.invalid-feedback {
    font-size: 0.8rem;
    margin-left: 10px;
}
.forgot-link {
    display: block;
    text-align: right;
    margin-top: 10px;
    font-size: 0.9rem;
    color: #2563eb;
    text-decoration: none;
}
.forgot-link:hover {
    text-decoration: underline;
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

<div class="wrapper">

    <div class="login-header">
        <img src="BWU_logo.png" class="logo-img" alt="University Logo">
        <h2>Welcome Back</h2>
        <p>Login to manage the Birthday System</p>
    </div>

    <?php if (!empty($login_err)): ?>
        <div class="alert alert-danger text-center">
            <i class="fas fa-exclamation-circle"></i> <?php echo $login_err; ?>
        </div>
    <?php endif; ?>

    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username"
                   class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>"
                   value="<?php echo htmlspecialchars($username); ?>"
                   placeholder="Enter username">
            <div class="invalid-feedback d-block"><?php echo $username_err; ?></div>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password"
                   class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                   placeholder="Enter password">
            <div class="invalid-feedback d-block"><?php echo $password_err; ?></div>
        </div>
        
        <a href="#" class="forgot-link" data-toggle="modal" data-target="#forgotPasswordModal">Forgot Password?</a>

        <button type="submit" class="btn btn-login btn-block mt-4">
            LOGIN <i class="fas fa-sign-in-alt ml-1"></i>
        </button>

    </form>
</div>

<div class="modal fade" id="forgotPasswordModal" tabindex="-1" role="dialog" aria-labelledby="forgotPasswordLabel" aria-hidden="true" data-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title" id="forgotPasswordLabel"><i class="fas fa-lock text-primary"></i> Reset Password</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-4">
        
        <div id="fp-alert" class="alert d-none"></div>

        <div id="step-1">
            <p class="text-muted mb-3">Enter your Username or Email to receive a verification code.</p>
            <div class="form-group">
                <label>Username or Email</label>
                <input type="text" id="fp_input" class="form-control" placeholder="username or email@example.com">
            </div>
            <button type="button" class="btn btn-primary btn-block" onclick="sendOTP()" id="btn-send-otp">
                Send OTP
            </button>
        </div>

        <div id="step-2" style="display:none;">
            <p class="text-muted mb-3">An OTP has been sent to your email. Please enter it below.</p>
            <div class="form-group">
                <label>Enter 6-digit OTP</label>
                <input type="text" id="fp_otp" class="form-control" maxlength="6" placeholder="123456">
            </div>
            <button type="button" class="btn btn-success btn-block" onclick="verifyOTP()" id="btn-verify-otp">
                Verify OTP
            </button>
            <div class="text-center mt-2">
                <a href="#" class="text-secondary small" onclick="resetSteps()">Start Over</a>
            </div>
        </div>

        <div id="step-3" style="display:none;">
            <p class="text-muted mb-3">OTP Verified. Please set your new password.</p>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" id="fp_new_pass" class="form-control" placeholder="Minimum 6 characters">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" id="fp_confirm_pass" class="form-control" placeholder="Re-enter password">
            </div>
            <button type="button" class="btn btn-primary btn-block" onclick="resetPassword()" id="btn-reset-pass">
                Update Password
            </button>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
    // Page Loading Script
    window.addEventListener('load', function() {
        var loader = document.getElementById('loader');
        loader.style.opacity = '0';
        setTimeout(function() {
            loader.style.display = 'none';
        }, 500);
    });

    // Helper to Show Alerts
    function showAlert(type, message) {
        var alertBox = $('#fp-alert');
        alertBox.removeClass('d-none alert-success alert-danger');
        alertBox.addClass('alert-' + type);
        alertBox.html(message);
    }

    // Helper to Reset Modal on Close
    $('#forgotPasswordModal').on('hidden.bs.modal', function () {
        resetSteps();
    });

    function resetSteps() {
        $('#step-1').show();
        $('#step-2').hide();
        $('#step-3').hide();
        $('#fp_input').val('');
        $('#fp_otp').val('');
        $('#fp_new_pass').val('');
        $('#fp_confirm_pass').val('');
        $('#fp-alert').addClass('d-none');
    }

    // STEP 1: Send OTP
    function sendOTP() {
        var inputVal = $('#fp_input').val();
        if(!inputVal) {
            showAlert('danger', 'Please enter a username or email.');
            return;
        }

        var btn = $('#btn-send-otp');
        var originalText = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

        $.ajax({
            url: 'forgot_password.php',
            type: 'POST',
            data: { action: 'send_otp', input_val: inputVal },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    $('#step-1').hide();
                    $('#step-2').fadeIn();
                    showAlert('success', response.message);
                } else {
                    showAlert('danger', response.message);
                }
            },
            error: function() {
                showAlert('danger', 'Server connection failed.');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    }

    // STEP 2: Verify OTP
    function verifyOTP() {
        var otpVal = $('#fp_otp').val();
        if(!otpVal || otpVal.length < 6) {
            showAlert('danger', 'Please enter a valid 6-digit OTP.');
            return;
        }

        var btn = $('#btn-verify-otp');
        var originalText = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Verifying...');

        $.ajax({
            url: 'forgot_password.php',
            type: 'POST',
            data: { action: 'verify_otp', otp_val: otpVal },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    $('#step-2').hide();
                    $('#step-3').fadeIn();
                    showAlert('success', response.message);
                } else {
                    showAlert('danger', response.message);
                }
            },
            error: function() {
                showAlert('danger', 'Server connection failed.');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    }

    // STEP 3: Reset Password
    function resetPassword() {
        var newPass = $('#fp_new_pass').val();
        var confirmPass = $('#fp_confirm_pass').val();

        if(newPass.length < 6) {
            showAlert('danger', 'Password must be at least 6 characters.');
            return;
        }
        if(newPass !== confirmPass) {
            showAlert('danger', 'Passwords do not match.');
            return;
        }

        var btn = $('#btn-reset-pass');
        var originalText = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Updating...');

        $.ajax({
            url: 'forgot_password.php',
            type: 'POST',
            data: { action: 'reset_password', new_password: newPass },
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    $('#step-3').html('<div class="text-center py-4"><i class="fas fa-check-circle fa-3x text-success mb-3"></i><h4>Success!</h4><p>Your password has been reset. You can now login.</p><button class="btn btn-primary" data-dismiss="modal">Close</button></div>');
                    showAlert('success', response.message);
                } else {
                    showAlert('danger', response.message);
                }
            },
            error: function() {
                showAlert('danger', 'Server connection failed.');
            },
            complete: function() {
                if(btn.length) btn.prop('disabled', false).html(originalText);
            }
        });
    }
</script>

</body>
</html>