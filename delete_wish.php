<?php
// birthday_system01/delete_wish.php

// Initialize the session
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Process "Soft Delete" operation
if(isset($_GET["id"]) && !empty(trim($_GET["id"]))){
    
    // UPDATE to flag as deleted instead of DELETE
    $sql = "UPDATE wishes SET is_deleted = 1 WHERE id = ?";

    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $param_id);
        $param_id = trim($_GET["id"]);

        if(mysqli_stmt_execute($stmt)){
            // Redirect back to wishes page
            header("location: wishes.php");
            exit();
        } else{
            echo "Oops! Something went wrong. Please try again later.";
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($link);
    
} else{
    // Check existence of id parameter
    header("location: error.php");
    exit();
}
?>