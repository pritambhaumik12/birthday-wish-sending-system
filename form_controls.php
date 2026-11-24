<?php
// birthday_system01/form_controls.php

// Initialize session
session_start();

// Check Admin Login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

$msg = "";
$msg_type = "";

// --- CALCULATE STUDENT FORM URL ---
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$host = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['PHP_SELF']);
// Normalize slashes (windows fix) and remove trailing slash
$path = rtrim(str_replace('\\', '/', $path), '/');
$student_form_url = $protocol . "://" . $host . $path . "/student-form.php";

// --- PRG PATTERN: STEP 3 (GET) ---
// Check if there's a message from a previous redirect
if (isset($_SESSION['control_msg'])) {
    $msg = $_SESSION['control_msg']['text'];
    $msg_type = $_SESSION['control_msg']['type'];
    // Clear the message so it doesn't persist on refresh
    unset($_SESSION['control_msg']);
}

// --- PRG PATTERN: STEP 1 (POST) ---
// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // A. Bulk Update Logic
    if (isset($_POST['bulk_action'])) {
        $target_dept = $_POST['target_dept'];
        $target_year = $_POST['target_year'];
        // Determine status: 'open' -> 1, 'close' -> 0
        $new_status = ($_POST['bulk_action'] == 'open') ? 1 : 0;

        $sql = "UPDATE sections_master s 
                JOIN departments_master d ON s.dept_id = d.id 
                JOIN years_master y ON s.year_id = y.id 
                SET s.reg_open = ?";
        
        $params = [$new_status];
        $types = "i";
        
        // Build dynamic query based on filters
        if (!empty($target_dept)) {
            $sql .= " WHERE d.id = ?";
            $params[] = $target_dept;
            $types .= "i";
            
            if (!empty($target_year)) {
                $sql .= " AND y.id = ?";
                $params[] = $target_year;
                $types .= "i";
            }
        } elseif (!empty($target_year)) {
            $sql .= " WHERE y.id = ?";
            $params[] = $target_year;
            $types .= "i";
        }

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            if (mysqli_stmt_execute($stmt)) {
                $affected = mysqli_stmt_affected_rows($stmt);
                $status_text = ($new_status == 1) ? "Opened" : "Closed";
                $_SESSION['control_msg'] = [
                    'text' => "Successfully $status_text registration for $affected section(s).", 
                    'type' => 'success'
                ];
            } else {
                $_SESSION['control_msg'] = [
                    'text' => "Error updating records: " . mysqli_error($link), 
                    'type' => 'danger'
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // B. Single Toggle Logic
    elseif (isset($_POST['toggle_id'])) {
        $id = $_POST['toggle_id'];
        $current_val = $_POST['current_val'];
        $new_val = ($current_val == 1) ? 0 : 1;
        
        $sql = "UPDATE sections_master SET reg_open = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $new_val, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
            $status_text = ($new_val == 1) ? "opened" : "closed";
            $_SESSION['control_msg'] = [
                'text' => "Section registration $status_text.", 
                'type' => 'success'
            ];
        } else {
            $_SESSION['control_msg'] = [
                'text' => "Database error during toggle.", 
                'type' => 'danger'
            ];
        }
    }

    // --- PRG PATTERN: STEP 2 (REDIRECT) ---
    // Redirect back to the same page.
    // We preserve the QUERY_STRING (filters) so the user doesn't lose their place in the list.
    $redirect_url = "form_controls.php";
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect_url .= "?" . $_SERVER['QUERY_STRING'];
    }
    
    header("Location: " . $redirect_url);
    exit(); // Stop script execution immediately after header
}

// --- FETCH DATA FOR VIEW ---

// 1. Fetch Dropdown Options
$dept_options = [];
$year_options = [];

$res = mysqli_query($link, "SELECT * FROM departments_master ORDER BY dept_name");
while ($row = mysqli_fetch_assoc($res)) $dept_options[] = $row;

$res = mysqli_query($link, "SELECT * FROM years_master ORDER BY year_val DESC");
while ($row = mysqli_fetch_assoc($res)) $year_options[] = $row;

// 2. Fetch Section List with Filters
$filter_d = $_GET['f_dept'] ?? '';
$filter_y = $_GET['f_year'] ?? '';

$list_sql = "SELECT s.id, s.section_name, s.reg_open, d.dept_name, d.dept_initials, y.year_val 
             FROM sections_master s
             JOIN departments_master d ON s.dept_id = d.id
             JOIN years_master y ON s.year_id = y.id
             WHERE 1=1";

if(!empty($filter_d)) $list_sql .= " AND d.id = " . intval($filter_d);
if(!empty($filter_y)) $list_sql .= " AND y.id = " . intval($filter_y);

