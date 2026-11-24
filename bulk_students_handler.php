<?php
// birthday_system01/bulk_students_handler.php
session_start();
require_once "config.php";

// Check login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Validate Selection
    if (!isset($_POST['selected_students']) || empty($_POST['selected_students'])) {
        $_SESSION['flash_msg'] = ['type' => 'warning', 'text' => 'No students selected for bulk action.'];
        header("location: students.php");
        exit;
    }

    $selected_ids = $_POST['selected_students']; // Array of "id_year" strings (e.g., "15_24")
    $action = $_POST['action'] ?? '';

    $success_count = 0;
    $error_count = 0;

    // --- BULK TRASH ACTION ---
    if ($action === 'bulk_trash') {
        
        $stmt_map = []; // Cache prepared statements by year to optimize

        foreach ($selected_ids as $composite_key) {
            // Parse "ID_YEAR"
            $parts = explode('_', $composite_key);
            if (count($parts) !== 2) continue;
            
            $stu_id = intval($parts[0]);
            $year_val = preg_replace('/\D/', '', $parts[1]); // Sanitize year
            
            $table_name = "students_" . $year_val;

            // Verify table logic/existence (Basic check via creating stmt)
            if (!isset($stmt_map[$year_val])) {
                // Check if table exists first to avoid fatal errors if year is bad
                $check = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
                if (mysqli_num_rows($check) == 0) continue;

                $sql = "UPDATE `$table_name` SET is_deleted = 1 WHERE id = ?";
                $stmt = mysqli_prepare($link, $sql);
                if ($stmt) {
                    $stmt_map[$year_val] = $stmt;
                } else {
                    $error_count++;
                    continue;
                }
            }

            if (isset($stmt_map[$year_val])) {
                mysqli_stmt_bind_param($stmt_map[$year_val], "i", $stu_id);
                if (mysqli_stmt_execute($stmt_map[$year_val])) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }

        // Close statements
        foreach ($stmt_map as $stmt) mysqli_stmt_close($stmt);
        
        $msg_text = "Moved $success_count students to trash.";
        if ($error_count > 0) $msg_text .= " ($error_count failed)";
        
        $_SESSION['flash_msg'] = ['type' => ($success_count > 0 ? 'success' : 'danger'), 'text' => $msg_text];

    // --- BULK EDIT ACTION ---
    } elseif ($action === 'bulk_edit') {
        
        $new_dept = trim($_POST['bulk_dept'] ?? '');
        $new_sec = trim($_POST['bulk_section'] ?? '');

        // If both are empty, nothing to do
        if (empty($new_dept) && empty($new_sec)) {
             $_SESSION['flash_msg'] = ['type' => 'info', 'text' => 'No changes selected (Department or Section empty).'];
             header("location: students.php");
             exit;
        }

        $stmt_map = []; 

        foreach ($selected_ids as $composite_key) {
            $parts = explode('_', $composite_key);
            if (count($parts) !== 2) continue;
            
            $stu_id = intval($parts[0]);
            $year_val = preg_replace('/\D/', '', $parts[1]);
            $table_name = "students_" . $year_val;

            // Determine Update Mode: "D" (Dept), "S" (Sec), "DS" (Both)
            $mode_key = "";
            if (!empty($new_dept)) $mode_key .= "D";
            if (!empty($new_sec)) $mode_key .= "S";
            
            // Unique key for prepared statement cache: "YEAR_MODE" (e.g. "24_DS")
            $cache_key = $year_val . "_" . $mode_key;

            if (!isset($stmt_map[$cache_key])) {
                // Check table existence
                $check = mysqli_query($link, "SHOW TABLES LIKE '$table_name'");
                if (mysqli_num_rows($check) == 0) continue;

                $sql = "UPDATE `$table_name` SET ";
                
                if (!empty($new_dept)) {
                    $sql .= "department = ? ";
                }
                if (!empty($new_sec)) {
                    if (!empty($new_dept)) $sql .= ", ";
                    $sql .= "section = ? ";
                }
                $sql .= "WHERE id = ?";
                
                $stmt = mysqli_prepare($link, $sql);
                if ($stmt) {
                    $stmt_map[$cache_key] = $stmt;
                } else {
                    $error_count++;
                    continue;
                }
            }

            // Execute
            if (isset($stmt_map[$cache_key])) {
                $stmt = $stmt_map[$cache_key];
                
                if ($mode_key === "DS") {
                    mysqli_stmt_bind_param($stmt, "ssi", $new_dept, $new_sec, $stu_id);
                } elseif ($mode_key === "D") {
                    mysqli_stmt_bind_param($stmt, "si", $new_dept, $stu_id);
                } elseif ($mode_key === "S") {
                    mysqli_stmt_bind_param($stmt, "si", $new_sec, $stu_id);
                }
                
                if (mysqli_stmt_execute($stmt)) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
        }

        foreach ($stmt_map as $stmt) mysqli_stmt_close($stmt);

        $msg_text = "Updated $success_count students successfully.";
        if ($error_count > 0) $msg_text .= " ($error_count failed)";
        $_SESSION['flash_msg'] = ['type' => 'success', 'text' => $msg_text];
    
    } else {
        $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Invalid Action Selected'];
    }

} else {
    $_SESSION['flash_msg'] = ['type' => 'danger', 'text' => 'Invalid Request'];
}

mysqli_close($link);
header("location: students.php");
exit;
?>
