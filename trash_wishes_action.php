<?php
// birthday_system01/trash_wishes_action.php

session_start();

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (isset($_POST['ids']) && is_array($_POST['ids']) && !empty($_POST['ids'])) {
        
        $ids = $_POST['ids'];
        $action = $_POST['action'] ?? '';
        
        // Sanitize IDs
        $clean_ids = array_map('intval', $ids);
        $id_list = implode(',', $clean_ids);
        
        if ($action === 'restore') {
            // Restore: Set is_deleted = 0
            $sql = "UPDATE wishes SET is_deleted = 0 WHERE id IN ($id_list)";
            if (mysqli_query($link, $sql)) {
                $_SESSION['trash_msg'] = "Selected wishes restored successfully.";
                $_SESSION['trash_msg_type'] = "success";
            } else {
                $_SESSION['trash_msg'] = "Error restoring wishes.";
                $_SESSION['trash_msg_type'] = "danger";
            }
        } elseif ($action === 'delete_permanent') {
            
            // 1. Delete associated Photos first
            $sql_photos = "SELECT photo_path FROM wishes WHERE id IN ($id_list)";
            if ($result = mysqli_query($link, $sql_photos)) {
                while ($row = mysqli_fetch_assoc($result)) {
                    if (!empty($row['photo_path']) && file_exists($row['photo_path'])) {
                        unlink($row['photo_path']); // Delete file
                    }
                }
            }

            // 2. Delete Record from DB
            $sql = "DELETE FROM wishes WHERE id IN ($id_list)";
            if (mysqli_query($link, $sql)) {
                $_SESSION['trash_msg'] = "Selected wishes permanently deleted.";
                $_SESSION['trash_msg_type'] = "warning";
            } else {
                $_SESSION['trash_msg'] = "Error deleting wishes.";
                $_SESSION['trash_msg_type'] = "danger";
            }
        }
    } else {
        $_SESSION['trash_msg'] = "No items selected.";
        $_SESSION['trash_msg_type'] = "info";
    }
    
    header("location: trash_wishes.php");
    exit();
} else {
    header("location: trash_wishes.php");
    exit();
}
?>