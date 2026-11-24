<?php
// birthday_system01/trash_students.php

// 1. Initialize session
session_start();

// 2. Check login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// 3. Initialize Variables
$search_query = isset($_GET['search']) ? trim($_GET['search']) : "";
$filter_dept = isset($_GET['dept']) ? trim($_GET['dept']) : "";
$filter_year = isset($_GET['year']) ? trim($_GET['year']) : "";
$filter_sec = isset($_GET['sec']) ? trim($_GET['sec']) : "";

$trash_records = [];
$dept_options = [];
$sec_options = [];
$year_options = [];

// 4. Fetch Master Years
$sql_y = "SELECT year_val FROM years_master ORDER BY year_val DESC";
if ($res_y = mysqli_query($link, $sql_y)) {
    while ($row = mysqli_fetch_assoc($res_y)) {
        $year_options[] = $row['year_val'];
    }
}

// 5. DATA FETCHING LOOP (Aggregate data from all Year Tables)
// We loop through all years to find deleted students and populate filter options
foreach ($year_options as $yr) {
    
    // Determine table name
    $table_name = (function_exists('get_student_table')) ? get_student_table($yr) : "students_" . preg_replace('/\D/', '', $yr);
    
    // Skip if table doesn't exist
    $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
    if (mysqli_num_rows($check_table) == 0) continue;

    // A. Fetch Filter Options (Departments & Sections from deleted records)
    $opt_sql = "SELECT DISTINCT department, section FROM `$table_name` WHERE is_deleted = 1";
    if ($opt_res = mysqli_query($link, $opt_sql)) {
        while ($opt_row = mysqli_fetch_assoc($opt_res)) {
            if (!empty($opt_row['department'])) $dept_options[] = $opt_row['department'];
            if (!empty($opt_row['section'])) $sec_options[] = $opt_row['section'];
        }
    }

    // B. Fetch Trash Records (If Year filter matches or is empty)
    if (empty($filter_year) || $filter_year == $yr) {
        
        $sql = "SELECT *, '$yr' as batch_year FROM `$table_name` WHERE is_deleted = 1";
        $params = [];
        $types = "";

        // Apply Search
        if (!empty($search_query)) {
            $sql .= " AND (student_name LIKE ? OR student_custom_id LIKE ? OR student_mail_id LIKE ? OR department LIKE ? OR section LIKE ?)";
            $search_param = "%" . $search_query . "%";
            array_push($params, $search_param, $search_param, $search_param, $search_param, $search_param);
            $types .= "sssss";
        }

        // Apply Dept Filter
        if (!empty($filter_dept)) {
            $sql .= " AND department = ?";
            $params[] = $filter_dept;
            $types .= "s";
        }

        // Apply Section Filter
        if (!empty($filter_sec)) {
            $sql .= " AND section = ?";
            $params[] = $filter_sec;
            $types .= "s";
        }

        $sql .= " ORDER BY id DESC";

        // Execute
        if ($stmt = mysqli_prepare($link, $sql)) {
            if (!empty($params)) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                while ($row = mysqli_fetch_assoc($result)) {
                    $trash_records[] = $row;
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Unique and Sort Filter Options
$dept_options = array_unique($dept_options);
sort($dept_options);
$sec_options = array_unique($sec_options);
sort($sec_options);

// Display Messages
$msg = "";
$msg_type = "";
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg']['text'];
    $msg_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash Bin - Birthday System</title>
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: url('university.jpg') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-bottom: 50px;
        }

        /* =====================
           LOADER WITH LOGO
           ===================== */
        #loader {
            position: fixed; left: 0; top: 0; width: 100%; height: 100%; z-index: 9999;
            background-color: #fff; display: flex; justify-content: center; align-items: center;
            transition: opacity 0.5s ease;
        }

        .spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }

        .spinner {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            border: 8px solid #f3f3f3;
            border-top: 8px solid #dc3545; /* Red Accent for Trash Page */
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }

        .loader-logo { width: 45px; height: auto; z-index: 1; }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .wrapper { margin-top: 30px; margin-bottom: 50px; }
        
        .card-box {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 30px; border: 1px solid rgba(255,255,255,0.5);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px; flex-wrap: wrap; gap: 15px;
        }

        .page-header h2 { color: #dc3545; font-weight: 700; margin: 0; font-size: 1.75rem; }

        /* Filter Section */
        .filter-section {
            background-color: #f8f9fa; border-radius: 10px; padding: 20px;
            margin-bottom: 25px; border: 1px solid #e9ecef;
        }
        .filter-label { font-size: 0.85rem; font-weight: 600; color: #6c757d; margin-bottom: 5px; display: block; }

        /* Table & Scroll */
        .table-responsive { border-radius: 10px; overflow-x: auto; background: #fff; border: 1px solid #e9ecef; }
        .table thead th {
            background-color: #6c757d; color: #fff; border: none;
            text-transform: uppercase; font-size: 0.85rem; vertical-align: middle; white-space: nowrap;
        }
        .table td { vertical-align: middle; white-space: nowrap; }

        /* Buttons */
        .btn-back {
            border-radius: 30px; padding: 8px 20px; border: 2px solid #6c757d;
            background: transparent; color: #6c757d; font-weight: 600; transition: all 0.3s;
        }
        .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }

        .bulk-actions {
            margin-bottom: 15px; padding: 10px; background: #fff3cd; border-radius: 8px;
            border: 1px solid #ffeeba; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        .btn-restore { background-color: #28a745; color: white; border: none; font-weight: 600; }
        .btn-delete-perm { background-color: #dc3545; color: white; border: none; font-weight: 600; }
        
        .custom-checkbox { transform: scale(1.2); cursor: pointer; }

        /* Modal Styles */
        .modal-header-restore { background-color: #28a745; color: white; }
        .modal-header-delete { background-color: #dc3545; color: white; }
        .modal-icon { font-size: 3rem; opacity: 0.2; position: absolute; right: 20px; top: 20px; }

        .badge-batch { font-size: 0.75rem; background-color: #343a40; color: white; border-radius: 4px; padding: 2px 6px; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; text-align: center; }
            .bulk-actions { flex-direction: column; align-items: stretch; }
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

    <div class="container wrapper">
        <div class="card-box">
            <div class="page-header">
                <h2><i class="fas fa-trash-alt mr-2"></i>Trash Bin</h2>
                <a href="students.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back to Students</a>
            </div>

            <?php if(!empty($msg)): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="get" class="filter-section">
                <div class="form-row align-items-end">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <span class="filter-label">Search Keyword</span>
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Name, ID..." value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <span class="filter-label">Department</span>
                        <select name="dept" class="form-control form-control-sm">
                            <option value="">All Depts</option>
                            <?php foreach ($dept_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($filter_dept === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <span class="filter-label">Admission Year</span>
                        <select name="year" class="form-control form-control-sm">
                            <option value="">All Years</option>
                            <?php foreach ($year_options as $y): ?>
                                <option value="<?php echo htmlspecialchars($y); ?>" <?php echo ($filter_year === $y) ? 'selected' : ''; ?>>20<?php echo htmlspecialchars($y); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <span class="filter-label">Section</span>
                        <select name="sec" class="form-control form-control-sm">
                            <option value="">All Sections</option>
                            <?php foreach ($sec_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($filter_sec === $option) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-12 mb-3">
                        <button type="submit" class="btn btn-secondary btn-sm btn-block">Apply Filters</button>
                    </div>
                </div>
            </form>

            <form action="trash_action.php" method="POST" id="trashForm">
                <input type="hidden" name="action" id="formAction" value="">
                
                <div class="bulk-actions">
                    <span class="text-muted mr-auto"><i class="fas fa-info-circle"></i> Select students below to perform actions.</span>
                    <button type="button" class="btn btn-restore btn-sm" onclick="triggerRestore()">
                        <i class="fas fa-trash-restore mr-1"></i> Restore Selected
                    </button>
                    <button type="button" class="btn btn-delete-perm btn-sm" onclick="triggerDelete()">
                        <i class="fas fa-times-circle mr-1"></i> Delete Permanently
                    </button>
                </div>

                <?php if (!empty($trash_records)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style='width: 40px;' class='text-center'><input type='checkbox' id='selectAll' class='custom-checkbox'></th>
                                    <th>Batch</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Section</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($trash_records as $row): ?>
                                <tr>
                                    <td class='text-center'>
                                        <input type='checkbox' name='ids[]' value="<?php echo $row['id'] . '|' . $row['batch_year']; ?>" class='student-checkbox custom-checkbox'>
                                    </td>
                                    <td><span class="badge-batch">20<?php echo $row['batch_year']; ?></span></td>
                                    <td><?php echo htmlspecialchars($row['student_custom_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td><?php echo htmlspecialchars($row['section']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5"><h4 class="text-muted">Trash is Empty</h4><p class="text-muted">No deleted students matching your criteria were found across any year.</p></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="modal fade" id="restoreModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-restore">
                    <h5 class="modal-title"><i class="fas fa-trash-restore mr-2"></i>Confirm Restore</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <i class="fas fa-undo-alt modal-icon text-success"></i>
                    <p class="lead">Are you sure?</p>
                    <p>You are about to restore <strong id="restoreCount">0</strong> selected student(s) back to the active list.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitForm('restore')">Yes, Restore Them</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deletePermModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-delete">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-2"></i>Permanent Delete</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <i class="fas fa-times-circle modal-icon text-danger"></i>
                    <p class="lead text-danger font-weight-bold">Warning: This action is irreversible!</p>
                    <p>You are about to <strong>permanently delete</strong> <strong id="deleteCount">0</strong> selected student(s) from the database.</p>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="submitForm('delete_permanent')">Yes, Delete Forever</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="alertModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
            <div class="modal-content text-center">
                <div class="modal-body p-4">
                    <i class="fas fa-exclamation-circle fa-3x text-warning mb-3"></i>
                    <h5>No Selection</h5>
                    <p class="mb-0">Please select at least one student from the list.</p>
                </div>
                <div class="modal-footer justify-content-center p-2">
                    <button type="button" class="btn btn-primary btn-sm px-4" data-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        window.addEventListener('load', function() {
            var loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(function() { loader.style.display = 'none'; }, 500);
        });

        $('#selectAll').click(function() {
            $('.student-checkbox').prop('checked', this.checked);
        });

        function getCheckedCount() { return $('.student-checkbox:checked').length; }

        function triggerRestore() {
            var count = getCheckedCount();
            if (count === 0) { $('#alertModal').modal('show'); } 
            else { $('#restoreCount').text(count); $('#restoreModal').modal('show'); }
        }

        function triggerDelete() {
            var count = getCheckedCount();
            if (count === 0) { $('#alertModal').modal('show'); } 
            else { $('#deleteCount').text(count); $('#deletePermModal').modal('show'); }
        }

        function submitForm(actionType) {
            $('#formAction').val(actionType);
            $('#trashForm').submit();
        }
    </script>
</body>
</html>