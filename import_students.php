<?php
// birthday_system01/import_students.php

// 1. Initialize session
session_start();

// 2. Check login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 3. Include config
require_once "config.php";

header('Content-Type: application/json');

// Helper function to find dept name from map
function getDeptNameFromInitial($initial, $map) {
    $initial = strtoupper(trim($initial));
    return isset($map[$initial]) ? $map[$initial] : false;
}

// 5. Fetch Department Map (Initials -> Full Name)
$dept_map = [];
$dept_sql = "SELECT dept_name, dept_initials FROM departments_master";
if ($dept_res = mysqli_query($link, $dept_sql)) {
    while ($d_row = mysqli_fetch_assoc($dept_res)) {
        if (!empty($d_row['dept_initials'])) {
            $dept_map[strtoupper(trim($d_row['dept_initials']))] = $d_row['dept_name'];
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $action = $_POST['action'] ?? 'import'; // 'preview' or 'import'
    $confirm_redirect = isset($_POST['confirm_redirect']) ? true : false;
    $auto_redirect_check = isset($_POST['auto_redirect']) ? true : false;

    // Collect Form Inputs
    $selected_dept = trim($_POST['dept'] ?? '');
    $selected_year_val = trim($_POST['year'] ?? '');
    $section = trim($_POST['section'] ?? '');

    if (empty($selected_dept) || empty($selected_year_val) || empty($section)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select Department, Year, and Section.']);
        exit;
    }

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        
        $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
        if ($handle === FALSE) {
            echo json_encode(['status' => 'error', 'message' => 'Could not read file.']);
            exit;
        }

        fgetcsv($handle); // Skip Header

        $valid_rows = [];
        $mismatch_rows = [];
        $error_count = 0;
        $row_count = 0;

        // --- PHASE 1: PARSE & IDENTIFY ---
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_count++;
            
            // CSV Mapping
            $csv_id    = strtoupper(trim($data[0] ?? ''));
            $csv_name  = trim($data[1] ?? '');
            $csv_phone = preg_replace('/[^0-9]/', '', trim($data[2] ?? ''));
            $csv_dob   = trim($data[3] ?? '');
            $csv_type  = strtolower(trim($data[4] ?? '')); // Mail Type: 'university' or 'personal'
            $csv_email = trim($data[5] ?? '');             // Email Value

            if (empty($csv_id) || empty($csv_name)) continue;

            // DETERMINE EMAIL LOGIC PER ROW
            $final_email = "";
            
            if ($csv_type === 'personal') {
                // Must have valid email in Column 5
                if (empty($csv_email) || !filter_var($csv_email, FILTER_VALIDATE_EMAIL)) {
                    $error_count++; continue; // Skip invalid personal emails
                }
                $final_email = $csv_email;
            } else {
                // Default to University (Auto-generate)
                // Ignores whatever is in Column 5
                $clean_id = strtolower(str_replace('/', '', $csv_id));
                $final_email = $clean_id . "@brainwareuniversity.ac.in";
            }

            // Parse ID: BWU/BTS/24/101
            $id_parts = explode('/', $csv_id);
            if (count($id_parts) < 3) { $error_count++; continue; }

            $row_dept_initial = strtoupper($id_parts[1] ?? '');
            $row_year_val = $id_parts[2] ?? '';

            // Resolve Dept
            $target_dept_name = getDeptNameFromInitial($row_dept_initial, $dept_map);
            if (!$target_dept_name) { $error_count++; continue; }

            // Resolve Year & Table
            if (!preg_match('/^\d{2}$/', $row_year_val)) { $error_count++; continue; }
            
            // Check Mismatch
            $is_mismatch = ($target_dept_name !== $selected_dept) || ($row_year_val !== $selected_year_val);
            
            $row_data = [
                'id' => $csv_id, 
                'name' => $csv_name, 
                'phone' => $csv_phone, 
                'dob' => $csv_dob,
                'email' => $final_email, // Calculated above
                'target_dept' => $target_dept_name,
                'target_year' => $row_year_val,
                'section' => $section 
            ];

            if ($is_mismatch) {
                $mismatch_rows[] = $row_data;
            } else {
                $valid_rows[] = $row_data;
            }
        }
        fclose($handle);

        // --- PHASE 2: HANDLE ACTION ---

        // A. PREVIEW MODE
        if ($action === 'preview') {
            if (count($mismatch_rows) > 0) {
                // Return mismatched data for user review
                $preview_data = [];
                foreach($mismatch_rows as $r) {
                    $preview_data[] = [
                        'id' => $r['id'],
                        'name' => $r['name'],
                        'selected' => $selected_dept . " (" . $selected_year_val . ")",
                        'target' => $r['target_dept'] . " (" . $r['target_year'] . ")"
                    ];
                }
                echo json_encode(['status' => 'review_required', 'mismatches' => $preview_data]);
                exit;
            } else {
                $action = 'import'; 
            }
        }

        // B. IMPORT MODE
        if ($action === 'import') {
            
            $stmts = [];
            $valid_tables = [];
            $imported_count = 0;
            $redirected_count = 0;

            // 1. Process VALID rows (Matches)
            foreach ($valid_rows as $row) {
                if(insertStudent($link, $row, $row['target_year'], $stmts, $valid_tables)) {
                    $imported_count++;
                }
            }

            // 2. Process MISMATCH rows if confirmed
            if ($confirm_redirect || ($auto_redirect_check && count($mismatch_rows) == 0)) {
                foreach ($mismatch_rows as $row) {
                    if(insertStudent($link, $row, $row['target_year'], $stmts, $valid_tables)) {
                        $imported_count++;
                        $redirected_count++;
                    }
                }
            }

            // Close Stmts
            foreach ($stmts as $s) mysqli_stmt_close($s);

            // Message
            $msg_type = ($imported_count > 0) ? 'success' : 'warning';
            $msg_text = "Imported <b>$imported_count</b> students.";
            if ($redirected_count > 0) $msg_text .= " ($redirected_count auto-redirected to correct tables).";
            if (count($mismatch_rows) > 0 && !$confirm_redirect) $msg_text .= " Skipped " . count($mismatch_rows) . " mismatched students.";
            if ($error_count > 0) $msg_text .= " Failed/Skipped <b>$error_count</b> rows (Invalid Format or Email).";
            
            $_SESSION['flash_msg'] = ['text' => $msg_text, 'type' => $msg_type];
            echo json_encode(['status' => 'success']);
            exit;
        }

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Upload error.']);
    }
}