$list_sql .= " ORDER BY d.dept_name ASC, y.year_val DESC, s.section_name ASC";
$sections = mysqli_query($link, $list_sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Form Access Controls | Birthday System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="BWU_logo.png" type="image/png">
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

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

        /* Loading Overlay */
        #loader {
            position: fixed; inset: 0; background: #ffffff;
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; transition: opacity 0.4s ease;
        }
        .spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }
        .spinner {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            border: 8px solid #f3f3f3; border-top: 8px solid #17a2b8; /* Teal Accent */
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* Glass Container */
        .wrapper {
            margin: 60px auto; padding: 40px; max-width: 1100px;
            background: rgba(255, 255, 255, 0.98); 
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 30px; border-bottom: 2px solid #f1f1f1; padding-bottom: 15px;
            flex-wrap: wrap; gap: 15px;
        }
        .page-header h2 { color: #343a40; font-weight: 700; margin: 0; font-size: 1.8rem; letter-spacing: 0.5px; }

        /* Control Panel Card */
        .control-panel {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.02);
        }
        .panel-title { font-size: 0.9rem; font-weight: 700; text-transform: uppercase; color: #6c757d; margin-bottom: 15px; letter-spacing: 1px; }

        /* Status Badges */
        .status-badge {
            padding: 6px 15px; border-radius: 50px; font-size: 0.8rem; font-weight: 700;
            display: inline-block; width: 90px; text-align: center; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .status-open { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-closed { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Custom Buttons */
        .btn-custom { border-radius: 50px; font-weight: 600; padding: 10px 25px; transition: all 0.3s; }
        
        .btn-open {
            background: linear-gradient(135deg, #28a745, #218838); color: white; border: none;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        .btn-open:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); color: white; }
        
        .btn-close-reg {
            background: linear-gradient(135deg, #dc3545, #c82333); color: white; border: none;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        .btn-close-reg:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4); color: white; }

        .btn-back {
            background: white; border: 2px solid #6c757d; color: #6c757d; border-radius: 50px; padding: 8px 25px; font-weight: 600;
            transition: all 0.3s;
        }
        .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }
        
        /* Copy Button Style */
        .btn-copy {
            background: #17a2b8; border: none; color: white; border-radius: 50px; padding: 8px 25px; font-weight: 600;
            transition: all 0.3s; box-shadow: 0 4px 10px rgba(23, 162, 184, 0.2);
        }
        .btn-copy:hover { background: #138496; color: white; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(23, 162, 184, 0.3); }

        .btn-toggle {
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: none; background: #e9ecef; color: #495057; transition: all 0.2s;
        }
        .btn-toggle:hover { background: #17a2b8; color: white; transform: rotate(180deg); }

        /* Table */
        .table-responsive { border-radius: 12px; overflow: hidden; background: white; border: 1px solid #e9ecef; }
        .table thead th {
            background-color: #17a2b8; color: white; border: none;
            text-transform: uppercase; font-size: 0.85rem; letter-spacing: 1px;
            vertical-align: middle; padding: 15px;
        }
        .table td { vertical-align: middle; padding: 12px 15px; }

        .filter-row { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-bottom: 15px; }

        /* Select2 Style Override */
        .select2-container .select2-selection--single { height: 42px !important; border-radius: 50px !important; padding-left: 10px; }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered { line-height: 40px !important; }
        
        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .btn-action-group { flex-direction: column; gap: 10px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-row select { width: 100%; }
            .header-buttons { display: flex; flex-direction: column; gap: 10px; width: 100%; }
            .header-buttons .btn { width: 100%; }
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
    
    <div style="position: fixed; top: 25px; right: 25px; z-index: 10000;">
        <div id="copyToast" class="toast hide shadow-lg" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000" style="min-width: 300px; border-radius: 10px; overflow: hidden;">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-check-circle mr-2"></i>
                <strong class="mr-auto">Success</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close" style="text-shadow: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body bg-white font-weight-bold text-dark">
                Student form link copied to clipboard!
            </div>
        </div>
    </div>

    <div class="container">
        <div class="wrapper">
            <div class="page-header">
                <div>
                    <h2><i class="fas fa-sliders-h text-info mr-2"></i>Form Access Controls</h2>
                    <p class="text-muted mb-0">Manage registration status for departments and sections.</p>
                </div>
                <div class="header-buttons">
                    <button type="button" class="btn btn-copy mr-2" onclick="copyFormUrl()">
                        <i class="fas fa-link mr-2"></i>Copy Form Link
                    </button>
                    <a href="dashboard.php" class="btn btn-back"><i class="fas fa-arrow-left mr-2"></i>Dashboard</a>
                </div>
            </div>

            <?php if(!empty($msg)): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm rounded-pill text-center px-4 mb-4" role="alert">
                    <i class="fas <?php echo ($msg_type == 'success') ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                    <?php echo $msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="control-panel">
                <div class="panel-title"><i class="fas fa-cog mr-2"></i>Bulk Actions</div>
                <form method="POST" class="form-row align-items-end">
                    <div class="col-lg-4 col-md-6 mb-3">
                        <label class="small font-weight-bold">Target Department</label>
                        <select name="target_dept" class="form-control select2-filter">
                            <option value="">All Departments</option>
                            <?php foreach($dept_options as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <label class="small font-weight-bold">Admission Year</label>
                        <select name="target_year" class="form-control rounded-pill">
                            <option value="">All Years</option>
                            <?php foreach($year_options as $y): ?>
                                <option value="<?php echo $y['id']; ?>">20<?php echo $y['year_val']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-5 col-md-12 mb-3">
                        <div class="d-flex btn-action-group">
                            <button type="submit" name="bulk_action" value="open" class="btn btn-custom btn-open flex-fill mr-2">
                                <i class="fas fa-lock-open mr-2"></i>Open Registration
                            </button>
                            <button type="submit" name="bulk_action" value="close" class="btn btn-custom btn-close-reg flex-fill">
                                <i class="fas fa-lock mr-2"></i>Close Registration
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="filter-row">
                <span class="font-weight-bold text-muted small text-uppercase mr-2 align-self-center"><i class="fas fa-filter mr-1"></i> Filter List:</span>
                <form method="GET" class="form-inline w-100 justify-content-end" id="filterForm">
                    <select name="f_dept" class="form-control form-control-sm mr-2 rounded-pill shadow-sm" style="min-width: 150px;" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach($dept_options as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($filter_d == $d['id'])?'selected':''; ?>>
                                <?php echo htmlspecialchars($d['dept_initials']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="f_year" class="form-control form-control-sm rounded-pill shadow-sm" style="min-width: 120px;" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php foreach($year_options as $y): ?>
                            <option value="<?php echo $y['id']; ?>" <?php echo ($filter_y == $y['id'])?'selected':''; ?>>
                                20<?php echo $y['year_val']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Year</th>
                            <th>Section</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($sections) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($sections)): ?>
                                <tr>
                                    <td>
                                        <span class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['dept_name']); ?></span>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($row['dept_initials']); ?></small>
                                    </td>
                                    <td><span class="badge badge-light border px-2 py-1">20<?php echo $row['year_val']; ?></span></td>
                                    <td><span class="badge badge-info px-2 py-1"><?php echo $row['section_name']; ?></span></td>
                                    <td class="text-center">
                                        <?php if($row['reg_open'] == 1): ?>
                                            <span class="status-badge status-open"><i class="fas fa-check-circle mr-1"></i>Open</span>
                                        <?php else: ?>
                                            <span class="status-badge status-closed"><i class="fas fa-times-circle mr-1"></i>Closed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="toggle_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="current_val" value="<?php echo $row['reg_open']; ?>">
                                            <button type="submit" class="btn-toggle shadow-sm" title="Toggle Status" data-toggle="tooltip">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 text-black-50"></i><br>
                                No sections found. Add them in <a href="departments.php">Master Data</a>.
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Loading animation script
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() {
                loader.style.display = 'none';
            }, 500);
        });
        
        // Copy to Clipboard Function
        function copyFormUrl() {
            var url = "<?php echo $student_form_url; ?>";
            
            // Modern Async Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function() {
                    $('#copyToast').toast('show');
                }, function(err) {
                    // Fail silently or fallback
                    console.error("Async: Could not copy text: ", err);
                    fallbackCopyTextToClipboard(url);
                });
            } else {
                // Fallback for older browsers or non-secure http
                fallbackCopyTextToClipboard(url);
            }
        }
        
        function fallbackCopyTextToClipboard(text) {
            var textArea = document.createElement("textarea");
            textArea.value = text;
            
            // Avoid scrolling to bottom
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    $('#copyToast').toast('show');
                } else {
                    alert('Unable to copy automatically. Please copy this URL manually:\n' + text);
                }
            } catch (err) {
                alert('Unable to copy automatically. Please copy this URL manually:\n' + text);
            }

            document.body.removeChild(textArea);
        }

        $(document).ready(function() {
            $('[data-toggle="tooltip"]').tooltip();
            
            // Initialize Select2 for nicer dropdowns
            $('.select2-filter').select2({
                theme: 'bootstrap4',
                width: '100%'
            });
        });
    </script>
</body>
</html>