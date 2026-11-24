<?php
// birthday_system01/students.php
date_default_timezone_set('Asia/Kolkata');

// Initialize the session
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Initialize Messages
$msg = "";
$msg_type = "";
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg']['text'];
    $msg_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}

// --- 1. FETCH MASTER DATA FOR FILTERS & LOGIC ---
$years_master = [];
$depts_master = [];
$all_sections_list = [];

// Fetch Years
$sql_y = "SELECT year_val FROM years_master ORDER BY year_val DESC";
if ($res_y = mysqli_query($link, $sql_y)) {
    while ($row = mysqli_fetch_assoc($res_y)) $years_master[] = $row['year_val'];
}

// Fetch Departments (Name and Initials)
$sql_d = "SELECT dept_name, dept_initials FROM departments_master ORDER BY dept_name ASC";
if ($res_d = mysqli_query($link, $sql_d)) {
    while ($row = mysqli_fetch_assoc($res_d)) {
        $depts_master[] = [
            'name' => $row['dept_name'],
            'initial' => $row['dept_initials']
        ];
    }
}

// Fetch All Unique Section Names (for Bulk Edit Datalist)
$sql_sec_unique = "SELECT DISTINCT section_name FROM sections_master ORDER BY section_name ASC";
if ($res_sec_u = mysqli_query($link, $sql_sec_unique)) {
    while ($row = mysqli_fetch_assoc($res_sec_u)) $all_sections_list[] = $row['section_name'];
}

// --- 2. DETERMINE FILTERS ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : "";
$filter_dept = isset($_GET['dept']) ? trim($_GET['dept']) : "";
$filter_sec = isset($_GET['sec']) ? trim($_GET['sec']) : "";

if ($filter_sec === 'all') $filter_sec = "";

// Year Logic
if (isset($_GET['year']) && !empty($_GET['year'])) {
    $filter_year = $_GET['year'];
} elseif (!empty($search_query)) {
    $filter_year = 'all';
} else {
    $filter_year = !empty($years_master) ? $years_master[0] : '';
}

// Check if any filter is explicitly active
$is_filter_active = (!empty($search_query) || !empty($filter_dept) || !empty($filter_sec) || isset($_GET['year']));

// --- 3. BUILD DYNAMIC SQL ---

$target_years = ($filter_year === 'all') ? $years_master : [$filter_year];
$union_queries = [];
$bind_params = [];
$bind_types = "";

foreach ($target_years as $yr) {
    if (empty($yr)) continue;
    
    $stu_table = "students_" . $yr;
    $log_table = "email_logs_" . $yr;

    // Verify table existence
    $check = mysqli_query($link, "SHOW TABLES LIKE '$stu_table'");
    if (mysqli_num_rows($check) == 0) continue;

    // Base Query for this Year
    // JOIN departments_master to get dept_initials for the table view
    $q = "SELECT s.*, dm.dept_initials, '$yr' as batch_year, el.last_sent 
          FROM `$stu_table` s 
          LEFT JOIN departments_master dm ON s.department = dm.dept_name
          LEFT JOIN (
            SELECT student_id, MAX(sent_at) as last_sent 
            FROM `$log_table` 
            GROUP BY student_id
          ) el ON s.id = el.student_id
          WHERE s.is_deleted = 0";

    // Apply Filters
    if (!empty($search_query)) {
        $q .= " AND (s.student_name LIKE ? OR s.student_custom_id LIKE ? OR s.student_mail_id LIKE ? OR s.department LIKE ? OR s.section LIKE ?)";
    }
    if (!empty($filter_dept)) {
        $q .= " AND s.department = '" . mysqli_real_escape_string($link, $filter_dept) . "'";
    }
    if (!empty($filter_sec)) {
        $q .= " AND s.section = '" . mysqli_real_escape_string($link, $filter_sec) . "'";
    }

    $union_queries[] = $q;
}

// Final Construction
$final_sql = "";
$final_params = [];
$final_types = "";