// Helper Function for Insertion
function insertStudent($link, $data, $year, &$stmts, &$valid_tables) {
    // 1. Determine Table
    if (isset($valid_tables[$year])) {
        $table = $valid_tables[$year];
    } else {
        $table = "students_" . $year;
        $check = mysqli_query($link, "SHOW TABLES LIKE '$table'");
        if (mysqli_num_rows($check) > 0) {
            $valid_tables[$year] = $table;
        } else {
            return false; // Table doesn't exist
        }
    }

    // 2. Prepare Stmt
    if (!isset($stmts[$table])) {
        $sql = "INSERT INTO `$table` (student_custom_id, student_name, student_mail_id, country_code, student_phone, date_of_birth, section, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($link, $sql)) {
            $stmts[$table] = $stmt;
        } else {
            return false;
        }
    }

    // 3. Bind & Execute
    // Email is now pre-calculated in $data['email']
    $email = $data['email']; 
    $cc = "+91";
    $dob = !empty($data['dob']) ? $data['dob'] : date('Y-m-d');

    mysqli_stmt_bind_param($stmts[$table], "ssssssss", 
        $data['id'], $data['name'], $email, $cc, $data['phone'], $dob, $data['section'], $data['target_dept']
    );

    try {
        return mysqli_stmt_execute($stmts[$table]);
    } catch (Exception $e) {
        return false; // Duplicate or error
    }
}
?>