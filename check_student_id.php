<?php
// birthday_system01/check_student_id.php

// Include config file
require_once "config.php";

// Set header to JSON
header('Content-Type: application/json');

// Initialize response
$response = ['exists' => false, 'data' => null];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["student_id"])) {
    
    $student_id = strtoupper(trim($_POST["student_id"]));
    
    // Logic: Extract Year from ID (Format: BWU/DEPT/YY/ID)
    // Example: BWU/CSE/24/001 -> Year is 24
    $parts = explode('/', $student_id);
    
    // We need at least 3 parts to identify the year (index 2)
    if (count($parts) >= 3 && is_numeric($parts[2])) {
        
        $year_val = $parts[2];
        
        // Determine table name using helper or fallback
        $table_name = (function_exists('get_student_table')) ? get_student_table($year_val) : "students_" . preg_replace('/\D/', '', $year_val);
        
        // 1. Check if the table actually exists
        $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
        
        if (mysqli_num_rows($check_table) > 0) {
            
            // 2. Table exists, now check if ID exists in it
            $sql = "SELECT id, student_name, student_custom_id FROM `$table_name` WHERE student_custom_id = ?";
            
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "s", $student_id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        // ID Found
                        $row = mysqli_fetch_assoc($result);
                        $response['exists'] = true;
                        $response['data'] = $row;
                    }
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Return JSON response
echo json_encode($response);
mysqli_close($link);
?>