<?php
// birthday_system01/export_students.php

// 1. Initialize session
session_start();

// 2. Check login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// 3. Include config
require_once "config.php";

// 4. Handle POST Request
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Collect Inputs
    $year_val = isset($_POST['year']) ? trim($_POST['year']) : '';
    $dept = isset($_POST['dept']) ? trim($_POST['dept']) : '';
    $section = isset($_POST['section']) ? trim($_POST['section']) : '';

    // Validate Year (Required to pick a table)
    if (empty($year_val)) {
        $_SESSION['flash_msg'] = ['text' => "Please select an Admission Year to export.", 'type' => 'warning'];
        header("location: students.php");
        exit;
    }

    // Determine Table Name
    $table_name = (function_exists('get_student_table')) ? get_student_table($year_val) : "students_" . preg_replace('/\D/', '', $year_val);

    // Verify Table Exists
    $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
    if (mysqli_num_rows($check_table) == 0) {
        $_SESSION['flash_msg'] = ['text' => "Error: Database table for Year 20$year_val not found.", 'type' => 'danger'];
        header("location: students.php");
        exit;
    }

    // --- CSV HEADERS ---
    $filename = "students_export_20" . $year_val;
    if ($dept !== 'all' && !empty($dept)) $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $dept);
    if ($section !== 'all' && !empty($section)) $filename .= "_Sec" . $section;
    $filename .= "_" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Open Output Stream
    $output = fopen('php://output', 'w');

    // Write CSV Column Headers
    fputcsv($output, [
        'Student ID', 
        'Student Name', 
        'Email Address', 
        'Phone Number', 
        'Date of Birth', 
        'Department', 
        'Section', 
        'Country Code'
    ]);

    // --- BUILD DYNAMIC QUERY ---
    $sql = "SELECT student_custom_id, student_name, student_mail_id, student_phone, date_of_birth, department, section, country_code 
            FROM `$table_name` 
            WHERE is_deleted = 0";
    
    $types = "";
    $params = [];

    // Apply Department Filter
    if ($dept !== 'all' && !empty($dept)) {
        $sql .= " AND department = ?";
        $types .= "s";
        $params[] = $dept;
    }

    // Apply Section Filter
    if ($section !== 'all' && !empty($section)) {
        $sql .= " AND section = ?";
        $types .= "s";
        $params[] = $section;
    }

    $sql .= " ORDER BY student_custom_id ASC";

    // --- EXECUTE QUERY ---
    if ($stmt = mysqli_prepare($link, $sql)) {
        
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        // --- WRITE ROWS ---
        while ($row = mysqli_fetch_assoc($result)) {
            fputcsv($output, [
                $row['student_custom_id'],
                $row['student_name'],
                $row['student_mail_id'],
                $row['student_phone'],
                $row['date_of_birth'],
                $row['department'],
                $row['section'],
                $row['country_code']
            ]);
        }
        
        mysqli_stmt_close($stmt);
    }

    // Close Stream and Exit
    fclose($output);
    mysqli_close($link);
    exit;

} else {
    // Redirect if accessed directly without POST
    header("location: students.php");
    exit;
}
?>