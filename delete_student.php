<?php
// birthday_system01/delete_student.php

// 1. Initialize session
session_start();

// 2. Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// 3. Include config
require_once "config.php";

// 4. Process the Delete Request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Validate ID and Year parameters
    if(isset($_POST["id"]) && !empty($_POST["id"]) && isset($_POST["year"]) && !empty($_POST["year"])){
        
        $id = trim($_POST["id"]);
        $year_val = trim($_POST["year"]);
        
        // Determine the table name using the helper or manual fallback
        $table_name = (function_exists('get_student_table')) ? get_student_table($year_val) : "students_" . preg_replace('/\D/', '', $year_val);

        // Security Check: Verify table exists before query
        $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
        
        if(mysqli_num_rows($check_table) > 0) {
            
            // Prepare Soft Delete Statement (Update is_deleted flag)
            $sql = "UPDATE `$table_name` SET is_deleted = 1 WHERE id = ?";
            
            if($stmt = mysqli_prepare($link, $sql)){
                // Bind variables to the prepared statement as parameters
                mysqli_stmt_bind_param($stmt, "i", $id);
                
                // Attempt to execute the prepared statement
                if(mysqli_stmt_execute($stmt)){
                    // Success: Set flash message
                    $_SESSION['flash_msg'] = [
                        'text' => "Student moved to Trash. You can restore it from the Trash bin.", 
                        'type' => 'warning'
                    ];
                } else {
                    // Database error
                    $_SESSION['flash_msg'] = [
                        'text' => "Error: Could not execute deletion. Please try again.", 
                        'type' => 'danger'
                    ];
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            // Table doesn't exist error
            $_SESSION['flash_msg'] = [
                'text' => "Error: The database table for Batch 20$year_val was not found.",     
                'type' => 'danger'
            ];
        }
        
    } else {
        // Missing parameters error
        $_SESSION['flash_msg'] = ['text' => "Invalid request parameters.", 'type' => 'danger'];
    }
} else {
    // Accessing file directly without POST
    $_SESSION['flash_msg'] = ['text' => "Invalid request method.", 'type' => 'danger'];
}

// 5. Close connection & Redirect
mysqli_close($link);
header("location: students.php");
exit();
?>