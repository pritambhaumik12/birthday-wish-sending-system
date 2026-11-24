<?php
// birthday_system01/backup_action.php

session_start();
require_once "config.php";

// 1. Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    if(isset($_POST['action'])) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("location: index.php");
    exit;
}

// --------------------------------------------------------
// AJAX HANDLER: COUNT STUDENTS
// --------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'count_students') {
    $filter_year = $_POST['filter_year'] ?? 'all';
    $filter_dept = $_POST['filter_dept'] ?? 'all';
    $filter_section = $_POST['filter_section'] ?? 'all';

    // 1. Resolve Tables
    $target_tables = [];
    if ($filter_year !== 'all') {
        $clean_year = preg_replace('/\D/', '', $filter_year);
        $tbl = "students_" . $clean_year;
        // Verify existence
        $check = mysqli_query($link, "SHOW TABLES LIKE '$tbl'");
        if (mysqli_num_rows($check) > 0) $target_tables[] = $tbl;
    } else {
        $res = mysqli_query($link, "SHOW TABLES LIKE 'students_%'");
        while ($row = mysqli_fetch_row($res)) $target_tables[] = $row[0];
    }

    // 2. Build Query
    $conditions = ["is_deleted = 0"];
    
    // Resolve Dept Name from ID if needed
    if ($filter_dept !== 'all') {
        $dept_name = "";
        $stmt_d = mysqli_prepare($link, "SELECT dept_name FROM departments_master WHERE id = ?");
        mysqli_stmt_bind_param($stmt_d, "i", $filter_dept);
        mysqli_stmt_execute($stmt_d);
        mysqli_stmt_bind_result($stmt_d, $d_name);
        if(mysqli_stmt_fetch($stmt_d)) $dept_name = $d_name;
        mysqli_stmt_close($stmt_d);
        
        if(!empty($dept_name)) {
            $conditions[] = "department = '" . mysqli_real_escape_string($link, $dept_name) . "'";
        }
    }

    if ($filter_section !== 'all') {
        $conditions[] = "section = '" . mysqli_real_escape_string($link, $filter_section) . "'";
    }

    $where_clause = implode(" AND ", $conditions);
    $total_count = 0;

    foreach ($target_tables as $table) {
        $q = "SELECT COUNT(*) as c FROM `$table` WHERE $where_clause";
        if ($res = mysqli_query($link, $q)) {
            $row = mysqli_fetch_assoc($res);
            $total_count += $row['c'];
        }
    }

    echo json_encode(['status' => 'success', 'count' => number_format($total_count)]);
    exit;
}


// --------------------------------------------------------
// BACKUP LOGIC
// --------------------------------------------------------

// Ensure backup directory exists
$backup_dir = __DIR__ . '/backups/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Protect against directory traversal for download
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backup_dir . $file;

    if (file_exists($filepath)) {
        // Serve file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        $_SESSION['flash_notification'] = ['type' => 'danger', 'message' => 'Backup file not found or expired.'];
        header("location: dashboard.php");
        exit;
    }
}

