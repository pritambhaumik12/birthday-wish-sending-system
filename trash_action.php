<?php
// birthday_system01/trash_action.php

// 1. Initialize session
session_start();

// 2. Check login
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// 3. Include config
require_once "config.php";

// 4. Handle Request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Check if IDs are selected
    if (isset($_POST['ids']) && is_array($_POST['ids']) && !empty($_POST['ids'])) {
        
        $raw_input = $_POST['ids']; // Array of strings like "101|24", "102|24", "55|25"
        $action = $_POST['action'] ?? '';
        
        // Group IDs by Year to optimize queries (Batch Processing)
        // Structure: [ '24' => [101, 102], '25' => [55] ]
        $batches = [];
        
        foreach ($raw_input as $item) {
            // Parse "ID|YEAR" string
            $parts = explode('|', $item);
            
            if (count($parts) === 2) {
                $id = intval($parts[0]);
                $year_val = preg_replace('/\D/', '', $parts[1]); // Sanitize Year (digits only)
                
                if ($id > 0 && !empty($year_val)) {
                    $batches[$year_val][] = $id;
                }
            }
        }
        
        $success_count = 0;
        $error_flag = false;
        
        // Execute Query per Batch/Year
        foreach ($batches as $year => $ids) {
            
            // Determine dynamic table name
            $table_name = (function_exists('get_student_table')) ? get_student_table($year) : "students_" . $year;
            
            // Security Check: Verify table exists
            $check_table = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
            if (mysqli_num_rows($check_table) == 0) continue; // Skip if table missing
            
            // Create ID string for IN clause
            $id_list = implode(',', $ids);
            
            $sql = "";
            if ($action === 'restore') {
                // Restore: Set is_deleted = 0
                $sql = "UPDATE `$table_name` SET is_deleted = 0 WHERE id IN ($id_list)";
                
            } elseif ($action === 'delete_permanent') {
                // Permanent Delete: Remove row
                $sql = "DELETE FROM `$table_name` WHERE id IN ($id_list)";
                
                // Optional: You could also delete corresponding email logs here if needed
                // $log_table = "email_logs_" . $year;
                // mysqli_query($link, "DELETE FROM `$log_table` WHERE student_id IN ($id_list)");
            }
            
            // Execute
            if (!empty($sql) && mysqli_query($link, $sql)) {
                $success_count += count($ids); // Count successful operations
            } else {
                $error_flag = true;
            }
        }
        
        // Set Flash Messages
        if ($success_count > 0) {
            $action_text = ($action === 'restore') ? "Restored" : "Permanently deleted";
            $_SESSION['flash_msg'] = [
                'text' => "$action_text $success_count student(s) successfully.", 
                'type' => ($action === 'restore' ? 'success' : 'warning')
            ];
        } elseif ($error_flag) {
            $_SESSION['flash_msg'] = ['text' => "Error occurred during operation.", 'type' => 'danger'];
        } else {
            $_SESSION['flash_msg'] = ['text' => "No valid records processed.", 'type' => 'info'];
        }
        
    } else {
        $_SESSION['flash_msg'] = ['text' => "No items selected.", 'type' => 'info'];
    }
    
    // PRG Pattern
    header("location: trash_students.php");
    exit();

} else {
    // Direct Access Block
    header("location: trash_students.php");
    exit();
}
?>