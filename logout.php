<?php
// logout.php

// 1. Initialize the session
session_start();

// 2. Database Connection (Required to save logout time)
require_once "config.php";

// 3. Update Last Logout Time if user is logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["id"])) {
    $user_id = $_SESSION["id"];
    // Use the configured timezone from config.php logic (assuming DB uses system time or NOW())
    // Using NOW() relies on MySQL server time. 
    $sql = "UPDATE users SET last_logout = NOW() WHERE id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// 4. Unset all of the session variables
$_SESSION = array();

// 5. Destroy the session.
session_destroy();

// 6. Close connection
mysqli_close($link);

// 7. Redirect to login page
header("location: index.php");
exit;
?>