// Handle Backup Generation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'create_backup') {
    
    set_time_limit(300); 
    ini_set('memory_limit', '512M');

    $scope = $_POST['backup_scope']; 
    $type = $_POST['backup_type'];   
    $include_files = isset($_POST['include_files']) ? true : false;
    
    // Filters
    $filter_year = $_POST['filter_year'] ?? 'all';
    $filter_dept = $_POST['filter_dept'] ?? 'all';
    $filter_section = $_POST['filter_section'] ?? 'all';
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename_base = "backup_{$scope}_{$timestamp}";
    $generated_file = "";

    // -- HELPER: Array to CSV String --
    function array2csv(array &$array) {
        if (count($array) == 0) return null;
        ob_start();
        $df = fopen("php://output", 'w');
        fputcsv($df, array_keys(reset($array)));
        foreach ($array as $row) fputcsv($df, $row);
        fclose($df);
        return ob_get_clean();
    }

    // -- HELPER: SQL Dump with WHERE --
    function get_table_sql($link, $table, $where = "") {
        $content = "DROP TABLE IF EXISTS `$table`;\n";
        $res = mysqli_query($link, "SHOW CREATE TABLE `$table`");
        $row = mysqli_fetch_row($res);
        $content .= $row[1] . ";\n\n";

        $query = "SELECT * FROM `$table`";
        if (!empty($where)) {
            $query .= " WHERE " . $where;
        }

        $res = mysqli_query($link, $query);
        if (mysqli_num_rows($res) > 0) {
            $content .= "INSERT INTO `$table` VALUES ";
            $rows = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $vals = [];
                foreach ($row as $val) {
                    $vals[] = is_null($val) ? "NULL" : "'" . mysqli_real_escape_string($link, $val) . "'";
                }
                $rows[] = "(" . implode(", ", $vals) . ")";
            }
            $content .= implode(",\n", $rows) . ";\n\n";
        }
        return $content;
    }

    // 1. GATHER DATA & APPLY FILTERS
    $tables_to_process = [];
    $student_where_clause = "";

    if ($scope === 'all') {
        $tables_req = mysqli_query($link, "SHOW TABLES");
        while ($t = mysqli_fetch_row($tables_req)) $tables_to_process[] = $t[0];
    } elseif ($scope === 'admins') {
        $tables_to_process[] = 'users';
    } elseif ($scope === 'wishes') {
        $tables_to_process[] = 'wishes';
    } elseif ($scope === 'students') {
        
        // Resolve Dept Name for filtering
        $filter_dept_name = "";
        if($filter_dept !== 'all') {
            $stmt = mysqli_prepare($link, "SELECT dept_name FROM departments_master WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $filter_dept);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $dn);
            if(mysqli_stmt_fetch($stmt)) $filter_dept_name = $dn;
            mysqli_stmt_close($stmt);
        }

        // Build WHERE Clause
        $conds = ["is_deleted = 0"]; // Always exclude deleted
        if(!empty($filter_dept_name)) $conds[] = "department = '" . mysqli_real_escape_string($link, $filter_dept_name) . "'";
        if($filter_section !== 'all') $conds[] = "section = '" . mysqli_real_escape_string($link, $filter_section) . "'";
        $student_where_clause = implode(" AND ", $conds);

        // Determine Tables
        if ($filter_year !== 'all') {
            $tables_to_process[] = "students_" . preg_replace('/\D/', '', $filter_year);
        } else {
            $tables_req = mysqli_query($link, "SHOW TABLES LIKE 'students_%'");
            while ($t = mysqli_fetch_row($tables_req)) $tables_to_process[] = $t[0];
        }
    }

    // 2. GENERATE CONTENT (SQL or CSV)
    $sql_content = "";
    $csv_files = []; // [filename => content]

    if ($type === 'sql') {
        $sql_content .= "-- BWU Birthday System Backup\n-- Generated: $timestamp\n-- Scope: $scope\n\n";
        foreach ($tables_to_process as $tbl) {
            // Apply student filter only to student tables
            $current_where = (strpos($tbl, 'students_') === 0 && !empty($student_where_clause)) ? $student_where_clause : "";
            $sql_content .= get_table_sql($link, $tbl, $current_where);
        }
    } else {
        // CSV
        foreach ($tables_to_process as $tbl) {
            $data = [];
            $q = "SELECT * FROM `$tbl`";
            // Apply student filter
            if (strpos($tbl, 'students_') === 0 && !empty($student_where_clause)) {
                $q .= " WHERE " . $student_where_clause;
            }
            
            $res = mysqli_query($link, $q);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $data[] = $row;
            }
            if (!empty($data)) {
                $csv_files["$tbl.csv"] = array2csv($data);
            }
        }
    }

    // 3. PACKAGE (ZIP or RAW)
    $use_zip = ($include_files || $scope === 'all' || count($csv_files) > 1);

    if ($use_zip) {
        $zip_filename = $filename_base . ".zip";
        $zip_path = $backup_dir . $zip_filename;
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
            die("Cannot open <$zip_path>\n");
        }

        // Add Data
        if ($type === 'sql') {
            $zip->addFromString("database_dump.sql", $sql_content);
        } else {
            foreach ($csv_files as $name => $content) {
                $zip->addFromString("data/$name", $content);
            }
        }

        // Add Uploads if requested
        if ($include_files || $scope === 'all') {
            $rootPath = realpath('uploads/');
            if ($rootPath && is_dir($rootPath)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rootPath),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = 'uploads/' . substr($filePath, strlen($rootPath) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }
        }
        $zip->close();
        $generated_file = $zip_filename;

    } else {
        // Single File Download (SQL or Single CSV)
        if ($type === 'sql') {
            $generated_file = $filename_base . ".sql";
            file_put_contents($backup_dir . $generated_file, $sql_content);
        } else {
            // Single CSV
            $keys = array_keys($csv_files);
            if(!empty($keys)) {
                $generated_file = $filename_base . "_" . $keys[0]; 
                file_put_contents($backup_dir . $generated_file, array_values($csv_files)[0]);
            } else {
                // Handle case where query returned empty (e.g. strict filter)
                 $_SESSION['flash_notification'] = ['type' => 'warning', 'message' => 'No records found matching filters.'];
                 header("location: dashboard.php");
                 exit;
            }
        }
    }

    // 4. LOGGING (Update System Settings)
    $user_name = $_SESSION["full_name"] ?? "Admin";
    $log_time = date('Y-m-d H:i:s');
    
    $keys = ['last_backup_time', 'last_backup_by'];
    foreach($keys as $k) {
        $check = mysqli_query($link, "SELECT setting_key FROM system_settings WHERE setting_key = '$k'");
        if(mysqli_num_rows($check) == 0) {
            mysqli_query($link, "INSERT INTO system_settings (setting_key, setting_value) VALUES ('$k', '')");
        }
    }
    
    $stmt = mysqli_prepare($link, "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'last_backup_time'");
    mysqli_stmt_bind_param($stmt, "s", $log_time);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($link, "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'last_backup_by'");
    mysqli_stmt_bind_param($stmt, "s", $user_name);
    mysqli_stmt_execute($stmt);

    // 5. PRG - Redirect to Dashboard with Download Trigger
    $_SESSION['flash_notification'] = ['type' => 'success', 'message' => 'Backup generated successfully! Downloading...'];
    $_SESSION['auto_download'] = $generated_file;
    
    header("location: dashboard.php");
    exit;
}
?>