if (!empty($union_queries)) {
    $final_sql = implode(" UNION ALL ", $union_queries);
    $final_sql .= " ORDER BY student_custom_id ASC";
    
    // Show 50 students ONLY when no filters are added. Otherwise show ALL.
    if (!$is_filter_active) {
        $final_sql .= " LIMIT 50";
    }

    if (!empty($search_query)) {
        $search_str = "%" . $search_query . "%";
        foreach ($union_queries as $uq) {
            array_push($final_params, $search_str, $search_str, $search_str, $search_str, $search_str);
            $final_types .= "sssss";
        }
    }
} else {
    $final_sql = ""; 
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management - BWU Birthday Management System</title>
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

        /* LOADER */
        #loader {
            position: fixed; left: 0; top: 0; width: 100%; height: 100%;
            z-index: 9999; background-color: #fff;
            display: flex; justify-content: center; align-items: center;
            transition: opacity 0.5s ease;
        }
        .spinner-container { position: relative; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; }
        .spinner {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            border: 8px solid #f3f3f3; border-top: 8px solid #007bff;
            border-radius: 50%; animation: spin 1s linear infinite; z-index: 2;
        }
        .loader-logo { width: 45px; height: auto; z-index: 1; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        /* PAGE STYLES */
        .wrapper { margin-top: 30px; margin-bottom: 50px; }
        .card-box {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 30px; overflow: hidden; border: 1px solid rgba(255,255,255,0.5); margin-bottom: 20px;
        }
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px;
            flex-wrap: wrap; gap: 15px;
        }
        .page-header h2 { color: #2c3e50; font-weight: 700; margin: 0; font-size: 1.75rem; }

        .filter-section {
            background-color: #f8f9fa; border-radius: 10px; padding: 20px;
            margin-bottom: 25px; border: 1px solid #e9ecef;
        }
        .filter-label { font-size: 0.85rem; font-weight: 600; color: #6c757d; margin-bottom: 5px; display: block; }

        .table-responsive { border-radius: 10px; overflow-x: auto; background: #fff; border: 1px solid #e9ecef; }
        .table thead th {
            background-color: #007bff; color: #fff; border: none;
            text-transform: uppercase; font-size: 0.85rem; letter-spacing: 0.5px;
            vertical-align: middle; white-space: nowrap;
        }
        .table td { vertical-align: middle; font-size: 0.95rem; white-space: nowrap; }

        .action-btn {
            width: 32px; height: 32px; line-height: 32px; text-align: center;
            border-radius: 50%; display: inline-block; color: #fff;
            transition: transform 0.2s; margin: 0 2px; font-size: 0.8rem;
            border: none; outline: none; cursor: pointer;
        }
        .action-btn:hover { transform: scale(1.15); text-decoration: none; color: #fff; }
        .btn-edit { background-color: #ffc107; color: #212529; }
        .btn-delete { background-color: #dc3545; color: white;}

        /* Header Buttons */
        .btn-add {
            border-radius: 30px; padding: 8px 20px; font-weight: 600;
            background: linear-gradient(45deg, #11998e, #38ef7d); border: none; color: white;
            box-shadow: 0 4px 15px rgba(56, 239, 125, 0.4); transition: all 0.3s;
        }
        .btn-add:hover { background: linear-gradient(45deg, #38ef7d, #11998e); color: white; transform: translateY(-2px); }

        .btn-tool {
            border-radius: 30px; padding: 8px 15px; font-weight: 600; font-size: 0.9rem;
            border: none; color: white; transition: all 0.3s;
        }
        .btn-export { background: linear-gradient(45deg, #FF8008, #FFC837); box-shadow: 0 4px 10px rgba(255, 128, 8, 0.3); }
        .btn-export:hover { background: linear-gradient(45deg, #FFC837, #FF8008); color: white; transform: translateY(-2px); }
        
        .btn-import { background: linear-gradient(45deg, #833ab4, #fd1d1d); box-shadow: 0 4px 10px rgba(131, 58, 180, 0.3); }
        .btn-import:hover { background: linear-gradient(45deg, #fd1d1d, #833ab4); color: white; transform: translateY(-2px); }

        .btn-info-custom {
            border-radius: 30px; padding: 8px 20px; font-weight: 600;
            background: linear-gradient(45deg, #007bff, #00c6ff); border: none; color: white;
            box-shadow: 0 4px 15px rgba(0, 198, 255, 0.4); transition: all 0.3s;
        }
        .btn-info-custom:hover { background: linear-gradient(45deg, #00c6ff, #007bff); color: white; transform: translateY(-2px); text-decoration: none; }

        .btn-trash {
            border-radius: 30px; padding: 8px 20px; font-weight: 600;
            background: transparent; border: 2px solid #dc3545; color: #dc3545; transition: all 0.3s;
        }
        .btn-trash:hover { background: #dc3545; color: white; text-decoration: none; }

        .btn-back {
            border-radius: 30px; padding: 8px 20px; border: 2px solid #6c757d;
            background: transparent; color: #6c757d; font-weight: 600; transition: all 0.3s;
        }
        .btn-back:hover { background: #6c757d; color: white; text-decoration: none; }

        .header-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        
        .badge-dept { 
            background-color: #e3f2fd; color: #0d47a1; 
            font-weight: 700; padding: 5px 10px; border-radius: 4px; 
            letter-spacing: 0.5px;
        }
        
        .badge-year {
            background-color: #343a40; color: white; font-size: 0.8rem; padding: 4px 8px; border-radius: 4px;
        }

        /* Bulk Actions Toolbar */
        .bulk-actions-toolbar {
            display: none; /* Hidden by default */
            background-color: #e8f0fe;
            border: 1px solid #b3d7ff;
            color: #0c5460;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            align-items: center;
            justify-content: space-between;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .selected-count { font-weight: 700; margin-right: 15px; }

        /* Modal Styles */
        .modal-header-trash { background-color: #dc3545; color: white; }
        .modal-header-export { background: linear-gradient(45deg, #FF8008, #FFC837); color: white; }
        .modal-header-import { background: linear-gradient(45deg, #833ab4, #fd1d1d); color: white; }
        .modal-header-bulk-edit { background: linear-gradient(45deg, #11998e, #38ef7d); color: white; }
        .modal-header-review { background: linear-gradient(45deg, #f09819, #edde5d); color: #fff; }

        /* General Modal Header */
        .modal-header { border-top-left-radius: 4px; border-top-right-radius: 4px; border:none; }
        
        .modal-content {
            border: none; border-radius: 15px; overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }

        /* Review Table */
        .review-table-container { max-height: 300px; overflow-y: auto; border: 1px solid #ddd; }
        .table-review { font-size: 0.85rem; margin-bottom: 0; }
        .table-review th { position: sticky; top: 0; background: #f8f9fa; z-index: 1; }
        
        @media (max-width: 992px) {
            .page-header { flex-direction: column; text-align: center; }
            .header-buttons { width: 100%; display: flex; justify-content: center; }
        }
        @media (max-width: 768px) {
             .header-buttons { flex-direction: column; gap: 10px; }
             .btn-add, .btn-back, .btn-trash, .btn-info-custom, .btn-tool { width: 100%; }
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
            
            <?php if(!empty($msg)): ?>
                <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm" role="alert">
                    <?php echo $msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="page-header">
                <h2><i class="fas fa-user-graduate text-primary mr-2"></i>Student Details</h2>
                <div class="header-buttons">
                    <a href="dashboard.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Dashboard</a>
                    
                    <button type="button" class="btn btn-tool btn-export" data-toggle="modal" data-target="#exportModal">
                        <i class="fas fa-file-download mr-1"></i> Export
                    </button>
                    <button type="button" class="btn btn-tool btn-import" data-toggle="modal" data-target="#importModal">
                        <i class="fas fa-file-upload mr-1"></i> Import
                    </button>

                    <button type="button" class="btn btn-info-custom" data-toggle="modal" data-target="#testEmailModal">
                        <i class="fas fa-paper-plane"></i> Test Email
                    </button>
                    <a href="trash_students.php" class="btn btn-trash"><i class="fas fa-trash-alt"></i> Trash</a>
                    <a href="create_student.php" class="btn btn-add"><i class="fas fa-plus"></i> Add Student</a>
                </div>
            </div>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="get" class="filter-section">
                <div class="form-row align-items-end">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <span class="filter-label">Search Keyword</span>
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                            <input type="text" name="search" class="form-control" placeholder="Name, ID, Email..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <span class="filter-label">Admission Year</span>
                        <select name="year" class="form-control form-control-sm" id="filter-year-select">
                            <option value="all">All Years</option>
                            <?php foreach ($years_master as $y): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($filter_year == $y) ? 'selected' : ''; ?>>20<?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <span class="filter-label">Department</span>
                        <select name="dept" class="form-control form-control-sm" id="filter-dept-select">
                            <option value="">All Depts</option>
                            <?php foreach ($depts_master as $d): ?>
                                <?php 
                                    // Display format: (INITIAL) Department Name
                                    $display_name = "(" . $d['initial'] . ") " . $d['name'];
                                ?>
                                <option value="<?php echo htmlspecialchars($d['name']); ?>" <?php echo ($filter_dept === $d['name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-3">
                        <span class="filter-label">Section</span>
                        <select name="sec" class="form-control form-control-sm" id="filter-sec-select" data-selected="<?php echo htmlspecialchars($filter_sec); ?>">
                            <option value="">All Sections</option>
                            <?php if(!empty($filter_sec) && $filter_sec !== 'all') echo "<option value='".htmlspecialchars($filter_sec)."' selected>".htmlspecialchars($filter_sec)."</option>"; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-12 mb-3">
                        <button type="submit" class="btn btn-primary btn-sm btn-block font-weight-bold">Apply</button>
                        <?php if($is_filter_active): ?>
                            <a href="students.php" class="btn btn-outline-secondary btn-sm btn-block mt-2"><i class="fas fa-times mr-1"></i> Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="bulk-actions-toolbar" id="bulk-toolbar">
                <div class="d-flex align-items-center">
                    <span class="selected-count" id="selected-count-text">0 Selected</span>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-warning font-weight-bold mr-2 text-white" data-toggle="modal" data-target="#bulkEditModal" id="btn-bulk-edit">
                        <i class="fas fa-pencil-alt mr-1"></i> Bulk Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-danger font-weight-bold" data-toggle="modal" data-target="#bulkTrashModal" id="btn-bulk-trash">
                        <i class="fas fa-trash-alt mr-1"></i> Bulk Trash
                    </button>
                </div>
            </div>

            <?php if (!empty($final_sql)): ?>
                <?php
                if ($stmt = mysqli_prepare($link, $final_sql)) {
                    if (!empty($final_params)) {
                        mysqli_stmt_bind_param($stmt, $final_types, ...$final_params);
                    }
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $result = mysqli_stmt_get_result($stmt);
                        if (mysqli_num_rows($result) > 0) {
                            echo '<div class="table-responsive">';
                                echo '<table class="table table-striped table-hover mb-0">';
                                    echo "<thead><tr>";
                                    echo "<th style='width:40px' class='text-center'><input type='checkbox' id='select-all'></th>";
                                    echo "<th>Batch</th><th>ID</th><th>Name</th><th>Email</th><th>Dept</th><th>Sec</th><th>Last Email</th><th class='text-center'>Action</th>";
                                    echo "</tr></thead>";
                                    echo "<tbody>";
                                    while ($row = mysqli_fetch_array($result)) {
                                        $display_dept_full = $row['department'];
                                        // Use initials if available (from the JOIN), otherwise fallback to full name
                                        $display_dept_initial = !empty($row['dept_initials']) ? $row['dept_initials'] : $display_dept_full;

                                        $year_val = $row['batch_year'];
                                        // COMPOSITE ID for Bulk Action
                                        $composite_id = $row['id'] . '_' . $year_val;
                                        
                                        echo "<tr>";
                                            echo "<td class='text-center'><input type='checkbox' class='student-checkbox' name='selected_students[]' value='" . $composite_id . "'></td>";
                                            
                                            echo "<td><span class='badge badge-year'>20" . htmlspecialchars($year_val) . "</span></td>";
                                            echo "<td><strong>" . htmlspecialchars($row['student_custom_id']) . "</strong></td>";
                                            echo "<td>" . htmlspecialchars($row['student_name']) . "</td>";
                                            echo "<td><small>" . htmlspecialchars($row['student_mail_id']) . "</small></td>";
                                            // Display initials in the table cell, full name in tooltip
                                            echo "<td><span class='badge badge-dept' data-toggle='tooltip' title='" . htmlspecialchars($display_dept_full) . "'>" . htmlspecialchars($display_dept_initial) . "</span></td>";
                                            echo "<td>" . htmlspecialchars($row['section']) . "</td>";
                                            
                                            $last_sent_display = !empty($row['last_sent']) 
                                                ? '<span class="text-success" style="font-size:0.85em"><i class="fas fa-check-circle mr-1"></i>' . date('d-M-y', strtotime($row['last_sent'])) . '</span>' 
                                                : '<span class="text-muted" style="font-size:0.85em;">-</span>';
                                            
                                            echo "<td>" . $last_sent_display . "</td>";

                                            echo "<td class='text-center'>";
                                                echo '<a href="edit_student.php?id='. $row['id'] .'&year='. $year_val .'" class="action-btn btn-edit" title="Edit" data-toggle="tooltip"><i class="fas fa-pencil-alt"></i></a>';
                                                
                                                echo '<button type="button" class="action-btn btn-delete trash-trigger" ';
                                                echo 'data-toggle="modal" data-target="#trashModal" ';
                                                echo 'data-id="'. $row['id'] .'" ';
                                                echo 'data-year="'. $year_val .'" ';
                                                echo 'data-name="'. htmlspecialchars($row['student_name']) .'" ';
                                                echo 'data-customid="'. htmlspecialchars($row['student_custom_id']) .'" ';
                                                echo 'title="Move to Trash">';
                                                echo '<i class="fas fa-trash-alt"></i></button>';
                                                
                                            echo "</td>";
                                        echo "</tr>";
                                    }
                                    echo "</tbody>";
                                echo "</table>";
                            echo "</div>";
                        } else {
                            echo '<div class="text-center py-5"><i class="fas fa-search fa-3x text-muted mb-3" style="opacity: 0.3"></i><h4 class="text-muted">No students found</h4><p class="text-muted">Try changing the Year or Search terms.</p></div>';
                        }
                    } else {
                        echo '<div class="alert alert-danger">Error executing query: '.mysqli_error($link).'</div>';
                    }
                    mysqli_stmt_close($stmt);
                }
                ?>
            <?php else: ?>
                <div class="alert alert-warning text-center">No active year tables found. Please add a Year in Master Data.</div>
            <?php endif; ?>
            
            <?php mysqli_close($link); ?>
        </div>
    </div>

    <div class="modal fade" id="trashModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-trash">
                    <h5 class="modal-title" id="trashModalLabel">Move to Trash</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body position-relative">
                    <p class="mb-3">Are you sure you want to move this student to the trash bin? <br><small class="text-muted">You can restore this record later.</small></p>
                    <div class="student-details-box bg-light p-3 rounded">
                        <div class="row student-detail-row">
                            <div class="col-4 font-weight-bold">Name:</div>
                            <div class="col-8" id="modal-student-name"></div>
                        </div>
                        <div class="row student-detail-row">
                            <div class="col-4 font-weight-bold">ID:</div>
                            <div class="col-8" id="modal-student-id"></div>
                        </div>
                         <div class="row student-detail-row">
                            <div class="col-4 font-weight-bold">Batch:</div>
                            <div class="col-8">20<span id="modal-student-year"></span></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <form action="delete_student.php" method="POST">
                        <input type="hidden" name="id" id="modal-db-id">
                        <input type="hidden" name="year" id="modal-db-year">
                        <button type="submit" class="btn btn-danger">Yes, Move to Trash</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="bulkEditModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-bulk-edit">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-pencil-alt mr-2"></i>Bulk Edit Students</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form action="bulk_students_handler.php" method="POST" id="bulk-edit-form">
                        <input type="hidden" name="action" value="bulk_edit">
                        <div id="bulk-edit-ids-container"></div>

                        <p class="text-muted mb-3">Update properties for <span class="font-weight-bold text-primary bulk-modal-count">0</span> selected students.</p>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-muted small text-uppercase">New Department</label>
                            <select name="bulk_dept" class="form-control">
                                <option value="">No Change</option>
                                <?php foreach ($depts_master as $d) echo "<option value='" . htmlspecialchars($d['name']) . "'>" . htmlspecialchars($d['name']) . "</option>"; ?>
                            </select>
                            <small class="form-text text-muted">Leave empty to keep current department.</small>
                        </div>

                        <div class="form-group">
                            <label class="font-weight-bold text-muted small text-uppercase">New Section</label>
                            <input type="text" name="bulk_section" class="form-control" list="section_list" placeholder="Enter Section Name">
                            <datalist id="section_list">
                                <?php foreach($all_sections_list as $sec) echo "<option value='".htmlspecialchars($sec)."'>"; ?>
                            </datalist>
                            <small class="form-text text-muted">Leave empty to keep current section.</small>
                        </div>

                        <div class="alert alert-warning small"><i class="fas fa-exclamation-triangle mr-1"></i> Changes will apply to all selected students immediately.</div>

                        <button type="submit" class="btn btn-block btn-success font-weight-bold mt-3">
                            <i class="fas fa-save mr-1"></i> Update Students
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="bulkTrashModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-trash">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-trash-alt mr-2"></i>Bulk Move to Trash</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4 text-center">
                    <form action="bulk_students_handler.php" method="POST" id="bulk-trash-form">
                        <input type="hidden" name="action" value="bulk_trash">
                        <div id="bulk-trash-ids-container"></div>

                        <div class="mb-3 text-danger">
                            <i class="fas fa-exclamation-circle fa-3x"></i>
                        </div>
                        <h5 class="mb-3">Are you sure?</h5>
                        <p class="text-muted">You are about to move <span class="font-weight-bold text-dark bulk-modal-count">0</span> students to the trash.</p>
                        
                        <div class="row mt-4">
                            <div class="col-6">
                                <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Cancel</button>
                            </div>
                            <div class="col-6">
                                <button type="submit" class="btn btn-danger btn-block">Yes, Move to Trash</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="exportModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-export">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-file-download mr-2"></i>Export Students</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form action="export_students.php" method="POST">
                        <p class="text-muted mb-3">Select the target group to export to CSV.</p>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-muted small text-uppercase">Department</label>
                            <select name="dept" class="form-control modal-dept-select" id="export-dept" required>
                                <option value="">Select Department</option>
                                <option value="all">All Departments</option>
                                <?php foreach ($depts_master as $d) echo "<option value='" . htmlspecialchars($d['name']) . "'>" . htmlspecialchars($d['name']) . "</option>"; ?>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-muted small text-uppercase">Year</label>
                                <select name="year" class="form-control modal-year-select" id="export-year" required>
                                    <option value="">Select Year</option>
                                    <?php foreach ($years_master as $y) echo "<option value='$y'>20$y</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-muted small text-uppercase">Section</label>
                                <select name="section" class="form-control modal-section-select" id="export-sec" required>
                                    <option value="all">All Sections</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-block btn-warning font-weight-bold mt-3 text-white" style="background: linear-gradient(45deg, #FF8008, #FFC837);">
                            <i class="fas fa-download mr-1"></i> Download CSV
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-import">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-file-upload mr-2"></i>Import Students</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form action="import_students.php" method="POST" enctype="multipart/form-data" id="import-form">
                        <p class="text-muted mb-3">Select destination group and upload CSV file.</p>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-muted small text-uppercase">Department</label>
                            <select name="dept" class="form-control modal-dept-select" id="import-dept" required>
                                <option value="">Select Department</option>
                                <?php foreach ($depts_master as $d) echo "<option value='" . htmlspecialchars($d['name']) . "'>" . htmlspecialchars($d['name']) . "</option>"; ?>
                            </select>
                        </div>
                        
                        <div class="form-row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-muted small text-uppercase">Year</label>
                                <select name="year" class="form-control modal-year-select" id="import-year" required>
                                    <option value="">Select Year</option>
                                    <?php foreach ($years_master as $y) echo "<option value='$y'>20$y</option>"; ?>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold text-muted small text-uppercase">Section</label>
                                <select name="section" class="form-control modal-section-select" id="import-sec" disabled required>
                                    <option value="">Select Section</option>
                                </select>
                            </div>
                        </div>

                        <div class="text-right mb-2">
                             <a href="download_template.php" class="btn btn-sm btn-outline-dark" style="border-radius: 20px;">
                                <i class="fas fa-file-csv mr-1"></i> Download Template
                             </a>
                        </div>

                        <div class="form-group mt-2">
                            <label class="font-weight-bold text-muted small text-uppercase">CSV File</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="csv_file" id="csvFile" accept=".csv" required>
                                <label class="custom-file-label" for="csvFile">Choose file...</label>
                            </div>
                            <small class="form-text text-muted">
                                Columns: <strong>Student ID, Name, Phone, DOB (YYYY-MM-DD)</strong>.
                            </small>
                        </div>

                        <div class="custom-control custom-checkbox my-3 p-2 bg-light rounded border">
                            <input type="checkbox" class="custom-control-input" id="autoRedirectCheck" name="auto_redirect" value="1">
                            <label class="custom-control-label font-weight-bold text-primary" for="autoRedirectCheck">
                                <i class="fas fa-magic mr-1"></i> Auto-detect & Redirect
                            </label>
                            <div class="small text-muted mt-1" style="line-height: 1.2;">
                                If enabled, students not belonging to the selected department/year will be identified. You can review and redirect them to their correct tables.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-block btn-danger font-weight-bold mt-4" style="background: linear-gradient(45deg, #833ab4, #fd1d1d); border:none;">
                            <i class="fas fa-upload mr-1"></i> Upload & Import
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="importReviewModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header modal-header-review">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-tasks mr-2"></i>Review Mismatched Data</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle mr-1"></i> The following <strong><span id="review-count">0</span></strong> students do not belong to your selected Department/Year based on their IDs.
                    </div>
                    
                    <div class="review-table-container mb-3">
                        <table class="table table-sm table-bordered table-review">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Selected Group</th>
                                    <th class="bg-light text-primary">Correct Table (Detected)</th>
                                </tr>
                            </thead>
                            <tbody id="review-table-body">
                                </tbody>
                        </table>
                    </div>

                    <p class="text-muted small">
                        Click "Accept & Import All" to import these students into their correct detected tables, and the rest into the selected table.<br>
                        Click "Cancel" to abort the entire import.
                    </p>

                    <div class="row">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-secondary btn-block" data-dismiss="modal">Cancel Import</button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-success btn-block font-weight-bold" id="btn-confirm-import">
                                <i class="fas fa-check-circle mr-1"></i> Accept & Import All
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="testEmailModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content modal-content-email">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white;">
                    <h5 class="modal-title"><i class="fas fa-paper-plane mr-2"></i>Test Birthday Wish</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4">
                    <form id="test-email-form">
                        <div class="form-group">
                            <label>Recipient Name</label>
                            <input type="text" id="test-name" class="form-control" placeholder="Enter Name">
                        </div>
                        <div class="form-group">
                            <label>Recipient Email</label>
                            <input type="email" id="test-email" class="form-control" placeholder="Enter Email Address">
                        </div>
                        <button type="button" class="btn btn-success btn-block mt-4" id="btn-send-email">Send Test Email</button>
                    </form>
                    <div id="send-msg" class="mt-3 text-center font-weight-bold"></div>
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

        $(document).ready(function(){ 
            $('[data-toggle="tooltip"]').tooltip(); 
            
            // Custom File Input Label
            $(".custom-file-input").on("change", function() {
                var fileName = $(this).val().split("\\").pop();
                $(this).siblings(".custom-file-label").addClass("selected").html(fileName);
            });

            // Single Trash Modal Logic
            $('.trash-trigger').click(function() {
                $('#modal-db-id').val($(this).data('id'));
                $('#modal-db-year').val($(this).data('year'));
                $('#modal-student-year').text($(this).data('year'));
                $('#modal-student-name').text($(this).data('name'));
                $('#modal-student-id').text($(this).data('customid'));
            });

            // --- BULK ACTION LOGIC ---
            function updateBulkToolbar() {
                var checkedCount = $('.student-checkbox:checked').length;
                if(checkedCount > 0) {
                    $('#bulk-toolbar').css('display', 'flex'); // Show flexbox
                    $('#selected-count-text').text(checkedCount + ' Selected');
                } else {
                    $('#bulk-toolbar').hide();
                }
            }

            // Select All Checkbox
            $('#select-all').click(function() {
                var isChecked = $(this).prop('checked');
                $('.student-checkbox').prop('checked', isChecked);
                updateBulkToolbar();
            });

            // Individual Checkbox Click
            $(document).on('change', '.student-checkbox', function() {
                updateBulkToolbar();
                // If one unchecked, uncheck "Select All"
                if (!$(this).prop('checked')) {
                    $('#select-all').prop('checked', false);
                }
            });

            // Populate Hidden Inputs for Modals
            function populateBulkModal(containerId) {
                var container = $(containerId);
                container.empty();
                var count = 0;
                $('.student-checkbox:checked').each(function() {
                    count++;
                    var val = $(this).val();
                    // Append hidden input for each selected student
                    container.append('<input type="hidden" name="selected_students[]" value="'+val+'">');
                });
                $('.bulk-modal-count').text(count);
            }

            $('#btn-bulk-edit').click(function() {
                populateBulkModal('#bulk-edit-ids-container');
            });

            $('#btn-bulk-trash').click(function() {
                populateBulkModal('#bulk-trash-ids-container');
            });


            /**
             * Dynamically load sections based on Dept & Year.
             */
            function loadModalSections(deptSelect, yearSelect, targetSecSelect, isExport, preSelectedValue = null) {
                var dept = deptSelect.val();
                var year = yearSelect.val();
                var secSelect = targetSecSelect;

                if (dept === 'all' || year === 'all' || year === '' || !dept || !year) {
                    var defaultOpts = '<option value="">' + (isExport ? 'All Sections' : 'All Sections') + '</option>';
                    if(isExport) defaultOpts = '<option value="all">All Sections</option>';
                    secSelect.empty().append(defaultOpts);
                    secSelect.prop('disabled', (isExport ? false : true));
                    return;
                }

                secSelect.prop('disabled', true).html('<option>Loading...</option>');
                
                $.ajax({
                    url: 'get_sections.php',
                    type: 'POST',
                    data: { dept_name: dept, year_val: year },
                    dataType: 'json',
                    success: function(data) {
                        secSelect.empty().append('<option value="">' + (isExport ? 'Select Section' : 'All Sections') + '</option>');
                        if (isExport) secSelect.append('<option value="all">All Sections</option>');

                        if (data.length > 0) {
                            $.each(data, function(i, val) {
                                var isSel = (preSelectedValue && preSelectedValue == val) ? 'selected' : '';
                                secSelect.append('<option value="'+val+'" '+isSel+'>'+val+'</option>');
                            });
                        } else if (!isExport) {
                            secSelect.append('<option value="">No sections found</option>');
                        }
                        secSelect.prop('disabled', false);
                    },
                    error: function() {
                        secSelect.html('<option>Error loading</option>').prop('disabled', false);
                    }
                });
            }

            // Bind Events for Modals
            $('#export-dept, #export-year').change(function() {
                loadModalSections($('#export-dept'), $('#export-year'), $('#export-sec'), true);
            });
            $('#import-dept, #import-year').change(function() {
                loadModalSections($('#import-dept'), $('#import-year'), $('#import-sec'), false);
            });
            
            // Bind Events for Main Search Filter
            $('#filter-dept-select, #filter-year-select').change(function() {
                loadModalSections($('#filter-dept-select'), $('#filter-year-select'), $('#filter-sec-select'), false);
            });

            // Initial Load for Filter Section (Preserve Selected Value)
            var preSelectedSec = $('#filter-sec-select').data('selected');
            if($('#filter-dept-select').val() && $('#filter-year-select').val() !== 'all') {
                 loadModalSections($('#filter-dept-select'), $('#filter-year-select'), $('#filter-sec-select'), false, preSelectedSec);
            }

            // Test Email Logic
            $('#btn-send-email').click(function() {
                var name = $('#test-name').val().trim();
                var email = $('#test-email').val().trim();
                var btn = $(this);
                if(name === "" || email === "") {
                    $('#send-msg').css('color','red').text("Fill all fields."); return;
                }
                var originalText = btn.html();
                btn.html('Sending...').prop('disabled', true);
                $.ajax({
                    url: 'test_email_handler.php',
                    type: 'POST',
                    data: { action: 'send_email', name: name, email: email },
                    dataType: 'json',
                    success: function(response) {
                        $('#send-msg').css('color', response.success ? 'green' : 'red').html(response.message);
                        btn.html(originalText).prop('disabled', false);
                    },
                    error: function() {
                        $('#send-msg').css('color', 'red').text("Server Error.");
                        btn.html(originalText).prop('disabled', false);
                    }
                });
            });

            // --- SMART IMPORT LOGIC ---
            var importFormData = null; // To store data between preview and confirm

            $('#import-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var formData = new FormData(this);
                var autoRedirect = $('#autoRedirectCheck').is(':checked');
                
                // Mode: If auto-redirect is checked, we first PREVIEW. If not, we IMPORT (matches only).
                var action = autoRedirect ? 'preview' : 'import';
                formData.append('action', action);
                
                // Show Loader
                $('#loader').show().css('opacity', '1');
                $('#importModal').modal('hide');

                $.ajax({
                    url: 'import_students.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        $('#loader').hide();

                        if (response.status === 'review_required') {
                            // SHOW REVIEW MODAL
                            importFormData = formData; // Store for confirmation
                            
                            var tbody = $('#review-table-body');
                            tbody.empty();
                            $('#review-count').text(response.mismatches.length);
                            
                            $.each(response.mismatches, function(i, item) {
                                var row = '<tr>' + 
                                    '<td><strong>' + item.id + '</strong></td>' +
                                    '<td>' + item.name + '</td>' +
                                    '<td class="text-muted"><small>' + item.selected + '</small></td>' +
                                    '<td class="font-weight-bold text-primary">' + item.target + '</td>' +
                                    '</tr>';
                                tbody.append(row);
                            });

                            $('#importReviewModal').modal('show');
                        
                        } else if (response.status === 'success') {
                            // Success - Reload to show flash message
                            window.location.reload();
                        } else {
                            // Error
                            alert(response.message || "An error occurred.");
                            $('#importModal').modal('show');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loader').hide();
                        console.error(xhr.responseText);
                        alert("Server communication error. Please try again.");
                    }
                });
            });

            // Confirm Import in Review Modal
            $('#btn-confirm-import').click(function() {
                if (!importFormData) return;

                // Update action to 'import' and add confirmation flag
                importFormData.set('action', 'import');
                importFormData.append('confirm_redirect', '1');

                $('#importReviewModal').modal('hide');
                $('#loader').show().css('opacity', '1');

                $.ajax({
                    url: 'import_students.php',
                    type: 'POST',
                    data: importFormData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                         window.location.reload();
                    },
                    error: function(xhr) {
                        $('#loader').hide();
                        alert("Error during final import.");
                    }
                });
            });
        });
    </script>
</body>
</html>